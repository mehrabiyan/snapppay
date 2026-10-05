# Deployment and recovery runbook

## Before rollout

Keep stage merchant credentials separate from production. Confirm support for BNPL services/hosting and commission category with merchant team. Back up files, database and WHMCS encryption key. Install candidate into isolated licensed staging. Verify Apache/Nginx private-library restrictions, callback POST/WAF path, direct outbound HTTPS, egress IP whitelist, registered return URL and root/subdirectory links. Production checkbox acknowledges completed acceptance; it does not test credentials or certify module. Never deploy the test simulator or research documents into web root.

## Upgrade to schema 2

rc.3 encrypts hosted URLs, including legacy URLs that can contain payment tokens. New code reads both formats; old rc.1/rc.2 code cannot use encrypted URLs. This requires a maintenance window, not mixed-version workers. See [ADR 0005](adr/0005-hosted-url-encryption.md).

1. Pause new checkout, HTTP traffic and cron at the hosting layer; keep gateway configuration unchanged. Drain in-flight callback/refund operations and stop old PHP workers. Back up database, module files and WHMCS encryption key after draining.
2. Replace module/hook files atomically. Reload PHP-FPM/clear opcode cache so no old workers remain. Use new code to activate SnappPay Operations in a controlled admin-only window. If already active, record allowed addon roles, deactivate the addon (which retains tables), activate it again to run the migration, then restore those roles. Keep the payment gateway active/configured throughout the pause.
3. Activation encrypts legacy URLs in batches of 100. Each row commits independently; failure leaves completed rows intact and does not advance schema version. Keep traffic paused, resolve key/data failure and retry activation. Never reset tokens or edit payment/accounting state to bypass failure.
4. Confirm `mod_snapppay_meta` has `schema_version=2`. Check only counts, without printing secrets: `SELECT COUNT(*) FROM mod_snapppay WHERE payment_url IS NOT NULL AND payment_url NOT LIKE 'spurl1:%';` must return zero. Compare payment/refund/amount counts with pre-upgrade evidence; test owned invoice redirect and admin reconciliation in staging.
5. Resume cron and traffic after migration succeeds. Retain original encryption key and protected backups. Earlier backups can contain plaintext hosted tokens; protect/retire them under existing retention policy.

## Daily operations

Inspect addon Needs attention rows and Gateway Log. Cron rotates at most 25 due attempts per invocation; normal pending buyers do not block later records. Reconcile retries verify only after provider PENDING with durable prior verify intent, and settle only VERIFY. Provider status button reads fresh remote state without accounting changes, including review records. Invoice links and refund previews are administrative only. Audit contains identifier, operation/result, admin and UTC timestamp; phone/token/Authorization/body logging prohibited.

## Recovery decisions

| Local state/error | Action |
|---|---|
| pending, buyer not returned | Inspect provider status. Do not assume payment; provider expiry/reversion controls eventual outcome |
| verifying/settling timeout | Reconcile; status controls retry. Never blindly replay Verify from callback/browser |
| settled, uncredited | Reconcile with invoice/ledger comparison; helper credit happens only once |
| paid | Ordinary callback replay returns recorded outcome. Provider status reads fresh provider state |
| review / invoice_changed | Compare saved snapshot, provider status and WHMCS ledger. Decide correct invoice payment/refund with accounting staff; module does not override |
| token_unknown / creating after process death | Merchant portal/support lookup by transaction ID; obtain provider token or resolve transaction with supported operational process. Do not fabricate token or resend original token request |
| refund sending / refund_uncertain | Inspect provider state/amount. If target/CANCEL confirmed, Reconcile records provider_done. If previous amount remains, request provider confirmation; no blind second Update/Cancel |
| refund provider_done, handed_off=false | Original request failed before handoff; native refund retry can return confirmed saved result once |
| refund provider_done, handed_off=true, ledger absent | Native accounting handoff occurred; block retries. Verify actual core ledger, then complete supported WHMCS accounting repair using **saved refund ID and exact amount**. Use no second provider mutation or bank transfer. Reconcile clears intent only once matching ledger exists |
| revert_intent/revert_uncertain | Read-only Provider status; if REVERT confirmed, review accounting and request operator correction. No automatic duplicate reversal |
| environment_mismatch | Restore original merchant environment for unresolved record. Do not migrate stage tokens to production |
| provider_http_401/403 / dns_failed | Check credential rotation, IP/domain whitelist, API origin, TLS/egress configuration. Logs intentionally omit raw provider body |

Do not edit SQL amounts/states or remove records to bypass safeguards. If supported WHMCS accounting repair cannot record the required refund reference, keep exception blocked and escalate with saved IDs. No public recovery endpoint accepts raw tokens or arbitrary JSON.

## Rollback

Disable new client selection through WHMCS gateway settings after outstanding payments are resolved; disabling gateway also prevents configured callback processing, so plan a drain window. Keep callback/hook files and original credentials during draining. Stop new checkout, process outstanding statuses/refunds, export audit/ledger, then deactivate addon if needed. Deactivation retains tables. Schema 2 changes hosted URL representation; restoring rc.1/rc.2 files alone is unsupported. Prefer a forward fix while traffic stays paused. A full pre-upgrade restore requires proof no provider/accounting changes occurred after backup; otherwise reconcile and retain those newer money records first. Never drop tables, decrypt hosted URLs back to plaintext, reset tokens or change encryption key during rollback. Newer-schema guard prevents silent downgrade. Database retention permits later recovery/re-enable.

WHMCS gateway deactivation can reassign invoices, services, transactions and pay methods to another gateway. Do not perform that reassignment while SnappPay payment/refund records remain unresolved; saved gateway identity checks would then fail. See WHMCS-INTEGRATION.md for supported public lifecycle contracts and deployment limits.

## Live production switch

Complete LIVE-ACCEPTANCE.md with transaction IDs and evidence. Merchant team must approve net cart allocation and partial refund semantics, exact checkout/return host, payment method filters and required pre-demo/demo. Switch only after stage records settled/refunded/reconciled; verify production origin/hostname and whitelist separately. Run merchant-approved low-value production test through native UI and obtain customer/admin approval for consequential live financial actions. This repository did not execute such a payment.
