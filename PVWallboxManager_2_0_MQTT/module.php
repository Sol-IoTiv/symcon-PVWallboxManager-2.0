<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Profiles.php';
require_once __DIR__ . '/lib/Helpers.php';
require_once __DIR__ . '/lib/Communication/MqttClientTrait.php';
require_once __DIR__ . '/lib/MqttHandlersTrait.php';

class PVWallboxManager_2_0_MQTT extends IPSModule
{
    use Profiles;
    use Helpers;
    use MqttClientTrait;     // Attach, Subscribe, Publish, sendSet()
    use MqttHandlersTrait;   // ReceiveData + Parsing/Abfragen

    // Grenzen für go-e (ggf. für V4/32A anpassen)
    private const MIN_AMP = 6;
    private const MAX_AMP = 16;

    public function Create()
    {
        parent::Create();

        // Properties
//        $this->RegisterPropertyString('BaseTopic', 'go-eCharger/285450');
        
        // --- Properties & Auto-Modus ---
        $this->RegisterPropertyString('BaseTopic', '');            // leer => Auto
        $this->RegisterAttributeString('AutoBaseTopic', '');       // erkannter Stamm
        $this->RegisterPropertyString('DeviceIDFilter', '');       // z.B. "285450", leer = kein Filter

        // Profile sicherstellen
        $this->ensureProfiles();

        // Kern-Variablen
        $this->RegisterVariableInteger('Ampere_A',    'Ampere [A]',        'GoE.Amp',        10);
        $this->EnableAction('Ampere_A');

        $this->RegisterVariableInteger('Leistung_W',  'Leistung [W]',      '~Watt',          20);

        $this->RegisterVariableInteger('CarState',    'Fahrzeugstatus',    'GoE.CarState',   25);

        $this->RegisterVariableInteger('FRC',         'Force State (FRC)', 'GoE.ForceState', 50);
        $this->EnableAction('FRC');

        $this->RegisterVariableInteger('Phasenmodus', 'Phasenmodus',       'GoE.PhaseMode',  60);
        $this->EnableAction('Phasenmodus');

        $this->RegisterVariableString('LastSeenUTC',  'Zuletzt gesehen (UTC)', '',           70);

        // Debug / Rohwerte
        $this->RegisterVariableString('NRG_RAW',      'NRG (roh)',         '~TextBox',       90);

        // (Für späteres WebFront-Preview)
        // $this->RegisterVariableString('Preview', 'Wallbox-Preview', '~HTMLBox', 100);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Parent suchen/setzen + Wildcard-Subscribe
        $ok = $this->attachAndSubscribe();
        if (!$ok) {
            $this->SetStatus(IS_EBASE + 2);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Ampere_A':
                $amp = max(self::MIN_AMP, min(self::MAX_AMP, (int)$Value));
                $this->sendSet('ama', (string)$amp);       // ama/set
                $this->SetValueSafe('Ampere_A', $amp);
                break;

            case 'Phasenmodus':
                $pm = ((int)$Value === 2) ? 2 : 1;
                $this->sendSet('psm', (string)$pm);        // psm/set
                $this->SetValueSafe('Phasenmodus', $pm);
                break;

            case 'FRC':
                $frc = in_array((int)$Value, [0, 1, 2], true) ? (int)$Value : 0;
                $this->sendSet('frc', (string)$frc);       // frc/set
                $this->SetValueSafe('FRC', $frc);
                break;
        }
    }

    // -------- Konfig-Dialog (form.json inline) --------
    public function GetConfigurationForm()
    {
        $prop  = trim($this->ReadPropertyString('BaseTopic'));
        $auto  = trim($this->ReadAttributeString('AutoBaseTopic'));
        $state = $prop !== '' ? 'Fix (Property)' : ($auto !== '' ? 'Auto erkannt' : 'Unbekannt');

        // Prefix aus module.json holen (Fallback: dein aktueller Prefix "GOEMQTT")
        $inst   = @IPS_GetInstance($this->InstanceID);
        $mod    = is_array($inst) ? @IPS_GetModule($inst['ModuleID']) : null;
        $prefix = (is_array($mod) && !empty($mod['Prefix'])) ? $mod['Prefix'] : 'GOEMQTT';

        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'MQTT BaseTopic'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'BaseTopic', 'caption' => 'BaseTopic (optional, leer = Auto)']
                ]],
                ['type' => 'Label', 'caption' => 'Erkannt: ' . ($auto !== '' ? $auto : '—')],
                ['type' => 'Label', 'caption' => 'Status: ' . $state]
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Erkannten BaseTopic übernehmen',
                    'onClick' => sprintf('%s_ApplyDetectedBaseTopic($id);', $prefix),
                    'enabled' => ($auto !== '' && $prop === '')
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Auto-Erkennung zurücksetzen',
                    'onClick' => sprintf('%s_ClearDetectedBaseTopic($id);', $prefix),
                    'enabled' => ($auto !== '')
                ]
            ]
        ]);
    }

    // Button: AutoBaseTopic -> Property übernehmen
    public function ApplyDetectedBaseTopic(): void
    {
        $auto = trim($this->ReadAttributeString('AutoBaseTopic'));
        if ($auto === '') {
            $this->LogMessage('Kein AutoBaseTopic vorhanden.', KL_WARNING);
            $this->ReloadForm();
            return;
        }
        IPS_SetProperty($this->InstanceID, 'BaseTopic', $auto);
        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();
    }

    // Button: Auto-Erkennung löschen
    public function ClearDetectedBaseTopic(): void
    {
        $this->WriteAttributeString('AutoBaseTopic', '');
        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();
    }

}
