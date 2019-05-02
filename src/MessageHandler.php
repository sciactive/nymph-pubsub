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
        $this->handleAuthentication($from, $data);
        break;
      case 'subscribe':
      case 'unsubscribe':
        $this->handleSubscription($from, $data);
        break;
      case 'publish':
        $this->handlePublish($from, $msg, $data);
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
    foreach ($this->querySubs as $curEtype => &$curSubscriptions) {
      foreach ($curSubscriptions as $curQuery => &$curClients) {
        if ($curClients->contains($conn)) {
          unset($curClients[$conn]);

          $count = count($curClients);

          if ($count === 0) {
            unset($curSubscriptions[$curQuery]);
            if (count($this->querySubs[$curEtype]) === 0) {
              unset($this->querySubs[$curEtype]);
            }
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
      unset($curClients);
    }
    unset($curSubscriptions);

    foreach ($this->uidSubs as $curUID => $curClients) {
      if ($curClients->contains($conn)) {
        unset($curClients[$conn]);

        $count = count($curClients);

        if ($count === 0) {
          unset($this->uidSubs[$curUID]);
        } else {
          if (Server::$config['broadcast_counts']) {
            // Notify clients of the subscription count.
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

    if ($this->sessions->contains($conn)) {
      unset($this->sessions[$conn]);
      $mess++;
    }

    if ($mess) {
      $this->logger->notice(
          "Cleaned up client's mess. ".
            "($mess, {$conn->resourceId})"
      );
    }
  }

  public function onError(ConnectionInterface $conn, \Exception $e) {
    $this->logger->err("An error occured. ({$e->getMessage()})");
  }

  /**
   * Handle an authentication from a client.
   *
   * @param ConnectionInterface $from
   * @param array $data
   */
  private function handleAuthentication(ConnectionInterface $from, $data) {
    // Save the user's auth token in session storage.
    $token = $data['token'];
    if (isset($token)) {
      $this->sessions[$from] = $token;
    } elseif ($this->sessions->contains($from)) {
      unset($this->sessions[$from]);
    }
  }

  /**
   * Handle a subscribe or unsubscribe from a client.
   *
   * @param ConnectionInterface $from
   * @param array $data
   */
  private function handleSubscription(ConnectionInterface $from, $data) {
    if (isset($data['query'])) {
      // Request is for a query.

      $this->handleSubscriptionQuery($from, $data);
    } elseif (isset($data['uid']) && is_string($data['uid'])) {
      // Request is for a UID.

      $this->handleSubscriptionUid($from, $data);
    }
  }

  /**
   * Handle a subscribe or unsubscribe for a query from a client.
   *
   * @param ConnectionInterface $from
   * @param array $data
   */
  private function handleSubscriptionQuery(ConnectionInterface $from, $data) {
    $args = json_decode($data['query'], true);
    if (!class_exists($args[0]['class'])) {
      return;
    }
    $count = count($args);
    if ($count > 1) {
      for ($i = 1; $i < $count; $i++) {
        $newArg =
          \Nymph\REST::translateSelector($args[0]['class'], $args[$i]);
        if ($newArg === false) {
          return;
        }
        $args[$i] = $newArg;
      }
    }
    $etype = $args[0]['class']::ETYPE;
    $serialArgs = serialize($args);
    $this->prepareSelectors($args);

    if ($data['action'] === 'subscribe') {
      // Client is subscribing to a query.
      if (!key_exists($etype, $this->querySubs)) {
        $this->querySubs[$etype] = [];
      }
      if (!key_exists($serialArgs, $this->querySubs[$etype])) {
        $this->querySubs[$etype][$serialArgs] = new \SplObjectStorage();
      }
      $guidArgs = $args;
      $guidArgs[0]['return'] = 'guid';
      $guidArgs[0]['source'] = 'client';
      $token = null;
      if ($this->sessions->contains($from)) {
        $token = $this->sessions[$from];
      }
      if (class_exists('\Tilmeld\Tilmeld') && isset($token)) {
        $user = \Tilmeld\Tilmeld::extractToken($token);
        if ($user) {
          // Log in the user for access controls.
          \Tilmeld\Tilmeld::fillSession($user);
        }
      }
      $this->querySubs[$etype][$serialArgs][$from] = [
        'current' => call_user_func_array(
            "\Nymph\Nymph::getEntities",
            $guidArgs
        ),
        'query' => $data['query'],
        'count' => !!$data['count']
      ];
      if (class_exists('\Tilmeld\Tilmeld') && isset($token)) {
        // Clear the user that was temporarily logged in.
        \Tilmeld\Tilmeld::clearSession();
      }
      $this->logger->notice(
          "Client subscribed to a query! ".
            "($serialArgs, {$from->resourceId})"
      );

      if (Server::$config['broadcast_counts']) {
        // Notify clients of the subscription count.
        $count = count($this->querySubs[$etype][$serialArgs]);
        foreach ($this->querySubs[$etype][$serialArgs] as $key) {
          $curData = $this->querySubs[$etype][$serialArgs][$key];
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
      if (!key_exists($etype, $this->querySubs)) {
        return;
      }
      if (!key_exists($serialArgs, $this->querySubs[$etype])) {
        return;
      }
      if (!$this->querySubs[$etype][$serialArgs]->contains($from)) {
        return;
      }
      unset($this->querySubs[$etype][$serialArgs][$from]);
      $this->logger->notice(
          "Client unsubscribed from a query! ".
            "($serialArgs, {$from->resourceId})"
      );

      $count = count($this->querySubs[$etype][$serialArgs]);

      if ($count === 0) {
        // No more subscribed clients.
        unset($this->querySubs[$etype][$serialArgs]);
        if (count($this->querySubs[$etype]) === 0) {
          unset($this->querySubs[$etype]);
        }
        return;
      }

      if (Server::$config['broadcast_counts']) {
        // Notify clients of the subscription count.
        foreach ($this->querySubs[$etype][$serialArgs] as $key) {
          $curData = $this->querySubs[$etype][$serialArgs][$key];
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

  /**
   * Handle a subscribe or unsubscribe for a UID from a client.
   *
   * @param ConnectionInterface $from
   * @param array $data
   */
  private function handleSubscriptionUid(ConnectionInterface $from, $data) {
    if ($data['action'] === 'subscribe') {
      // Client is subscribing to a UID.
      if (!key_exists($data['uid'], $this->uidSubs)) {
        $this->uidSubs[$data['uid']] = new \SplObjectStorage();
      }
      $this->uidSubs[$data['uid']][$from] = [
        'count' => !!$data['count']
      ];
      $this->logger->notice(
          "Client subscribed to a UID! ".
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
      unset($this->uidSubs[$data['uid']][$from]);
      $this->logger->notice(
          "Client unsubscribed from a UID! ".
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

  /**
   * Handle a publish from a client.
   *
   * @param ConnectionInterface $from
   * @param string $msg
   * @param array $data
   */
  private function handlePublish(ConnectionInterface $from, $msg, $data) {
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

      // Relay the publish to other servers.
      $this->relay($msg);

      $this->handlePublishEntity($from, $data);
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

      // Relay the publish to other servers.
      $this->relay($msg);

      $this->handlePublishUid($from, $data);
    }
  }

  /**
   * Handle an entity publish from a client.
   *
   * @param ConnectionInterface $from
   * @param array $data
   */
  private function handlePublishEntity(ConnectionInterface $from, $data) {
    $this->logger->notice(
        "Received an entity publish! ".
          "({$data['guid']}, {$data['event']}, {$from->resourceId})"
    );

    $etype = $data['etype'];

    if (!key_exists($etype, $this->querySubs)) {
      return;
    }

    foreach ($this->querySubs[$etype] as $curQuery => $curClients) {
      $updatedClients = new \SplObjectStorage();

      if ($data['event'] === 'delete' || $data['event'] === 'update') {
        // Check if it is in any client's currents.
        foreach ($curClients as $curClient) {
          $curData = $curClients[$curClient];
          if (in_array($data['guid'], $curData['current'])) {
            // Update currents list.
            $queryArgs = unserialize($curQuery);
            $this->prepareSelectors($queryArgs);
            $queryArgs[0]['source'] = 'client';
            $queryArgs[] = ['&', 'guid' => $data['guid']];
            $token = null;
            if ($this->sessions->contains($curClient)) {
              $token = $this->sessions[$curClient];
            }
            if (class_exists('\Tilmeld\Tilmeld') && isset($token)) {
              $user = \Tilmeld\Tilmeld::extractToken($token);
              if ($user) {
                // Log in the user for access controls.
                \Tilmeld\Tilmeld::fillSession($user);
              }
            }
            $current = call_user_func_array(
                "\Nymph\Nymph::getEntity",
                $queryArgs
            );

            if (isset($current)) {
              // Notify subscriber.
              $this->logger->notice(
                  "Notifying client of update! ".
                    "({$curClient->resourceId})"
              );
              if (is_callable([$current, 'updateDataProtection'])) {
                $current->updateDataProtection();
              }
              $curClient->send(json_encode([
                'query' => $curData['query'],
                'updated' => $data['guid'],
                'data' => $current
              ]));
            } else {
              $curData['current'] = array_diff(
                  $curData['current'],
                  [$data['guid']]
              );
              $curClients[$curClient] = $curData;

              // Notify subscriber.
              $this->logger->notice(
                  "Notifying client of removal! ".
                    "({$curClient->resourceId})"
              );
              $curClient->send(json_encode([
                'query' => $curData['query'],
                'removed' => $data['guid']
              ]));
            }

            if (class_exists('\Tilmeld\Tilmeld') && isset($token)) {
              // Clear the user that was temporarily logged in.
              \Tilmeld\Tilmeld::clearSession();
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

        if (class_exists($options['class'])
            && class_exists($data['entity']['class'])
            && $options['class']::ETYPE === $data['entity']['class']::ETYPE
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
              // The user was already notified. (Of an update.)
              continue;
            }

            // Check that the user can access the entity.
            $queryArgs = unserialize($curQuery);
            $this->prepareSelectors($queryArgs);
            $queryArgs[0]['source'] = 'client';
            $queryArgs[] = ['&', 'guid' => $data['guid']];
            $token = null;
            if ($this->sessions->contains($curClient)) {
              $token = $this->sessions[$curClient];
            }
            if (class_exists('\Tilmeld\Tilmeld') && isset($token)) {
              $user = \Tilmeld\Tilmeld::extractToken($token);
              if ($user) {
                // Log in the user for access controls.
                \Tilmeld\Tilmeld::fillSession($user);
              }
            }
            $current = call_user_func_array(
                "\Nymph\Nymph::getEntity",
                $queryArgs
            );

            if (isset($current)) {
              // Update the currents list.
              $curData = $curClients[$curClient];
              $curData['current'][] = $data['guid'];
              $curClients[$curClient] = $curData;

              // Notify client.
              $this->logger->notice(
                  "Notifying client of new match! ".
                    "({$curClient->resourceId})"
              );
              if (is_callable([$current, 'updateDataProtection'])) {
                $current->updateDataProtection();
              }
              $curClient->send(json_encode([
                'query' => $curData['query'],
                'added' => $data['guid'],
                'data' => $current
              ]));
            }

            if (class_exists('\Tilmeld\Tilmeld') && isset($token)) {
              // Clear the user that was temporarily logged in.
              \Tilmeld\Tilmeld::clearSession();
            }
          }
        }
      }
    }
  }

  /**
   * Handle a UID publish from a client.
   *
   * @param ConnectionInterface $from
   * @param array $data
   */
  private function handlePublishUid(ConnectionInterface $from, $data) {
    $this->logger->notice(
        "Received a UID publish! (".
          (
            isset($data['name'])
                ? $data['name']
                : "{$data['oldName']} => {$data['newName']}"
          ).
          ", {$data['event']}, {$from->resourceId})"
    );

    foreach ($data as $key => $name) {
      if (!in_array($key, ['name', 'newName', 'oldName'])
          || !key_exists($name, $this->uidSubs)) {
        continue;
      }
      foreach ($this->uidSubs[$name] as $curClient) {
        $this->logger->notice(
            "Notifying client of {$data['event']}! ".
              "($name, {$curClient->resourceId})"
        );
        $payload = [
          'uid' => $name,
          'event' => $data['event']
        ];
        if (isset($data['value'])) {
          $payload['value'] = $data['value'];
        }
        $curClient->send(json_encode($payload));
      }
    }
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
