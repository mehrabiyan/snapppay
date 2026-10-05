# Verification report — 2026-10-05

Release candidate 1.0.0-rc.3. Local tests executed; remote CI and real merchant/core acceptance are separate gates. Exact commands are in README. Source/test packages contain no live credentials.

| Layer | Result | Environment / limits |
|---|---|---|
| Unit tests | 58 passed | Money, rounding/property checks, phone normalization, URL/IP/HTML/config protections |
| Integration/state/persistence tests | 42 passed | Real SQLite, encrypted sodium fixture, fake provider responses; lifecycle, idempotency, timeout/crash/drift/refund/locks/cron fairness |
| PHP compatibility | 100 tests pass per version | Actual PHP 8.1, 8.2 and 8.3 official Docker runtimes, plus host PHP 8.5.7 |
| Real MySQL integration | 4 grouped scenarios passed | MySQL 8.0.46/InnoDB, two real PDO connections; schema/index and legacy hosted URL encryption migration, ledger-field preservation, encrypted storage, lifecycle, connection lock timeout/release, refund bookkeeping recovery |
| Real TLS transport | 10 checks passed | Actual PHP cURL against loopback Python TLS server; untrusted cert rejected, fixture CA trusted only in child PHP; HTTP/JSON/size/header/redirect handling |
| HTTP end-to-end | 35 assertions passed | Actual gateway/start/callback/addon/hook/refund routes under explicit disposable WHMCS simulator; stock collection shapes, Price object/raw totals and foreign-client selector regression; native ledger/provisioning core is simulated |
| PHP syntax | 32 PHP files pass | Runtime, hooks, addon and test fixtures, excluding generated runtime cache |
| Static analysis | PHPStan level 5 clean | WHMCS contract stubs; explicit template arguments; no baselines/ignored errors |
| Composer | Strict validation and audit pass | Locked phpstan development dependency; zero runtime packages |
| Release package | Deterministic manifest/hash verification | Excludes tests, mocks, vendor, credentials, certificate keys and copied research; verifies every file hash |
| Browser visual QA | Not completed | Browser/CDP timeouts and pending native Computer Use permissions; no layout/screenshot certification claimed |
| Licensed WHMCS / real SnappPay stage | Not executed | No target/credentials/IP/domain whitelist supplied |
| Production deployment/payment | Not executed | No production target or merchant acceptance evidence supplied |

100 named unit/integration tests additionally include 1,000 randomized allocation cases and 1,000 exact decimal round trips. These property assertions are not counted as separate tests. Real MySQL's four scenarios summarize grouped checks rather than hundreds of independent tests.

## Scope exercised

Successful checkout→Verify→Settle→invoice credit, sessionless callback, replay, duplicate submission/tabs, anonymous/foreign client and admin-role rejection, token ambiguity, OAuth-per-operation, eligibility false and stage bounds, external invoice changes during settlement, provider ID/amount mismatch, lost verify/settle responses, before/after credit crashes, verification budget, full/partial refunds, lost refund response, uncertain mutation blocking, one-time accounting handoff, approved pre-settlement revert, cross-environment isolation, SQL injection, reflected/stored HTML injection, CRLF/SSRF/private/multicast IP policies, encrypted token/hosted URL storage, legacy migration interruption/corruption/batch boundary, secret/mobile exclusion, idempotent migration and actual lock contention.

Test corrections included explicit fixture CA trust, HTTP Content-Length for proper TLS EOF behavior, required pcntl in compatibility images, shell exit-code propagation, occupied-port preflight, native gateway/addon slug separation, isolated tokens/invoices for repeatable MySQL runs and realistic post-refund accounting checks. Corrections were followed by reruns; no failed test is treated as passing.

Follow-up WHMCS contract review found the selection hook ignored actual stock `gateways`/`availableGateways` variables. New regression failed before the fix and passed after it. Six's pre-rendered invoice dropdown, Smarty rendering and Nexus remain separate live checks; see WHMCS-INTEGRATION.md. rc.2 fixed hooks. rc.3 encrypts hosted URLs and adds schema-2 migration. Whole-row token-disclosure regression failed before the fix and passed after it. All 100 named tests reran on host PHP 8.5.7 and actual PHP 8.1/8.2/8.3 containers; updated MySQL, HTTP, static, syntax and Composer checks passed. Transport code is unchanged; ten TLS checks retain prior execution evidence. HTTP/MySQL use real module code and simulated core/provider; neither certifies licensed WHMCS. Upgrade and rollback requirements are in ADR 0005.

Read-only [client preview](previews/client.html) and [admin preview](previews/admin.html) contain simulated data with disabled controls. They are rendered HTML snapshots, not screenshots. Full visual checks across desktop/mobile/RTL, stock/custom themes and licensed core remain in LIVE-ACCEPTANCE.md.
