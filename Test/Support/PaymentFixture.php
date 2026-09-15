<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Support;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;

/** Keep the real method-instance and store assignment logic without loading a quote from storage. */
final class PaymentFixture extends Payment
{
    public function __construct(private readonly Quote $fixtureQuote)
    {
    }

    public function getQuote()
    {
        return $this->fixtureQuote;
    }
}
