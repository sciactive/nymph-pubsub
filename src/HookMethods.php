<?php
namespace Nymph\PubSub;

class HookMethods {
	public static function setup() {
		\SciActive\H::addCallback('Nymph->saveEntity', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['entity'] = $arguments[0];
			$data['guid'] = $arguments[0]->guid;
		});
		\SciActive\H::addCallback('Nymph->saveEntity', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!$return[0]) {
				return;
			}
			HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => $data['guid'] === $data['entity']->guid ? 'update' : 'create', 'guid' => $data['entity']->guid]));
		});
		\SciActive\H::addCallback('Nymph->deleteEntity', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['guid'] = $arguments[0]->guid;
		});
		\SciActive\H::addCallback('Nymph->deleteEntity', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!$return[0]) {
				return;
			}
			HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'delete', 'guid' => $data['guid']]));
		});
		\SciActive\H::addCallback('Nymph->deleteEntityByID', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['guid'] = $arguments[0];
		});
		\SciActive\H::addCallback('Nymph->deleteEntityByID', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!$return[0]) {
				return;
			}
			HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'delete', 'guid' => $data['guid']]));
		});
		\SciActive\H::addCallback('Nymph->newUID', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['name'] = $arguments[0];
		});
		\SciActive\H::addCallback('Nymph->newUID', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!isset($return[0])) {
				return;
			}
			HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'newUID', 'name' => $data['name']]));
		});
		\SciActive\H::addCallback('Nymph->setUID', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['name'] = $arguments[0];
		});
		\SciActive\H::addCallback('Nymph->setUID', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!$return[0]) {
				return;
			}
			HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'setUID', 'name' => $data['name']]));
		});
		\SciActive\H::addCallback('Nymph->renameUID', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['oldName'] = $arguments[0];
			$data['newName'] = $arguments[1];
		});
		\SciActive\H::addCallback('Nymph->renameUID', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!$return[0]) {
				return;
			}
			HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'renameUID', 'oldName' => $data['oldName'], 'newName' => $data['newName']]));
		});
		\SciActive\H::addCallback('Nymph->deleteUID', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['name'] = $arguments[0];
		});
		\SciActive\H::addCallback('Nymph->deleteUID', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!$return[0]) {
				return;
			}
			HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'deleteUID', 'name' => $data['name']]));
		});
	}

	public static function sendMessage($message) {
		$config = \SciActive\R::_('NymphPubSubConfig');

		$loop = \React\EventLoop\Factory::create();

		$logger = new \Zend\Log\Logger();
		$writer = new \Zend\Log\Writer\Stream("php://stderr");
		$logger->addWriter($writer);

		$client = new \Devristo\Phpws\Client\WebSocket($config->master['value'], $loop, $logger);

		$client->on("connect", function() use ($message, $client){
			$client->send($message);
			$client->close();
		});

		$client->open();
		$loop->run();
	}
}