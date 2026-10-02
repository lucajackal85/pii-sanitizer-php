# pii-sanitizer-php

A Monolog 3 processor that removes PII and secrets from log records. It sends each record to the local [PII Sanitizer Engine](https://github.com/lucajackal85/pii-sanitizer-engine) sidecar over a Unix socket and writes back the sanitized result. It works with any PHP application that uses Monolog.

This package is framework-agnostic. For Symfony, use the [pii-sanitizer-symfony](https://github.com/lucajackal85/pii-sanitizer-symfony) bundle, which sets it up automatically. A Laravel integration is planned as a separate package.

```text
Before:  Payment failed for John Doe  {"email":"john@example.com"}
After:   Payment failed for [PRIVATE_PERSON]  {"email":"[PRIVATE_EMAIL]"}
```

## Install

The package is not on Packagist yet, so first add this repository to your app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/lucajackal85/pii-sanitizer-php" }
]
```

```bash
composer require lucajackal85/pii-sanitizer-php:dev-main
```

While the repository is private, Composer needs a GitHub token that can read it (`composer config --global github-oauth.github.com <token>`).

Requirements: PHP ≥ 8.1 and Monolog ^3. You also need a running `pii-sanitizer-engine` container whose socket your PHP process can read and write (see [examples/docker-compose.yml](examples/docker-compose.yml)).

## Plain Monolog

```php
use OpenPii\MonologSanitizer\Client\PiiSocketClient;
use OpenPii\MonologSanitizer\Processor\PiiSanitizerProcessor;

$processor = new PiiSanitizerProcessor(
    new PiiSocketClient('/tmp/sockets/pii_sanitizer.sock', connectTimeout: 0.05, readTimeout: 1.0),
    onFailure: PiiSanitizerProcessor::ON_FAILURE_REDACT,
    circuitBreakerSeconds: 5.0,
);
$handler->pushProcessor($processor);   // per handler, or $logger->pushProcessor($processor)
```

The record's message, context and extra are sent together in **one** request. Objects and exceptions in the context are first normalized with Monolog's `NormalizerFormatter`, so the engine sees the same data your formatter would.

## When the sidecar is unavailable

| `on_failure` | Behaviour |
|---|---|
| `redact` *(default)* | The message becomes `[PII_SANITIZER_UNAVAILABLE]`, context is dropped and extra becomes `{"pii_sanitizer":"unavailable"}`. The level, channel and timestamp are kept, so alerting still works and nothing leaks. |
| `passthrough` | The record is logged unchanged. Logs keep flowing but **may contain PII**. |

After a failure the processor stops calling the engine for `circuit_breaker_seconds`. During that window the failure policy applies straight away, so a dead sidecar doesn't add a timeout to every log line.

## Timeouts and latency

The defaults are a 50 ms connect timeout and a 1 s read timeout. On CPU the engine takes about 100–400 ms per string, so log calls are synchronous and add that much latency. Attach the processor only to the channels that leave the box. A read timeout is never retried. The connection is dropped so that a late reply can't be mistaken for the next answer. If a reused connection was closed by the server (for example after a restart), the client retries once on a fresh connection.

Typical engine latency is listed in the [engine README](https://github.com/lucajackal85/pii-sanitizer-engine#performance).

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
PII_SOCKET_PATH=/tmp/sockets/pii_sanitizer.sock php examples/demo.php   # against a running engine
```
