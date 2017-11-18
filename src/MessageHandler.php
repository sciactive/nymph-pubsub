<?php namespace Nymph\PubSub;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use WebSocket\Client as TextalkWebSocketClient;

/**
 * Handle subscriptions and publications.
 */
class MessageHandler implements MessageComponentInterface {
  private $logger;
  protected $subscriptions = [
    'queries' => [],
    'uids' => []
  ];

  public function __construct(\Zend\Log\Logger $logger) {
    $this->logger = $logger;
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
            ['subscribe', 'unsubscribe', 'publish']
        )) {
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
              if (!class_exists($args[0]['class'])) {
                return;
              }
              $newArg = \Nymph\REST::translateSelector($args[0]['class'], $args[$i]);
              if ($newArg === false) {
                return;
              }
              $args[$i] = $newArg;
            }
          }
          $serialArgs = serialize($args);
          $this->prepareSelectors($args);
          $args[0]['skip_ac'] = true;
          if ($data['action'] === 'subscribe') {
            if (!key_exists($serialArgs, $this->subscriptions['queries'])) {
              $guidArgs = $args;
              $guidArgs[0]['return'] = 'guid';
              $guidArgs[0]['skip_ac'] = true;
              $this->subscriptions['queries'][$serialArgs] = [
                'current' => call_user_func_array(
                    "\Nymph\Nymph::getEntities",
                    $guidArgs
                )
              ];
            }
            $this->subscriptions['queries'][$serialArgs][] =
                [
                  'client' => $from,
                  'query' => $data['query'],
                  'count' => !!$data['count']
                ];
            $this->logger->notice(
                "Client subscribed to a query! " .
                    "($serialArgs, {$from->resourceId})"
            );
            if (Server::$config['broadcast_counts']) {
              // Notify clients of the subscription count.
              $count = count($this->subscriptions['queries'][$serialArgs]) - 1;
              foreach ($this->subscriptions['queries'][$serialArgs] as
                  $key => $curClient) {
                if ($key === 'current') {
                  continue;
                }
                if ($curClient['count']) {
                  $curClient['client']->send(
                      json_encode(
                          ['query' => $curClient['query'], 'count' => $count]
                      )
                  );
                }
              }
            }
          } elseif ($data['action'] === 'unsubscribe') {
            if (!key_exists($serialArgs, $this->subscriptions['queries'])) {
              return;
            }
            foreach ($this->subscriptions['queries'][$serialArgs] as
                $key => $value) {
              if ($key === 'current') {
                continue;
              }
              if ($from->resourceId === $value['client']->resourceId
                  && $data['query'] === $value['query']) {
                unset($this->subscriptions['queries'][$serialArgs][$key]);
                $this->logger->notice(
                    "Client unsubscribed from a query! ".
                        "($serialArgs, {$from->resourceId})"
                );
                if (Server::$config['broadcast_counts']) {
                  // Notify clients of the subscription count.
                  $count =
                      count($this->subscriptions['queries'][$serialArgs]) - 1;
                  foreach ($this->subscriptions['queries'][$serialArgs] as
                      $key => $curClient) {
                    if ($key === 'current') {
                      continue;
                    }
                    if ($curClient['count']) {
                      $curClient['client']->send(
                          json_encode(
                              [
                                'query' => $curClient['query'],
                                'count' => $count
                              ]
                          )
                      );
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
            $this->subscriptions['uids'][$data['uid']][] =
                ['client' => $from, 'count' => !!$data['count']];
            $this->logger->notice(
                "Client subscribed to a UID! " .
                    "({$data['uid']}, {$from->resourceId})"
            );
            if (Server::$config['broadcast_counts']) {
              // Notify clients of the subscription count.
              $count = count($this->subscriptions['uids'][$data['uid']]);
              foreach ($this->subscriptions['uids'][$data['uid']] as
                  $curClient) {
                if ($curClient['count']) {
                  $curClient['client']->send(
                      json_encode(
                          ['uid' => $data['uid'], 'count' => $count]
                      )
                  );
                }
              }
            }
          } elseif ($data['action'] === 'unsubscribe') {
            if (!key_exists($data['uid'], $this->subscriptions['uids'])) {
              return;
            }
            foreach ($this->subscriptions['uids'][$data['uid']] as
                $key => $value) {
              if ($from->resourceId === $value['client']->resourceId) {
                unset($this->subscriptions['uids'][$data['uid']][$key]);
                $this->logger->notice(
                    "Client unsubscribed from a UID! " .
                        "({$data['uid']}, {$from->resourceId})"
                );
                if (Server::$config['broadcast_counts']) {
                  // Notify clients of the subscription count.
                  $count = count($this->subscriptions['uids'][$data['uid']]);
                  foreach ($this->subscriptions['uids'][$data['uid']] as
                      $curClient) {
                    if ($curClient['count']) {
                      $curClient['client']->send(
                          json_encode(
                              ['uid' => $data['uid'], 'count' => $count]
                          )
                      );
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
        if (isset($data['guid'])
            && (
              $data['event'] === 'delete'
              || (
                isset($data['entity'])
                && ($data['event'] === 'create' || $data['event'] === 'update')
              )
            )) {
          $this->logger->notice(
              "Received an entity publish! " .
                  "({$data['guid']}, {$data['event']}, {$from->resourceId})"
          );
          // Relay the publish to other servers.
          $this->relay($msg);
          foreach ($this->subscriptions['queries'] as
              $curQuery => &$curClients) {
            if ($data['event'] === 'delete' || $data['event'] === 'update') {
              // Check if it is in any queries' currents.
              if (in_array($data['guid'], $curClients['current'])) {
                // Update currents list.
                $guidArgs = unserialize($curQuery);
                $this->prepareSelectors($guidArgs);
                $guidArgs[0]['return'] = 'guid';
                $guidArgs[0]['skip_ac'] = true;
                $curClients['current'] =
                    call_user_func_array(
                        "\Nymph\Nymph::getEntities",
                        $guidArgs
                    );
                // Notify subscribers.
                foreach ($curClients as $key => $curClient) {
                  if ($key === 'current') {
                    continue;
                  }
                  $this->logger->notice(
                      "Notifying client of modification! " .
                          "({$curClient['client']->resourceId})"
                  );
                  $curClient['client']->send(
                      json_encode(['query' => $curClient['query']])
                  );
                }
                continue;
              }
            }
            // It isn't in the current matching entities.
            if ($data['event'] === 'create' || $data['event'] === 'update') {
              // Check if it matches any queries.
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
                  )) {
                // Update currents list.
                $guidArgs = unserialize($curQuery);
                $this->prepareSelectors($guidArgs);
                $guidArgs[0]['return'] = 'guid';
                $guidArgs[0]['skip_ac'] = true;
                $curClients['current'] =
                    call_user_func_array(
                        "\Nymph\Nymph::getEntities",
                        $guidArgs
                    );
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
                  $this->logger->notice(
                      "Notifying client of new match! " .
                          "({$curClient['client']->resourceId})"
                  );
                  $curClient['client']->send(
                      json_encode(['query' => $curClient['query']])
                  );
                }
              }
            }
          }
          unset($curClients);
        } elseif ((
              isset($data['name'])
              || (isset($data['oldName']) && isset($data['newName']))
            )
            && in_array(
                $data['event'],
                ['newUID', 'setUID', 'renameUID', 'deleteUID']
            )) {
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
                || !key_exists($name, $this->subscriptions['uids'])) {
              continue;
            }
            foreach ($this->subscriptions['uids'][$name] as $curClient) {
              $this->logger->notice(
                  "Notifying client of {$data['event']}! " .
                      "($name, {$curClient['client']->resourceId})"
              );
              $curClient['client']->send(json_encode(['uid' => $name]));
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
    foreach ($this->subscriptions['queries'] as $curQuery => &$curClients) {
      foreach ($curClients as $key => $curClient) {
        if ($key === 'current') {
          continue;
        }
        if ($conn->resourceId === $curClient['client']->resourceId) {
          unset($curClients[$key]);
          if (Server::$config['broadcast_counts']) {
            // Notify clients of the subscription count.
            $count = count($curClients) - 1;
            foreach ($curClients as $key => $curCountClient) {
              if ($key === 'current') {
                continue;
              }
              if ($curCountClient['count']) {
                $curCountClient['client']->send(
                    json_encode(
                        [
                          'query' => $curCountClient['query'],
                          'count' => $count
                        ]
                    )
                );
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
        if ($conn->resourceId === $curClient['client']->resourceId) {
          unset($curClients[$key]);
          if (Server::$config['broadcast_counts']) {
            // Notify clients of the subscription count.
            $count = count($curClients);
            foreach ($curClients as $curCountClient) {
              if ($curCountClient['count']) {
                $curCountClient['client']->send(
                    json_encode(['uid' => $curUID, 'count' => $count])
                );
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
