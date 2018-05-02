<?php namespace Nymph\PubSub;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use WebSocket\Client as TextalkWebSocketClient;

/**
 * Handle subscriptions and publications.
 */
class MessageHandler implements MessageComponentInterface {
  private $logger;
  private $sessions;
  protected $querySubs = [];
  protected $uidSubs = [];

  public function __construct(\Zend\Log\Logger $logger) {
    $this->logger = $logger;
    $this->sessions = new \SplObjectStorage();
  }

  /**
   * Log users who join.
   *
   * @param ConnectionInterface $conn
   */
  public function onOpen(ConnectionInterface $conn) {
    $this->logger->notice("Client joined the party! ({$conn->resourceId})");
  }

  /**
   * Handle a message from a client.
   *
   * @param ConnectionInterface $from
   * @param string $msg
   */
  public function onMessage(ConnectionInterface $from, $msg) {
    $data = json_decode($msg, true);
    if (!$data['action']
        || !in_array(
            $data['action'],
            ['authenticate', 'subscribe', 'unsubscribe', 'publish']
        )) {
      return;
    }
    switch ($data['action']) {
      case 'authenticate':
        // Save the user's auth token in session storage.
        $token = $data['token'];
        if (isset($token)) {
          $this->sessions->attach($from, $token);
        } else {
          $this->sessions->detach($from);
        }
        break;
      case 'subscribe':
      case 'unsubscribe':
        if (isset($data['query'])) {
          // Request is for a query.

          $args = json_decode($data['query'], true);
          $count = count($args);
          if ($count > 1) {
            for ($i = 1; $i < $count; $i++) {
              if (!class_exists($args[0]['class'])) {
                return;
              }
              $newArg =
                \Nymph\REST::translateSelector($args[0]['class'], $args[$i]);
              if ($newArg === false) {
                return;
              }
              $args[$i] = $newArg;
            }
          }
          $serialArgs = serialize($args);
          $this->prepareSelectors($args);

          if ($data['action'] === 'subscribe') {
            // Client is subscribing to a query.
            if (!key_exists($serialArgs, $this->querySubs)) {
              $this->querySubs[$serialArgs] = new \SplObjectStorage();
            }
            $guidArgs = $args;
            $guidArgs[0]['return'] = 'guid';
            $guidArgs[0]['source'] = 'pubsub';
            if ($this->sessions->contains($from)) {
              $guidArgs[0]['token'] = $this->sessions[$from];
            } else {
              $guidArgs[0]['token'] = null;
            }
            $this->querySubs[$serialArgs]->attach($from, [
              'current' => call_user_func_array(
                  "\Nymph\Nymph::getEntities",
                  $guidArgs
              ),
              'query' => $data['query'],
              'count' => !!$data['count']
            ]);
            $this->logger->notice(
                "Client subscribed to a query! " .
                  "($serialArgs, {$from->resourceId})"
            );

            if (Server::$config['broadcast_counts']) {
              // Notify clients of the subscription count.
              $count = count($this->querySubs[$serialArgs]);
              foreach ($this->querySubs[$serialArgs] as $key) {
                $curData = $this->querySubs[$serialArgs][$key];
                if ($curData['count']) {
                  $key->send(json_encode([
                    'query' => $curData['query'],
                    'count' => $count
                  ]));
                }
              }
            }
          }

          if ($data['action'] === 'unsubscribe') {
            // Client is unsubscribing from a query.
            if (!key_exists($serialArgs, $this->querySubs)) {
              return;
            }
            if (!$this->querySubs[$serialArgs]->contains($from)) {
              return;
            }
            $this->querySubs[$serialArgs]->detach($from);
            $this->logger->notice(
                "Client unsubscribed from a query! " .
                  "($serialArgs, {$from->resourceId})"
            );

            $count = count($this->querySubs[$serialArgs]);

            if ($count === 0) {
              // No more subscribed clients.
              unset($this->querySubs[$serialArgs]);
              return;
            }

            if (Server::$config['broadcast_counts']) {
              // Notify clients of the subscription count.
              foreach ($this->querySubs[$serialArgs] as $key) {
                $curData = $this->querySubs[$serialArgs][$key];
                if ($curData['count']) {
                  $key->send(json_encode([
                    'query' => $curData['query'],
                    'count' => $count
                  ]));
                }
              }
            }
          }
        }

        if (isset($data['uid']) && is_string($data['uid'])) {
          // Request is for a UID.

          if ($data['action'] === 'subscribe') {
            // Client is subscribing to a UID.
            if (!key_exists($data['uid'], $this->uidSubs)) {
              $this->uidSubs[$data['uid']] = new \SplObjectStorage();
            }
            $this->uidSubs[$data['uid']]->attach($from, [
              'count' => !!$data['count']
            ]);
            $this->logger->notice(
                "Client subscribed to a UID! " .
                  "({$data['uid']}, {$from->resourceId})"
            );

            if (Server::$config['broadcast_counts']) {
              // Notify clients of the subscription count.
              $count = count($this->uidSubs[$data['uid']]);
              foreach ($this->uidSubs[$data['uid']] as $key) {
                $curData = $this->uidSubs[$data['uid']][$key];
                if ($curData['count']) {
                  $key->send(json_encode([
                    'uid' => $data['uid'],
                    'count' => $count
                  ]));
                }
              }
            }
          }

          if ($data['action'] === 'unsubscribe') {
            // Client is unsubscribing from a UID.
            if (!key_exists($data['uid'], $this->uidSubs)) {
              return;
            }
            if (!$this->uidSubs[$data['uid']]->contains($from)) {
              return;
            }
            $this->uidSubs[$data['uid']]->detach($from);
            $this->logger->notice(
                "Client unsubscribed from a UID! " .
                  "({$data['uid']}, {$from->resourceId})"
            );

            $count = count($this->uidSubs[$data['uid']]);

            if ($count === 0) {
              // No more subscribed clients.
              unset($this->uidSubs[$data['uid']]);
              return;
            }

            if (Server::$config['broadcast_counts']) {
              // Notify clients of the subscription count.
              $count = count($this->uidSubs[$data['uid']]);
              foreach ($this->uidSubs[$data['uid']] as $key) {
                $curData = $this->uidSubs[$data['uid']][$key];
                if ($curData['count']) {
                  $key->send(json_encode([
                    'uid' => $data['uid'],
                    'count' => $count
                  ]));
                }
              }
            }
          }
        }
        break;
      case 'publish':
        if (isset($data['guid'])
            && (
              $data['event'] === 'delete'
              || (
                isset($data['entity'])
                && ($data['event'] === 'create' || $data['event'] === 'update')
              )
            )
          ) {
          // Publish is an entity.
          $this->logger->notice(
              "Received an entity publish! " .
                "({$data['guid']}, {$data['event']}, {$from->resourceId})"
          );
          // Relay the publish to other servers.
          $this->relay($msg);

          foreach ($this->querySubs as $curQuery => $curClients) {
            $updatedClients = new \SplObjectStorage();

            if ($data['event'] === 'delete' || $data['event'] === 'update') {
              // Check if it is in any client's currents.
              foreach ($curClients as $curClient) {
                $curData = $curClients[$curClient];
                if (in_array($data['guid'], $curData['current'])) {
                  // Update currents list.
                  $queryArgs = unserialize($curQuery);
                  $this->prepareSelectors($queryArgs);
                  $queryArgs[0]['return'] = 'guid';
                  $queryArgs[0]['source'] = 'pubsub';
                  if ($this->sessions->contains($curClient)) {
                    $queryArgs[0]['token'] = $this->sessions[$curClient];
                  } else {
                    $queryArgs[0]['token'] = null;
                  }
                  $queryArgs[] = ['&', 'guid' => $data['guid']];
                  $current = call_user_func_array(
                      "\Nymph\Nymph::getEntity",
                      $queryArgs
                  );

                  if (isset($current)) {
                    // Notify subscriber.
                    $this->logger->notice(
                        "Notifying client of update! " .
                          "({$curClient->resourceId})"
                    );
                    $curClient->send(json_encode([
                      'query' => $curData['query'],
                      'updated' => $data['guid']
                    ]));
                  } else {
                    $curData['current'] = array_diff(
                        $curData['current'],
                        [$data['guid']]
                    );
                    $curClients->attach($curClient, $curData);

                    // Notify subscriber.
                    $this->logger->notice(
                        "Notifying client of removal! " .
                          "({$curClient->resourceId})"
                    );
                    $curClient->send(json_encode([
                      'query' => $curData['query'],
                      'removed' => $data['guid']
                    ]));
                  }

                  $updatedClients->attach($curClient);
                }
              }
            }

            if ($data['event'] === 'create' || $data['event'] === 'update') {
              // Check if it matches the query.
              $selectors = unserialize($curQuery);
              $this->prepareSelectors($selectors);
              $options = $selectors[0];
              unset($selectors[0]);
              $entityData = $data['entity']['data'];
              $entityData['cdate'] = $data['entity']['cdate'];
              $entityData['mdate'] = $data['entity']['mdate'];
              $entitySData = [];

              if ($options['class'] === $data['entity']['class']
                  && \Nymph\Nymph::checkData(
                      $entityData,
                      $entitySData,
                      $selectors,
                      $data['guid'],
                      $data['entity']['tags']
                  )
                ) {
                // It does match the query.
                foreach ($curClients as $curClient) {
                  if ($updatedClients->contains($curClient)) {
                    continue;
                  }

                  // Check that the user can access the entity.
                  $queryArgs = unserialize($curQuery);
                  $this->prepareSelectors($queryArgs);
                  $queryArgs[0]['return'] = 'guid';
                  $queryArgs[0]['source'] = 'pubsub';
                  if ($this->sessions->contains($curClient)) {
                    $queryArgs[0]['token'] = $this->sessions[$curClient];
                  } else {
                    $queryArgs[0]['token'] = null;
                  }
                  $queryArgs[] = ['&', 'guid' => $data['guid']];
                  $current = call_user_func_array(
                      "\Nymph\Nymph::getEntity",
                      $queryArgs
                  );
                  if (!isset($current)) {
                    continue;
                  }

                  // Update the currents list.
                  $curData = $curClients[$curClient];
                  $curData['current'][] = $data['guid'];
                  $curClients->attach($curClient, $curData);

                  // Notify client.
                  $this->logger->notice(
                      "Notifying client of new match! " .
                        "({$curClient->resourceId})"
                  );
                  $curClient->send(json_encode([
                    'query' => $curData['query'],
                    'added' => $data['guid']
                  ]));
                }
              }
            }
          }
        }

        if ((
              isset($data['name'])
              || (isset($data['oldName']) && isset($data['newName']))
            )
            && in_array(
                $data['event'],
                ['newUID', 'setUID', 'renameUID', 'deleteUID']
            )
          ) {
          // Publish is a UID.
          $this->logger->notice(
              "Received a UID publish! (" .
                (
                  isset($data['name'])
                      ? $data['name']
                      : "{$data['oldName']} => {$data['newName']}"
                ) .
                ", {$data['event']}, {$from->resourceId})"
          );
          // Relay the publish to other servers.
          $this->relay($msg);

          foreach ($data as $key => $name) {
            if (!in_array($key, ['name', 'newName', 'oldName'])
                || !key_exists($name, $this->uidSubs)) {
              continue;
            }
            foreach ($this->uidSubs[$name] as $curClient) {
              $this->logger->notice(
                  "Notifying client of {$data['event']}! " .
                    "($name, {$curClient->resourceId})"
              );
              $curClient->send(json_encode([
                'uid' => $name
              ]));
            }
          }
        }
        break;
    }
  }

  /**
   * Clean up after users who leave.
   *
   * @param ConnectionInterface $conn
   */
  public function onClose(ConnectionInterface $conn) {
    $this->logger->notice("Client skedaddled. ({$conn->resourceId})");

    $mess = 0;
    foreach ($this->querySubs as $curQuery => $curClients) {
      if ($curClients->contains($conn)) {
        $curClients->detach($conn);

        $count = count($curClients);

        if ($count === 0) {
          unset($this->querySubs[$curQuery]);
        } else {
          if (Server::$config['broadcast_counts']) {
            // Notify clients of the subscription count.
            foreach ($curClients as $key) {
              $curData = $curClients[$key];
              if ($curData['count']) {
                $key->send(json_encode([
                  'query' => $curData['query'],
                  'count' => $count
                ]));
              }
            }
          }
        }
        $mess++;
      }
    }

    foreach ($this->uidSubs as $curUID => $curClients) {
      if ($curClients->contains($conn)) {
        $curClients->detach($conn);

        $count = count($curClients);

        if ($count === 0) {
          unset($this->uidSubs[$curUID]);
        } else {
          if (Server::$config['broadcast_counts']) {
            // Notify clients of the subscription count.
            $count = count($curClients);
            foreach ($curClients as $key) {
              $curData = $curClients[$key];
              if ($curData['count']) {
                $key->send(json_encode([
                  'uid' => $curUID,
                  'count' => $count
                ]));
              }
            }
          }
        }
        $mess++;
      }
    }

    if ($mess) {
      $this->logger->notice(
          "Cleaned up client's mess. " .
            "($mess, {$conn->resourceId})"
      );
    }
  }

  public function onError(ConnectionInterface $conn, \Exception $e) {
    $this->logger->err("An error occured. ({$e->getMessage()})");
  }

  /**
   * Relay publish data to other servers.
   *
   * @param string $message The publish data to relay.
   */
  private function relay($message) {
    if (!Server::$config['relays']) {
      return;
    }

    foreach (Server::$config['relays'] as $host) {
      $client = new TextalkWebSocketClient($host);
      $client->send($message);
    }
  }

  private function prepareSelectors(&$selectors) {
    if (count($selectors) > 1) {
      $options = $selectors[0];
      unset($selectors[0]);
      // formatSelectors will modify relative time clauses, so this needs to be
      // done when testing entities/making queries, etc, but not saved as the
      // query the user is subscribed to.
      \Nymph\Nymph::formatSelectors($selectors);
      $selectors = array_merge([$options], $selectors);
    }
  }
}
