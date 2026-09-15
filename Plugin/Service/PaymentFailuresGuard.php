<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Plugin\Service;

use Magento\Sales\Api\PaymentFailuresInterface;

/**
 * Skip notification handling for IDs that cannot identify a cart (upstream PR #1).
 *
 * The transparent response controller can pass 0 when its session has no quote. Core would
 * suspend translation and throw on the quote lookup before sending any email. That exception
 * escapes because the service is called inside the controller's catch block. Positive IDs and
 * their errors are left to core; payment eligibility is only guarded in the Payflow resolver.
 */
class PaymentFailuresGuard
{
    /**
     * @param PaymentFailuresInterface $subject
     * @param callable $proceed
     * @param int $cartId
     * @param string $errorMessage
     * @param string $checkoutType
     * @return PaymentFailuresInterface
     */
    public function aroundHandle(
        PaymentFailuresInterface $subject,
        callable $proceed,
        int $cartId,
        string $errorMessage,
        string $checkoutType = 'onepage'
    ): PaymentFailuresInterface {
        if ($cartId < 1) {
            return $subject;
        }

        return $proceed($cartId, $errorMessage, $checkoutType);
    }
}
