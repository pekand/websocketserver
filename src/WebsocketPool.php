<?php

namespace pekand\WebSocketServer;

use pekand\SocketServer\SocketPool;

class WebSocketPool {  
    private $listening = false;

    private $clients = [];
    private $socketClients = [];

    private $socketPool = null;
    
    public function __construct() {
    	$this->socketPool = new SocketPool();
    }
         
    public function addAction($params, $action) {
    	$this->socketPool->addAction($params, $action);
    }   

    public function addClient($client) {
        $this->clients[] = $client;
        $socketClient = $client->getSocketClient();
        $this->socketClients[] = $socketClient;

        if($this->listening) {
            $this->socketPool->addClient($socketClient);
            $socketClient->connect();
            if($socketClient->isConnected()) {
                $socketClient->callAfterClientConnected();
            }
        }
    }
    
	public function listen($clients) {
		
        $this->clients = array_merge($this->clients, $clients);

        $this->socketClients = [];
        foreach ($clients as $client) {
            $this->socketClients[] = $client->getSocketClient();
        }

        $this->listening = true;
        
        $this->socketPool->listen($this->socketClients);
    }
}
