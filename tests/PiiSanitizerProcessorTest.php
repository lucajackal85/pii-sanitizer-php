<?php

declare(strict_types=1);

namespace OpenPii\MonologSanitizer\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use OpenPii\MonologSanitizer\Client\MaskingStrategy;
use OpenPii\MonologSanitizer\Client\PiiClientException;
use OpenPii\MonologSanitizer\Client\PiiClientInterface;
use OpenPii\MonologSanitizer\Processor\PiiSanitizerProcessor;
use PHPUnit\Framework\TestCase;

final class PiiSanitizerProcessorTest extends TestCase
{
    private static function record(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'Payment failed for John Doe',
            context: ['email' => 'john@example.com', 'exception' => new \RuntimeException('card declined')],
            extra: ['ip' => '10.0.0.1'],
        );
    }

    public function testRecordIsRebuiltFromSanitizedPayload(): void
    {
        $client = new class implements PiiClientInterface {
            /** @var list<array<mixed>|string> */
            public array $calls = [];

            public function sanitize(string|array $payload, MaskingStrategy $strategy = MaskingStrategy::Tag): string|array
            {
                $this->calls[] = $payload;
                $json = json_encode($payload, JSON_THROW_ON_ERROR);

                return json_decode(str_replace(['John Doe', 'john@example.com'], ['[PRIVATE_PERSON]', '[PRIVATE_EMAIL]'], $json), true, 512, JSON_THROW_ON_ERROR);
            }
        };

        $out = (new PiiSanitizerProcessor($client))(self::record());

        self::assertCount(1, $client->calls, 'message, context and extra go in one request');
        self::assertSame('Payment failed for [PRIVATE_PERSON]', $out->message);
        self::assertSame('[PRIVATE_EMAIL]', $out->context['email']);
        // The exception object was normalized to a JSON-safe array before sending.
        self::assertIsArray($out->context['exception']);
        self::assertSame(\RuntimeException::class, $out->context['exception']['class']);
        self::assertSame(['ip' => '10.0.0.1'], $out->extra);
        self::assertSame('app', $out->channel);
        self::assertSame(Level::Error, $out->level);
    }

    public function testRedactPolicyDropsEverythingOnFailure(): void
    {
        $out = (new PiiSanitizerProcessor(self::failingClient()))(self::record());

        self::assertSame(PiiSanitizerProcessor::UNAVAILABLE_MARKER, $out->message);
        self::assertSame([], $out->context);
        self::assertSame(['pii_sanitizer' => 'unavailable'], $out->extra);
        self::assertSame(Level::Error, $out->level);
    }

    public function testPassthroughPolicyKeepsRecord(): void
    {
        $record = self::record();
        $out = (new PiiSanitizerProcessor(self::failingClient(), PiiSanitizerProcessor::ON_FAILURE_PASSTHROUGH))($record);

        self::assertSame($record, $out);
    }

    public function testCircuitBreakerSkipsEngineUntilCooldownExpires(): void
    {
        $client = self::failingClient();
        $now = 1000.0;
        $processor = new PiiSanitizerProcessor($client, circuitBreakerSeconds: 5.0, clock: static function () use (&$now): float {
            return $now;
        });

        $processor(self::record());
        $processor(self::record());
        $now += 4.9;
        $processor(self::record());
        self::assertSame(1, $client->calls, 'engine not called while the breaker is open');

        $now += 0.2;
        $processor(self::record());
        self::assertSame(2, $client->calls, 'engine retried after cooldown');
    }

    public function testInvalidPolicyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PiiSanitizerProcessor(self::failingClient(), 'ignore');
    }

    /**
     * @return PiiClientInterface&object{calls: int}
     */
    private static function failingClient(): PiiClientInterface
    {
        return new class implements PiiClientInterface {
            public int $calls = 0;

            public function sanitize(string|array $payload, MaskingStrategy $strategy = MaskingStrategy::Tag): string|array
            {
                ++$this->calls;
                throw new PiiClientException('down');
            }
        };
    }
}
