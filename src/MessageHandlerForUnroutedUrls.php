<?php
namespace Nymph\PubSub;
use \Devristo\Phpws\Messaging\WebSocketMessageInterface;
use \Devristo\Phpws\Protocol\WebSocketTransportInterface;
use \Devristo\Phpws\Server\UriHandler\WebSocketUriHandler;

/**
 * This class deals with users who are not routed
 */
class MessageHandlerForUnroutedUrls extends WebSocketUriHandler {
	public function onConnect(WebSocketTransportInterface $user){
		//do nothing
		$this->logger->notice("Client doesn't know what he's doing. ({$user->getId()})");
	}
	public function onMessage(WebSocketTransportInterface $user, WebSocketMessageInterface $msg) {
		//do nothing
		$this->logger->notice("Client is talking to the wind. ({$msg->getData()}, {$user->getId()})");
	}
}