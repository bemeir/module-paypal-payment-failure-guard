<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Plugin\Resolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\PaypalGraphQl\Model\Resolver\PayflowProResponse;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

/**
 * Gate the PayflowPro merchant "payment failed" notification behind a genuine Payflow payment context.
 *
 * Core PayflowProResponse::resolve() loads the cart from the caller-supplied masked cart_id (not the
 * session) and, on any validation failure of paypal_payload, calls PaymentFailuresInterface::handle(),
 * which emails the merchant. An unauthenticated caller can therefore make the store email itself by
 * pointing a garbage payload at an empty cart created via createEmptyCart, with no payment ever selected
 * on that cart. The legitimate Payflow Pro GraphQL flow always selects a Payflow method
 * (setPaymentMethodOnCart, then createPayflowProToken) before this resolver runs, so the request is
 * refused before the resolver runs unless the cart's selected payment method is a Payflow method. A
 * genuine Payflow decline still carries a Payflow method and still notifies.
 */
class PayflowProResponseGuard
{
    /**
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     * @param PaymentMethodManagementInterface $paymentMethodManagement
     * @param string[] $allowedPaymentMethods
     */
    public function __construct(
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly PaymentMethodManagementInterface $paymentMethodManagement,
        private readonly array $allowedPaymentMethods = []
    ) {
    }

    /**
     * Refuse the resolver before it runs unless the cart is in a genuine Payflow payment context.
     *
     * @param PayflowProResponse $subject
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return void
     * @throws GraphQlInputException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeResolve(
        PayflowProResponse $subject,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): void {
        $maskedCartId = (string)($args['input']['cart_id'] ?? '');
        $paypalPayload = (string)($args['input']['paypal_payload'] ?? '');

        // Let the resolver own its own input validation and cart-not-found errors; only guard a
        // well-formed request, which is the only shape that can reach the notification in core.
        if ($maskedCartId === '' || $paypalPayload === '') {
            return;
        }

        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedCartId);
            $payment = $this->paymentMethodManagement->get($quoteId);
        } catch (NoSuchEntityException $e) {
            // An invalid cart never reaches the notification in core either, so let the resolver throw.
            return;
        }

        // PaymentMethodManagement::get() returns null (it does not throw) for a quote with no payment,
        // so the empty-cart abuse lands here: an empty method fails the allow-list and is refused. The
        // NoSuchEntityException catch above only covers a genuinely missing cart.
        $method = ($payment !== null) ? (string)$payment->getMethod() : '';
        if (in_array($method, $this->allowedPaymentMethods, true)) {
            return;
        }

        // No genuine Payflow payment selected on this cart, so refuse before the resolver runs and it
        // never reaches the merchant notification.
        throw new GraphQlInputException(__('Transaction has been declined.'));
    }
}
