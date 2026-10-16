# Bookcat — Decision Log

Decisions made during the build that change or extend the specs. Newest first.
When a decision here affects the roles matrix, MVP scope, or screen inventory,
update that doc and link back here.

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