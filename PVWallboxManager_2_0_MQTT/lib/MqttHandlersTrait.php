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

        $base   = $this->currentBaseTopic();
        $filter = trim($this->ReadPropertyString('DeviceIDFilter'));

        // 1) BaseTopic unbekannt → aus Topic ableiten (go-eCharger/<id>)
        if ($base === '') {
            if (preg_match('#^(go-eCharger/([^/]+))/#', $topic, $m)) {
                $detectedBase = $m[1];   // go-eCharger/<id>
                $detectedId   = $m[2];   // <id>

                // Falls Filter gesetzt: nur diese ID akzeptieren
                if ($filter !== '' && $filter !== $detectedId) {
                    return; // falsches Gerät für diese Instanz
                }

                if ($detectedBase !== '' && $this->ReadAttributeString('AutoBaseTopic') !== $detectedBase) {
                    $this->WriteAttributeString('AutoBaseTopic', $detectedBase);
                    $this->LogMessage('Auto-BaseTopic erkannt: ' . $detectedBase, KL_MESSAGE);

                    // zusätzlich konkret subscriben
                    $this->mqttSubscribe($detectedBase . '/+', 0);
                }
            } else {
                return; // kein go-eCharger Topic
            }
        } else {
            // BaseTopic gesetzt → alles außerhalb ignorieren
            if (strpos($topic, $base . '/') !== 0) return;
        }

        // 2) Key bestimmen
        $baseNow = $this->currentBaseTopic();
        if ($baseNow !== '' && strpos($topic, $baseNow . '/') === 0) {
            $key = substr($topic, strlen($baseNow) + 1);
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
            {
                $raw = trim($payload, "\" \t\n\r\0\x0B"); // z.B. 2025-08-11T11:55:42.988

                // robust gegen Millisekunden/ohne Z
                $ts = 0;
                try {
                    $dt = new DateTime($raw, new DateTimeZone('UTC'));
                    $ts = $dt->getTimestamp();
                } catch (Throwable $e) {
                    // Fallback: Millisekunden entfernen und erneut versuchen
                    $clean = preg_replace('/\.\d+/', '', $raw);
                    try {
                        $dt = new DateTime($clean, new DateTimeZone('UTC'));
                        $ts = $dt->getTimestamp();
                    } catch (Throwable $e2) {
                        // Letzter Fallback: ignorieren
                        break;
                    }
                }

                // → Integer mit Profil ~UnixTimestamp => WebFront zeigt lokale Zeit
                $this->SetValueSafe('Uhrzeit', $ts);
                break;
            }

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
