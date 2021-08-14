# Nymph PubSub Server - collaborative app data

[![Latest Stable Version](https://img.shields.io/packagist/v/sciactive/nymph-pubsub.svg)](https://packagist.org/packages/sciactive/nymph-pubsub) [![Open Issues](https://img.shields.io/github/issues/sciactive/nymph-pubsub.svg)](https://github.com/sciactive/nymph-pubsub/issues) [![License](https://img.shields.io/github/license/sciactive/nymph-pubsub.svg)]()

Powerful object data storage and querying for collaborative web apps.

## Deprecation Notice

The PHP implementation of Nymph/Tilmeld has been deprecated. It will no longer have any new features added. Instead, a new version of Nymph running on Node.js, written entirely in TypeScript will replace the PHP implementation. You can find it over at the [Nymph.js repo](https://github.com/sciactive/nymphjs).

## Installation

### Automatic Setup

The fastest way to start building a Nymph app is with the [Nymph App Template](https://github.com/hperrin/nymph-template).

### Manual Installation

```sh
composer require sciactive/nymph-pubsub
```

This repository is the PHP publish/subscribe server. For more information, you can see the [main Nymph repository](https://github.com/sciactive/nymph).

## Usage

```php
// pubsub.php: Start with `php pubsub.php [-d]`

if (php_sapi_name() != "cli") {
  die("You can only run pubsub.php from the command line.");
}

// This is an example server that is configured with hostname
// "pubsubnetwork1entry" as an entry point to network1, which contains two
// endpoint servers, "pubsubnetwork1endpoint1" and "pubsubnetwork1endpoint2".

// Setting a default timezome is highly recommended.
date_default_timezone_set('America/Los_Angeles');

require 'vendor/autoload.php';

// Set up Nymph.
use Nymph\Nymph;
Nymph::configure([
  'MySQL' => [
    'host' => 'your_db_host',
    'database' => 'your_database',
    'user' => 'your_user',
    'password' => 'your_password'
  ]
]);

\Nymph\Nymph::connect();

// Allow this file to be called with "-d" to daemonize it.
if (in_array('-d', $argv)) {
  function shutdown() {
    posix_kill(posix_getpid(), SIGHUP);
  }

  // Switch over to daemon mode.
  if ($pid = pcntl_fork()) {
    return;
  }

  register_shutdown_function('shutdown');
} else {
  error_reporting(E_ALL);
}

// Set up Nymph PubSub.
$config = include(__DIR__.'/pubsub-config.php');
$config['port'] = 8080;
$config['relays'] = [
  'ws://pubsubnetwork1endpoint1:8080/',
  'ws://pubsubnetwork1endpoint2:8080/'
];
$server = new \Nymph\PubSub\Server($config);

// Run the server.
$server->run();
```

```php
// pubsub-config.php

// This config file tells Nymph to publish entity updates to these network entry
// points. They will then relay the publish to their network.

return [
  'entries' => [
    'ws://pubsubnetwork1entry:8080/',
    'ws://pubsubnetwork2entry:8080/',
    'ws://pubsubnetwork3entry:8080/'
  ]
];
```

```php
// somewhere in your Nymph rest endpoint.
$config = include('path/to/pubsub/pubsub-config.php');
\Nymph\PubSub\Server::configure($config);
```

For a thorough step by step guide to setting up Nymph on your own server, visit the [Setup Guide](https://github.com/sciactive/nymph/wiki/Setup-Guide).

## API Docs

Check out the [API Docs in the wiki](https://github.com/sciactive/nymph/wiki/API-Docs).
