# Required live acceptance — not executed

Missing prerequisites: licensed WHMCS staging location/version, merchant SnapPay stage credential references, whitelisted egress IP/return domain, enabled method types/hosting category and production deployment target. Use secret-manager references, not credentials in issue/chat/test reports. Computer Use permissions also remained pending during local visual review.

Record version, host, PHP/ionCube/MySQL version, theme, provider environment, tester, UTC timestamp, transaction/refund IDs and pass/fail evidence for each check.

| Check | Expected result | Status |
|---|---|---|
| Addon activation/deactivation/reactivation | Schema 2/indexes installed once; legacy URLs encrypted; roles enforced; records retained | Pending |
| Gateway secret fields/config/environment overrides | Decrypted only inside core; no secret output/log; stage/prod credentials isolated | Pending |
| Owner and associated user invoice permission | Owner and allowed invoices user can pay; restricted user/foreign account denied | Pending |
| Stock and custom checkout themes, desktop/mobile/RTL | Eligible title/description unchanged, false hidden, after price change refreshed, accessible labels/buttons | Pending |
| Six invoice dropdown / chosen WHMCS version | Six's pre-rendered dropdown behavior verified or supported theme selected; WHMCS 9/Nexus/core ledger semantics separately accepted if requested | Pending |
| Currency conversion / composite invoices | Convert To For Processing and Mass Payment disabled for candidate; no FX or uncertified composite accounting | Pending |
| Below/above stage eligibility bounds | Provider false below 4000 tomans/above 10M tomans; no local installment arithmetic | Pending |
| IRR/IRT/tax/credit/discount/previous payments | Exact provider IRR total, item identities and included-tax net allocation accepted by merchant | Pending |
| Customer fee fixed/percentage | Fee line appears on new SnappPay invoice email/PDF and client invoice; removed on gateway change and when disabled; card/selector total matches provider amount; `updateInvoiceTotal` keeps tax correct; partial/full refund handles fee; surcharge permitted by merchant contract | Pending |
| Successful checkout/callback | Authenticated status→Verify→Settle; exact invoice credited once; core provisioning/notifications correct | Pending |
| Abandon/FAILED/malformed/forged/replay/duplicate tabs | No unauthorized credit/revert; paid replay idempotent; second paid attempt flagged | Pending |
| Invoice drift/different gateway during checkout | Review protects invoice accounting; actual race behavior understood | Pending |
| Network failures and worker crash | Status recovers verify/settle; uncredited SETTLE retry does not duplicate provisioning/ledger | Pending |
| Crash after native ledger insertion | Verify core provisioning/notification/module-queue completion through supported recovery; do not repeat payment credit solely to rerun automation | Pending |
| Partial refund twice, zero/excess/increase | Update reduces itemized cart; identities/removal correct; native refund ledger unique; invalid amounts denied | Pending |
| Full refund after partial | Cancel remaining provider amount, native refund ledger/reference correct | Pending |
| Admin permissions / CSRF / confirmation | Restricted native admin cannot refund; addon role denied; approved revert requires confirmation | Pending |
| Unknown Update/Cancel outcome / core accounting crash | Saved intent/handoff blocks duplicate refund; supported accounting repair matches saved reference | Pending |
| Approved Revert before settlement | Feature/provider enablement and confirmation required; SETTLE/credited rejected | Pending |
| Cron fairness/load/timeouts | 25-row batches rotate, transient errors isolated, FPM/server timeout capacity measured | Pending |
| Provider portal-initiated changes | Read-only status reveals change; operator reconciles accounting without extra transfer | Pending |
| Actual TLS/DNS/WAF/registered subdirectory return | Proper certificates, public pinned DNS, direct HTTPS, POST accepted, no sensitive access logs | Pending |
| Production pre-demo/demo and rollout approval | Provider-approved evidence attached before environment acknowledgment enabled | Pending |

HTTP E2E simulator and real MySQL/TLS tests are local evidence, not replacements for these checks. No deployment or merchant certification is claimed.

Schema-2 upgrade acceptance: use drained maintenance window; interrupt/retry activation in staging; compare saved ledger fields; verify zero plaintext URL count without displaying URLs; preserve encryption key; exercise documented forward recovery. Old files alone cannot roll back schema 2 (ADR 0005).
