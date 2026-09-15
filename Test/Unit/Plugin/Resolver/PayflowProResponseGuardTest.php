<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Unit\Plugin\Resolver;

use Magento\Framework\DataObject;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Payment\Model\MethodInterface;
use Magento\PaypalGraphQl\Model\Resolver\PayflowProResponse;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magetu\PaypalPaymentFailureGuard\Plugin\Resolver\PayflowProResponseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PayflowProResponseGuardTest extends TestCase
{
    private const ARGS = ['input' => ['cart_id' => 'mask', 'paypal_payload' => 'RESULT=12']];

    private function invoke(GetCartForUser $getCart, ?array $args, array $allowed = ['payflowpro']): void
    {
        $context = new DataObject([
            'user_id' => 19,
            'extension_attributes' => new DataObject(['store' => new DataObject(['id' => 3])]),
        ]);
        (new PayflowProResponseGuard($getCart, $allowed))->beforeResolve(
            $this->createStub(PayflowProResponse::class),
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            null,
            $args
        );
    }

    public static function missingInputs(): array
    {
        return [
            'null arguments' => [null],
            'no input' => [[]],
            'missing cart' => [['input' => ['paypal_payload' => 'x']]],
            'missing payload' => [['input' => ['cart_id' => 'mask']]],
            'empty cart' => [['input' => ['cart_id' => '', 'paypal_payload' => 'x']]],
            'empty payload' => [['input' => ['cart_id' => 'mask', 'paypal_payload' => '']]],
            'zero cart' => [['input' => ['cart_id' => '0', 'paypal_payload' => 'x']]],
            'zero payload' => [['input' => ['cart_id' => 'mask', 'paypal_payload' => '0']]],
        ];
    }

    /** @dataProvider missingInputs */
    #[DataProvider('missingInputs')]
    public function testMissingInputDefersToCoreWithoutLoadingCart(?array $args): void
    {
        $getCart = $this->createMock(GetCartForUser::class);
        $getCart->expects(self::never())->method('execute');
        $this->invoke($getCart, $args);
    }

    public function testAuthorizationErrorPropagatesBeforeInspectingPayment(): void
    {
        $getCart = $this->createMock(GetCartForUser::class);
        $exception = new GraphQlAuthorizationException(__('Unauthorized cart'));
        $getCart->expects(self::once())->method('execute')->with('mask', 19, 3)->willThrowException($exception);
        $this->expectExceptionObject($exception);
        $this->invoke($getCart, self::ARGS);
    }

    public function testUnexpectedCartLoadingErrorIsNotSwallowed(): void
    {
        $getCart = $this->createStub(GetCartForUser::class);
        $exception = new \RuntimeException('Storage unavailable');
        $getCart->method('execute')->willThrowException($exception);
        $this->expectExceptionObject($exception);
        $this->invoke($getCart, self::ARGS);
    }

    public static function rejectedMethods(): array
    {
        return [
            'missing payment' => [null, ['payflowpro']],
            'empty method even if allowlisted' => ['', ['', 'payflowpro']],
            'unrelated method' => ['checkmo', ['payflowpro']],
            'strict case' => ['PAYFLOWPRO', ['payflowpro']],
            'empty allowlist fails closed' => ['payflowpro', []],
        ];
    }

    /** @dataProvider rejectedMethods */
    #[DataProvider('rejectedMethods')]
    public function testRejectedMethodDoesNotInstantiateGateway(?string $code, array $allowed): void
    {
        $quote = $this->createStub(Quote::class);
        $payment = null;
        if ($code !== null) {
            $payment = $this->createMock(Payment::class);
            $payment->method('getMethod')->willReturn($code);
            $payment->expects(self::never())->method('getMethodInstance');
        }
        $quote->method('getPayment')->willReturn($payment);
        $getCart = $this->createStub(GetCartForUser::class);
        $getCart->method('execute')->willReturn($quote);
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Transaction has been declined.');
        $this->invoke($getCart, self::ARGS, $allowed);
    }

    public function testCustomAllowlistedMethodUsesAuthorizedCartStoreAndOnlyActiveCheck(): void
    {
        $gateway = $this->createMock(MethodInterface::class);
        $gateway->expects(self::once())->method('isActive')->with(3)->willReturn(true);
        $gateway->expects(self::never())->method('isAvailable');
        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn('custom_transparent');
        $payment->method('getMethodInstance')->willReturn($gateway);
        $quote = $this->createStub(Quote::class);
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('getStoreId')->willReturn(3);
        $getCart = $this->createStub(GetCartForUser::class);
        $getCart->method('execute')->willReturn($quote);
        $this->invoke($getCart, self::ARGS, ['custom_transparent']);
    }
}
