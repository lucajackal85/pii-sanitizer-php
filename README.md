# pii-sanitizer-php

A Monolog 3 processor that removes PII and secrets from log records. It sends each record to the local [PII Sanitizer Engine](https://github.com/lucajackal85/pii-sanitizer-engine) sidecar over a Unix socket and writes back the sanitized result. It works with any PHP application that uses Monolog.

This package is framework-agnostic. For Symfony, use the [pii-sanitizer-symfony](https://github.com/lucajackal85/pii-sanitizer-symfony) bundle, which sets it up automatically. A Laravel integration is planned as a separate package.

```text
Before:  Payment failed for John Doe  {"email":"john@example.com"}
After:   Payment failed for [PRIVATE_PERSON]  {"email":"[PRIVATE_EMAIL]"}
```

## Install

```bash
composer require lucajackal85/pii-sanitizer-php
```

Requirements: PHP ≥ 8.1 and Monolog ^3. You also need a running PII Sanitizer Engine container whose socket your PHP process can read and write. See [Running the engine](#running-the-engine).

## Running the engine

The engine is published as a public Docker image on GitHub's container registry, `ghcr.io/lucajackal85/pii-sanitizer-engine`, so no login is needed. Its source and full documentation are in [pii-sanitizer-engine](https://github.com/lucajackal85/pii-sanitizer-engine).

```bash
docker pull ghcr.io/lucajackal85/pii-sanitizer-engine:latest
```

```bash
docker run -d --name pii-sanitizer-engine --user "$(id -u):$(id -g)" -v /tmp/pii-sockets:/tmp/sockets ghcr.io/lucajackal85/pii-sanitizer-engine:latest
```

The socket is then at `/tmp/pii-sockets/pii_sanitizer.sock`. Pass that path to `PiiSocketClient`, or set it as `PII_SOCKET_PATH`. The model takes about 10 seconds to load; `docker logs pii-sanitizer-engine` shows `listening on …` when it's ready.

To run it next to your app, use [examples/docker-compose.yml](examples/docker-compose.yml), which already uses this image. To change the engine's settings, see its [configuration guide](https://github.com/lucajackal85/pii-sanitizer-engine#configuration).

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

## Using the client directly

`PiiSocketClient` also works without Monolog, for any string or nested array:

```php
use OpenPii\MonologSanitizer\Client\MaskingStrategy;
use OpenPii\MonologSanitizer\Client\PiiSocketClient;

$client = new PiiSocketClient('/tmp/sockets/pii_sanitizer.sock');

$client->sanitize('John Doe called support');                            // "[PRIVATE_PERSON] called support"
$client->sanitize('John Doe called support', MaskingStrategy::Hash);     // "[PRIVATE_PERSON_4c2a] called support"
$client->sanitize('John Doe called support', MaskingStrategy::Asterisk); // "******** called support"
```

The strategy defaults to `MaskingStrategy::Tag`. The client always sends it, so the engine's own `masking_strategy` setting doesn't apply to calls made through this package. The enum lists the strategies the engine accepts: `Tag`, `Hash` and `Asterisk`.

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
composer check        # php-cs-fixer + rector (dry run), PHPStan and PHPUnit, like CI
composer cs-fix       # apply php-cs-fixer
composer rector-fix   # apply rector
PII_SOCKET_PATH=/tmp/sockets/pii_sanitizer.sock php examples/demo.php   # against a running engine
```

## Releases

Every pull request merged into `main` is tagged automatically with the next version, and a GitHub Release with the list of merged PRs is published. Packagist picks up new tags on its own.

The version bump is set with a label on the PR:

| Label | Example |
|---|---|
| *(none)* | `v0.1.0` → `v0.1.1` |
| `minor` | `v0.1.0` → `v0.2.0` |
| `major` | `v0.1.0` → `v1.0.0` |
| `skip-release` | no new version, e.g. for docs or CI changes |

## License

MIT. See [LICENSE](LICENSE).
