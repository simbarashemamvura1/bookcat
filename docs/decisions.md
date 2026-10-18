# Bookcat — Decision Log

Decisions made during the build that change or extend the specs. Newest first.
When a decision here affects the roles matrix, MVP scope, or screen inventory,
update that doc and link back here.
## 2026-10-15 — Fees are absorbed by the organizer and computed per ticket

- The buyer pays the ticket price only. The Bookcat fee (flat + 2.5%) and the 3%
  processing fee are deducted from the organizer's sales. Matches the Financial
  Report example math and the CAD 500 Minimum Charge being organizer-side.
  Resolves open items round 2, 1.1.
- Fees are computed per ticket on the price actually paid (after coupon), in integer
  cents and basis points, rounded half up. Order totals are always the sum of ticket
  rows, so reports reconcile exactly.
- Each ticket stores its own money snapshot (list, discount, paid, both fees), so
  later fee overrides never rewrite history.
- Comp tickets carry only the flat fee and no processing fee. Fully free events carry
  zero rates, so all fees are 0.
- A paid ticket fully discounted to 0 by a coupon still carries the flat fee, same as a
  comp ticket. Confirm or change.
- The CAD 500 Minimum Charge is applied per event at settlement time (the greater of
  the summed fees and the minimum), not per ticket.

## 2026-10-15 — Coupons apply per ticket

- A flat coupon takes its amount off EACH ticket (never more than the ticket's price);
  a percent coupon is a percentage of each ticket. Resolves round 2, 6.4.
- One-time coupons are forced to a single redemption. Redemptions are claimed with one
  atomic UPDATE so two buyers cannot both use the last one. A failed payment or expired
  hold must release the claim.

## 2026-10-15 — Guest orders link to a Club Member by verified email

- Every order keeps the buyer's email (lowercased). When a member verifies their email,
  their past guest purchase orders with that email are linked to the account. Resolves
  round 2, 6.3.
- Verification is required before linking. Comp orders are never linked.

## 2026-10-15 — Double-booking is blocked in the database, not only in Redis

- A partial unique index allows at most one valid ticket per event seat. Redis holds
  reduce contention; this index is the guarantee. A refunded ticket frees the seat.
- Tickets carry an unguessable token (what the QR encodes). It also makes a no-account
  "your ticket" link possible for Additional Attendees (round 2, 6.1) with no extra schema.

## 2026-10-15 — Event lifecycle is stored as independent gates

- `review_status` (draft | submitted | approved | rejected) is the Approve gate.
- `creation_fee_paid_at` is the fee gate. Fully free events (`is_free`) skip it.
- `activated_at` is the Admin Activate switch. Clearing it later hides the event and
  stops sales without touching the content approval.
- `made_public_at` and `made_bookable_at` are the organizer's two toggles.
- Purchasable = bookable toggle on, `sales_status` open, and every gate above still true.
  The gates are re-checked on every request, never cached on the event.
- `frozen_by_type` (organizer | admin) records who froze the event. Only an admin
  freeze blocks the organizer from reactivating. Also answers the open question about
  showing the organizer who froze it.

## 2026-10-15 — Event fee terms are copied, not referenced

- Each event gets its own concrete copy of the fee schedule at creation. Later changes
  to platform defaults never alter an existing event's deal.
- Fully free events get zero fees across the board.
- Only CAD has a fee schedule so far. USD events cannot be created until the USD
  fee question (open items round 2, 1.2) is decided.

## 2026-10-15 — Custom floor plans: seats are snapshotted when the plan is ready

- A custom plan has no seats until the Bookcat team builds it, so its event's seat
  snapshot (and therefore seat pricing, #28) can only happen after "Mark floor plan
  ready". Library templates snapshot immediately.
- Doc fix needed: screen inventory #41 says Approve happens "before any fee payment".
  #24, #42, the matrix (note 6) and the MVP flow all put the fee at creation, before
  submission. The schema follows the latter.

## 2026-10-15 — Seated-first v1, standing admission left open as an organizer option

- v1 ships seated-only: every ticket is one specific seat.
- Standing / entrance-only tickets are a planned option that the ORGANIZER chooses
  to offer per event. The platform never forces it, and an event with no standing
  areas enabled behaves exactly like a seated-only event.
- Not built yet. Rules to keep the door open when building events and tickets:
  1. Never assume a ticket has a seat. On the tickets table the seat reference must
     be nullable, alongside an admission type (seated | standing).
  2. Standing areas are separate from seats. A floor plan may later define optional
     standing areas (name, default capacity) in their own table; seated floor plans
     are unaffected, so adding it is purely additive.
  3. The organizer opts in per event and sets capacity and price per area.
  4. Inventory for standing areas is counter-based (atomic decrement against
     capacity), not one lock per seat. It uses its own Redis key namespace,
     `event:{event_id}:area:{area_key}`, so it can never collide with seat keys
     (`event:{event_id}:seat:{seat_key}`).
  5. The per-ticket rules generalize: every ticket needs a name, and the QR code
     encodes the ticket, not the seat, so check-in works unchanged.
- Docs to update when built: screen inventory #13 (attendee details per seat becomes
  per ticket), #28 (pricing by section/area), #12 (seat map shows standing zones).

## 2026-10-15 — Event seats are snapshotted per event

- Creating an event copies the floor plan's seats into per-event seat rows
  (`event_seats`). Holds, sales, tickets and organizer blocks all reference those rows.
- Editing a floor plan later can never move or delete a seat that already has sales.

## 2026-10-15 — Venue request review is its own permission

- New toggleable Admin permission: `review_venue_request` (approve or reject a venue
  an organizer requested).
- Separate from `create_venue` (Admin creating a venue directly), following the
  matrix's rule that Approve and Create are separate toggles.
- Docs to update: roles matrix section 0 toggle list and section 3 (Venue & Floor Map).
- Also missing from the matrix section 0 list: `reactivate_event`. Notes item 7
  refers to it, but it was never listed as a toggle.

## 2026-10-15 — Fee overrides live per event

- Negotiated terms (Bookcat fee, processing fee, Minimum Charge, reserve, payout
  timing) are stored per event, not on the organizer record.
- Matches the roles matrix wording and the Admin Financial Report screen (#46b).
## 2026-10-17 — Seat holds are carts, locked per seat in Redis

- Selecting a seat holds it immediately. The hold timer starts at the first seat and is
  NOT reset by adding seats, so nobody can hold seats indefinitely. Window comes from
  config, clamped to the 10-20 minute band (Platform Settings will override it later).
- A multi-seat request is all or nothing, done in one Redis script.
- Locks are taken first, then the database is checked (sold / blocked / unpriced). A sale
  commits to Postgres before its hold is released, so a sold seat can never be re-held.
  The partial unique index on tickets remains the final guarantee if Redis is lost.
- Pressing Pay locks the cart (no seat changes) and guarantees at least 5 minutes from that
  moment, once. Resolves round 2, 4.2. The order's seat list is read from the cart, never
  from the client. If a late payment confirmation finds the seat gone, the order must fail
  and the payment be refunded automatically (to build with checkout).
- A freeze or deactivation blocks new selections and starting payment; a cart already
  paying keeps its seats and may finish.
- Max 8 seats per cart (spec was silent).
- Redis keys use a per-event hash tag, e.g. bookcat:{event12}:seat:ORCH-A-1, and a held
  index (sorted set seat => expiry) so the seat map can show holds and expire them
  client-side without any expiry event.
- Standing areas, if added later, use a counter in the same namespace
  (bookcat:{event12}:area:KEY), as already decided.