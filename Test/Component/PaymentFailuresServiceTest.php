<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Component;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Service\PaymentFailuresService;
use Magetu\PaypalPaymentFailureGuard\Plugin\Service\PaymentFailuresGuard;
use Magetu\PaypalPaymentFailureGuard\Test\Support\InterceptedSubject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PaymentFailuresServiceTest extends TestCase
{
    private function service(CartRepositoryInterface $repository, StateInterface $inline): PaymentFailuresService
    {
        $transport = $this->createMock(TransportBuilder::class);
        $transport->expects(self::never())->method('getTransport');
        return new PaymentFailuresService(
            $this->createStub(ScopeConfigInterface::class),
            $inline,
            $transport,
            $this->createStub(TimezoneInterface::class),
            $repository,
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testZeroCartSkipsActualServiceBeforeTranslationAndQuoteLookup(): void
    {
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->expects(self::never())->method('get');
        $inline = $this->createMock(StateInterface::class);
        $inline->expects(self::never())->method('suspend');
        $service = $this->service($repository, $inline);
        self::assertSame($service, (new PaymentFailuresGuard())->aroundHandle($service, [$service, 'handle'], 0, 'Declined'));
    }

    public function testUnguardedZeroCartThrowsBeforeEmail(): void
    {
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->expects(self::once())->method('get')->with(0)->willThrowException(new NoSuchEntityException());
        $inline = $this->createMock(StateInterface::class);
        $inline->expects(self::once())->method('suspend');
        $service = $this->service($repository, $inline);
        $this->expectException(NoSuchEntityException::class);
        $service->handle(0, 'Declined');
    }

    public function testGeneratedAroundInterceptorSkipsZeroAndForwardsPositiveId(): void
    {
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->expects(self::once())->method('get')->with(42)->willThrowException(new NoSuchEntityException());
        $inline = $this->createMock(StateInterface::class);
        $inline->expects(self::once())->method('suspend');
        $plugins = $this->createStub(PluginListInterface::class);
        $plugins->method('getPlugin')->willReturn(new PaymentFailuresGuard());
        $plugins->method('getNext')->willReturnCallback(static function ($type, $method, $code = '__self') {
            return $code === null || $code === '__self' ? [DefinitionInterface::LISTENER_AROUND => 'guard'] : null;
        });
        $service = InterceptedSubject::wrap($this->service($repository, $inline), $plugins);
        self::assertSame($service, $service->handle(0, 'Declined'));
        $this->expectException(NoSuchEntityException::class);
        $service->handle(42, 'Declined', 'multishipping');
    }
}
