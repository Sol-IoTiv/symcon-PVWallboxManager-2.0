<?php
declare(strict_types=1);

trait MqttClientTrait
{
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    private array $GOE_KEYS = ['utc','nrg','car','amp','ama','psm','frc','alw'];

    protected function attachAndSubscribe(): bool
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $parent = $this->autoAttachSingleMqttGateway();
            if ($parent <= 0) {
                $this->warnLog('MQTT', 'Kein MQTT-Gateway gefunden.');
                return false;
            }
        }

        $pInst = @IPS_GetInstance($parent);
        if (is_array($pInst)) {
            $pMod = @IPS_GetModule($pInst['ModuleID']);
            $this->infoLog('MQTT Parent', ($pMod['ModuleName'] ?? '??') . ' #' . $parent, true);
        }

        $base   = $this->currentBaseTopic();
        $filter = trim($this->ReadPropertyString('DeviceIDFilter'));

        if ($base === '') {
            $devicePart = ($filter !== '') ? $filter : '+';
            foreach ($this->GOE_KEYS as $k) {
                $this->mqttSubscribe('go-eCharger/' . $devicePart . '/' . $k, 0);
            }
            return true;
        }

        $this->mqttSubscribe($base . '/+', 0);
        return true;
    }

    protected function mqttSubscribe(string $topic, int $qos = 0): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) { return; }

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

    private function mqttSubscribeAll(): void
    {
        $base = rtrim($this->currentBaseTopic(), '/');
        if ($base === '') return;
        foreach (['nrg','car','alw','amp','psm','utc','eto'] as $t) {
            $this->mqttSubscribe($base.'/'.$t, 0);
        }
    }

    protected function mqttPublish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) { return; }

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

    private function sendSet(string $key, string $payload): void
    {
        $base = $this->currentBaseTopic();
        if ($base === '') return;
        $topic = $base . '/' . $key . '/set';   // amp/set, frc/set, psm/set
        $this->mqttPublish($topic, $payload, 0, false);
        $this->dbgMqtt('TX', $topic.' = '.$payload);
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
            return $pid;
        }
        return 0;
    }
}
