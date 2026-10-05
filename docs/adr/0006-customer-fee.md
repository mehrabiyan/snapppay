# ADR 0006 — Admin-configured SnappPay customer fee

Accepted 2026-10-05 for 1.0.0-rc.4.

## Decision

Gateway settings add **Customer fee** (`No fee`, `Fixed amount`, `Percentage of payable amount`), **Fee value** and **Fee invoice line**. Fixed values are whole Rials (IRR) for every invoice currency. Percentages accept 0–100 with up to two decimals and are stored as basis points. Invalid values fail closed with `invalid_fee`, like other configuration errors.

The fee is a real WHMCS invoice line with module-owned item type `SnappPayFee`, not an extra provider-only amount. The invoice balance, provider `amount`, cart items, callback amount check, `addInvoicePayment` amount and refunds therefore stay one number. Charging more than the WHMCS balance would create overpayment credit, and charging less would leave the invoice part-paid.

- **Base:** current invoice balance less any existing SnappPay fee line. Taxes, credits, discounts and earlier payments are already in the balance. The fee line is untaxed.
- **Rounding:** percentage fee uses exact integer arithmetic and rounds half-up to one whole unit of invoice currency: 1 rial for IRR, 10 rials (1 toman) for IRT/TMN. A fixed fee is applied as entered.
- **Sync points:** `InvoiceCreationPreEmail` and `InvoiceCreated` add the line before the invoice email where core supports it. `InvoiceChangeGateway` adds or removes it. The start route re-syncs under the invoice advisory lock, after ownership/status/gateway checks and before amount, eligibility, cart and token creation. This covers invoices created before the fee was enabled, admin edits and setting changes.
- **Removal:** a fee line is removed when the invoice moves to another gateway or the fee is disabled. Only `Unpaid` invoices change. If earlier payments already cover part of the fee (balance ≤ existing fee), the line is never rewritten.
- **Display:** the invoice card, Standard Cart/Twenty-One selectors and footer terms show `SnappPay fee` and the fee-inclusive total. Provider eligibility is always requested for the fee-inclusive amount.
- **Write path:** in one Capsule transaction, delete `tblinvoiceitems` rows of type `SnappPayFee`, insert at most one replacement, then call core `updateInvoiceTotal`.

## Consequences

The fee is part of the itemized provider cart, so partial refunds reduce it proportionally like any other line, and a full refund returns it. Changing fee settings while a buyer is on SnappPay checkout can change a later start for the same invoice. The existing invoice-drift guard then sends the older attempt to `review` instead of crediting a wrong amount. Change fee settings during a quiet window.

Fee lines on unpaid invoices are not removed if the gateway is deactivated, because core reassignment fires no module hook. Set the fee to `No fee` and reconcile open invoices before deactivating. Surcharging customers can be restricted by the merchant's SnappPay contract or by consumer rules. Merchants must confirm this before enabling it. Licensed WHMCS acceptance must confirm the hook order, `updateInvoiceTotal` totals/tax and invoice/PDF/email rendering.
