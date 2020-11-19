<?php

set_time_limit(0);

spl_autoload_register(function ($class_name) {
    require_once dirname(__FILE__).DIRECTORY_SEPARATOR.str_replace("\\", "/", $class_name) . '.php';
});

use WebSocketServer\WebSocketServer;

$server = new WebSocketServer([
    'port' => 8080
]);

$server->afterServerError(function($server, $code, $message) {
     echo "SERVER ERROR [$code]: $message\n";
});

$server->afterClientError(function($server, $clientUid, $code, $message) {
    echo "({$clientUid}) [$code]: $message\n";
});

$server->afterShutdown(function($server) {
    echo "SERVER SHUTDOWN\n";
});

$server->clientConnected(function($server, $clientUid) {
    echo "({$clientUid}) CLIENT CONNECTED\n";    
    return true; //accept client
});

$server->clientDisconnected(function($server, $clientUid, $reason) {
    echo "({$clientUid}) CLIENT DISCONNECTED: {$reason}\n";   
});

//build mesasge witch server use to check if client is live
$server->buildPing(function($server, $clientUid) {     
     $server->send($clientUid, ['action'=>'ping']);
});

// catch all messages from server to client
$server->beforeSendMessage(function($server, $clientUid, $message) {          
    echo "MESSAGE TO CLIENT ({$clientUid}): {$message}\n";
});

// listen to all request from clients (request is raw as client it send)
$server->addListener(function($server, $clientUid, $request) {       
    echo "({$clientUid}) MESSAGE FROM CLIENT (LEN:".strlen($request)."): ".$request."\n";    
});

// parse request to json and match coresponding action
$server->addAction('ping', function($server, $clientUid, $data){    
    $server->send($clientUid, ['action'=>'pong']);      
});

$server->listen();
