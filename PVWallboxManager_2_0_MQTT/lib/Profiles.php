<?php
declare(strict_types=1);

trait Profiles
{
    protected function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('GoE.CarState')) {
            IPS_CreateVariableProfile('GoE.CarState', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('GoE.CarState', 'Car');
            IPS_SetVariableProfileAssociation('GoE.CarState', 0, 'Unbekannt/Firmwarefehler', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 1, 'Bereit, kein Fahrzeug',   '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 2, 'Fahrzeug lädt',          '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 3, 'Verbunden / bereit',     '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 4, 'Ladung beendet',         '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 5, 'Fehler',                 '', -1);
        }

        if (!IPS_VariableProfileExists('GoE.Amp')) {
            IPS_CreateVariableProfile('GoE.Amp', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('GoE.Amp', 6, 32, 1);
            IPS_SetVariableProfileText('GoE.Amp', '', ' A');
        }

        if (!IPS_VariableProfileExists('GoE.PhaseMode')) {
            IPS_CreateVariableProfile('GoE.PhaseMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('GoE.PhaseMode', 1, '1-phasig', '', -1);
            IPS_SetVariableProfileAssociation('GoE.PhaseMode', 2, '3-phasig', '', -1);
        }

        if (!IPS_VariableProfileExists('GoE.ForceState')) {
            IPS_CreateVariableProfile('GoE.ForceState', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 0, 'Neutral',    '', -1);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 1, 'Stop',       '', -1);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 2, 'Start',      '', -1);
        }

        // in ensureProfiles()
        if (!IPS_VariableProfileExists('PVWM.Mode')) {
            IPS_CreateVariableProfile('PVWM.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('PVWM.Mode', 0, 'PV-Automatik', '', -1);
            IPS_SetVariableProfileAssociation('PVWM.Mode', 1, 'Manuell (fix)', '', -1);
            IPS_SetVariableProfileAssociation('PVWM.Mode', 2, 'Aus (gesperrt)', '', -1);
        }
    }
}
