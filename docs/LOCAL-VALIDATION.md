# Local installation and checkout testing

Use a local Magento installation with captured outbound mail. Commands below run from the **Magento project directory**, not the patch repo, unless stated otherwise. The example uses sibling directories `m2b2b` and `module-paypal-payment-failure-guard`.

## Current local installation

As of 2026-09-15, this fork is installed and enabled in `m2b2b` at `app/code/Magetu/PaypalPaymentFailureGuard`. DDEV startup and `setup:upgrade` completed; Magento reported all modules up to date. The project uses developer mode and generates interception on demand.

- [Storefront](https://m2b2b.ddev.site/)
- [Admin](https://m2b2b.ddev.site/admin/)
- [Mailpit](https://m2b2b.ddev.site:8026/)

Mageplaza SMTP is disabled by a local environment override. Magento's effective configuration and the SMTP helper both reported it disabled. One synthetic message titled **Local PayPal guard: Mailpit transport check** confirmed delivery through Magento into Mailpit.

The HTTP checks below already passed. Normal customer order placement and real Payflow sandbox checkout remain to be tested. See the [recorded results](REVIEW.md#installed-module-checks-in-ddev).

## First installation in a local project

Skip this section for the already-installed `m2b2b` project unless rebuilding the local environment. If the module is Composer-installed, update it through that repository workflow instead of creating a second registered copy.

```bash
ddev start
# Choose a new snapshot name if this one already exists.
ddev snapshot --name before-paypal-guard-local
mkdir -p app/code/Magetu/PaypalPaymentFailureGuard
rsync -a --exclude=.git --exclude=vendor --exclude=composer.lock \
  --exclude=.phpunit.cache --exclude=.phpunit.result.cache \
  ../module-paypal-payment-failure-guard/ app/code/Magetu/PaypalPaymentFailureGuard/
ddev exec bin/magento module:enable Magetu_PaypalPaymentFailureGuard
ddev exec bin/magento setup:upgrade
ddev exec bin/magento cache:clean config
ddev exec bin/magento module:status Magetu_PaypalPaymentFailureGuard
ddev exec bin/magento setup:db:status
```

Expected: the module is enabled and all modules are up to date. In production mode, also run the installation's normal `setup:di:compile` deployment step. The guard constructor changed and compiled dependency injection must be regenerated.

### Refresh the installed copy after editing the fork

The `app/code` installation is a copy, not a symlink. From `m2b2b`:

```bash
rsync -a --exclude=.git --exclude=vendor --exclude=composer.lock \
  --exclude=.phpunit.cache --exclude=.phpunit.result.cache \
  ../module-paypal-payment-failure-guard/ app/code/Magetu/PaypalPaymentFailureGuard/
ddev exec bin/magento cache:clean config reflection compiled_config
```

This copies changed and new files; it does not remove files deleted in the fork. Review removed files when updating. Regenerate compiled DI when required, and run `setup:upgrade` if a later change introduces setup/schema work or new modules. For this documentation update, no Magento upgrade is needed.

## Confirm local email capture

DDEV's PHP sendmail transport points to Mailpit. An SMTP extension can bypass it. In this project's local environment, disable Mageplaza SMTP with:

```bash
ddev exec bin/magento config:set --lock-env smtp/general/enabled 0
ddev exec bin/magento cache:clean config
ddev exec php -r 'echo ini_get("sendmail_path"), PHP_EOL;'
```

The sendmail path should contain `mailpit` and `127.0.0.1:1025`. The environment override is separate from this module; installing the package does not change SMTP configuration. Check any store-level SMTP overrides before testing another store view. Keep Magento mail enabled so that lack of a notification actually tests the guard.

The following read-only runtime check prints the effective flags for each local store. A raw database value or `config:show` output alone may not reflect the local environment override.

```bash
ddev exec php <<'PHP'
<?php
require 'app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$scope = $om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStores();
foreach ($stores as $store) {
    printf(
        "%s: SMTP extension=%s, Magento mail disabled=%s\n",
        $store->getCode(),
        $scope->isSetFlag('smtp/general/enabled', 'store', $store->getId()) ? 'on' : 'off',
        $scope->isSetFlag('system/smtp/disable', 'store', $store->getId()) ? 'yes' : 'no'
    );
}
PHP
```

For these tests, expect `SMTP extension=off, Magento mail disabled=no`. Open Mailpit and record the message count. Use a quiet local environment so unrelated mail does not change the count during a test.

## Repeat the HTTP checks

These commands run inside the DDEV web container and send requests to its loopback interface. They use the example project's host header and HTTPS offload header. They do not follow redirects, start a gateway transaction, or include template-injection payloads. Adjust the host header for a differently named local DDEV project.

### 1. Create an empty guest cart

```bash
ddev exec curl -fsS \
  -H 'Host: m2b2b.ddev.site' -H 'X-Forwarded-Proto: https' \
  -H 'Content-Type: application/json' \
  --data '{"query":"mutation { createEmptyCart }"}' \
  http://127.0.0.1/graphql
```

Expected: a nonempty masked ID at `data.createEmptyCart`. Each run creates one empty local cart; it creates no customer or order. Copy that ID into the next command.

### 2. Try a declined response with no payment selected

Replace `PASTE_MASKED_CART_ID` and run this command three times against the same cart:

```bash
ddev exec curl -fsS \
  -H 'Host: m2b2b.ddev.site' -H 'X-Forwarded-Proto: https' \
  -H 'Content-Type: application/json' \
  --data '{"query":"mutation { handlePayflowProResponse(input: {cart_id: \"PASTE_MASKED_CART_ID\", paypal_payload: \"RESULT=12&RESPMSG=Declined\"}) { cart { id } } }"}' \
  http://127.0.0.1/graphql
```

Expected: GraphQL returns `Transaction has been declined.` in `errors`. The Mailpit count must remain unchanged after all three calls. HTTP 200 by itself is not enough: check the GraphQL error and the mailbox. During the completed local check, all three requests were rejected and the mail count stayed at 0.

### 3. Check an unknown cart

Repeat the previous command with `local-guard-unknown-cart` instead of the real masked cart ID.

Expected: Magento's `Could not find a cart with ID ...` error. No new mail. This verifies that the payment guard preserves the cart-access error.

### 4. Check the session-less transparent response

Use a fresh request with no cookie jar:

```bash
ddev exec curl -sS -o /dev/null -w 'HTTP %{http_code}\n' \
  -H 'Host: m2b2b.ddev.site' -H 'X-Forwarded-Proto: https' \
  --data 'RESULT=12&RESPMSG=Declined' \
  http://127.0.0.1/paypal/transparent/response/
```

The tested local project returned HTTP 200 and added no mail. Check for newly generated errors in `var/log` and `var/report`; payment notification handling should not throw `No such entity with cartId = 0`. Other controller/template errors are separate issues—the module does not guarantee every controller response will be error-free.

### Existing m2b2b convenience scripts

The working `m2b2b` environment also has session-created helpers:

```bash
ddev exec php var/paypal-guard-local-test/http-smoke.php
ddev exec php var/paypal-guard-local-test/check-runtime.php
```

The first repeats the HTTP checks and compares Mailpit counts. The second reports effective configuration and both installed plugin chains. These helpers live in the local project's ignored `var/` directory and are **not shipped in this patch repository**. The commands above are the standalone reproduction steps for other checkouts.

## Manual checkout tests

### Normal checkout in the current local store

1. Log in with an existing local test customer.
2. Add an in-stock item and go through cart, address selection and shipping.
3. Complete checkout with **Pay after order preparation**, the enabled offline `banktransfer` method.
4. Verify order details/totals and any expected captured checkout emails. There should be no spurious payment-failure reminders.
5. Repeat the empty-cart HTTP check and confirm it still adds no mail.

This full order-placement test has not yet been recorded as passed. Use synthetic order details. No live card transaction is needed.

### Payflow sandbox and broader regression matrix

Payflow Pro, Payments Pro and Payflow vault are disabled in the tested local store. Use dedicated sandbox credentials and test mode if enabling them for the following checks. The isolated suite covers the guard decisions, but those tests do not establish a successful gateway checkout.

| Case | Expected result | Installed application status |
| --- | --- | --- |
| Guest cart with no selected payment; three declines | Decline errors; no new mail | Passed over HTTP |
| Unknown cart | Core cart-access error; no new mail | Passed over HTTP |
| Session-less transparent response yielding cart ID 0 | No cart-zero notification lookup exception | HTTP 200 and no new mail observed |
| Unrelated selected method | Decline error; no new mail | Isolated suite passed; application test pending |
| Inactive cart or another customer's cart | Core cart-access error; no new mail | Isolated suite passed; application test pending |
| Active Payflow cart; sandbox decline | Normal checkout failure and merchant notification | Pending |
| Active Payflow cart; sandbox success | Existing success/order flow works | Pending |
| Only Payments Pro enabled | Existing compatible flow works | Pending |
| Saved card with provider and vault enabled | Existing saved-card flow works | Pending |
| Saved Payflow method after provider is disabled | Decline error; no new mail | Isolated suite passed; application test pending |
| Vault or provider disabled | Decline error; no new mail | Isolated suite passed; application test pending |
| Same-website store change | Enabled check uses resulting cart store | Isolated suite passed; application test pending |
| Different-website store change | Core website error; no new mail | Isolated suite passed; application test pending |
| Repeated declines on an eligible cart | Core retry notifications retained; no deduplication | Isolated suite passed; sandbox test pending |
| Another gateway's positive-cart notification | Existing behavior retained | Application checkout test pending |

The enabled-method check intentionally rejects an in-flight response after the provider is disabled. Selecting an enabled method does not prove a genuine gateway response; callback authentication and attempt correlation are outside this module.

## Isolated regression suite

From the **patch repo directory**, use the [README test command](../README.md#automated-tests). It runs 48 tests without bootstrapping the Magento application. The [review](REVIEW.md#isolated-regression-suite) records the exact PHP/PHPUnit matrix and assertions.

## Local rollback

From the Magento project directory:

```bash
ddev exec bin/magento module:disable Magetu_PaypalPaymentFailureGuard
ddev exec bin/magento cache:clean config
```

Regenerate compiled DI if applicable. If Composer-installed, revert the local package change through Composer. This module adds no schema or persistent state. Keep the separate SMTP override off while testing locally.

The completed `m2b2b` installation has a pre-upgrade database snapshot named `before-paypal-guard-20260915` (159 MB). Restore it only when intentionally rolling the entire local database back; doing so discards subsequent local carts/orders and is unnecessary for simply disabling the guard.
