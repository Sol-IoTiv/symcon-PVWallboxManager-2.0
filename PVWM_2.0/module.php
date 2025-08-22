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

        $baseWithSlash = rtrim($this->ReadPropertyString('BaseTopic'), '/') . '/';
        if ($topic === '' || strpos($topic, $baseWithSlash) !== 0) {
            return; // andere Topics ignorieren
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

                $w = $this->nrgTotalW($payload);
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

    // ---- MQTT SUBSCRIBE (8.1 + abwärtskompatibel) ----
    private function mqttSubscribe(string $topic, int $qos = 0): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) { $this->LogMessage('MQTT SUB SKIP: kein Parent', KL_WARNING); return; }

        $this->SendDataToParent(json_encode([
            'DataID'            => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'        => 8, // SUBSCRIBE
            // 8.1 erwartet Root-Felder:
            'TopicFilter'       => $topic,
            'QualityOfService'  => $qos,
            // für ältere Builds zusätzlich:
            'Topics'            => [[
                'Topic'            => $topic,
                'TopicFilter'      => $topic,
                'QoS'              => $qos,
                'QualityOfService' => $qos
            ]]
        ]));
    }

    // ---- MQTT PUBLISH (8.1 + abwärtskompatibel) ----
    private function mqttPublish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) { $this->LogMessage('MQTT PUB SKIP: kein Parent', KL_WARNING); return; }

        $this->SendDataToParent(json_encode([
            'DataID'            => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'        => 3, // PUBLISH
            'Topic'             => $topic,
            'Payload'           => $payload,
            // 8.1 Pflichtfelder:
            'Retain'            => $retain,
            'QualityOfService'  => $qos,
            // für ältere Builds zusätzlich:
            'QoS'               => $qos
        ]));
    }

    // Holt PTotal (Index 11, 0-basiert) aus nrg → int Watt (ohne Skalierung)
    private function nrgTotalW(string $payload): ?int
    {
        $p = trim($payload, "\" \t\n\r\0\x0B"); // evtl. Anführungszeichen entfernen
        if ($p === '') {
            return null;
        }

        // JSON-Array?
        if ($p[0] === '[') {
            $arr = json_decode($p, true);
            if (is_array($arr) && array_key_exists(11, $arr) && is_numeric($arr[11])) {
                return (int)round((float)$arr[11]);
            }
            return null;
        }

        // CSV (Komma/Semikolon)
        $parts = preg_split('/[;,]/', $p);
        if (is_array($parts) && array_key_exists(11, $parts) && is_numeric($parts[11])) {
            return (int)round((float)$parts[11]);
        }

        return null;
    }

    /**
     * Findet genau EIN MQTT-Gateway (Server ODER Client) und hängt diese Instanz darunter.
     * Rückgabe: Parent-ID oder 0 (wenn keins/einschließlich Mehrfachtreffer).
     */
    private function autoAttachSingleMqttGateway(): int
    {
        // DataID, die MQTT-Gateways (Server/Client) zum Senden implementieren (TX)
        $txDataId = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

        $candidates = [];

        foreach (IPS_GetInstanceList() as $iid) {
            if (!@IPS_InstanceExists($iid)) {
                continue;
            }
            $inst = @IPS_GetInstance($iid);
            if (!is_array($inst)) {
                continue;
            }
            // Safer: key prüfen
            $moduleID = $inst['ModuleID'] ?? '';
            if (!is_string($moduleID) || $moduleID === '') {
                // keine gültige Modul-GUID → ignorieren
                continue;
            }
            $mod = @IPS_GetModule($moduleID);
            if (!is_array($mod)) {
                continue;
            }
            $implemented = $mod['Implemented'] ?? [];
            if (is_array($implemented) && in_array($txDataId, $implemented, true)) {
                // Das ist ein MQTT-Gateway (Server ODER Client)
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

