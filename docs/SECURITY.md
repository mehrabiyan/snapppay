# Security review

Implementation review performed during build and again before packaging. This is an internal code/test review, not an independent penetration test. No runtime dependency packages shipped. Development PHPStan package locked; Composer audit run. Production remains gated by licensed WHMCS/provider acceptance.

## Controls and evidence

| Threat | Control | Evidence |
|---|---|---|
| Client IDOR / amount tampering | CurrentUser selected client + associated invoice permission; server GetInvoice and currency; no posted amount trusted | Owner tests, HTTP foreign-client and amount tamper tests; real associated-user core check pending |
| CSRF | WHMCS plain token comparison; client session-bound expiring submission nonce; admin mutations POST-only | HTTP client/admin forged token rejection |
| Callback forgery/replay | Random transaction ID, exact original amount; authenticated provider status with saved encrypted token; provider enum/ID/amount checks; invoice lock and ledger duplicate check | Forged OK/FAILED, SQL injection, amount/ID mismatch, replay, sessionless E2E |
| SQL injection | Prepared values; fixed SQL identifiers/allowed update columns; integer pagination | Storage injection tests, static review, real MySQL |
| XSS | Escaped provider/admin/item text, escaped attributes; footer JSON HEX encoding + textContent; isolated callback CSP | Malicious title/description/mobile unit tests, HTTP footer/card tests |
| SSRF / DNS rebinding | Exact configured HTTPS API origin; public IPv4/global-unicast IPv6 validation; pinned DNS IP; no redirects/proxy | Private/reserved/mapped/multicast IP tests, URL tests, TLS redirect rejection |
| Header/URL injection | Reject credentials/URL control chars and backslashes; validate access/payment tokens; header validation | CRLF, userinfo, malformed URL, suffix-host tests |
| TLS / parser abuse | Verify peer/hostname, standard CA bundle, HTTPS-only, connect 10s/op 30s, response ≤1 MiB, JSON content type/depth | Real TLS fixture tests: invalid trust, redirect, status errors, malformed/oversized body, headers |
| Secret disclosure | WHMCS encrypted password fields, secret-manager overrides, encrypted payment token and hosted URL, no retained mobile, sanitized error codes/audit | Whole-row encrypted SQLite/MySQL state, legacy migration/crash tests, HTTP persisted checkout/dashboard/gateway log secret absence |
| Double credit / crash | MySQL connection advisory invoice lock; verify intent persisted; SETTLE proof; exact ledger identity/amount; invoice recheck after network | Actual MySQL contention, duplicate tabs, before/after ledger crash, invoice drift during settle |
| Duplicate refunds | Durable target/cart/ID before mutation; status resolves uncertainty; one-time native accounting handoff; block until WHMCS refund ledger recorded | Update/cancel timeout/replay/handoff/accounting tests |
| Wrong environment | Fingerprint environment/origin/client/username; reject mismatches | Environment switch test |
| Unauthorized admin mutation | CurrentUser admin + configured addon roles; confirmation checkbox for revert; native WHMCS handles refund permission/confirmation | Anonymous/role/CSRF HTTP rejection; production core permission gate pending |
| Unbounded retry / cron starvation | Invoice hourly token-attempt cap, verify send budget, 25-row cron limit; touch poll timestamps to rotate pending records | Rate/budget/fairness tests |

## Review findings fixed

1. Gateway/addon function-name collision: separate addon slug `snapppay_ops`.
2. Refund reconciliation skipped terminal paid/cancelled states: process non-null refund intent before terminal shortcut.
3. Refund result could be returned twice before native bookkeeping: persisted handed_off flag, reject concurrent retry.
4. Invoice could change during provider calls: final server invoice reread immediately before ledger credit.
5. Old pending rows could starve newer cron work: update polling timestamp even while PENDING.
6. PHP filter did not fully express shared/multicast/mapped-address restrictions: explicit publicIp policy.
7. Template relied on implicit include-scope variables: explicit typed renderer arguments, static analysis clean.
8. Fixture tests could reuse occupied port: fail before deleting disposable fixture data; isolate owned server lifecycle.
9. Eligibility hook used only a custom collection name: support official cart `gateways` and Twenty-One `availableGateways`; preserve other gateways and core exclusions, validate raw totals, cover foreign-client selector denial.

10. Hosted URL stored plaintext could expose its embedded payment token despite encrypted token column: encrypt URLs with WHMCS adapter, migrate legacy URLs without changing ledger fields, resume interrupted activation and fail closed on unreadable ciphertext. Whole-row regression failed before fix and passed after it; upgrade requires drained workers (ADR 0005).

## Material remaining gates/limits

Live WHMCS associated-user permissions, native admin refund permissions, actual helper side effects/provisioning, custom templates, PHP-FPM timeout/load behavior and real provider stage responses have not been exercised. Other payment gateways do not share this module's advisory lock; final reread detects changes but cannot establish global atomicity across arbitrary third-party modules. Do not intentionally pay one invoice concurrently through different gateways; include drift scenarios in acceptance. Provider success + core crash after refund handoff requires deliberate accounting repair, not automatic re-mutation. Review/unknown token states remain operator-managed. Real external portal changes require Provider status inspection and accounting review.

No automatic pre-production/provider certification is implied by checkbox or local tests. Audit/token retention, backups, key rotation, WAF/rate rules and patched WHMCS/ionCube are deployment responsibilities described in runbook.
