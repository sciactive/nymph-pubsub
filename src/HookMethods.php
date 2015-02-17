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
			$client = HookMethods::getClient();
			$client->sendData(json_encode(['action' => 'publish', 'event' => $data['guid'] === $data['entity']->guid ? 'update' : 'create', 'guid' => $data['entity']->guid]));
			$client->disconnect();
		});
		\SciActive\H::addCallback('Nymph->deleteEntity', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['guid'] = $arguments[0]->guid;
		});
		\SciActive\H::addCallback('Nymph->deleteEntity', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!$return[0]) {
				return;
			}
			$client = HookMethods::getClient();
			$client->sendData(json_encode(['action' => 'publish', 'event' => 'delete', 'guid' => $data['guid']]));
			$client->disconnect();
		});
		\SciActive\H::addCallback('Nymph->deleteEntityByID', -10, function(&$arguments, $name, &$object, &$function, &$data){
			$data['guid'] = $arguments[0];
		});
		\SciActive\H::addCallback('Nymph->deleteEntityByID', 10, function(&$return, $name, &$object, &$function, &$data){
			if (!$return[0]) {
				return;
			}
			$client = HookMethods::getClient();
			$client->sendData(json_encode(['action' => 'publish', 'event' => 'delete', 'guid' => $data['guid']]));
			$client->disconnect();
		});
	}

	public static function getClient() {
		$config = \SciActive\R::_('NymphPubSubConfig');
		$master = parse_url($config->master['value']);

		$client = new WebSocketClient();
		if (!$client->connect($master['host'], $master['port'], $master['path'])) {
			throw new Exception('Can\'t connect to master Nymph-PubSub server.');
		}
		return $client;
	}
}