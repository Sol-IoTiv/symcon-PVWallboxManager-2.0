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

    // Modul-Prefix dynamisch (für IPS_LogMessage-Tag)
    protected function modulePrefix(): string
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        $mod  = is_array($inst) ? @IPS_GetModule($inst['ModuleID']) : null;
        return (is_array($mod) && !empty($mod['Prefix'])) ? (string)$mod['Prefix'] : 'GOEMQTT';
    }

    // Debug in Instanz-Debug + Meldungen (nur wenn DebugLogging=true)
    protected function dbgLog(string $title, string $message): void
    {
        if (!$this->ReadPropertyBoolean('DebugLogging')) return;
        $this->SendDebug($title, $message, 0);                       // Instanz-Debug
        IPS_LogMessage($this->modulePrefix(), $title . ': ' . $message); // Meldungen
    }

    // Kompaktes Change-Log (nutzt dbgLog)
    protected function dbgChanged(string $title, $old, $new): void
    {
        if (!$this->ReadPropertyBoolean('DebugLogging')) return;
        $msg = 'old=' . json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
             . ' → new=' . json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->dbgLog($title, $msg);
    }
}
