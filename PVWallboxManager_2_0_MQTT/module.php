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
        $this->RegisterPropertyBoolean('DebugPVWM', false); // allgemeine Modul-Logs
        $this->RegisterPropertyBoolean('DebugMQTT', false); // rohe MQTT-Topics/Payloads

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
        $this->RegisterPropertyInteger('MinPublishGapMs', 2000);      // Mindestabstand amp/set
        $this->RegisterPropertyInteger('WBSubtractMinW', 100);

        //// Start-Reserve in Watt (Sicherheitsmarge über MinAmp)
        $this->RegisterPropertyInteger('StartReserveW', 200); // z.B. 200W

        //// Mindest-Laufzeiten für FRC (Start/Stop)
        $this->RegisterPropertyInteger('MinOnTimeMs',  60000); // 60s nach Start nicht wieder stoppen
        $this->RegisterPropertyInteger('MinOffTimeMs', 15000); // 15s nach Stop nicht gleich wieder starten

        //// Zeitpunkt letzte FRC-Änderung
        $this->RegisterAttributeInteger('LastFrcChangeMs', 0);
        $this->RegisterAttributeString('MQTT_BUF', '{}');

        $this->RegisterAttributeInteger('WB_ActivePhases', 1);

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


        // --- Smoothing & Ramping ---
        $this->RegisterPropertyInteger('SmoothAlphaPermille', 300);  // 0..1000 → 0.3 = smooth, 0 = aus
        $this->RegisterPropertyInteger('RampHoldMs', 3000);          // min. Abstand zw. Amp-Schritten
        $this->RegisterPropertyInteger('RampStepA', 1);              // 1 A pro Schritt
        $this->RegisterAttributeInteger('SmoothSurplusW', 0);
        $this->RegisterAttributeInteger('LastAmpChangeMs', 0);


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
                $this->sendSet('amp', (string)$amp);
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
        $this->SetTimerInterval('LOOP', 0);
        $mode  = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV, 1=Manuell, 2=Aus
        $nowMs = (int)(microtime(true) * 1000);
        $lastFR = $this->lastFrcChangeMs();

        // MQTT-Buffer lesen
        $nrg = $this->mqttBufGet('nrg', null);
        if ($nrg !== null) {
            try { $this->parseAndStoreNRG($nrg); } catch (\Throwable $e) { $this->dbgLog('NRG', 'Parse-Fehler: '.$e->getMessage()); }
        }

        // AUS
        if ($mode === 2) {
            $frcCur = (int)@GetValue(@$this->GetIDForIdent('FRC'));
            if ($frcCur !== 1) {
                $this->sendSet('frc', '1');
                $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
                $this->dbgLog('Lademodus', 'Aus → Force-Off');
            }
            $this->WriteAttributeInteger('CntStart', 0);
            $this->WriteAttributeInteger('CntStop',  0);
            $this->WriteAttributeInteger('CntTo1p',  0);
            $this->WriteAttributeInteger('CntTo3p',  0);
            return;
        }

        // MANUELL
        if ($mode === 1) {
            $holdMs   = (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs');
            $lastSw   = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
            $holdOver = ($nowMs - $lastSw) >= $holdMs;

            $pmWanted = ((int)@GetValue(@$this->GetIDForIdent('Phasenmodus')) === 2) ? 2 : 1;
            [$minA, $maxA] = $this->ampRange();
            $aWanted = max($minA, min($maxA, (int)@GetValue(@$this->GetIDForIdent('Ampere_A'))));

            $curPm = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus'));
            if ($holdOver && $pmWanted !== $curPm) {
                $this->sendSet('psm', (string)$pmWanted);
                $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                $this->WriteAttributeInteger('LastPhaseMode', $pmWanted);
            } else if (!$holdOver) {
                $this->dbgLog('Lademodus', 'Phasenwechsel-Sperrzeit aktiv – psm bleibt vorerst');
            }

            $lastPub = (int)$this->ReadAttributeInteger('LastPublishMs');
            $gapMs   = (int)$this->ReadPropertyInteger('MinPublishGapMs');
            $lastA   = (int)$this->ReadAttributeInteger('LastAmpSet');

            if (($nowMs - $lastPub) >= $gapMs && $aWanted !== $lastA) {
                $this->sendSet('amp', (string)$aWanted);
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
            return;
        }

        // -------- 1) Eingänge --------
        $pv         = $this->readVarWUnit('VarPV_ID',     'VarPV_Unit');
        $houseTotal = $this->readVarWUnit('VarHouse_ID',  'VarHouse_Unit');
        $batt       = $this->readVarWUnit('VarBattery_ID','VarBattery_Unit');
        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) $batt = -$batt;

        // WB-Leistung
        $wb    = max(0, $this->getWBPowerW());
        $minWB = max(0, (int)$this->ReadPropertyInteger('WBSubtractMinW'));
        $wbEff = ($wb > $minWB) ? $wb : 0;

        $houseNet   = max(0, $houseTotal - $wbEff - max(0, $batt)); // Batterie-Laden rausrechnen
        $this->SetValueSafe('HouseNet_W', (int)round($houseNet));
        $surplusRaw = max(0, $pv - $houseNet);


        // -------- 1a) Glättung --------
        $alphaPermille = (int)$this->ReadPropertyInteger('SmoothAlphaPermille');
        if ($alphaPermille <= 0) { $alphaPermille = 350; }
        $alpha = min(1.0, max(0.0, $alphaPermille / 1000.0));
        $prevSmooth = (int)$this->ReadAttributeInteger('SmoothSurplusW');
        $surplus = (int)round($alpha * $surplusRaw + (1.0 - $alpha) * $prevSmooth);
        $this->WriteAttributeInteger('SmoothSurplusW', $surplus);

        $this->dbgLog('Bilanz', sprintf(
            'PV=%dW, HausGes=%dW, WB=%dW (> %dW? %s), HausNet=%dW, Batt=%+dW, Überschuss roh=%dW, glatt=%dW (α=%.2f)',
            (int)$pv, (int)$houseTotal, (int)$wb, (int)$minWB, ($wbEff>0?'ja':'nein'),
            (int)$houseNet, (int)$batt, (int)$surplusRaw, (int)$surplus, $alpha
        ));

        // -------- 2) Zustände --------
        $pm  = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')) ?: 1; // 1=1p, 2=3p
        $car = (int)@GetValue(@$this->GetIDForIdent('CarState')) ?: 0;

        // -------- 3) Start/Stop-Hysterese --------
        $startW = (int)$this->ReadPropertyInteger('StartThresholdW');
        $stopW  = (int)$this->ReadPropertyInteger('StopThresholdW');
        $cStart = (int)$this->ReadAttributeInteger('CntStart');
        $cStop  = (int)$this->ReadAttributeInteger('CntStop');

        if ($surplus >= $startW) { $cStart++; $cStop = max(0, $cStop - 1); }
        elseif ($surplus <= $stopW) { $cStop++;  $cStart = max(0, $cStart - 1); }
        else { $cStart = max(0, $cStart - 1); $cStop = max(0, $cStop - 1); }

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

        $phEff = (int)$this->ReadAttributeInteger('WB_ActivePhases');
        if ($phEff < 1 || $phEff > 3) {
            $phEff = ($pm === 2) ? 3 : 1; // Fallback auf deklarierten Kontaktor
        }

        // -------- 5) Start/Stop + Ampere --------
        $U      = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        [$minA, $maxA] = $this->ampRange();
        $resW   = (int)$this->ReadPropertyInteger('StartReserveW');

        $onHold  = ($nowMs - $lastFR) < (int)$this->ReadPropertyInteger('MinOnTimeMs');
        $offHold = ($nowMs - $lastFR) < (int)$this->ReadPropertyInteger('MinOffTimeMs');

        $connected = in_array($car, [1,2,3,4], true);
        $charging  = $this->isChargingActive();

        // dynamische Mindestleistungen
        $minP_eff = $minA * $U * $phEff + $resW;   // effektive Mindestleistung
        $minP_1p  = $minA * $U * 1      + $resW;   // 1-ph Referenz
        $this->dbgLog('StartCheck', sprintf(
            'car=%d connected=%s charging=%s pm=%d phEff=%d startOk=%d stopOk=%d offHold=%s onHold=%s surplus=%dW minP_eff=%dW',
            $car, $connected?'ja':'nein', $charging?'ja':'nein', $pm, $phEff, $startOk, $stopOk,
            $offHold?'Warte':'ok', $onHold?'Warte':'ok', $surplus, $minP_eff
        ));

        // 3p → 1p Starthilfe:
        if ($connected && !$charging && $pm === 2 && $holdOver && $surplus < $minP_eff && $surplus >= $minP_1p) {
            $this->sendSet('psm', '1');
            $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
            $this->WriteAttributeInteger('LastPhaseMode', 1);
            return;
        }

        $frcCur = (int)@GetValue(@$this->GetIDForIdent('FRC'));
        if (!$connected) {
            if ($frcCur !== 1) {
                $this->sendSet('frc', '1');
                $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
                $this->dbgLog('Ladung', 'Stop (kein Fahrzeug)');
            }
            $this->WriteAttributeInteger('CntStart', 0);
            $this->WriteAttributeInteger('CntStop',  0);
            $this->WriteAttributeInteger('CntTo1p',  0);
            $this->WriteAttributeInteger('CntTo3p',  0);
            return;
        }

        $startedNow = false;

        // STOP
        if ($charging && $stopOk && !$onHold) {
            $this->sendSet('frc', '1');
            $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
            $this->WriteAttributeInteger('CntStart', 0);
            $this->WriteAttributeInteger('CntStop',  0);
            $this->dbgLog('Ladung', 'Stop (Stop-Hysterese erreicht)');
            return;
        }

        // START
        if (!$charging && $connected && $startOk && !$offHold && $surplus >= $minP_eff) {
            $this->sendSet('frc', '2');
            $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
            $this->WriteAttributeInteger('CntStart', 0);
            $this->WriteAttributeInteger('CntStop',  0);
            $this->dbgLog('Ladung', 'Start (Hysterese & Reserve erfüllt, eff. Phasen='.$phEff.')');
            $startedNow = true;
        }

        // --- Sanftes Ampere-Update (ein Block, korrekt initialisiert) ---
        $lastPub   = (int)$this->ReadAttributeInteger('LastPublishMs');
        $gapMs     = (int)$this->ReadPropertyInteger('MinPublishGapMs');
        $minHoldMs = (int)max(3000, floor($gapMs * 1.5));
        $sincePub  = $nowMs - $lastPub;

        $vidA  = @$this->GetIDForIdent('Ampere_A');
        $lastA = (int)$this->ReadAttributeInteger('LastAmpSet');
        $curA  = $vidA ? (int)@GetValue($vidA) : $lastA;

        // Ziel-A auf Basis ROH-Überschuss und effektiver Phasen
        $effSurplus = max(0, $surplusRaw - (int)floor($resW / 2));
        $setA = max($minA, min($maxA, (int)floor($effSurplus / ($U * max(1, $phEff)))));

        // Nur während aktiver Ladung rampen
        if ($charging && $sincePub >= $minHoldMs && $setA !== $curA) {
            $nextA = $curA + (($setA > $curA) ? 1 : -1);     // 1A Schritte
            $nextA = max($minA, min($maxA, $nextA));

            if ($nextA !== $curA) {
                $this->sendSet('amp', (string)$nextA);
                if ($vidA) @SetValue($vidA, $nextA);          // Anzeige
                $this->WriteAttributeInteger('LastAmpSet',    $nextA);
                $this->WriteAttributeInteger('LastPublishMs', $nowMs);
                $this->dbgChanged('Ampere', $curA.' A', $nextA.' A');
            }
        } else {
            $this->dbgLog('Ampere', sprintf(
                'kein Ramp: charging=%s, ΔA=%d, sincePub=%d/%d, set=%d, cur=%d, phEff=%d',
                $charging ? 'ja' : 'nein', abs($setA - $curA), $sincePub, $minHoldMs, $setA, $curA, $phEff
            ));
        }
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
        $houseTotal = $this->readVarWUnit('VarHouse_ID','VarHouse_Unit');
        $wb         = max(0, $this->getWBPowerW());
        $minWB      = max(0, (int)$this->ReadPropertyInteger('WBSubtractMinW'));
        $wbEff      = ($wb > $minWB) ? $wb : 0;

        // Batterie (ggf. Vorzeichen drehen)
        $batt = $this->readVarWUnit('VarBattery_ID','VarBattery_Unit');
        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) { $batt = -$batt; }

        // Haus ohne WB und ohne Batterie-Laden
        $houseNet = max(0, (int)round($houseTotal - $wbEff - max(0, $batt)));
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

    /**
     * Letzter FRC-Änderzeitpunkt in ms.
     * Max aus Attribut (explizit gesetzt) und Zeit der FRC-Variable.
     */
    private function lastFrcChangeMs(): int
    {
        $attr = (int)$this->ReadAttributeInteger('LastFrcChangeMs');

        $vid = @$this->GetIDForIdent('FRC');
        if ($vid && @IPS_VariableExists($vid)) {
            $vi = @IPS_GetVariable($vid);
            if (is_array($vi) && isset($vi['VariableUpdated'])) {
                $varMs = (int)$vi['VariableUpdated'] * 1000; // s → ms
                if ($varMs > $attr) {
                    return $varMs;
                }
            }
        }
        return $attr;
    }

    public function GetConfigurationForm()
    {
        $U    = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $minA = (int)$this->ReadPropertyInteger('MinAmp');
        $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
        $thr3 = 3 * max(1, $minA) * $U;
        $thr1 = max(1, $maxA) * $U;
        $msHint = "⏱ 1 000 ms = 1 s · 10 000 ms = 10 s · 30 000 ms = 30 s";

        return json_encode([
            'elements' => [
                [
                    'type'=>'ExpansionPanel','caption'=>'🔌 Wallbox Konfiguration','items'=>[
                        ['type'=>'ValidationTextBox','name'=>'BaseTopic','caption'=>'Base-Topic (z. B. go-eCharger/285450)'],
                        ['type'=>'ValidationTextBox','name'=>'DeviceIDFilter','caption'=>'Device-ID Filter (optional)'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'NumberSpinner','name'=>'MinAmp','caption'=>'Min. Ampere','minimum'=>1,'maximum'=>32,'suffix'=>' A'],
                            ['type'=>'NumberSpinner','name'=>'MaxAmp','caption'=>'Max. Ampere','minimum'=>1,'maximum'=>32,'suffix'=>' A'],
                            ['type'=>'NumberSpinner','name'=>'NominalVolt','caption'=>'Netzspannung','minimum'=>200,'maximum'=>245,'suffix'=>' V'],
                        ]],
                        ['type'=>'NumberSpinner','name'=>'MinHoldAfterPhaseMs','caption'=>'Sperrzeit Phasenwechsel','suffix'=>' ms'],
                        ['type'=>'Label','caption'=>$msHint],
                        ['type'=>'Label','caption'=>"⚙️ Richtwerte: 3-ph Start ab ≈ {$thr3} W · 1-ph unter ≈ {$thr1} W"],
                    ]
                ],
                [
                    'type'=>'ExpansionPanel','caption'=>'⚡ Eingänge','items'=>[
                        ['type'=>'SelectVariable','name'=>'VarPV_ID','caption'=>'PV-Leistung'],
                        ['type'=>'SelectVariable','name'=>'VarHouse_ID','caption'=>'Haus gesamt (inkl. WB)'],
                        ['type'=>'SelectVariable','name'=>'VarBattery_ID','caption'=>'Batterie (optional)'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'Select','name'=>'VarPV_Unit','caption'=>'PV Einheit','options'=>[
                                ['caption'=>'W','value'=>'W'],['caption'=>'kW','value'=>'kW']]],
                            ['type'=>'Select','name'=>'VarHouse_Unit','caption'=>'Haus Einheit','options'=>[
                                ['caption'=>'W','value'=>'W'],['caption'=>'kW','value'=>'kW']]],
                            ['type'=>'Select','name'=>'VarBattery_Unit','caption'=>'Batt Einheit','options'=>[
                                ['caption'=>'W','value'=>'W'],['caption'=>'kW','value'=>'kW']]],
                        ]],
                        ['type'=>'CheckBox','name'=>'BatteryPositiveIsCharge','caption'=>'+ bedeutet Laden'],
                        ['type'=>'NumberSpinner','name'=>'WBSubtractMinW','caption'=>'WB-Abzug ab','suffix'=>' W'],
                        ['type'=>'Label','caption'=>'WB-Leistung erst ab diesem Wert vom Hausverbrauch abziehen.'],
                        ['type'=>'NumberSpinner','name'=>'SmoothAlphaPermille','caption'=>'Glättung α · 0..1000','suffix'=>' ‰'],
                        ['type'=>'Label','caption'=>'350 ≈ 0,35 · 0 = aus · 1000 = keine Glättung'],
                    ]
                ],
                [
                    'type'=>'ExpansionPanel','caption'=>'🧠 Regellogik','items'=>[
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'NumberSpinner','name'=>'StartThresholdW','caption'=>'Start-Schwelle','suffix'=>' W'],
                            ['type'=>'NumberSpinner','name'=>'StopThresholdW','caption'=>'Stop-Schwelle','suffix'=>' W'],
                        ]],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'NumberSpinner','name'=>'StartCycles','caption'=>'Start-Zyklen','minimum'=>1,'maximum'=>20],
                            ['type'=>'NumberSpinner','name'=>'StopCycles','caption'=>'Stop-Zyklen','minimum'=>1,'maximum'=>20],
                        ]],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'NumberSpinner','name'=>'ThresTo1p_W','caption'=>'→ 1-ph unter','suffix'=>' W'],
                            ['type'=>'NumberSpinner','name'=>'To1pCycles','caption'=>'Zyklen','minimum'=>1,'maximum'=>20],
                            ['type'=>'NumberSpinner','name'=>'ThresTo3p_W','caption'=>'→ 3-ph über','suffix'=>' W'],
                            ['type'=>'NumberSpinner','name'=>'To3pCycles','caption'=>'Zyklen','minimum'=>1,'maximum'=>20],
                        ]],
                        ['type'=>'NumberSpinner','name'=>'StartReserveW','caption'=>'Start-Reserve','suffix'=>' W'],
                    ]
                ],
                [
                    'type'=>'ExpansionPanel','caption'=>'🔧 Ramping & Zeiten','items'=>[
                        ['type'=>'NumberSpinner','name'=>'MinPublishGapMs','caption'=>'Mindestabstand amp/set','suffix'=>' ms'],
                        ['type'=>'NumberSpinner','name'=>'RampHoldMs','caption'=>'Ramping-Haltezeit','suffix'=>' ms'],
                        ['type'=>'NumberSpinner','name'=>'RampStepA','caption'=>'Ramping-Schritt','minimum'=>1,'maximum'=>5,'suffix'=>' A'],
                        ['type'=>'RowLayout','items'=>[
                            ['type'=>'NumberSpinner','name'=>'MinOnTimeMs','caption'=>'Min. EIN-Zeit','suffix'=>' ms'],
                            ['type'=>'NumberSpinner','name'=>'MinOffTimeMs','caption'=>'Min. AUS-Zeit','suffix'=>' ms'],
                        ]],
                        ['type'=>'Label','caption'=>$msHint],
                    ]
                ],
                [
                    'type'=>'ExpansionPanel','caption'=>'🪲 Debug','items'=>[
                        ['type'=>'CheckBox','name'=>'DebugPVWM','caption'=>'Modul-Debug (Regellogik)'],
                        ['type'=>'CheckBox','name'=>'DebugMQTT','caption'=>'MQTT-Rohdaten loggen'],
                        ['type'=>'Label','caption'=>'Ausgabe im Meldungen-Fenster.']
                    ]
                ],
            ],
            'actions'=>[
                ['type'=>'Label','caption'=>'ℹ️ Hinweise aktualisieren sich nach Speichern.']
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

}
