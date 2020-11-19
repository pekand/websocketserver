<?php

namespace pekand\WebSocketServer;

use pekand\WebSocketServer\WebSocketServerBase;
use pekand\SocketServer\SocketServer;

class WebSocketServer extends WebSocketServerBase {
    
    private $frames = [];
    private $listeners = [];
    private $socketServer = null; 
    private $action = [];
    
    public function __construct($options) {
        $this->socketServer = new SocketServer($options);
    }

    public function afterClientError($afterClientError){ 
        $this->afterClientError = $afterClientError;
        $this->socketServer->afterClientError(function($server, $clientUid, $code, $mesage) { // client cause error
            if (isset($this->afterClientError) && is_callable($this->afterClientError)) {
                call_user_func_array($this->afterClientError, [$this, $clientUid, $code, $mesage]);
            }
        });        
        return $this;
    }
    
    public function afterServerError($afterServerError){ 
        $this->afterServerError = $afterServerError;
        $this->socketServer->afterServerError(function($server, $code, $mesage) { // client cause error
            if (isset($this->afterServerError) && is_callable($this->afterServerError)) {
                call_user_func_array($this->afterServerError, [$this, $code, $mesage]);
            }
        });
        return $this;
    }

    public function afterDataRecived($afterDataRecivedEvent){ 
        $this->afterDataRecivedEvent = $afterDataRecivedEvent;
        return $this;
    }
    
    public function clientConnected($clientConnectedEvent){ 
        $this->clientConnectedEvent = $clientConnectedEvent;
        return $this;
    }
    
    public function clientDisconnected($clientDisconectEvent){ 
        $this->clientDisconectEvent = $clientDisconectEvent;
        return $this;
    }
    
    public function buildPing($buildPing){ 
        $this->buildPing = $buildPing;
        return $this;
    }
    
    public function beforeSendMessage($beforeSendMessage) { 
        $this->beforeSendMessage = $beforeSendMessage;
        return $this;
    }
    
    public function sendMessage($clientUid, $message){ 
        if (isset($this->beforeSendMessage) && is_callable($this->beforeSendMessage)) {
            call_user_func_array($this->beforeSendMessage, [$this, $clientUid, $message]);
        }
                
        return $this->socketServer->sendData($clientUid, $this->mesage($message));
    }
    
     public function send($clientUid, $data){       
        return $this->sendMessage($clientUid, json_encode($data));
    }
    
    public function getClient($clientUid) {      
       return $this->socketServer->getClient($clientUid);
    }
    
    public function isClient($clientUid) {      
       return $this->socketServer->isClient($clientUid);
    }
        
    public function closeClient($clientUid) {

        if (isset($this->frames[$clientUid])) {
            unset($this->frames[$clientUid]);
        }
       
       $this->socketServer->closeClient($clientUid);
    }
    
    public function afterShutdown($afterShutdownEvent = null) {
        $this->afterShutdownEvent = $afterShutdownEvent;
        $this->socketServer->afterShutdown(function($server) { // client cause error            
            if (isset($this->afterShutdownEvent) && is_callable($this->afterShutdownEvent)) {
                 call_user_func_array($this->afterShutdownEvent, [$this]);
            }
        });
        return $this;
    }
    
    public function shutdown() {
       $this->socketServer->shutdown();
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
    
    public function callAction($name, $clientUid, $data) {
        if (isset($this->actions[$name]) && is_callable($this->actions[$name])) {
            call_user_func_array($this->actions[$name], [$this, $clientUid, $data]);
        }
    }
    
    public function decodeData($data) {
        $json = json_decode($data, true);
        
        if(json_last_error() == JSON_ERROR_NONE) {
            return $json;    
        }
        
        return null;
    }
    
    public function addListener($listener) {
         $this->listeners[] = $listener;
         
    }
    
    public function addWorker($params, $workerCallBack) {
        $server = $this;
        $this->socketServer->addWorker($params, function($socketServer) use ($server, $workerCallBack){
            if (is_callable($workerCallBack)) {
                call_user_func_array($workerCallBack, [$server]);
            }
        });
    }
    
    public function listenBody() {
        $this->socketServer        
        ->clientConnected(function ($server, $clientUid, $data) { // client connected
            $headers = $this->createConnectHeader($data);
            
            $this->socketServer->sendData($clientUid, $headers);
            
            $this->frames[$clientUid] = null;
            
            if (isset($this->clientConnectedEvent) && is_callable($this->clientConnectedEvent)) {
               return call_user_func_array($this->clientConnectedEvent, [$this, $clientUid]);
            }
            
            return true;
        })
        ->clientDisconnected(function($server, $clientUid, $reason) { // client disconected
            if (isset($this->clientDisconectEvent) && is_callable($this->clientDisconectEvent)) {
                call_user_func_array($this->clientDisconectEvent, [$this, $clientUid, $reason]);
            }
            
            $this->socketServer->sendData($clientUid, $this->mesage("", 8));
            $this->closeClient($clientUid);
        })
        ->buildPing(function($server, $clientUid) {
            if (isset($this->buildPing) && is_callable($this->buildPing)) {
                $message =  call_user_func_array($this->buildPing, [$this, $clientUid]);
            }
        })
        ->addListener(
            function ($server, $clientUid, $data) { // client send request
                $lastFrame  = null;
                if (isset($this->frames[$clientUid]) && $this->frames[$clientUid] != null) { // ceck for unfinished frame
                    $lastFrame = $this->frames[$clientUid]; 
                    $this->frames[$clientUid] = null;
                }
                
                $frames = [];

                try {
                    $frames = $this->proccessRequest($lastFrame, $data);
                } catch (\Exception $e) {                    
                    if (isset($this->afterClientError) && is_callable($this->afterClientError)) {
                        call_user_func_array($this->afterClientError, [$this, $clientUid, null, $e->getMessage()]);
                    }
                }

                if (isset($this->afterDataRecivedEvent) && is_callable($this->afterDataRecivedEvent)) {
                     call_user_func_array($this->afterDataRecivedEvent, [$this, $clientUid, $data, $frames]);
                }
                    
                foreach ($frames as $frame) {                    
                    $this->frames[$clientUid] = $frame;
                    if ($frame == null) {
                        return;
                    }
                    
                    if (!$frame['full']) {
                        $this->frames[$clientUid] = $frame;
                        break;                    
                    }

                    if ($frame['opcode'] == 8) { // denotes a connection close 
                        if (isset($this->clientDisconectEvent) && is_callable($this->clientDisconectEvent)) {
                            call_user_func_array($this->clientDisconectEvent, [$this, $clientUid, 'CLIENT_CLOSE_CONNECTION']);
                        }
                        
                        $this->closeClient($clientUid);
                        return;
                    }
                    
                    if ($frame['opcode'] == 9) { // denotes a ping
                        $this->socketServer->sendData($clientUid, $this->mesage("", 10));
                        return;
                    }

                    $request = $frame['payloaddata'];
                    
                    $processed = false;
                    foreach ($this->listeners as $listener) {
                        if (isset($listener) && is_callable($listener)) {                            
                            $result = call_user_func_array($listener, [$this, $clientUid, $request]);
                            
                            if($result === true){
                                $processed = true;
                                $this->frames[$clientUid] = null;
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
                            $this->callAction($json['action'], $clientUid, $json);
                        } 
                    }
                }
            }
        );
        
        return $this;
    }
    
    public function listen() {
        $this->listenBody();
        $this->socketServer->listen();
    }
}
