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
        $this->RegisterPropertyInteger('WBSubtractMinW', 100); 

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
        $this->RegisterVariableInteger('Mode', 'Lademodus', 'PVWM.Mode', 5);
        $this->EnableAction('Mode');
        
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

        // --- GoE.Amp-Profil an Properties anpassen ---
        [$minA, $maxA] = $this->ampRange();
        if (IPS_VariableProfileExists('GoE.Amp')) {
            IPS_SetVariableProfileValues('GoE.Amp', $minA, $maxA, 1);
            IPS_SetVariableProfileText('GoE.Amp', '', ' A');
        }
        if ($vidAmp = @$this->GetIDForIdent('Ampere_A')) {
            $cur = (int)@GetValue($vidAmp);
            $clamped = min($maxA, max($minA, $cur));
            if ($cur !== $clamped) { @SetValue($vidAmp, $clamped); }
        }

        // --- MQTT attach + Subscribe ---
        if (!$this->attachAndSubscribe()) {
            // Watchdog aus, Status Fehler
            $this->SetTimerInterval('LOOP', 0);
            $this->SetStatus(IS_EBASE + 2);
            return;
        }

        // --- alte Referenzen/Messages aufräumen ---
        $refs = @IPS_GetInstance($this->InstanceID)['References'] ?? [];
        if (is_array($refs)) {
            foreach ($refs as $rid) {
                @ $this->UnregisterMessage($rid, VM_UPDATE);
                @ $this->UnregisterReference($rid);
            }
        }

        // --- auf Änderungen reagieren: Haus-Gesamt (Modbus) & eigene WB-Leistung ---
        $houseId = (int)$this->ReadPropertyInteger('VarHouse_ID');
        if ($houseId > 0 && @IPS_VariableExists($houseId)) {
            @ $this->RegisterMessage($houseId, VM_UPDATE);
            @ $this->RegisterReference($houseId);
        }
        if ($wbVid = @$this->GetIDForIdent('Leistung_W')) {
            @ $this->RegisterMessage($wbVid, VM_UPDATE);
            // keine Reference nötig – eigene Variable
        }

        // --- LOOP-Timer Skript aktualisieren + deaktivieren ---
        $wantedScript = $this->modulePrefix() . '_Loop($_IPS[\'TARGET\']);';
        $eid = @IPS_GetObjectIDByIdent('LOOP', $this->InstanceID);
        if ($eid) {
            @IPS_SetEventScript($eid, $wantedScript);
        }
        $this->SetTimerInterval('LOOP', 0);

        // --- Initial: HausNet berechnen & einmal regeln ---
        $this->RecalcHausverbrauchAbzWallbox(true);
        $this->Loop();

        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) return;

        $houseId = (int)$this->ReadPropertyInteger('VarHouse_ID');
        $wbVid   = @$this->GetIDForIdent('Leistung_W');

        if ($SenderID === $houseId || $SenderID === $wbVid) {
            $this->dbgLog('HN-Trigger', "VM_UPDATE von {$SenderID}");
            $this->RecalcHausverbrauchAbzWallbox(true);
            // optional: $this->Loop();  // wenn du direkt danach regeln willst
        }
    }

    // -------- Actions (WebFront) --------
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mode':
                $mode = in_array((int)$Value, [0,1,2], true) ? (int)$Value : 0;
                $this->SetValueSafe('Mode', $mode);
                // sofort anwenden
                $this->Loop();
                break;

            case 'Ampere_A':
                [$minA,$maxA] = $this->ampRange();
                $amp = max($minA, min($maxA, (int)$Value));
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
        // --- Lademodus prüfen ---
        $mode  = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV, 1=Manuell, 2=Aus
        $nowMs = (int)(microtime(true) * 1000);
        $lastFR = (int)$this->ReadAttributeInteger('LastFrcChangeMs');

        // AUS: Force-Off erzwingen und raus
        if ($mode === 2) {
            $frcCur = (int)@GetValue(@$this->GetIDForIdent('FRC'));
            if ($frcCur !== 1) {
                $this->sendSet('frc', '1');
                $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
                $this->dbgLog('Lademodus', 'Aus → Force-Off');
            }
            return;
        }

        // MANUELL: Phasen + Ampere durchsetzen, Force-On, dann raus
        if ($mode === 1) {
            $holdMs   = (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs');
            $lastSw   = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
            $holdOver = ($nowMs - $lastSw) >= $holdMs;

            $pmWanted = ((int)@GetValue(@$this->GetIDForIdent('Phasenmodus')) === 2) ? 2 : 1;
            [$minA, $maxA] = $this->ampRange();
            $aWanted = max($minA, min($maxA, (int)@GetValue(@$this->GetIDForIdent('Ampere_A'))));

            if ($holdOver) {
                $this->sendSet('psm', (string)$pmWanted);
                $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                $this->WriteAttributeInteger('LastPhaseMode', $pmWanted);
            } else {
                $this->dbgLog('Lademodus', 'Phasenwechsel-Sperrzeit aktiv – psm bleibt vorerst');
            }

            $lastPub = (int)$this->ReadAttributeInteger('LastPublishMs');
            $gapMs   = (int)$this->ReadPropertyInteger('MinPublishGapMs');
            $lastA   = (int)$this->ReadAttributeInteger('LastAmpSet');

            if (($nowMs - $lastPub) >= $gapMs && $aWanted !== $lastA) {
                $this->sendSet('ama', (string)$aWanted);
                $this->WriteAttributeInteger('LastAmpSet', $aWanted);
                $this->WriteAttributeInteger('LastPublishMs', $nowMs);
                $this->dbgChanged('Ampere', $lastA.' A', $aWanted.' A');
            }

            $frcCur = (int)@GetValue(@$this->GetIDForIdent('FRC'));
            if ($frcCur !== 2) {
                $this->sendSet('frc', '2');
                $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
                $this->dbgLog('Lademodus', 'Manuell → Force-On');
            }
            return; // PV-Logik überspringen
        }

        // -------- 1) Eingänge (W), Einheiten pro Quelle skaliert --------
        $pv         = $this->readVarWUnit('VarPV_ID',     'VarPV_Unit');      // W
        $houseTotal = $this->readVarWUnit('VarHouse_ID',  'VarHouse_Unit');   // W (GESAMT inkl. WB)
        $batt       = $this->readVarWUnit('VarBattery_ID','VarBattery_Unit'); // W (optional)
        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) {
            $batt = -$batt; // + = Laden, - = Entladen
        }

        // Wallbox-Leistung (aus nrg → Leistung_W), nur > Schwelle abziehen
        $wb    = max(0, $this->getWBPowerW());
        $minWB = max(0, (int)$this->ReadPropertyInteger('WBSubtractMinW')); // z.B. 100 W
        $wbEff = ($wb > $minWB) ? $wb : 0;

        $houseNet = max(0, $houseTotal - $wbEff);
        $this->SetValueSafe('HouseNet_W', (int)round($houseNet));

        // Überschuss = PV - Haus(ohne WB) - Batterie(Ladeleistung)
        $surplus = max(0, $pv - $houseNet - max(0, $batt));

        $this->dbgLog(
            'Bilanz',
            sprintf(
                'PV=%dW, HausGes=%dW, WB=%dW (> %dW? %s), HausNet=%dW, Batt=%+dW, Überschuss=%dW',
                (int)$pv, (int)$houseTotal, (int)$wb, (int)$minWB, ($wbEff>0?'ja':'nein'),
                (int)$houseNet, (int)$batt, (int)$surplus
            )
        );

        // -------- 2) aktuelle Zustände --------
        $pm  = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')) ?: 1; // 1=1ph, 2=3ph
        $car = (int)@GetValue(@$this->GetIDForIdent('CarState')) ?: 0;

        // -------- 3) Start/Stop-Hysterese --------
        $startW = (int)$this->ReadPropertyInteger('StartThresholdW');
        $stopW  = (int)$this->ReadPropertyInteger('StopThresholdW');
        $cStart = (int)$this->ReadAttributeInteger('CntStart');
        $cStop  = (int)$this->ReadAttributeInteger('CntStop');

        $cStart = ($surplus >= $startW) ? ($cStart + 1) : 0;
        $cStop  = ($surplus <= $stopW)  ? ($cStop  + 1) : 0;
        $this->WriteAttributeInteger('CntStart', $cStart);
        $this->WriteAttributeInteger('CntStop',  $cStop);

        $startOk = ($cStart >= (int)$this->ReadPropertyInteger('StartCycles'));
        $stopOk  = ($cStop  >= (int)$this->ReadPropertyInteger('StopCycles'));

        // -------- 4) Phasen-Hysterese --------
        $to1pW = (int)$this->ReadPropertyInteger('ThresTo1p_W');
        $to3pW = (int)$this->ReadPropertyInteger('ThresTo3p_W');
        $c1p   = (int)$this->ReadAttributeInteger('CntTo1p');
        $c3p   = (int)$this->ReadAttributeInteger('CntTo3p');

        $c1p = ($surplus <= $to1pW) ? ($c1p + 1) : 0;
        $c3p = ($surplus >= $to3pW) ? ($c3p + 1) : 0;
        $this->WriteAttributeInteger('CntTo1p', $c1p);
        $this->WriteAttributeInteger('CntTo3p', $c3p);

        $holdMs   = (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs');
        $lastSw   = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
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

        // -------- 5) Start/Stop + Ampere --------
        $U      = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $ph     = ($pm === 2) ? 3 : 1;
        [$minA, $maxA] = $this->ampRange();
        $resW   = (int)$this->ReadPropertyInteger('StartReserveW');

        $onHold  = ($nowMs - $lastFR) < (int)$this->ReadPropertyInteger('MinOnTimeMs');
        $offHold = ($nowMs - $lastFR) < (int)$this->ReadPropertyInteger('MinOffTimeMs');

        $connected = ($car >= 3);
        $charging  = $this->isChargingActive();

        $minP1 = $minA * $U * 1 + $resW;
        $minP3 = $minA * $U * 3 + $resW;
        $minP  = ($ph === 3) ? $minP3 : $minP1;

        $this->dbgLog('StartCheck', sprintf(
            'car=%d connected=%s charging=%s startOk=%d stopOk=%d offHold=%s onHold=%s surplus=%dW minP1=%dW minP3=%dW pm=%d',
            $car, $connected?'ja':'nein', $charging?'ja':'nein', $startOk, $stopOk,
            $offHold?'Warte':'ok', $onHold?'Warte':'ok', $surplus, $minP1, $minP3, $pm
        ));

        // 3ph → 1ph für Start, falls 3ph nicht reicht, 1ph jedoch schon (und Sperrzeit vorbei)
        if (!$charging && $pm === 2 && $holdOver && $surplus < $minP3 && $surplus >= $minP1) {
            $this->sendSet('psm', '1');
            $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
            $this->WriteAttributeInteger('LastPhaseMode', 1);
            $this->dbgChanged('Phasenmodus', '3-phasig', '1-phasig (für Start)');
            return;
        }

        // STOP
        if ($charging && $stopOk && !$onHold) {
            $this->sendSet('frc', '1');
            $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
            $this->dbgLog('Ladung', 'Stop (Stop-Hysterese erreicht)');
            return;
        }

        // START
        if (!$charging && $connected && $startOk && !$offHold && $surplus >= $minP) {
            $this->sendSet('frc', '2');
            $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
            $this->dbgLog('Ladung', 'Start (Hysterese & Reserve erfüllt, pm=' . ($ph===3?'3-ph':'1-ph') . ')');
            // kein return: gleich Ampere setzen
        }

        // Ampere-Setpoint konservativ
        $lastPub    = (int)$this->ReadAttributeInteger('LastPublishMs');
        $gapMs      = (int)$this->ReadPropertyInteger('MinPublishGapMs');
        $lastA      = (int)$this->ReadAttributeInteger('LastAmpSet');
        $effSurplus = max(0, $surplus - (int)floor($resW / 2));
        $neededA    = (int)floor($effSurplus / ($U * $ph));
        $setA       = max($minA, min($maxA, $neededA));

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

//                        ['type' => 'CheckBox',      'name' => 'CtrlEnabled',     'caption' => 'Regelung aktiv'],
//                        ['type' => 'NumberSpinner', 'name' => 'CtrlIntervalMs',  'caption' => 'Regel-Intervall (ms)']
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

    private function updateHouseNetFromInputs(): void
    {
        // Bewusst ohne eigenes Log – zentrale Logik sitzt jetzt in RecalcHausverbrauchAbzWallbox()
        $this->RecalcHausverbrauchAbzWallbox(false);
    }

    /**
     * Neu (2.0): Rechnet "Hausverbrauch abzüglich WB" mit Schwelle
     * und schreibt HouseNet_W (und optional eine Kompatibilitäts-Variable).
     * Batterie wird hier bewusst NICHT berücksichtigt.
     */
    public function RecalcHausverbrauchAbzWallbox(bool $withLog = true): void
    {
        // Eingänge: Haus gesamt (inkl. WB), WB-Leistung (W)
        $houseTotal = $this->readVarWUnit('VarHouse_ID', 'VarHouse_Unit');
        $wb         = max(0, $this->getWBPowerW());
        $minWB      = max(0, (int)$this->ReadPropertyInteger('WBSubtractMinW')); // z.B. 100 W

        // WB nur abziehen, wenn über Schwelle
        $wbEff    = ($wb > $minWB) ? $wb : 0;
        $houseNet = max(0, (int)round($houseTotal - $wbEff));

        // Schreiben
        $this->SetValueSafe('HouseNet_W', $houseNet);

        // (Optional) Kompatibilitäts-Variable, falls es sie noch gibt
        if ($vid = @$this->GetIDForIdent('Hausverbrauch_abz_Wallbox')) {
            @SetValue($vid, $houseNet);
        }

        // Log
        if ($withLog) {
            $fmt = static function (int $w): string { return number_format($w, 0, ',', '.'); };
            $this->dbgLog('HausNet', sprintf(
                'HausGesamt=%s W | WB=%s W %s (Schwelle=%s W) → HausNet=%s W',
                $fmt((int)round($houseTotal)),
                $fmt((int)round($wb)),
                ($wb > $minWB) ? 'abgezogen' : '≤ Schwelle, nicht abgezogen',
                $fmt($minWB),
                $fmt($houseNet)
            ));
        }
    }
^

}
