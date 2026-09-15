<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Component;

use Magento\Framework\Interception\Definition\Runtime;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\PaypalGraphQl\Model\Resolver\PayflowProResponse;
use Magento\Sales\Api\PaymentFailuresInterface;
use Magetu\PaypalPaymentFailureGuard\Plugin\Resolver\PayflowProResponseGuard;
use Magetu\PaypalPaymentFailureGuard\Plugin\Service\PaymentFailuresGuard;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    public function testDiAreasTargetsAndMethods(): void
    {
        $root = dirname(__DIR__, 2);
        $global = simplexml_load_file($root . '/etc/di.xml');
        $graphql = simplexml_load_file($root . '/etc/graphql/di.xml');
        $service = $global->xpath('/config/type[@name="' . PaymentFailuresInterface::class . '"]/plugin');
        self::assertCount(1, $service);
        self::assertSame(PaymentFailuresGuard::class, (string)$service[0]['type']);
        self::assertSame([], $global->xpath('/config/type[@name="' . PayflowProResponse::class . '"]'));
        $resolver = $graphql->xpath('/config/type[@name="' . PayflowProResponse::class . '"]/plugin');
        self::assertCount(1, $resolver);
        self::assertSame(PayflowProResponseGuard::class, (string)$resolver[0]['type']);
        $items = $graphql->xpath('/config/type[@name="' . PayflowProResponseGuard::class
            . '"]/arguments/argument[@name="allowedPaymentMethods"]/item');
        self::assertSame(['payflowpro', 'payflowpro_cc_vault'], array_map(static fn($item) => constant((string)$item), $items));
        $definitions = new Runtime();
        self::assertSame(['resolve' => DefinitionInterface::LISTENER_BEFORE], $definitions->getMethodList(PayflowProResponseGuard::class));
        self::assertSame(['handle' => DefinitionInterface::LISTENER_AROUND], $definitions->getMethodList(PaymentFailuresGuard::class));
    }

    public function testNewDirectDependenciesAreDeclaredAndSequenced(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $modules = simplexml_load_file($root . '/etc/module.xml');
        self::assertArrayHasKey('magento/module-quote-graph-ql', $composer['require']);
        self::assertArrayHasKey('magento/module-sales', $composer['require']);
        foreach (['Magento_QuoteGraphQl', 'Magento_Sales'] as $name) {
            self::assertCount(1, $modules->xpath('/config/module/sequence/module[@name="' . $name . '"]'));
        }
    }
}
