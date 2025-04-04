<?php

class SlackTphHeizung {

    private array $roomMapping;
    private string $slackToken;
    private string $hausverstandToken;
    private CurlHandle $ch;

    private const COMMAND_ON = '/heizung_an';
    private const COMMAND_OFF = '/heizung_aus';
    private const COMMAND_STATUS = '/heizung_status';
    private array $supportedCommandsOnOff = [self::COMMAND_ON, self::COMMAND_OFF];
    private array $supportedCommandsStatus = [self::COMMAND_STATUS];
    private array $supportedCommandsAll = [self::COMMAND_ON, self::COMMAND_OFF, self::COMMAND_STATUS];

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

        $this->ch = curl_init();
    }

    public function handle(): void {
        if (isset($_POST['token']) && $_POST['token'] == $this->slackToken
            && (in_array($_POST['command'], $this->supportedCommandsAll))
        ) {

            $command = $_POST['command'];
            if (in_array($command, $this->supportedCommandsOnOff)) {
                $this->handleOnOff();
            }
            if (in_array($command, $this->supportedCommandsStatus)) {
                $this->handleStatus();
            }
        } else {
            $this->responseAndExit('missing parameters');
        }
    }

    /**
     * Main function to handle the incoming request
     * @return void
     */
    public function handleOnOff(): void {
        $this->log($_REQUEST);

        if ($_POST['channel_name'] !== 'heizung') {
            $this->responseAndExit('Die Heizung kann nur im Channel #heizung geschaltet werden.');
        }

        $command = $_POST['command'];
        $parts = preg_split('/\s+/', trim($_POST['text']), 2);
        $text = $parts[0] ?? null;
        $comment = $parts[1] ?? null;

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
            'entity_id' => 'switch.'.$entityId
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

            if (!empty($comment)) {
                $this->sendLog([
                    'name' => 'Heizung ' . $text . ' ' . $urlSegment,
                    'message' => $comment,
                    'entity_id' => 'switch.'.$entityId
                ]);
            }
            // when turning on, only send a new comment when one is given. else keep the old one.
            if ($urlSegment == 'turn_on' && !empty($comment)) {
                $this->sendComment($text, $comment);
            }
            if ($urlSegment == 'turn_off') {
                $this->sendComment($text, $comment ?? 'via slack, von ' . $_POST['user_name']);
            }

            $this->log($result);

            $this->responseAndExit(json_encode([
                "response_type" => "in_channel",
                "text" => "Heizung `".$urlSegment."` in `".$text."` von " . $_POST['user_name'] . " (`switch.".$entityId."`)" . " Kommentar: `" . ($comment ?? '<leer, bisheriger bleibt oder autocomment>') . "`",
            ]), true);

        } else {
            $this->responseAndExit('API call to hausverstand failed. Jakob probably updated it and now everything is broken.');
        }

    }

    private function sendComment(string $roomName, string $comment) {
        $this->post('http://hausverstand.iot.fiber.garden:8123/api/services/input_text/set_value', [
            'entity_id' => 'input_text.' . $roomName . '_heizkommentar',
            'value' => $comment
        ]);
    }

    private function sendLog(array $data) {
        $this->post('http://hausverstand.iot.fiber.garden:8123/api/services/logbook/log', $data);
    }

    private function handleStatus(): void {
        $results = [];
        if (empty($_POST['text'])) {
            $roomNames = array_keys($this->roomMapping);
            foreach($roomNames as $name) {
                $response = $this->get('http://hausverstand.iot.fiber.garden:8123/api/states/sensor.status_'.$name);

                $responseJSON = json_decode($response, true);
                if ($responseJSON !== null) {
                    $results[] = $responseJSON['state'] ?? '<no response for '.$name.'>';
                } else {
                    $results[] = 'FAIL';
                }

                $this->log($results);
            }
        } else {
            $text = trim($_POST['text']);
            $response = $this->get('http://hausverstand.iot.fiber.garden:8123/api/states/sensor.status_'.$text);
            $responseJSON = json_decode($response, true);
            $results[] = $responseJSON['state'] ?? '<no response for '.$text.'>';
        }

        $this->responseAndExit(json_encode([
            "response_type" => $_POST['channel_name'] === 'heizung' ? 'in_channel' : 'ephemeral',

            // TODO hier noch schick machen!
            "text" => print_r($results, true),
        ]), true);
    }

    private function get(string $url): string {
        return $this->curl($url, false);
    }

    private function post(string $url, array $data): string {
        return $this->curl($url, true, $data);
    }

    private function curl(string $url, bool $post, ?array $data = null): string {
        curl_reset($this->ch);
        curl_setopt($this->ch, CURLOPT_URL, $url);
        if ($post) {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        curl_setopt($this->ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->hausverstandToken,
            "Content-Type: application/json"
        ]);
        return curl_exec($this->ch);
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
