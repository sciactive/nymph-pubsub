<?php
/**
 * Nymph-PubSub's configuration defaults.
 *
 * @package Nymph-PubSub
 * @license http://www.gnu.org/licenses/lgpl.html
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://sciactive.com/
 */

return (object) [
	'master' => [
		'cname' => 'Master Notifier',
		'description' => 'The URL of one of the Nymph-PubSub instances that will act as the master. Use the default if you only use one Nymph-PubSub server.',
		'value' => 'ws://127.0.0.1:8080/',
	],
	'host' => [
		'cname' => 'Host',
		'description' => 'The host address to bind to.',
		'value' => '0.0.0.0',
	],
	'port' => [
		'cname' => 'Port',
		'description' => 'The port to listen on.',
		'value' => 8080,
	],
];

