<?php

function getMessageTimePrefix()
{
    return getColorText(date('[Y.m.d H:i:s]'), 'cyan');
}

function getColorText($text, $color)
{
    try {
        $colors = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'magenta' => "\033[35m",
            'cyan' => "\033[36m",
            'white' => "\033[37m",
            'reset' => "\033[0m"
        ];

        if (array_key_exists($color, $colors)) {
            return $colors[$color] . $text . $colors['reset'];
        }
    } catch (Exception $err) {
        echo getMessageTimePrefix() . getColorText("\n < < < < < < < < !!! Exception !!! \n\n", 'red');
        print_r($err);
        echo getMessageTimePrefix() . getColorText("\n !!! Exception !!! > > > > > > > > \n\n", 'red');
        return $text;
    }
}

$PORT = 12345;
$socket = stream_socket_server("tcp://0.0.0.0:" . $PORT, $errno, $errstr);

echo getMessageTimePrefix() . getColorText(' WebSoket server: tcp://0.0.0.0:' . $PORT . "\n", 'green');
echo getMessageTimePrefix() . getColorText(' WebSoket server:  ws://0.0.0.0:' . $PORT . ' - for JavaScript' . "\n", 'green');

if (!$socket) {
    die("$errstr ($errno)\n");
}

$connects = array();
while (true) {
    try {
        //формируем массив прослушиваемых сокетов:
        $read = $connects;
        $read[] = $socket;
        $write = $except = null;

        if (!stream_select($read, $write, $except, null)) {//ожидаем сокеты доступные для чтения (без таймаута)
            break;
        }

        if (in_array($socket, $read)) {//есть новое соединение
            //принимаем новое соединение и производим рукопожатие:
            if (($connect = stream_socket_accept($socket, -1)) && $info = handshake($connect)) {
                $connects[] = $connect;//добавляем его в список необходимых для обработки
                onOpen($connect, $info);//вызываем пользовательский сценарий
            }
            unset($read[array_search($socket, $read)]);
        }

        foreach ($read as $connect) {//обрабатываем все соединения
            $data = fread($connect, 100000);

            if (!$data) { //соединение было закрыто
                fclose($connect);
                unset($connects[array_search($connect, $connects)]);
                onClose($connect);//вызываем пользовательский сценарий
                continue;
            }

            onMessage($connect, $data);//вызываем пользовательский сценарий
        }
    } catch (Exception $err) {
        echo "\n" . getMessageTimePrefix() . getColorText(" < < < < < < < < \n\n", 'red');
        print_r($err);
        echo "\n" . getMessageTimePrefix() . getColorText(" > > > > > > > > \n\n", 'red');
    }
}

fclose($server);

function handshake($connect)
{
    $info = array();

    $line = fgets($connect);
    $header = explode(' ', $line);
    $info['method'] = $header[0];
    $info['uri'] = $header[1];

    //считываем заголовки из соединения
    while ($line = rtrim(fgets($connect))) {
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $info[$matches[1]] = $matches[2];
        } else {
            break;
        }
    }

    $address = explode(':', stream_socket_get_name($connect, true)); //получаем адрес клиента
    $info['ip'] = $address[0];
    $info['port'] = $address[1];

    if (empty($info['Sec-WebSocket-Key'])) {
        return false;
    }

    //отправляем заголовок согласно протоколу вебсокета
    $SecWebSocketAccept = base64_encode(pack('H*', sha1($info['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Accept:$SecWebSocketAccept\r\n\r\n";
    fwrite($connect, $upgrade);

    return $info;
}

function encode($payload, $type = 'text', $masked = false)
{
    $frameHead = array();
    $payloadLength = strlen($payload);

    switch ($type) {
        case 'text':
            // first byte indicates FIN, Text-Frame (10000001):
            $frameHead[0] = 129;
            break;

        case 'close':
            // first byte indicates FIN, Close Frame(10001000):
            $frameHead[0] = 136;
            break;

        case 'ping':
            // first byte indicates FIN, Ping frame (10001001):
            $frameHead[0] = 137;
            break;

        case 'pong':
            // first byte indicates FIN, Pong frame (10001010):
            $frameHead[0] = 138;
            break;
    }

    // set mask and payload length (using 1, 3 or 9 bytes)
    if ($payloadLength > 65535) {
        $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 255 : 127;
        for ($i = 0; $i < 8; $i++) {
            $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
        }
        // most significant bit MUST be 0
        if ($frameHead[2] > 127) {
            return array('type' => '', 'payload' => '', 'error' => 'frame too large (1004)');
        }
    } elseif ($payloadLength > 125) {
        $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
        $frameHead[1] = ($masked === true) ? 254 : 126;
        $frameHead[2] = bindec($payloadLengthBin[0]);
        $frameHead[3] = bindec($payloadLengthBin[1]);
    } else {
        $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
    }

    // convert frame-head to string:
    foreach (array_keys($frameHead) as $i) {
        $frameHead[$i] = chr($frameHead[$i]);
    }
    if ($masked === true) {
        // generate a random mask:
        $mask = array();
        for ($i = 0; $i < 4; $i++) {
            $mask[$i] = chr(rand(0, 255));
        }

        $frameHead = array_merge($frameHead, $mask);
    }
    $frame = implode('', $frameHead);

    // append payload to frame:
    for ($i = 0; $i < $payloadLength; $i++) {
        $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
    }

    return $frame;
}

function decode($data)
{
    $unmaskedPayload = '';
    $decodedData = array();

    // estimate frame type:
    $firstByteBinary = sprintf('%08b', ord($data[0]));
    $secondByteBinary = sprintf('%08b', ord($data[1]));
    $opcode = bindec(substr($firstByteBinary, 4, 4));
    $isMasked = ($secondByteBinary[0] == '1') ? true : false;
    $payloadLength = ord($data[1]) & 127;

    // unmasked frame is received:
    if (!$isMasked) {
        return array('type' => '', 'payload' => '', 'error' => 'protocol error (1002)');
    }

    switch ($opcode) {
        // text frame:
        case 1:
            $decodedData['type'] = 'text';
            break;

        case 2:
            $decodedData['type'] = 'binary';
            break;

        // connection close frame:
        case 8:
            $decodedData['type'] = 'close';
            break;

        // ping frame:
        case 9:
            $decodedData['type'] = 'ping';
            break;

        // pong frame:
        case 10:
            $decodedData['type'] = 'pong';
            break;

        default:
            return array('type' => '', 'payload' => '', 'error' => 'unknown opcode (1003)');
    }

    if ($payloadLength === 126) {
        $mask = substr($data, 4, 4);
        $payloadOffset = 8;
        $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
    } elseif ($payloadLength === 127) {
        $mask = substr($data, 10, 4);
        $payloadOffset = 14;
        $tmp = '';
        for ($i = 0; $i < 8; $i++) {
            $tmp .= sprintf('%08b', ord($data[$i + 2]));
        }
        $dataLength = bindec($tmp) + $payloadOffset;
        unset($tmp);
    } else {
        $mask = substr($data, 2, 4);
        $payloadOffset = 6;
        $dataLength = $payloadLength + $payloadOffset;
    }

    /**
     * We have to check for large frames here. socket_recv cuts at 1024 bytes
     * so if websocket-frame is > 1024 bytes we have to wait until whole
     * data is transferd.
     */
    if (strlen($data) < $dataLength) {
        return false;
    }

    if ($isMasked) {
        for ($i = $payloadOffset; $i < $dataLength; $i++) {
            $j = $i - $payloadOffset;
            if (isset($data[$i])) {
                $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
            }
        }
        $decodedData['payload'] = $unmaskedPayload;
    } else {
        $payloadOffset = $payloadOffset - 4;
        $decodedData['payload'] = substr($data, $payloadOffset);
    }

    return $decodedData;
}

//пользовательские сценарии:

function onOpen($connect, $info)
{
    echo getMessageTimePrefix() . getColorText(" OPEN: somebody connect to WebSoket server\n", 'magenta');
    fwrite($connect, encode('Привет'));
}

function onClose($connect)
{
    echo getMessageTimePrefix() . getColorText(" CLOSE: somebody disconnect from WebSocket server\n", 'magenta');
}

function onMessage($connect, $data)
{
    $STRING = decode($data)['payload'];
    $STRING_LENGTH = strlen($STRING);

    if ($STRING_LENGTH === 0) {
        echo "\n" . getMessageTimePrefix() . getColorText(" < < < < < < < < \n\n", 'yellow');
        echo getMessageTimePrefix() . getColorText(" Somebody send empty message to WebSoket server \n", 'yellow');
        echo "\n" . getMessageTimePrefix() . getColorText(" > > > > > > > > \n\n", 'yellow');
        return;
    }

    echo "\n" . getMessageTimePrefix() . getColorText(" < < < < < < < < \n\n", 'green');
    echo getMessageTimePrefix() . getColorText(" Somebody send message to WebSoket server: \n", 'green');
    echo getMessageTimePrefix() . getColorText($STRING . "\n", 'green');
    echo "\n" . getMessageTimePrefix() . getColorText(" > > > > > > > > \n\n", 'green');
}
