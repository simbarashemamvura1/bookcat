# Bookcat — Open Items, Round 2 (External Review)

**Purpose:** A single consolidated list of gaps, undecided rules, and cross-document inconsistencies found during a pass over `event-booking-platform-product-blueprint-v0_2.md`, `mvp-v1-scope.md`, `bookcat-roles-permissions-matrix.md`, `screen-inventory-v2.md`, `bookcat-stitch-prompts.md`, and `bookcat.drawio`.
**Status:** New — nothing in this file is confirmed yet. Each item should be resolved and then folded into the relevant authoritative doc (roles matrix / MVP scope / screen inventory), the same way earlier open items were.
**How to use this file:** Treat it as a working list, not a fourth spec. Once an item below is decided, move the decision into whichever of the three authoritative docs owns that topic, mark it `[CONFIRMED]` there, and strike it here (or delete the line) so this file doesn't become a fourth place the same fact has to be kept in sync.

---

## 1. Financial Model Ambiguities

### 1.1 Who pays the Bookcat fee — attendee surcharge or organizer deduction?
Not stated directly anywhere. Indirect evidence points both ways:
- The checkout screen (#14) shows "price breakdown including fees" to the buyer — suggesting the buyer sees and pays the fee as a line item on top of ticket price.
- The Financial Report treats "Ticket sales collected" as a starting figure from which Bookcat fees are *subtracted* to reach organizer net revenue — consistent with attendee-pays-fee if "ticket sales collected" is defined as the full amount charged to the buyer (ticket price + fees).
- Refund behavior ("fee is reversed along with the refund") supports attendee-pays, since it implies the buyer had paid the fee and gets it back.

**Needs:** One explicit sentence in MVP §3.2 stating the checkout math: does the buyer pay ticket price + Bookcat fee + processing fee as an itemized total, and does "ticket sales collected" in the Financial Report mean that full amount? This affects checkout UI, the Financial Report definition, and refund amounts.

### 1.2 Multi-currency fees not defined
§3.12 confirms USD and CAD are both required at launch, but every fee in §3 (CAD 100 create-event fee, CAD 1.5 + 2.5% ticketing fee, CAD 500 Minimum Charge) is denominated only in CAD.

**Needs:** Decide whether USD events get a converted-at-transaction-time CAD-equivalent fee, a fixed parallel USD fee schedule, or something else. This is a concrete build requirement, not a later polish item.

### 1.3 Taxes — never addressed since the original blueprint
Blueprint §34 raised "will the platform calculate taxes" and "who issues tax receipts or invoices." Never answered in any of the three current docs, and not listed under "Still Cut From v1" or "Deferred Post-MVP" either — it's simply absent.

**Needs:** At minimum, an explicit line stating tax calculation/receipts are the organizer's responsibility (if that's the intended answer), so it's a decision rather than a silent gap. GST/HST (Canada) and state sales tax (US) have real compliance implications for a live marketplace.

### 1.4 Payout timing is calendar-based, not completion-verified
Payout defaults to 3 business days after the event date — a fixed clock, with no confirmation step that the event actually occurred. Blueprint §34 asked "what evidence confirms that an event occurred?" and it was never answered in the current docs. Payouts are manual, so an admin's judgment is the practical safety net, but there's no *designed* control (e.g., an explicit "confirm event occurred" checkbox before payout eligibility clocks even start, or before the payout screen allows submission).

**Needs:** Decide whether this manual-judgment gap is accepted as-is for v1, or whether a lightweight "mark event completed" admin action should gate payout eligibility.

---

## 2. Missing or Undecided Policy: Refunds & Cancellations

### 2.1 No refund eligibility policy — only refund accounting mechanics
The docs thoroughly specify what happens to the ledger *if* a refund happens (Bookcat fee and processing fee both reversed), but never define:
- Who can request a refund, and under what circumstances (organizer discretion? platform-wide policy? per-event refund window?)
- Whether admin approval is required per request, or whether organizers can self-approve refunds on their own events
- Deadlines (e.g., no refunds within 24 hours of the event)

Screens #20 (attendee refund request) and #45 (admin refund management, platform-wide) exist, but the business rule behind them doesn't.

### 2.2 Individual refund vs. whole-event cancellation are conflated
An attendee requesting a refund on their own ticket (#20) and an organizer cancelling an entire approved event (needing bulk refunds to every ticket holder, per MVP §3.14) are mechanically very different flows, but the current docs only really describe the first. §3.14 already flags event cancellation as open — this item notes that resolving it will also require resolving 2.1, since a cancellation-triggered refund needs the same "who approves, on what terms" logic the individual refund flow is missing.

### 2.3 Ticket transfers — silently dropped
Blueprint §34 asked "are ticket transfers allowed?" Never resurfaced in the MVP doc, roles matrix, or screen inventory — not confirmed in scope, not confirmed cut.

**Needs:** An explicit "out of scope for v1" line (or the opposite), so it doesn't become an assumed feature by omission.

---

## 3. Missing Screens / Flows

### 3.1 Organizer-side override booking has no screen
The roles matrix and MVP doc both define "Book with organizer override (bypasses pricing/seat limits) — own events only" as an Organizer capability (roles matrix §5). The screen inventory only has #52, "Book ticket with admin override," under the Admin/Super Admin section — there's no Organizer-dashboard equivalent screen number. The stitch prompts inherit this gap (only the admin version is prompted for).

**Needs:** A new screen number in section D (Organizer Dashboard) of the screen inventory, e.g. "#37d — Book ticket with organizer override," own events only.

### 3.2 No self-service ticket retrieval for Guest Attendees
Guest Attendees have no account or dashboard by design (per the terminology section) — their only path to their ticket is the QR-code email (#16). If that email is missed, mistyped, or lands in spam, there's no "look up my order" screen; the only recovery path is contacting support manually.

**Needs:** A lightweight "retrieve my ticket" screen (order/confirmation number + email lookup) in section B.

---

## 4. Dropped Requirements (present in the original blueprint, absent from the current confirmed docs)

### 4.1 Mandatory MFA for business accounts
Blueprint §27.4 named mandatory MFA for admins as a baseline security requirement. It does not appear in the MVP feature list, and it is not listed under "Still Cut From v1" either — it appears to have simply been lost when §27 was marked superseded, rather than deliberately deferred. Given Admin/Super Admin accounts can approve organizers, override fees down to arbitrary values, and record manual payouts, this should be explicitly re-confirmed as in- or out-of-scope for v1 rather than left absent.

### 4.2 Seat-hold expiring during in-flight payment
Blueprint §15.3 explicitly named "handle payment completion that occurs near the hold expiration" as a required behavior. None of the three current docs mention it. This is a realistic failure mode (payment succeeds seconds after the hold already released the seat to someone else) and needs an explicit rule — e.g., a short grace window, or a hard "payment must complete before hold expiry or it's rejected and refunded" rule.

### 4.3 The "platform should not casually pool organizer funds" warning was archived along with the section that raised it
Blueprint §20.4: *"The platform should not casually collect and manually hold organizer funds in an ordinary bank account... [it] should use a payment provider and legal structure designed for marketplace or platform payments... This area must be validated before development is finalized."*

The confirmed MVP model does exactly what this warned against: all payments collect into the platform's own account, and payouts are "entirely manual... executed and recorded by admins" (MVP §3.6, §4). That may be a fine, deliberate MVP simplification — but the regulatory concern itself (money-transmitter status, commingled funds) was never re-raised as a resolved-or-accepted risk; it just disappeared when §20 was marked `[SUPERSEDED]`. Recommend explicitly re-confirming this as an accepted MVP-stage risk (with a note to revisit before scaling), rather than leaving it implicitly dropped.

---

## 5. Documentation Hygiene

### 5.1 Same open item independently tracked in 2–3 places
- "Does the organizer's dashboard show who froze their event?" appears in both the roles matrix (note 7) and screen inventory (note 14).
- "Event cancellation flow" is open in the MVP doc (§3.14), the roles matrix (note 11), *and* the screen inventory (note 12).

Currently consistent, but nothing enforces that they stay in sync if one gets resolved and updated. Recommend designating one doc as the source of truth per open item and having the others reference it rather than restate it.

### 5.2 `bookcat.drawio` is stale and unmarked
Screen inventory note 11 already says the lifecycle diagram "is now out of date... needs a redraw," but the drawio file itself carries no superseded banner (unlike the blueprint doc, which is clearly marked `[SUPERSEDED]` at the top). It still shows old terminology (`Member Attendee` instead of `Club Member`, no `Pending Organizer`, no toggle-permission model, no domain split, no Public/Bookable states). Recommend either deleting it or adding the same superseded header treatment used on the blueprint doc.

---

## Summary Table

| # | Item | Type | Where it should ultimately live |
|---|---|---|---|
| 1.1 | Attendee-pays vs. organizer-absorbed fee model | Ambiguity | mvp-v1-scope.md §3.2 |
| 1.2 | Multi-currency fee conversion | Gap | mvp-v1-scope.md §3.12 |
| 1.3 | Tax calculation / receipts | Gap | mvp-v1-scope.md (new §) |
| 1.4 | Payout timing not tied to event-completion evidence | Gap | mvp-v1-scope.md §3.5 |
| 2.1 | Refund eligibility policy | Gap | mvp-v1-scope.md (new §) |
| 2.2 | Individual refund vs. bulk cancellation-refund | Gap | mvp-v1-scope.md §3.14 |
| 2.3 | Ticket transfers in/out of scope | Gap | mvp-v1-scope.md §5 or §6 |
| 3.1 | Organizer override-booking screen missing | Missing screen | screen-inventory-v2.md §D |
| 3.2 | Guest ticket self-retrieval screen missing | Missing screen | screen-inventory-v2.md §B |
| 4.1 | Mandatory MFA for business accounts | Dropped requirement | mvp-v1-scope.md §4 (Platform Operations) |
| 4.2 | Seat-hold-expiry-during-payment race condition | Dropped requirement | mvp-v1-scope.md §3.11 |
| 4.3 | Manual fund-pooling regulatory risk | Dropped risk | mvp-v1-scope.md §3.6 (add risk note) |
| 5.1 | Duplicate open-item tracking | Doc hygiene | N/A — process fix |
| 5.2 | Stale, unmarked drawio diagram | Doc hygiene | bookcat.drawio |
| 6.1 | No self-service ticket access for Additional Attendees | Interaction gap | screen-inventory-v2.md §B |
| 6.2 | No notification to existing ticket-holders on status change | Interaction gap | mvp-v1-scope.md §4 / screen-inventory-v2.md §B |
| 6.3 | Guest-to-member order linkage unconfirmed | Interaction gap | bookcat-roles-permissions-matrix.md / screen-inventory-v2.md §B |
| 6.4 | Coupon scope (per-order vs per-seat) undefined | Interaction gap | mvp-v1-scope.md §3.2 |
| 6.5 | No confirmation/status visibility for change requests | Interaction gap | screen-inventory-v2.md §D |
| 6.6 | No proactive admin alerting, only manual queue-checking | Interaction gap | mvp-v1-scope.md §4 |

---

## 6. Interaction-Model Gaps (Round 3 — screen inventory / stitch prompts pass)

### 6.1 Additional Attendees have no self-service access to their own ticket
An Additional Attendee currently only ever receives a CC'd QR email — no account, no dashboard, no lookup path. If they lose the email, want a refund, or want to check event status, their only route is going back through the Buyer. This is a real interaction model decision, not a missing screen: does an Additional Attendee stay a purely passive recipient forever, or should there be a lightweight way to view/claim their own seat without an account?

**Needs:** Decide whether Additional Attendees get any self-service path at all, and if so, add the corresponding screen to section B of the screen inventory.

### 6.2 No notification path to existing ticket-holders when event status changes after purchase
The Freeze/Sold-Out messaging is deliberately generic for visitors browsing the event page, but nothing addresses people who **already hold a ticket** when a freeze, cancellation, or other status change happens. Right now a ticket-holder only finds out by coincidentally revisiting the event page.

**Needs:** An explicit notification trigger (email at minimum) to existing ticket-holders on freeze/cancel/reschedule, distinct from the generic public-facing messaging.

### 6.3 Guest-to-Club-Member conversion doesn't confirm order linkage
The post-purchase Club Member signup prompt exists specifically to convert a guest into a member, but nothing confirms whether the order the guest just placed retroactively appears in their new "Upcoming Tickets" once they sign up with the same email. If it doesn't link automatically, the first thing a brand-new member sees is an empty dashboard despite having just bought a ticket — undermining the conversion incentive at the exact moment it's offered.

**Needs:** Confirm whether Club Member signup auto-links prior guest orders by matching email, and if so, note this explicitly in the roles matrix / screen inventory.

### 6.4 Coupon application scope is undefined for multi-seat orders
The checkout screen has a single coupon field for the whole order, but attendee details are entered per seat. It's not defined whether a coupon discounts the order total once, or applies per seat/per ticket. This changes what the buyer sees as their running total at checkout and what "coupon-discounted ticket" means in the Financial Report's per-ticket-type breakdown, which implies per-seat application.

**Needs:** Explicit rule in the MVP financial model (§3.2) or ticketing rules (screen inventory) on whether coupons apply per-order or per-seat.

### 6.5 Request Event Change flow has no confirmation or status visibility
The change-request flow (#26a) is currently just a text area and a "Send Request" button. After submitting, there's no confirmation state and no visible "request pending" indicator anywhere on the event page. The organizer has no way to know whether the request was received or is being looked at — they only find out when someone eventually emails back.

**Needs:** A visible "change request pending" status on the event itself once submitted, plus a submission confirmation state for #26a.

### 6.6 Admins have no proactive alerting — only manual queue-checking
"Transactional email" is currently the only notification mechanism mentioned anywhere in the MVP scope, and it's attendee/organizer-facing. Admins find out about a new organizer application, a pending change request, or a refund request only by manually checking each queue screen individually. This is fine at low volume, but becomes the actual operational bottleneck once there's more than a couple of admins or a meaningful number of events.

**Needs:** Decide whether basic admin-facing alerting (even just a badge count per queue, or a daily digest email) is in scope for v1, or explicitly deferred with that tradeoff acknowledged.

---

**End of Round 2 review.**
