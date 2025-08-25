<?php
declare(strict_types=1);

trait MqttClientTrait
{
    // MQTT DataIDs (Symcon 8.1)
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'; // Child -> Parent
    private const MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}'; // Parent -> Child

    protected function attachAndSubscribe(): bool
    {
        $base = rtrim((string)$this->ReadPropertyString('BaseTopic'), '/');
        if ($base === '') {
            $this->LogMessage('BaseTopic ist leer.', KL_ERROR);
            return false;
        }

        // Parent automatisch wählen, wenn noch keiner gesetzt ist
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $parent = $this->autoAttachSingleMqttGateway();
            if ($parent <= 0) {
                $this->LogMessage('Kein MQTT-Gateway gefunden. Bitte Parent manuell wählen.', KL_WARNING);
                return false;
            }
        }

        // Debug
        $pInst = @IPS_GetInstance($parent);
        if (is_array($pInst)) {
            $pMod = @IPS_GetModule($pInst['ModuleID']);
            $this->LogMessage('Parent: ' . (($pMod['ModuleName'] ?? '??')) . ' #' . $parent, KL_MESSAGE);
        }

        // Wildcard-Subscribe auf alle Keys
        $this->mqttSubscribe($base . '/+', 0);
        return true;
    }

    protected function mqttSubscribe(string $topic, int $qos = 0): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $this->LogMessage('MQTT SUB SKIP: kein Parent', KL_WARNING);
            return;
        }

        $frame = [
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 8, // SUBSCRIBE
            // generische Felder
            'QualityOfService' => $qos,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => '',
            // subscribe-spezifisch
            'TopicFilter'      => $topic,
            // legacy kompatibel
            'Topics'           => [[
                'Topic'            => $topic,
                'TopicFilter'      => $topic,
                'QoS'              => $qos,
                'QualityOfService' => $qos
            ]]
        ];

        $this->SendDataToParent(json_encode($frame));
    }

    protected function mqttPublish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $this->LogMessage('MQTT PUB SKIP: kein Parent', KL_WARNING);
            return;
        }

        $this->SendDataToParent(json_encode([
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 3, // PUBLISH
            'Topic'            => $topic,
            'Payload'          => $payload,
            'Retain'           => $retain,
            'QualityOfService' => $qos,
            'QoS'              => $qos
        ]));
    }

    // Komfort: <BaseTopic>/<key>/set senden
    protected function sendSet(string $key, string $value, int $qos = 0, bool $retain = false): void
    {
        $topic = rtrim((string)$this->ReadPropertyString('BaseTopic'), '/') . '/' . $key . '/set';
        $this->mqttPublish($topic, $value, $qos, $retain);
    }

    /**
     * Findet genau EIN MQTT-Gateway (Server ODER Client) und hängt die Instanz darunter.
     * Rückgabe: Parent-ID oder 0.
     */
    protected function autoAttachSingleMqttGateway(): int
    {
        $candidates = [];
        foreach (IPS_GetInstanceList() as $iid) {
            if (!@IPS_InstanceExists($iid)) continue;
            $inst = @IPS_GetInstance($iid);
            if (!is_array($inst)) continue;
            $mod = @IPS_GetModule($inst['ModuleID'] ?? '');
            if (!is_array($mod)) continue;

            $implemented = $mod['Implemented'] ?? [];
            if (is_array($implemented) && in_array(self::MQTT_TX, $implemented, true)) {
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
            $this->LogMessage('Mehrere MQTT-Gateways gefunden. Bitte Parent manuell wählen.', KL_WARNING);
        } else {
            $this->LogMessage('Kein MQTT-Gateway gefunden. Bitte MQTT Server/Client anlegen.', KL_WARNING);
        }
        return 0;
    }
}
