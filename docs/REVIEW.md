# Hardening review

## Source and scope

- Starting point: upstream main commit `2d6fa4a2e1ebe1ea08bb4763a3175100342939a5`.
- Incorporates the behavior proposed in [PR #1](https://github.com/Magetu/module-paypal-payment-failure-guard/pull/1), reviewed at `b93f0cc5af634219d1e746ae33449ae5791a4bbf`.
- Local implementation review: 2026-09-15, against installed Magento Open Source 2.4.7-p10 sources.

## Changes

1. Use core `GetCartForUser` before inspecting payment state. This preserves Magento's ownership, active-cart, website and store-handling decisions. Unknown/inaccessible carts no longer produce payment-dependent errors from the guard.
2. Read payment state from that authorized quote and require an allowlisted method. Refuse an empty code even if a customization mistakenly puts it in the allowlist.
3. Use the selected method's native `isActive()` in the resulting quote store. This handles the Payments Pro alias and vault/provider dependency without duplicating Magento's configuration rules. `Quote\Payment::getMethodInstance()` sets the method's store first.
4. Skip `PaymentFailuresInterface::handle()` only for IDs below 1. Positive-cart calls remain unchanged across gateways.
5. Match core's empty-input handling, including string `"0"`. Keep operational exceptions visible rather than broadly swallowing them.
6. Add direct QuoteGraphQl/Sales dependencies, regression tests, supported PHPUnit metadata, and local checkout instructions.

## Decisions and remaining limits

The enabled check intentionally stops a response after an administrator disables the method, even if the cart selected it earlier. This closes the stale-method case; merchants changing payment settings during checkout should account for in-flight attempts.

Allowed requests call `GetCartForUser` in the guard and again in core. The helper may save a store/currency adjustment. Reusing core's policy avoids copying authorization logic or replacing the resolver, at the cost of an extra helper call. Tests cover same-website changes and cross-website rejection. Core's ordering of currency handling and ownership validation is preserved.

A payload remains caller-controlled after the eligibility check. Gateway authentication, attempt correlation and deduplication require a separate design spanning token issuance, responses and retries. The regression suite explicitly records that repeated declines on an enabled-method cart retain core's notification behavior. No unverifiable token-presence check or global email suppression has been introduced.

`NoSuchEntityException` extends `LocalizedException`. In the session-less controller path, it escapes because it is thrown inside the existing catch block's call to the notification service. The invalid-cart guard fixes that specific exception path; it does not guarantee the entire controller will be error-free.

Notification protection does not remove malware or replace vendor security patches. No incident-specific customer records, payloads, credentials or host details are part of this module.

## Validation

### Isolated regression suite

The suite uses real local Magento implementations for cart access, currency/store handling, method activation, response validation and notification service control flow, with fake storage, gateway and mail boundaries.

| PHP | PHPUnit | Result |
| --- | --- | --- |
| 8.2 | 9.6.33 | 48 tests, 208 assertions passed |
| 8.3.27 | 9.6.33 | 48 tests, 208 assertions passed |
| 8.3.27 | 10.5.62 | 48 tests, 208 assertions passed |
| 8.3.27 | 12.5.8 | 48 tests, 214 assertions passed |

The isolated suite runs used disposable containers with networking disabled and read-only source mounts. No application database, gateway or real email transport was used in those runs. The differing assertion totals are PHPUnit accounting differences for the same 48 tests. PHPUnit 12 was also run with notices treated as failures.

Additional checks passed: PHP syntax for all 12 PHP files; Magento XSD validation for global DI, GraphQL DI and module XML; Composer manifest validation; `git diff --check`; and Magento2 coding-standard checks for the production plugin classes (zero source errors/warnings). The installed coding standard itself reports a deprecated sniff; that tool-level notice is unrelated to module code.

Magento packages used: `module-paypal` 101.0.7-p10, `module-paypal-graph-ql` 100.4.5, `module-quote` 101.2.7-p9, `module-quote-graph-ql` 100.4.7-p8 and `module-sales` 103.0.7-p8. Other Magento/Mage-OS releases and PHP 8.1/8.4/8.5 are not established by this matrix.

Generated-interceptor tests execute Magento-generated methods and the real interception trait. They supply a controlled plugin list and dependency state, so those tests alone do not establish behavior with a site's complete merged DI configuration. The installed-module checks below cover the tested local application's plugin configuration and HTTP paths.

### Installed-module checks in DDEV

On 2026-09-15, the fork was copied into `app/code/Magetu/PaypalPaymentFailureGuard` in the local `m2b2b` project. DDEV was started, the module was enabled, and `setup:upgrade` completed. Magento reported all modules up to date. The project runs in developer mode, with generated interception created on demand.

| Check | Observed result |
| --- | --- |
| Merged GraphQL plugin list | `magetu_payflow_pro_response_guard` present alongside the site's other resolver plugins |
| Merged global service plugin list | `magetu_payment_failures_guard` present; the resolved payment-failure service was an interceptor |
| Three declined responses for a newly created empty guest cart | Each returned `Transaction has been declined.`; no additional mail |
| Unknown cart mask | Core cart-access error retained |
| Session-less transparent-response controller with a declined response | HTTP 200; no additional mail |
| Mailpit count during the HTTP checks | Unchanged at 0 |
| Separate synthetic message through Magento's mail transport | Captured in Mailpit, confirming that local delivery worked |
| Storefront, main stylesheet and admin login page | HTTP 200 |

The HTTP checks used ordinary decline data and did not initiate a gateway transaction. The test created an empty local guest cart. The control message contained synthetic addresses and text.

A local database snapshot was taken before the module upgrade. External Mageplaza SMTP was disabled with an `app/etc/env.php` override, and effective runtime configuration was verified. The original database SMTP setting was preserved. This was a local test-environment change, not functionality added to the module. Production was not accessed or modified during installation or validation.

### Still to test

- A complete customer checkout/order using the site's theme and enabled offline method.
- Real Payflow sandbox success and decline, including saved-card checkout. Payflow Pro, Payments Pro and vault are disabled in the tested local store.
- Full application behavior for stale-method carts, inactive/other-customer carts and store switching. These are covered by isolated tests but have not all been exercised through the installed application's checkout.
- Other gateway notification behavior in a complete checkout flow.

See [local validation steps](LOCAL-VALIDATION.md) for repeatable HTTP checks, expected results and the remaining checkout matrix.

## Reference flow

Adobe documents the token and response steps in [createPayflowProToken](https://developer.adobe.com/commerce/webapi/graphql/schema/checkout/mutations/create-payflow-pro-token) and [handlePayflowProResponse](https://developer.adobe.com/commerce/webapi/graphql/schema/checkout/mutations/handle-payflow-pro-response). The guard preserves the existing resolver flow for eligible payment contexts; it does not establish the authenticity of the client-supplied response.
