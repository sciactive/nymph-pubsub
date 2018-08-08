<?php namespace Nymph\PubSub;

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class Server {
  private $logger;
  private $writer;
  private $server;

  /**
   * The PubSub config array.
   *
   * @var array
   * @access public
   */
  public static $config;

  /**
   * Apply configuration to Nymph-PubSub.
   *
   * $config should be an associative array of Nymph-PubSub configuration. Use
   * the following form:
   *
   * [
   *   'entries' => [
   *     'ws://127.0.0.1:8081/',
   *   ],
   *   'port' => 8081
   * ]
   *
   * @param array $config An associative array of Nymph's configuration.
   */
  public static function configure($config = []) {
    $defaults = include dirname(__DIR__).'/conf/defaults.php';
    self::$config = array_replace($defaults, $config);
  }

  public function __construct($config = []) {
    self::configure($config);

    // Create a logger which writes everything to the STDOUT
    $this->logger = new \Zend\Log\Logger();
    $this->writer = new \Zend\Log\Writer\Stream("php://output");
    $this->logger->addWriter($this->writer);

    // Create a WebSocket server using SSL
    try {
      $this->logger->notice(
          "Nymph-PubSub server starting on ".
            self::$config['host'].":".self::$config['port']."."
      );
    } catch (\Exception $e) {
      if (strpos($e->getMessage(), 'date.timezone')) {
        echo "It looks like you haven't set a default timezone. In order to ".
            "avoid constant complaints from Zend's logger, I'm just going to ".
            "kill myself now.\n\n";
        echo $e->getMessage()."\n";
        exit;
      }
      throw $e;
    }

    $wsServer = new WsServer(new MessageHandler($this->logger));

    $this->server = IoServer::factory(
        new HttpServer(
            $wsServer
        ),
        self::$config['port'],
        self::$config['host']
    );

    $wsServer->enableKeepAlive($this->server->loop, 30);
  }

  public function __destruct() {
    try {
      $this->logger->notice("Nymph-PubSub server shutting down.");
    } catch (\Exception $e) {
      if (strpos($e->getMessage(), 'date.timezone')) {
        // Already echoed an error about this.
        exit;
      }
      throw $e;
    }
  }

  public function run() {
    // Start the event loop
    $this->server->run();
  }
}
