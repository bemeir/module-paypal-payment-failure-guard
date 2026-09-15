<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Component;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\Parameters;
use Magento\Directory\Model\Currency;
use Magento\Payment\Gateway\Config\Config;
use Magento\Paypal\Model\Payflow\Service\Response\Transaction;
use Magento\Paypal\Model\Payflow\Service\Response\Validator\ResponseValidator;
use Magento\Paypal\Model\Payflow\Transparent;
use Magento\PaypalGraphQl\Model\Resolver\PayflowProResponse;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\QuoteGraphQl\Model\Cart\IsActive;
use Magento\QuoteGraphQl\Model\Cart\UpdateCartCurrency;
use Magento\Sales\Api\PaymentFailuresInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use Magento\Vault\Model\Method\Vault;
use Magetu\PaypalPaymentFailureGuard\Plugin\Resolver\PayflowProResponseGuard;
use Magetu\PaypalPaymentFailureGuard\Test\Support\InterceptedSubject;
use Magetu\PaypalPaymentFailureGuard\Test\Support\PaymentFixture;
use Magetu\PaypalPaymentFailureGuard\Test\Support\QuoteFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Execute the real cart authorization, store handling, method activation, response validator and
 * resolver. Storage, transport and gateway boundaries are mocked; there is no application bootstrap.
 */
class PayflowResponseTest extends TestCase
{
    private QuoteFixture $quote;
    private PaymentFixture $payment;
    private PayflowProResponse $resolver;
    private PayflowProResponseGuard $guard;
    private DataObject $context;
    private array $enabled = [1 => ['payflowpro' => true], 2 => ['payflowpro' => false]];
    private array $notifications = [];
    private array $configStores = [];
    private int $responses = 0;
    private int $paymentSaves = 0;
    private int $quoteSaves = 0;
    private int $cartLoads = 0;
    private bool $knownCart = true;

    protected function setUp(): void
    {
        $this->quote = new QuoteFixture();
        $this->quote->setData([
            'entity_id' => 42, 'store_id' => 1, 'customer_id' => 0,
            'is_active' => true, 'quote_currency_code' => 'USD',
        ]);
        $this->quote->setId(42);
        $this->payment = new PaymentFixture($this->quote);
        $this->payment->setMethod('payflowpro');
        $this->quote->seedPayment($this->payment);

        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(function ($path, $scope, $storeId) {
            self::assertSame(ScopeInterface::SCOPE_STORE, $scope);
            $this->configStores[] = (int)$storeId;
            $code = explode('/', $path)[1];
            return (int)($this->enabled[(int)$storeId][$code] ?? false);
        });
        $provider = $this->getMockBuilder(Transparent::class)->disableOriginalConstructor()
            ->onlyMethods(['isAvailable'])->getMock();
        $provider->expects(self::never())->method('isAvailable');
        $property = new \ReflectionProperty($provider, '_scopeConfig');
        $property->setValue($provider, $config);
        $this->payment->setMethodInstance($provider);

        // Real Vault::isActive() uses the real Payflow provider and real Config's store lookup.
        $vault = (new \ReflectionClass(Vault::class))->newInstanceWithoutConstructor();
        foreach (['vaultProvider' => $provider, 'config' => new Config($config, 'payflowpro_cc_vault')] as $key => $value) {
            (new \ReflectionProperty(Vault::class, $key))->setValue($vault, $value);
        }
        $this->vault = $vault;

        $masked = $this->createStub(MaskedQuoteIdToQuoteIdInterface::class);
        $masked->method('execute')->willReturnCallback(function (): int {
            $this->cartLoads++;
            if (!$this->knownCart) {
                throw new NoSuchEntityException();
            }
            return 42;
        });
        $repository = $this->createStub(CartRepositoryInterface::class);
        $repository->method('get')->willReturn($this->quote);
        $repository->method('save')->willReturnCallback(function (): void {
            $this->quoteSaves++;
        });
        $currency = $this->createStub(Currency::class);
        $currency->method('getCode')->willReturn('USD');
        $website = $this->createStub(Website::class);
        $otherWebsite = $this->createStub(Website::class);
        $stores = [];
        foreach ([1, 2, 3] as $storeId) {
            $store = $this->createStub(Store::class);
            $store->method('getCurrentCurrency')->willReturn($currency);
            $store->method('getWebsite')->willReturn($storeId === 3 ? $otherWebsite : $website);
            $stores[$storeId] = $store;
        }
        $storeRepository = $this->createStub(StoreRepositoryInterface::class);
        $storeRepository->method('getById')->willReturnCallback(static fn($id) => $stores[(int)$id]);
        $getCart = new GetCartForUser($masked, $repository, new IsActive(), new UpdateCartCurrency($repository, $storeRepository));
        $this->guard = new PayflowProResponseGuard($getCart, ['payflowpro', 'payflowpro_cc_vault']);

        $failures = $this->createStub(PaymentFailuresInterface::class);
        $failures->method('handle')->willReturnCallback(function (...$args) use ($failures): PaymentFailuresInterface {
            $this->notifications[] = $args;
            return $failures;
        });
        $transaction = $this->createStub(Transaction::class);
        $transaction->method('getResponseObject')->willReturnCallback(function (array $data): DataObject {
            $this->responses++;
            return new DataObject(['result' => (int)($data['RESULT'] ?? -1)]);
        });
        $transaction->method('savePaymentInQuote')->willReturnCallback(function ($response, $id): void {
            self::assertEquals(42, $id);
            $this->paymentSaves++;
        });
        $this->resolver = new PayflowProResponse(
            $transaction, new ResponseValidator([]), $failures, new Json(), $provider, $getCart,
            new Parameters(new \Laminas\Stdlib\Parameters()), $this->createStub(DataObjectFactory::class)
        );
        $this->context = new DataObject([
            'user_id' => null,
            'extension_attributes' => new DataObject(['store' => new DataObject(['id' => 1])]),
        ]);
    }

    private Vault $vault;

    private function resolve(bool $guarded = true, string $payload = 'RESULT=12&RESPMSG=Declined'): array
    {
        $field = $this->createStub(Field::class);
        $info = $this->createStub(ResolveInfo::class);
        $args = ['input' => ['cart_id' => 'test-mask', 'paypal_payload' => $payload]];
        if ($guarded) {
            $this->guard->beforeResolve($this->resolver, $field, $this->context, $info, null, $args);
        }
        return $this->resolver->resolve($field, $this->context, $info, null, $args);
    }

    private function decline(bool $guarded = true): void
    {
        try {
            $this->resolve($guarded);
            self::fail('Expected a declined response.');
        } catch (GraphQlInputException $e) {
            self::assertStringContainsString('Transaction has been declined', $e->getMessage());
        }
        self::assertSame(0, $this->paymentSaves);
    }

    public function testBaselineNoPaymentReachesCoreNotification(): void
    {
        $this->payment->setMethod(null);
        $this->decline(false);
        self::assertCount(1, $this->notifications);
    }

    public static function blockedMethods(): array
    {
        return [[''], ['checkmo']];
    }

    /** @dataProvider blockedMethods */
    #[DataProvider('blockedMethods')]
    public function testNoPaymentAndUnrelatedPaymentCannotReachNotification(string $code): void
    {
        $this->payment->setMethod($code);
        $this->decline();
        self::assertSame([], $this->notifications);
        self::assertSame(0, $this->responses);
        self::assertSame(1, $this->cartLoads);
    }

    public static function activationCases(): array
    {
        return [
            'payflow enabled' => ['payflowpro', true, false, false, true],
            'payments pro alias enabled' => ['payflowpro', false, true, false, true],
            'stale disabled method' => ['payflowpro', false, false, false, false],
            'vault enabled with provider' => ['payflowpro_cc_vault', true, false, true, true],
            'vault with payments pro provider' => ['payflowpro_cc_vault', false, true, true, true],
            'vault provider disabled' => ['payflowpro_cc_vault', false, false, true, false],
            'vault switch disabled' => ['payflowpro_cc_vault', true, false, false, false],
        ];
    }

    /** @dataProvider activationCases */
    #[DataProvider('activationCases')]
    public function testNativeStoreAndVaultActivation(
        string $code,
        bool $payflow,
        bool $paymentsPro,
        bool $vault,
        bool $allowed
    ): void {
        $this->enabled[1] = ['payflowpro' => $payflow, 'paypal_payment_pro' => $paymentsPro, 'payflowpro_cc_vault' => $vault];
        $this->payment->setMethod($code);
        if ($code === 'payflowpro_cc_vault') {
            $this->payment->setMethodInstance($this->vault);
        }
        $this->decline();
        self::assertCount($allowed ? 1 : 0, $this->notifications);
        self::assertSame($allowed ? 1 : 0, $this->responses);
        self::assertSame([1], array_values(array_unique($this->configStores)));
    }

    public static function unauthorizedUsers(): array
    {
        return ['guest vs customer cart' => [19, null], 'other customer' => [19, 20], 'customer vs guest cart' => [0, 19]];
    }

    /** @dataProvider unauthorizedUsers */
    #[DataProvider('unauthorizedUsers')]
    public function testUnauthorizedCartIsRejectedBeforePaymentInspection(int $owner, ?int $caller): void
    {
        $this->quote->setCustomerId($owner);
        $this->context->setUserId($caller);
        $this->expectException(GraphQlAuthorizationException::class);
        try {
            $this->resolve();
        } finally {
            self::assertSame(0, $this->quote->paymentReadCount);
            self::assertSame([], $this->notifications);
            self::assertSame([], $this->configStores);
        }
    }

    public function testOwningCustomerRetainsDeclineNotification(): void
    {
        $this->quote->setCustomerId(19);
        $this->context->setUserId(19);
        $this->decline();
        self::assertCount(1, $this->notifications);
    }

    public function testInactiveCartPreservesCoreError(): void
    {
        $this->quote->setIsActive(false);
        $this->expectException(GraphQlNoSuchEntityException::class);
        $this->expectExceptionMessage("The cart isn't active.");
        try {
            $this->resolve();
        } finally {
            self::assertSame(0, $this->quote->paymentReadCount);
            self::assertSame([], $this->notifications);
        }
    }

    public function testUnknownMaskPreservesCoreError(): void
    {
        $this->knownCart = false;
        $this->expectException(GraphQlNoSuchEntityException::class);
        try {
            $this->resolve();
        } finally {
            self::assertSame(0, $this->quote->paymentReadCount);
            self::assertSame([], $this->notifications);
        }
    }

    public function testDifferentWebsiteIsRejectedBeforePaymentInspection(): void
    {
        $this->context->getExtensionAttributes()->getStore()->setId(3);
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage("Can't assign cart to store in different website.");
        try {
            $this->resolve();
        } finally {
            self::assertSame(0, $this->quote->paymentReadCount);
            self::assertSame([], $this->notifications);
            self::assertSame(0, $this->quoteSaves);
        }
    }

    public function testSameWebsiteStoreSwitchUsesNewStoresDisabledSetting(): void
    {
        $this->context->getExtensionAttributes()->getStore()->setId(2);
        $this->decline();
        self::assertSame(2, $this->quote->getStoreId());
        self::assertSame(1, $this->quoteSaves);
        self::assertSame([2], array_values(array_unique($this->configStores)));
        self::assertSame([], $this->notifications);
    }

    public function testSuccessfulResponsePreservesCoreResultAndPaymentSave(): void
    {
        $result = $this->resolve(true, 'RESULT=0&RESPMSG=Approved');
        self::assertSame(['cart' => ['model' => $this->quote]], $result);
        self::assertSame(1, $this->paymentSaves);
        self::assertSame([], $this->notifications);
    }

    public function testEmptyPayloadKeepsCoreInputErrorWithoutLoadingCart(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Required parameter "paypal_payload" is missing.');
        try {
            $this->resolve(true, '0');
        } finally {
            self::assertSame(0, $this->cartLoads);
            self::assertSame([], $this->notifications);
        }
    }

    public function testRepeatedDeclinesOnEnabledMethodRemainCoreBehavior(): void
    {
        $this->decline();
        $this->decline();
        self::assertCount(2, $this->notifications, 'This guard does not authenticate responses or deduplicate retries.');
    }

    public function testGeneratedBeforeInterceptorBlocksNoPaymentAndPreservesEnabledDecline(): void
    {
        $plugins = $this->createStub(PluginListInterface::class);
        $plugins->method('getNext')->willReturn([DefinitionInterface::LISTENER_BEFORE => ['guard']]);
        $plugins->method('getPlugin')->willReturn($this->guard);
        $this->resolver = InterceptedSubject::wrap($this->resolver, $plugins);
        $this->payment->setMethod('');
        $this->decline(false);
        self::assertSame([], $this->notifications);
        self::assertSame(1, $this->cartLoads);
        $this->payment->setMethod('payflowpro');
        $this->decline(false);
        self::assertCount(1, $this->notifications);
    }
}
