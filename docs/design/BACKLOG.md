# Feature Backlog

A living list of features and improvements we want to build. Append ideas here as they
come up (`/backlog "<idea>"`); move them to **Shipped** when they land. This is the
durable list — the [session handoff](./SESSION-HANDOFF.md) is the *current* in-flight
snapshot and links here, but does not own this list.

Each entry: `- [ ] <title> — <one-line what> (<date added>)`. Where a fuller rationale
exists, the entry points at the deeper note. Designs for all open ideas live in
[backlog-designs.md](./backlog-designs.md).

## Open ideas

- [ ] **Dashboard card layout + Funds-by-Channel alignment polish** — (1) fix the vertical misalignment of the editable **Fees** text input vs. the other numeric cells in "Funds by Channel" (likely input margin), and narrow the Fees column assuming fees ≤ $999.99; (2) tighten the "Channel" column too, freeing width so "Event Reconciliation" can render its three nested cards with aligned numbers and a non-wrapping "Net Expected Funds" title; (3) reorder the dashboard — put "Product Sales" + "Product Revenue" side-by-side on one row at the top, then "Event Reconciliation" + "Funds by Channel", then the legacy mini-cards below. (2026-06-01)

- [ ] **Contact merge** — merge two duplicate contacts into one canonical record, reassigning their orders/attendees/history to the survivor and retiring the dup. Should be silenceable (see admin email suppression). ([design](./backlog-designs.md#6-contact-merge)) (2026-05-31)

- [ ] **Admin email suppression** — let admins stop outbound notifications when editing email addresses. Re-scoped after a code dig: admin back-office edits already send no email, so only a **per-action toggle** on the public self-service edit modals + check-in door is needed; the global session kill-switch was dropped as a footgun. ([design](./backlog-designs.md#5-admin-email-suppression--per-action-toggle-scoped-down)) (2026-05-31)

- [ ] **Orders payment-type filter** — filter the Orders page by payment type (single combined dropdown: Cash / Check / Card / Bank transfer / Comp / Donation / Stripe), backend routing each value to the right `order_payments` column or the Stripe relation. ([design](./backlog-designs.md#4-orders-payment-type-filter)) (2026-05-31)

- [ ] **Escape-clears-filters everywhere** — the check-in page already clears filters on `Esc`; factor that into a shared hook applied to Orders, Contacts, and Attendees too. (SSR — guard `document`/keyboard listeners.) ([design](./backlog-designs.md#1-escape-clears-filters-everywhere)) (2026-05-31)

- [ ] **Guard the payment card when an order is fully settled** — on the "Manage orders" payment panel, lock the add-transaction form once `payment_balance.isSettled`; clicking the lock pops a confirm and only then unlocks, making a second transaction deliberate. Frontend-only. ([design](./backlog-designs.md#2-guard-the-payment-card-when-an-order-is-fully-settled)) (2026-05-31)

- [ ] **Check-in overpayment → donation prompt** — when a recorded payment exceeds outstanding, prompt "Record the extra as a donation?" (door + Manage-Payments) and on yes write a second `DONATION` row for the excess with the same method. Schema prerequisite landed `b7b51df9`. ([design](./backlog-designs.md#3-check-in-overpayment--donation-prompt)) (2026-05-31)

- [ ] **Bug: kebab click injects a stray char into the Orders filter** — intermittently, after setting a filter on the Orders page, clicking the "Manage order" kebab drops a random character into the *start* of the filter input (as if typed), which re-filters and hides the row the operator was acting on; they must re-enter the filter. Works on the next try. Likely a focus/keydown leak — the kebab-trigger keypress (or an autofocus stealing the event) is routed into the still-focused search field. ([design](./backlog-designs.md#7-bug-kebab-click-injects-a-stray-char-into-the-orders-filter)) (2026-05-31)

## Shipped

- [x] **`order_payments` type-split + offline-column cleanup** — split the overloaded `type` column into `transaction_type` + `payment_method`, dropped legacy offline columns. (`b7b51df9`, 2026-05-31)
