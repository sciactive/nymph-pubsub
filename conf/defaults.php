<?php
/**
 * Nymph-PubSub's configuration defaults.
 *
 * @package Nymph-PubSub
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @author Hunter Perrin <hperrin@gmail.com>
 * @copyright SciActive.com
 * @link http://sciactive.com/
 */

return (object) [
  'entries' => [
    'cname' => 'PubSub Entries',
    'description' => 'The URLs of the Nymph-PubSub servers to directly publish to. These servers are how this host will enter the PubSub network. If you only have one PubSub server, it needs to be listed here.',
    'value' => [
      'ws://127.0.0.1:8080/',
    ],
  ],
  'relays' => [
    'cname' => 'PubSub Relays',
    'description' => 'The URLs of additional Nymph-PubSub servers to relay publishes to. If this host is a PubSub server, these servers are how it will continue into your PubSub network.',
    'value' => [
      //'ws://127.0.0.1:8080/',
    ],
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
  'broadcast_counts' => [
    'cname' => 'Broadcast Counts',
    'description' => 'Allow clients to request to be notified when other clients subscribe to the same queries.',
    'value' => true,
  ],
];

