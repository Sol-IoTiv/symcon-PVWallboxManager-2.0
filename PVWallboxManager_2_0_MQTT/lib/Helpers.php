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

    // BaseTopic + Key
    protected function bt(string $key): string
    {
        return rtrim((string)$this->ReadPropertyString('BaseTopic'), '/') . '/' . $key;
    }
}
