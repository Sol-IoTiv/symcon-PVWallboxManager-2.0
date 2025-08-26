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
        // nrg: U(L1,L2,L3,N)[0..3], I(L1,L2,L3)[4..6], P(L1,L2,L3,N,Total)[7..11], pf(L1,L2,L3,N)[12..15]
        $p = trim($payload, "\" \t\n\r\0\x0B");
        $arr = ($p !== '' && $p[0] === '[') ? @json_decode($p, true) : null;
        if (!is_array($arr)) {
            $parts = preg_split('/[;,]/', $p);
            if (is_array($parts)) $arr = array_map(static fn($x)=>is_numeric($x)?(float)$x:null, $parts);
        }
        if (!is_array($arr)) return;

        // Gesamtleistung
        if (isset($arr[11]) && is_numeric($arr[11])) {
            $ptotal = (int)round((float)$arr[11]);
            $this->SetValueSafe('Leistung_W', $ptotal);
            $this->dbgLog('NRG→Leistung_W', $ptotal.' W');
        }

        // Effektive Phasen: bevorzugt Strom (I), Mindestschwelle 1.0 A
        $U           = max(200, (int)$this->ReadPropertyInteger('NominalVolt'));
        $pmDeclared  = (int)@GetValue(@$this->GetIDForIdent('Phasenmodus')); // 1=1p, 2=3p
        $phDeclared  = ($pmDeclared === 2) ? 3 : 1;
        $I_THRESH_A  = 1.0;

        $i1 = $arr[4] ?? null;  $i2 = $arr[5] ?? null;  $i3 = $arr[6] ?? null;
        $p1 = $arr[7] ?? null;  $p2 = $arr[8] ?? null;  $p3 = $arr[9] ?? null;

        $phEff = 0; $via = 'declared';

        // 1) Über Strom (präferiert)
        if ($i1 !== null && $i2 !== null && $i3 !== null) {
            $phEff = (int)((float)$i1 >= $I_THRESH_A) + (int)((float)$i2 >= $I_THRESH_A) + (int)((float)$i3 >= $I_THRESH_A);
            if ($phEff > 0) $via = 'I>=3A';
        }

        // 2) Fallback über Leistung je Phase ~ (≈ U * 1A * Faktor)
        if ($phEff === 0 && $p1 !== null && $p2 !== null && $p3 !== null) {
            $thW = (int)round($U * $I_THRESH_A * 0.9); // ~207 W bei 230 V
            $phEff = (int)((float)$p1 >= $thW) + (int)((float)$p2 >= $thW) + (int)((float)$p3 >= $thW);
            if ($phEff > 0) $via = 'P≈U*1A';
        }

        if ($phEff <= 0) $phEff = $phDeclared; // letzter Fallback
        if ($phDeclared === 1) $phEff = 1;     // Kontaktor 1p begrenzt
        $phEff = min(3, max(1, $phEff));

        $this->WriteAttributeInteger('WB_ActivePhases', $phEff);
        $this->dbgLog('NRG→Phasen', sprintf(
            'phEff=%d via=%s | I1=%.2fA I2=%.2fA I3=%.2fA | P1=%.1fW P2=%.1fW P3=%.1fW',
            $phEff, $via,
            (float)$i1, (float)$i2, (float)$i3,
            (float)$p1, (float)$p2, (float)$p3
        ));
    }

}
