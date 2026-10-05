# ADR 0005 — Encrypt hosted checkout URLs and preserve ledger during upgrade

Accepted 2026-10-05. Security follow-up for release candidate 1.0.0-rc.3; schema version 2.

## Context

The provider's hosted checkout URL can contain its payment token in the path or query. Encrypting only the dedicated token column leaves a usable secret in plaintext `payment_url`. A whole-row disclosure regression reproduced this problem before the fix. Existing transactions must retain their IDs, amounts, accounting state and timestamps during repair.

## Decision

Encrypt new hosted URLs using the existing WHMCS `encrypt`/`decrypt` adapter and preserve its configured key. Prefix URL ciphertext with `spurl1:` to distinguish it from legacy HTTPS URLs. Validate URL syntax before encryption and after decryption; redirect still checks the exact configured checkout host. No URL or token is added to audit or gateway logs.

On addon activation, scan saved URLs in ordered batches of 100. Encrypt legacy plaintext URLs with prepared conditional updates. Each update commits independently, allowing interrupted activation to resume without rewriting completed ciphertext. Verify existing ciphertext can decrypt. Advance schema metadata to version 2 only after all batches succeed. Reject schemas newer than this module before rewriting records. No table, payment, refund or audit record is deleted.

## Reader/writer transition

| Phase | New code | Old rc.1/rc.2 code | Operational rule |
|---|---|---|---|
| Before activation | Reads legacy HTTPS and encrypted URLs; writes encrypted URLs | Reads/writes plaintext URLs | Stop checkout, cron and requests; drain old workers before replacing code |
| Interrupted activation | Reads either representation; retries migration safely | Cannot use encrypted redirect URL correctly | Keep maintenance enabled; resume activation using new code and original key |
| After activation | Reads both; all migrated/new URLs encrypted | Cannot read new URL format; activation rejects schema 2 | Do not mix old and new workers or restore old files alone |

This is a maintenance-window migration, not a rolling mixed-version deployment. Conditional updates avoid overwriting a changed URL, but cannot make old workers safe; the operator must drain them first. Re-running activation scans legacy URLs even after version 2, repairing accidental old writes once their source is stopped.

## Forward, rollback and recovery

Back up database, files and encryption key before the maintenance window. Replace code atomically after stopping requests and cron; terminate/reload PHP workers, activate the addon, verify schema 2 and no remaining legacy URLs, then resume cron and traffic. Preserve addon role configuration. Addon deactivation retains data.

If encryption/key/data validation fails, leave traffic paused and fix the key or damaged record through a reviewed recovery process, then retry activation. Already encrypted rows and ledger fields remain intact. Prefer a forward fix. After the first schema-2 write, restoring old files alone is unsupported. A complete pre-upgrade restore is safe only when no post-backup provider/accounting changes occurred; otherwise reconcile and preserve newer money records before any restore. Do not decrypt URLs back to plaintext, drop tables, reset tokens or change amounts to force a downgrade.

Earlier backups may contain plaintext hosted URLs. Protect and retire them under the merchant's existing secret/data-retention policy; migration cannot rewrite historical backups.

## Proof and remaining gate

Regression tests cover whole-row token disclosure, preserved fields, repeat activation, interrupted encryption, corrupted ciphertext, future-schema rejection and a 101-row batch boundary. Real MySQL exercises encrypted storage and legacy URL migration with unchanged ledger fields. HTTP checkout still redirects correctly from decrypted state. Tests use authenticated sodium fixture encryption; actual WHMCS key/adapter behavior requires licensed staging acceptance. PHP version and suite results are recorded in TEST-REPORT.md.
