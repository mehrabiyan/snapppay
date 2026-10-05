# Delivery status — 2026-10-05

Release candidate 1.0.0-rc.4; production certification and deployment remain incomplete.

| Requested outcome | Delivered evidence | Remaining work |
|---|---|---|
| Read provider documentation | Public academy inventory, all 16 technical sections, embedded examples and API coverage in SOURCE-REVIEW.md/API.md | Merchant-specific portal material and pre-demo/demo acceptance need merchant access; no claim to inaccessible content |
| Understand WHMCS module contracts | Official gateway/addon/hooks/core/API/version research, pinned stock template contracts in WHMCS-INTEGRATION.md | Licensed core permissions, provisioning, native refund and supported theme/version checks |
| Plan before implementation | SPEC.md and ADRs 0001–0004; security follow-up ADR 0005 | Merchant confirmation of net cart allocation/category/methods |
| Full documented API functionality | OAuth, eligibility, token, verify, settle, status, revert, cancel and update; durable accounting recovery | Live API acceptance with issued credentials and whitelists |
| Modern client/admin interface | Responsive local assets, English/Persian RTL client card, administrative operations/refund previews, static read-only previews | Desktop/mobile/RTL/theme screenshot review; browser debugger/permissions unavailable |
| Security/injection review | SECURITY.md, exact-money/ownership/CSRF/XSS/SQL/SSRF/TLS/replay/crash tests; hosted URL encryption migration | Independent review and licensed-core/hosting acceptance |
| Unit, integration and E2E execution | 132 named tests on PHP 8.1/8.2/8.3, real MySQL four scenarios, real TLS nine checks, HTTP simulator 44 assertions; report includes exact limits | Remote CI, licensed WHMCS + provider stage E2E and production load checks |
| Admin customer fee (rc.4) | Fixed IRR or percentage fee as a synced `SnappPayFee` invoice line; eligibility, card, selectors, provider amount, cart and refunds include it (ADR 0006) | Licensed WHMCS hook order/`updateInvoiceTotal`/PDF-email check; merchant contract approval for surcharging |
| Production preparation | Deterministic ZIP/manifest/checksum, installation, migration/rollback/recovery runbook and LIVE-ACCEPTANCE.md | Named target, staging/production access, acceptance evidence and deployment |

Stock Standard Cart and Twenty-One collection shapes are covered by local tests. Six's opaque invoice dropdown and WHMCS 9 Nexus need distinct acceptance. Candidate requires gateway currency conversion and Mass Payment disabled; composite invoices are not explicitly rejected by code. MySQL 8 was tested; MariaDB is not yet accepted.

No live payment, production deployment, native provisioning or browser visual certification has been executed. Access needed to finish: licensed staging WHMCS/version/theme, SnappPay stage credential references and whitelisted IP/return domain, then an identified production target after live acceptance. Provide secret references through a secure secret manager rather than documentation or chat.
