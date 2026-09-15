<?php

declare(strict_types=1);

namespace Magetu\PaypalPaymentFailureGuard\Test\Support;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;

/** Use real quote data access while replacing its database-backed payment collection. */
final class QuoteFixture extends Quote
{
    public int $paymentReadCount = 0;
    private ?Payment $fixturePayment = null;

    public function __construct()
    {
        // The exercised quote data accessors need no application services.
    }

    public function seedPayment(Payment $payment): void
    {
        $this->fixturePayment = $payment;
    }

    public function getPayment()
    {
        $this->paymentReadCount++;
        return $this->fixturePayment;
    }
}
