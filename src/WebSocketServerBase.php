<?php

namespace pekand\WebSocketServer;

class WebSocketServerBase {
    //convert byte array string to binnary representation array "A" -> "01000001"
    protected  function str2bin($s) {
        $res = "";
        for($i = 0;  $i < strlen($s); $i++) {
            $o = ord($s[$i]);
            for($j = 7;  $j >= 0; $j--) {
                $res .= ($o & (1 << $j)) ? '1' : '0';
            }
        }
        return $res;
    }

    //convert int to binnary string representation  65 -> "01000001"
    protected function toBin($d, $pad) {
        return str_pad(decbin($d), $pad, "0", STR_PAD_LEFT);
    }

    // convert binnary representation as string to byte array string "01000001" -> "A"
    protected function bin2str($s) {
        $r = "";
        $c = 0;
        $cnt = 1;
        for ($i = 0; $i < strlen($s); $i++) {

            $c = ($c + ($s[$i] == "1" ? 1 : 0));

            if ($cnt == 8) {
                $r .= chr($c);
                $c = 0;
                $cnt = 0;
            }

            $c = $c << 1;

            $cnt++;
        }
        return $r;
    }

    protected function createConnectHeader($data) {
        preg_match('#Sec-WebSocket-Key: (.*)#', $data, $matches);

        $key = "";
        if (isset($matches[1])) {
            $key = base64_encode(pack(
                'H*',
                sha1(trim($matches[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')
            ));
        }

        $headers = "HTTP/1.1 101 Switching Protocols\n";
        $headers .= "Upgrade: websocket\n";
        $headers .= "Connection: Upgrade\n";
        $headers .= "Sec-WebSocket-Version: 13\n";
        $headers .= "Sec-WebSocket-Accept: $key\n\n";

        return $headers;
    }

    public function mesage($message, $opcode = 1, $mask = false) {

        $len = strlen($message);
        $lenext = "";
        if ($len >= 2**16) {
            $len = 127;
            $lenext = $this->toBin(strlen($message), 8*8);
        } else if ($len > 125) {
            $len = 126;
            $lenext = strlen($message);
            $lenext = $this->toBin(strlen($message), 8*2);
        }

        $frame = [
            'fin' => '1',
            'rsv1' => '0',
            'rsv2' => '0',
            'rsv3' => '0',
            'opcode'=> $this->toBin($opcode, 4),
            'mask' => $mask ? '1' : '0',
            'len' => $this->toBin($len, 7),
            'lenext' => $lenext,
        ];

        $frameHeader = "";
        foreach ($frame as $v) {
            $frameHeader .= $v;
        }

        if($mask) {
            $maskingkey = openssl_random_pseudo_bytes(4);

            $messageMasked = "";
            for ($i = 0; $i<strlen($message); $i++)
                $messageMasked .= $message[$i] ^ $maskingkey[$i % 4];

            return $this->bin2str($frameHeader).$maskingkey.$messageMasked;
        }

        return $this->bin2str($frameHeader).$message;
    }

    // rfc6455 The WebSocket Protocol
    // https://tools.ietf.org/html/rfc6455
    protected function proccessRequest($lastFrame, $data)
    {

        $frames = [];

        if ($lastFrame != null && isset($frame['partialdata']) && $frame['partialdata'] !== "") { // process short header from previous frame
            $data = $frame['partialdata'] . $data;
            $lastFrame = null;
        }

        // proccess additional data
        if ($lastFrame != null && isset($frame['full']) && !$frame['full']) {
            $frame = $lastFrame;

            $len = ($frame['len'] == 126 || $frame['len'] == 127) ? $frame['lenext'] : $frame['len'];

            $remainingLength = $len - strlen($frame['payloaddata']);
            $remainingData = substr($data, 0, $remainingLength);

            if ($frame['mask']) {
                for ($i = 0; $i<strlen($remainingData); $i++)
                    $frame['payloaddata'] .= $remainingData[$i] ^ $frame['maskingkey'][$i % 4];
            } else {
                $frame['payloaddata'] .= $remainingData;
            }

            if($len == strlen($frame['payloaddata'])) {
                $frame['full'] == true;
            }

            $frames[] = $frame;

            $data = substr($data, $remainingLength);
        }

        while (strlen($data) > 0) {     // split data to frames
            $frame = [
                'full' => false,
                'fin' =>0,
                'rsv1' => 0,
                'rsv2' => 0,
                'rsv3' => 0,
                'opcode' => 0,
                'mask' => 0,
                'maskingkey' => null,
                'len' => 0,
                'lenext' => 0,
                'partialdata' => "",
                'payloaddata' => "",
            ];

            if(strlen($data)<2) { //header is too short
                $frame['partialdata'] = $data;
                $frames[] = $frame;
                break;
            }

            $b1 = $this->str2bin($data[0]);
            $frame['fin'] = $b1[0] == '1';
            $frame['rsv1'] = $b1[1] == '1';
            $frame['rsv2'] = $b1[2] == '1';
            $frame['rsv3'] = $b1[3] == '1';
            $frame['opcode'] = bindec(substr($b1, 4, 4));

            if ($frame['rsv1'] != 0 || $frame['rsv2'] != 0 || $frame['rsv3'] != 0) {
                throw new \Exception("Reserved bytes set to unexpected value in frame");
            }

            if (!in_array($frame['opcode'], [0,1,2,8,9,10])) {
                throw new \Exception("Unexpected opcode value in frame");
            }

            $b2 = $this->str2bin($data[1]);
            $frame['mask'] = $b2[0] == '1';
            $frame['len'] = $frame['mask'] ? ord($data[1]) & 127 : ord($data[1]);

            $frame['lenext'] = 0;
            if ($frame['len']===126) {

                if(strlen($data)<4) { // header is too short wait for additional data
                    $frame['partialdata'] = $data;
                    $frames[] = $frame;
                    break;
                }

                $frame['lenext'] = bindec(
                    $this->str2bin($data[2]).
                    $this->str2bin($data[3])
                );
            } elseif ($frame['len']===127) {
                if(strlen($data)<10) { // header is too short wait for additional data
                    $frame['partialdata'] = $data;
                    $frames[] = $frame;
                    break;
                }

                $frame['lenext'] = bindec(
                    $this->str2bin($data[2]).
                    $this->str2bin($data[3]).
                    $this->str2bin($data[4]).
                    $this->str2bin($data[5]).
                    $this->str2bin($data[6]).
                    $this->str2bin($data[7]).
                    $this->str2bin($data[8]).
                    $this->str2bin($data[9])
                );
            }

            $frame['payloaddata'] = "";
            if ($frame['mask']) {

                if ($frame['len']===126) {
                    if(strlen($data)<8) { // header is too short wait for additional data
                        $frame['partialdata'] = $data;
                        $frames[] = $frame;
                        break;
                    }

                    $frame['maskingkey'] = substr($data, 4, 4);
                } elseif ($frame['len']===127) {
                    if(strlen($data)<12) { // header is too short wait for additional data
                        $frame['partialdata'] = $data;
                        $frames[] = $frame;
                        break;
                    }

                    $frame['maskingkey'] = substr($data, 10, 4);
                } else {
                    if(strlen($data)<6) { // header is too short wait for additional data
                        $frame['partialdata'] = $data;
                        $frames[] = $frame;
                        break;
                    }

                    $frame['maskingkey'] = substr($data, 2, 4);
                }

                if ($frame['len']===126){
                    $coded_data = substr($data, 8, $frame['lenext']);
                    $data = substr($data, $frame['lenext'] + 8);
                } elseif ($frame['len']===127) {
                    $coded_data = substr($data, 14, $frame['lenext']);
                    $data = substr($data, $frame['lenext'] + 14);
                } else {
                    $coded_data = substr($data, 6, $frame['len']);
                    $data = substr($data, $frame['len'] + 6);
                }

                for ($i = 0; $i<strlen($coded_data); $i++) {
                    $frame['payloaddata'] .= $coded_data[$i] ^ $frame['maskingkey'][$i % 4];
                }
            }
            else
            {
                if ($frame['len']===126) {
                    $frame['payloaddata'] = substr($data, 4, $frame['lenext']);
                    $data = substr($data, $frame['lenext'] + 4);
                } elseif ($frame['len']===127) {
                    $frame['payloaddata'] = substr($data, 10, $frame['lenext']);
                    $data = substr($data, $frame['lenext'] + 10);
                } else {
                    $frame['payloaddata'] = substr($data, 2, $frame['len']);
                    $data = substr($data, $frame['len'] + 2);
                }
            }

            $frame['full'] = false;
            if($frame['len'] < 126 && $frame['len'] == strlen($frame['payloaddata'])) {
                $frame['full'] = true;
            } else if(($frame['len'] == 126 || $frame['len'] == 127) && $frame['lenext'] == strlen($frame['payloaddata'])) {
                $frame['full'] = true;
            }

            $frames[] = $frame;
        } 
        
        return $frames;
    }
}
