<?php

declare(strict_types=1);

namespace OpenPii\MonologSanitizer\Tests;

use OpenPii\MonologSanitizer\Bridge\Symfony\PiiSanitizerBundle;
use OpenPii\MonologSanitizer\Client\PiiSocketClient;
use OpenPii\MonologSanitizer\Processor\PiiSanitizerProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PiiSanitizerBundleTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private static function build(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $bundle = new PiiSanitizerBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();

        return $container;
    }

    public function testDefaultsTagProcessorForAllChannels(): void
    {
        $container = self::build([]);

        $def = $container->getDefinition(PiiSanitizerProcessor::class);
        self::assertSame([[]], $def->getTag('monolog.processor'));
        self::assertSame(PiiSanitizerProcessor::ON_FAILURE_REDACT, $def->getArgument(1));
        self::assertSame('/tmp/sockets/pii_sanitizer.sock', $container->getDefinition(PiiSocketClient::class)->getArgument(0));
    }

    public function testChannelsAndOptions(): void
    {
        $container = self::build([
            'socket_path' => '/run/pii.sock',
            'on_failure' => 'passthrough',
            'channels' => ['app', 'security'],
        ]);

        $def = $container->getDefinition(PiiSanitizerProcessor::class);
        self::assertSame([['channel' => 'app'], ['channel' => 'security']], $def->getTag('monolog.processor'));
        self::assertSame('passthrough', $def->getArgument(1));
        self::assertSame('/run/pii.sock', $container->getDefinition(PiiSocketClient::class)->getArgument(0));
    }
}
