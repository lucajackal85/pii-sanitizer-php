<?php

declare(strict_types=1);

namespace OpenPii\MonologSanitizer\Bridge\Symfony;

use OpenPii\MonologSanitizer\Client\PiiClientInterface;
use OpenPii\MonologSanitizer\Client\PiiSocketClient;
use OpenPii\MonologSanitizer\Processor\PiiSanitizerProcessor;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * config/packages/pii_sanitizer.yaml:
 *
 *     pii_sanitizer:
 *         socket_path: '%env(PII_SOCKET_PATH)%'
 *         connect_timeout: 0.05
 *         read_timeout: 1.0
 *         on_failure: redact            # or passthrough
 *         circuit_breaker_seconds: 5
 *         channels: []                  # empty = every channel
 */
final class PiiSanitizerBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        // One statement per option instead of a fluent chain: Symfony 6.4 types end() loosely, so a
        // chain through ->end() can't be statically analysed there.
        $options = self::arrayRoot($definition->rootNode())->children();
        $options->scalarNode('socket_path')->defaultValue('/tmp/sockets/pii_sanitizer.sock');
        $options->floatNode('connect_timeout')->defaultValue(0.05)->min(0);
        $options->floatNode('read_timeout')->defaultValue(1.0)->min(0);
        $options->enumNode('on_failure')
            ->values([PiiSanitizerProcessor::ON_FAILURE_REDACT, PiiSanitizerProcessor::ON_FAILURE_PASSTHROUGH])
            ->defaultValue(PiiSanitizerProcessor::ON_FAILURE_REDACT);
        $options->floatNode('circuit_breaker_seconds')->defaultValue(5.0)->min(0);
        $options->arrayNode('channels')->defaultValue([])->scalarPrototype();
    }

    /**
     * The root node of a bundle config is always an array node, but Symfony 6.4 declares rootNode()
     * as returning the generic NodeDefinition (7.x narrows it). Check it once so both versions type-check.
     */
    private static function arrayRoot(object $node): ArrayNodeDefinition
    {
        if (!$node instanceof ArrayNodeDefinition) {
            throw new \LogicException(sprintf('Expected the bundle config root to be an array node, got %s', $node::class));
        }

        return $node;
    }

    /**
     * @param array{socket_path: string, connect_timeout: float, read_timeout: float, on_failure: string, circuit_breaker_seconds: float, channels: list<string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(PiiSocketClient::class)
            ->args([$config['socket_path'], $config['connect_timeout'], $config['read_timeout']]);
        $services->alias(PiiClientInterface::class, PiiSocketClient::class);

        $processor = $services->set(PiiSanitizerProcessor::class)
            ->args([new Reference(PiiClientInterface::class), $config['on_failure'], $config['circuit_breaker_seconds']]);

        if ([] === $config['channels']) {
            $processor->tag('monolog.processor');
        } else {
            foreach ($config['channels'] as $channel) {
                $processor->tag('monolog.processor', ['channel' => $channel]);
            }
        }
    }
}
