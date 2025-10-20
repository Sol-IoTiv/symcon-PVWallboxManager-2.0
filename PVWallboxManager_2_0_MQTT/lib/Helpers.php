<?php
declare(strict_types=1);

trait Helpers
{
    protected function SetValueSafe(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid) {
            $old = @GetValue($vid);
            if ($old !== $value) { @SetValue($vid, $value); }
        }
    }

    protected function currentBaseTopic(): string
    {
        $p = trim((string)$this->ReadPropertyString('BaseTopic'));
        if ($p !== '') return rtrim($p, '/');
        $a = trim((string)$this->ReadAttributeString('AutoBaseTopic'));
        if ($a !== '') return rtrim($a, '/');
        return '';
    }

    protected function bt(string $key): string
    {
        $base = $this->currentBaseTopic();
        return ($base === '') ? $key : ($base . '/' . $key);
    }

    protected function modulePrefix(): string
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        $mod  = is_array($inst) ? @IPS_GetModule($inst['ModuleID']) : null;
        return (is_array($mod) && !empty($mod['Prefix'])) ? (string)$mod['Prefix'] : 'GOEMQTT';
    }

    // --- Logging mit Icons ---
    protected function emojiFor(string $level): string
    {
        switch ($level) { case 'debug': return '🐞'; case 'info': return 'ℹ️';
            case 'warn': return '⚠️'; case 'error': return '⛔'; }
        return '';
    }

    protected function logUnified(string $level, string $title, string $message, bool $force = false): void
    {
        if (!$force && !$this->pvwmDebugEnabled()) return;

        // Emoji optional (falls Methode fehlt → ohne Emoji)
        $emoji = method_exists($this, 'emojiFor') ? (string)$this->emojiFor($level) : '';
        $label = ($emoji !== '' ? $emoji.' ' : '') . $title;

        @ $this->SendDebug($label, $message, 0);
        @ IPS_LogMessage($this->modulePrefix(), $label.' | '.$message);
    }
    protected function infoLog(string $title, string $message, bool $force = true): void { $this->logUnified('info',  $title, $message, $force); }
    protected function warnLog(string $title, string $message): void  { $this->logUnified('warn',  $title, $message, true); }
    protected function errorLog(string $title, string $message): void { $this->logUnified('error', $title, $message, true); }

    // MQTT Debug-Helfer
    protected function topicDeviceId(string $topic): string
    {
        if (preg_match('#^go-eCharger/([^/]+)/#', $topic, $m)) return $m[1];
        return '';
    }
    protected function dbgCtx(string $topic): string
    {
        $id = $this->topicDeviceId($topic);
        return ($id !== '') ? " [{$id}]" : '';
    }
    protected function strShort(string $s, int $max = 240): string
    {
        if (mb_strlen($s) <= $max) return $s;
        $extra = mb_strlen($s) - $max;
        return mb_substr($s, 0, $max) . "… (+{$extra} chars)";
    }
/*
    protected function dbgMqtt(string $key, string $topic, string $payload): void
    {
        $this->dbgLog('MQTT ' . $key . $this->dbgCtx($topic), $this->strShort($payload));
    }

    protected function dbgChanged(string $title, $old, $new): void
    {
        $msg = 'old=' . json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
             . ' → new=' . json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->dbgLog($title, $msg);
    }
*/
    protected function phaseModeLabel(int $pm): string { return ($pm === 2) ? '3-phasig' : '1-phasig'; }
    protected function frcLabel(int $v): string { return [0=>'Neutral',1=>'Stop',2=>'Start'][$v] ?? (string)$v; }
    protected function carStateLabel(int $s): string
    {
        return [0=>'Unbekannt',1=>'Bereit/kein Fahrzeug',2=>'Lädt',3=>'Verbunden',4=>'Beendet',5=>'Fehler'][$s] ?? (string)$s;
    }

    // Quellen lesen (W/kW → W)
    protected function readVarWUnit(string $idProp, string $unitProp): float
    {
        $id = (int)$this->ReadPropertyInteger($idProp);
        if ($id <= 0 || !@IPS_VariableExists($id)) return 0.0;
        $val = GetValue($id);
        if (!is_numeric($val)) return 0.0;
        $unit = strtoupper((string)$this->ReadPropertyString($unitProp));
        $scale = ($unit === 'KW') ? 1000.0 : 1.0;
        return (float)$val * $scale;
    }

    protected function getWBPowerW(): int
    {
        $vid = @$this->GetIDForIdent('Leistung_W');
        return $vid ? (int)@GetValue($vid) : 0;
    }

    protected function isChargingActive(): bool
    {
        $car = (int)@GetValue(@$this->GetIDForIdent('CarState'));
        if ($car === 2) return true;           // 2 = lädt
        return $this->getWBPowerW() > 300;     // Fallback: >300W = aktiv
    }

    protected function ampRange(): array
    {
        $min = max(1, (int)$this->ReadPropertyInteger('MinAmp'));
        $max = max($min, (int)$this->ReadPropertyInteger('MaxAmp'));
        return [$min, $max];
    }
    protected function minAmp(): int { return $this->ampRange()[0]; }
    protected function maxAmp(): int { return $this->ampRange()[1]; }

    private function smoothSurplus(int $rawW): int
    {
        $alphaPermille = (int)$this->ReadPropertyInteger('SmoothAlphaPermille');
        $alphaPermille = max(0, min(1000, $alphaPermille));
        if ($alphaPermille === 0) {
            $this->WriteAttributeInteger('SmoothSurplusW', $rawW);
            return $rawW; // glätten aus
        }
        $alpha = $alphaPermille / 1000.0;

        $prev = (int)$this->ReadAttributeInteger('SmoothSurplusW');
        $sm   = (int)round($alpha * $rawW + (1.0 - $alpha) * $prev);

        $this->WriteAttributeInteger('SmoothSurplusW', $sm);
        return $sm;
    }

    private function canToggleFRC(int $targetFRC): bool
    {
        $nowMs  = (int)(microtime(true) * 1000);
        $lastMs = (int)$this->ReadAttributeInteger('LastFrcChangeMs'); // du schreibst das bereits bei sendSet('frc',..)
        $minOn  = (int)$this->ReadPropertyInteger('MinOnTimeMs');   // z.B. 60000
        $minOff = (int)$this->ReadPropertyInteger('MinOffTimeMs');  // z.B. 15000
        $elapsed = $nowMs - $lastMs;

        // aktuellen erzwungenen Zustand lesen (oder notfalls 0=neutral)
        $frcCur = (int)@GetValue(@$this->GetIDForIdent('FRC')); // falls du FRC als Var führst
        $frcCur = ($frcCur >= 0 && $frcCur <= 2) ? $frcCur : 0;

        // gleiche Zielrichtung? kein Toggle nötig
        if ($targetFRC === $frcCur) {
            return false;
        }

        // Start→Stop erst nach MinOnTime
        if ($frcCur === 2 && $targetFRC === 1 && $elapsed < $minOn) {
            return false;
        }
        // Stop/Neutral→Start erst nach MinOffTime
        if ($frcCur !== 2 && $targetFRC === 2 && $elapsed < $minOff) {
            return false;
        }
        return true;
    }

    private function rampAmpere(int $targetA): void
    {
        $minA = (int)$this->ReadPropertyInteger('MinAmp');
        $maxA = (int)$this->ReadPropertyInteger('MaxAmp');
        $targetA = max($minA, min($maxA, $targetA));

        $vid = @$this->GetIDForIdent('Ampere_A'); // deine Soll-Ampere-Var (falls vorhanden)
        $curA = $vid ? (int)@GetValue($vid) : $minA;

        $stepA = max(1, (int)$this->ReadPropertyInteger('RampStepA')); // default 1 A
        $hold  = max(500, (int)$this->ReadPropertyInteger('RampHoldMs')); // default 3000 ms

        $nowMs  = (int)(microtime(true) * 1000);
        $lastMs = (int)$this->ReadAttributeInteger('LastAmpChangeMs');
        if (($nowMs - $lastMs) < $hold) {
            return; // noch warten
        }

        $nextA = $curA;
        if ($targetA > $curA)       $nextA = min($curA + $stepA, $targetA);
        elseif ($targetA < $curA)   $nextA = max($curA - $stepA, $targetA);

        if ($nextA !== $curA) {
            // hier deine Setz-API:
            $this->setCurrentLimitA($nextA);
//            if ($vid) @SetValue($vid, $nextA);
            $this->writeAmpUIIfAllowed((int)$nextA); 
            $this->WriteAttributeInteger('LastAmpChangeMs', $nowMs);
            $this->dbgLog('RAMP', "Ampere: $curA A → $nextA A (Ziel $targetA A)");
        }
    }

    private function scheduleUpdateFromMqtt(int $delayMs = 350): void
    {
        if ($delayMs < 50) $delayMs = 50;
        // Dein bestehender Timer-Name:
        $this->SetTimerInterval('LOOP', $delayMs);
    }
    protected function mqttBufGet(string $key, $default=null)
    {
        $buf = json_decode($this->ReadAttributeString('MQTT_BUF'), true) ?: [];
        return array_key_exists($key, $buf) ? $buf[$key] : $default;
    }

    private function pvwmDebugEnabled(): bool
    {
        return (bool)$this->ReadPropertyBoolean('DebugPVWM');
    }

    private function mqttDebugEnabled(): bool
    {
        return (bool)$this->ReadPropertyBoolean('DebugMQTT');
    }

    protected function dbgLog(string $topic, string $msg): void
    {
        $this->logUnified('info', $topic, $msg);
    }

    protected function dbgChanged(string $what, string $old, string $new): void
    {
        $this->logUnified('change', 'CHANGE', sprintf('%s: %s → %s', $what, $old, $new));
    }

    protected function dbgMqtt(string $tag, string $line): void
    {
        if (!(bool)$this->ReadPropertyBoolean('DebugMQTT')) return;
        @ $this->SendDebug('MQTT '.$tag, $line, 0);
        @ IPS_LogMessage($this->modulePrefix().'-MQTT', $line);
    }

    private function setCurrentLimitA(int $amp): void
    {
        [$minA, $maxA] = $this->ampRange();
        $amp = max($minA, min($maxA, $amp));

        $this->sendSet('amp', (string)$amp);

        $this->WriteAttributeInteger('LastAmpSet', $amp);
        $this->WriteAttributeInteger('LastPublishMs', (int)(microtime(true) * 1000));

        if ($vidEff = @$this->GetIDForIdent('AmpereEff_A')) {
            @SetValue($vidEff, (int)$amp);             // effektiv gesetzter Strom
        }
        $this->writeAmpUIIfAllowed((int)$amp);        // UI nur außerhalb Manuell
        $this->dbgLog('setCurrentLimitA', "amp={$amp} A gesendet");
    }

    private function preferNrgPowerW(): int
    {
        // versuche NRG[11]
        $nrg = $this->mqttBufGet('nrg', null);
        if ($nrg !== null) {
            if (is_string($nrg)) { $tmp = json_decode($nrg, true); if (is_array($tmp)) $nrg = $tmp; }
            if (is_array($nrg) && isset($nrg[11])) {
                return (int)round(max(0.0, (float)$nrg[11]));
            }
        }
        // Fallback: Trait
        return (int)round(max(0.0, (float)$this->getWBPowerW()));
    }

    private function targetPhaseAmp(int $targetW): array
    {
        $pm = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')); // 1|2
        if ($this->ReadPropertyBoolean('AutoPhase')) {
            $pm = ($targetW >= (int)$this->ReadPropertyInteger('ThresTo3p_W')) ? 2 : 1;
        }
        $u   = (int)$this->ReadPropertyInteger('NominalVolt'); if ($u <= 0) $u = 230;
        $den = $u * ($pm === 2 ? 3 : 1);
        $a   = ($den > 0) ? (int)round($targetW / $den) : 0;
        $a   = max($this->minAmp(), min($this->maxAmp(), $a));
        $txt = ($pm === 2 ? '3-phasig' : '1-phasig') . ' / ' . $a . ' A';
        return [$pm, $a, $txt];
    }

    private function updateRegelziel(int $targetW, int $pmCalc, int $aCalc): void
    {
        $txt = sprintf('%s · %d A · ≈ %.1f kW', ($pmCalc===3?'3-phasig':'1-phasig'), max(0,$aCalc), max(0,$targetW)/1000);
        $this->SetValueSafe('Regelziel', $txt);
    }

    private function fmtKW($w): string
    {
        return number_format(max(0,$w)/1000, 1, ',', '.');
    }

    private function isManualMode(): bool
    {
        $vid = @$this->GetIDForIdent('Mode'); // 0=PV, 1=Manuell, 2=Nur Anzeige
        return ($vid && (int)@GetValue($vid) === 1);
    }

    private function setVarReadOnly(string $ident, bool $readOnly): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if (!$vid) return;

        // Bedienung sperren/freigeben
        @IPS_SetVariableCustomAction($vid, $readOnly ? 0 : $this->InstanceID);

        // Optisch ausgrauen: „Objekt deaktivieren“
        @IPS_SetDisabled($vid, $readOnly);
    }

    private function updateUiInteractivity(): void
    {
        $mode    = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV,1=Manuell,2=Nur Anzeige,3=PV-Anteil
        $manual  = $this->isManualMode();                         // i.d.R. ($mode === 1)
        $share   = ($mode === 3);

        // Manuell: Ampere & Phasen bedienbar, sonst read-only
        $this->setVarReadOnly('Ampere_A',    !$manual);
        $this->setVarReadOnly('Phasenmodus', !$manual);

        // PV-Anteil-Slider nur im Modus 3 bedienbar
        $this->setVarReadOnly('PVShare_Pct', !$share);
    }

    // GUID Archiv: {43192F0B-135B-4CE7-A0A7-1475603F3060}
    private function acId(): int
    {
        $list = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (is_array($list) && count($list) > 0) return (int)$list[0];
        return 35472; // Fallback aus deinem Setup
    }

    private function seriesLogged(int $vid, int $from, int $to, float $scale = 1.0): array
    {
        if ($vid <= 0 || !@IPS_VariableExists($vid)) return [];
        $rows = @AC_GetLoggedValues($this->acId(), $vid, $from, $to, 0);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) $out[] = [$r['TimeStamp'] * 1000, (float)$r['Value'] * $scale];
        return $out;
    }

    private function unitScale(string $unit): float
    {
        $u = strtolower(trim($unit));
        if ($u === 'kw') return 1000.0;
        return 1.0; // 'w' oder unbekannt
    }

    // flackern vermeiden: nur aktualisieren, wenn sich HTML ändert
    private function setHtmlIfChanged(string $ident, string $html): void {
        $vid = @$this->GetIDForIdent($ident);
        if (!$vid) return;
        $old = (string)@GetValue($vid);
        if ($old !== $html) @SetValue($vid, $html);
    }

    // 0..n Tage zurück → Tagesbereich
    private function dayRangeByOffset(int $daysBack): array {
        $d = strtotime('-'.$daysBack.' day');
        $start = strtotime(date('Y-m-d 00:00:00', $d));
        $end   = strtotime(date('Y-m-d 23:59:59', $d));
        return [$start, $end];
    }

    // In class PVWallboxManager_2_0_MQTT
    private function calcPvUeberschussW(): int
    {
        // Modus: 0=PV-Auto, 1=Manuell, 2=Nur Anzeige, 3=PV-Anteil
        $mode = (int)@GetValue(@$this->GetIDForIdent('Mode'));

        // Eingänge (W / kW-handling via readVarWUnit)
        $pv         = (int)round((float)$this->readVarWUnit('VarPV_ID','VarPV_Unit'));
        $houseTotal = (int)round((float)$this->readVarWUnit('VarHouse_ID','VarHouse_Unit'));

        // WB-Leistung: bevorzugt eigene Variable; sonst 0
        $wbVid = @$this->GetIDForIdent('PowerToCar_W');
        $wbW   = $wbVid ? (int)@GetValue($wbVid) : 0;

        // Batterie (nur Laden als Verbrauch)
        $batt = (int)round((float)$this->readVarWUnit('VarBattery_ID','VarBattery_Unit'));
        if (!$this->ReadPropertyBoolean('BatteryPositiveIsCharge')) { $batt = -$batt; }
        $battCharge = max(0, $batt);

        // Haus ohne WB (für PV-Anteil)
        $houseNetVid = @$this->GetIDForIdent('HouseNet_W');
        $houseNet    = $houseNetVid ? (int)@GetValue($houseNetVid) : max(0, $houseTotal - max(0, $wbW));

        // SoC-Gurt (nur im PV-Auto nutzen, nicht im PV-Anteil)
        $battForCalc = $battCharge;
        if ($mode !== 3) {
            $socId = (int)$this->ReadPropertyInteger('VarBatterySoc_ID');
            $soc   = ($socId>0 && @IPS_VariableExists($socId)) ? (float)@GetValue($socId) : -1.0;
            $min   = (int)$this->ReadPropertyInteger('BatteryMinSocForPV');
            if ($soc >= 0 && $soc >= $min) {
                $battForCalc = 0; // Akku bereits „ok“ → nicht als Verbrauch abziehen
            }
        }

        // Roh-Überschuss je Modus
        if ($mode === 3) {
            // PV-Anteil: Überschuss vor Akku/Auto
            return (int)($pv - $houseNet);
        }

        // PV-Auto (klassisch): PV - (Haus inkl. WB) - (Batterieladung) + WB (da in Haus enthalten)
        return (int)max(0, $pv - $battForCalc - $houseTotal + max(0, $wbW));
    }

    private function writeAmpUIIfAllowed(int $a): void {
        $mode = (int)@GetValue(@$this->GetIDForIdent('Mode')); // 0=PV,1=Manuell,2=Nur Anzeige,3=PV-Anteil
        if ($mode !== 1) {
            if ($vid=@$this->GetIDForIdent('Ampere_A')) @SetValue($vid, $a);
        }
    }

}
