<?php
declare(strict_types=1);

trait MqttClientTrait
{
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    // Relevante Keys für Auto-Subscribe
    private array $GOE_KEYS = ['utc','nrg','car','amp','ama','psm','frc','alw'];

    protected function attachAndSubscribe(): bool
    {
        // Parent setzen/prüfen
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $parent = $this->autoAttachSingleMqttGateway();
            if ($parent <= 0) {
                $this->LogMessage('Kein MQTT-Gateway gefunden. Bitte Parent wählen.', KL_WARNING);
                return false;
            }
        }

        // Debug Parent
        $pInst = @IPS_GetInstance($parent);
        if (is_array($pInst)) {
            $pMod = @IPS_GetModule($pInst['ModuleID']);
            $this->LogMessage('Parent: ' . (($pMod['ModuleName'] ?? '??')) . ' #' . $parent, KL_MESSAGE);
        }

        $base = $this->currentBaseTopic();
        if ($base === '') {
            // Auto-Modus: nur die relevanten Keys hören
            foreach ($this->GOE_KEYS as $k) {
                $this->mqttSubscribe('go-eCharger/+/' . $k, 0);
            }
            return true;
        }

        // Fixer Stamm bekannt
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
            'PacketType'       => 8,
            'QualityOfService' => $qos,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => '',
            'TopicFilter'      => $topic,
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
            'PacketType'       => 3,
            'Topic'            => $topic,
            'Payload'          => $payload,
            'Retain'           => $retain,
            'QualityOfService' => $qos,
            'QoS'              => $qos
        ]));
    }

    protected function sendSet(string $key, string $value, int $qos = 0, bool $retain = false): void
    {
        $topic = $this->bt($key) . '/set';
        $this->mqttPublish($topic, $value, $qos, $retain);
    }

    protected function autoAttachSingleMqttGateway(): int
    {
        $candidates = [];
        foreach (IPS_GetInstanceList() as $iid) {
            if (!@IPS_InstanceExists($iid)) continue;
            $inst = @IPS_GetInstance($iid);
            if (!is_array($inst)) continue;
            $mod = @IPS_GetModule($inst['ModuleID'] ?? '');
            if (!is_array($mod)) continue;
            $impl = $mod['Implemented'] ?? [];
            if (is_array($impl) && in_array(self::MQTT_TX, $impl, true)) {
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
