# BNPL API coverage and contract

Primary contract: [SnappPay self-CMS v2.1](https://academy.snapppay.ir/self-cms/), all 16 sections and embedded examples. Exact merchant API origin is configuration; all endpoints are fixed paths, never caller-supplied. Each operation obtains fresh OAuth JWT; API client never persists JWT or forwards raw response into user HTML/logs.

| Operation | Method/path | Module behavior | Proof |
|---|---|---|---|
| OAuth | POST `/api/online/v1/oauth/token` | Basic client_id:client_secret; form grant_type=password, scope=online-merchant, username/password; validated access token | Every lifecycle call + wire contract tests |
| Eligible | GET `/api/online/offer/v1/eligible` | Integer IRR amount; optional comma-separated paymentMethodTypes; hide false; escaped unmodified title_message/description | Unit, checkout-hook HTTP E2E |
| Token | POST `/api/online/payment/v1/token` | Persist unique alphanumeric transaction ID before request; itemized cart, net balance, mobile, exact returnURL, optional forcedPaymentMethodTypes; encrypted token and allowlisted paymentPageUrl | Integration, HTTP checkout E2E |
| Verify | POST `/api/online/payment/v1/verify` | Saved paymentToken; durable intent/count, matched transaction ID; recover through authenticated status; bounded retry on PENDING | Replay/timeout/crash/forgery tests |
| Settle | POST `/api/online/payment/v1/settle` | Only VERIFY; saved token; independent SETTLE+amount+ID proof before credit; final invoice reread | Lost-response, failed-settle, drift tests |
| Status | GET `/api/online/payment/v1/status` | Saved paymentToken; status enum and exact integer amount/ID checks; separate read-only operator inspection allows drift investigation | State mismatch, operator inspect, refunds |
| Revert | POST `/api/online/payment/v1/revert` | Provider-approved feature toggle, addon-role admin confirmation, only PENDING/VERIFY, never credited/settled; status confirms REVERT | Approval and state boundary tests |
| Cancel | POST `/api/online/payment/v1/cancel` | Native confirmed admin full remaining refund; one durable accounting handoff; verify CANCEL with prior or zero amount | Full refund HTTP/SQL tests |
| Update | POST `/api/online/payment/v1/update` | Native confirmed partial refund; strictly reduced amount and saved itemized cart; exhausted items removed; status exact target amount | Partial refund/timeout/cart/SQL tests |

Token/update carts include cartId, cartItems (id/name/category/amount/count/commissionType), isShipmentIncluded=true, isTaxIncluded=true, shippingAmount=0, allocated included taxAmount and exact totalAmount. Token includes discountAmount=0/externalSourceAmount=0 because invoice balance adjustments are allocated into net items; no double subtraction. See ADR 0002. Tax and proportional adjustment semantics require merchant demo sign-off.

Provider success requires `successful === true` plus object-like response. OAuth response is not wrapped. Mutation response transactionId must match persisted merchant transaction. Status cannot authorize wrong ID/amount, unknown enum, fractional/float/negative amount or untrusted success callback. Public callback accepts POST transactionId/state/amount only; original invoice balance is immutable correlation amount, including after refunds.

No subscription/card capture API exists in this contract. New renewal invoices require buyer checkout. Product search feeds, vouchers, merchant financial reporting, advertisements and portal account management are separate academy topics, not BNPL payment endpoints. Module does not fabricate APIs for these.
