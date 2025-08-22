<?php

declare(strict_types=1);

class GoEMQTTMirror extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('BaseTopic', 'go-eCharger/285450'); // <<< anpassen

        // Kern-Variablen
        $this->RegisterVariableInteger('Ampere_A',         'Ampere [A]',     '', 10);
        $this->RegisterVariableInteger('Leistung_W',       'Leistung [W]',   '~Watt',             20);
        $this->RegisterVariableBoolean('FahrzeugVerbunden','Fahrzeug verbunden', '~Switch',        30);
        $this->RegisterVariableInteger('ALW',              'ALW (0/1)',      '',                  40);
        $this->RegisterVariableInteger('FRC',              'FRC (0/1/2)',    '',                  50);
        $this->RegisterVariableInteger('Phasenmodus',      'Phasenmodus (1/2)', '',               60);
        $this->RegisterVariableString('LastSeenUTC',       'Zuletzt gesehen (UTC)', '',           70);

        // Optional: Rohdaten ablegen
        $this->RegisterVariableString('NRG_RAW',           'NRG (roh)',      '~TextBox',          90);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $base = rtrim($this->ReadPropertyString('BaseTopic'), '/');
        if ($base === '') {
            $this->SetStatus(IS_EBASE + 1);
            $this->LogMessage('BaseTopic ist leer.', KL_ERROR);
            return;
        }

        // Parent (MQTT Server ODER MQTT Client) muss gesetzt sein
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $this->SetStatus(IS_EBASE + 2);
            $this->LogMessage('Kein Parent (MQTT) verknüpft.', KL_ERROR);
            return;
        }

        // Wildcard-Subscribe auf alle Keys unterhalb des BaseTopics
        $this->mqttSubscribe($base . '/+', 0);

        $this->SetStatus(IS_ACTIVE);
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!$data) return;

        // Einheitliches Format: Topic + Payload
        $topic   = (string)($data['Topic']   ?? '');
        $payload = (string)($data['Payload'] ?? '');

        $base = rtrim($this->ReadPropertyString('BaseTopic'), '/') . '/';
        if ($topic === '' || strpos($topic, $base) !== 0) {
            return; // Anderes Topic
        }

        $key = substr($topic, strlen($base)); // z.B. "amp","alw","nrg","car","psm","utc"

        switch ($key) {
            case 'amp':
                $this->SetValueSafe('Ampere_A', (int)$payload);
                break;

            case 'alw':
                $this->SetValueSafe('ALW', (int)$payload);
                break;

            case 'frc':
                $this->SetValueSafe('FRC', (int)$payload);
                break;

            case 'car':
                $this->SetValueSafe('FahrzeugVerbunden', ((int)$payload) ? true : false);
                break;

            case 'psm':
                $mode = ((int)$payload === 2) ? 2 : 1;
                $this->SetValueSafe('Phasenmodus', $mode);
                break;

            case 'utc':
                // go-e liefert oft einen in Anführungszeichen stehenden ISO-String
                $this->SetValueSafe('LastSeenUTC', trim($payload, "\" \t\n\r\0\x0B"));
                break;

            case 'nrg':
                // 1) Roh speichern (hilft beim Debug)
                $this->SetValueSafe('NRG_RAW', $payload);

                // 2) Versuch, Gesamtleistung zu ermitteln (optional & robust):
                //    - JSON-Array: [..] → summe aller >=0 Werte
                //    - CSV: "a,b,c" → summe aller numerischen Werte
                $pTotal = $this->tryComputePowerFromNRG($payload);
                if ($pTotal !== null) {
                    $this->SetValueSafe('Leistung_W', (int)round($pTotal));
                }
                break;

            default:
                // Für unbekannte Keys nichts tun (oder: optional dynamische Variablen anlegen)
                break;
        }
    }

    // --- Hilfsfunktionen ----------------------------------------------------

    private function SetValueSafe(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid) {
            // Änderung nur schreiben, wenn wirklich anders → Logflut vermeiden
            $old = @GetValue($vid);
            if ($old !== $value) {
                @SetValue($vid, $value);
            }
        }
    }

    private function mqttSubscribe(string $topic, int $qos = 0): void
    {
        $parent = IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0;
        if ($parent <= 0) {
            $this->LogMessage('MQTT SUB SKIP: kein Parent', KL_WARNING);
            return;
        }
        $msg = [
            'DataID'     => '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}',
            'PacketType' => 8, // SUBSCRIBE
            'Topics'     => [['Topic' => $topic, 'QoS' => $qos]]
        ];
        $this->SendDataToParent(json_encode($msg));
    }

    private function tryComputePowerFromNRG(string $payload): ?float
    {
        $payload = trim($payload);

        // JSON-Array?
        if (strlen($payload) > 0 && $payload[0] === '[') {
            $arr = json_decode($payload, true);
            if (is_array($arr) && count($arr) > 0) {
                $sum = 0.0;
                foreach ($arr as $v) {
                    if (is_numeric($v)) {
                        $sum += (float)$v;
                    }
                }
                // Wenn plausible Summe, zurückgeben (ansonsten null lassen)
                return (is_finite($sum) && $sum >= 0) ? $sum : null;
            }
            return null;
        }

        // CSV (Komma/Semikolon)
        $parts = preg_split('/[;,]/', $payload);
        if (is_array($parts) && count($parts) > 0) {
            $sum = 0.0;
            $has = false;
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                if (is_numeric($p)) {
                    $sum += (float)$p;
                    $has = true;
                }
            }
            return $has ? $sum : null;
        }

        return null;
        // Hinweis: Für exakte pTotal-Bestimmung kannst du später ein Mapping ergänzen.
    }
}
