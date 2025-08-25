# symcon-PVWallboxMmanager-2.0

Projektstruktur:

PVWallboxManager2/
├─ module.json
├─ module.php
└─ lib/
   ├─ Profiles.php                  (Variable-Profile)
   ├─ Helpers.php                   (SetValueSafe, Topic-Helfer, utils)
   ├─ Communication/
   │   └─ MqttClientTrait.php       (MQTT TX/RX IDs, attach, subscribe, publish)
   └─ MqttHandlersTrait.php         (ReceiveData + Topic-spezifisches Parsing)
