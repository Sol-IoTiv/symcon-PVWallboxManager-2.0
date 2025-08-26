<?php
declare(strict_types=1);

/**
 * PVWMSlowControl
 *
 * Einfache, robuste Regelung:
 * - Anzeige: live jede Sekunde (nur berechnen/anzeigen, nichts schalten)
 * - Regelung: alle X Sekunden (10–30) ±1 A in Richtung Ziel
 * - Keine FRC-Umschaltungen. Erwartet, dass die WB bereits ladebereit ist (z. B. "Manuell").
 * - Ziel aus Netzfluss (Bezug>0, Einspeisung<0) ODER aus direkter Überschuss-Variable
 *
 * Minimal-Abhängigkeit: optional GOeCharger_SetCurrentChargingWatt(InstanzID, Watt)
 */

class PVWMSlowControl extends IPSModule
{
    // -------------------------
    // Lifecycle
    // -------------------------
    public function Create()
    {
        parent::Create();

        // INPUT-Auswahl
        // inputMode: 'grid' | 'surplus'
        $this->RegisterPropertyString('InputMode', 'grid');
        $this->RegisterPropertyInteger('VarGrid_ID', 0);       // Netzfluss: Bezug +, Einspeisung -
        $this->RegisterPropertyInteger('VarSurplus_ID', 0);    // direkter PV-Überschuss (W)

        // WALLBOX / Physik
        $this->RegisterPropertyInteger('GoEInstanceID', 0);    // go-e Instanz (optional)
        $this->RegisterPropertyInteger('FixedPhases', 1);      // 1 oder 3 (einfacher Betrieb)
        $this->RegisterPropertyInteger('Voltage', 230);        // Nennspannung
        $this->RegisterPropertyInteger('MinAmp', 6);
        $this->RegisterPropertyInteger('MaxAmpPerPhase', 16);

        // Regel-Intervalle und Schwellen
        $this->RegisterPropertyInteger('ControlIntervalSec', 15); // 10..30 s
        $this->RegisterPropertyInteger('StartExportW', 300);       // Start ab mind. 300 W Export
        $this->RegisterPropertyInteger('StopImportW', 200);        // Stop bei Import > 200 W

        // UI-Timer (1 s) und Control-Timer (X s) via RequestAction triggern
        $this->RegisterTimer('PVWMSC_TickUI', 0, 'IPS_RequestAction($_IPS["TARGET"], "DoTickUI", 0);');
        $this->RegisterTimer('PVWMSC_TickControl', 0, 'IPS_RequestAction($_IPS["TARGET"], "DoTickControl", 0);');

        // Variablen
        $this->RegisterVariableBoolean('ControlActive', 'Regelung aktiv', '~Switch', 10);
        $this->EnableAction('ControlActive');

        $this->RegisterVariableInteger('TargetA_Live', 'Ziel Ampere (live)', '', 20);
        $this->RegisterVariableInteger('TargetW_Live', 'Zielleistung (live)', '~Watt', 21);
        $this->RegisterVariableFloat('Grid_W', 'Netzfluss [W]', '~Watt', 22);
        $this->RegisterVariableFloat('Export_W', 'Einspeisung [+W]', '~Watt', 23);
        $this->RegisterVariableFloat('Import_W', 'Bezug [+W]', '~Watt', 24);

        // Attribute: intern
        $this->RegisterAttributeInteger('LastSetAmp', 0);
        $this->RegisterAttributeInteger('LastAmpSendMs', 0);
        $this->RegisterAttributeInteger('LastCalcTargetA', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $ctrl = max(10, min(30, (int)$this->ReadPropertyInteger('ControlIntervalSec')));
        $this->SetTimerInterval('PVWMSC_TickUI', 1000);              // Anzeige 1 s
        $this->SetTimerInterval('PVWMSC_TickControl', $ctrl * 1000); // Regelung selten
    }

    // -------------------------
    // RequestAction (UI, Timer)
    // -------------------------
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ControlActive':
                $this->SetValue('ControlActive', (bool)$Value);
                return;
            case 'DoTickUI':
                $this->TickUI();
                return;
            case 'DoTickControl':
                $this->TickControl();
                return;
        }
    }

    // -------------------------
    // 1) Anzeige tickt jede Sekunde: nur berechnen, anzeigen, nichts schalten
    // -------------------------
    public function TickUI(): void
    {
        [$gridW, $exportW, $importW] = $this->readGridTuple();
        $this->SetValueSafe('Grid_W', (float)$gridW);
        $this->SetValueSafe('Export_W', (float)$exportW);
        $this->SetValueSafe('Import_W', (float)$importW);

        $surplusW = $this->readSurplusW($gridW); // aus Grid oder direkter Überschuss-Variable

        $ph = ($this->ReadPropertyInteger('FixedPhases') === 3) ? 3 : 1;
        $u  = max(200, (int)$this->ReadPropertyInteger('Voltage'));
        $aMin = (int)$this->ReadPropertyInteger('MinAmp');
        $aMax = (int)$this->ReadPropertyInteger('MaxAmpPerPhase');

        $targetW = max(0, (int)$surplusW);
        $targetA = (int)ceil($targetW / ($u * $ph));
        $targetA = max($aMin, min($targetA, $aMax));

        $this->SetValueSafe('TargetW_Live', $targetW);
        $this->SetValueSafe('TargetA_Live', $targetA);
        $this->WriteAttributeInteger('LastCalcTargetA', $targetA);
    }

    // -------------------------
    // 2) Regelung tickt alle X Sekunden: ±1 A in Richtung Ziel
    // -------------------------
    public function TickControl(): void
    {
        if (!(bool)@GetValue(@$this->GetIDForIdent('ControlActive'))) return;

        [$gridW, $exportW, $importW] = $this->readGridTuple();
        $startExp = max(0, (int)$this->ReadPropertyInteger('StartExportW'));
        $stopImp  = max(0, (int)$this->ReadPropertyInteger('StopImportW'));

        // Start/Stop Hysterese an Grid
        if ($importW > $stopImp) {
            // Import zu hoch → Amp nicht erhöhen (hier nur halten). Optional: absenken.
            // Wir senken sanft um 1 A.
            $this->stepAmpTowardTarget(max(0, $this->getLastSetAmp() - 1));
            return;
        }
        if ($exportW < $startExp) {
            // kaum Überschuss → nicht erhöhen
            return;
        }

        // Ziel aus letztem UI-Live-Wert
        $targetA = (int)$this->ReadAttributeInteger('LastCalcTargetA');
        if ($targetA <= 0) return;

        $this->stepAmpTowardTarget($targetA);
    }

    // -------------------------
    // Kern: ±1 A Schritt und setzen
    // -------------------------
    private function stepAmpTowardTarget(int $targetA): void
    {
        $aMin = (int)$this->ReadPropertyInteger('MinAmp');
        $aMax = (int)$this->ReadPropertyInteger('MaxAmpPerPhase');
        $targetA = max($aMin, min($targetA, $aMax));

        $curA = (int)$this->ReadAttributeInteger('LastSetAmp');
        if ($curA <= 0) $curA = $aMin;
        if ($targetA === $curA) return;

        // Rate-Limit: min ControlIntervalSec zwischen Sends reicht, Timer tickt selten
        $nextA = ($targetA > $curA) ? $curA + 1 : $curA - 1;
        $this->setCurrentLimitA($nextA);
        $this->WriteAttributeInteger('LastSetAmp', $nextA);

        $this->SendDebug('RAMP_SLOW', sprintf('A %d → %d (Ziel=%d)', $curA, $nextA, $targetA), 0);
    }

    // -------------------------
    // Surplus lesen
    // -------------------------
    private function readSurplusW(float $gridW): int
    {
        $mode = strtolower(trim($this->ReadPropertyString('InputMode')));
        if ($mode === 'surplus') {
            $id = (int)$this->ReadPropertyInteger('VarSurplus_ID');
            if ($id > 0 && @IPS_VariableExists($id)) {
                return (int)round((float)@GetValue($id));
            }
        }
        // Default: aus Grid ableiten → Überschuss = Einspeisung als positive Zahl
        return (int)round(max(0.0, -$gridW));
    }

    // Grid-Tuple: [grid, export+, import+]
    private function readGridTuple(): array
    {
        $grid = 0.0;
        $id = (int)$this->ReadPropertyInteger('VarGrid_ID');
        if ($id > 0 && @IPS_VariableExists($id)) {
            $grid = (float)@GetValue($id);
        }
        $export = max(0.0, -$grid);
        $import = max(0.0,  $grid);
        return [$grid, $export, $import];
    }

    // -------------------------
    // WB-Steuerung (Watt aus Ampere über Phasen*U)
    // -------------------------
    private function setCurrentLimitA(int $amp): void
    {
        $ph = ($this->ReadPropertyInteger('FixedPhases') === 3) ? 3 : 1;
        $u  = max(200, (int)$this->ReadPropertyInteger('Voltage'));
        $watts = max(0, (int)round($amp * $ph * $u));

        $inst = (int)$this->ReadPropertyInteger('GoEInstanceID');
        if ($inst > 0 && function_exists('GOeCharger_SetCurrentChargingWatt')) {
            try { @GOeCharger_SetCurrentChargingWatt($inst, $watts); } catch (\Throwable $e) {}
        } else {
            // Fallback: nur Debug
            $this->SendDebug('SET', 'Kein GOeCharger_SetCurrentChargingWatt verfügbar', 0);
        }
    }

    // -------------------------
    // Utils
    // -------------------------
    private function SetValueSafe(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid) {
            $old = @GetValue($vid);
            if ($old !== $value) { @SetValue($vid, $value); }
        }
    }

    private function getLastSetAmp(): int
    {
        $a = (int)$this->ReadAttributeInteger('LastSetAmp');
        if ($a <= 0) $a = (int)$this->ReadPropertyInteger('MinAmp');
        return $a;
    }
}
