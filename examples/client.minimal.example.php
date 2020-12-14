<?php

set_time_limit(0);

require "../../socketserver/src/SocketClient.php";
require "../../socketserver/src/SocketPool.php";
require "../src/WebSocketServerBase.php";
require "../src/WebSocketClient.php";
require "../src/WebsocketPool.php";

use pekand\WebSocketServer\WebSocketClient;
use pekand\WebSocketServer\WebsocketPool;

echo "Client\n";

$client = new WebSocketClient([
]);

$client->afterConnect(function ($client) {
    echo "After connect\n";
    $client->send(['action'=>'action1']);
    return true; // accept server connection
});

$client->addAction('ping', function ($client, $data) {   
    $client->send(['action'=>'pong']);          
});

$client->addAction('pong', function ($client, $data) {   
    echo "server response with pong\n";
});

$client->addAction('action2', function ($client, $data) {   
    echo "server response with action2\n";
    var_dump($data);
});

$pool = new WebSocketPool();

$pool->addAction(['delay'=>1000000, 'repeat'=>1000000], function(){
    echo "delay\n";
});

$pool->listen([
    $client
]);
