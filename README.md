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

Requirements: PHP ≥ 8.1 and Monolog ^3. You also need a running PII Sanitizer Engine container whose socket your PHP process can read and write. See [Running the engine](#running-the-engine).

## Running the engine

The engine is published as a Docker image on GitHub's container registry: `ghcr.io/lucajackal85/pii-sanitizer-engine`. Its source and full documentation are in [pii-sanitizer-engine](https://github.com/lucajackal85/pii-sanitizer-engine).

### 1. Publish the image (maintainers)

Image builds don't run automatically. To publish the current `main`, start the `docker-publish` workflow by hand, either from **Actions → docker-publish → Run workflow** on the `main` branch, or from the terminal:

```bash
gh workflow run docker-publish --repo lucajackal85/pii-sanitizer-engine --ref main
```

It runs the tests, builds the image and pushes it with two tags:

- `ghcr.io/lucajackal85/pii-sanitizer-engine:latest`
- `ghcr.io/lucajackal85/pii-sanitizer-engine:sha-<commit>`, which pins an exact version

A run takes about 15 minutes of GitHub Actions time, mostly downloading the ~2.8 GB of model weights into the image.

### 2. Log in to the registry

While the image is private, you need a GitHub token with the `read:packages` scope. A classic personal access token works, or add the scope to the GitHub CLI with `gh auth refresh -s read:packages` and use `gh auth token`:

```bash
echo <TOKEN> | docker login ghcr.io -u <github-username> --password-stdin
```

If the image's visibility is set to public (on GitHub: Packages → `pii-sanitizer-engine` → Package settings), anyone can pull it without logging in, and this step isn't needed.

### 3. Pull and run it

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
