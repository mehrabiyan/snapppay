# SnappPay BNPL for WHMCS

Production candidate **1.0.0-rc.3**. Native third-party payment gateway, operations addon and reconciliation hook. Covers the nine documented BNPL API operations, responsive English/Persian client checkout, dynamic provider eligibility, exact IRR conversion, secure hosted redirect, verify/settle recovery, full cancellation, itemized partial update/refund, and administrative status/recovery/refund previews.

**Live release gate remains open:** no merchant credentials, whitelisted IP/domain or licensed WHMCS installation were supplied. Local automated tests are evidence of implementation behavior, not provider or WHMCS certification. Read [test report](docs/TEST-REPORT.md), [security review](docs/SECURITY.md) and [live acceptance checklist](docs/LIVE-ACCEPTANCE.md) before enabling production.

## Install

1. Back up WHMCS files/database. Use a patched WHMCS 8.13 installation with matching ionCube loader and **64-bit PHP 8.1–8.3**, cURL, PDO MySQL, BCMath and mbstring. Target later WHMCS versions only after their supported PHP/core acceptance checks pass. MySQL 8/InnoDB tested; MariaDB support still needs acceptance.
2. Build/download the release ZIP, verify `dist/SHA256SUMS`, then copy **only `modules/` and `includes/`** into the WHMCS root. Runtime has no Composer/vendor dependency. Keep research, test harness, .env, and developer tools outside public web root.
3. Activate **SnappPay Operations** (`snapppay_ops`) under System Settings → Addon Modules. Select permitted admin roles. Activation creates three versioned payment/audit/metadata tables and migrates legacy hosted URLs to encrypted schema 2; deactivation retains all data. Existing installations must follow the maintenance-window [upgrade procedure](docs/RUNBOOK.md#upgrade-to-schema-2).
4. Activate **SnappPay BNPL** (`snapppay`) under Payment Gateways. Select stage; enter exact API origin, checkout hostname(s), Client ID, Client Secret, username and password supplied by SnappPay. Secrets use WHMCS password fields; environment overrides supported through hosting secret manager. No built-in dotenv loader.
5. Whitelist outgoing server IP and register exact return URL: `https://YOUR-WHMCS-BASE/modules/gateways/callback/snapppay.php`. Include WHMCS subdirectory if present. Keep domain/path consistent with provider registration. Client return is POST; ensure CDN/WAF allows it and disables response caching. Callback needs no browser session cookie.
6. Use genuine IRR currency, or IRT/TMN when invoice prices are in tomans. Module multiplies tomans by 10; rejects unsupported currencies, FX guessing and fractional rials. Keep **Convert To For Processing** disabled. Keep **Mass Payment** disabled until composite invoice distribution/refunds have separate acceptance; candidate does not explicitly reject composites. Commission type defaults to 100; category defaults to Services. Configure forced methods only after merchant enablement by SnappPay.
7. Ensure WHMCS cron runs and `AfterCronJob` hooks execute. API calls have 10s connect/30s operation timeouts; OAuth is fresh for each operation. Size PHP-FPM/request timeouts for multi-operation verification (up to 300s), and verify production load behavior. Each cron invocation processes at most 25 due records.
8. Run all [live acceptance checks](docs/LIVE-ACCEPTANCE.md), complete SnappPay pre-demo/demo, switch merchant credentials/hosts to production, then enable production acknowledgment. Never switch environment while stage payments remain unresolved; records are bound to merchant/environment fingerprint.

## Operate

Open Addons → SnappPay Operations. Filter/paginate payment attempts; inspect fresh provider status; reconcile recovery states; preview full/partial refund allocation. Amounts display in saved invoice currency. UTC timestamps are labelled. Approval checkbox required for enabled pre-settlement Revert; this feature remains off unless provider requests it.

Execute refunds through **WHMCS invoice → Refund → Refund through Gateway**, selecting original transaction. Full remaining amount calls Cancel. Partial amount calls Update with proportionally reduced saved invoice items; zero-valued items are removed. WHMCS records native refund accounting. SnappPay returns credit/funds itself; do not send a second manual payment. Refund API use without an authenticated WHMCS admin is rejected. Original invoice line prices reflect net payable allocation, including tax/credits/previous payments; see [money ADR](docs/adr/0002-money-and-cart.md).

If provider refund succeeded but WHMCS accounting did not, subsequent refund requests stop with `refund_accounting_pending`. Use saved refund ID/amount and supported WHMCS accounting repair workflow after checking ledger; never resend provider refund. Dashboard exposes refund ID and amount for this recovery. `token_unknown` requires provider support/portal lookup; token creation is never blindly replayed. `review` requires operator investigation and explicit accounting correction; automatic reconciliation does not override invoice drift or terminal review decisions.

Client selection hooks filter ineligible SnappPay and show dynamic terms using Standard Cart `gateways`, Twenty-One invoice `availableGateways`, and custom `paymentmethods` collections. Preserve numeric Price/raw total and currency values, plus paymentmethod radio labels; certify each theme. Six invoice's pre-rendered `gatewaydropdown` is not rewritten; its selector requires separate theme acceptance. Module starts payment from owned invoice, supports subsequent renewal invoices with buyer interaction, and does not create automatic BNPL subscriptions. No unpublished portal/reporting/advertising endpoints are invented. See [WHMCS contract review](docs/WHMCS-INTEGRATION.md) for public contracts and core-dependent assumptions.

## Server hardening

TLS verification remains enabled; cURL refuses redirects, pins validated public DNS IP, ignores outbound proxy environment and caps JSON responses at 1 MiB. Configure trusted CA bundle through standard `curl.cainfo` when hosting requires it. Payment checkout URLs must match exact allowlisted HTTPS hostname. Production must allow outbound direct HTTPS to merchant API.

Apache `.htaccess` denies direct access to library PHP and directory listings. On Nginx configure equivalent restrictions (preserve your existing PHP handler):

```nginx
location ~ ^/WHMCS-SUBDIR/modules/gateways/snapppay/(src/|bootstrap\.php) { deny all; }
location ~ ^/WHMCS-SUBDIR/modules/addons/snapppay_ops/view\.php$ { deny all; }
```

Adjust prefix for root deployment. Do not log callback POST bodies/query strings, Authorization headers, mobile numbers or hosted checkout/payment token URLs. Configure log retention/access controls in WHMCS; audit tables retain only identifiers, operation/result, admin ID and timestamps. Encrypted token/hosted URL retention/deletion must follow unresolved-payment/refund/accounting requirements; do not delete recoverable records during rollout. Back up and preserve WHMCS encryption key.

## Develop and verify

```sh
composer install
python3 tools/lint.py
composer analyse
composer audit
php tests/run.php
python3 tests/integration/transport.py
python3 tests/e2e/run.py
python3 tools/package.py
```

PHP test dependencies additionally include sodium, PDO SQLite and pcntl. Python 3.10+ and OpenSSL CLI required; no Python packages needed. TLS fixture trusts its generated CA only in test child process. HTTP E2E simulator listens on loopback port 8765, removes only its own disposable test site/database, and ships no simulator files. Use a free port; fixture refuses an occupied listener.

Real MySQL integration uses a disposable database:

```sh
docker run --detach --name snapppay-whmcs-test-mysql --publish 127.0.0.1:33079:3306 \
  --env MYSQL_ROOT_PASSWORD=test-only-root --env MYSQL_DATABASE=snapppay_test \
  --env MYSQL_USER=snapppay_test --env MYSQL_PASSWORD=test-only-password mysql:8.0
# Wait until database is ready, then:
SNAPPPAY_TEST_DSN='mysql:host=127.0.0.1;port=33079;dbname=snapppay_test' php tests/integration/mysql.php
docker stop snapppay-whmcs-test-mysql
```

CI defines PHP 8.1/8.2/8.3 jobs, real MySQL tests, lint/static checks, TLS/HTTP suites and packaging. CI must be executed in target repository after upload; local runs do not imply remote workflow completion.

## Design and sources

Start with [spec](docs/SPEC.md), [architecture ADR](docs/adr/0001-architecture.md), [URL-encryption upgrade ADR](docs/adr/0005-hosted-url-encryption.md), [API coverage](docs/API.md), [source review](docs/SOURCE-REVIEW.md) and [runbook](docs/RUNBOOK.md). Official provider [technical documentation](https://academy.snapppay.ir/self-cms/), WHMCS [gateway callbacks](https://developers.whmcs.com/payment-gateways/callbacks), [refunds](https://developers.whmcs.com/payment-gateways/refunds), [authentication](https://developers.whmcs.com/advanced/authentication), [8.13 requirements](https://docs.whmcs.com/8-13/installation-guide/system-requirements/). Independent integration; certification depends on merchant-specific provider acceptance.
