<?php

namespace pekand\WebSocketServer;

use pekand\SocketServer\SocketClient;
use pekand\WebSocketServer\WebSocketServerBase;

class WebsocketClient extends WebSocketServerBase {   
    
    private $client = null;
    private $lastFrame = null;
    private $listeners = [];
    private $actions = [];
       
    protected $options = [
    ];
          
    public function __construct($options = []) {
        
        $this->options = array_merge($this->options, $options);
        $this->client = new SocketClient($this->options);
        
        $headerToServer =  $this->getHeader();        
        $this->client->addSendHeader(function($client) use ($headerToServer) {            
            $client->sendData($headerToServer);
        });


        $client = $this;
        $this->client->addReceiveHeader(function($headerFromServer) use ($client) {
            if (isset($client->afterConnect) && is_callable($client->afterConnect)) {
                call_user_func_array($client->afterConnect, [$client]);
            }    
        });

        $this->client->addListener(function($data) {
           
            $frames = [];
            
            try {
                $frames = $this->proccessRequest(null, $data);
            } catch (\Exception $e) {                    
                if (isset($this->afterClientError) && is_callable($this->afterClientError)) {
                    call_user_func_array($this->afterClientError, [$this, null, $e->getMessage()]);
                }
            }
                    
            foreach ($frames as $frame) {
                
                if (!$frame['full']) {
                    $this->lastFrame = $frame;
                    break;                    
                }
                
                $this->lastFrame = null;

                
                
                $request = $frame['payloaddata'];
                    
                $processed = false;
                foreach ($this->listeners as $listener) {
                    if (isset($listener) && is_callable($listener)) {
                        $result = call_user_func_array($listener, [$this, $request]);
                        
                        if($result === true){
                            $processed = true;
                            break;
                        }
                    }
                }   
                
                if(!$processed) {
                    $json = null;
                    if(count($this->actions) > 0) {
                        $json = $this->decodeData($request);
                    }
                    
                    if($json != null && isset($json['action']) && $this->isAction($json['action'])){
                        $this->callAction($json['action'], $json);
                    } 
                }
                    
            }
            
        });
    }

    public function getHeader() {
        $header = "GET / HTTP/1.1
Connection: Upgrade
Pragma: no-cache
Cache-Control: no-cache
User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36
Upgrade: websocket
Sec-WebSocket-Version: 13
Accept-Encoding: gzip, deflate
Accept-Language: en-US,en;q=0.9,sk;q=0.8,und;q=0.7,la;q=0.6,fr;q=0.5
Sec-WebSocket-Key: vQBa+DW32bHjI3m5+Omfxg==
Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits";

        return $header;
    }
    
    public function afterConnect($afterConnect = null) {    
        $this->afterConnect = $afterConnect;
        return $this;
    }
    
    public function sendMessage($message){                 
        return $this->client->sendData($this->mesage($message, 1, true));
    }
    
    public function send($data){                 
        return $this->sendMessage(json_encode($data));
    }

    public function addAction($name, $action) {
         $this->actions[$name] = $action;
    }
    
    public function isAction($name) {
         if(isset($this->actions[$name])) {
           return true;
         }
         
         return false;
    }
    
    public function callAction($name, $data) {
        if (isset($this->actions[$name]) && is_callable($this->actions[$name])) {
            call_user_func_array($this->actions[$name], [$this, $data]);
        }
    }
    
    public function addListener($listener) {
         $this->listeners[] = $listener;
          return $this;
    }
    
   
    public function listen() {        
        $this->client->listen();
        return $this;
    }
    
     public function getSocketClient(){
        return $this->client;
    }
    
    public function decodeData($data) {
        $json = json_decode($data, true);
        
        if(json_last_error() == JSON_ERROR_NONE) {
            return $json;    
        }
        
        return null;
    }
}
