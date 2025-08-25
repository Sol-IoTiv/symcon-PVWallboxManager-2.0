<?php
declare(strict_types=1);

trait MqttHandlersTrait
{
    // Empfangene MQTT-PUBLISH Frames
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) return;

        $topic   = (string)($data['Topic']   ?? '');
        $payload = (string)($data['Payload'] ?? '');

        $base = (string)$this->ReadPropertyString('BaseTopic');
        if ($base === '') return;

        $baseWithSlash = rtrim($base, '/') . '/';
        if ($topic === '' || strpos($topic, $baseWithSlash) !== 0) {
            return; // andere Topics ignorieren
        }

        $key = substr($topic, strlen($baseWithSlash)); // z.B. "nrg","car","amp","alw","psm","utc","frc","ama"

        switch ($key) {
            case 'ama':
            case 'amp':
                $this->SetValueSafe('Ampere_A', (int)$payload);
                break;

            case 'frc':
                $v = (int)$payload; // 0/1/2
                $this->SetValueSafe('FRC', $v);
                break;

            case 'car':
                $state = is_numeric($payload) ? (int)$payload : 0;
                $this->SetValueSafe('CarState', $state);
                break;

            case 'psm':
                $pm = (int)$payload; // 1/2
                $this->SetValueSafe('Phasenmodus', $pm);
                break;

            case 'utc':
                $this->SetValueSafe('LastSeenUTC', trim($payload, "\" \t\n\r\0\x0B"));
                break;

            case 'nrg':
                $this->SetValueSafe('NRG_RAW', $payload);
                $this->parseAndStoreNRG($payload);
                break;

            default:
                // Optional: Weitere Topics später hier ergänzen
                break;
        }
    }

    // PTotal (Index 11) aus NRG-Frame extrahieren
    private function parseAndStoreNRG(string $payload): void
    {
        $p = trim($payload, "\" \t\n\r\0\x0B");

        $ptotal = null;
        if ($p !== '' && $p[0] === '[') {
            $arr = json_decode($p, true);
            if (is_array($arr) && isset($arr[11]) && is_numeric($arr[11])) {
                $ptotal = (int)round((float)$arr[11]);
            }
        } else {
            $parts = preg_split('/[;,]/', $p);
            if (is_array($parts) && isset($parts[11]) && is_numeric($parts[11])) {
                $ptotal = (int)round((float)$parts[11]);
            }
        }

        if ($ptotal !== null) {
            $this->SetValueSafe('Leistung_W', $ptotal);
        }
    }
}
