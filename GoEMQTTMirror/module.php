<?php
declare(strict_types=1);

class GoEMQTTMirror extends IPSModule
{
    // MQTT DataIDs (Symcon 8.1)
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'; // Child -> Parent (SUB/PUB)
    private const MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}'; // Parent -> Child (Empfang)

    // ---------------------------
    // Create
    // ---------------------------
    public function Create()
    {
        parent::Create();

        $this->ensureProfiles();

        // Dein go-e Basis-Topic hier anpassen
        $this->RegisterPropertyString('BaseTopic', 'go-eCharger/285450');

        // Kern-Variablen
        $this->RegisterVariableInteger('Ampere_A',          'Ampere [A]',               'GoE.Ampere', 10);
        $this->EnableAction('Ampere_A');
        $this->RegisterVariableInteger('Leistung_W',        'Leistung [W]',             '~Watt',  20);
        $this->RegisterVariableInteger('CarState',          'Fahrzeugstatus',           'GoE.CarState', 25);
        $this->RegisterVariableBoolean('FahrzeugVerbunden', 'Fahrzeug verbunden',       '~Switch',30);
//        $this->RegisterVariableInteger('ALW',               'ALW (0/1)',                '',       40);
        $this->RegisterVariableBoolean('ALW',               'Allow Charging (ALW)',     '~Switch', 40);

//        $this->RegisterVariableInteger('FRC',               'FRC (0/1/2)',              '',       50);
        $this->RegisterVariableInteger('FRC',               'Force State (FRC)',        'GoE.ForceState', 50);

//        $this->RegisterVariableInteger('Phasenmodus',       'Phasenmodus (1/2)',        '',       60);
        $this->RegisterVariableInteger('Phasenmodus',       'Phasenmodus',              'GoE.PhaseMode', 60);
        $this->EnableAction('Phasenmodus');

        $this->RegisterVariableString('LastSeenUTC',        'Zuletzt gesehen (UTC)',    '',       70);

        // Debug
        $this->RegisterVariableString('NRG_RAW',            'NRG (roh)',                '~TextBox', 90);
    }

    // ---------------------------
    // ApplyChanges
    // ---------------------------
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ensureProfiles();

        $base = rtrim((string)$this->ReadPropertyString('BaseTopic'), '/');
        if ($base === '') {
            $this->LogMessage('BaseTopic ist leer.', KL_ERROR);
            $this->SetStatus(IS_EBASE + 1);
            return;
        }

        // Falls kein Parent gesetzt ist -> versuchen automatisch anzuhängen
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $parent = $this->autoAttachSingleMqttGateway();
        }
        if ($parent <= 0) {
            $this->LogMessage('Kein Parent (MQTT) verknüpft.', KL_ERROR);
            $this->SetStatus(IS_EBASE + 2);
            return;
        }

        // Debug: Parent anzeigen
        $pInst = @IPS_GetInstance($parent);
        if (is_array($pInst)) {
            $pMod  = @IPS_GetModule($pInst['ModuleID']);
            $this->LogMessage('Parent: '.(($pMod['ModuleName'] ?? '??')).' #'.$parent, KL_MESSAGE);
        }

        // Wildcard-Subscribe auf alle Keys unterhalb des BaseTopics
        $this->mqttSubscribe($base . '/+', 0);

        $this->SetStatus(IS_ACTIVE);
    }

    // ---------------------------
    // ReceiveData (eingehende MQTT-PUBLISH Frames)
    // ---------------------------
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) return;

        $topic   = (string)($data['Topic']   ?? '');
        $payload = (string)($data['Payload'] ?? '');

        // Guard: BaseTopic muss gesetzt sein
        $base = $this->ReadPropertyString('BaseTopic');
        if (!is_string($base) || $base === '') {
            $this->LogMessage('BaseTopic fehlt/leer – ReceiveData verworfen.', KL_WARNING);
            return;
        }

        $baseWithSlash = rtrim($base, '/') . '/';
        if ($topic === '' || strpos($topic, $baseWithSlash) !== 0) {
            return; // andere Topics ignorieren
        }

        $key = substr($topic, strlen($baseWithSlash)); // z.B. "nrg","car","amp","alw","psm","utc"

        switch ($key) {
            case 'ama':
            case 'amp':
                $this->SetValueSafe('Ampere_A', (int)$payload);
                break;

            case 'alw':
            {
                // v2: read-only Anzeige (true/false)
                $this->SetValueSafe('ALW', ((int)$payload) === 1);
                break;
            }

            case 'frc':
            {
                // 0=Neutral, 1=Force-Off, 2=Force-On
                $this->SetValueSafe('FRC', (int)$payload);
                break;
}

            case 'car':
            {
                // CarState ablegen + "verbunden" ableiten
                $state = is_numeric($payload) ? (int)$payload : 0;
                $this->SetValueSafe('CarState', $state);

                // "verbunden" = Idle/Charging/WaitCar/Complete
                $connected = in_array($state, [1,2,3,4], true);
                $this->SetValueSafe('FahrzeugVerbunden', $connected);
                break;
            }

            case 'psm':
            {
                // 1/2 durchreichen, sonst Payload robust casten
                $pm = (int)$payload;
                if ($pm !== 1 && $pm !== 2) {
                    // falls exotisch, einfach setzen – Profil zeigt Zahl
                }
                $this->SetValueSafe('Phasenmodus', $pm);
                break;
            }

            case 'utc':
                $this->SetValueSafe('LastSeenUTC', trim($payload, "\" \t\n\r\0\x0B"));
                break;

            case 'nrg':
                // optional fürs Debug:
                $this->SetValueSafe('NRG_RAW', $payload);

                // Nur PTotal (Index 11) auf Leistung_W schreiben
                $p = trim($payload, "\" \t\n\r\0\x0B");
                $w = null;

                if ($p !== '' && $p[0] === '[') {
                    // JSON-Array
                    $arr = json_decode($p, true);
                    if (is_array($arr) && isset($arr[11]) && is_numeric($arr[11])) {
                        $w = (int)round((float)$arr[11]); // PTotal
                    }
                } else {
                    // CSV (Komma/Semikolon)
                    $parts = preg_split('/[;,]/', $p);
                    if (is_array($parts) && isset($parts[11]) && is_numeric($parts[11])) {
                        $w = (int)round((float)$parts[11]); // PTotal
                    }
                }

                if ($w !== null) {
                    $this->SetValueSafe('Leistung_W', $w);
                }
                break;

            default:
                // Unbekannte Keys ignorieren (oder: dynamisch anlegen)
                break;
        }
    }

    // ======================================================================
    // Hilfsfunktionen
    // ======================================================================

    private function SetValueSafe(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid) {
            $old = @GetValue($vid);
            if ($old !== $value) {
                @SetValue($vid, $value);
            }
        }
    }

    // Kleiner Helfer für Topics
    private function bt(string $k): string
    {
        return rtrim($this->ReadPropertyString('BaseTopic'), '/') . '/' . $k;
    }

// SUBSCRIBE (Symcon 8.1; maximal kompatibel & gateway-freundlich)
private function mqttSubscribe(string $topic, int $qos = 0): void
{
    $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
    if ($parent <= 0) {
        $this->LogMessage('MQTT SUB SKIP: kein Parent', KL_WARNING);
        return;
    }

    // Einheitlicher Frame: generische Felder + Subscribe-spezifische Felder
    $frame = [
        'DataID'            => self::MQTT_TX,
        'PacketType'        => 8,          // SUBSCRIBE

        // Generisch (manche Builds/Validatoren verlangen das immer)
        'QualityOfService'  => $qos,
        'Retain'            => false,
        'Topic'             => $topic,
        'Payload'           => '',         // leer, um "Payload fehlt" zu vermeiden

        // SUBSCRIBE-spezifisch (8.1)
        'TopicFilter'       => $topic,

        // Legacy/Abwärtskompatibilität
        'Topics'            => [[
            'Topic'            => $topic,
            'TopicFilter'      => $topic,
            'QoS'              => $qos,
            'QualityOfService' => $qos
        ]]
    ];

    // Optional zum Debuggen:
    $this->LogMessage('SUB frame: '.json_encode($frame), KL_MESSAGE);

    $this->SendDataToParent(json_encode($frame));
}


    // PUBLISH (Symcon 8.1; kompatibel)
    private function mqttPublish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) { $this->LogMessage('MQTT PUB SKIP: kein Parent', KL_WARNING); return; }

        $this->SendDataToParent(json_encode([
            'DataID'            => self::MQTT_TX,
            'PacketType'        => 3,                 // PUBLISH
            'Topic'             => $topic,
            'Payload'           => $payload,
            'Retain'            => $retain,           // Pflicht in 8.1
            'QualityOfService'  => $qos,              // Pflicht in 8.1
            'QoS'               => $qos               // Abwärtskompatibel
        ]));
    }

    /**
     * Findet genau EIN MQTT-Gateway (Server ODER Client) und hängt diese Instanz darunter.
     * Rückgabe: Parent-ID oder 0.
     */
    private function autoAttachSingleMqttGateway(): int
    {
        $txDataId = self::MQTT_TX; // Gateways implementieren diese TX-DataID

        $candidates = [];
        foreach (IPS_GetInstanceList() as $iid) {
            if (!@IPS_InstanceExists($iid)) { continue; }
            $inst = @IPS_GetInstance($iid);
            if (!is_array($inst)) { continue; }
            if (!isset($inst['ModuleID']) || !is_string($inst['ModuleID']) || $inst['ModuleID'] === '') { continue; }

            $mod = @IPS_GetModule($inst['ModuleID']);
            if (!is_array($mod)) { continue; }

            $implemented = $mod['Implemented'] ?? [];
            if (is_array($implemented) && in_array($txDataId, $implemented, true)) {
                $candidates[] = $iid;
            }
        }

        if (count($candidates) === 1) {
            $pid = $candidates[0];
            @IPS_SetParent($this->InstanceID, $pid);
            $this->LogMessage("Auto-Parent gesetzt auf MQTT-Gateway #$pid", KL_MESSAGE);
            return $pid;
        }

        if (count($candidates) > 1) {
            $this->LogMessage('Mehrere MQTT-Gateways gefunden. Bitte Parent manuell wählen (Gateway ändern…).', KL_WARNING);
        } else {
            $this->LogMessage('Kein MQTT-Gateway gefunden. Bitte MQTT Server oder MQTT Client anlegen.', KL_WARNING);
        }
        return 0;
    }

    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('GoE.CarState')) {
            IPS_CreateVariableProfile('GoE.CarState', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('GoE.CarState', '', '');
            IPS_SetVariableProfileIcon('GoE.CarState', 'Car');
            // value, label, icon, color
            IPS_SetVariableProfileAssociation('GoE.CarState', 0, 'Unbekannt/Firmwarefehler', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 1, 'Bereit, kein Fahrzeug', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 2, 'Fahrzeug lädt', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 3, 'Fahrzeug verbunden / Bereit zum Laden', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 4, 'Ladung beendet, Fahrzeug noch verbunden', '', -1);
            IPS_SetVariableProfileAssociation('GoE.CarState', 5, 'Fehler', '', -1);
        }

        if (!IPS_VariableProfileExists('GoE.AmpLimit')) {
            IPS_CreateVariableProfile('GoE.AmpLimit', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('GoE.AmpLimit', 6, 16, 2);
            IPS_SetVariableProfileText('GoE.AmpLimit', '', ' A');
            IPS_SetVariableProfileIcon('GoE.AmpLimit', 'Electricity');
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

    // Grenzen für go-e (typisch): 6..32 A
    private const MIN_AMP = 6;
    private const MAX_AMP = 16;

    /**
     * Symcon-Action-Handler für schreibbare Variablen
     */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Ampere_A':
                // dynamisches Ampere-Limit → ama/set (Fallback amp/set)
                $this->sendSet('ama', (int)$Value);
                break;

            case 'Phasenmodus':
                $pm = ((int)$Value === 2) ? 2 : 1;
                $this->sendSet('psm', $pm);
                break;

            case 'FRC':
                $fs = in_array((int)$Value, [0,1,2], true) ? (int)$Value : 0;
                $this->sendSet('frc', $fs);
                break;

            case 'ALW':
                // v2: per MQTT nicht schreibbar → ignorieren
                $this->LogMessage('ALW ist über MQTT (v2) read-only. Bitte FRC verwenden.', KL_WARNING);
                break;
        }
    }

    private function sendSet(string $key, $value): void
    {
        $topic = rtrim($this->ReadPropertyString('BaseTopic'), '/') . '/' . $key . '/set';
        $this->mqttPublish($topic, (string)$value, 0, false);
    }
}
