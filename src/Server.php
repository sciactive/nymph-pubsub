<?php
namespace Nymph\PubSub;
use \Devristo\Phpws\Server\WebSocketServer;

class Server {
	private $loop;
	private $logger;
	private $writer;
	private $router;
	private $server;

	public function __construct() {
		$config = \SciActive\R::_('NymphPubSubConfig');

		$this->loop = \React\EventLoop\Factory::create();

		// Create a logger which writes everything to the STDOUT
		$this->logger = new \Zend\Log\Logger();
		$this->writer = new \Zend\Log\Writer\Stream("php://output");
		$this->logger->addWriter($this->writer);

		// Create a WebSocket server using SSL
		$this->logger->notice("Nymph-PubSub server starting on {$config->host['value']}:{$config->port['value']}.");
		$this->server = new WebSocketServer("tcp://{$config->host['value']}:{$config->port['value']}", $this->loop, $this->logger);

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
