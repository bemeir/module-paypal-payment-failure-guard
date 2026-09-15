<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Unit\Plugin\Service;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\PaymentFailuresInterface;
use Magetu\PaypalPaymentFailureGuard\Plugin\Service\PaymentFailuresGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentFailuresGuardTest extends TestCase
{
    public static function invalidIds(): array
    {
        return [[0], [-1], [PHP_INT_MIN]];
    }

    /** @dataProvider invalidIds */
    #[DataProvider('invalidIds')]
    public function testInvalidIdNeverCallsNotificationService(int $id): void
    {
        $subject = $this->createStub(PaymentFailuresInterface::class);
        $proceed = static function (): void {
            self::fail('An invalid cart must not enter the notification service.');
        };
        self::assertSame($subject, (new PaymentFailuresGuard())->aroundHandle($subject, $proceed, $id, 'Declined'));
    }

    public function testPositiveIdPreservesArgumentsAndReturnValue(): void
    {
        $subject = $this->createStub(PaymentFailuresInterface::class);
        $result = $this->createStub(PaymentFailuresInterface::class);
        $calls = [];
        $proceed = static function (...$args) use (&$calls, $result): PaymentFailuresInterface {
            $calls[] = $args;
            return $result;
        };
        $guard = new PaymentFailuresGuard();
        self::assertSame($result, $guard->aroundHandle($subject, $proceed, 1, 'Declined'));
        self::assertSame($result, $guard->aroundHandle($subject, $proceed, 42, 'Other gateway', 'multishipping'));
        self::assertSame([[1, 'Declined', 'onepage'], [42, 'Other gateway', 'multishipping']], $calls);
    }

    public function testPositiveMissingCartExceptionPropagates(): void
    {
        $subject = $this->createStub(PaymentFailuresInterface::class);
        $error = new NoSuchEntityException(__('Missing cart'));
        $proceed = static function () use ($error): void {
            throw $error;
        };
        $this->expectExceptionObject($error);
        (new PaymentFailuresGuard())->aroundHandle($subject, $proceed, 42, 'Declined');
    }
}
