<?php

class SlackTphHeizung {

    private array $roomMapping;
    private string $slackToken;
    private string $hausverstandToken;

    private array $supportedCommands = [
        '/heizung_an', '/heizung_aus'
    ];
    public static ?SlackTphHeizung $instance;

    public static function create(): SlackTphHeizung {
        self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        require('config.php');
        $this->roomMapping = $config['roomMapping'];
        $this->hausverstandToken = $config['hausverstandToken'];
        $this->slackToken = $config['slackToken'];
    }

    /**
     * Main function to handle the incoming request
     * @return void
     */
    public function handle(): void {
        $this->log($_REQUEST);

        if (isset($_POST['token']) && $_POST['token'] == $this->slackToken
            && (in_array($_POST['command'], $this->supportedCommands))
        ) {

            if ($_POST['channel_name'] !== 'heizung-ticker') {
                $this->responseAndExit('Heizung kann nur im Kanal #heizung-ticker geschaltet werden.');
            }

            $command = $_POST['command'];
            $text = trim($_POST['text']);

            if (empty($text)) {
                $this->responseAndExit('Kein Raum angegeben. Mögliche Raumnamen: `salon`, `wohnzimmer`, `atelier`, `loetlabor` (in exakt dieser Schreibweise)');
            }

            $entityId = null;
            if (isset($this->roomMapping[$text])) {
                $entityId = $this->roomMapping[$text];
            } else {
                $this->responseAndExit('Unbekannter Raum, keine Schaltung.');
            }

            if ($command == '/heizung_an') {
                $urlSegment = 'turn_on';
            } else if ($command == '/heizung_aus') {
                $urlSegment = 'turn_off';
            } else {
                $this->responseAndExit('Unbekannter Befehl.');
            }

            $url = 'http://hausverstand.iot.fiber.garden:8123/api/services/switch/'.$urlSegment;

            $data = [
                'entity_id' => $entityId
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch,CURLOPT_POST, true);
            curl_setopt($ch,CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $this->hausverstandToken,
                "Content-Type: application/json"
            ]);

            $result = curl_exec($ch);
            if (!curl_errno($ch)) {

                $this->log($result);

                $this->responseAndExit(json_encode([
                    "response_type" => "in_channel",
                    "text" => "Heizung `".$urlSegment."` in `".$text."` von " . $_POST['user_name'] . " (`".$entityId."`)",
                ]), true);

            } else {
                $this->responseAndExit('API call to hausverstand failed. Jakob probably updated it and now everything is broken.');
            }

        } else {
            $this->responseAndExit('missing parameters');
        }
    }

    private function responseAndExit(mixed $text, bool $asJson = false): never {
        header("HTTP/1.1 200 OK");
        if ($asJson) header("Content-Type: application/json");
        if ($text != null) echo $text;
        exit;
    }


    /**
     * Super simple log file, enough for this application.
     * @param mixed $data
     * @return void
     */
    private function log(mixed $data): void {
        file_put_contents('heizung.log', print_r($data,true), FILE_APPEND);
    }
}

// self call
SlackTphHeizung::create()->handle();