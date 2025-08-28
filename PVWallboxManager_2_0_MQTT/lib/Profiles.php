<?php
declare(strict_types=1);

trait Profiles
{
    protected function ensureProfiles(): void
    {
        // --- Fahrzeugstatus
        if (!IPS_VariableProfileExists('GoE.CarState')) {
            IPS_CreateVariableProfile('GoE.CarState', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('GoE.CarState', 'Car');
        }
        IPS_SetVariableProfileAssociation('GoE.CarState', 0, 'Unbekannt/Firmwarefehler', '', -1);
        IPS_SetVariableProfileAssociation('GoE.CarState', 1, 'Bereit, kein Fahrzeug',   '', -1);
        IPS_SetVariableProfileAssociation('GoE.CarState', 2, 'Fahrzeug lädt',          '', -1);
        IPS_SetVariableProfileAssociation('GoE.CarState', 3, 'Verbunden / bereit zum Laden',     '', -1);
        IPS_SetVariableProfileAssociation('GoE.CarState', 4, 'Ladung beendet, Fahrzeug verbunden',         '', -1);
        IPS_SetVariableProfileAssociation('GoE.CarState', 5, 'Fehler',                 '', -1);

        // --- Ampere
        if (!IPS_VariableProfileExists('GoE.Amp')) {
            IPS_CreateVariableProfile('GoE.Amp', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('GoE.Amp', 'Electricity');
            IPS_SetVariableProfileValues('GoE.Amp', 6, 32, 1);
            IPS_SetVariableProfileText('GoE.Amp', '', ' A');
        }

        // --- NEU: Phasen-Profil (1 oder 3)
        if (!IPS_VariableProfileExists('PVWM.Phasen')) {
            IPS_CreateVariableProfile('PVWM.Phasen', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('PVWM.Phasen', 1, '1-phasig', '', -1);
        IPS_SetVariableProfileAssociation('PVWM.Phasen', 3, '3-phasig', '', -1);

        // --- NEU: FRC auf Deutsch
        if (!IPS_VariableProfileExists('PVWM.FRC')) {
            IPS_CreateVariableProfile('PVWM.FRC', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('PVWM.FRC', 0, 'Neutral (Wallbox entscheidet)', '', -1);
        IPS_SetVariableProfileAssociation('PVWM.FRC', 2, 'Start erzwingen',                '', -1);
        IPS_SetVariableProfileAssociation('PVWM.FRC', 1, 'Stop erzwingen',                 '', -1);

        // --- Lademodus
        if (!IPS_VariableProfileExists('PVWM.Mode')) {
            IPS_CreateVariableProfile('PVWM.Mode', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('PVWM.Mode', 0, 'PV-Automatik', '', -1);
        IPS_SetVariableProfileAssociation('PVWM.Mode', 1, 'Manuell (fix)', '', -1);
        IPS_SetVariableProfileAssociation('PVWM.Mode', 2, 'Nur Anzeige',   '', -1);

        // --- Optional: Legacy-Profile belassen (falls irgendwo referenziert)
        if (!IPS_VariableProfileExists('GoE.PhaseMode')) {
            IPS_CreateVariableProfile('GoE.PhaseMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('GoE.PhaseMode', 1, '1-phasig', '', -1);
            IPS_SetVariableProfileAssociation('GoE.PhaseMode', 2, '3-phasig', '', -1);
        }
        if (!IPS_VariableProfileExists('GoE.ForceState')) {
            IPS_CreateVariableProfile('GoE.ForceState', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 0, 'Neutral', '', -1);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 1, 'Stop',    '', -1);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 2, 'Start',   '', -1);
        }
    }
}
