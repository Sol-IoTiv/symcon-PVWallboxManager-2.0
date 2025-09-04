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

    // --- Start/Stop-Hysterese ---
    $this->RegisterPropertyInteger('StartThresholdW', 1400);
    $this->RegisterPropertyInteger('StopThresholdW', 1100);
    $this->RegisterPropertyInteger('StartCycles', 3);
    $this->RegisterPropertyInteger('StopCycles', 3);

    // --- Phasenumschaltung ---
    $this->RegisterPropertyInteger('ThresTo1p_W', 3680);
    $this->RegisterPropertyInteger('To1pCycles', 3);
    $this->RegisterPropertyInteger('ThresTo3p_W', 4140);
    $this->RegisterPropertyInteger('To3pCycles', 3);
    $this->RegisterPropertyBoolean('SnapOnConnect', true);
    $this->RegisterPropertyBoolean('AutoPhase', true);

    // --- Netz-/Strom-Parameter & Zeiten ---
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

    // --- Slow-Control (bleibt intern, kein eigener WebFront-Schalter) ---
    $this->RegisterPropertyBoolean('SlowControlEnabled', true);
    $this->RegisterPropertyInteger('ControlIntervalSec', 15);
    $this->RegisterPropertyInteger('SlowAlphaPermille', 250);
    $this->RegisterPropertyInteger('TargetMinW', 1380);

    // --- Profile ---
    $this->ensureProfiles(); // stellt u. a. PVWM.Mode, PVWM.FRC, PVWM.Phasen, GoE.Amp bereit

    // --- Kern-Variablen (WebFront) ---
    // 0=PV-Automatik, 1=Manuell (fix), 2=Nur Anzeige
    $this->RegisterVariableInteger('Mode', 'Lademodus', 'PVWM.Mode', 5);
    $this->EnableAction('Mode');

    $this->RegisterVariableInteger('Ampere_A', 'Ampere [A]', 'GoE.Amp', 10);
    $this->EnableAction('Ampere_A');

    // Ladeleistung zum Fahrzeug
    $this->RegisterVariableInteger('PowerToCar_W', 'Ladeleistung [W]', '~Watt', 20);

    $this->RegisterVariableInteger('HouseNet_W', 'Hausverbrauch (ohne WB) [W]', '~Watt', 21);

    $this->RegisterVariableInteger('CarState', 'Fahrzeugstatus', 'GoE.CarState', 25);

    // FRC deutsch beschriftet
    $this->RegisterVariableInteger('FRC', 'Ladefreigabe-Modus', 'PVWM.FRC', 40);
    $this->EnableAction('FRC');

    // 1=1p, 3=3p
    $this->RegisterVariableInteger('Phasenmodus', 'Phasenmodus', 'PVWM.Phasen', 50);
    $this->EnableAction('Phasenmodus');

    $this->RegisterVariableInteger('Uhrzeit', 'Uhrzeit', '~UnixTimestamp', 70);

    // Kompakte Zielanzeige (ersetzt einzelne Ziel-Variablen)
    $this->RegisterVariableString('Regelziel', 'Regelziel', '', 80);

    $this->RegisterVariableString('Ladechart', 'Ladechart', '~HTMLBox', 900);

    // --- Altlasten entfernen / migrieren ---
    @ $this->UnregisterVariable('SlowControlActive');     // „Slow-Regler aktiv“ entfällt
    @ $this->UnregisterVariable('TargetA_Live');          // ersetzt durch Regelziel
    @ $this->UnregisterVariable('TargetW_Live');          // ersetzt durch Regelziel
    @ $this->UnregisterVariable('TargetPhaseAmp');        // ersetzt durch Regelziel
    @ $this->UnregisterVariable('Leistung_W');            // durch PowerToCar_W ersetzt

    // --- Attribute ---
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

    $this->RegisterAttributeInteger('Slow_SurplusRaw', 0);
    $this->RegisterAttributeInteger('Slow_AboveStartMs', 0);
    $this->RegisterAttributeInteger('Slow_BelowStopMs', 0);

    $this->RegisterAttributeInteger('LastCarState', 0);
    $this->RegisterAttributeInteger('Phase_Above3pMs', 0);
    $this->RegisterAttributeInteger('Phase_Below1pMs', 0);

    $this->RegisterAttributeInteger('PendingPhaseMode', 0);   // 0=none, 1|3 Ziel
    $this->RegisterAttributeInteger('PhaseSwitchState', 0);   // 0 idle,1 stop,2 set,3 start

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
                $this->SetValueSafe('Mode', in_array((int)$Value,[0,1,2],true)?(int)$Value:0);
                $this->updateUiInteractivity();
                return;

            case 'Ampere_A': {
                if (!$this->isManualMode()) {
                    $cur = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
                    $this->SetValueSafe('Ampere_A', $cur); // UI zurück
                    $this->SendDebug('UI','Amp ignoriert: nicht Manuell',0);
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
                        $this->sendSet('frc','2'); @SetValue(@$this->GetIDForIdent('FRC'),2);
                    }
                    $nowMs = (int)(microtime(true)*1000);
                    $this->WriteAttributeInteger('LastAmpSet',    $a);
                    $this->WriteAttributeInteger('LastPublishMs', $nowMs);
                }
                return;
            }

            case 'Phasenmodus': {
                if (!$this->isManualMode()) {
                    $cur = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus'));
                    $this->SetValueSafe('Phasenmodus', $cur); // UI zurück
                    $this->SendDebug('UI','Phase ignoriert: nicht Manuell',0);
                    return;
                }

                $pm  = ((int)$Value === 3) ? 3 : 1;
                $old = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus'));
                if ($pm === $old) return;

                $this->SetValueSafe('Phasenmodus', $pm);

                $connected = in_array((int)@GetValue(@$this->GetIDForIdent('CarState')), [2,3,4], true);
                if ($connected) {
                    // Sequenz über Timer
                    $this->WriteAttributeInteger('PendingPhaseMode', $pm);
                    $this->WriteAttributeInteger('PhaseSwitchState', 0);
                    $this->WriteAttributeInteger('LastPhaseSwitchMs', (int)(microtime(true)*1000));
                    return;
                }

                // Kein Ladevorgang → direkt setzen
                $this->sendSet('psm', ($pm===3)?'2':'1'); // go-e: 1=1P, 2=3P
                $nowMs = (int)(microtime(true)*1000);
                $this->WriteAttributeInteger('LastPhaseMode', $pm);
                $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                return;
            }

            case 'FRC':
                $frc = in_array((int)$Value,[0,1,2],true)?(int)$Value:0;
                $this->SetValueSafe('FRC', $frc);
                $this->sendSet('frc', (string)$frc);
                return;

            case 'RenderLadechart':
                $this->RenderLadechart((int)$Value ?: 12);
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
        $mode = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV, 1=Manuell, 2=Nur Anzeige

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

        // ===== PV-AUTOMATIK =====
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

        // Überschuss + EMA
        $surplusRaw = max(0, $pv - $battForCalc - $houseTotal + $wbW);
        $alpha   = min(1.0, max(0.0, (int)$this->ReadPropertyInteger('SlowAlphaPermille')/1000.0));
        $emaPrev = (int)$this->ReadAttributeInteger('SlowSurplusW');
        $ema     = (int)round($alpha*$surplusRaw + (1.0-$alpha)*$emaPrev);
        $this->WriteAttributeInteger('SlowSurplusW', $ema);
        $this->WriteAttributeInteger('Slow_SurplusRaw', $surplusRaw);

        // Ziel
        $targetW = max(0, $ema - max(0, $reserveW));
        if ($batSoc >= 0 && $batSoc < $minSoc) { $targetW = 0; }
        $this->WriteAttributeInteger('Slow_TargetW', (int)$targetW);
        $minTargetW = (int)$this->ReadPropertyInteger('TargetMinW');
        if ($targetW < $minTargetW) { $targetW = 0; }

        // Soll-Phasen/Ampere (Plan)
        [$pmCalc,$aCalc] = $this->targetPhaseAmp((int)$targetW);
        $pmCalcPh = ($pmCalc===3) ? 3 : 1;

        // ===== Anzeige konsistent machen =====
        // Wenn wirklich am Laden → effektive Phasen und LIVE-Ampere anzeigen,
        // sonst geplante Phasen und aus Ziel-W die nötigen Ampere berechnen.
        $dispPh = $charging ? $phEff : $pmCalcPh;

        if ($charging) {
            $dispA = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
        } else {
            $minA = (int)$this->ReadPropertyInteger('MinAmp');
            $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
            $dispA = ($dispPh > 0) ? (int)ceil($targetW / ($U * $dispPh)) : 0;
            $dispA = min($maxA, max($minA, $dispA));
        }

        $dispW     = (int)round($dispA * $U * max(1,$dispPh));
        $phaseTxt  = ($dispPh===3?'3-phasig':($dispPh===2?'2-phasig':'1-phasig'));
        $this->SetValueSafe('Regelziel', sprintf(
            '%s · %d A · ≈ %s kW (PV-Ziel %s kW)',
            $phaseTxt, max(0,$dispA), $this->fmtKW($dispW), $this->fmtKW($targetW)
        ));
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
            'PV=%s - Batt(Laden)=%s - Haus=%s + WB=%s ⇒ Roh=%s | EMA=%s (α=%.2f) | Reserve=%s | Ziel=%s, ZielA=%s @ %d V · %s | SoC=%s%% (min %d%%)',
            $fmtW($pv), $fmtW($battCharge), $fmtW($houseTotal), $fmtW($wbW),
            $fmtW($surplusRaw), $fmtW((int)$ema), (int)$this->ReadPropertyInteger('SlowAlphaPermille')/1000.0,
            $fmtW($reserveW), $fmtW($targetW), $fmtA($aCalc), (int)$U, $phTxt,
            ($batSoc>=0? (int)round($batSoc): -1), $minSoc
        ));

        $this->RenderLadechart(12);

    }

    public function SLOW_TickControl(): void
    {
        if (!$this->ReadPropertyBoolean('SlowControlEnabled')) return;

        $mode = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV,1=Manuell,2=Aus
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
                if ($frc !== 0) { $this->sendSet('frc','0'); @SetValue(@$this->GetIDForIdent('FRC'),0); }
                return;
            }

            // Phasenwechsel-Sequenz (Stop -> psm -> Start)
            $pend   = (int)$this->ReadAttributeInteger('PendingPhaseMode');   // 0|1|3
            $pstate = (int)$this->ReadAttributeInteger('PhaseSwitchState');   // 0..3
            $tmark  = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
            $hold   = max(1500, (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs'));

            if ($pend !== 0) {
                switch ($pstate) {
                    case 0: // Stop
                        if ($frc !== 1) { $this->sendSet('frc','1'); @SetValue(@$this->GetIDForIdent('FRC'),1); }
                        $this->WriteAttributeInteger('PhaseSwitchState', 1);
                        $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                        return;

                    case 1: // psm setzen
                        if ($nowMs - $tmark >= $hold) {
                            $this->sendSet('psm', ($pend===3)?'2':'1');
                            $this->WriteAttributeInteger('PhaseSwitchState', 2);
                            $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                        }
                        return;

                    case 2: // wieder starten
                        if ($nowMs - $tmark >= 1200) {
                            $this->sendSet('frc','2'); @SetValue(@$this->GetIDForIdent('FRC'),2);
                            $this->WriteAttributeInteger('PhaseSwitchState', 3);
                            $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                        }
                        return;

                    case 3: // Abschluss
                        if ($nowMs - $tmark >= 800) {
                            $this->WriteAttributeInteger('PendingPhaseMode', 0);
                            $this->WriteAttributeInteger('PhaseSwitchState', 0);
                        }
                        break;
                }
            }

            // On-the-fly halten
            if (($nowMs - $lastPub) < $gapMs) return;

            $pmUi = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')); // 1|3 (UI)
            $aSel = min(
                (int)$this->ReadPropertyInteger('MaxAmp'),
                max((int)$this->ReadPropertyInteger('MinAmp'),
                    (int)@GetValue(@$this->GetIDForIdent('Ampere_A')))
            );

            $this->sendSet('psm', ($pmUi===3)?'2':'1');
            $this->setCurrentLimitA($aSel);
            if ($frc !== 2) { $this->sendSet('frc','2'); @SetValue(@$this->GetIDForIdent('FRC'),2); }
            if ($vidA=@$this->GetIDForIdent('Ampere_A')) @SetValue($vidA, $aSel);
            $this->WriteAttributeInteger('LastAmpSet',    $aSel);
            $this->WriteAttributeInteger('LastPublishMs', $nowMs);
            return;
        }

        // ===== PV-AUTOMATIK =====
        $targetW    = (int)$this->ReadAttributeInteger('Slow_TargetW');
        $minTargetW = (int)$this->ReadPropertyInteger('TargetMinW');

        // SoC-Gurt nur hier
        $batSocID = (int)$this->ReadPropertyInteger('VarBatterySoc_ID');
        $batSoc   = ($batSocID>0 && @IPS_VariableExists($batSocID)) ? (float)@GetValue($batSocID) : -1.0;
        $minSoc   = (int)$this->ReadPropertyInteger('BatteryMinSocForPV');
        if ($batSoc >= 0 && $batSoc < $minSoc) { $targetW = 0; }

        // STOP
        if (!$connected || $targetW < $minTargetW) {
            $targetFrc = 0; // neutral warten
            if ($frc !== $targetFrc) {
                $this->sendSet('frc', (string)$targetFrc);
                if ($vid=@$this->GetIDForIdent('FRC')) @SetValue($vid, $targetFrc);
            }
            return;
        }

        // START
        if ($frc !== 2 && ($nowMs - $lastPub) >= $gapMs) {
            [$pmPlan, /*$aPlan*/] = $this->targetPhaseAmp($targetW); // pm: 1|3
            $U         = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
            $phEffAttr = (int)$this->ReadAttributeInteger('WB_ActivePhases');   // 1/2/3 (aus NRG)
            $phForCalc = ($phEffAttr === 2) ? 2 : (($pmPlan === 3) ? 3 : 1);    // 2-phasig bevorzugen
            $minA      = (int)$this->ReadPropertyInteger('MinAmp');
            $maxA      = (int)$this->ReadPropertyInteger('MaxAmp');
            $aStart    = (int)ceil($targetW / ($U * max(1,$phForCalc)));
            $aStart    = min($maxA, max($minA, $aStart));
            $psmWanted = ($phForCalc >= 2) ? '2' : '1';

            $this->sendSet('psm', $psmWanted);
            $this->setCurrentLimitA($aStart);
            $this->sendSet('frc', '2');
            if ($vidA=@$this->GetIDForIdent('Ampere_A')) @SetValue($vidA, $aStart);
            $this->WriteAttributeInteger('LastAmpSet',    $aStart);
            $this->WriteAttributeInteger('LastPublishMs', $nowMs);
            return;
        } // <-- fehlende Klammer war hier

        // FRC nicht frei / Timing nicht ok
        if ($frc !== 2 || ($nowMs - $lastPub) < $gapMs) { $this->WriteAttributeInteger('LastCarState', $car); return; }
        if (!$connected) { $this->WriteAttributeInteger('LastCarState', $car); return; }

        // Auto-Phasenwechsel mit Sperrzeit
        if ($this->ReadPropertyBoolean('AutoPhase')) {
            $holdMs = (int)$this->ReadPropertyInteger('MinHoldAfterPhaseMs');
            $lastSw = (int)$this->ReadAttributeInteger('LastPhaseSwitchMs');
            if ($nowMs - $lastSw >= $holdMs) {
                $pmCur = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')) ?: 1; // 1|3 (UI)
                $p3    = (int)$this->ReadAttributeInteger('Phase_Above3pMs');
                $p1    = (int)$this->ReadAttributeInteger('Phase_Below1pMs');
                $need3 = max(1,(int)$this->ReadPropertyInteger('To3pCycles')) * 1000;
                $need1 = max(1,(int)$this->ReadPropertyInteger('To1pCycles')) * 1000;

                if ($pmCur === 1 && $p3 >= $need3) {
                    $this->sendSet('psm', '2'); if ($vidPM=@$this->GetIDForIdent('Phasenmodus')) @SetValue($vidPM,3);
                    $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                    $this->WriteAttributeInteger('LastCarState', $car);
                    return;
                }
                if ($pmCur === 3 && $p1 >= $need1) {
                    $this->sendSet('psm', '1'); if ($vidPM=@$this->GetIDForIdent('Phasenmodus')) @SetValue($vidPM,1);
                    $this->WriteAttributeInteger('LastPhaseSwitchMs', $nowMs);
                    $this->WriteAttributeInteger('LastCarState', $car);
                    return;
                }
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
        if ($targetA === $curA) { $this->WriteAttributeInteger('LastCarState', $car); return; }

        $step  = (int)$this->ReadPropertyInteger('RampStepA'); if ($step <= 0) $step = 1;
        $nextA = ($targetA > $curA) ? min($curA + $step, $maxA) : max($curA - $step, $minA);

        $this->sendSet('psm', ($phForCalc >= 2) ? '2' : '1');  // 2-/3-phasig ⇒ '2'
        $this->sendSet('amp', (string)$nextA);
        if ($vidA) @SetValue($vidA, $nextA);
        $this->WriteAttributeInteger('LastAmpSet',    $nextA);
        $this->WriteAttributeInteger('LastPublishMs', $nowMs);
        $this->WriteAttributeInteger('LastCarState',  $car);
    }

    // -------------------------
    // Klassik-Loop bleibt verfügbar, wird aber in Slow nicht benutzt
    // -------------------------
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
                            ['type' => 'Select', 'name' => 'VarPV_Unit',     'caption' => 'PV Einheit',   'options' => [['caption' => 'W','value' => 'W'],   ['caption' => 'kW','value' => 'kW']]],
                            ['type' => 'Select', 'name' => 'VarHouse_Unit',  'caption' => 'Haus Einheit', 'options' => [['caption' => 'W','value' => 'W'],   ['caption' => 'kW','value' => 'kW']]],
                            ['type' => 'Select', 'name' => 'VarBattery_Unit','caption' => 'Batt Einheit', 'options' => [['caption' => 'W','value' => 'W'],   ['caption' => 'kW','value' => 'kW']]],
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
                        'validTypes' => [1, 2], // Integer/Float
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
