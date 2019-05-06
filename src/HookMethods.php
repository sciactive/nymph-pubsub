<?php namespace Nymph\PubSub;

use SciActive\Hook;

class HookMethods {
  public static function setup() {
    if (!isset(Server::$config)) {
      Server::configure();
    }

    Hook::addCallback(
        'Nymph->saveEntity',
        -10,
        function (&$arguments, $name, &$object, &$function, &$data) {
          $data['entity'] = &$arguments[0];
          $data['guid'] = $arguments[0]->guid;
          $className = is_callable([$arguments[0], '_hookObject'])
              ? get_class($arguments[0]->_hookObject())
              : get_class($arguments[0]);
          $data['etype'] = $className::ETYPE;
        }
    );
    Hook::addCallback(
        'Nymph->saveEntity',
        10,
        function (&$return, $name, &$object, &$function, &$data) {
          if (!$return[0]) {
            return;
          }
          HookMethods::sendMessage(json_encode([
            'action' => 'publish',
            'event' => $data['guid'] === $data['entity']->guid
                ? 'update'
                : 'create',
            'guid' => $data['entity']->guid,
            'entity' => $data['entity']->jsonSerialize(false),
            'etype' => $data['etype']
          ]));
        }
    );
    Hook::addCallback(
        'Nymph->deleteEntity',
        -10,
        function (&$arguments, $name, &$object, &$function, &$data) {
          $data['guid'] = $arguments[0]->guid;
          $className = is_callable([$arguments[0], '_hookObject'])
              ? get_class($arguments[0]->_hookObject())
              : get_class($arguments[0]);
          $data['etype'] = $className::ETYPE;
        }
    );
    Hook::addCallback(
        'Nymph->deleteEntity',
        10,
        function (&$return, $name, &$object, &$function, &$data) {
          if (!$return[0]) {
            return;
          }
          HookMethods::sendMessage(json_encode([
            'action' => 'publish',
            'event' => 'delete',
            'guid' => $data['guid'],
            'etype' => $data['etype']
          ]));
        }
    );
    Hook::addCallback(
        'Nymph->deleteEntityByID',
        -10,
        function (&$arguments, $name, &$object, &$function, &$data) {
          $data['guid'] = $arguments[0];
          $data['etype'] = $arguments[1]::ETYPE;
        }
    );
    Hook::addCallback(
        'Nymph->deleteEntityByID',
        10,
        function (&$return, $name, &$object, &$function, &$data) {
          if (!$return[0]) {
            return;
          }
          HookMethods::sendMessage(json_encode([
            'action' => 'publish',
            'event' => 'delete',
            'guid' => $data['guid'],
            'etype' => $data['etype']
          ]));
        }
    );
    Hook::addCallback(
        'Nymph->newUID',
        -10,
        function (&$arguments, $name, &$object, &$function, &$data) {
          $data['name'] = $arguments[0];
        }
    );
    Hook::addCallback(
        'Nymph->newUID',
        10,
        function (&$return, $name, &$object, &$function, &$data) {
          if (!isset($return[0])) {
            return;
          }
          HookMethods::sendMessage(json_encode([
            'action' => 'publish',
            'event' => 'newUID',
            'name' => $data['name'],
            'value' => $return[0]
          ]));
        }
    );
    Hook::addCallback(
        'Nymph->setUID',
        -10,
        function (&$arguments, $name, &$object, &$function, &$data) {
          $data['name'] = $arguments[0];
          $data['value'] = (int) $arguments[1];
        }
    );
    Hook::addCallback(
        'Nymph->setUID',
        10,
        function (&$return, $name, &$object, &$function, &$data) {
          if (!$return[0]) {
            return;
          }
          HookMethods::sendMessage(json_encode([
            'action' => 'publish',
            'event' => 'setUID',
            'name' => $data['name'],
            'value' => $data['value']
          ]));
        }
    );
    Hook::addCallback(
        'Nymph->renameUID',
        -10,
        function (&$arguments, $name, &$object, &$function, &$data) {
          $data['oldName'] = $arguments[0];
          $data['newName'] = $arguments[1];
        }
    );
    Hook::addCallback(
        'Nymph->renameUID',
        10,
        function (&$return, $name, &$object, &$function, &$data) {
          if (!$return[0]) {
            return;
          }
          HookMethods::sendMessage(json_encode([
            'action' => 'publish',
            'event' => 'renameUID',
            'oldName' => $data['oldName'],
            'newName' => $data['newName']
          ]));
        }
    );
    Hook::addCallback(
        'Nymph->deleteUID',
        -10,
        function (&$arguments, $name, &$object, &$function, &$data) {
          $data['name'] = $arguments[0];
        }
    );
    Hook::addCallback(
        'Nymph->deleteUID',
        10,
        function (&$return, $name, &$object, &$function, &$data) {
          if (!$return[0]) {
            return;
          }
          HookMethods::sendMessage(json_encode([
            'action' => 'publish',
            'event' => 'deleteUID',
            'name' => $data['name']
          ]));
        }
    );
  }

  public static function sendMessage($message) {
    foreach (Server::$config['entries'] as $host) {
      \Ratchet\Client\connect($host)->then(function($conn) use ($message) {
        $conn->send($message);
        $conn->close();
      }, function ($e) {
        error_log("Could not connect to PubSub: {$e->getMessage()}");
      });
    }
  }
}
