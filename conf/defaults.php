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

return [
  /*
   * PubSub Entries
   * The URLs of the Nymph-PubSub servers to directly publish to. These servers
   * are how this host will enter the PubSub network. If you only have one
   * PubSub server, it needs to be listed here.
   */
  'entries' => [
    'ws://127.0.0.1:8080/',
  ],
  /*
   * PubSub Relays
   * The URLs of additional Nymph-PubSub servers to relay publishes to. If this
   * host is a PubSub server, these servers are how it will continue into your
   * PubSub network.
   */
  'relays' => [
    //'ws://127.0.0.1:8080/',
  ],
  /*
   * Host
   * The host address to bind to.
   */
  'host' => '0.0.0.0',
  /*
   * Port
   * The port to listen on.
   */
  'port' => 8080,
  /*
   * Broadcast Counts
   * Allow clients to request to be notified when other clients subscribe to the
   * same queries.
   */
  'broadcast_counts' => true,
];
