<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Profiles.php';
require_once __DIR__ . '/lib/Helpers.php';
require_once __DIR__ . '/lib/Communication/MqttClientTrait.php';
require_once __DIR__ . '/lib/MqttHandlersTrait.php';

class PVWallboxManager_2_0_MQTT extends IPSModule
{
    use Profiles;
    use Helpers;
    use MqttClientTrait;     // Attach, Subscribe, Publish, sendSet()
    use MqttHandlersTrait;   // ReceiveData + Parsing/Abfragen

    public function Create()
    {
        parent::Create();
        
        // Property: Debug an/aus
        $this->RegisterPropertyBoolean('DebugLogging', false);

        // --- Properties & Auto-Modus ---
        $this->RegisterPropertyString('BaseTopic', '');            // leer => Auto
        $this->RegisterAttributeString('AutoBaseTopic', '');       // erkannter Stamm
        $this->RegisterPropertyString('DeviceIDFilter', '');       // z.B. "285450", leer = kein Filter

        // --- Energiequellen (Variablen + Einheit W/kW) ---
        $this->RegisterPropertyInteger('VarPV_ID', 0);
        $this->RegisterPropertyString('VarPV_Unit', 'W');     // 'W' | 'kW'

        $this->RegisterPropertyInteger('VarHouse_ID', 0);
        $this->RegisterPropertyString('VarHouse_Unit', 'W');  // 'W' | 'kW'

        $this->RegisterPropertyInteger('VarBattery_ID', 0);   // optional
        $this->RegisterPropertyString('VarBattery_Unit', 'W');// 'W' | 'kW'
        $this->RegisterPropertyBoolean('BatteryPositiveIsCharge', true); // + = Laden

        // --- Start/Stop-Hysterese (Watt + Zyklen) ---
        $this->RegisterPropertyInteger('StartThresholdW', 1400);
        $this->RegisterPropertyInteger('StopThresholdW', 1100);
        $this->RegisterPropertyInteger('StartCycles', 3);
        $this->RegisterPropertyInteger('StopCycles', 3);

        // --- Phasenumschaltung (Watt + Zyklen) ---
        $this->RegisterPropertyInteger('ThresTo1p_W', 3680);  // runter auf 1-ph
        $this->RegisterPropertyInteger('To1pCycles', 3);
        $this->RegisterPropertyInteger('ThresTo3p_W', 4140);  // hoch auf 3-ph
        $this->RegisterPropertyInteger('To3pCycles', 3);

        // --- Netz-/Strom-Parameter & Loop-Settings ---
        $this->RegisterPropertyInteger('MinAmp', 6);
        $this->RegisterPropertyInteger('MaxAmp', 16);         // ggf. 32 je nach Box
        $this->RegisterPropertyInteger('NominalVolt', 230);   // pro Phase
        $this->RegisterPropertyInteger('MinHoldAfterPhaseMs', 30000); // Sperrzeit nach psm-Wechsel
        $this->RegisterPropertyInteger('MinPublishGapMs', 2000);      // Mindestabstand ama/set
        $this->RegisterPropertyBoolean('CtrlEnabled', true);
        $this->RegisterPropertyInteger('CtrlIntervalMs', 1000);       // Loop-Intervall

        //// Start-Reserve in Watt (Sicherheitsmarge über MinAmp)
        $this->RegisterPropertyInteger('StartReserveW', 200); // z.B. 200W

        //// Mindest-Laufzeiten für FRC (Start/Stop)
        $this->RegisterPropertyInteger('MinOnTimeMs',  60000); // 60s nach Start nicht wieder stoppen
        $this->RegisterPropertyInteger('MinOffTimeMs', 15000); // 15s nach Stop nicht gleich wieder starten

        //// Zeitpunkt letzte FRC-Änderung
        $this->RegisterAttributeInteger('LastFrcChangeMs', 0);

        // Profile sicherstellen
        $this->ensureProfiles();

        // Kern-Variablen
        $this->RegisterVariableInteger('Ampere_A',    'Ampere [A]',        'GoE.Amp',        10);
        $this->EnableAction('Ampere_A');

        $this->RegisterVariableInteger('Leistung_W',  'Leistung [W]',      '~Watt',          20);
        $this->RegisterVariableInteger('HouseNet_W',  'Hausverbrauch (ohne WB) [W]', '~Watt', 21);

        $this->RegisterVariableInteger('CarState',    'Fahrzeugstatus',    'GoE.CarState',   25);

        $this->RegisterVariableInteger('FRC',         'Force State (FRC)', 'GoE.ForceState', 50);
        $this->EnableAction('FRC');

        $this->RegisterVariableInteger('Phasenmodus', 'Phasenmodus',       'GoE.PhaseMode',  60);
        $this->EnableAction('Phasenmodus');

        $this->RegisterVariableInteger('Uhrzeit',     'Uhrzeit',            '~UnixTimestamp', 70);

        // --- interne Zähler/Status (Attribute) ---
        $this->RegisterAttributeInteger('CntStart', 0);
        $this->RegisterAttributeInteger('CntStop', 0);
        $this->RegisterAttributeInteger('CntTo1p', 0);
        $this->RegisterAttributeInteger('CntTo3p', 0);
        $this->RegisterAttributeInteger('LastAmpSet', 0);
        $this->RegisterAttributeInteger('LastPublishMs', 0);
        $this->RegisterAttributeInteger('LastPhaseMode', 0);     // 1/2
        $this->RegisterAttributeInteger('LastPhaseSwitchMs', 0);

        // --- Timer für Control-Loop ---
        $this->RegisterTimer('LOOP', 0, $this->modulePrefix()."_Loop(\$_IPS['TARGET']);");

        // (Für späteres WebFront-Preview)
        // $this->RegisterVariableString('Preview', 'Wallbox-Preview', '~HTMLBox', 100);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Profil GoE.Amp an Min/Max anpassen
        $minA = max(1, (int)$this->ReadPropertyInteger('MinAmp'));
        $maxA = max($minA, (int)$this->ReadPropertyInteger('MaxAmp'));
        if (IPS_VariableProfileExists('GoE.Amp')) {
            IPS_SetVariableProfileValues('GoE.Amp', $minA, $maxA, 1);
            IPS_SetVariableProfileText('GoE.Amp', '', ' A');
        }
        $vidAmp = @$this->GetIDForIdent('Ampere_A');
        if ($vidAmp) {
            $cur = (int)@GetValue($vidAmp);
            $clamped = min($maxA, max($minA, $cur));
            if ($cur !== $clamped) { @SetValue($vidAmp, $clamped); }
        }

        // --- MQTT attach + Subscribe ---
        if (!$this->attachAndSubscribe()) {
            // Timer aus, Status Fehler
            $this->SetTimerInterval('LOOP', 0);
            $this->SetStatus(IS_EBASE + 2);
            return;
        }

        // --- LOOP-Timer sicher behandeln (ohne Doppel-Register) ---
        $enabled      = $this->ReadPropertyBoolean('CtrlEnabled');
        $interval     = max(200, (int)$this->ReadPropertyInteger('CtrlIntervalMs'));
        $wantedScript = $this->modulePrefix()."_Loop(\$_IPS['TARGET']);";

        // 1) Event-ID des Timers per Ident holen
        $eid = @IPS_GetObjectIDByIdent('LOOP', $this->InstanceID);

        if ($eid && @IPS_EventExists($eid)) {
            // 2) Script-Text (Migration von $id -> $_IPS['TARGET'])
            @IPS_SetEventScript($eid, $wantedScript);
        } else {
            // 3) Fallback: Timer fehlt (alte Instanz) -> leise neu anlegen
            @ $this->RegisterTimer('LOOP', 0, $wantedScript);  // @ unterdrückt „bereits vorhanden“-Warnung
            $eid = @IPS_GetObjectIDByIdent('LOOP', $this->InstanceID);
        }

        // 4) Intervall setzen/aktivieren
        $this->SetTimerInterval('LOOP', $enabled ? $interval : 0);

        $this->SetStatus(IS_ACTIVE);
    }

    // -------- Actions (WebFront) --------
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Ampere_A':
                $minA = (int)$this->ReadPropertyInteger('MinAmp');
                $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
                $amp  = max($minA, min($maxA, (int)$Value));
                $this->sendSet('ama', (string)$amp);
                $this->SetValueSafe('Ampere_A', $amp);
                break;

            case 'Phasenmodus':
                $pm = ((int)$Value === 2) ? 2 : 1;
                $this->sendSet('psm', (string)$pm);
                $this->SetValueSafe('Phasenmodus', $pm);
                break;

            case 'FRC':
                $frc = in_array((int)$Value, [0,1,2], true) ? (int)$Value : 0;
                $this->sendSet('frc', (string)$frc);
                $this->SetValueSafe('FRC', $frc);
                break;
        }
    }

    // -------- Control-Loop --------
    public function Loop(): void
    {
        if (!$this->ReadPropertyBoolean('CtrlEnabled')) return;

        // 1) Eingänge (in Watt, Einheit W/kW wird pro Quelle skaliert)
        $pv         = $this->readVarWUnit('VarPV_ID',     'VarPV_Unit');      // W
        $houseTotal = $this->readVarWUnit('VarHouse_ID',  'VarHouse_Unit');   // W (GESAMT inkl. WB)
        $batt       = $this->readVarWUnit('VarBattery_ID','VarBattery_Unit'); // W (optional)

        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) {
            $batt = -$batt; // invertieren, falls System + = Entladen liefert
        }

        // Wallbox-Leistung aus eigener Instanz (kommt aus nrg → Leistung_W)
        $wb = max(0, $this->getWBPowerW());

        // Reiner Hausverbrauch (ohne Wallbox)
        $houseNet = max(0, $houseTotal - $wb);
        $this->SetValueSafe('HouseNet_W', (int)round($houseNet));

        // Überschuss = PV - Haus(ohne WB) - Batterie(Ladeleistung)
        $surplus = max(0, $pv - $houseNet - max(0, $batt));

        // (optional) Debug-Bilanz
        $this->dbgLog(
            'Bilanz',
            sprintf('PV=%dW, HausGes=%dW, WB=%dW, HausNet=%dW, Batt=%dW, Überschuss=%dW',
                (int)$pv, (int)$houseTotal, (int)$wb, (int)$houseNet, (int)$batt, (int)$surplus
            )
        );

        // 2) aktuelle Zustände
        $pm  = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')) ?: 1;
        $car = (int)@GetValue(@$this->GetIDForIdent('CarState')) ?: 0;

        // 3) Start/Stop Hysterese
        $startW = (int)$this->ReadPropertyInteger('StartThresholdW');
        $stopW  = (int)$this->ReadPropertyInteger('StopThresholdW');
        $cStart = (int)$this->ReadAttributeInteger('CntStart');
        $cStop  = (int)$this->ReadAttributeInteger('CntStop');

        $cStart = ($surplus >= $startW) ? ($cStart+1) : 0;
        $cStop  = ($surplus <= $stopW)  ? ($cStop+1)  : 0;
        $this->WriteAttributeInteger('CntStart', $cStart);
        $this->WriteAttributeInteger('CntStop',  $cStop);

        $startOk = ($cStart >= (int)$this->ReadPropertyInteger('StartCycles'));
        $stopOk  = ($cStop  >= (int)$this->ReadPropertyInteger('StopCycles'));

        // 4) Phasen-Hysterese
        $to1pW = (int)$this->ReadPropertyInteger('ThresTo1p_W');
        $to3pW = (int)$this->ReadPropertyInteger('ThresTo3p_W');
        $c1p = (int)$this->ReadAttributeInteger('CntTo1p');
        $c3p = (int)$this->ReadAttributeInteger('CntTo3p');

        $c1p = ($surplus <= $to1pW) ? ($c1p+1) : 0;
        $c3p = ($surplus >= $to3pW) ? ($c3p+1) : 0;
        $this->WriteAttributeInteger('CntTo1p', $c1p);
        $this->WriteAttributeInteger('CntTo3p', $c3p);

        $holdMs = (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs');
        $nowMs  = (int)(microtime(true)*1000);
        $lastSw = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
        $holdOver = ($nowMs - $lastSw) >= $holdMs;

        $targetPM = $pm;
        if ($car > 0 && $holdOver) {
            if ($pm === 2 && $c1p >= (int)$this->ReadPropertyInteger('To1pCycles')) $targetPM = 1;
            if ($pm === 1 && $c3p >= (int)$this->ReadPropertyInteger('To3pCycles')) $targetPM = 2;
        }

        if ($targetPM !== $pm) {
            $this->sendSet('psm', (string)$targetPM);
            $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
            $this->WriteAttributeInteger('LastPhaseMode', $targetPM);
            $this->dbgChanged('Phasenmodus', $this->phaseModeLabel($pm), $this->phaseModeLabel($targetPM));
            return;
        }

        // -------------------------
        // 5) Start/Stop + Phasen + Ampere
        // -------------------------

        $U        = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $pm       = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')) ?: 1;   // 1=1ph, 2=3ph
        $ph       = ($pm === 2) ? 3 : 1;
        $minA     = (int)$this->ReadPropertyInteger('MinAmp');
        $maxA     = (int)$this->ReadPropertyInteger('MaxAmp');
        $resW     = (int)$this->ReadPropertyInteger('StartReserveW');            // z.B. 200W
        $nowMs    = (int)(microtime(true) * 1000);
        $lastFR   = (int)$this->ReadAttributeInteger('LastFrcChangeMs');
        $onHold   = ($nowMs - $lastFR) < (int)$this->ReadPropertyInteger('MinOnTimeMs');
        $offHold  = ($nowMs - $lastFR) < (int)$this->ReadPropertyInteger('MinOffTimeMs');

        $car      = (int)@GetValue(@$this->GetIDForIdent('CarState'));
        $connected= ($car >= 3);                             // 3=verbunden/bereit, 4=Beendet (noch verbunden)
        $charging = $this->isChargingActive();

        // Mindestleistung für Start je nach Phasen
        $minP1 = $minA * $U * 1 + $resW;
        $minP3 = $minA * $U * 3 + $resW;
        $minP  = ($ph === 3) ? $minP3 : $minP1;

        // --- Falls aktuell 3-ph und Überschuss reicht nicht für 3-ph, aber für 1-ph: zuerst auf 1-ph umschalten ---
        $holdMs = (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs');
        $lastSw = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
        $holdOver = ($nowMs - $lastSw) >= $holdMs;

        $this->dbgLog('StartCheck', sprintf(
            'car=%d connected=%s charging=%s startOk=%d stopOk=%d offHold=%s onHold=%s surplus=%dW minP1=%dW minP3=%dW pm=%d',
            $car, $connected?'ja':'nein', $charging?'ja':'nein', $startOk, $stopOk,
            $offHold?'Warte':'ok', $onHold?'Warte':'ok', $surplus, $minP1, $minP3, $pm
        ));

        if (!$charging && $pm === 2 && $holdOver && $surplus < $minP3 && $surplus >= $minP1) {
            $this->sendSet('psm', '1');
            $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
            $this->WriteAttributeInteger('LastPhaseMode', 1);
            $this->dbgChanged('Phasenmodus', '3-phasig', '1-phasig (für Start)');
            return; // im nächsten Tick mit 1-ph weiter
        }

        // --- STOP: nur wenn wir wirklich laden, Stop-Hysterese erfüllt und Mindest-Einzeit vorbei ---
        if ($charging && $stopOk && !$onHold) {
            $this->sendSet('frc', '1'); // Force-Off
            $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
            $this->dbgLog('Ladung', 'Stop (Stop-Hysterese erreicht)');
            return;
        }

        // --- START: nur wenn nicht laden, Fahrzeug verbunden, Start-Hysterese, Mindest-Auszeit vorbei, und genug Reserve ---
        if (!$charging && $connected && $startOk && !$offHold && $surplus >= $minP) {
            $this->sendSet('frc', '2'); // Force-On
            $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
            $this->dbgLog('Ladung', 'Start (Hysterese & Reserve erfüllt, pm=' . ($ph===3?'3-ph':'1-ph') . ')');
            // kein return: wir können gleich den ersten Ampere-Setpoint schicken
        }

        // --- Ampere-Setpoint konservativ setzen ---
        $lastPub = (int)$this->ReadAttributeInteger('LastPublishMs');
        $gapMs   = (int)$this->ReadPropertyInteger('MinPublishGapMs');
        $lastA   = (int)$this->ReadAttributeInteger('LastAmpSet');

        // kleine Sicherheitsmarge, damit wir nicht sofort ins Negative rutschen
        $effSurplus = max(0, $surplus - (int)floor($resW / 2));
        $neededA = (int)floor($effSurplus / ($U * (($pm===2)?3:1)));
        $setA    = max($minA, min($maxA, $neededA));

        if (($nowMs - $lastPub) >= $gapMs && $setA !== $lastA) {
            $this->sendSet('ama', (string)$setA);
            $this->WriteAttributeInteger('LastAmpSet', $setA);
            $this->WriteAttributeInteger('LastPublishMs', $nowMs);
            $this->dbgChanged('Ampere', $lastA.' A', $setA.' A');
        }
    }

    // -------- Konfiguration (ohne form.json) --------
    public function GetConfigurationForm()
    {
        $prop  = trim($this->ReadPropertyString('BaseTopic'));
        $auto  = trim($this->ReadAttributeString('AutoBaseTopic'));
        $state = ($prop !== '') ? 'Fix (Property)' : (($auto !== '') ? 'Auto erkannt' : 'Unbekannt');

        $inst   = @IPS_GetInstance($this->InstanceID);
        $mod    = is_array($inst) ? @IPS_GetModule($inst['ModuleID']) : null;
        $prefix = (is_array($mod) && !empty($mod['Prefix'])) ? $mod['Prefix'] : 'GOEMQTT';

        return json_encode([
            'elements' => [

                // MQTT
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'MQTT / Geräte-Zuordnung',
                    'items'   => [
                        ['type' => 'Label', 'caption' => 'BaseTopic (leer = automatische Erkennung)'],
                        ['type' => 'ValidationTextBox', 'name' => 'BaseTopic', 'caption' => 'MQTT BaseTopic (optional)'],
                        ['type' => 'Label', 'caption' => 'Erkannt: ' . ($auto !== '' ? $auto : '—')],
                        ['type' => 'Label', 'caption' => 'Status: ' . $state],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Erkannten BaseTopic übernehmen',
                                    'onClick' => sprintf('%s_ApplyDetectedBaseTopic($id);', $prefix),
                                    'enabled' => ($auto !== '' && $prop === '')
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Auto-Erkennung zurücksetzen',
                                    'onClick' => sprintf('%s_ClearDetectedBaseTopic($id);', $prefix),
                                    'enabled' => ($auto !== '')
                                ]
                            ]
                        ],
                        ['type' => 'ValidationTextBox', 'name' => 'DeviceIDFilter', 'caption' => 'DeviceID-Filter (optional)']
                    ]
                ],

                // Energiequellen
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Energiequellen',
                    'items'   => [
                        ['type' => 'Label', 'caption' => 'Pflicht: PV-Erzeugung & Hausverbrauch. Batterie optional.'],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'SelectVariable', 'name' => 'VarPV_ID', 'caption' => 'PV-Erzeugung'],
                                ['type' => 'Select', 'name' => 'VarPV_Unit', 'caption' => 'Einheit',
                                    'options' => [
                                        ['caption' => 'Watt (W)', 'value' => 'W'],
                                        ['caption' => 'Kilowatt (kW)', 'value' => 'kW']
                                    ]
                                ]
                            ]
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'SelectVariable', 'name' => 'VarHouse_ID', 'caption' => 'Gesamter Hausverbrauch'],
                                ['type' => 'Select', 'name' => 'VarHouse_Unit', 'caption' => 'Einheit',
                                    'options' => [
                                        ['caption' => 'Watt (W)', 'value' => 'W'],
                                        ['caption' => 'Kilowatt (kW)', 'value' => 'kW']
                                    ]
                                ]
                            ]
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'SelectVariable', 'name' => 'VarBattery_ID', 'caption' => 'Batterieleistung (+=Laden)'],
                                ['type' => 'Select', 'name' => 'VarBattery_Unit', 'caption' => 'Einheit',
                                    'options' => [
                                        ['caption' => 'Watt (W)', 'value' => 'W'],
                                        ['caption' => 'Kilowatt (kW)', 'value' => 'kW']
                                    ]
                                ],
                                ['type' => 'CheckBox', 'name' => 'BatteryPositiveIsCharge', 'caption' => 'Positiv = Laden (invertieren, falls nötig)']
                            ]
                        ]
                    ]
                ],

                // Hysterese / Phasen
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Regelung',
                    'items'   => [
                        ['type' => 'NumberSpinner', 'name' => 'StartThresholdW', 'caption' => 'Start bei PV-Überschuss (W)'],
                        ['type' => 'NumberSpinner', 'name' => 'StartCycles',     'caption' => 'Start-Hysterese (Zyklen)'],
                        ['type' => 'NumberSpinner', 'name' => 'StopThresholdW',  'caption' => 'Stop bei fehlendem PV-Überschuss (W)'],
                        ['type' => 'NumberSpinner', 'name' => 'StopCycles',      'caption' => 'Stop-Hysterese (Zyklen)'],

                        ['type' => 'NumberSpinner', 'name' => 'ThresTo1p_W',     'caption' => 'Schwelle auf 1-phasig (W)'],
                        ['type' => 'NumberSpinner', 'name' => 'To1pCycles',      'caption' => 'Zählerlimit 1-phasig'],
                        ['type' => 'NumberSpinner', 'name' => 'ThresTo3p_W',     'caption' => 'Schwelle auf 3-phasig (W)'],
                        ['type' => 'NumberSpinner', 'name' => 'To3pCycles',      'caption' => 'Zählerlimit 3-phasig'],

                        ['type' => 'NumberSpinner', 'name' => 'MinAmp',          'caption' => 'Min. Ampere'],
                        ['type' => 'NumberSpinner', 'name' => 'MaxAmp',          'caption' => 'Max. Ampere'],
                        ['type' => 'NumberSpinner', 'name' => 'NominalVolt',     'caption' => 'Nennspannung pro Phase (V)'],

                        ['type' => 'NumberSpinner', 'name' => 'MinHoldAfterPhaseMs', 'caption' => 'Sperrzeit nach Phasenwechsel (ms)'],
                        ['type' => 'NumberSpinner', 'name' => 'MinPublishGapMs',     'caption' => 'Mindestabstand ama/set (ms)'],

                        ['type' => 'CheckBox',      'name' => 'CtrlEnabled',     'caption' => 'Regelung aktiv'],
                        ['type' => 'NumberSpinner', 'name' => 'CtrlIntervalMs',  'caption' => 'Regel-Intervall (ms)']
                    ]
                ],

                // Debug
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Debug & Diagnose',
                    'items'   => [
                        ['type' => 'CheckBox', 'name' => 'DebugLogging',
                         'caption' => '🐞 Debug-Logging aktivieren (Instanz-Debug & Meldungen)']
                    ]
                ]
            ]
        ]);
    }

    public function ApplyDetectedBaseTopic(): void
    {
        $auto = trim($this->ReadAttributeString('AutoBaseTopic'));
        if ($auto === '') { $this->ReloadForm(); return; }
        IPS_SetProperty($this->InstanceID, 'BaseTopic', $auto);
        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();
    }

    public function ClearDetectedBaseTopic(): void
    {
        $this->WriteAttributeString('AutoBaseTopic', '');
        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();
    }
}
