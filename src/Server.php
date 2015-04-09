<?php
namespace Nymph\PubSub;
use \Devristo\Phpws\Server\WebSocketServer;

class Server {
	private $loop;
	private $logger;
	private $writer;
	private $router;
	private $server;

	/**
	 * Apply configuration to Nymph-PubSub.
	 *
	 * $config should be an associative array of Nymph-PubSub configuration. Use
	 * the following form:
	 *
	 * [
	 *     'driver' => 'MySQL',
	 *     'pubsub' => true,
	 *     'MySql' => [
	 *         'host' => '127.0.0.1'
	 *     ]
	 * ]
	 *
	 * @param array $config An associative array of Nymph's configuration.
	 */
	public static function configure($config = []) {
		\SciActive\RequirePHP::_('NymphPubSubConfig', [], function() use ($config){
			$defaults = include dirname(__DIR__).'/conf/defaults.php';
			$nymphConfig = [];
			foreach ($defaults as $curName => $curOption) {
				if ((array) $curOption === $curOption && isset($curOption['value'])) {
					$nymphConfig[$curName] = $curOption['value'];
				} else {
					$nymphConfig[$curName] = [];
					foreach ($curOption as $curSubName => $curSubOption) {
						$nymphConfig[$curName][$curSubName] = $curSubOption['value'];
					}
				}
			}
			return array_replace_recursive($nymphConfig, $config);
		});
	}

	public function __construct($config = []) {
		self::configure($config);
		$config = \SciActive\RequirePHP::_('NymphPubSubConfig');

		$this->loop = \React\EventLoop\Factory::create();

		// Create a logger which writes everything to the STDOUT
		$this->logger = new \Zend\Log\Logger();
		$this->writer = new \Zend\Log\Writer\Stream("php://output");
		$this->logger->addWriter($this->writer);

		// Create a WebSocket server using SSL
		$this->logger->notice("Nymph-PubSub server starting on {$config['host']}:{$config['port']}.");
		$this->server = new WebSocketServer("tcp://{$config['host']}:{$config['port']}", $this->loop, $this->logger);

		// Create a router which transfers all /chat connections to the MessageHandler class
		$this->router = new \Devristo\Phpws\Server\UriHandler\ClientRouter($this->server, $this->logger);

		// route / url
		$this->router->addRoute('#^/#i', new MessageHandler($this->logger));

		// route unmatched urls
		$this->router->addRoute('#^(.*)$#i', new MessageHandlerForUnroutedUrls($this->logger));

		// Bind the server
		$this->server->bind();
	}

	public function __destruct() {
		$this->logger->notice("Nymph-PubSub server shutting down.");
		$this->server->removeAllListeners();
		$this->stop();
	}

	public function run() {
		// Start the event loop
		$this->loop->run();
	}

	public function stop() {
		// Stop the event loop
		$this->loop->stop();
	}
}
