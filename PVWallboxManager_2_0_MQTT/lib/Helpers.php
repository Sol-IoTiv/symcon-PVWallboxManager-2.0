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

    // Liefert den aktuell gültigen BaseTopic: Property > Auto-Attribute
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
}
