# Magetu_PaypalPaymentFailureGuard

Stops an unauthenticated caller from making a Magento store email itself "payment failed" merchant notifications through core `Magento_PaypalGraphQl`, without disabling the module.

## Why

An unauthenticated caller can make a Magento store send itself the "Payment Transaction Failed" merchant notification, repeatedly, with no real payment and regardless of which gateway the store runs. Core `Magento_PaypalGraphQl` reaches that notification for a PayflowPro response without confirming the cart ever entered a genuine payment flow. It is low severity on its own (the store spams its own inbox, no code execution and no data exposure) and is present by default wherever `Magento_PaypalGraphQl` ships.

## Not a StyleSmuggler fix

This is not a fix or a mitigation for StyleSmuggler / CVE-2026-75650 / APSB26-146. StyleSmuggler produces the same "Payment Transaction Failed" email through a different, unrelated request, and on an unpatched store that path is unauthenticated remote code execution. Apply APSB26-146 first. A burst of these emails on an unpatched store can be a symptom of active exploitation, not this benign self-spam bug. This module does not touch the StyleSmuggler request.

## How it works

A single `before` plugin on the PayflowPro response resolver (`Magento\PaypalGraphQl\Model\Resolver\PayflowProResponse`), wired in `etc/graphql/di.xml`. It lets the resolver run only when the cart has a Payflow payment method selected (`payflowpro` or `payflowpro_cc_vault` by default). The legitimate Payflow Pro flow selects a method with `setPaymentMethodOnCart` before this resolver runs, so a real Payflow decline still carries a method and still notifies. The empty-cart case has no method, so it is refused with the same `Transaction has been declined.` error the caller would otherwise get, and no email is sent.

The allowed method codes are configurable, so a store on another transparent-redirect gateway that reuses this resolver can add its own:

```xml
<type name="Magetu\PaypalPaymentFailureGuard\Plugin\Resolver\PayflowProResponseGuard">
    <arguments>
        <argument name="allowedPaymentMethods" xsi:type="array">
            <item name="payflowpro" xsi:type="const">Magento\Paypal\Model\Config::METHOD_PAYFLOWPRO</item>
            <item name="payflowpro_cc_vault" xsi:type="const">Magento\Paypal\Model\Payflow\Transparent::CC_VAULT_CODE</item>
        </argument>
    </arguments>
</type>
```

## Install

```bash
composer require magetu/module-paypal-payment-failure-guard
bin/magento setup:upgrade
```

## Scope

- The guard's signal is the cart's selected payment method. A store that actually runs Payflow still notifies on genuine declines, as intended; only the no-payment abuse is closed.
- It does not touch the sibling web controller `Magento\Paypal\Controller\Transparent\Response`, which is session-derived and does not email a caller with no session. If Payflow is disabled on your store, deny that route at the web server as well.

## Tests

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php Test/Unit
```

Six unit tests cover both halves: the empty cart and any non-Payflow method are refused, a Payflow cart is allowed so a genuine decline still notifies, and a missing payload or unknown cart defers to the resolver's own handling.

## Requirements

Built for Adobe Commerce / Magento Open Source **2.4.6** and **Mage-OS**, **PHP 8.1 - 8.5**.

The unit suite (`Test/Unit/`) runs on **PHPUnit 9.6.33+, 10.5.62+, or 12.5.8+**. Note PHPUnit 12 itself requires PHP 8.3+.

## Issues and pull requests

Bugs and PRs are welcome on GitHub. Keep a PR focused, and include a failing test for any behavior change.

## License

MIT
