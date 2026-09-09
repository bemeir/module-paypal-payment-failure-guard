<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Unit\Plugin\Resolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\PaypalGraphQl\Model\Resolver\PayflowProResponse;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magetu\PaypalPaymentFailureGuard\Plugin\Resolver\PayflowProResponseGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PayflowProResponseGuardTest extends TestCase
{
    private const ALLOWED = ['payflowpro', 'payflowpro_cc_vault'];

    /** @var MaskedQuoteIdToQuoteIdInterface|MockObject */
    private $maskedToId;

    /** @var PaymentMethodManagementInterface|MockObject */
    private $paymentManagement;

    /** @var PayflowProResponse|MockObject */
    private $resolver;

    /** @var Field|MockObject */
    private $field;

    /** @var ResolveInfo|MockObject */
    private $info;

    /** @var PayflowProResponseGuard */
    private $plugin;

    protected function setUp(): void
    {
        $this->maskedToId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $this->paymentManagement = $this->createMock(PaymentMethodManagementInterface::class);
        $this->resolver = $this->createMock(PayflowProResponse::class);
        $this->field = $this->createMock(Field::class);
        $this->info = $this->createMock(ResolveInfo::class);
        $this->plugin = new PayflowProResponseGuard(
            $this->maskedToId,
            $this->paymentManagement,
            self::ALLOWED
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function invokeGuard(array $args): void
    {
        $this->plugin->beforeResolve(
            $this->resolver,
            $this->field,
            null,
            $this->info,
            null,
            $args
        );
    }

    private function withPaymentMethod(?string $method): void
    {
        $this->maskedToId->method('execute')->with('mask')->willReturn(5);
        if ($method === null) {
            $this->paymentManagement->method('get')->with(5)->willReturn(null);
            return;
        }
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $this->paymentManagement->method('get')->with(5)->willReturn($payment);
    }

    private function assertRefused(): void
    {
        $threw = false;
        try {
            $this->invokeGuard(['input' => ['cart_id' => 'mask', 'paypal_payload' => 'RESULT=12&RESPMSG=Declined']]);
        } catch (GraphQlInputException $e) {
            $threw = true;
            $this->assertSame('Transaction has been declined.', $e->getMessage());
        }
        $this->assertTrue($threw, 'Guard must refuse the request');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function assertAllowed(array $args): void
    {
        // No exception means the resolver is allowed to run and notify a genuine failure.
        $this->invokeGuard($args);
        $this->addToAssertionCount(1);
    }

    public function testEmptyCartWithNoPaymentMethodIsRefusedWithoutNotifying(): void
    {
        $this->withPaymentMethod(null);
        $this->assertRefused();
    }

    public function testCartWithNonPayflowMethodIsRefusedWithoutNotifying(): void
    {
        $this->withPaymentMethod('checkmo');
        $this->assertRefused();
    }

    public function testPayflowProCartIsAllowedSoAGenuineFailureStillNotifies(): void
    {
        $this->withPaymentMethod('payflowpro');
        $this->assertAllowed(['input' => ['cart_id' => 'mask', 'paypal_payload' => 'x']]);
    }

    public function testPayflowProCcVaultCartIsAllowed(): void
    {
        $this->withPaymentMethod('payflowpro_cc_vault');
        $this->assertAllowed(['input' => ['cart_id' => 'mask', 'paypal_payload' => 'x']]);
    }

    public function testMissingPayloadDefersToResolverInputValidation(): void
    {
        $this->maskedToId->expects($this->never())->method('execute');
        $this->assertAllowed(['input' => ['cart_id' => 'mask']]);
    }

    public function testInvalidCartDefersToResolver(): void
    {
        $this->maskedToId->method('execute')->with('mask')->willThrowException(new NoSuchEntityException());
        $this->assertAllowed(['input' => ['cart_id' => 'mask', 'paypal_payload' => 'x']]);
    }
}
