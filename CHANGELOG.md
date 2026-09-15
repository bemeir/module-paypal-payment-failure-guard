# Change log

## Unreleased — hardening and local validation, 2026-09-15

### Changed

- Resolve and authorize the cart using core `GetCartForUser` before reading payment state.
- Read payment state from the authorized quote, retain the method allowlist, and always reject an empty method code.
- Check the selected method's native store-scoped `isActive()` behavior. This supports the Payments Pro alias and requires both vault and provider activation for saved cards.
- Match core's empty-input handling, including the string `"0"`; propagate cart-access and operational errors.
- Correct the documentation's claims about protection, exception handling, compatibility and test coverage.

### Added

- The invalid-cart notification guard proposed in [upstream PR #1](https://github.com/Magetu/module-paypal-payment-failure-guard/pull/1). IDs below 1 return before notification handling; positive IDs pass through.
- Direct `Magento_QuoteGraphQl` and `Magento_Sales` dependencies and module sequencing.
- A 48-case regression suite covering guard behavior, core authorization, store switching, native payment activation, notification calls and generated interception.
- Reproducible local installation, HTTP verification, mail capture and checkout testing instructions.

### Validation

- Isolated tests passed on PHP 8.2/8.3 and the tested PHPUnit 9.6/10.5/12.5 versions. See the [exact matrix](docs/REVIEW.md#isolated-regression-suite).
- Local Magento 2.4.7-p10 installation and merged plugin configuration were verified.
- Empty-cart declines, unknown-cart handling and the session-less transparent response passed HTTP checks. No messages were added by those requests.
- A separate synthetic Magento email was captured in Mailpit. Storefront, CSS and admin login pages returned HTTP 200.
- Normal order placement and real Payflow sandbox flows remain to be tested.

### Operational notes

Disabling a payment provider also rejects an in-flight response for a cart that previously selected it. Allowed requests call the core cart helper in both the guard and resolver; core store/currency behavior is retained.

This module does not authenticate gateway responses, correlate payment attempts, deduplicate notifications, rate-limit requests, patch StyleSmuggler or remove malware. An eligible cart can still generate repeated decline notifications.

The DDEV installation used a separate local SMTP override to direct mail into Mailpit. That environment setting is not part of the module and is not applied when someone installs this package.
