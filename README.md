# Magetu_PaypalPaymentFailureGuard

Blocks Payflow GraphQL payment-failure notifications for carts with no eligible, enabled payment method. Also skips notification handling when a caller supplies a cart ID below 1.

## Changes in this fork

- Authorize the cart through Magento before inspecting its payment method.
- Require an allowed method that is enabled in the resulting cart's store, including Payments Pro and vault/provider handling.
- Include the invalid-cart guard proposed in upstream PR #1.
- Preserve core input errors and valid payment response handling; keep positive-cart notification calls unchanged.
- Add regression tests and installation/checkout test instructions.

See the [change log](CHANGELOG.md) for the summary and [implementation review](docs/REVIEW.md) for the reasoning and tradeoffs.

## Why

Core `Magento_PaypalGraphQl` can call the merchant payment-failure notification service when a caller supplies a declined response for a guest cart with no selected payment method. This can produce repeated **Payment Transaction Failed** emails without a real payment attempt.

The session-based `/paypal/transparent/response/` controller has a separate failure path: when its session has no cart, it can call the notification service with cart ID `0`. The service throws before sending mail. The invalid-ID guard from [upstream PR #1](https://github.com/Magetu/module-paypal-payment-failure-guard/pull/1) avoids that specific lookup, exception and report-generation path.

## How it works

The GraphQL `beforeResolve` plugin:

1. Leaves missing/empty input handling to the original resolver.
2. Calls Magento's `GetCartForUser` **before reading payment state**, using the caller and store from the GraphQL context. Core retains responsibility for cart ownership, active status and website/store rules.
3. Requires a selected method in the allowlist: `payflowpro` or `payflowpro_cc_vault` by default.
4. Uses the payment method's native `isActive()` check in the authorized cart's store. Payflow Pro also supports the `paypal_payment_pro` configuration alias; vault payments require both an active provider and the vault setting.
5. Allows core to handle the response, including its existing payment validation, success result and decline notification.

Rejected payment contexts return `Transaction has been declined.` before the resolver's notification block. Cart-access errors retain Magento's existing error type/message.

A global `aroundHandle` plugin on `Magento\Sales\Api\PaymentFailuresInterface` returns immediately for cart IDs below 1. Positive IDs, messages, checkout types, return values and exceptions pass through unchanged, including notifications from other payment gateways.

## Behavior and limits

- A saved Payflow method is rejected if its provider has since been disabled in the checkout store. This also applies to an in-flight response after an administrator disables that provider.
- Same-website store changes follow core's `GetCartForUser` behavior; the enabled check uses the resulting cart store. Cross-website changes are rejected by core. `GetCartForUser` may update cart currency/store as it already does in the resolver. Allowed requests call that helper again when core runs.
- The guard uses `isActive()`, not checkout-time `isAvailable()`. It does not re-run shipping, amount, country or other selection restrictions during a payment response.
- **Selecting an enabled method is not proof of a genuine gateway response.** This module does not authenticate callbacks, add payment-attempt correlation, deduplicate retries, or provide rate limiting. Repeated declined payloads against an eligible cart can still reach core's notification service.
- The global guard does not suppress positive-cart notifications. It does not block all requests or all errors on the transparent response controller.
- This is notification hardening, **not a StyleSmuggler security patch or malware removal tool**. Apply Adobe/Sansec security updates and investigate any existing compromise separately.

## Configuration

The method allowlist remains configurable through `etc/graphql/di.xml` in a customization module loaded after this one. Only add a method whose response protocol is compatible with this Payflow resolver:

```xml
<type name="Magetu\PaypalPaymentFailureGuard\Plugin\Resolver\PayflowProResponseGuard">
    <arguments>
        <argument name="allowedPaymentMethods" xsi:type="array">
            <item name="custom_transparent" xsi:type="string">custom_transparent</item>
        </argument>
    </arguments>
</type>
```

An added method must also pass its native store-scoped `isActive()` check. Empty method codes are always refused. No database schema, cron jobs, configuration settings or persistent notification state are added.

## Install and local checkout testing

Install **this fork/branch** using your project's normal Composer repository workflow, or copy it into `app/code/Magetu/PaypalPaymentFailureGuard` in a disposable local Magento installation. Keep only one registered copy of the module. A copy in `app/code` must be refreshed after changes in the fork; it is not a symlink.

Then enable it and run the normal local module upgrade/compilation steps for that installation. The plugin constructor changed, so deployments using compiled dependency injection must regenerate that code.

See [the local validation guide](docs/LOCAL-VALIDATION.md) for DDEV startup, installation/update commands, local mail capture, repeatable HTTP checks, manual checkout steps and rollback. Installing another branch or release does not establish that it contains this fork's changes.

## Validation status — 2026-09-15

Tested against Magento Open Source **2.4.7-p10**:

| Check | Result |
| --- | --- |
| Isolated regression suite | 48 tests passed on PHP 8.2/8.3; PHPUnit 9.6.33, 10.5.62 and 12.5.8 combinations are listed in the review |
| Installation in local DDEV `m2b2b` | Module enabled, setup upgrade completed, database status up to date |
| Magento's merged GraphQL/global configuration | Both guard plugins present at runtime |
| Three empty-cart decline requests over HTTP | Decline errors; captured mail count unchanged |
| Unknown cart over HTTP | Core cart-access error retained |
| Session-less transparent response over HTTP | HTTP 200; captured mail count unchanged |
| Magento email transport | One synthetic control message captured in Mailpit |
| Storefront, main stylesheet and admin login page | HTTP 200 |
| Normal customer checkout and order placement | Still requires manual testing |
| Real Payflow sandbox success, decline and saved-card checkout | Not run; Payflow is disabled in the local store |

The HTTP checks used the installed module and a local database. The isolated suite uses test doubles for storage, gateways and email. Neither result establishes that a real Payflow transaction has succeeded. [Validation details](docs/REVIEW.md#validation)

## Automated tests

From this module directory, with Magento dependencies and PHPUnit available:

```bash
MAGENTO_VENDOR_PATH=/path/to/magento/vendor php /path/to/phpunit.phar \
  --no-configuration --bootstrap Test/bootstrap.php --do-not-cache-result Test
```

When this module has its own dependencies installed, `composer test` also runs the suite.

The tests cover denied notifications, allowed successes/declines, cart authorization, native Payflow/Payments Pro/vault activation, store switching, input errors, invalid cart IDs, and generated Magento interceptor calls. Storage, gateway and mail boundaries are replaced with test doubles. The bootstrap loads installed classes without bootstrapping Magento's application. Interceptor tests generate temporary files in the system temp directory and remove them afterward.

Automated tests and the completed HTTP checks do not replace full checkout testing with the site's theme, extensions and payment gateway. Follow the [remaining checkout tests](docs/LOCAL-VALIDATION.md#manual-checkout-tests) before treating those flows as verified.

## Requirements

Declared Composer ranges: PHP **8.1–8.5**, Magento framework **103.x**, Quote **101.2+ within 101.x**, QuoteGraphQl **100.4+ within 100.x**, PayPal **101.x**, PayPalGraphQl **100.4+ within 100.x**, Sales **103.x**.

The test suite supports **PHPUnit 9.6.33+, 10.5.62+, or 12.5.8+** in their respective major versions. PHPUnit 12 requires PHP 8.3+. Compatibility ranges are not a claim that every Magento/PHP combination has been tested; check the review notes and your Magento release's own PHP requirements.

## License

MIT. Based on Magetu's original guard and the invalid-cart guard proposed in upstream PR #1.
