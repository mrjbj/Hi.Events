# Feature Backlog

A living list of features and improvements we want to build. Append ideas here as they
come up (`/backlog "<idea>"`); move them to **Shipped** when they land. This is the
durable list — the [session handoff](./SESSION-HANDOFF.md) is the *current* in-flight
snapshot and links here, but does not own this list.

Each entry: `- [ ] <title> — <one-line what> (<date added>)`. Where a fuller rationale
exists, the entry points at the deeper note. Designs for all open ideas live in
[backlog-designs.md](./backlog-designs.md).

## Open ideas

- [ ] **Contact merge** — merge two duplicate contacts into one canonical record, reassigning their orders/attendees/history to the survivor and retiring the dup. Should be silenceable (see admin email suppression). ([design](./backlog-designs.md#6-contact-merge)) (2026-05-31)

- [ ] **Admin email suppression** — let admins stop outbound notifications when editing email addresses. Re-scoped after a code dig: admin back-office edits already send no email, so only a **per-action toggle** on the public self-service edit modals + check-in door is needed; the global session kill-switch was dropped as a footgun. ([design](./backlog-designs.md#5-admin-email-suppression--per-action-toggle-scoped-down)) (2026-05-31)

- [ ] **Bug: kebab click injects a stray char into the Orders filter** — intermittently, after setting a filter on the Orders page, clicking the "Manage order" kebab drops a random character into the *start* of the filter input (as if typed), which re-filters and hides the row the operator was acting on; they must re-enter the filter. Works on the next try. Likely a focus/keydown leak — the kebab-trigger keypress (or an autofocus stealing the event) is routed into the still-focused search field. ([design](./backlog-designs.md#7-bug-kebab-click-injects-a-stray-char-into-the-orders-filter)) (2026-05-31)

## Shipped

- [x] **Orders payment-type filter** — single combined "Payment type" multi-select on the Orders page (Cash / Check / Card / Bank transfer / Comp / Donation / Stripe); backend `OrderRepository::applyPaymentTypeFilter()` routes each value to the right ledger column / relation. _Note: shipped without the repository unit tests the design called for — coverage added separately._ ([design](./backlog-designs.md#4-orders-payment-type-filter)) (`42c26ad9`, 2026-05-31)

- [x] **Escape-clears-filters everywhere** — shared `useEscapeClearsFilters` hook now consumed by check-in, Orders, Attendees, and Contacts. ([design](./backlog-designs.md#1-escape-clears-filters-everywhere)) (`42c26ad9`, 2026-05-31)

- [x] **Check-in overpayment → donation prompt** — payment exceeding outstanding prompts to record the excess as a `DONATION` row with the same method; wired through both the check-in door and the Manage-Payments panel, with backend split + unit tests. ([design](./backlog-designs.md#3-check-in-overpayment--donation-prompt)) (`42c26ad9`, 2026-05-31)

- [x] **Guard the payment card when an order is fully settled** — the Manage-Payments add-transaction form locks once `payment_balance.isSettled`; an explicit confirm unlocks it so a second transaction is deliberate. ([design](./backlog-designs.md#2-guard-the-payment-card-when-an-order-is-fully-settled)) (`42c26ad9`, 2026-05-31)

- [x] **Dashboard card layout + Funds-by-Channel alignment polish** — reordered cards (charts → reconciliation pair → mini-cards), vertically centered + narrowed the editable Fees input, shortened Channel/Fees columns so all 7 columns fit, stopped reconciliation tile labels wrapping. (`6889e6ac`, 2026-06-01)

- [x] **`order_payments` type-split + offline-column cleanup** — split the overloaded `type` column into `transaction_type` + `payment_method`, dropped legacy offline columns. (`b7b51df9`, 2026-05-31)
