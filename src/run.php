<?php
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Nymph\PubSub\Server;

if (file_exists(dirname(__DIR__).'/vendor/autoload.php')) {
	require dirname(__DIR__).'/vendor/autoload.php';
} elseif (file_exists(dirname(__DIR__).'/../../sciactive') && file_exists(dirname(__DIR__).'/../../autoload.php')) {
	require dirname(__DIR__).'/../../autoload.php';
}

try {
	\SciActive\R::_('NymphPubSubConfig');
} catch (\SciActive\RequireModuleFailedException $ex) {
	\SciActive\R::_('NymphPubSubConfig', [], function(){
		return include dirname(__DIR__).'/conf/defaults.php';
	});
}
$config = \SciActive\R::_('NymphPubSubConfig');

$server = IoServer::factory(
	new HttpServer(
		new WsServer(
			new Server()
		)
	),
	$config->port['value'],
	$config->host['value']
);

echo "Nymph PubSub server starting on {$config->host['value']}:{$config->port['value']}.\n";
$server->run();
