<?php
namespace Nymph\PubSub;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Server implements MessageComponentInterface {
	protected $clients;
	protected $subscriptions = [
		'queries' => [],
		'uids' => []
	];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

	public function onOpen(ConnectionInterface $conn) {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);

        echo "New connection! ({$conn->resourceId})\n";
	}

	public function onMessage(ConnectionInterface $from, $msg) {
		$data = json_decode($msg, true);
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
						$this->subscriptions['queries'][$serialArgs][] = ['client' => $from, 'query' => $data['query']];
					} elseif ($data['action'] === 'unsubscribe') {
						if (!key_exists($serialArgs, $this->subscriptions['queries'])) {
							return;
						}
						foreach ($this->subscriptions['queries'][$serialArgs] as $key => $value) {
							if ($from === $value['client'] && $data['query'] === $value['query']) {
								unset($this->subscriptions['queries'][$serialArgs][$key]);
							}
						}
					}
				} elseif (isset($data['uid']) && is_string($data['uid'])) {
					if ($data['action'] === 'subscribe') {
						if (!key_exists($data['uid'], $this->subscriptions['uids'])) {
							$this->subscriptions['uids'][$data['uid']] = [];
						}
						$this->subscriptions['uids'][$data['uid']][] = $from;
					} elseif ($data['action'] === 'unsubscribe') {
						if (!key_exists($data['uid'], $this->subscriptions['uids'])) {
							return;
						}
						foreach ($this->subscriptions['uids'][$data['uid']] as $key => $value) {
							if ($from === $value) {
								unset($this->subscriptions['uids'][$data['uid']][$key]);
							}
						}
					}
				}
				break;
			case 'publish':
				if (isset($data['guid']) && in_array($data['event'], ['create', 'update', 'delete'])) {
					foreach ($this->subscriptions['queries'] as $curQuery => $curClients) {
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
									$curClient['client']->send(json_encode(['query' => $curClient['query']]));
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
							$selectors = array_merge([$options, ['&', 'guid' => $data['guid']]], $selectors);
							$entityTest = call_user_func_array("\Nymph\Nymph::getEntity", $selectors);
							if (isset($entityTest) && $entityTest->guid) {
								// Update currents list.
								$oldCurrents = $curClients['current'];
								$guidArgs = unserialize($curQuery);
								$guidArgs[0]['return'] = 'guid';
								$curClients['current'] = call_user_func_array("\Nymph\Nymph::getEntities", $guidArgs);
								if ($oldCurrents !== $curClients['current']) {
									// Notify subscribers.
									foreach ($curClients as $key => $curClient) {
										if ($key === 'current') {
											continue;
										}
										$curClient['client']->send(json_encode(['query' => $curClient['query']]));
									}
								}
							}
						}
					}
				}
				break;
		}

        foreach ($this->clients as $client) {
            if ($from !== $client) {
                // The sender is not the receiver, send to each client connected
                $client->send($msg);
            }
        }
	}

	public function onClose(ConnectionInterface $conn) {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} has disconnected\n";
	}

	public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
	}
}