<?php

declare(strict_types=1);

namespace OpenPii\MonologSanitizer\Tests;

use OpenPii\MonologSanitizer\Client\MaskingStrategy;
use OpenPii\MonologSanitizer\Client\PiiClientException;
use OpenPii\MonologSanitizer\Client\PiiSocketClient;
use PHPUnit\Framework\TestCase;

final class PiiSocketClientTest extends TestCase
{
    /** @var resource|null */
    private $process = null;
    private string $socket = '';

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->socket);
    }

    private function startServer(string $mode): void
    {
        $this->socket = sys_get_temp_dir() . '/pii-test-' . bin2hex(random_bytes(4)) . '.sock';
        $cmd = [PHP_BINARY, __DIR__ . '/Fixtures/fake_server.php', $this->socket, $mode];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->process = $process;
        self::assertSame("ready\n", fgets($pipes[1]));
    }

    public function testRoundTripNestedPayload(): void
    {
        $this->startServer('normal');
        $client = new PiiSocketClient($this->socket);

        $result = $client->sanitize(['message' => 'Hi John Doe', 'context' => ['n' => 3, 'who' => ['John Doe']]]);

        self::assertSame(['message' => 'Hi [PRIVATE_PERSON]', 'context' => ['n' => 3, 'who' => ['[PRIVATE_PERSON]']]], $result);
        self::assertSame('[PRIVATE_PERSON]', $client->sanitize('John Doe'));
    }

    public function testStrategyIsSentAsItsProtocolValue(): void
    {
        $this->startServer('normal');
        $client = new PiiSocketClient($this->socket);

        self::assertSame('Hi ********', $client->sanitize('Hi John Doe', MaskingStrategy::Asterisk));
        self::assertSame('Hi [PRIVATE_PERSON_4c2a]', $client->sanitize('Hi John Doe', MaskingStrategy::Hash));
        self::assertSame('Hi [PRIVATE_PERSON]', $client->sanitize('Hi John Doe', MaskingStrategy::Tag));
        self::assertSame('Hi [PRIVATE_PERSON]', $client->sanitize('Hi John Doe'), 'no strategy = engine default');
    }

    public function testReusesPersistentConnection(): void
    {
        $this->startServer('normal');
        $client = new PiiSocketClient($this->socket);
        $client->sanitize('a');

        // The fake server echoes the connection number in a field the client ignores; verify via a raw read instead.
        $ref = new \ReflectionProperty($client, 'stream');
        $first = $ref->getValue($client);
        $client->sanitize('b');
        self::assertSame($first, $ref->getValue($client));
    }

    public function testReconnectsWhenServerClosesConnection(): void
    {
        $this->startServer('close');
        $client = new PiiSocketClient($this->socket);

        self::assertSame('[PRIVATE_PERSON]', $client->sanitize('John Doe'));
        usleep(20_000); // let the server close its side
        self::assertSame('ok [PRIVATE_PERSON]', $client->sanitize('ok John Doe'));
    }

    public function testReadTimeoutThrowsWithoutRetry(): void
    {
        $this->startServer('slow');
        $client = new PiiSocketClient($this->socket, 0.05, 0.1);

        $start = microtime(true);
        try {
            $client->sanitize('John Doe');
            self::fail('expected timeout');
        } catch (PiiClientException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }
        self::assertLessThan(0.18, microtime(true) - $start, 'a timeout must not be retried');
    }

    public function testEngineErrorIsReported(): void
    {
        $this->startServer('error');
        $this->expectException(PiiClientException::class);
        $this->expectExceptionMessage('boom');
        (new PiiSocketClient($this->socket))->sanitize('x');
    }

    public function testMissingSocketThrows(): void
    {
        $this->expectException(PiiClientException::class);
        $this->expectExceptionMessage('Cannot connect');
        (new PiiSocketClient('/nonexistent/pii.sock'))->sanitize('x');
    }
}
