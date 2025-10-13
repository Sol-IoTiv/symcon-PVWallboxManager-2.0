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
    use MqttHandlersTrait;   // ReceiveData + Parsing/Abfragen (nrg, car, psm ...)

    // -------------------------
    // Lifecycle
    // -------------------------
    public function Create()
    {
        parent::Create();

        // --- Debug ---
        $this->RegisterPropertyBoolean('DebugPVWM', false);
        $this->RegisterPropertyBoolean('DebugMQTT', false);

        // --- MQTT Basis ---
        $this->RegisterPropertyString('BaseTopic', '');
        $this->RegisterAttributeString('AutoBaseTopic', '');
        $this->RegisterPropertyString('DeviceIDFilter', '');
        $this->RegisterAttributeString('MQTT_BUF', '{}');

        // --- Energiequellen ---
        $this->RegisterPropertyInteger('VarPV_ID', 0);
        $this->RegisterPropertyString('VarPV_Unit', 'W');
        $this->RegisterPropertyInteger('VarHouse_ID', 0);
        $this->RegisterPropertyString('VarHouse_Unit', 'W');
        $this->RegisterPropertyInteger('VarBattery_ID', 0);
        $this->RegisterPropertyString('VarBattery_Unit', 'W');
        $this->RegisterPropertyBoolean('BatteryPositiveIsCharge', true);

        // Batterie-Logik
        $this->RegisterPropertyInteger('VarBatterySoc_ID', 0);
        $this->RegisterPropertyInteger('BatteryMinSocForPV', 90);
        $this->RegisterPropertyInteger('BatteryReserveW', 300);

        // --- Phasenumschaltung ---
        $this->RegisterPropertyInteger('ThresTo1p_W', 3680);
        $this->RegisterPropertyInteger('To1pCycles', 3);
        $this->RegisterPropertyInteger('ThresTo3p_W', 4140);
        $this->RegisterPropertyInteger('To3pCycles', 3);
        $this->RegisterPropertyBoolean('SnapOnConnect', true);
        $this->RegisterPropertyBoolean('AutoPhase', true);

        // --- Netz-/Strom-Parameter & Zeiten ---
        // --- Hauszuleitungs-Wächter ---
        $this->RegisterPropertyInteger('MaxGridPowerW', 0);
        $this->RegisterPropertyInteger('HousePowerVarID', 0);

        $this->RegisterPropertyInteger('MinAmp', 6);
        $this->RegisterPropertyInteger('MaxAmp', 16);
        $this->RegisterPropertyInteger('NominalVolt', 230);
        $this->RegisterPropertyInteger('MinHoldAfterPhaseMs', 30000);
        $this->RegisterPropertyInteger('MinPublishGapMs', 5000);
        $this->RegisterPropertyInteger('WBSubtractMinW', 100);
        $this->RegisterPropertyInteger('StartReserveW', 200);
        $this->RegisterPropertyInteger('MinOnTimeMs',  60000);
        $this->RegisterPropertyInteger('MinOffTimeMs', 15000);
        $this->RegisterPropertyInteger('RampStepA', 1);

        // --- Slow-Control ---
        $this->RegisterPropertyBoolean('SlowControlEnabled', true);
        $this->RegisterPropertyInteger('ControlIntervalSec', 15);
        $this->RegisterPropertyInteger('SlowAlphaPermille', 250);
        $this->RegisterPropertyInteger('TargetMinW', 1380);

        // --- Anti-Flackern: Properties ---
        $this->RegisterPropertyInteger('StartThresholdW', 1400);
        $this->RegisterPropertyInteger('StopThresholdW',  -300);
        $this->RegisterPropertyInteger('MinOn_s',           90);
        $this->RegisterPropertyInteger('MinOff_s',          90);
        $this->RegisterPropertyInteger('DeficitBufferWh',   40);

        // --- Profile ---
        $this->ensureProfiles();

        // --- Kern-Variablen (WebFront) ---
        $this->RegisterVariableInteger('Mode', 'Lademodus', 'PVWM.Mode', 5);
        $this->EnableAction('Mode');

        $this->RegisterVariableInteger('Ampere_A', 'Ampere [A]', 'GoE.Amp', 10);
        $this->EnableAction('Ampere_A');

        // ... nach Ampere_A registrieren (Position anpassen nach Bedarf)
        if (!IPS_VariableProfileExists('PVWM.Percent')) {
            IPS_CreateVariableProfile('PVWM.Percent', 1);
            IPS_SetVariableProfileValues('PVWM.Percent', 0, 100, 1);
            IPS_SetVariableProfileText('PVWM.Percent', '', ' %');
        }

//        $this->RegisterPropertyInteger('PVShareDefaultPct', 50);
        $this->RegisterVariableInteger('PVShare_Pct', 'PV-Anteil', 'PVWM.Percent', 15);
        $this->EnableAction('PVShare_Pct');

        // Default 50% nur, wenn (noch) kein gültiger Wert (0..100) vorliegt
        if ($vidShare = @$this->GetIDForIdent('PVShare_Pct')) {
            $v = (int)@GetValue($vidShare);
            if ($v < 0 || $v > 100) { @SetValue($vidShare, 50); }
        }

        $this->RegisterVariableInteger('PowerToCar_W', 'Ladeleistung [W]', '~Watt', 20);
        $this->RegisterVariableInteger('HouseNet_W',   'Hausverbrauch (ohne WB) [W]', '~Watt', 21);
        $this->RegisterVariableInteger('CarState',     'Fahrzeugstatus', 'GoE.CarState', 25);

        $this->RegisterVariableInteger('FRC', 'Ladefreigabe-Modus', 'PVWM.FRC', 40);
        $this->EnableAction('FRC');

        $this->RegisterVariableInteger('Phasenmodus', 'Phasenmodus', 'PVWM.Phasen', 50);
        $this->EnableAction('Phasenmodus');

        $this->RegisterVariableInteger('Uhrzeit', 'Uhrzeit', '~UnixTimestamp', 70);
        $this->RegisterVariableString('Regelziel', 'Regelziel', '', 80);
//        $this->RegisterVariableString('Ladechart', 'Ladechart', '~HTMLBox', 900);

        // --- Attribute (einmalig registrieren) ---
        $this->RegisterAttributeInteger('LastFrcChangeMs', 0);
        $this->RegisterAttributeInteger('WB_ActivePhases', 1);
        $this->RegisterAttributeInteger('WB_W_Smooth', 0);
        $this->RegisterAttributeInteger('WB_SubtractActive', 0);
        $this->RegisterAttributeInteger('WB_SubtractChangedMs', 0);

        $this->RegisterAttributeInteger('CntStart', 0);
        $this->RegisterAttributeInteger('CntStop', 0);
        $this->RegisterAttributeInteger('CntTo1p', 0);
        $this->RegisterAttributeInteger('CntTo3p', 0);
        $this->RegisterAttributeInteger('LastAmpSet', 0);
        $this->RegisterAttributeInteger('LastPublishMs', 0);
        $this->RegisterAttributeInteger('LastPhaseMode', 0);
        $this->RegisterAttributeInteger('LastPhaseSwitchMs', 0);
        $this->RegisterAttributeInteger('SlowSurplusW', 0);
        $this->RegisterAttributeInteger('SmoothSurplusW', 0);
        $this->RegisterAttributeInteger('LastAmpChangeMs', 0);
        $this->RegisterAttributeInteger('Slow_LastCalcA', 0);
        $this->RegisterAttributeInteger('Slow_TargetW', 0);
        $this->RegisterAttributeInteger('HouseNetEmaW', 0);

        $this->RegisterAttributeInteger('Slow_SurplusRaw', 0);
        $this->RegisterAttributeInteger('Slow_AboveStartMs', 0);
        $this->RegisterAttributeInteger('Slow_BelowStopMs', 0);

        $this->RegisterAttributeInteger('LastCarState', 0);
        $this->RegisterAttributeInteger('Phase_Above3pMs', 0);
        $this->RegisterAttributeInteger('Phase_Below1pMs', 0);

        $this->RegisterAttributeInteger('PendingPhaseMode', 0);
        $this->RegisterAttributeInteger('PhaseSwitchState', 0);

        // Anti-Flackern: Konto + Zeitbasis
        $this->RegisterAttributeInteger('LastBankTsMs', 0);
        $this->RegisterAttributeFloat('DeficitBankWh', 0.0);

        // --- Timer ---
        $this->RegisterTimer('LOOP', 0, $this->modulePrefix().'_Loop($_IPS["TARGET"]);');
        $this->RegisterTimer('SLOW_TickUI', 0, 'IPS_RequestAction($_IPS["TARGET"], "DoSlowTickUI", 0);');
        $this->RegisterTimer('SLOW_TickControl', 0, 'IPS_RequestAction($_IPS["TARGET"], "DoSlowTickControl", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // go-e Amp-Profil an Max/Min anpassen
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

        // MQTT attach + Subscribe (Trait)
        if (!$this->attachAndSubscribe()) {
            $this->SetTimerInterval('LOOP', 0);
            $this->SetTimerInterval('SLOW_TickUI', 0);
            $this->SetTimerInterval('SLOW_TickControl', 0);
            $this->SetStatus(IS_EBASE + 2);
            return;
        }

        // Ampere-Grenzen an Box pushen (optional, sicher)
        [$min,$max] = $this->ampRange();
        $this->sendSet('ama', (string)$max);
        $this->sendSet('amx', (string)$max);

        // Referenzen/VM_UPDATE neu setzen
        $refs = @IPS_GetInstance($this->InstanceID)['References'] ?? [];
        if (is_array($refs)) {
            foreach ($refs as $rid) { @ $this->UnregisterMessage($rid, VM_UPDATE); @ $this->UnregisterReference($rid); }
        }
        $houseId = (int)$this->ReadPropertyInteger('VarHouse_ID');
        if ($houseId > 0 && @IPS_VariableExists($houseId)) { @ $this->RegisterMessage($houseId, VM_UPDATE); @ $this->RegisterReference($houseId); }
        if ($wbVid = @$this->GetIDForIdent('PowerToCar_W')) { @ $this->RegisterMessage($wbVid, VM_UPDATE); }

        // Timer: Slow aktiv, klassische LOOP aus
        $this->SetTimerInterval('LOOP', 0);
        $ctrl = max(10, min(30, (int)$this->ReadPropertyInteger('ControlIntervalSec')));
        $this->SetTimerInterval('SLOW_TickUI', 1000);
        $this->SetTimerInterval('SLOW_TickControl', $this->ReadPropertyBoolean('SlowControlEnabled') ? $ctrl*1000 : 0);

        // Initiale Berechnung
        $this->RecalcHausverbrauchAbzWallbox(true);
        $this->SetStatus(IS_ACTIVE);

        $this->updateUiInteractivity();
    }

    // -------------------------
    // Events
    // -------------------------
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) return;
        $houseId = (int)$this->ReadPropertyInteger('VarHouse_ID');
        $wbVid   = @$this->GetIDForIdent('PowerToCar_W');
        if ($SenderID === $houseId || $SenderID === $wbVid) {
            $this->dbgLog('HN-Trigger', 'VM_UPDATE → Recalc HouseNet');
            $this->RecalcHausverbrauchAbzWallbox(true);
        }
    }

    // -------------------------
    // WebFront Actions
    // -------------------------
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'DoSlowTickUI':
                $this->SLOW_TickUI();
                return;

            case 'DoSlowTickControl':
                $this->SLOW_TickControl();
                return;

            case 'Mode':
                $this->SetValueSafe('Mode', in_array((int)$Value, [0,1,2,3], true) ? (int)$Value : 0);
                $this->updateUiInteractivity();
                return;

            case 'Ampere_A': {
                if (!$this->isManualMode()) {
                    $cur = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
                    $this->SetValueSafe('Ampere_A', $cur); // UI zurück
                    $this->SendDebug('UI', 'Amp ignoriert: nicht Manuell', 0);
                    return;
                }

                $a = (int)$Value;
                $minA = (int)$this->ReadPropertyInteger('MinAmp');
                $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
                $a    = min($maxA, max($minA, $a));
                $this->SetValueSafe('Ampere_A', $a);

                $connected = in_array((int)@GetValue(@$this->GetIDForIdent('CarState')), [2,3,4], true);
                if ($connected) {
                    $this->setCurrentLimitA($a);
                    if ((int)@GetValue(@$this->GetIDForIdent('FRC')) !== 2) {
                        $this->setFRC(2, 'UI: Ampere_A');
                    }
                    $nowMs = (int)(microtime(true) * 1000);
                    $this->WriteAttributeInteger('LastAmpSet',    $a);
                    $this->WriteAttributeInteger('LastPublishMs', $nowMs);
                }
                return;
            }

            case 'Phasenmodus': {
                if (!$this->isManualMode()) {
                    $cur = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus'));
                    $this->SetValueSafe('Phasenmodus', $cur); // UI zurück
                    $this->SendDebug('UI', 'Phase ignoriert: nicht Manuell', 0);
                    return;
                }

                $pm  = ((int)$Value === 3) ? 3 : 1;
                $old = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus'));
                if ($pm === $old) { return; }

                $this->SetValueSafe('Phasenmodus', $pm);

                $connected = in_array((int)@GetValue(@$this->GetIDForIdent('CarState')), [2,3,4], true);
                if ($connected) {
                    // Sequenz wird anderswo abgearbeitet
                    $this->WriteAttributeInteger('PendingPhaseMode', $pm);
                    $this->WriteAttributeInteger('PhaseSwitchState', 0);
                    $this->WriteAttributeInteger('LastPhaseSwitchMs', (int)(microtime(true) * 1000));
                    return;
                }

                // Kein Ladevorgang → direkt setzen
                $this->sendSet('psm', ($pm === 3) ? '2' : '1'); // go-e: 1=1P, 2=3P
                $nowMs = (int)(microtime(true) * 1000);
                $this->WriteAttributeInteger('LastPhaseMode', $pm);
                $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                return;
            }

            case 'FRC':
                $frc = in_array((int)$Value, [0,1,2], true) ? (int)$Value : 0;
                $this->setFRC($frc, 'UI');
                return;

            case 'PVShare_Pct':
                $pct = max(0, min(100, (int)$Value));
                $this->SetValueSafe('PVShare_Pct', $pct);
                return;
        }
        throw new Exception("Invalid Ident $Ident");
    }

    // -------------------------
    // Slow: Anzeige (1 Hz) – nur berechnen/anzeigen
    // -------------------------
    public function SLOW_TickUI(): void
    {
        $this->SetValueSafe('Uhrzeit', time());

        // NRG immer auswerten (Phasen & P_total)
        $nrgBuf = $this->mqttBufGet('nrg', null);
        if ($nrgBuf !== null && method_exists($this, 'parseAndStoreNRG')) {
            try { $this->parseAndStoreNRG($nrgBuf); } catch (\Throwable $e) {}
        }

        $U    = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $mode = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV, 1=Manuell, 2=Nur Anzeige, 3=PV-Anteil

        // ===== MANUELL (fix): Zieltext + reale/geschätzte WB-Leistung =====
        if ($mode === 1) {
            $pmUi  = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')); // 1|3 (UI)
            $aSel  = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
            $effPh = (int)$this->ReadAttributeInteger('WB_ActivePhases');   // 1|2|3 (aus NRG)
            if ($effPh < 1 || $effPh > 3) $effPh = ($pmUi === 3) ? 3 : 1;

            // reale WB-Leistung bevorzugt
            $wbW = 0;
            $nrg = $this->mqttBufGet('nrg', null);
            if (is_string($nrg)) { $t = @json_decode($nrg, true); if (is_array($t)) $nrg = $t; }
            if (is_array($nrg) && isset($nrg[11]) && is_numeric($nrg[11])) {
                $wbW = (int)round(max(0.0, (float)$nrg[11]));
            } elseif ((int)@GetValue(@$this->GetIDForIdent('FRC')) === 2 && $aSel > 0) {
                $wbW = (int)round($aSel * $U * $effPh);
            }
            $this->SetValueSafe('PowerToCar_W', $wbW);

            $phaseTxt = ($effPh===3 ? '3-phasig' : ($effPh===2 ? '2-phasig' : '1-phasig'));
            $wSet     = (int)round($aSel * $U * $effPh);
            $this->SetValueSafe('Regelziel', sprintf('Manuell · %s · %d A · ≈ %s kW', $phaseTxt, $aSel, $this->fmtKW($wSet)));
            return;
        }

        // ===== PV-AUTOMATIK / PV-ANTEIL =====
        $pv = (int)round((float)$this->readVarWUnit('VarPV_ID','VarPV_Unit'));

        // Batterie: nur Laden als Verbrauch
        $batt = (int)round((float)$this->readVarWUnit('VarBattery_ID','VarBattery_Unit'));
        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) { $batt = -$batt; }
        $battCharge = max(0, $batt);

        // Haus gesamt (inkl. WB)
        $houseTotal = (int)round((float)$this->readVarWUnit('VarHouse_ID','VarHouse_Unit'));

        // Status/Phasen
        $pmUi   = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')); // 1|3 (UI)
        $phEff  = (int)$this->ReadAttributeInteger('WB_ActivePhases');   // 1|2|3 (aus NRG)
        if ($phEff < 1 || $phEff > 3) $phEff = ($pmUi===3) ? 3 : 1;

        $frc       = (int)@GetValue(@$this->GetIDForIdent('FRC'));
        $car       = (int)@GetValue(@$this->GetIDForIdent('CarState'));
        $charging  = ($frc === 2) && ($car === 2);

        // Ladeleistung WB (NRG bevorzugen)
        $wbW = 0;
        $nrg = $this->mqttBufGet('nrg', null);
        if (is_string($nrg)) { $t = @json_decode($nrg, true); if (is_array($t)) $nrg = $t; }
        if (is_array($nrg) && isset($nrg[11]) && is_numeric($nrg[11])) {
            $wbW = (int)round(max(0.0, (float)$nrg[11]));
        } elseif ($charging) {
            $ampLive = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
            if ($ampLive > 0) $wbW = (int)round($ampLive * $U * max(1,$phEff));
        }
        $this->SetValueSafe('PowerToCar_W', $wbW);

        // Reserve + SoC
        $reserveW = (int)$this->ReadPropertyInteger('BatteryReserveW');
        $batSocID = (int)$this->ReadPropertyInteger('VarBatterySoc_ID');
        $batSoc   = ($batSocID>0 && @IPS_VariableExists($batSocID)) ? (float)@GetValue($batSocID) : -1.0;
        $minSoc   = (int)$this->ReadPropertyInteger('BatteryMinSocForPV');

        $connected   = in_array($car, [2,3,4], true);
        $battForCalc = ($connected && $batSoc >= 0 && $batSoc >= $minSoc) ? 0 : $battCharge;
        
        // Haus ohne WB (für PV-Anteil) – immer EMA verwenden, wenn vorhanden
        $houseNetAttr = (int)$this->ReadAttributeInteger('HouseNetEmaW');
        if ($houseNetAttr > 0) {
            $houseNet = $houseNetAttr;
        } else {
            $houseNetVid = @$this->GetIDForIdent('HouseNet_W');
            $houseNet    = $houseNetVid ? (int)@GetValue($houseNetVid) : ($houseTotal - $wbW);
        }
        
        $useShareMode = ($mode === 3);

        // Überschuss-Rohwert
        $surplusRaw = $useShareMode
            ? ($pv - $houseNet)                         // Überschuss vor Akku/Auto
            : ($pv - $battForCalc - $houseTotal + $wbW);// bestehende PV-Auto-Logik

        // EMA
        $alpha   = min(1.0, max(0.0, (int)$this->ReadPropertyInteger('SlowAlphaPermille')/1000.0));
        $emaPrev = (int)$this->ReadAttributeInteger('SlowSurplusW');
        $ema     = (int)round($alpha*$surplusRaw + (1.0-$alpha)*$emaPrev);
        $this->WriteAttributeInteger('SlowSurplusW', $ema);
        $this->WriteAttributeInteger('Slow_SurplusRaw', $surplusRaw);

        // Ziel
        if ($useShareMode) {
            $sliderPct = (int)@GetValue(@$this->GetIDForIdent('PVShare_Pct'));
            $sliderPct = max(0, min(100, $sliderPct));
            $fullOrNone = ($batSocID <= 0) || ($batSoc >= 99.0); // kein Akku oder voll
            $pctEff = $fullOrNone ? 100 : $sliderPct;

            $targetW = max(0, (int)round(($ema - max(0,$reserveW)) * $pctEff / 100.0));
            // KEINE SoC-Sperre im PV-Anteil-Modus!
        } else {
            $targetW = max(0, $ema - max(0, $reserveW));
            if ($batSoc >= 0 && $batSoc < $minSoc) { $targetW = 0; } // nur in PV-Auto
        }

        $this->WriteAttributeInteger('Slow_TargetW', (int)$targetW);
        $minTargetW = (int)$this->ReadPropertyInteger('TargetMinW');
        if ($targetW < $minTargetW) { $targetW = 0; }

        // Soll-Phasen/Ampere (Plan)
        [$pmCalc,$aCalc] = $this->targetPhaseAmp((int)$targetW);
        $pmCalcPh = ($pmCalc===3) ? 3 : 1;

        // Anzeige konsistent
        $dispPh = $charging ? $phEff : $pmCalcPh;

        if ($charging) {
            $dispA = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
        } else {
            $minA = (int)$this->ReadPropertyInteger('MinAmp');
            $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
            $dispA = ($dispPh > 0) ? (int)ceil($targetW / ($U * $dispPh)) : 0;
            $dispA = min($maxA, max($minA, $dispA));
        }

        $dispW    = (int)round($dispA * $U * max(1,$dispPh));
        $phaseTxt = ($dispPh===3?'3-phasig':($dispPh===2?'2-phasig':'1-phasig'));

        if ($useShareMode) {
            $sliderPctShow = (int)@GetValue(@$this->GetIDForIdent('PVShare_Pct')); // Anzeige bleibt wie eingestellt
            $this->SetValueSafe('Regelziel', sprintf(
                'PV-Anteil %d%% · %s · %d A · ≈ %s kW (PV-Ziel %s kW)',
                $sliderPctShow, $phaseTxt, max(0,$dispA), $this->fmtKW($dispW), $this->fmtKW($targetW)
            ));
        } else {
            $this->SetValueSafe('Regelziel', sprintf(
                '%s · %d A · ≈ %s kW (PV-Ziel %s kW)',
                $phaseTxt, max(0,$dispA), $this->fmtKW($dispW), $this->fmtKW($targetW)
            ));
        }
        $this->WriteAttributeInteger('Slow_LastCalcA', max(0,$aCalc));

        // Hysteresezähler
        $startW = (int)$this->ReadPropertyInteger('StartThresholdW');
        $stopW  = (int)$this->ReadPropertyInteger('StopThresholdW');
        $above  = ($surplusRaw >= $startW) ? min((int)$this->ReadAttributeInteger('Slow_AboveStartMs') + 1000, 3600000) : 0;
        $below  = ($surplusRaw <= $stopW)  ? min((int)$this->ReadAttributeInteger('Slow_BelowStopMs')  + 1000, 3600000) : 0;
        $this->WriteAttributeInteger('Slow_AboveStartMs', $above);
        $this->WriteAttributeInteger('Slow_BelowStopMs',  $below);

        // Phasenhist
        $thr3 = (int)$this->ReadPropertyInteger('ThresTo3p_W');
        $thr1 = (int)$this->ReadPropertyInteger('ThresTo1p_W');
        $p3   = ($surplusRaw >= $thr3) ? min((int)$this->ReadAttributeInteger('Phase_Above3pMs') + 1000, 3600000) : 0;
        $p1   = ($surplusRaw <= $thr1) ? min((int)$this->ReadAttributeInteger('Phase_Below1pMs') + 1000, 3600000) : 0;
        $this->WriteAttributeInteger('Phase_Above3pMs', $p3);
        $this->WriteAttributeInteger('Phase_Below1pMs', $p1);

        // Debug
        $fmtW = static fn($w)=>number_format((int)round($w),0,',','.') . ' W';
        $fmtA = static fn($a)=>number_format((int)round($a),0,',','.') . ' A';
        $phTxt = ($phEff===3?'3p':($phEff===2?'2p':'1p'));
        $this->dbgLog('PV-Überschuss', sprintf(
            '[%s] PV=%s - Batt(Laden)=%s - Haus=%s + WB=%s ⇒ Roh=%s | EMA=%s (α=%.2f) | Reserve=%s | Ziel=%s, ZielA=%s @ %d V · %s | SoC=%s%% (min %d%%)',
            ($useShareMode ? 'PV-Anteil' : 'PV-Auto'),
            $fmtW($pv), $fmtW($battCharge), $fmtW($houseTotal), $fmtW($wbW),
            $fmtW($surplusRaw), $fmtW((int)$ema), (int)$this->ReadPropertyInteger('SlowAlphaPermille')/1000.0,
            $fmtW($reserveW), $fmtW($targetW), $fmtA($aCalc), (int)$U, $phTxt,
            ($batSoc>=0? (int)round($batSoc): -1), $minSoc
        ));
    }

    public function SLOW_TickControl(): void
    {
        if (!$this->ReadPropertyBoolean('SlowControlEnabled')) return;

        $mode = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV,1=Manuell,2=Nur Anzeige,3=PV-Anteil
        if ($mode === 2) return;

        $nowMs   = (int)(microtime(true)*1000);
        $gapMs   = (int)$this->ReadPropertyInteger('MinPublishGapMs');
        $lastPub = (int)$this->ReadAttributeInteger('LastPublishMs');

        $frc       = (int)@GetValue(@$this->GetIDForIdent('FRC'));
        $car       = (int)@GetValue(@$this->GetIDForIdent('CarState'));
        $connected = in_array($car, [2,3,4], true);

        // ===== MANUELL (fix) =====
        if ($mode === 1) {
            if (!$connected) { // neutral warten
                if ($frc !== 0) { $this->dbgLog('MANUAL', 'not connected → frc→0'); $this->setFRC(0, 'manuell: nicht verbunden'); }
                return;
            }

            // Phasenwechsel-Sequenz (Stop -> psm -> Start)
            $pend   = (int)$this->ReadAttributeInteger('PendingPhaseMode');   // 0|1|3
            $pstate = (int)$this->ReadAttributeInteger('PhaseSwitchState');   // 0..3
            $tmark  = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
            $hold   = max(1500, (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs'));

            if ($pend !== 0) {
                $this->dbgLog('PHASE_SWITCH', sprintf('pending=%d state=%d dt=%dms', $pend, $pstate, $nowMs - $tmark));
                switch ($pstate) {
                    case 0: // Stop
                        if ($frc !== 1) { $this->dbgLog('PHASE_SWITCH', '→ STOP (frc→1)'); $this->setFRC(1, 'phase switch stop'); }
                        $this->WriteAttributeInteger('PhaseSwitchState', 1);
                        $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                        return;

                    case 1: // psm setzen
                        if ($nowMs - $tmark >= $hold) {
                            $this->dbgLog('PHASE_SWITCH', '→ SET PSM');
                            $this->sendSet('psm', ($pend===3)?'2':'1');
                            $this->WriteAttributeInteger('PhaseSwitchState', 2);
                            $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                        }
                        return;

                    case 2: // wieder starten
                        if ($nowMs - $tmark >= 1200) {
                            $this->dbgLog('PHASE_SWITCH', '→ START (frc→2)');
                            $this->setFRC(2, 'phase switch start');
                            $this->WriteAttributeInteger('PhaseSwitchState', 3);
                            $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                        }
                        return;

                    case 3: // Abschluss
                        if ($nowMs - $tmark >= 800) {
                            $this->dbgLog('PHASE_SWITCH', 'done');
                            $this->WriteAttributeInteger('PendingPhaseMode', 0);
                            $this->WriteAttributeInteger('PhaseSwitchState', 0);
                        }
                        break;
                }
            }

            // On-the-fly halten
            if (($nowMs - $lastPub) < $gapMs) { $this->dbgLog('MANUAL', sprintf('publish hold %dms < gap %dms', $nowMs-$lastPub, $gapMs)); return; }

            $pmUi = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')); // 1|3 (UI)
            $aSel = min(
                (int)$this->ReadPropertyInteger('MaxAmp'),
                max((int)$this->ReadPropertyInteger('MinAmp'),
                    (int)@GetValue(@$this->GetIDForIdent('Ampere_A')))
            );
            // Hauszuleitungsbegrenzung auch im manuellen Modus
            $Uman = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
            $phEffMan = ($pmUi===3) ? 3 : 1;
            $desiredWman = (int)($aSel * $Uman * max(1,$phEffMan));
            $limitedWman = (int)$this->applyHouseLimit((float)$desiredWman);
            if ($limitedWman < $desiredWman) {
                $limA = (int)floor($limitedWman / ($Uman * max(1,$phEffMan)));
                $minA = (int)$this->ReadPropertyInteger('MinAmp');
                $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
                $aSel = min($maxA, max($minA, max($limA, 0)));
                $this->dbgLog('HouseLimit', sprintf('manuell: desired=%dW → limited=%dW ⇒ amp=%d', $desiredWman, $limitedWman, $aSel));
            }


            $this->dbgLog('MANUAL', sprintf('hold psm=%s amp=%d frc=%d', ($pmUi===3?'3p':'1p'), $aSel, $frc));
            $this->sendSet('psm', ($pmUi===3)?'2':'1');
            $this->setCurrentLimitA($aSel);
            if ($frc !== 2) { $this->dbgLog('MANUAL', 'frc→2'); $this->setFRC(2, 'manuell halten'); }
            if ($vidA=@$this->GetIDForIdent('Ampere_A')) @SetValue($vidA, $aSel);
            $this->WriteAttributeInteger('LastAmpSet',    $aSel);
            $this->WriteAttributeInteger('LastPublishMs', $nowMs);
            return;
        }

        // ===== PV-AUTOMATIK (Mode 0) & PV-ANTEIL (Mode 3) =====
        $targetW    = (int)$this->ReadAttributeInteger('Slow_TargetW');

        // Hauszuleitungsbegrenzung anwenden
        $targetW = (int)$this->applyHouseLimit((float)$targetW);
        $minTargetW = (int)$this->ReadPropertyInteger('TargetMinW');

        // SoC-Gurt nur im PV-Auto (Mode==0), NICHT im PV-Anteil
        if ($mode !== 3) {
            $batSocID = (int)$this->ReadPropertyInteger('VarBatterySoc_ID');
            $batSoc   = ($batSocID>0 && @IPS_VariableExists($batSocID)) ? (float)@GetValue($batSocID) : -1.0;
            $minSoc   = (int)$this->ReadPropertyInteger('BatteryMinSocForPV');
            if ($batSoc >= 0 && $batSoc < $minSoc) { $this->dbgLog('START-DECIDE', sprintf('SoC guard active: %.1f < %d → targetW=0', $batSoc, $minSoc)); $targetW = 0; }
        }

        // DEBUG direkt vor Anti-Flackern / Startentscheidung
        $this->dbgLog('START-DECIDE', sprintf(
            'mode=%d connected=%d targetW=%d minTargetW=%d frc=%d bank=%.1f',
            $mode, (int)$connected, $targetW, $minTargetW,
            (int)@GetValue(@$this->GetIDForIdent('FRC')),
            (float)$this->ReadAttributeFloat('DeficitBankWh')
        ));

        // STOP: nicht verbunden oder Ziel zu klein -> neutral
        if (!$connected || $targetW < $minTargetW) {
            $reason = !$connected ? 'kein auto' : 'ziel < minTargetW';
            if ($frc !== 0) { $this->dbgLog('NEUTRAL', $reason.' → frc→0'); $this->setFRC(0, 'pv: '.$reason); }
            return;
        }

        // Anti-Flackern: nur wenn kein Phasenwechsel läuft
        $phaseSwitchActive = ((int)$this->ReadAttributeInteger('PhaseSwitchState') !== 0);
        if (!$phaseSwitchActive) {
            // *** WICHTIG: geglätteten Überschuss (EMA) verwenden ***
            $pvSurplusW = (int)$this->ReadAttributeInteger('SlowSurplusW');
            $this->dbgLog('AF_INPUT', sprintf('pvSurplusW(EMA)=%d', $pvSurplusW));

            $frcBefore = (int)@GetValue(@$this->GetIDForIdent('FRC'));
            $this->ApplyAntiFlackerLogic($pvSurplusW); // entscheidet ausschließlich über FRC 1/2
            $frc = (int)@GetValue(@$this->GetIDForIdent('FRC')); // aktualisieren
            if ($frc !== $frcBefore) $this->dbgLog('AF_RESULT', sprintf('FRC %d → %d', $frcBefore, $frc));
        } else {
            $this->dbgLog('AF_SKIP', 'phase switch active');
        }

        // Wenn FRC nicht frei oder Publish-Sperre aktiv -> raus
        if ($frc !== 2) { $this->WriteAttributeInteger('LastCarState', $car); $this->dbgLog('HOLD', 'frc != 2 → keine Regelung'); return; }
        if (($nowMs - $lastPub) < $gapMs) { $this->WriteAttributeInteger('LastCarState', $car); $this->dbgLog('HOLD', sprintf('publish hold %dms < gap %dms', $nowMs-$lastPub, $gapMs)); return; }
        if (!$connected) { $this->WriteAttributeInteger('LastCarState', $car); $this->dbgLog('HOLD', 'not connected (late)'); return; }

        // Auto-Phasenwechsel mit Sperrzeit + „Gating“ am Amp-Limit
        if ($this->ReadPropertyBoolean('AutoPhase')) {
            $holdMs = (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs');
            $lastSw = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
            if ($nowMs - $lastSw >= $holdMs) {
                $pmCur     = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')) ?: 1; // 1|3 (UI)
                $p3        = (int)$this->ReadAttributeInteger('Phase_Above3pMs');
                $p1        = (int)$this->ReadAttributeInteger('Phase_Below1pMs');
                $need3     = max(1,(int)$this->ReadPropertyInteger('To3pCycles')) * 1000;
                $need1     = max(1,(int)$this->ReadPropertyInteger('To1pCycles')) * 1000;
                $minA      = (int)$this->ReadPropertyInteger('MinAmp');
                $maxA      = (int)$this->ReadPropertyInteger('MaxAmp');
                $lastCalcA = (int)$this->ReadAttributeInteger('Slow_LastCalcA'); // SLOW_TickUI schreibt das

                // Hoch nur, wenn EMA lange genug hoch ODER (gleich), UND Ziel-Ampere am oberen Limit klebt
                if ($pmCur === 1 && $p3 >= $need3 && $lastCalcA >= $maxA) {
                    $this->dbgLog('PHASE_DECIDE', sprintf('→ 3p (p3 %dms ≥ %dms & lastCalcA %d ≥ maxA %d)', $p3, $need3, $lastCalcA, $maxA));
                    $this->sendSet('psm', '2'); if ($vidPM=@$this->GetIDForIdent('Phasenmodus')) @SetValue($vidPM,3);
                    $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                    $this->WriteAttributeInteger('LastCarState', $car);
                    return;
                }
                // Runter nur, wenn EMA lange genug niedrig ODER (gleich), UND Ziel-Ampere nahe Min
                if ($pmCur === 3 && $p1 >= $need1 && $lastCalcA <= $minA + 1) {
                    $this->dbgLog('PHASE_DECIDE', sprintf('→ 1p (p1 %dms ≥ %dms & lastCalcA %d ≤ minA+1 %d)', $p1, $need1, $lastCalcA, $minA+1));
                    $this->sendSet('psm', '1'); if ($vidPM=@$this->GetIDForIdent('Phasenmodus')) @SetValue($vidPM,1);
                    $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                    $this->WriteAttributeInteger('LastCarState', $car);
                    return;
                }
            } else {
                $this->dbgLog('PHASE_DECIDE', sprintf('hold %dms < %dms', $nowMs-$lastSw, $holdMs));
            }
        }

        // Feinregelung (2-Phasen aware)
        [$pmNow, /*$aIgnore*/] = $this->targetPhaseAmp($targetW);
        $U         = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $phEffAttr = (int)$this->ReadAttributeInteger('WB_ActivePhases');       // 1/2/3
        $phForCalc = ($phEffAttr === 2) ? 2 : (($pmNow === 3) ? 3 : 1);

        $minA  = (int)$this->ReadPropertyInteger('MinAmp');
        $maxA  = (int)$this->ReadPropertyInteger('MaxAmp');
        $vidA  = @$this->GetIDForIdent('Ampere_A');
        $curA  = $vidA ? (int)@GetValue($vidA) : (int)$this->ReadAttributeInteger('LastAmpSet');
        if ($curA <= 0) $curA = $minA;

        $targetA = (int)ceil($targetW / ($U * max(1,$phForCalc)));
        $targetA = min($maxA, max($minA, $targetA));
        if ($targetA === $curA) { $this->WriteAttributeInteger('LastCarState', $car); $this->dbgLog('RAMP', 'no change'); return; }

        $step  = (int)$this->ReadPropertyInteger('RampStepA'); if ($step <= 0) $step = 1;
        $nextA = ($targetA > $curA) ? min($curA + $step, $maxA) : max($curA - $step, $minA);

        $this->dbgLog('RAMP', sprintf('ph=%dp U=%dV curA=%d → nextA=%d (targetA=%d, step=%d) targetW=%d',
            $phForCalc, $U, $curA, $nextA, $targetA, $step, $targetW));

        $this->sendSet('psm', ($phForCalc >= 2) ? '2' : '1');  // 2-/3-phasig ⇒ '2'
        $this->sendSet('amp', (string)$nextA);
        if ($vidA) @SetValue($vidA, $nextA);
        $this->WriteAttributeInteger('LastAmpSet',    $nextA);
        $this->WriteAttributeInteger('LastPublishMs', $nowMs);
        $this->WriteAttributeInteger('LastCarState',  $car);
        return;
    }
    
    public function Loop(): void
    {
        // bewusst leer bzw. deaktiviert – Slow-Control übernimmt
        $this->SetTimerInterval('LOOP', 0);
    }

    // -------------------------
    // Hilfsrechner: Hausverbrauch ohne WB
    // -------------------------
    public function RecalcHausverbrauchAbzWallbox(bool $withLog = true): void
    {
        // Haus gesamt (positiv, inkl. WB)
        $houseTotal = (int)round((float)$this->readVarWUnit('VarHouse_ID','VarHouse_Unit'));

        // WB-Leistung aus eigener Variablen (positiv). Fallback: Trait.
        $wbVid = @$this->GetIDForIdent('PowerToCar_W');
        $wb    = $wbVid ? (int)@GetValue($wbVid) : (int)round(max(0.0, (float)$this->getWBPowerW()));
        if ($wb < 0) $wb = 0;

        // Haus ohne WB
        $houseNet = max(0, $houseTotal - $wb);
        $this->SetValueSafe('HouseNet_W', $houseNet);

        // Kompatibilität (falls vorhanden)
        if ($vid = @$this->GetIDForIdent('Hausverbrauch_abz_Wallbox')) { @SetValue($vid, $houseNet); }

        if ($withLog) {
            $fmt = static function (int $w): string { return number_format($w, 0, ',', '.'); };
            $this->dbgLog('HausNet', sprintf(
                'HausGesamt=%s W | WB=%s W → HausNet=%s W',
                $fmt($houseTotal), $fmt($wb), $fmt($houseNet)
            ));
        }
    }

    private function ApplyAntiFlackerLogic(int $pvSurplusW): void
    {
        // Konfiguration mit sinnvollen Defaults
        $StartW   = (int)($this->ReadPropertyInteger('StartThresholdW') ?: 1400);
        $StopW    = (int)($this->ReadPropertyInteger('StopThresholdW')  ?: -300);
        $MinOn_s  = (int)($this->ReadPropertyInteger('MinOn_s')  ?: max(1, (int)round(($this->ReadPropertyInteger('MinOnTimeMs')  ?: 60000) / 1000)));
        $MinOff_s = (int)($this->ReadPropertyInteger('MinOff_s') ?: max(1, (int)round(($this->ReadPropertyInteger('MinOffTimeMs') ?: 15000) / 1000)));
        $BufferWh = (int)($this->ReadPropertyInteger('DeficitBufferWh') ?: 40);

        // Sanity: Start-Schwelle sollte über Stop-Schwelle liegen
        if ($StartW <= $StopW) {
            $StopW = $StartW - 1;
        }

        $nowMs      = (int)(microtime(true) * 1000);
        $lastChange = (int)$this->ReadAttributeInteger('LastFrcChangeMs');
        $since_s    = max(0.0, ($nowMs - $lastChange) / 1000.0);

        // Energiekonto (Defizit positiv)
        $kontoWhPrev = (float)$this->ReadAttributeFloat('DeficitBankWh');
        $kontoWh     = $kontoWhPrev;
        $lastTs      = (int)$this->ReadAttributeInteger('LastBankTsMs');
        if ($lastTs <= 0) $lastTs = $nowMs;
        $dt_h = max(0.001, ($nowMs - $lastTs) / 3600000.0);
        $kontoWh += (-$pvSurplusW) * $dt_h;

        // Begrenzen & runden, um unnötige Flash-Writes zu vermeiden
        $maxWh  = max(2 * $BufferWh, 200.0);
        if ($kontoWh >  $maxWh) $kontoWh =  $maxWh;
        if ($kontoWh < -$maxWh) $kontoWh = -$maxWh;
        $kontoWh = round($kontoWh, 1);

        $this->WriteAttributeFloat('DeficitBankWh', $kontoWh);
        $this->WriteAttributeInteger('LastBankTsMs', $nowMs);

        // Aktueller FRC-Status (1=Stop, 2=Start; 0 wird hier nicht verwendet)
        $frcVid = @$this->GetIDForIdent('FRC');
        $frcIst = $frcVid ? (int)@GetValue($frcVid) : 1;
        if ($frcIst < 1 || $frcIst > 2) $frcIst = 1;

        // Entscheidung mit Hysterese + Mindestlaufzeiten + Energiekonto
        if ($frcIst !== 2) {
            // Start erlauben
            if ($pvSurplusW >= $StartW && $kontoWh <= -$BufferWh && $since_s >= $MinOff_s) {
                $this->setFRC(2, sprintf('AF: Start (PV=%d W, Bank=%.1f Wh, since=%.0fs)', $pvSurplusW, $kontoWh, $since_s));
            }
        } else {
            // Stop verlangen
            if ($pvSurplusW <= $StopW && $kontoWh >= $BufferWh && $since_s >= $MinOn_s) {
                $this->setFRC(1, sprintf('AF: Stop (PV=%d W, Bank=%.1f Wh, since=%.0fs)', $pvSurplusW, $kontoWh, $since_s));
            }
        }
    }

    private function setFRC(int $frc, string $reason = ''): void
    {
        $frc = in_array($frc, [0,1,2], true) ? $frc : 0;

        $vid = @$this->GetIDForIdent('FRC');
        $cur = $vid ? (int)@GetValue($vid) : -1;
        if ($cur === $frc) return;

        $this->sendSet('frc', (string)$frc);
        if ($vid) @SetValue($vid, $frc);

        $nowMs = (int)(microtime(true) * 1000);
        $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);

        if (method_exists($this, 'dbgLog')) {
            $this->dbgLog('FRC', sprintf('Set → %d (%s)', $frc, $reason));
        }
    }

    // -------------------------
    // Form
    // -------------------------
    public function GetConfigurationForm()
    {
        $U    = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $minA = (int)$this->ReadPropertyInteger('MinAmp');
        $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
        $thr3 = 3 * max(1, $minA) * $U;
        $thr1 = max(1, $maxA) * $U;
        $msHint = '⏱ 1 000 ms = 1 s · 10 000 ms = 10 s · 30 000 ms = 30 s';

        return json_encode([
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔌 Wallbox',
                    'items'   => [
                        ['type' => 'ValidationTextBox', 'name' => 'BaseTopic',      'caption' => 'Base-Topic (go-eCharger/285450)'],
                        ['type' => 'ValidationTextBox', 'name' => 'DeviceIDFilter', 'caption' => 'Device-ID Filter (optional)'],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'NumberSpinner', 'name' => 'MinAmp',      'caption' => 'Min. Ampere', 'minimum' => 1,   'maximum' => 32,  'suffix' => ' A'],
                                ['type' => 'NumberSpinner', 'name' => 'MaxAmp',      'caption' => 'Max. Ampere', 'minimum' => 1,   'maximum' => 32,  'suffix' => ' A'],
                                ['type' => 'NumberSpinner', 'name' => 'NominalVolt', 'caption' => 'Netzspannung','minimum' => 200, 'maximum' => 245, 'suffix' => ' V'],
                            ]
                        ],
                        ['type' => 'Label', 'caption' => "⚙️ Richtwerte: 3P Start ≈ {$thr3} W · 1P unter ≈ {$thr1} W"],
                    ]
,
[
    'type'    => 'ExpansionPanel',
    'caption' => '⚡ Hauszuleitungs-Wächter',
    'items'   => [
        ['type' => 'NumberSpinner',  'name' => 'MaxGridPowerW',  'caption' => 'Maximaler Leistungsbezug [W]', 'minimum' => 0, 'suffix' => ' W'],
        ['type' => 'SelectVariable','name' => 'HousePowerVarID', 'caption' => 'Variable Hausleistung (W)']
    ]
]

                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '⚡ Eingänge',
                    'items'   => [
                        ['type' => 'SelectVariable', 'name' => 'VarPV_ID',     'caption' => 'PV-Leistung'],
                        ['type' => 'SelectVariable', 'name' => 'VarHouse_ID',  'caption' => 'Haus gesamt (inkl. WB)'],
                        ['type' => 'SelectVariable', 'name' => 'VarBattery_ID','caption' => 'Batterie (optional)'],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'Select', 'name' => 'VarPV_Unit',     'caption' => 'PV Einheit',   'options' => [['caption' => 'W','value' => 'W'], ['caption' => 'kW','value' => 'kW']]],
                                ['type' => 'Select', 'name' => 'VarHouse_Unit',  'caption' => 'Haus Einheit', 'options' => [['caption' => 'W','value' => 'W'], ['caption' => 'kW','value' => 'kW']]],
                                ['type' => 'Select', 'name' => 'VarBattery_Unit','caption' => 'Batt Einheit', 'options' => [['caption' => 'W','value' => 'W'], ['caption' => 'kW','value' => 'kW']]],
                            ]
                        ],
                        ['type' => 'CheckBox',      'name' => 'BatteryPositiveIsCharge', 'caption' => '+ bedeutet Laden'],
                        ['type' => 'NumberSpinner', 'name' => 'WBSubtractMinW',          'caption' => 'WB-Abzug ab', 'suffix' => ' W'],
                        ['type' => 'Label',         'caption' => 'WB-Leistung erst ab diesem Wert vom Hausverbrauch abziehen.'],
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔋 Intelligente Batterie-Logik (PV zuerst Akku, dann Auto)',
                    'items'   => [
                        [
                            'type'       => 'SelectVariable',
                            'name'       => 'VarBatterySoc_ID',
                            'caption'    => 'Batterie-SoC Variable [%]',
                            'validTypes' => [1, 2],
                            'required'   => false,
                            'width'      => '600px'
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'BatteryMinSocForPV',
                            'caption' => 'Mindest-SoC bevor Auto laden darf',
                            'minimum' => 0,
                            'maximum' => 100,
                            'suffix'  => ' %',
                            'width'   => '200px'
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'BatteryReserveW',
                            'caption' => 'Haus-/Akku-Reserve',
                            'minimum' => 0,
                            'maximum' => 10000,
                            'suffix'  => ' W',
                            'width'   => '200px'
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Erlaubt Laden nur wenn SoC ≥ Mindest-SoC. ReserveW wird vom PV-Überschuss abgezogen.'
                        ]
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🐢 Slow-Control',
                    'items'   => [
                        ['type' => 'CheckBox',      'name' => 'SlowControlEnabled',  'caption' => 'aktiv'],
                        ['type' => 'NumberSpinner', 'name' => 'ControlIntervalSec',  'caption' => 'Regelintervall', 'minimum' => 10, 'maximum' => 30,  'suffix' => ' s'],
                        ['type' => 'NumberSpinner', 'name' => 'SlowAlphaPermille',   'caption' => 'Glättung α',     'minimum' => 0,  'maximum' => 1000,'suffix' => ' ‰'],
                        ['type' => 'Label',         'caption' => 'Anzeige 1 Hz live. Regelung ±1 A pro Intervall.'],
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🧯 Anti-Flackern',
                    'items'   => [
                        ['type' => 'Label', 'caption' => '↕ Hysterese: Start bei Überschuss ≥ Start, Stop erst bei ≤ Stop.'],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'NumberSpinner', 'name' => 'StartThresholdW','caption' => 'Start-Schwelle','minimum' => -10000,'maximum' => 20000,'suffix' => ' W'],
                                ['type' => 'NumberSpinner', 'name' => 'StopThresholdW', 'caption' => 'Stop-Schwelle', 'minimum' => -10000,'maximum' => 20000,'suffix' => ' W'],
                            ]
                        ],
                        ['type' => 'Label', 'caption' => '⏱ Mindestlaufzeiten verhindern Ping-Pong.'],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'NumberSpinner', 'name' => 'MinOn_s', 'caption' => 'Mindest-Ein', 'minimum' => 0, 'maximum' => 3600, 'suffix' => ' s'],
                                ['type' => 'NumberSpinner', 'name' => 'MinOff_s','caption' => 'Mindest-Aus', 'minimum' => 0, 'maximum' => 3600, 'suffix' => ' s'],
                            ]
                        ],
                        ['type' => 'Label', 'caption' => '⚡ Energiekonto: kurze Defizite puffern.'],
                        ['type' => 'NumberSpinner', 'name' => 'DeficitBufferWh','caption' => 'Defizit-Puffer','minimum' => 0,'maximum' => 2000,'suffix' => ' Wh'],
                    ]
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🪲 Debug',
                    'items'   => [
                        ['type' => 'CheckBox', 'name' => 'DebugPVWM', 'caption' => 'Modul-Debug (Regellogik)'],
                        ['type' => 'CheckBox', 'name' => 'DebugMQTT', 'caption' => 'MQTT-Rohdaten loggen'],
                        ['type' => 'Label',    'caption' => 'Ausgabe im Meldungen-Fenster.'],
                    ]
                ],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => $msHint]
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

}