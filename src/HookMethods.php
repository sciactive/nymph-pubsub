<?php
namespace Nymph\PubSub;
use \SciActive\Hook as Hook;

class HookMethods {
  public static function setup() {
    if (!\SciActive\RequirePHP::isdef('NymphPubSubConfig')) {
      \Nymph\PubSub\Server::configure();
    }

    Hook::addCallback('Nymph->saveEntity', -10, function(&$arguments, $name, &$object, &$function, &$data){
      $data['entity'] = $arguments[0];
      $data['guid'] = $arguments[0]->guid;
    });
    Hook::addCallback('Nymph->saveEntity', 10, function(&$return, $name, &$object, &$function, &$data){
      if (!$return[0]) {
        return;
      }
      HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => $data['guid'] === $data['entity']->guid ? 'update' : 'create', 'guid' => $data['entity']->guid, 'entity' => $data['entity']->jsonSerialize(false)]));
    });
    Hook::addCallback('Nymph->deleteEntity', -10, function(&$arguments, $name, &$object, &$function, &$data){
      $data['guid'] = $arguments[0]->guid;
    });
    Hook::addCallback('Nymph->deleteEntity', 10, function(&$return, $name, &$object, &$function, &$data){
      if (!$return[0]) {
        return;
      }
      HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'delete', 'guid' => $data['guid']]));
    });
    Hook::addCallback('Nymph->deleteEntityByID', -10, function(&$arguments, $name, &$object, &$function, &$data){
      $data['guid'] = $arguments[0];
    });
    Hook::addCallback('Nymph->deleteEntityByID', 10, function(&$return, $name, &$object, &$function, &$data){
      if (!$return[0]) {
        return;
      }
      HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'delete', 'guid' => $data['guid']]));
    });
    Hook::addCallback('Nymph->newUID', -10, function(&$arguments, $name, &$object, &$function, &$data){
      $data['name'] = $arguments[0];
    });
    Hook::addCallback('Nymph->newUID', 10, function(&$return, $name, &$object, &$function, &$data){
      if (!isset($return[0])) {
        return;
      }
      HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'newUID', 'name' => $data['name']]));
    });
    Hook::addCallback('Nymph->setUID', -10, function(&$arguments, $name, &$object, &$function, &$data){
      $data['name'] = $arguments[0];
    });
    Hook::addCallback('Nymph->setUID', 10, function(&$return, $name, &$object, &$function, &$data){
      if (!$return[0]) {
        return;
      }
      HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'setUID', 'name' => $data['name']]));
    });
    Hook::addCallback('Nymph->renameUID', -10, function(&$arguments, $name, &$object, &$function, &$data){
      $data['oldName'] = $arguments[0];
      $data['newName'] = $arguments[1];
    });
    Hook::addCallback('Nymph->renameUID', 10, function(&$return, $name, &$object, &$function, &$data){
      if (!$return[0]) {
        return;
      }
      HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'renameUID', 'oldName' => $data['oldName'], 'newName' => $data['newName']]));
    });
    Hook::addCallback('Nymph->deleteUID', -10, function(&$arguments, $name, &$object, &$function, &$data){
      $data['name'] = $arguments[0];
    });
    Hook::addCallback('Nymph->deleteUID', 10, function(&$return, $name, &$object, &$function, &$data){
      if (!$return[0]) {
        return;
      }
      HookMethods::sendMessage(json_encode(['action' => 'publish', 'event' => 'deleteUID', 'name' => $data['name']]));
    });
  }

  public static function sendMessage($message) {
    try {
      $config = \SciActive\RequirePHP::_('NymphPubSubConfig');

      $loop = \React\EventLoop\Factory::create();

      $logger = new \Zend\Log\Logger();
      $writer = new \Zend\Log\Writer\Stream("php://stderr");
      $logger->addWriter($writer);

      foreach ($config['entries'] as $host) {
        $client = new \Devristo\Phpws\Client\WebSocket($host, $loop, $logger);

        $client->on("connect", function() use ($message, $client){
          $client->send($message);
          $client->close();
        });

        $client->open();
      }

      $loop->run();
    } catch (\React\SocketClient\ConnectionException $e) {
      // Ignore a failed connection.
    }
  }
}