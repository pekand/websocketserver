<?php

namespace pekand\WebSocketServer;

use pekand\SocketServer\SocketPool;

class WebSocketPool {   
    private $socketPool = null;
    
    public function __construct() {
    	$this->socketPool = new SocketPool();
    }
         
    public function addAction($params, $action) {
    	$this->socketPool->addAction($params, $action);
    }   
    
	public function listen($clients) {
		
        $socketClients = [];
        foreach ($clients as $client) {
            $socketClients[] = $client->getSocketClient();
        }
        
        $this->socketPool->listen($socketClients);
    }
}
