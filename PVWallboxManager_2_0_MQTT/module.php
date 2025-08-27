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
        $this->RegisterPropertyString('BaseTopic', '');            // leer => Auto
        $this->RegisterAttributeString('AutoBaseTopic', '');       // erkannter Stamm
        $this->RegisterPropertyString('DeviceIDFilter', '');       // z.B. "285450"
        $this->RegisterAttributeString('MQTT_BUF', '{}');

        // --- Energiequellen ---
        $this->RegisterPropertyInteger('VarPV_ID', 0);
        $this->RegisterPropertyString('VarPV_Unit', 'W');
        $this->RegisterPropertyInteger('VarHouse_ID', 0);
        $this->RegisterPropertyString('VarHouse_Unit', 'W');
        $this->RegisterPropertyInteger('VarBattery_ID', 0);
        $this->RegisterPropertyString('VarBattery_Unit', 'W');
        $this->RegisterPropertyBoolean('BatteryPositiveIsCharge', true);

        // --- Start/Stop-Hysterese ---
        $this->RegisterPropertyInteger('StartThresholdW', 1400);
        $this->RegisterPropertyInteger('StopThresholdW', 1100);
        $this->RegisterPropertyInteger('StartCycles', 3);
        $this->RegisterPropertyInteger('StopCycles', 3);

        // --- Phasenumschaltung (Konfig beibehalten, Slow nutzt sie nicht aktiv) ---
        $this->RegisterPropertyInteger('ThresTo1p_W', 3680);
        $this->RegisterPropertyInteger('To1pCycles', 3);
        $this->RegisterPropertyInteger('ThresTo3p_W', 4140);
        $this->RegisterPropertyInteger('To3pCycles', 3);

        // --- Netz-/Strom-Parameter & Zeiten ---
        $this->RegisterPropertyInteger('MinAmp', 6);
        $this->RegisterPropertyInteger('MaxAmp', 16);
        $this->RegisterPropertyInteger('NominalVolt', 230);
        $this->RegisterPropertyInteger('MinHoldAfterPhaseMs', 30000);
        $this->RegisterPropertyInteger('MinPublishGapMs', 5000);   // Slow: 5 s reichen
        $this->RegisterPropertyInteger('WBSubtractMinW', 100);
        $this->RegisterPropertyInteger('StartReserveW', 200);
        $this->RegisterPropertyInteger('MinOnTimeMs',  60000);
        $this->RegisterPropertyInteger('MinOffTimeMs', 15000);

        // --- Slow-Control (neu) ---
        $this->RegisterPropertyBoolean('SlowControlEnabled', true);
        $this->RegisterPropertyInteger('ControlIntervalSec', 15);      // 10..30 s
        $this->RegisterPropertyInteger('SlowAlphaPermille', 250);      // 0..1000 (0.25)

        // --- Profile ---
        $this->ensureProfiles();

        // --- Kern-Variablen ---
        $this->RegisterVariableInteger('Mode', 'Lademodus', 'PVWM.Mode', 5); // 0=PV, 1=Manuell, 2=Aus
        $this->EnableAction('Mode');

        $this->RegisterVariableInteger('Ampere_A',   'Ampere [A]', 'GoE.Amp', 10);
        $this->EnableAction('Ampere_A');

        $this->RegisterVariableInteger('Leistung_W', 'Leistung [W]', '~Watt', 20);
        $this->RegisterVariableInteger('HouseNet_W', 'Hausverbrauch (ohne WB) [W]', '~Watt', 21);
        $this->RegisterVariableInteger('CarState',   'Fahrzeugstatus', 'GoE.CarState', 25);

        $this->RegisterVariableInteger('FRC', 'Force State (FRC)', 'GoE.ForceState', 50);
        $this->EnableAction('FRC');

        $this->RegisterVariableInteger('Phasenmodus', 'Phasenmodus', 'GoE.PhaseMode', 60); // 1=1p, 2=3p
        $this->EnableAction('Phasenmodus');

        $this->RegisterVariableInteger('Uhrzeit', 'Uhrzeit', '~UnixTimestamp', 70);

        // --- Slow UI/Control Variablen ---
        $this->RegisterVariableBoolean('SlowControlActive', 'Slow-Regler aktiv', '~Switch', 6);
        $this->EnableAction('SlowControlActive');
        $this->RegisterVariableInteger('TargetA_Live', 'Ziel Ampere (live)', 'GoE.Amp', 12);
        $this->RegisterVariableInteger('TargetW_Live', 'Zielleistung (live)', '~Watt', 13);

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
        $this->RegisterAttributeInteger('SmoothSurplusW', 0);
        $this->RegisterAttributeInteger('LastAmpChangeMs', 0);
        $this->RegisterAttributeInteger('Slow_LastCalcA', 0);

        $this->RegisterAttributeInteger('Slow_SurplusRaw', 0);
        $this->RegisterAttributeInteger('Slow_AboveStartMs', 0);
        $this->RegisterAttributeInteger('Slow_BelowStopMs', 0);

        // --- Timer ---
        $this->RegisterTimer('LOOP', 0, $this->modulePrefix().'_Loop($_IPS["TARGET"]);'); // bleibt vorhanden, aber in Slow aus
        //$this->RegisterTimer('SLOW_TickUI', 0, $this->modulePrefix().'_SLOW_TickUI($_IPS["TARGET"]);');
        $this->RegisterTimer('SLOW_TickUI', 0, 'IPS_RequestAction($_IPS["TARGET"], "DoSlowTickUI", 0);');
        $this->RegisterTimer('SLOW_TickControl', 0, 'IPS_RequestAction($_IPS["TARGET"], "DoSlowTickControl", 0);');
        //$this->RegisterTimer('SLOW_TickControl', 0, $this->modulePrefix().'_SLOW_TickControl($_IPS["TARGET"]);');
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
        if ($wbVid = @$this->GetIDForIdent('Leistung_W')) { @ $this->RegisterMessage($wbVid, VM_UPDATE); }

        // Timer: Slow aktiv, klassische LOOP aus
        $this->SetTimerInterval('LOOP', 0);
        $ctrl = max(10, min(30, (int)$this->ReadPropertyInteger('ControlIntervalSec')));
        $this->SetTimerInterval('SLOW_TickUI', 1000);
        $this->SetTimerInterval('SLOW_TickControl', $this->ReadPropertyBoolean('SlowControlEnabled') ? $ctrl*1000 : 0);

        // Initiale Berechnung
        $this->RecalcHausverbrauchAbzWallbox(true);
        $this->SetStatus(IS_ACTIVE);
    }

    // -------------------------
    // Events
    // -------------------------
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) return;
        $houseId = (int)$this->ReadPropertyInteger('VarHouse_ID');
        $wbVid   = @$this->GetIDForIdent('Leistung_W');
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
            case 'Mode':
                $mode = in_array((int)$Value, [0,1,2], true) ? (int)$Value : 0;
                $this->SetValueSafe('Mode', $mode);
                break;
            case 'SlowControlActive':
                $this->SetValueSafe('SlowControlActive', (bool)$Value);
                break;
            case 'Ampere_A':
                $this->setCurrentLimitA((int)$Value);
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
            case 'DoSlowTickUI':
                $this->SLOW_TickUI();
                return;
            case 'DoSlowTickControl':
                $this->SLOW_TickControl();
                return;
        }
    }

    // -------------------------
    // Slow: Anzeige (1 Hz) – nur berechnen/anzeigen
    // -------------------------
    public function SLOW_TickUI(): void
    {
        $this->SetValueSafe('Uhrzeit', time());

        // Eingänge
        $pv = (int)round((float)$this->readVarWUnit('VarPV_ID','VarPV_Unit'));

        // Batterie: nur Laden als Verbrauch
        $batt = (int)round((float)$this->readVarWUnit('VarBattery_ID','VarBattery_Unit'));
        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) { $batt = -$batt; }
        $battCharge = max(0, $batt);

        // Haus gesamt (inkl. WB)
        $houseTotal = (int)round((float)$this->readVarWUnit('VarHouse_ID','VarHouse_Unit'));

        // NRG-Buffer frisch parsen (falls vorhanden)
        $nrgBuf = $this->mqttBufGet('nrg', null);
        if ($nrgBuf !== null && method_exists($this, 'parseAndStoreNRG')) {
            try { $this->parseAndStoreNRG($nrgBuf); } catch (\Throwable $e) {}
        }

        // Status / Netz
        $pm = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')); // 1=1p, 2=3p
        $phEff = (int)$this->ReadAttributeInteger('WB_ActivePhases');
        if ($phEff < 1 || $phEff > 3) { $phEff = ($pm===2) ? 3 : 1; }
        $U    = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $minA = (int)$this->ReadPropertyInteger('MinAmp');
        $maxA = (int)$this->ReadPropertyInteger('MaxAmp');

        // Laden aktiv?
        $frc = (int)@GetValue(@$this->GetIDForIdent('FRC'));      // 2 = Start
        $car = (int)@GetValue(@$this->GetIDForIdent('CarState')); // 2 = lädt
        $charging = ($frc === 2) && ($car === 2);

        // WB-Leistung: NRG[11] (nur wenn geladen), sonst 0; Fallback aus A*U*Phasen
        $wbW = 0;
        if ($charging) {
            $nrg = $this->mqttBufGet('nrg', null);
            if (is_string($nrg)) { $t = @json_decode($nrg, true); if (is_array($t)) $nrg = $t; }
            if (is_array($nrg) && array_key_exists(11, $nrg) && is_numeric($nrg[11])) {
                $wbW = (int)round(max(0.0, (float)$nrg[11]));
            }
            if ($wbW <= 0) {
                $ampLive = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
                $wbW = (int)round($ampLive * $U * max(1, $phEff));
            }
        }
        $this->SetValueSafe('Leistung_W', $wbW);

        // --- Überschuss für Wallbox: PV − Batt(Laden) − Haus + WB ---
        $surplusRaw = max(0, $pv - $battCharge - $houseTotal + $wbW);

        // Glättung (EMA) für Anzeige/Ziel
        $alpha   = min(1.0, max(0.0, (int)$this->ReadPropertyInteger('SlowAlphaPermille')/1000.0));
        $prevEMA = (int)$this->ReadAttributeInteger('SmoothSurplusW');
        $surplus = (int)round($alpha*$surplusRaw + (1.0-$alpha)*$prevEMA);
        $this->WriteAttributeInteger('SmoothSurplusW', $surplus);

        // Zielwerte aus geglättetem Überschuss
        $targetW = $surplus;
        $targetA = (int)ceil($targetW / ($U * max(1,$phEff)));
        $targetA = max($minA, min($maxA, $targetA));
        $this->SetValueSafe('TargetW_Live', $targetW);
        $this->SetValueSafe('TargetA_Live', $targetA);
        $this->WriteAttributeInteger('Slow_LastCalcA', $targetA);

        // Sekundenzähler für schnellen FRC (auf Basis der Roh-Formel)
        $this->WriteAttributeInteger('Slow_SurplusRaw', $surplusRaw);
        $startW = (int)$this->ReadPropertyInteger('StartThresholdW');
        $stopW  = (int)$this->ReadPropertyInteger('StopThresholdW');
        $above  = (int)$this->ReadAttributeInteger('Slow_AboveStartMs');
        $below  = (int)$this->ReadAttributeInteger('Slow_BelowStopMs');
        $above  = ($surplusRaw >= $startW) ? min($above + 1000, 3600000) : 0;
        $below  = ($surplusRaw <= $stopW)  ? min($below + 1000, 3600000) : 0;
        $this->WriteAttributeInteger('Slow_AboveStartMs', $above);
        $this->WriteAttributeInteger('Slow_BelowStopMs',  $below);
    }

    public function SLOW_TickControl(): void
    {
        // Slow-Regler aktiv?
        if (!(bool)@GetValue(@$this->GetIDForIdent('SlowControlActive'))) return;

        // AUS-Modus?
        $mode = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV, 1=Manuell, 2=Aus
        if ($mode === 2) return;

        $nowMs   = (int)(microtime(true)*1000);
        $gapMs   = (int)$this->ReadPropertyInteger('MinPublishGapMs');
        $lastPub = (int)$this->ReadAttributeInteger('LastPublishMs');
        if ($nowMs - $lastPub < $gapMs) return;

        // Start/Stop aus Sekundenzählern (roher Überschuss)
        $aboveMs = (int)$this->ReadAttributeInteger('Slow_AboveStartMs');
        $belowMs = (int)$this->ReadAttributeInteger('Slow_BelowStopMs');
        $startHoldMs = max(1000, (int)$this->ReadPropertyInteger('StartCycles') * 1000);
        $stopHoldMs  = max(1000, (int)$this->ReadPropertyInteger('StopCycles')  * 1000);
        $startOk = ($aboveMs >= $startHoldMs);
        $stopOk  = ($belowMs >= $stopHoldMs);

        // Mindestlaufzeiten
        $lastFR  = (int)$this->ReadAttributeInteger('LastFrcChangeMs');
        $onHold  = ($nowMs - $lastFR) < (int)$this->ReadPropertyInteger('MinOnTimeMs');
        $offHold = ($nowMs - $lastFR) < (int)$this->ReadPropertyInteger('MinOffTimeMs');

        $frcCur = (int)@GetValue(@$this->GetIDForIdent('FRC'));

        // STOP bei fehlendem Überschuss
        if ($stopOk && !$onHold && $frcCur !== 1) {
            $this->sendSet('frc', '1');
            $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
            $this->dbgLog('SLOW', 'FRC=Stop');
            return;
        }

        // START bei Überschuss
        if ($startOk && !$offHold && $frcCur !== 2) {
            $this->sendSet('frc', '2');
            $this->WriteAttributeInteger('LastFrcChangeMs', $nowMs);
            $this->dbgLog('SLOW', 'FRC=Start');
            // weiter: Amp regeln
        }

        // Nur regeln, wenn Laden erzwungen/aktiv
        if ((int)@GetValue(@$this->GetIDForIdent('FRC')) !== 2) return;

        // Ziel-Amp aus 1-Hz-Tick
        $targetA = (int)$this->ReadAttributeInteger('Slow_LastCalcA');
        if ($targetA <= 0) return;

        // Ist-Amp
        $minA = (int)$this->ReadPropertyInteger('MinAmp');
        $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
        $vidA = @$this->GetIDForIdent('Ampere_A');
        $curA = $vidA ? (int)@GetValue($vidA) : (int)$this->ReadAttributeInteger('LastAmpSet');
        if ($curA <= 0) $curA = $minA;

        if ($targetA === $curA) return;

        // ±1 A Schritt
        $nextA = ($targetA > $curA) ? $curA + 1 : $curA - 1;
        $nextA = max($minA, min($maxA, $nextA));

        // Senden
        $this->sendSet('amp', (string)$nextA);
        if ($vidA) @SetValue($vidA, $nextA);
        $this->WriteAttributeInteger('LastAmpSet',    $nextA);
        $this->WriteAttributeInteger('LastPublishMs', $nowMs);
        $this->dbgLog('RAMP_SLOW', sprintf('A %d → %d (Ziel=%d)', $curA, $nextA, $targetA));
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
    // Hilfsrechner: Hausverbrauch ohne WB (deine letzte Version, robust)
    // -------------------------
    public function RecalcHausverbrauchAbzWallbox(bool $withLog = true): void
    {
        $houseTotal = $this->readVarWUnit('VarHouse_ID','VarHouse_Unit');

        // Ladezustand
        $frc = (int)@GetValue(@$this->GetIDForIdent('FRC'));      // 1=Stop, 2=Start
        $car = (int)@GetValue(@$this->GetIDForIdent('CarState')); // 2 = lädt
        $charging = method_exists($this, 'isChargingActive')
            ? (bool)$this->isChargingActive()
            : ($frc === 2 && $car === 2);

        // WB-Leistung: bevorzugt NRG[11]
        $wbRaw = 0.0;
        if ($charging && $frc === 2) {
            $nrg = $this->mqttBufGet('nrg', null);
            if (is_string($nrg)) { $t = @json_decode($nrg, true); if (is_array($t)) $nrg = $t; }
            if (is_array($nrg) && array_key_exists(11, $nrg) && is_numeric($nrg[11])) {
                $wbRaw = (float)$nrg[11];
            } else {
                $wbRaw = (float)$this->getWBPowerW();
            }
            if ($wbRaw < 0) $wbRaw = 0.0;
        } else {
            // nicht laden → Filter zurücksetzen
            $this->WriteAttributeInteger('WB_W_Smooth', 0);
            $this->WriteAttributeInteger('WB_SubtractActive', 0);
        }

        $minWB = max(0, (int)$this->ReadPropertyInteger('WBSubtractMinW'));

        // Batterie: + = Laden zählt als Verbrauch, − = 0
        $batt = $this->readVarWUnit('VarBattery_ID','VarBattery_Unit');
        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) { $batt = -$batt; }
        $battCharge = max(0, (int)round($batt));

        // EMA-Glättung
        $alphaWB = 0.4;
        $wbPrev  = (int)$this->ReadAttributeInteger('WB_W_Smooth');
        if ($wbPrev <= 0) { $wbPrev = (int)round($wbRaw); }
        $wbSmooth = (int)round($alphaWB * $wbRaw + (1.0 - $alphaWB) * $wbPrev);
        $this->WriteAttributeInteger('WB_W_Smooth', $wbSmooth);

        // Hysterese „WB abziehen?“
        $onW = $minWB;
        $offW = (int)max(0, $minWB - 120);
        $active = (int)$this->ReadAttributeInteger('WB_SubtractActive') === 1;
        $lastChg= (int)$this->ReadAttributeInteger('WB_SubtractChangedMs');
        $nowMs  = (int)(microtime(true)*1000);
        $holdMs = 4000;

        if ($active) {
            if ($wbSmooth <= $offW && ($nowMs - $lastChg) >= $holdMs) { $active = false; $this->WriteAttributeInteger('WB_SubtractChangedMs', $nowMs); }
        } else {
            if ($wbSmooth >= $onW  && ($nowMs - $lastChg) >= $holdMs) { $active = true;  $this->WriteAttributeInteger('WB_SubtractChangedMs', $nowMs); }
        }
        if (!$charging || $frc !== 2) { $active = false; }
        $this->WriteAttributeInteger('WB_SubtractActive', $active ? 1 : 0);

        $wbEff = $active ? $wbSmooth : 0;

        // Haus ohne WB, Batterie-LADEN als Verbrauch ADDIEREN
        $houseNet = max(0, (int)round($houseTotal - $wbEff + $battCharge));
        $this->SetValueSafe('HouseNet_W', $houseNet);

        if ($vid = @$this->GetIDForIdent('Hausverbrauch_abz_Wallbox')) { @SetValue($vid, $houseNet); }

        if ($withLog) {
            $fmt = static function (int $w): string { return number_format($w, 0, ',', '.'); };
            $this->dbgLog('HausNet', sprintf(
                'HausGesamt=%s W | WB raw=%s W, smooth=%s W, eff=%s W [%s] (Schwelle on=%s/off=%s) | Batt(Laden)=%s W → HausNet=%s W',
                $fmt((int)round((float)$houseTotal)),
                $fmt((int)round((float)$wbRaw)),
                $fmt((int)round((float)$wbSmooth)),
                $fmt((int)round((float)$wbEff)),
                $active ? 'aktiv' : 'inaktiv',
                $fmt($onW),
                $fmt($offW),
                $fmt($battCharge),
                $fmt($houseNet)
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
                [ 'type'=>'ExpansionPanel','caption'=>'🔌 Wallbox','items'=>[
                    ['type'=>'ValidationTextBox','name'=>'BaseTopic','caption'=>'Base-Topic (go-eCharger/285450)'],
                    ['type'=>'ValidationTextBox','name'=>'DeviceIDFilter','caption'=>'Device-ID Filter (optional)'],
                    ['type'=>'RowLayout','items'=>[
                        ['type'=>'NumberSpinner','name'=>'MinAmp','caption'=>'Min. Ampere','minimum'=>1,'maximum'=>32,'suffix'=>' A'],
                        ['type'=>'NumberSpinner','name'=>'MaxAmp','caption'=>'Max. Ampere','minimum'=>1,'maximum'=>32,'suffix'=>' A'],
                        ['type'=>'NumberSpinner','name'=>'NominalVolt','caption'=>'Netzspannung','minimum'=>200,'maximum'=>245,'suffix'=>' V'],
                    ]],
                    ['type'=>'Label','caption'=>"⚙️ Richtwerte: 3P Start ≈ {$thr3} W · 1P unter ≈ {$thr1} W"],
                ]],
                [ 'type'=>'ExpansionPanel','caption'=>'⚡ Eingänge','items'=>[
                    ['type'=>'SelectVariable','name'=>'VarPV_ID','caption'=>'PV-Leistung'],
                    ['type'=>'SelectVariable','name'=>'VarHouse_ID','caption'=>'Haus gesamt (inkl. WB)'],
                    ['type'=>'SelectVariable','name'=>'VarBattery_ID','caption'=>'Batterie (optional)'],
                    ['type'=>'RowLayout','items'=>[
                        ['type'=>'Select','name'=>'VarPV_Unit','caption'=>'PV Einheit','options'=>[['caption'=>'W','value'=>'W'],['caption'=>'kW','value'=>'kW']]],
                        ['type'=>'Select','name'=>'VarHouse_Unit','caption'=>'Haus Einheit','options'=>[['caption'=>'W','value'=>'W'],['caption'=>'kW','value'=>'kW']]],
                        ['type'=>'Select','name'=>'VarBattery_Unit','caption'=>'Batt Einheit','options'=>[['caption'=>'W','value'=>'W'],['caption'=>'kW','value'=>'kW']]],
                    ]],
                    ['type'=>'CheckBox','name'=>'BatteryPositiveIsCharge','caption'=>'+ bedeutet Laden'],
                    ['type'=>'NumberSpinner','name'=>'WBSubtractMinW','caption'=>'WB-Abzug ab','suffix'=>' W'],
                    ['type'=>'Label','caption'=>'WB-Leistung erst ab diesem Wert vom Hausverbrauch abziehen.'],
                ]],
                [ 'type'=>'ExpansionPanel','caption'=>'🐢 Slow-Control','items'=>[
                    ['type'=>'CheckBox','name'=>'SlowControlEnabled','caption'=>'aktiv'],
                    ['type'=>'NumberSpinner','name'=>'ControlIntervalSec','caption'=>'Regelintervall','minimum'=>10,'maximum'=>30,'suffix'=>' s'],
                    ['type'=>'NumberSpinner','name'=>'SlowAlphaPermille','caption'=>'Glättung α','minimum'=>0,'maximum'=>1000,'suffix'=>' ‰'],
                    ['type'=>'Label','caption'=>'Anzeige 1 Hz live. Regelung ±1 A pro Intervall.'],
                ]],
                [ 'type'=>'ExpansionPanel','caption'=>'🪲 Debug','items'=>[
                    ['type'=>'CheckBox','name'=>'DebugPVWM','caption'=>'Modul-Debug (Regellogik)'],
                    ['type'=>'CheckBox','name'=>'DebugMQTT','caption'=>'MQTT-Rohdaten loggen'],
                    ['type'=>'Label','caption'=>'Ausgabe im Meldungen-Fenster.'],
                ]],
            ],
            'actions'=>[
                ['type'=>'Label','caption'=>$msHint]
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}
