<?php
declare(strict_types=1);

trait Profiles
{
    protected function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('GoE.CarState')) {
            IPS_CreateVariableProfile('GoE.CarState', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('GoE.CarState', '', '');
            IPS_SetVariableProfileIcon('GoE.CarState', 'Car');
            IPS_SetVariableProfileAssociation('GoE.CarState', 0, 'Unbekannt/Firmwarefehler', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 1, 'Bereit, kein Fahrzeug', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 2, 'Fahrzeug lädt', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 3, 'Fahrzeug verbunden / bereit', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 4, 'Ladung beendet, noch verbunden', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 5, 'Fehler', '', -1);
        }

        if (!IPS_VariableProfileExists('GoE.Amp')) {
            IPS_CreateVariableProfile('GoE.Amp', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('GoE.Amp', 6, 16, 1);
            IPS_SetVariableProfileText('GoE.Amp', '', ' A');
        }

        if (!IPS_VariableProfileExists('GoE.PhaseMode')) {
            IPS_CreateVariableProfile('GoE.PhaseMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('GoE.PhaseMode', 1, '1-phasig', '', -1);
            IPS_SetVariableProfileAssociation('GoE.PhaseMode', 2, '3-phasig', '', -1);
        }

        if (!IPS_VariableProfileExists('GoE.ForceState')) {
            IPS_CreateVariableProfile('GoE.ForceState', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 0, 'Neutral', '', -1);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 1, 'Stop (Force-Off)', '', -1);
            IPS_SetVariableProfileAssociation('GoE.ForceState', 2, 'Start (Force-On)', '', -1);
        }
    }
}
