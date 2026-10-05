# SnapPay WHMCS gateway specification

Status: implementation baseline. Written before module code, 2026-10-05.

## Outcome and scope

Deliver an installable third-party WHMCS gateway `snapppay`, companion administrative addon, client checkout component, reconciliation cron hook, and reproducible release ZIP. Cover every published BNPL payment endpoint: OAuth, eligibility, payment token, verify, settle, payment status, revert, cancel and update. Target WHMCS 8.13+ / PHP 8.1–8.3 / MySQL 8 or MariaDB with InnoDB; execute local proof on available PHP, with CI covering supported versions. No card storage, automatic recurring BNPL debit, or unpublished merchant portal/search advertising APIs.

## Sources and uncertainties

Primary technical source: https://academy.snapppay.ir/self-cms/ (labelled v2.1). Read all 16 sections and embedded PHP/shell/Java/.NET examples. Inventory other public academy posts/pages in documentation-inventory.json; raw fetched evidence remains local and excluded from release. WHMCS contracts: developers.whmcs.com/payment-gateways/{third-party-gateway,callbacks,refunds}, addon modules, local API, hooks.

Documentation discrepancies require explicit choices: introductory Digipay reference is an apparent copy error; implement SnappPay endpoints. Revert described both optional and required on FAILED: implement endpoint but require configured provider approval for use, never mutate from unauthenticated failure callback. Verify described once-only yet permits retry after status=PENDING: persist attempt before send; recover through status, retry only confirmed PENDING and within bounded budget. v2.1 page history stops at 2.0. Stage/prod URLs are merchant-specific, not guessed. Payment method filters require merchant enablement; default omitted. OAuth fetched fresh per API operation, never persist JWT. Exact hosted payment hostname configured by merchant; enforce HTTPS and public DNS addresses.

## Client journey

1. Invoice page displays provider-returned title_message and description as escaped text only when eligible=true. Never calculate installment offers locally. English/Persian interface, RTL, responsive card, keyboard-accessible buttons, no CDN dependencies.
2. Explicit POST with WHMCS CSRF token, session ownership/associated-user access, server invoice lookup, Iranian mobile normalization, gateway/currency/status validation. Client cannot choose amount, invoice owner, return URL, token or transaction ID. Rate limit per invoice/client; one submission key per rendered form; separate tabs get distinct identifiers.
3. Acquire invoice advisory lock; persist immutable amount/currency/cart and unique transaction before requesting token. Redirect only to allowlisted HTTPS provider host. Persist encrypted payment token and hosted checkout URL; do not log sensitive body/headers. Token failures after possible send are uncertain, never blindly recreate same transaction.
4. Public POST callback looks up stored transaction, requires matching amount and allowed state. Treat callback as trigger only: independent provider status, transaction ID and amount must match saved values. FAILED never marks invoice paid or initiates destructive operation without authenticated state checks.
5. Persist verify intent before network call; recover unknown outcome via status. Settle only verified payments. Credit exact WHMCS-currency amount only after provider SETTLE; detect duplicate WHMCS ledger transaction. Invoice-level lock serializes callbacks, retry, refunds and starts. If invoice changed or paid elsewhere, flag operator review, never provision twice. Show escaped outcome and authenticated invoice link, no personal data on callback page.

## Money/cart rules

Only IRR and IRT/TMN configured currency code; decimal strings converted with integer arithmetic; reject fractional rials, overflow, scientific notation, negative/zero payment and unrecognized currencies. Provider always receives integer IRR. No FX guessing. Send positive invoice lines as separate items with count=1 and invoice-item IDs, default commissionType=100. Allocate payable balance deterministically across positive lines including tax, discount/credits/previous payments, preserving exact sum. Included-tax/shipping flags true; net payable line amounts are tax-inclusive; taxAmount reports allocated included tax. Persist provider snapshot for refunds. Explain net allocation in ADR; merchant demo must confirm accepted invoice semantics. Negative invoice lines, credit and previous payments reduce line allocations, never create negative provider cart items.

## Administration and refunds

WHMCS encrypted secret configuration plus environment-variable overrides, exact API/payment hosts, stage default, production certification toggle, mobile/category/commission options, approved revert toggle, language. Fail closed until installation and valid configuration. Addon restricted to allowed WHMCS admin roles; additional session/role permission validation on every POST. Native WHMCS refund workflow is authoritative for bookkeeping: full refund invokes cancel; partial refund invokes update with reduced, nonzero itemized snapshot. Allocate reduction across items deterministically; remove zero lines, include tax proportionately, total strictly decreases. Native refund request supplies admin confirmation; API-only/cron refunds denied without admin context. Reject second pending refund; persist refund intent and proposed cart before send. Unknown outcome reconciles via status amount, never double refund. Persist one-time accounting handoff before returning native refund success. If provider succeeded but WHMCS refund accounting incomplete, block further refund and surface repair requirement. Addon provides transaction history/search/pagination, statuses, invoice links, retry/reconcile, approved pre-settlement revert with explicit confirmation, readiness panel and refund guidance/previews. Never let admin type arbitrary endpoint or JSON payload.

## Persistence/recovery

Versioned non-destructive schema migration on addon activation only; deactivation retains records. Unique transaction ID, attempt nonce, provider token digest; encrypted token and hosted URL, JSON cart, amount/currency, timestamps, verify count, state, credit flag, refund intent and error code. Prepared SQL only. MySQL advisory lock with bounded acquisition, automatically released; SQLite test lock equivalent. Audit operations contain IDs, operation/state and sanitized error class only. Cron processes bounded batch of uncredited settled/verify/pending transactions and uncertain refund outcomes. No retry of unsafely ambiguous token mutations. No terminal-state overwrite from callback replay. Environment fingerprint persisted so stage records cannot be processed in production after config changes.

## Security acceptance

CSRF, IDOR, session ownership, admin role authorization, stored/reflected XSS, SQL injection, URL/header injection, SSRF/DNS rebinding, secret disclosure, callback forgery/replay, concurrent double credits, invoice drift, precision/overflow, refund replay, malformed/oversized HTTP, TLS errors, schema installation, environment changes and crash windows must have tests or documented review evidence. cURL verifies TLS, allows HTTPS only, disables redirects, pins validated public DNS IP, uses connect timeout 10s and operation timeout 30s, caps response at 1 MiB. Sensitive credential/token/mobile keys omitted from logs. No raw provider errors in user UI. Callback has no session dependency. InnoDB locking and ledger helper behavior must also be verified on licensed WHMCS staging.

## Verification and release gate

Unit: conversion, phone, URLs, payloads, state transitions, escaping, allocation. Integration: real HTTP client against local TLS fixture, persistence/encryption, WHMCS adapter contracts, migration, lock contention and crash recovery. E2E: actual module routes under explicit WHMCS simulator, full checkout/provider/callback/invoice/refund journeys plus abuse cases; distinguish simulator from licensed WHMCS certification. PHP lint, static analysis, dependency audit, CI version matrix, packaging inspection. Local TLS mock cannot substitute provider stage certification. Live acceptance requires merchant credentials, whitelisted egress IP/return domain and licensed WHMCS staging; report missing access truthfully, never claim production certification or deploy without target.

## Security implementation addendum — schema 2

Release candidate rc.3 encrypts hosted URLs because their path/query can contain payment tokens. Non-destructive activation migrates legacy URLs in resumable batches, retaining accounting fields and verifying existing ciphertext. Maintenance/drained workers required; mixed old/new worker operation is unsupported. Forward and rollback rules, regression evidence and historical backup handling are specified in ADR 0005.

## Customer fee addendum — rc.4

Admins may set an optional fixed (whole IRR) or percentage (≤2 decimals) customer fee. It is a module-owned `SnappPayFee` invoice line, synced on invoice creation/gateway change and again under lock at start, so invoice balance, provider amount, cart, callback check and ledger credit stay equal. Eligibility and client UI use the fee-inclusive total. See ADR 0006.
