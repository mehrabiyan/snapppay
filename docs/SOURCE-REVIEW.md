# Source review and documentation coverage

Reviewed 2026-10-05. Technical specification/ADRs precede runtime implementation. Primary payment document: https://academy.snapppay.ir/self-cms/ . Read all 16 narrative sections and embedded shell/PHP/Java/.NET request/response examples, including samples hidden by JavaScript rendering. Nine operation contracts mapped in API.md. Read relevant activation, callback, credentials, IP, test/security and order-management guidance. Official published integration ZIP also inspected read-only for currency and eligible/status naming; no vendor implementation copied or executed.

Public WordPress endpoints yielded 134 posts/pages, 813,463 extracted text characters. `documentation-inventory.json` records IDs, titles, links and content hashes. Remaining academy content was inventoried and screened for technical relevance; mostly merchant onboarding, advertising, sales, product search, tax/accounting, webinars and partner services. This is **not a claim that every marketing paragraph or embedded video was watched/read exhaustively**. Account/profile/reset pages expose only login shells; authenticated material unavailable. Raw HTML/JSON/downloads retained locally under ignored research directory and excluded from release.

## Resolutions that affect implementation

| Source issue | Resolution |
|---|---|
| Intro refers to Digipay although page/paths say SnappPay | Use named SnappPay endpoints, not another provider |
| Page label v2.1, history latest entry 2.0 | Track observed page/hash; merchant stage collection remains acceptance gate |
| JWT lifetime 3600s, page says do not cache | Fresh OAuth per operation, no persistent cache |
| Verify once-only, timeout instructions permit retry on PENDING | Durable intent, status first, at most 3 verify sends; ordinary replay never sends verify again |
| FAILED says revert, Revert section says optional/provider-requested | Untrusted FAILED callback cannot trigger reversal; implement explicitly enabled admin-confirmed endpoint |
| Cancel status amount after cancellation not specified | Accept CANCEL with zero or previous amount; otherwise retain uncertain intent |
| Search/portal guides give broader product/feed/report topics | Keep BNPL scope; no invented merchant APIs or hosting inventory feed |
| Generic test guide mentions GET callbacks; technical contract explicitly POST | Payment callback accepts POST only |
| Price-policy guides prohibit BNPL markup | Charge saved invoice balance; no gateway surcharge or installment arithmetic |
| Exact API/checkout hostname varies by merchant | Configure provider-supplied origins/hosts; no guessed production endpoint |
| Net invoice balance may include tax/discounts/credits/previous payments | Exact item allocation under ADR 0002; demo sign-off required |

WHMCS primary references read: third-party gateway, callbacks/helper functions, refund contract, configuration, CurrentUser authentication, GetInvoice, client-area interface/cart hooks, 8.13 requirements. Local simulator cannot validate proprietary core internals; live acceptance covers associated-user pivot/permission representation, addon roles, helper ledger/provisioning behavior, template variables and refund accounting.

Follow-up WHMCS research covers official sample gateway/callback, addon lifecycle/output, Capsule/PDO, internal API, users/accounts, invoice/payment automation hooks, cron/output hooks, Price formatter, refunds/reversals, subscription/balance contracts, FX configuration, 8.13/9.0 release changes and exact version-pinned 8.13.7 cart/invoice templates. Findings, fixed template-variable mismatch, remaining core assumptions and primary citations are recorded in WHMCS-INTEGRATION.md; template hashes in whmcs-template-sources.json. Unavailable internal User class details and incomplete GetUserPermissions payload example remain explicitly uncertified.
