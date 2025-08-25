<?php
declare(strict_types=1);

trait Helpers
{
    protected function SetValueSafe(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid) {
            $old = @GetValue($vid);
            if ($old !== $value) {
                @SetValue($vid, $value);
            }
        }
    }

    // Aktueller BaseTopic: Property > Auto-Attribut
    protected function currentBaseTopic(): string
    {
        $p = trim((string)$this->ReadPropertyString('BaseTopic'));
        if ($p !== '') return rtrim($p, '/');

        $a = trim((string)$this->ReadAttributeString('AutoBaseTopic'));
        if ($a !== '') return rtrim($a, '/');

        return '';
    }

    // <BaseTopic>/<key>
    protected function bt(string $key): string
    {
        $base = $this->currentBaseTopic();
        return ($base === '') ? $key : ($base . '/' . $key);
    }

    // Modul-Prefix für Meldungen-Tag dynamisch ermitteln
    protected function modulePrefix(): string
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        $mod  = is_array($inst) ? @IPS_GetModule($inst['ModuleID']) : null;
        return (is_array($mod) && !empty($mod['Prefix'])) ? (string)$mod['Prefix'] : 'GOEMQTT';
    }

    // ⬇️ Hilfsfunktionen für sauberes Logging mit Icons

    protected function emojiFor(string $level): string
    {
        switch ($level) {
            case 'debug': return '🐞';
            case 'info':  return 'ℹ️';
            case 'warn':  return '⚠️';
            case 'error': return '⛔';
        }
        return '';
    }

    /**
     * Vereinheitlichtes Logging.
     * - level: debug|info|warn|error
     * - force: true = immer loggen (z.B. einmalige Events), sonst nur wenn DebugLogging=true
     */
    protected function logUnified(string $level, string $title, string $message, bool $force = false): void
    {
        $do = $force || $this->ReadPropertyBoolean('DebugLogging');
        if (!$do) return;

        $label = $this->emojiFor($level) . ' ' . $title;
        $this->SendDebug($label, $message, 0);                                  // Instanz-Debug
        IPS_LogMessage($this->modulePrefix(), $label . ': ' . $message);        // Meldungen
    }

    // Bequeme Wrapper
    protected function dbgLog(string $title, string $message): void
    {
        $this->logUnified('debug', $title, $message, false);
    }
    protected function infoLog(string $title, string $message, bool $force = true): void
    {
        $this->logUnified('info', $title, $message, $force);
    }
    protected function warnLog(string $title, string $message): void
    {
        $this->logUnified('warn', $title, $message, true);
    }
    protected function errorLog(string $title, string $message): void
    {
        $this->logUnified('error', $title, $message, true);
    }

    // Geräte-ID aus Topic holen: go-eCharger/<id>/...
    protected function topicDeviceId(string $topic): string
    {
        if (preg_match('#^go-eCharger/([^/]+)/#', $topic, $m)) {
            return $m[1];
        }
        return '';
    }

    // Kontext für Titel: " [<id>]"
    protected function dbgCtx(string $topic): string
    {
        $id = $this->topicDeviceId($topic);
        return ($id !== '') ? " [{$id}]" : '';
    }

    // Lange Payloads einkürzen (für Meldungen lesbar halten)
    protected function strShort(string $s, int $max = 240): string
    {
        if (mb_strlen($s) <= $max) return $s;
        $extra = mb_strlen($s) - $max;
        return mb_substr($s, 0, $max) . "… (+{$extra} chars)";
    }

    // Einheitlicher MQTT-Debug-Logger
    protected function dbgMqtt(string $key, string $topic, string $payload): void
    {
        $this->dbgLog('MQTT ' . $key . $this->dbgCtx($topic), $this->strShort($payload));
    }

    // Kompaktes Change-Log (nur wenn DebugLogging=true)
    protected function dbgChanged(string $title, $old, $new): void
    {
        $msg = 'old=' . json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
             . ' → new=' . json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->dbgLog($title, $msg);
    }

    // Labels für States
    protected function phaseModeLabel(int $pm): string  { return ($pm === 2) ? '3-phasig' : '1-phasig'; }
    protected function frcLabel(int $v): string         { return [0=>'Neutral',1=>'Stop',2=>'Start'][$v] ?? (string)$v; }
    protected function carStateLabel(int $s): string    { return [0=>'Unbekannt',1=>'Bereit/kein Fahrzeug',2=>'Lädt',3=>'Verbunden',4=>'Beendet (noch verbunden)',5=>'Fehler'][$s] ?? (string)$s; }
}
