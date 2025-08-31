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
            case 'amp':     // Sollstrom
            case 'ama':     // einige FW senden hier den aktuellen Grenzwert mit
            {
                $new = (int)$payload;
                $old = (int)@GetValue(@$this->GetIDForIdent('Ampere_A'));
                if ($old !== $new) {
                    $this->SetValueSafe('Ampere_A', $new);
                    $this->WriteAttributeInteger('LastAmpSet', $new);
                    $this->dbgChanged('Ampere @'.$topic, $old.' A', $new.' A');
                }
                break;
            }

            case 'psm': {
                $raw = trim($payload);
                $newUi = ((string)$raw === '2' || (int)$raw === 2) ? 3 : 1; // UI: 1|3
                $oldUi = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus'));
                if ($oldUi !== $newUi) {
                    $this->SetValueSafe('Phasenmodus', $newUi);
                    $this->dbgChanged('Phasenmodus @'.$topic, $this->phaseModeLabel($oldUi), $this->phaseModeLabel($newUi));
                }
                break;
            }

            case 'frc':     // 0 Neutral | 1 Stop | 2 Start
            {
                $new = (int)$payload;
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
                // Nur puffern/loggen. Auswertung zeitentkoppelt.
                $this->dbgMqtt('NRG', $topic . ' = ' . $payload);
                break;
            }

            default:
                break;
        }

        // --- Puffer aktualisieren + One-Shot Anzeige-Update ---
        $buf = json_decode($this->ReadAttributeString('MQTT_BUF'), true) ?: [];
        $buf[$key] = $payload;
        $this->WriteAttributeString('MQTT_BUF', json_encode($buf, JSON_UNESCAPED_SLASHES));
        $this->scheduleUpdateFromMqtt(350);

        return;
    }

    private function parseAndStoreNRG(string $payload): void
    {
        // nrg: U(L1..N)[0..3], I(L1..L3)[4..6], P(L1..Total)[7..11], pf(L1..N)[12..15]
        $p = trim($payload, "\" \t\n\r\0\x0B");
        $arr = ($p !== '' && $p[0] === '[') ? @json_decode($p, true) : null;
        if (!is_array($arr)) {
            $parts = preg_split('/[;,]/', $p);
            if (is_array($parts)) $arr = array_map(static fn($x)=>is_numeric($x)?(float)$x:null, $parts);
        }
        if (!is_array($arr)) return;

        // Gesamtleistung (optional)
        if (isset($arr[11]) && is_numeric($arr[11])) {
            $ptotal = (int)round((float)$arr[11]);
            if ($vid = @$this->GetIDForIdent('PowerToCar_W')) @SetValue($vid, max(0,$ptotal));
        }

        $U = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $I_THRESH_A = 5.0;                                // Phase gilt als aktiv ab ≥ 5 A
        $P_THRESH_W = (int)round($U * $I_THRESH_A * 0.85); // ~980 W bei 230 V

        $i1 = $arr[4] ?? null;  $i2 = $arr[5] ?? null;  $i3 = $arr[6] ?? null;
        $p1 = $arr[7] ?? null;  $p2 = $arr[8] ?? null;  $p3 = $arr[9] ?? null;

        $phEff = 0; $via = 'none';

        // 1) Über Stromstärke pro Phase
        if ($i1 !== null && $i2 !== null && $i3 !== null) {
            $phEff = (int)((float)$i1 >= $I_THRESH_A) + (int)((float)$i2 >= $I_THRESH_A) + (int)((float)$i3 >= $I_THRESH_A);
            if ($phEff > 0) $via = 'I>=5A';
        }

        // 2) Fallback über Leistung je Phase
        if ($phEff === 0 && $p1 !== null && $p2 !== null && $p3 !== null) {
            $phEff = (int)((float)$p1 >= $P_THRESH_W) + (int)((float)$p2 >= $P_THRESH_W) + (int)((float)$p3 >= $P_THRESH_W);
            if ($phEff > 0) $via = 'P≈U*I';
        }

        // Fallback: mindestens 1
        if ($phEff < 1 || $phEff > 3) $phEff = 1;

        $this->WriteAttributeInteger('WB_ActivePhases', $phEff);
        $this->dbgLog('NRG→Phasen', sprintf(
            'eff=%d via=%s | I=[%.2f, %.2f, %.2f] A | P=[%.0f, %.0f, %.0f] W',
            $phEff, $via, (float)$i1, (float)$i2, (float)$i3, (float)$p1, (float)$p2, (float)$p3
        ));
    }
}
