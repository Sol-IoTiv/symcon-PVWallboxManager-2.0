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

                    // ← Loggen der Erkennung (seltenes Event):
                    // immer im Instanz-Debug …
                    $this->SendDebug('Auto-BaseTopic', $detectedBase, 0);
                    // … und NUR wenn DebugLogging aktiv ist auch ins globale Log:
                    if ($this->ReadPropertyBoolean('DebugLogging')) {
                        IPS_LogMessage('GOEMQTT', 'Auto-BaseTopic erkannt: ' . $detectedBase);
                    }

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
                $raw = trim($payload, "\" \t\n\r\0\x0B");
                $oldTs = (int)@GetValue(@$this->GetIDForIdent('Uhrzeit')) ?: 0;

                try {
                    $dt = new DateTime($raw, new DateTimeZone('UTC'));
                } catch (Throwable $e) {
                    $raw = preg_replace('/\.\d+/', '', $raw);
                    try { $dt = new DateTime($raw, new DateTimeZone('UTC')); }
                    catch (Throwable $e2) { break; }
                }
                $ts = $dt->getTimestamp();

                if ($oldTs !== $ts) {
                    $this->SetValueSafe('Uhrzeit', $ts);
//                    $this->dbgLog('UTC→Uhrzeit', date('Y-m-d H:i:s T', $ts) . " (ts=$ts)");
                    $this->dbgLog('MQTT nrg (raw)', $payload);
                    $this->parseAndStoreNRG($payload);
                }
                break;
            }

            case 'nrg':
            {
                // Nur Instanz-Debug, KEIN IPS_LogMessage
                if ($this->ReadPropertyBoolean('DebugLogging')) {
                    $this->SendDebug('MQTT nrg (raw)', $payload, 0);
                }
                $this->parseAndStoreNRG($payload);
                break;
            }

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
