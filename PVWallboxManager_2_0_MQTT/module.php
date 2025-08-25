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

    // Grenzen für go-e (ggf. für V4/32A anpassen)
    private const MIN_AMP = 6;
    private const MAX_AMP = 16;

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

        // Profile sicherstellen
        $this->ensureProfiles();

        // Kern-Variablen
        $this->RegisterVariableInteger('Ampere_A',    'Ampere [A]',        'GoE.Amp',        10);
        $this->EnableAction('Ampere_A');

        $this->RegisterVariableInteger('Leistung_W',  'Leistung [W]',      '~Watt',          20);

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
//        $this->RegisterTimer('LOOP', 0, $this->modulePrefix().'_Loop($id);');
        $this->RegisterTimer('LOOP', 0, $this->modulePrefix().'_Loop($_IPS[\'TARGET\']);');


        // Debug / Rohwerte
//        $this->RegisterVariableString('NRG_RAW',      'NRG (roh)',         '~TextBox',       90);

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

        // MQTT attach + Subscribe
        if (!$this->attachAndSubscribe()) {
            $this->SetTimerInterval('LOOP', 0);
            $this->SetStatus(IS_EBASE + 2);
            return;
        }

        // Loop Timer
        // Loop Timer (Skript + Intervall setzen/aktualisieren)
        $enabled  = $this->ReadPropertyBoolean('CtrlEnabled');
        $interval = max(200, (int)$this->ReadPropertyInteger('CtrlIntervalMs'));
        $this->RegisterTimer('LOOP', $enabled ? $interval : 0, $this->modulePrefix().'_Loop($_IPS[\'TARGET\']);');
        
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

        // 1) Eingänge (in Watt)
        $pv    = $this->readVarWUnit('VarPV_ID',     'VarPV_Unit');
        $house = $this->readVarWUnit('VarHouse_ID',  'VarHouse_Unit');
        $batt  = $this->readVarWUnit('VarBattery_ID','VarBattery_Unit');
        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) {
            $batt = -$batt;
        }
        $surplus = max(0, $pv - $house - max(0, $batt));

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

        // 5) Ampere-Setpoint
        $U      = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $ph     = ($pm === 2) ? 3 : 1;
        $minA   = (int)$this->ReadPropertyInteger('MinAmp');
        $maxA   = (int)$this->ReadPropertyInteger('MaxAmp');
        $neededA = (int)ceil($surplus / ($U * $ph));
        $setA = max($minA, min($maxA, $neededA));

        // Start/Stop per FRC
        if ($stopOk && $car >= 2) {
            $this->sendSet('frc', '1');
            $this->dbgLog('Ladung', 'Stop (Stop-Hysterese erreicht)');
            return;
        }
        if ($startOk && $car >= 3) {
            $this->sendSet('frc', '2');
            $this->dbgLog('Ladung', 'Start (Start-Hysterese erreicht)');
        }

        // Rate-Limit
        $lastPub = (int)$this->ReadAttributeInteger('LastPublishMs');
        $gapMs   = (int)$this->ReadPropertyInteger('MinPublishGapMs');
        $lastA   = (int)$this->ReadAttributeInteger('LastAmpSet');

        if ($setA !== $lastA && ($nowMs - $lastPub) >= $gapMs) {
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
