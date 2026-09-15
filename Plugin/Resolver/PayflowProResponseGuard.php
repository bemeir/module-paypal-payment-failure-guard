<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Plugin\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\PaypalGraphQl\Model\Resolver\PayflowProResponse;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;

/**
 * Require an authorized cart with an enabled Payflow method before processing a response.
 *
 * A selected method is a prerequisite, not proof that the payload came from the gateway.
 */
class PayflowProResponseGuard
{
    private readonly GetCartForUser $getCartForUser;

    /** @var string[] */
    private readonly array $allowedPaymentMethods;

    /**
     * @param GetCartForUser $getCartForUser
     * @param string[] $allowedPaymentMethods
     */
    public function __construct(
        GetCartForUser $getCartForUser,
        array $allowedPaymentMethods = []
    ) {
        $this->getCartForUser = $getCartForUser;
        $this->allowedPaymentMethods = $allowedPaymentMethods;
    }

    /**
     * Check cart access before inspecting payment state or entering core's notification path.
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
        // Match core's empty() checks, including the string "0". GraphQL validates scalar types.
        if (empty($args['input']['cart_id']) || empty($args['input']['paypal_payload'])) {
            return;
        }

        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();
        $cart = $this->getCartForUser->execute(
            $args['input']['cart_id'],
            $context->getUserId(),
            $storeId
        );

        // Use the authorized cart. GetCartForUser preserves core's ownership, active-cart and
        // website checks, including permitted same-website store/currency changes.
        $payment = $cart->getPayment();
        $method = $payment !== null ? (string)$payment->getMethod() : '';
        if ($method === '' || !in_array($method, $this->allowedPaymentMethods, true)) {
            throw new GraphQlInputException(__('Transaction has been declined.'));
        }

        // Quote\Payment::getMethodInstance() sets the quote's store on the method. Native isActive()
        // supports both Payflow Pro / Payments Pro configuration and the vault's provider + switch.
        // Do not use isAvailable(): this is a response to an existing attempt, not method selection.
        if (!$payment->getMethodInstance()->isActive((int)$cart->getStoreId())) {
            throw new GraphQlInputException(__('Transaction has been declined.'));
        }
    }
}
