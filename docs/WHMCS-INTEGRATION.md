# WHMCS integration knowledge and contract review

Reviewed 2026-10-05 against official WHMCS developer/user documentation, official sample gateway, and version-pinned 8.13.7 templates. Implementation target remains self-hosted WHMCS 8.13 with PHP 8.1–8.3. This review establishes public contracts and identifies core-dependent acceptance checks; it does not claim access to proprietary source or execution inside licensed WHMCS.

## Module families and ownership

| Component | WHMCS contract | Module implementation |
|---|---|---|
| Hosted third-party gateway | Lowercase gateway filename; prefixed `_config`, `_MetaData`, `_link`; `_link` returns payment HTML | `modules/gateways/snapppay.php`; invoice form posts to authenticated start route |
| Callback | Bootstrap core and gateway/invoice helpers; active gateway; authenticate payment, validate invoice and transaction, then apply payment | Sessionless `modules/gateways/callback/snapppay.php`; provider status/settlement proof before credit |
| Native refund | `_refund` receives original transaction, invoice, decimal amount and currency; returns status, unique refund reference, optional fees/rawdata | Full Cancel or partial Update; durable refund intent and one-time accounting handoff |
| Operations addon | Separate slug, `_config`, `_activate`, `_deactivate`, `_output`; output is echoed; `modulelink` is supplied | `snapppay_ops`; core addon role restrictions plus explicit authentication/role checks |
| Hooks | Files under `includes/hooks` load without addon hook discovery; page hooks return template variables; output hooks return HTML | Cart/invoice selection, client footer, bounded `AfterCronJob` recovery |
| Persistence | Capsule query builder and underlying PDO are supported; avoid altering core schema | Prepared statements in module-owned tables; core accounting writes use WHMCS helpers |

Sources: [gateway guide](https://developers.whmcs.com/payment-gateways/third-party-gateway), [official gateway sample](https://github.com/WHMCS/sample-gateway-module), [callbacks](https://developers.whmcs.com/payment-gateways/callbacks), [refund contract](https://developers.whmcs.com/payment-gateways/refunds), [addon output](https://developers.whmcs.com/addon-modules/admin-area-output), [hooks](https://developers.whmcs.com/hooks/getting-started), [database integration](https://developers.whmcs.com/advanced/db-interaction).

## Billing and accounting boundaries

Use `localAPI('GetInvoice', ...)` after bootstrap. GetInvoice supplies invoice items, credits, taxes, status, payment method, total and balance. The module reads the selected client's currency and charges the current outstanding balance, never a browser amount. [GetInvoice](https://developers.whmcs.com/api-reference/getinvoice), [internal API](https://github.com/WHMCS/developer-docs/blob/master/api/internal-api.md).

Successful payment must enter through `addInvoicePayment`; changing invoice status directly would bypass payment automation. Core owns invoice payment emails, due-date changes, order provisioning and invoice hooks. This module owns authenticated settlement and idempotent handoff, not a second provisioning system. A crash after ledger insertion can still interrupt core automation: inspect WHMCS automation/module queues and repair through supported core operations; do not call the payment helper again merely to repeat provisioning. [billing logic](https://docs.whmcs.com/8-13/billing-and-invoicing/billing-logic/), [invoice hooks](https://developers.whmcs.com/hooks-reference/invoices-and-quotes).

Native refund accounting occurs after gateway refund succeeds. Provider refund and WHMCS bookkeeping cannot form one database transaction. The saved handoff flag blocks overlapping returns while ledger confirmation is absent. Manual/account-credit refunds are distinct core workflows: they do not automatically cancel/update SnappPay. Pre-settlement SnappPay Revert is also distinct from WHMCS `paymentReversed`, which reverses prior paid-invoice effects. Never infer a dispute or reverse hosting from an unsigned FAILED return. [refund contract](https://developers.whmcs.com/payment-gateways/refunds), [payment reversals](https://developers.whmcs.com/payment-gateways/reversals).

Automatic renewal invoices can be paid with buyer interaction. No `_capture`, stored card method or `_cancelSubscription` is declared: published SnappPay API does not expose automatic subscriptions. Merchant balances are not invented from payment status. [subscription contract](https://developers.whmcs.com/payment-gateways/subscription-management), [balance contract](https://developers.whmcs.com/payment-gateways/displaying-balances).

## Authentication and authorization

WHMCS users and client accounts differ. One user can select among several associated clients; selected account ID is not authenticated user ID. Use `CurrentUser` and compare invoice owner to selected client. Owners have all client permissions; associated users require invoice permission. Admin masquerading is a separate authentication state. [CurrentUser](https://developers.whmcs.com/advanced/authentication), [users and client accounts](https://docs.whmcs.com/8-13/clients/users-and-client-accounts/).

The public GetUserPermissions example omits its permission payload. Existing association/pivot parsing remains a fail-closed core-dependent assumption and must be checked against licensed 8.13; this research does not certify its representation. Native core refund permissions and addon role storage must likewise be exercised with restricted roles. CSRF/form sessions and sessionless provider callbacks have different trust boundaries. [GetUserPermissions](https://developers.whmcs.com/api-reference/getuserpermissions).

## Actual theme contracts and corrected regression

The initial hook assumed `paymentmethods`, so official stock template variables were ignored. Regression first reproduced the missing dynamic title. The hook now updates each provided collection without inserting a gateway excluded by WHMCS or changing unrelated gateways:

| Theme surface | Input shape | Result / limit |
|---|---|---|
| Standard Cart 8.13.7 | `gateways`, entries containing `sysname` and `name`; `total` Price object | Eligible provider title/description; remove only SnappPay when ineligible |
| Twenty-One invoice 8.13.7 | `availableGateways`, module→label map | Dynamic title and ineligible removal; server invoice/owner check |
| Custom themes | `paymentmethods` supported additionally | Preserve existing compatibility; certify actual theme variables |
| Six invoice 8.13.7 | Pre-rendered `gatewaydropdown` HTML | Not rewritten by collection filter; payment card/start still check eligibility. Selector behavior requires theme-specific acceptance |

`rawtotal` takes precedence over formatted total when supplied. Price objects use `toNumeric`; formatted strings are not parsed as money. Cart credit/promo/tax changes and AJAX refresh require live theme tests; invoice start rechecks final balance and eligibility independently. Official [pinned template sources and SHA256](whmcs-template-sources.json) provide evidence. No copied templates ship in the module.

## Version and deployment limits

WHMCS 8.13 introduced PHP 8.3 support. WHMCS 9.0 requires PHP 8.2+, immutable non-Draft invoices, credit/debit notes, new ledger presentation and Nexus dynamic checkout. Those changes require billing adapter, refund repair and theme acceptance; PHP compatibility alone does not establish WHMCS 9 support. [8.13 release notes](https://docs.whmcs.com/releases/8-13/8-13-release-notes/), [9.0 release notes](https://docs.whmcs.com/releases/9-0/9-0-release-notes/).

Keep **Convert To For Processing** disabled: this implementation uses saved invoice/client currency and integer IRR conversion, not WHMCS FX-converted gateway parameters. Keep Mass Payment disabled for this candidate: composite invoice distribution/refund semantics remain uncertified and there is no explicit composite-invoice rejection. [currency conversion](https://docs.whmcs.com/8-13/billing-and-invoicing/invoicing-tutorials/convert-invoice-currencies/).

Gateway deactivation can reassign services, invoices, transactions and pay methods to another gateway. Drain SnappPay transactions/refunds before deactivation; reassignment conflicts with saved gateway identity checks. Retaining addon tables intentionally differs from sample teardown guidance because payment recovery/accounting data must survive disabling the dashboard. `AfterCronJob` runs after scheduled tasks on each system cron execution, not only the daily run. [gateway operations](https://docs.whmcs.com/8-13/payments/payment-gateways/), [addon lifecycle](https://developers.whmcs.com/addon-modules/installation-uninstallation), [cron contract](https://developers.whmcs.com/hooks-reference/cron).

## Evidence and remaining acceptance

Focused regression: 34 HTTP assertions pass, including stock cart eligible/ineligible behavior, numeric Price/raw totals, Twenty-One invoice labels and foreign-client denial. PHPStan level 5 and 32-file syntax check pass. These execute actual module routes through the documented simulator; they do not render Smarty or execute licensed core. Remaining gates are recorded in [LIVE-ACCEPTANCE.md](LIVE-ACCEPTANCE.md).
