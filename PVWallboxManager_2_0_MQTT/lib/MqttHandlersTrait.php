<?php
declare(strict_types=1);

trait MqttHandlersTrait
{
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) return;

        $topic   = (string)($data['Topic']   ?? '');
        $payload = (string)($data['Payload'] ?? '');
        if ($topic === '') return;

        // 1) Falls BaseTopic noch unbekannt: aus Topic ableiten (go-eCharger/<id>)
        $base = $this->currentBaseTopic();
        if ($base === '') {
            if (preg_match('#^(go-eCharger/[^/]+)/#', $topic, $m)) {
                $detected = $m[1];
                if ($detected !== '' && $this->ReadAttributeString('AutoBaseTopic') !== $detected) {
                    $this->WriteAttributeString('AutoBaseTopic', $detected);
                    $this->LogMessage('Auto-BaseTopic erkannt: ' . $detected, KL_MESSAGE);

                    // Optional: zusätzlich den konkreten Stamm abonnieren (breiter Subscribe bleibt bestehen)
                    $this->mqttSubscribe($detected . '/+', 0);
                }
            }
        }

        // 2) Key bestimmen
        $base = $this->currentBaseTopic();
        if ($base !== '' && strpos($topic, $base . '/') === 0) {
            $key = substr($topic, strlen($base) + 1);
        } else {
            // Auto-Phase: letztes Segment als Key
            $parts = explode('/', $topic);
            $key = end($parts) ?: '';
        }

        // 3) Werte verarbeiten
        switch ($key) {
            case 'ama':
            case 'amp':
                $this->SetValueSafe('Ampere_A', (int)$payload);
                break;

            case 'frc':
                $this->SetValueSafe('FRC', (int)$payload);
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
                // weitere Topics später ergänzen
                break;
        }
    }

    private function parseAndStoreNRG(string $payload): void
    {
        $p = trim($payload, "\" \t\n\r\0\x0B");
        $ptotal = null;

        if ($p !== '' && $p[0] === '[') {
            $arr = json_decode($p, true);
            if (is_array($arr) && isset($arr[11]) && is_numeric($arr[11])) {
                $ptotal = (int)round((float)$arr[11]); // PTotal
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
