# Payment trust and recovery boundaries

```mermaid
flowchart LR
    C[Authenticated invoice owner] -->|POST + CSRF + form nonce| S[Start route]
    S -->|Server invoice + exact IRR cart| O[Payment service]
    O -->|Fresh OAuth / fixed HTTPS endpoints| P[SnappPay API]
    O --> D[(Payment intent / encrypted token / audit)]
    S -->|Allowlisted hosted URL| H[Provider checkout]
    H -->|Untrusted public POST hint| B[Callback route]
    B --> O
    O -->|Matched SETTLE + final invoice check| W[WHMCS ledger helpers]
    W --> I[Core invoice / provisioning]
    A[Role-authorized admin] -->|Confirmed native refund| O
    R[Bounded cron] -->|Status-driven recovery| O
```

Connection-scoped invoice lock spans provider/ledger work without a long SQL transaction. Durable state precedes each mutation. Exact original balance remains immutable; current remaining provider amount/cart changes only after confirmed refund. Snapshot/provider authentication controls accounting, not callback state. WHMCS owns invoice totals, native refund bookkeeping, provisioning, notifications and permissions.

```mermaid
stateDiagram-v2
    [*] --> creating
    creating --> pending: valid encrypted token + safe hosted URL
    creating --> token_unknown: ambiguous/error token result
    pending --> verifying: correlated OK + authenticated PENDING
    verifying --> settling: authenticated VERIFY
    settling --> settled: authenticated SETTLE / exact ID + amount
    settled --> paid: matching WHMCS ledger record
    pending --> reverted: provider REVERT
    paid --> refunding: native authorized refund intent
    refunding --> paid: confirmed partial UPDATE + accounting handoff
    refunding --> cancelled: confirmed full CANCEL + accounting handoff
    pending --> review: invoice drift / verification exhaustion
    settling --> review: invoice changed during provider request
```

Unknown token and review states require operator investigation. Refund intent remains until native WHMCS ledger matches saved refund ID/amount; subsequent calls cannot receive the same handed-off success twice. Read-only provider inspection works independently of terminal/review recovery decisions.
