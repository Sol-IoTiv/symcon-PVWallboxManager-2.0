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
            $this->sendSet('ama', (string)$nextA); // oder charger->setChargingCurrent($nextA)
            if ($vid) @SetValue($vid, $nextA);
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

}
