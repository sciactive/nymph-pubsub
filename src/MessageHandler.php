<?php
namespace Nymph\PubSub;
use \Devristo\Phpws\Messaging\WebSocketMessageInterface;
use \Devristo\Phpws\Protocol\WebSocketTransportInterface;
use \Devristo\Phpws\Server\UriHandler\WebSocketUriHandler;
use \SciActive\RequirePHP as RequirePHP;

/**
 * Handle subscriptions and publications.
 */
class MessageHandler extends WebSocketUriHandler {
	protected $subscriptions = [
		'queries' => [],
		'uids' => []
	];

	/**
	 * Log users who join.
	 *
	 * @param WebSocketTransportInterface $user
	 */
	public function onConnect(WebSocketTransportInterface $user){
        $this->logger->notice("Client joined the party! ({$user->getId()})");
	}

	/**
	 * Handle a message from a client.
	 *
	 * @param WebSocketTransportInterface $user
	 * @param WebSocketMessageInterface $msg
	 */
	public function onMessage(WebSocketTransportInterface $user, WebSocketMessageInterface $msg) {
		$data = json_decode($msg->getData(), true);
		if (!$data['action'] || !in_array($data['action'], ['subscribe', 'unsubscribe', 'publish'])) {
			return;
		}
		switch ($data['action']) {
			case 'subscribe':
			case 'unsubscribe':
				if (isset($data['query'])) {
					$args = json_decode($data['query'], true);
					$count = count($args);
					if ($count > 1) {
						for ($i = 1; $i < $count; $i++) {
							$newArg = \Nymph\REST::translateSelector($args[$i]);
							if ($newArg === false) {
								return;
							}
							$args[$i] = $newArg;
						}
					}
					if (count($args) > 1) {
						$options = $args[0];
						unset($args[0]);
						\Nymph\Nymph::formatSelectors($args);
						$args = array_merge([$options], $args);
					}
					$args[0]['skip_ac'] = true;
					$serialArgs = serialize($args);
					if ($data['action'] === 'subscribe') {
						if (!key_exists($serialArgs, $this->subscriptions['queries'])) {
							$guidArgs = $args;
							$guidArgs[0]['return'] = 'guid';
							$this->subscriptions['queries'][$serialArgs] = [
								'current' => call_user_func_array("\Nymph\Nymph::getEntities", $guidArgs)
							];
						}
						$this->subscriptions['queries'][$serialArgs][] = ['client' => $user, 'query' => $data['query'], 'count' => !!$data['count']];
						$this->logger->notice("Client subscribed to a query! ($serialArgs, {$user->getId()})");
						if (RequirePHP::_('NymphPubSubConfig')->broadcast_counts['value']) {
							// Notify clients of the subscription count.
							$count = count($this->subscriptions['queries'][$serialArgs]) - 1;
							foreach ($this->subscriptions['queries'][$serialArgs] as $key => $curClient) {
								if ($key === 'current') {
									continue;
								}
								if ($curClient['count']) {
									$curClient['client']->sendString(json_encode(['query' => $curClient['query'], 'count' => $count]));
								}
							}
						}
					} elseif ($data['action'] === 'unsubscribe') {
						if (!key_exists($serialArgs, $this->subscriptions['queries'])) {
							return;
						}
						foreach ($this->subscriptions['queries'][$serialArgs] as $key => $value) {
							if ($key === 'current') {
								continue;
							}
							if ($user->getId() === $value['client']->getId() && $data['query'] === $value['query']) {
								unset($this->subscriptions['queries'][$serialArgs][$key]);
								$this->logger->notice("Client unsubscribed from a query! ($serialArgs, {$user->getId()})");
								if (RequirePHP::_('NymphPubSubConfig')->broadcast_counts['value']) {
									// Notify clients of the subscription count.
									$count = count($this->subscriptions['queries'][$serialArgs]) - 1;
									foreach ($this->subscriptions['queries'][$serialArgs] as $key => $curClient) {
										if ($key === 'current') {
											continue;
										}
										if ($curClient['count']) {
											$curClient['client']->sendString(json_encode(['query' => $curClient['query'], 'count' => $count]));
										}
									}
								}
								if (count($this->subscriptions['queries'][$serialArgs]) === 1) {
									unset($this->subscriptions['queries'][$serialArgs]);
								}
							}
						}
					}
				} elseif (isset($data['uid']) && is_string($data['uid'])) {
					if ($data['action'] === 'subscribe') {
						if (!key_exists($data['uid'], $this->subscriptions['uids'])) {
							$this->subscriptions['uids'][$data['uid']] = [];
						}
						$this->subscriptions['uids'][$data['uid']][] = ['client' => $user, 'count' => !!$data['count']];
						$this->logger->notice("Client subscribed to a UID! ({$data['uid']}, {$user->getId()})");
						if (RequirePHP::_('NymphPubSubConfig')->broadcast_counts['value']) {
							// Notify clients of the subscription count.
							$count = count($this->subscriptions['uids'][$data['uid']]);
							foreach ($this->subscriptions['uids'][$data['uid']] as $curClient) {
								if ($curClient['count']) {
									$curClient['client']->sendString(json_encode(['uid' => $data['uid'], 'count' => $count]));
								}
							}
						}
					} elseif ($data['action'] === 'unsubscribe') {
						if (!key_exists($data['uid'], $this->subscriptions['uids'])) {
							return;
						}
						foreach ($this->subscriptions['uids'][$data['uid']] as $key => $value) {
							if ($user->getId() === $value['client']->getId()) {
								unset($this->subscriptions['uids'][$data['uid']][$key]);
								$this->logger->notice("Client unsubscribed from a UID! ({$data['uid']}, {$user->getId()})");
								if (RequirePHP::_('NymphPubSubConfig')->broadcast_counts['value']) {
									// Notify clients of the subscription count.
									$count = count($this->subscriptions['uids'][$data['uid']]);
									foreach ($this->subscriptions['uids'][$data['uid']] as $curClient) {
										if ($curClient['count']) {
											$curClient['client']->sendString(json_encode(['uid' => $data['uid'], 'count' => $count]));
										}
									}
								}
								break;
							}
						}
						if (count($this->subscriptions['uids'][$data['uid']]) === 0) {
							unset($this->subscriptions['uids'][$data['uid']]);
						}
					}
				}
				break;
			case 'publish':
				if (
						isset($data['guid']) &&
						(
							$data['event'] === 'delete' ||
							(
								isset($data['entity']) &&
								($data['event'] === 'create' || $data['event'] === 'update')
							)
						)
				) {
					$this->logger->notice("Received an entity publish! ({$data['guid']}, {$data['event']}, {$user->getId()})");
					// Relay the publish to other servers.
					$this->relay($msg->getData());
					foreach ($this->subscriptions['queries'] as $curQuery => &$curClients) {
						if ($data['event'] === 'delete' || $data['event'] === 'update') {
							// Check if it is in any queries' currents.
							if (in_array($data['guid'], $curClients['current'])) {
								// Update currents list.
								$guidArgs = unserialize($curQuery);
								$guidArgs[0]['return'] = 'guid';
								$curClients['current'] = call_user_func_array("\Nymph\Nymph::getEntities", $guidArgs);
								// Notify subscribers.
								foreach ($curClients as $key => $curClient) {
									if ($key === 'current') {
										continue;
									}
									$this->logger->notice("Notifying client of modification! ({$curClient['client']->getId()})");
									$curClient['client']->sendString(json_encode(['query' => $curClient['query']]));
								}
								continue;
							}
						}
						// It isn't in the current matching entities.
						if ($data['event'] === 'create' || $data['event'] === 'update') {
							// Check if it matches any queries.
							$selectors = unserialize($curQuery);
							$options = $selectors[0];
							unset($selectors[0]);
							$entityData = $data['entity']['data'];
							$entityData['cdate'] = $data['entity']['cdate'];
							$entityData['mdate'] = $data['entity']['mdate'];
							$entitySData = [];

							if ($options['class'] === $data['entity']['class'] && \Nymph\Nymph::checkData($entityData, $entitySData, $selectors, $data['guid'], $data['entity']['tags'])) {
								// Update currents list.
								$guidArgs = unserialize($curQuery);
								$guidArgs[0]['return'] = 'guid';
								$curClients['current'] = call_user_func_array("\Nymph\Nymph::getEntities", $guidArgs);
								// If we're here, it means the query didn't
								// match the entity before, and now it does. We
								// could check currents to see if it's been
								// removed by limits, but that may give us bad
								// data, since the client queries are filtered
								// by Tilmeld.
								// Notify subscribers.
								foreach ($curClients as $key => $curClient) {
									if ($key === 'current') {
										continue;
									}
									$this->logger->notice("Notifying client of new match! ({$curClient['client']->getId()})");
									$curClient['client']->sendString(json_encode(['query' => $curClient['query']]));
								}
							}
						}
					}
					unset($curClients);
				} elseif ((isset($data['name']) || (isset($data['oldName']) && isset($data['newName']))) && in_array($data['event'], ['newUID', 'setUID', 'renameUID', 'deleteUID'])) {
					$this->logger->notice("Received a UID publish! (".(isset($data['name']) ? $data['name'] : "{$data['oldName']} => {$data['newName']}").", {$data['event']}, {$user->getId()})");
					// Relay the publish to other servers.
					$this->relay($msg->getData());
					foreach ($data as $key => $name) {
						if (!in_array($key, ['name', 'newName', 'oldName']) || !key_exists($name, $this->subscriptions['uids'])) {
							continue;
						}
						foreach ($this->subscriptions['uids'][$name] as $curClient) {
							$this->logger->notice("Notifying client of {$data['event']}! ($name, {$curClient['client']->getId()})");
							$curClient['client']->sendString(json_encode(['uid' => $name]));
						}
					}
				}
				break;
		}
	}

	/**
	 * Clean up after users who leave.
	 *
	 * @param WebSocketTransportInterface $user
	 */
	public function onDisconnect(WebSocketTransportInterface $user){
        $this->logger->notice("Client skedaddled. ({$user->getId()})");

		$mess = 0;
		foreach ($this->subscriptions['queries'] as $curQuery => &$curClients) {
			foreach ($curClients as $key => $curClient) {
				if ($key === 'current') {
					continue;
				}
				if ($user->getId() === $curClient['client']->getId()) {
					unset($curClients[$key]);
					if (RequirePHP::_('NymphPubSubConfig')->broadcast_counts['value']) {
						// Notify clients of the subscription count.
						$count = count($curClients) - 1;
						foreach ($curClients as $key => $curCountClient) {
							if ($key === 'current') {
								continue;
							}
							if ($curCountClient['count']) {
								$curCountClient['client']->sendString(json_encode(['query' => $curCountClient['query'], 'count' => $count]));
							}
						}
					}
					if (count($curClients) === 1) {
						unset($this->subscriptions['queries'][$curQuery]);
					}
					$mess++;
				}
			}
		}
		unset($curClients);
		foreach ($this->subscriptions['uids'] as $curUID => &$curClients) {
			foreach ($curClients as $key => $curClient) {
				if ($user->getId() === $curClient['client']->getId()) {
					unset($curClients[$key]);
					if (RequirePHP::_('NymphPubSubConfig')->broadcast_counts['value']) {
						// Notify clients of the subscription count.
						$count = count($curClients);
						foreach ($curClients as $curCountClient) {
							if ($curCountClient['count']) {
								$curCountClient['client']->sendString(json_encode(['uid' => $curUID, 'count' => $count]));
							}
						}
					}
					if (count($curClients) === 0) {
						unset($this->subscriptions['uids'][$curUID]);
					}
					$mess++;
				}
			}
		}
		unset($curClients);

		if ($mess) {
			$this->logger->notice("Cleaned up client's mess. ($mess, {$user->getId()})");
		}
	}

	/**
	 * Relay publish data to other servers.
	 *
	 * @param string $message The publish data to relay.
	 */
	private function relay($message) {
		$config = \SciActive\RequirePHP::_('NymphPubSubConfig');

		if (!$config->relays['value']) {
			return;
		}

		$loop = \React\EventLoop\Factory::create();

		$logger = new \Zend\Log\Logger();
		$writer = new \Zend\Log\Writer\Stream("php://stderr");
		$logger->addWriter($writer);

		foreach ($config->relays['value'] as $host) {
			$client = new \Devristo\Phpws\Client\WebSocket($host, $loop, $logger);

			$client->on("connect", function() use ($message, $client){
				$client->send($message);
				$client->close();
			});

			$client->open();
		}

		$loop->run();
	}
}