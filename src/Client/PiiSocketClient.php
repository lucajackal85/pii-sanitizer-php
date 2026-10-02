<?php

declare(strict_types=1);

namespace Jackal\PiiSanitizer\Client;

/**
 * Unix-socket client for the PII Sanitizer Engine (newline-delimited JSON, see PROTOCOL.md).
 *
 * The connection is opened lazily and kept for the lifetime of the object, so a PHP-FPM worker
 * pays the connect cost once per request rather than once per log line.
 */
final class PiiSocketClient implements PiiClientInterface
{
    /** @var resource|null */
    private $stream;
    private int $seq = 0;

    private const RETRYABLE = -1; // never a real errno

    public function __construct(
        private readonly string $socketPath = '/tmp/sockets/pii_sanitizer.sock',
        private readonly float $connectTimeout = 0.05,
        private readonly float $readTimeout = 1.0,
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function sanitize(string|array $payload, MaskingStrategy $strategy = MaskingStrategy::Tag): string|array
    {
        $request = ['id' => (string) ++$this->seq, 'payload' => $payload, 'strategy' => $strategy->value];

        try {
            $line = json_encode($request, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        } catch (\JsonException $e) {
            throw new PiiClientException('Could not encode payload: ' . $e->getMessage(), 0, $e);
        }

        try {
            $response = $this->roundTrip($line);
        } catch (PiiClientException $e) {
            // A reused connection may have been closed by the server (restart, idle). Retry once on a
            // fresh one — but never after a timeout, which would double the worst-case latency.
            if (self::RETRYABLE !== $e->getCode()) {
                throw $e;
            }
            $this->close();
            $response = $this->roundTrip($line);
        }

        if (($response['id'] ?? null) !== $request['id']) {
            $this->close();
            throw new PiiClientException('Response id mismatch');
        }
        if (isset($response['error'])) {
            throw new PiiClientException('Engine error: ' . (is_string($response['error']) ? $response['error'] : 'unknown'));
        }
        if (!array_key_exists('payload', $response) || gettype($response['payload']) !== gettype($payload)) {
            throw new PiiClientException('Malformed engine response');
        }

        /** @var string|array<mixed> */
        return $response['payload'];
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function roundTrip(string $line): array
    {
        $reused = is_resource($this->stream);
        $stream = $this->connect();

        $written = @fwrite($stream, $line);
        if (false === $written || $written < strlen($line)) {
            $this->close();
            throw new PiiClientException('Failed to write to PII engine socket', $reused ? self::RETRYABLE : 0);
        }

        $raw = @fgets($stream);
        if (false === $raw) {
            // Always drop the connection: a late reply would otherwise be read as the next response.
            $timedOut = (bool) stream_get_meta_data($stream)['timed_out'];
            $this->close();
            if ($timedOut) {
                throw new PiiClientException('PII engine read timed out');
            }
            throw new PiiClientException('PII engine closed the connection', $reused ? self::RETRYABLE : 0);
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->close();
            throw new PiiClientException('Invalid JSON from PII engine', 0, $e);
        }
        if (!is_array($decoded)) {
            throw new PiiClientException('Invalid response from PII engine');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * @return resource
     */
    private function connect()
    {
        if (is_resource($this->stream) && !feof($this->stream)) {
            return $this->stream;
        }
        $this->close();

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, $this->connectTimeout);
        if (false === $stream) {
            throw new PiiClientException(sprintf('Cannot connect to PII engine at %s: %s', $this->socketPath, $errstr ?: 'unknown error'), (int) $errno);
        }
        $seconds = (int) floor($this->readTimeout);
        stream_set_timeout($stream, $seconds, (int) (($this->readTimeout - $seconds) * 1_000_000));

        return $this->stream = $stream;
    }
}
