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
        $do = $force || $this->ReadPropertyBoolean('DebugLogging');
        if (!$do) return;
        $label = $this->emojiFor($level) . ' ' . $title;
        $this->SendDebug($label, $message, 0);
        IPS_LogMessage($this->modulePrefix(), $label . ': ' . $message);
    }
    protected function dbgLog(string $title, string $message): void   { $this->logUnified('debug', $title, $message, false); }
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
        if ($car >= 2) return true; // 2=lädt, 3=verbunden (je nach Wallbox-Logik)
        $wbW = $this->getWBPowerW();
        return $wbW > 300; // Fallback: mehr als 300W an der Box -> "aktiv"
    }

}
