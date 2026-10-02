<?php

declare(strict_types=1);

namespace OpenPii\MonologSanitizer\Processor;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenPii\MonologSanitizer\Client\PiiClientException;
use OpenPii\MonologSanitizer\Client\PiiClientInterface;

/**
 * Sends message, context and extra to the PII engine in a single request and rebuilds the record
 * from the sanitized result.
 *
 * Failure policy when the engine is unavailable:
 *  - "redact" (default): replace the message with a marker and drop context/extra. Nothing leaks.
 *  - "passthrough": keep the record unchanged. Logs keep flowing but may contain PII.
 *
 * After a failure the processor stops calling the engine for $circuitBreakerSeconds, so a dead
 * sidecar doesn't add a timeout to every single log line.
 */
final class PiiSanitizerProcessor implements ProcessorInterface
{
    public const ON_FAILURE_REDACT = 'redact';
    public const ON_FAILURE_PASSTHROUGH = 'passthrough';
    public const UNAVAILABLE_MARKER = '[PII_SANITIZER_UNAVAILABLE]';

    private NormalizerFormatter $normalizer;
    private float $openUntil = 0.0;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param (callable(): float)|null $clock seconds as float; injectable for tests
     */
    public function __construct(
        private readonly PiiClientInterface $client,
        private readonly string $onFailure = self::ON_FAILURE_REDACT,
        private readonly float $circuitBreakerSeconds = 5.0,
        ?callable $clock = null,
    ) {
        if (!in_array($onFailure, [self::ON_FAILURE_REDACT, self::ON_FAILURE_PASSTHROUGH], true)) {
            throw new \InvalidArgumentException(sprintf('on_failure must be "%s" or "%s"', self::ON_FAILURE_REDACT, self::ON_FAILURE_PASSTHROUGH));
        }
        $this->normalizer = new NormalizerFormatter();
        $this->clock = null !== $clock ? \Closure::fromCallable($clock) : static fn (): float => microtime(true);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (($this->clock)() < $this->openUntil) {
            return $this->fail($record);
        }

        $payload = [
            'message' => $record->message,
            // Objects/exceptions -> JSON-safe arrays, the same way Monolog formatters would see them.
            'context' => $this->normalize($record->context),
            'extra' => $this->normalize($record->extra),
        ];

        try {
            $clean = $this->client->sanitize($payload);
            if (!is_array($clean)) {
                throw new PiiClientException('Expected an array payload back from the engine');
            }
        } catch (PiiClientException) {
            $this->openUntil = ($this->clock)() + $this->circuitBreakerSeconds;

            return $this->fail($record);
        }

        return $record->with(
            message: is_string($clean['message'] ?? null) ? $clean['message'] : self::UNAVAILABLE_MARKER,
            context: is_array($clean['context'] ?? null) ? $clean['context'] : [],
            extra: is_array($clean['extra'] ?? null) ? $clean['extra'] : [],
        );
    }

    private function fail(LogRecord $record): LogRecord
    {
        if (self::ON_FAILURE_PASSTHROUGH === $this->onFailure) {
            return $record;
        }

        return $record->with(message: self::UNAVAILABLE_MARKER, context: [], extra: ['pii_sanitizer' => 'unavailable']);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function normalize(array $data): array
    {
        $normalized = $this->normalizer->normalizeValue($data);

        return is_array($normalized) ? $normalized : [];
    }
}
