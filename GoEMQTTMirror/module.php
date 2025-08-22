<?php
declare(strict_types=1);

class GoEMQTTMirror extends IPSModule
{
    // ---------------------------
    // Create
    // ---------------------------
    public function Create()
    {
        parent::Create();

        // Dein go-e Basis-Topic hier anpassen
        $this->RegisterPropertyString('BaseTopic', 'go-eCharger/285450');

        // Kern-Variablen (ohne eigene Profile, nutzt Standard ~Watt / ~Intensity.Ampere)
        $this->RegisterVariableInteger('Ampere_A',          'Ampere [A]',               '',                  10);
        $this->RegisterVariableInteger('Leistung_W',        'Leistung [W]',             '~Watt',             20);
        $this->RegisterVariableBoolean('FahrzeugVerbunden', 'Fahrzeug verbunden',       '~Switch',           30);
        $this->RegisterVariableInteger('ALW',               'ALW (0/1)',                '',                  40);
        $this->RegisterVariableInteger('FRC',               'FRC (0/1/2)',              '',                  50);
        $this->RegisterVariableInteger('Phasenmodus',       'Phasenmodus (1/2)',        '',                  60);
        $this->RegisterVariableString('LastSeenUTC',        'Zuletzt gesehen (UTC)',    '',                  70);

        // Debug-Helfer
        $this->RegisterVariableString('NRG_RAW',            'NRG (roh)',                '~TextBox',          90);
    }

    // ---------------------------
    // ApplyChanges
    // ---------------------------
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $base = rtrim($this->ReadPropertyString('BaseTopic'), '/');
        if ($base === '') {
            $this->LogMessage('BaseTopic ist leer.', KL_ERROR);
            $this->SetStatus(IS_EBASE + 1);
            return;
        }

        // Falls kein Parent gesetzt ist und genau EIN MQTT-Gateway existiert → automatisch verbinden (Quality-of-Life)
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $parent = $this->autoAttachSingleMqttGateway();
        }

        if ($parent <= 0) {
            $this->LogMessage('Kein Parent (MQTT) verknüpft.', KL_ERROR);
            $this->SetStatus(IS_EBASE + 2);
            return;
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
        if (!is_array($data)) {
            return;
        }

        $topic   = (string)($data['Topic']   ?? '');
        $payload = (string)($data['Payload'] ?? '');

        $base = $this->ReadPropertyString('BaseTopic');
        if (!is_string($base) || $base === '') {
            // Instanz noch nicht vollständig initialisiert -> nicht craschen
            $this->LogMessage('BaseTopic (Property) fehlt oder ist leer – ReceiveData verworfen.', KL_WARNING);
            return;
        }
        $baseWithSlash = rtrim($base, '/') . '/';
        if ($topic === '' || strpos($topic, $baseWithSlash) !== 0) {
            return;
        }

        $key = substr($topic, strlen($baseWithSlash)); // z.B. "nrg","car","amp","alw","psm","utc"

        switch ($key) {
            case 'amp':
                $this->SetValueSafe('Ampere_A', (int)$payload);
                break;

            case 'alw':
                $this->SetValueSafe('ALW', (int)$payload);
                break;

            case 'frc':
                $this->SetValueSafe('FRC', (int)$payload);
                break;

            case 'car':
                $this->SetValueSafe('FahrzeugVerbunden', ((int)$payload) !== 0);
                break;

            case 'psm':
                $this->SetValueSafe('Phasenmodus', ((int)$payload === 2) ? 2 : 1);
                break;

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
                // Unbekannte Keys ignorieren (oder hier dynamische Variablen anlegen)
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

    // SUBSCRIBE (Symcon 8.1; strikt & kompatibel)
    private function mqttSubscribe(string $topic, int $qos = 0): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) { $this->LogMessage('MQTT SUB SKIP: kein Parent', KL_WARNING); return; }

        $this->SendDataToParent(json_encode([
            'DataID'            => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'        => 8,                 // SUBSCRIBE
            // 8.1-Pflichtfelder auf Root-Ebene:
            'TopicFilter'       => $topic,
            'QualityOfService'  => $qos,
            // Abwärtskompatibel für ältere Builds:
            'Topics'            => [[
                'TopicFilter'      => $topic,
                'QualityOfService' => $qos,
                'QoS'              => $qos
            ]]
            // WICHTIG: KEIN 'Topic' und KEIN 'Retain' im Root setzen,
            // damit der Frame garantiert nicht als PUBLISH fehlinterpretiert wird.
        ]));
    }

    // PUBLISH (Symcon 8.1, kompatibel)
    private function mqttPublish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) { $this->LogMessage('MQTT PUB SKIP: kein Parent', KL_WARNING); return; }

        $this->SendDataToParent(json_encode([
            'DataID'            => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'        => 3,   // PUBLISH
            'Topic'             => $topic,
            'Payload'           => $payload,
            'Retain'            => $retain,          // Pflicht in 8.1
            'QualityOfService'  => $qos,             // Pflicht in 8.1
            'QoS'               => $qos              // Abwärtskompatibel
        ]));
    }

    // Kleiner Helfer für Topics
    private function bt(string $k): string
    {
        return rtrim($this->ReadPropertyString('BaseTopic'), '/') . '/' . $k;
    }

    /**
     * Findet genau EIN MQTT-Gateway (Server ODER Client) und hängt diese Instanz darunter.
     * Rückgabe: Parent-ID oder 0 (wenn keins/einschließlich Mehrfachtreffer).
     */
    private function autoAttachSingleMqttGateway(): int
    {
        $txDataId = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'; // MQTT TX-DataID (Server/Client)

        $candidates = [];
        foreach (IPS_GetInstanceList() as $iid) {
            if (!@IPS_InstanceExists($iid)) { continue; }
            $inst = @IPS_GetInstance($iid);
            if (!is_array($inst)) { continue; }

            // Sicherstellen, dass eine gültige ModuleID existiert
            if (!isset($inst['ModuleID']) || !is_string($inst['ModuleID']) || $inst['ModuleID'] === '') {
                continue;
            }
            $moduleID = $inst['ModuleID'];

            $mod = @IPS_GetModule($moduleID);
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

}
