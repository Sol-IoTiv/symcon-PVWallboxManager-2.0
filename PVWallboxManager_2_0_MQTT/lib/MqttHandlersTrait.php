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

        // Auto-BaseTopic erkennen
        if ($base === '') {
            if (preg_match('#^(go-eCharger/([^/]+))/#', $topic, $m)) {
                $detectedBase = $m[1];
                $detectedId   = $m[2];

                if ($filter !== '' && $filter !== $detectedId) return;

                if ($detectedBase !== '' && $this->ReadAttributeString('AutoBaseTopic') !== $detectedBase) {
                    $this->WriteAttributeString('AutoBaseTopic', $detectedBase);
                    $this->dbgLog('AutoBaseTopic', "detected={$detectedBase} (id={$detectedId}) via {$topic}");
                    $this->mqttSubscribe($detectedBase . '/+', 0);
                }
            } else {
                return;
            }
        } else {
            if (strpos($topic, $base . '/') !== 0) return;
        }

        // Roh-MQTT loggen (schaltbar)
        $this->dbgMqtt('RX', $topic . ' = ' . $payload);

        // Key bestimmen
        $baseNow = $this->currentBaseTopic();
        if ($baseNow !== '' && strpos($topic, $baseNow . '/') === 0) {
            $key = substr($topic, strlen($baseNow) + 1);
        } else {
            $parts = explode('/', $topic);
            $key = end($parts) ?: '';
        }

        switch ($key) {
            case 'ama':
            case 'amp':
            {
                $new = (int)$payload;
                $old = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
                if ($old !== $new) {
                    $this->SetValueSafe('Ampere_A', $new);
                    $this->dbgChanged('Ampere @'.$topic, $old.' A', $new.' A');
                }
                break;
            }

            case 'psm':
            {
                $new = (int)$payload; // 1/2
                $old = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus'));
                if ($old !== $new) {
                    $this->SetValueSafe('Phasenmodus', $new);
                    $this->dbgChanged('Phasenmodus @'.$topic, $this->phaseModeLabel($old), $this->phaseModeLabel($new));
                }
                break;
            }

            case 'frc':
            {
                $new = (int)$payload; // 0/1/2
                $old = (int)@GetValue(@$this->GetIDForIdent('FRC'));
                if ($old !== $new) {
                    $this->SetValueSafe('FRC', $new);
                    $this->WriteAttributeInteger('LastFrcChangeMs', (int)(microtime(true)*1000));
                    $this->dbgChanged('FRC @'.$topic, $this->frcLabel($old), $this->frcLabel($new));
                }
                break;
            }

            case 'car':
            {
                $new = is_numeric($payload) ? (int)$payload : 0;
                $old = (int)@GetValue(@$this->GetIDForIdent('CarState'));
                if ($old !== $new) {
                    $this->SetValueSafe('CarState', $new);
                    $this->dbgChanged('CarState @'.$topic, $this->carStateLabel($old), $this->carStateLabel($new));
                }
                break;
            }

            case 'utc':
            {
                $raw   = trim($payload, "\" \t\n\r\0\x0B");
                $oldTs = (int)@GetValue(@$this->GetIDForIdent('Uhrzeit')) ?: 0;

                try {
                    $dt = new DateTime($raw, new DateTimeZone('UTC'));
                } catch (Throwable $e) {
                    $rawNoMs = preg_replace('/\.\d+/', '', $raw);
                    try { $dt = new DateTime($rawNoMs, new DateTimeZone('UTC')); }
                    catch (Throwable $e2) { break; }
                }
                $ts = $dt->getTimestamp();

                if ($oldTs !== $ts) {
                    $this->SetValueSafe('Uhrzeit', $ts);
                    $this->dbgLog('UTC @'.$topic, date('Y-m-d H:i:s T', $ts) . " (ts={$ts})");
                }
                break;
            }

            case 'nrg':
            {
                // Nur Log. Kein parse/store, keine Loop hier.
                $this->dbgMqtt('NRG', $topic . ' = ' . $payload);
                break;
            }

            default:
                break;
        }

        // --- NEU: Frame puffern + One-Shot LOOP auslösen ---
        $buf = json_decode($this->ReadAttributeString('MQTT_BUF'), true) ?: [];
        $buf[$key] = $payload;
        $this->WriteAttributeString('MQTT_BUF', json_encode($buf, JSON_UNESCAPED_SLASHES));

        // Coalescing: deine vorhandene LOOP-Timer-Instanz als One-Shot verwenden
        $this->scheduleUpdateFromMqtt(350);

        // WICHTIG: kein parseAndStoreNRG(), kein updateHouseNetFromInputs(), keine Loop() direkt hier.
        return;
    }

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
            $this->dbgLog('NRG→Leistung_W', (string)$ptotal.' W');
        }
    }
}
