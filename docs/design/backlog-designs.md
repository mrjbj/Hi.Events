# Backlog — Feature Designs

Per-feature designs for the open ideas in [BACKLOG.md](./BACKLOG.md). Each was a quick
capture; these are the deliberative pass. Decisions marked **(confirmed)** were settled with
Jason on 2026-06-01; everything else is a sensible default, called out where it matters.

Ordered low→high effort. Skim the **Decision** and **Files** lines per section to plan.

---

## 1. Escape-clears-filters everywhere

**Problem.** The check-in page clears its filters on `Esc`; Orders / Attendees / Contacts
don't. Inconsistent, and clearing filters by hand is high-frequency.

**Decision.** Factor the check-in handler into one shared hook; apply to the three list
pages. No backend. No questions — well-specified.

**Source of truth.** `frontend/src/components/layouts/CheckIn/index.tsx:181-206` —
document-level `keydown`, bails if a Mantine modal is open (`[role="dialog"]`), then clears
in priority order (search query first, then the active filter), `preventDefault` only when
it actually cleared something, and refocuses the search input.

**Approach (frontend only).** New hook `frontend/src/hooks/useEscapeClearsFilters.ts`:

```ts
useEscapeClearsFilters({
  steps: Array<{ isActive: () => boolean; clear: () => void }>, // run in order, stop at first active
  onCleared?: () => void,                                       // e.g. refocus search
  enabled?: boolean,
})
```

- SSR-guard: `if (typeof document === 'undefined') return;` before binding (CLAUDE.md rule).
- Skip when a dialog is open — keep the existing `querySelector('[role="dialog"]')` check.
- The `steps` array absorbs the three different state shapes the pages use:
  - **Check-in** (`CheckIn/index.tsx`): local state — `searchQuery`, then `attendeeFilter`.
    Refactor to consume the hook so there's one implementation, not two.
  - **Orders** (`routes/event/orders.tsx`) and **Attendees** (`routes/event/attendees.tsx`):
    URL-backed via `useFilterQueryParamSync` — `clear` calls `setSearchParams` to drop
    `query` then `filterFields` (Attendees already has `handleResetFilters` to reuse).
  - **Contacts** (`routes/contacts/ContactsAdmin/ContactsTab.tsx`): local `query` +
    `eventFilter` (+ sort) — `clear` calls the setters, reset page to 1.

**Edge cases.** Don't swallow `Esc` when nothing is active (let it bubble — e.g. closing a
dropdown). Don't fire while focus is in a modal. Multiple list pages can mount the hook
independently; each binds/unbinds its own listener on unmount.

**Test plan.** Vitest on the hook: dialog-open → no-op; step ordering; no-op when all
inactive. Manual: Esc on each page clears in the right order.

**Effort.** ~Half a day, frontend only.

---

## 2. Guard the payment card when an order is fully settled

**Problem.** On the Manage-Orders payment panel the add-transaction form is always live, so
an operator can record a second payment against an order that's already settled and create a
phantom overpayment.

**Decision.** Lock the add-transaction form when settled; an explicit unlock (confirm)
makes a second transaction deliberate. Pure frontend — the data we need is already on the
payload. No questions.

**What we have.** `OrderBalanceService::calculate()` sets `isSettled = balance <= 0.0`
(`backend/app/Services/Domain/Order/OrderBalanceService.php:83`), surfaced to the client as
`order.payment_balance.isSettled` via `OrderResource` (`:66-69`). Note this is `<= 0`, so
**overpaid orders count as settled** — correct for this guard (don't pile on more). The
panel already renders a "Settled · overpaid {amount}" badge
(`frontend/src/components/common/OrderPaymentManagement/index.tsx:174-179`); the
add-transaction form below it (`:262-331`) has no guard today.

**Approach (frontend only).** In `OrderPaymentManagement/index.tsx`:
- Local `const [unlocked, setUnlocked] = useState(false)`.
- When `balance.isSettled && !unlocked`: render the add-transaction form **disabled**
  (inputs + submit), overlaid with a lock affordance — lock icon, "Fully settled" text,
  and a subtle "Add another transaction anyway?" link/button.
- Clicking the lock → Mantine confirm modal ("This order is fully settled — add another
  transaction anyway?"). On confirm: `setUnlocked(true)`, form becomes editable.
- Reset `unlocked` to `false` whenever the order reloads settled after a successful record
  (via the `onUpdated` callback path) so each new settled state re-locks.
- Unsettled orders: unchanged — form is live as today.
- The "Comp remaining" / reverse controls stay available regardless (reversing is the
  correct way to undo, and comp-remaining is a no-op at zero balance).

**Edge cases.** Overpaid (balance < 0) is still `isSettled` → locked, which is what we want.
Don't lock the reverse buttons. Keep the guard purely client-side — the backend still
accepts the transaction if the operator unlocks (this is UX friction, not an invariant;
the server already allows recording on COMPLETED orders).

**Test plan.** Component test: settled → form disabled + lock shown; confirm → enabled;
unsettled → form live. Manual smoke via the playwright flow on a settled order.

**Effort.** ~Half a day, frontend only.

---

## 3. Check-in overpayment → donation prompt

**Problem.** At the door, a $20 cash payment on a $15 order settles the order and derives
`overpaid $5`, but the $5 stays typed as a PAYMENT. To label it a donation the operator
must hand-add a second row. Overpayment-as-donation is the common door case.

**Decision (confirmed).** Prompt **in both** the check-in door modal and the back-office
Manage-Payments panel whenever the entered amount exceeds the outstanding balance; on "yes,"
write a second `DONATION` ledger row for the excess with the same payment method. Schema
prerequisite already landed (`b7b51df9` — `transaction_type` + `payment_method` split).

**What we have.**
- Door path: `CreateAttendeeCheckInService::recordDoorPayment()`
  (`backend/app/Services/Domain/CheckInList/CreateAttendeeCheckInService.php:228-268`) →
  when `amount >= outstanding` calls `MarkOrderAsPaidService::recordSettlementPayment()`
  which records a **single** PAYMENT row; overpaid is derived later, never split.
- Back-office: `RecordOrderPaymentService::record()`
  (`backend/app/Services/Domain/Order/RecordOrderPaymentService.php:27-82`) +
  `RecordOrderPaymentDTO` (`transactionType`, `paymentMethod`, `amount`, …).
- `OrderBalanceService` already attributes DONATION rows to the right channel; the donation
  enum path (`PaymentTransactionType::DONATION`, method required) is ready.

**Approach — make the split a deliberate, opt-in second write, not an automatic one.**

*Backend.* Add a small domain helper so both entry points share one code path. Option:
`RecordOrderPaymentService::recordWithOptionalDonationSplit(dto, bool $splitExcessAsDonation)`:
1. Compute `outstanding` for the order (reuse `OrderBalanceService`).
2. If `splitExcessAsDonation` and `dto.amount > outstanding > 0`:
   - First `record()`: `transactionType=PAYMENT`, `amount=outstanding`, given method.
   - Second `record()`: `transactionType=DONATION`, `amount=(amount - outstanding)`,
     **same** `paymentMethod`, `note` defaulted to "Overpayment recorded as donation".
   - Both inside one `DB::transaction()`.
3. Else: today's single `record()`.

Wire the flag through:
- `RecordOrderPaymentDTO` gains `?bool $splitExcessAsDonation` (default false → fail-safe,
  behaviour unchanged when absent). DTO extends `BaseDataObject` (CLAUDE.md).
- `RecordOrderPaymentRequest` validates the new optional boolean.
- Door: `MarkAsPaid` / check-in payload gains the same flag; `recordDoorPayment()` passes it
  through to the shared helper instead of the single-row `recordSettlementPayment()`.

*Frontend.* Two prompts, same logic ("when amount > owed, offer the split"):
- **Door** — `CheckIn/CheckInOptionsModal.tsx`: when `amount > owed`, show an inline
  confirm ("Record the extra {excess} as a donation?") with the split flag defaulting to
  **on** (door overpayment is almost always a donation — no change given).
- **Manage Payments** — `OrderPaymentManagement/index.tsx` add-transaction form: when the
  typed amount exceeds outstanding, show the same prompt, defaulting **off** (back-office
  edits are more deliberate). Submit passes `splitExcessAsDonation`.

**Edge cases.** Only split when `transactionType` of the incoming row is PAYMENT (don't
split a COMP or an already-DONATION row). `outstanding <= 0` (already settled) → no split,
the whole amount is a donation only if the operator explicitly chose DONATION. Reversals are
unaffected. Method must carry to the donation row (the split's whole point — a card
overpayment becomes a card donation).

**Test plan.** Unit (`backend/tests/Unit/`, `DatabaseTransactions`): amount>outstanding
with flag → two rows (PAYMENT=outstanding, DONATION=excess, same method); flag off → one
row; amount==outstanding → one row, no donation; non-PAYMENT type → no split. Frontend:
prompt appears only when amount>owed; default on at door, off in back-office.

**Effort.** ~1.5 days (backend helper + DTO/request + two UI prompts + tests).

---

## 4. Orders payment-type filter

**Problem.** No way to filter the Orders list by how it was paid. Now that payments are
first-class `order_payments` rows this is possible.

**Decision (confirmed).** A **single combined "Payment type" dropdown** —
Cash · Check · Card · Bank transfer · Comp · Donation · Stripe — with the backend routing
each value to the right column or join. (The values span two columns plus a separate Stripe
table, but the operator shouldn't have to know that.)

**What we have.**
- Generic filter framework: `BaseRepository::applyFilterFields()`
  (`backend/app/Repository/Eloquent/BaseRepository.php:441-492`) applies whitelisted
  `FilterFieldDTO`s against **order columns**. Allowed fields live on
  `OrderDomainObject::getAllowedFilterFields()` (`:51-65`).
- Payment data is on the **child** `order_payments` table (`transaction_type`,
  `payment_method`), not on `orders` — so this can't be a plain allowed-field; it needs a
  `whereHas` join, exactly like the existing `applyProductIdFilter()`
  (`OrderRepository.php:76-105`, which joins `order_items` for `product_id`).
- Stripe payments are **not** in `order_payments` — they're a separate stripe-payment
  relation. So "Stripe" is a third routing case (orders that have a stripe payment).
- Frontend filter UI is declarative: `filterOptions` array in
  `frontend/src/components/routes/event/orders.tsx:49-68` feeds `FilterModal`; URL sync via
  `useFilterQueryParamSync`.

**Approach.**

*Backend.* Don't shoehorn this into `getAllowedFilterFields` (those map 1:1 to columns).
Add a dedicated special-case in `OrderRepository::findByEventId()` mirroring the product_id
pattern: intercept a `payment_type` filter field before the generic pass and dispatch:
- `cash|check|credit_card|bank_transfer|other` → `whereHas('order_payments', fn($q) =>
  $q->whereNull('reverses_payment_id')->where('payment_method', $value))`.
- `comp|donation` → `whereHas('order_payments', … ->where('transaction_type', $value))`.
  (COMP and DONATION are `transaction_type` values, not methods.)
- `stripe` → `whereHas('stripePayment')` (or the actual relation name — confirm in
  `OrderDomainObject`).
- Support multi-select (operator picks several) → `IN`-style with grouped `orWhere` inside
  one `whereHas`. Filter only on non-reversed rows so a fully-reversed payment doesn't match.

Keep the mapping (value → column/relation) in one private method, e.g.
`applyPaymentTypeFilter(Builder $q, array $values)`, so the routing is testable in isolation.

*Frontend.* Add one entry to the `filterOptions` array in `orders.tsx`:
`{ field: 'payment_type', label: t('Payment type'), type: 'multi-select', options: [...] }`
with the seven values above (labels via `t()` — CLAUDE.md). No new components — `FilterModal`
+ URL sync already handle multi-select. Add translations immediately (`/translations`),
English only per Jason's standing preference.

**Edge cases.** An order with both a card payment and a comp matches *both* `card` and
`comp` — correct (it has both). Reversed payments shouldn't make an order match a method it
no longer has → filter on `reverses_payment_id IS NULL` and exclude rows that *are*
reversed (a reversed-out method should arguably not match; simplest correct rule: match if
any non-reversal, non-reversed row of that type exists). Stripe + offline on the same order
both match. Empty selection → no filter.

**Test plan.** Repository unit tests (`DatabaseTransactions`): seed orders with each
payment shape, assert each filter value returns exactly the matching orders, multi-select
unions, reversed-only payment doesn't match.

**Effort.** ~1.5 days (repository routing + tests + one frontend filter entry).

---

## 5. Admin email suppression — per-action toggle (scoped down)

**Problem (re-scoped, confirmed).** Original idea was per-action toggle **and** a global
per-session "no emails" switch. My code dig changed the picture: **admin back-office edits
already send no email** — `EditAttendeeHandler` and `UpdateContactHandler` fire domain
events but no mail. The "details changed" notifications come only from the **public
self-service edit modals** and the **check-in door**. So:
- The global session kill-switch is **dropped** — it would solve a non-problem and is a
  footgun (forget to flip it off → real order confirmations silently vanish).
- We build only the **per-action "don't notify" toggle**, on the paths that actually email.

**What we have.**
- Three direct `Mail::...->queue()` sites that bypass the suppression chokepoint and send
  the edit notifications:
  - `SelfServiceEditAttendeeService.php:242` (`AttendeeDetailsChangedMail`)
  - `SelfServiceEditOrderService.php:149` (`OrderDetailsChangedMail`)
  - `PatchCheckInListAttendeePublicHandler.php:189` — **already gated** by a
    `notify_email_change` flag (`:132-139`). This is the pattern to copy.
- The transactional chokepoint `TransactionalEmailTrackingService::recordAndSend()` checks
  SES bounce/complaint suppression but is **not** on these three edit paths.

**Approach — a `notify` flag threaded to the send site, default true (fail-safe).**

The door handler already proves the shape. Generalise it:
- Add `?bool $notifyEmailChange` (default `true`) to the two self-service DTOs/requests:
  `EditAttendeePublicDTO` / `EditAttendeePublicRequest`, `EditOrderPublicDTO` /
  `EditOrderRequest`. Absent → true → today's behaviour (fail-safe per the original note).
- In `SelfServiceEditAttendeeService` / `SelfServiceEditOrderService`, wrap the
  `Mail::queue()` calls in `if ($notify) { … }`.
- Door handler: already done — no change beyond surfacing the toggle in its UI if missing.

*Frontend.* The edit modals already render a hard-coded warning that the old address will
be notified — turn that warning into a warning **+ toggle**:
- `EditAttendeeModal/index.tsx:111-128` and `EditOrderModal/index.tsx:39-81`: when the
  email field changed, show the existing warning plus a "Notify the contact of this change"
  switch (default **on**). Pass `notify_email_change` through the mutation.
- Surface the toggle only when the email actually changed (name-only edits send nothing).

**Why not a chokepoint refactor?** Routing all three through
`TransactionalEmailTrackingService` would be cleaner long-term, but it's a bigger change and
not needed for this toggle. Note it as a separate cleanup; don't couple it to this feature.

**Deliberately out of scope.** Global session switch; suppressing the SES-level
bounce/complaint logic (that's automatic and correct as-is). If bulk cleanup truly needs a
"send nothing" mode later, revisit — but scope it to a single screen's batch action, not a
session-wide flag.

**Edge cases.** Default-true everywhere so a missing flag never silences a real
notification. Toggle is per-edit, not sticky. Name-only edits: no email regardless, so no
toggle shown.

**Test plan.** Unit: service sends when `notify=true`/absent, suppresses when `false`, for
both attendee and order edit paths. Frontend: toggle appears only on email change; default
on; value reaches the request.

**Effort.** ~1 day (two DTOs/requests + two service guards + two modal toggles + tests).

---

## 6. Contact merge

**Problem.** Duplicate contacts accumulate (re-registrations under slightly different
emails/names). Today the only cleanup is a manual soft-delete, which **orphans history** —
attendees get their `contact_id` nulled. No first-class merge.

**Decision (confirmed).** Merge two contacts into one survivor; reassign the dup's children
to the survivor; **survivor wins, fill gaps** for attribute conflicts (keep survivor's
values, take the dup's only where the survivor's field is empty); soft-delete the dup. Must
be silenceable — but per feature #5 the contact path sends no email anyway, so "silenceable"
is automatically satisfied here.

**What we have.**
- `contacts`: `id, account_id, email, first_name, last_name, attributes (jsonb),
  attributes_history (jsonb), processed_question_answer_ids, ignored_question_answer_ids`,
  `SoftDeletes`. Unique index on `(account_id, lower(email)) WHERE deleted_at IS NULL`.
- **Only one FK child:** `attendees.contact_id` (nullable, `onDelete('set null')`,
  `Attendee` belongsTo `Contact`). No orders FK to contacts — orders reach contacts only
  through attendees. This makes merge tractable: reassign attendees, fold JSONB, retire dup.
- Existing conflict-resolution precedent in the codebase: `ApplyConflictDecisionsAction`,
  `ApplyStaleValueRemapsAction` (contact attribute reconciliation) — same house style.
- `DeleteContactHandler` already does the "null out attendees then soft-delete" dance; merge
  is that, but reassign-instead-of-null.

**Approach.**

*Backend — new Action → Handler → Service.*
- `MergeContactsAction` (POST `/accounts/{account_id}/contacts/{survivor_id}/merge`, body
  `{ source_contact_id }`). Extend `BaseAction`, `isActionAuthorized`, `resourceResponse()`
  returning the merged survivor (CLAUDE.md).
- `MergeContactsHandler` → `MergeContactsService::merge(int $survivorId, int $sourceId,
  int $accountId)`, all in one `DB::transaction()`:
  1. Load both, assert same `account_id` (ownership), assert distinct, assert neither
     soft-deleted. Custom exception (`ContactMergeException`) → caught in action →
     `ValidationException::withMessages()` (CLAUDE.md: no generic exceptions).
  2. **Reassign children:** `attendeeRepository->updateWhere([CONTACT_ID => $sourceId],
     [CONTACT_ID => $survivorId])`. (Reuse existing repo method — no bespoke one.)
  3. **Fill gaps:** for `first_name`, `last_name`, and each key in `attributes`, set the
     survivor's value from the source **only where the survivor's is empty/null**. Survivor's
     non-empty values always win. Email: survivor's email is kept (it's the canonical one);
     the dup's email is retired with the dup.
  4. **Audit:** append a merge entry to the survivor's `attributes_history` recording the
     source id/email and which gap-fills were applied (so the merge is traceable —
     mirrors how `updateEmail()` already appends history).
  5. **Retire dup:** soft-delete the source contact (`deleteById`). The partial unique index
     (`WHERE deleted_at IS NULL`) frees the dup's email so it doesn't block future use.
- DTO for the result extends `BaseDataObject`.

*Frontend.* `ContactsTab.tsx` already has per-row actions (edit/delete) and selection. Add a
merge entry point:
- Simplest: a "Merge…" action on a contact row → modal to pick the *other* contact (the
  source) via the existing contacts search, then a confirm summarising "Keep **A**, fold in
  **B**, reassign B's N attendees, then remove B." Since the decision is survivor-wins/
  fill-gaps (not a per-field picker), the confirm just needs to name the survivor and show
  the gap-fills that will happen.
- New `useMergeContacts()` mutation (`mutations/`), invalidate `GET_CONTACTS_QUERY_KEY` and
  the two contact detail keys on success; `showSuccess`/`showError` from
  `utilites/notifications.tsx` (CLAUDE.md).

**Edge cases.** Self-merge (same id) → reject. Cross-account → reject (ownership). One side
already soft-deleted → reject. Survivor selection is the operator's choice in the UI — be
explicit which record survives (its email/id is canonical). Attendees already linked to the
survivor are untouched. `processed/ignored_question_answer_ids`: union the two arrays so the
survivor inherits the dup's processed/ignored question state (avoids re-surfacing
already-handled prompts). Merge is **not** reversible from the UI — the dup is recoverable
only via its soft-delete row; state that in the confirm.

**Test plan.** Unit (`DatabaseTransactions`): attendees reassigned survivor←source; survivor
non-empty fields preserved; survivor empty fields filled from source; history gets a merge
entry; source soft-deleted; email freed; self/cross-account/deleted-side all rejected;
question-id arrays unioned.

**Effort.** ~2–3 days (action/handler/service + DTO + exception + repo wiring + merge modal
+ mutation + tests). Heaviest item on the list.

---

## 7. Bug: kebab click injects a stray char into the Orders filter

**Symptom.** Intermittently, after a filter is set on the Orders page, clicking the
"Manage order" kebab drops a character into the *start* of the search/filter input (as if
typed), which re-filters and hides the row being acted on. Retrying works.

**This is an investigation plan, not a fix design** — the bug is intermittent and not yet
reproduced, so I won't fabricate a root cause. What I can do is narrow the field with what
the code shows.

**What I mapped.**
- Search box: `frontend/src/components/common/SearchBar/index.tsx`. The `SearchBar` holds a
  **local `searchValue` state mirrored from the controlled `value` via**
  `useEffect(() => setSearchValue(value), [value])` (`:55-59`), while the parent
  (`orders.tsx:147-154`) feeds `searchParams.query` from `useFilterQueryParamSync` (URL
  round-trip). So there are *two* sources of truth for the input value with an async hop
  between them — a classic place for keystroke races.
- Kebab: a Mantine `<Menu>` in `OrdersTable/index.tsx:149-205` (`ActionMenu`). Mantine
  `Menu` manages focus and has built-in **type-ahead** over its items; on open it moves
  focus into the dropdown, on close it returns focus to the trigger.
- **Ruled out:** there is no global "press a key / press `/` to focus search" hotkey
  anywhere in `src/` (grep found none). So the stray char is *not* a global shortcut
  replaying a key into the search box.

**Leading hypotheses (in order).**
1. **Controlled-vs-local state race in `SearchBar`.** The `value`↔`searchValue` mirror means
   when the menu open triggers a parent re-render (or the URL sync produces a new
   `searchParams` reference), the `useEffect` re-runs and can reorder against an in-flight
   keystroke/IME composition, flushing a buffered character to the field. The "at the start"
   detail fits a value reset followed by a late keystroke. *Likely fix:* drop the local
   mirror and make `SearchBar` fully controlled (or debounce the URL sync), so there's one
   source of truth.
2. **Mantine `Menu` type-ahead / focus return leaking a key.** If the click that opens the
   menu is part of a key sequence (keyboard-activated kebab, or focus returning to the
   search input on close while a key is still down), Mantine's type-ahead or focus-return
   could route a character. *Likely fix:* set `returnFocus={false}` on the `Menu` (or
   ensure the trigger isn't the search input's neighbour in tab order) and confirm the kebab
   `ActionIcon` `type="button"` so it never submits/propagates oddly.
3. **Portal remount of the input.** The menu dropdown is portalled; if opening it remounts
   the toolbar subtree, the `TextInput` could re-init with a stale value. Lower likelihood
   but cheap to check with React DevTools "highlight updates."

**Instrumentation / repro plan.**
- Add a temporary `onChange`/`onKeyDown` logger on the `SearchBar` `TextInput` capturing
  `event.nativeEvent.inputType`, `isComposing`, and a stack marker, plus a log in the
  `useEffect` mirror. Reproduce by setting a filter, then rapidly clicking the kebab on
  several rows — watch whether the stray char arrives via a real `input` event
  (`inputType: insertText`) or via the effect resetting `searchValue`.
- If it's the effect (hypothesis 1): the log shows no `input` event but a `searchValue`
  change — confirms the state race; fix by removing the mirror.
- If it's a real input event with `isComposing`/odd `inputType`: hypothesis 2/IME; pursue
  the Menu focus path.
- Capture a Playwright trace of the flow (`smoke-report` skill) to get a deterministic
  repro of the intermittent case.

**Effort.** ~0.5 day to instrument + reproduce; fix is likely small (≈half a day) once the
mechanism is confirmed — almost certainly hypothesis 1.

---

## 8. Persistent email-suppression flag (address-keyed)

**Problem.** Need a durable, address-keyed **"never email this address"** list that holds
across **both** marketing and transactional sends, plus default suppression of placeholder
junk (e.g. `unknown@unknown.com` from the bulk admin-entered orders). Distinct from the
per-action toggle (§5, "don't fire *this* notification") and complementary to the existing
SES bounce/complaint suppression.

**Decisions (confirmed 2026-06-01).** Extend the existing SUPERADMIN suppressions screen
with a "Do not contact" reason **and** add an inline "Never email this address" toggle on
the Contact edit modal · do-not-contact/placeholder suppression is **always-on** (decoupled
from the SES feature flag) · placeholder set is a **configurable pattern list**.

**This is mostly an *extend*, not a *build* — the infra already exists:**
- `email_suppressions` table (`account_id` nullable, soft-delete, unique on
  `email + account_id + reason`).
- `EmailSuppressionReasonEnum` (`BOUNCE`, `COMPLAINT`) ·
  `EmailSuppressionSourceEnum` (`SES_NOTIFICATION`, `MANUAL`, `MANUAL_RESOLVE`).
- `EmailSuppressionService::isEmailSuppressed()` / `suppressEmail()` / `removeSuppression()`
  (`app/Services/Domain/Email/EmailSuppressionService.php`).
- SUPERADMIN endpoints `GET/POST/DELETE /admin/email-suppressions`
  (`app/Http/Actions/Admin/EmailSuppressions/*`); `CreateEmailSuppressionAction` currently
  restricts `reason` to bounce|complaint and creates a **global** (`account_id = null`),
  `source = manual` row.
- Chokepoints already consult suppression: `TransactionalEmailTrackingService::recordAndSend()`
  (`:40`, type `transactional`), `SendEventEmailJob` (`:41`, `marketing`),
  `SendEventEmailMessagesService::sendMessage()` (`:433`, `marketing`).
- **Gaps:** no clean "do not contact" reason (today you'd fake a Permanent bounce); all
  suppression is gated behind `config('services.ses.suppression_enabled')`; three
  change-notification sends bypass suppression entirely (same as §5):
  `SelfServiceEditAttendeeService:242`, `SelfServiceEditOrderService:149`,
  `PatchCheckInListAttendeePublicHandler:189`. No system-generated placeholder emails exist
  (blank emails are simply skipped), so "placeholders" = admin-entered junk addresses.

**Approach — backend.**
1. **New reason** `DO_NOT_CONTACT = 'do_not_contact'` on `EmailSuppressionReasonEnum`.
2. **`isEmailSuppressed()` — suppress-all + decouple from the SES flag.** Restructure so the
   flag gates *only* the bounce/complaint evaluation; the new checks run unconditionally:
   ```
   $email = strtolower($email);
   if ($this->isPlaceholderAddress($email)) return true;          // always-on
   if ($this->hasReason($email, $accountId, DO_NOT_CONTACT)) return true; // always-on, all types
   if (! config('services.ses.suppression_enabled')) return false;
   … existing bounce/complaint logic …                            // unchanged
   ```
   `DO_NOT_CONTACT` suppresses both `marketing` and `transactional` (like a Permanent
   bounce), independent of `bounce_type`.
3. **Placeholder check** `isPlaceholderAddress(string $email): bool` — glob-match against a
   config list `config('mail.suppressed_address_patterns')`, defaulting to
   `['unknown@unknown.com', '*@unknown', '*@example.com', '*@noemail.*']` (lowercased;
   `fnmatch`-style). No DB row needed. Keep the default conservative so it can't match real
   addresses.
4. **Close the three bypass sites** for the persistent list: wrap each `Mail::...->queue()`
   in `if (! $this->emailSuppressionService->isEmailSuppressed($oldEmail, $accountId, 'transactional'))`.
   A placeholder/do-not-contact old address should never be mailed even a change-notice.
   (Each site already has the account in scope; pass `null` if not — global/placeholder
   still match.) Note this overlaps §5's three sites; do whichever feature lands first and
   the other reuses the guard.
5. **Admin create endpoint** — extend `CreateEmailSuppressionAction` + its request to accept
   `reason = do_not_contact` (bounce_type/complaint_type N/A for it); keep `source = manual`,
   `account_id = null` (global) to match today. (Per-account scoping is a deliberate
   follow-up, below.)

**Approach — frontend.**
1. **Superadmin suppressions screen** (the React screen backing `/admin/email-suppressions`):
   add "Do not contact" to the reason filter and the create-form reason dropdown. Translate
   immediately (English only).
2. **Contact edit modal** (`EditContactModal`, email field is read-only): add a **"Never
   email this address"** switch acting on the contact's email. A small query reports current
   `do_not_contact` state; toggling on → `POST` a do-not-contact suppression, off → `DELETE`
   it. New `useToggleEmailSuppression` mutation + a state query; `showSuccess`/`showError`.

**Edge cases & caveats.**
- **Suppress-all blocks transactional too** — including password-reset and order-confirmation
  emails. That's the point for placeholders; for a *real* do-not-contact address it means no
  receipts/resets either. Acceptable per the spec; call it out in the toggle's helper text.
- **Permission scope:** the contact toggle reuses the **SUPERADMIN-only** admin endpoints —
  fine for District11's superadmin operator, but a non-superadmin account admin couldn't use
  it. A per-account suppression endpoint + per-account `account_id` rows is the multi-tenant
  follow-up (deliberately out of scope here).
- **Coexistence:** `unique(email, account_id, reason)` lets a `do_not_contact` row coexist
  with SES `bounce`/`complaint` rows for the same address — don't collapse them.
- **Attendee→contact fallback:** `resolveAttendeeEmail()` already checks transactional
  suppression; a `do_not_contact` on the contact email correctly suppresses the fallback too.
- **Un-suppress** = soft-delete via `removeSuppression()`; the toggle-off path must target the
  `do_not_contact` reason specifically (not the address's bounce rows).
- Case-insensitive throughout (service already lowercases).

**Test plan** (Unit, Mockery / `DatabaseTransactions`):
- `do_not_contact` row → suppresses both `marketing` and `transactional`, **even when
  `ses.suppression_enabled = false`**.
- `isPlaceholderAddress` matches each default pattern, is case-insensitive, and does **not**
  match a normal address; placeholder returns suppressed with **no DB row** and regardless of
  the flag.
- Existing bounce/complaint behaviour unchanged when the flag is on; gated off when off —
  while do-not-contact/placeholder still suppress.
- `CreateEmailSuppressionRequest` accepts `do_not_contact`.
- Each of the three bypass sites: no mail queued when the old email is placeholder /
  do-not-contact.

**Effort.** ~1.5–2 days (enum + service restructure + config + 3 bypass guards + endpoint/
request extension + superadmin UI option + contact toggle/mutation/query + tests). Smaller if
§5 already landed the three bypass guards.

---

## Suggested build order

1. **Esc-clears-filters** and **settled-guard** — small, frontend-only, ship together.
2. **Overpayment→donation** and **payment-type filter** — both build on the now-stable
   `order_payments` ledger; do them back-to-back while that code is in your head.
3. **Per-action email toggle** — small, but touches the public edit flows; do it on its own.
4. **Contact merge** — largest; do last, leaning on the merge precedent already in the repo.
