<?php

declare(strict_types=1);

namespace OpenPii\MonologSanitizer\Laravel;

use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger;
use OpenPii\MonologSanitizer\Client\PiiSocketClient;
use OpenPii\MonologSanitizer\Processor\PiiSanitizerProcessor;

/**
 * Laravel logging tap. In config/logging.php:
 *
 *     'daily' => [
 *         'driver' => 'daily',
 *         'path' => storage_path('logs/laravel.log'),
 *         'tap' => [\OpenPii\MonologSanitizer\Laravel\PiiSanitizerTap::class],
 *     ],
 *
 * Configured through env vars: PII_SOCKET_PATH, PII_ON_FAILURE, PII_CONNECT_TIMEOUT, PII_READ_TIMEOUT.
 */
final class PiiSanitizerTap
{
    private static ?PiiSanitizerProcessor $shared = null;

    /**
     * Laravel passes an Illuminate\Log\Logger; the underlying Monolog instance is reached via getLogger().
     */
    public function __invoke(object $logger): void
    {
        $monolog = $logger instanceof Logger ? $logger : (method_exists($logger, 'getLogger') ? $logger->getLogger() : null);
        if (!$monolog instanceof Logger) {
            return;
        }

        // Attach to handlers rather than the logger so records are scrubbed right before they are written.
        $processor = self::processor();
        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }

    private static function processor(): PiiSanitizerProcessor
    {
        // One processor (and one persistent socket) per worker process, shared across channels.
        return self::$shared ??= new PiiSanitizerProcessor(
            new PiiSocketClient(
                self::env('PII_SOCKET_PATH', '/tmp/sockets/pii_sanitizer.sock'),
                (float) self::env('PII_CONNECT_TIMEOUT', '0.05'),
                (float) self::env('PII_READ_TIMEOUT', '1.0'),
            ),
            self::env('PII_ON_FAILURE', PiiSanitizerProcessor::ON_FAILURE_REDACT),
        );
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && '' !== $value ? $value : $default;
    }
}
