# Bookcat

Bookcat is a two-sided event ticketing marketplace. Organizers list and sell tickets to events; attendees browse, book seats, and check in with a QR ticket. Bookcat sits in the middle as the platform — verifying organizers, approving events, collecting configurable fees, and handling payouts.

This README covers what Bookcat does, how the system is structured, and how it's being built.

---

## What Bookcat does

**For attendees**
- Browse and search events by category, location, and date — no account required
- Select seats on an interactive floor plan
- Check out as a guest or as a registered **Club Member** (member discounts, ticket history)
- Receive a QR code ticket by email; QR display alone is enough for door check-in — no scanner hardware needed at launch
- Book more than one seat in an order — every seat beyond the buyer's own is for an **Additional Attendee**

**For organizers**
- Apply either through a sales-assisted path or self-service registration with document upload; both require admin verification before the organizer can operate
- Create events: pick a venue from a ready-made floor plan library, or request a custom floor plan built from an uploaded blueprint
- Set ticket pricing per section/seat, open or pause sales, issue coupons and comp/free tickets
- Control event visibility independently of ticket sales via two separate toggles — **Make Public** (visible, not yet purchasable) and **Make Bookable** (sales enabled)
- View a financial report per event: sales, refunds, fees, net revenue, and payout status

**For Bookcat (Admin / Super Admin)**
- Review and approve organizer applications and event submissions
- Two separate approval gates per event: **Approve** (content/compliance) and **Activate** (financial/master switch)
- Configure platform-wide fee defaults, or override fee terms per organizer/event for negotiated deals
- Record manual payouts, manage refunds, and view a platform-wide financial report
- Manage admin accounts through a permission-toggle model — every admin capability is granted individually by Super Admin; nothing is bundled into a fixed "role"

---

## Domain & account model

Bookcat runs on two separate domains with two unrelated account pools:

- **`bookcat.com`** — consumer-facing. Visitor, Guest Attendee, and Club Member accounts live here.
- **`business.bookcat.com`** — B2B gateway. A single login routes Organizer, Admin, and Super Admin to their respective dashboards based on role.

There's no identity link between the two pools by design — an organizer who wants to attend an event as a member simply signs up on `bookcat.com` like anyone else.

---

## Roles

| Role | Where | Scope |
|---|---|---|
| Visitor | bookcat.com | Browse only |
| Guest Attendee | bookcat.com | Books without an account |
| Club Member | bookcat.com | Registered attendee, discounts, ticket history |
| Pending Organizer | business.bookcat.com | Awaiting approval |
| Organizer | business.bookcat.com | Manages their own events, venues, and coupons only |
| Admin | business.bookcat.com | Platform-wide actions, entirely permission-toggle based |
| Super Admin | business.bookcat.com | Everything Admin has automatically, plus exclusive control over creating/removing admins and granting their permissions |

Every Organizer-scoped action (freeze booking, override booking, coupons, etc.) is checked against **both** a permission and event ownership — an organizer can never act on another organizer's event.

---

## Tech stack

**Frontend — Next.js (React)**
- SSR for the public consumer site (SEO matters for event discovery)
- Separate Next.js app (or clearly separated route groups) for the business dashboard, since it's a logged-in tool and doesn't need SSR/SEO
- `laravel-echo` + `pusher-js` protocol client for real-time seat map updates

**Backend — Laravel**
- REST API serving both frontend apps
- **Sanctum** for auth, with separate session scope per domain to match the two-account-pool model
- **Reverb** for self-hosted WebSocket broadcasting (seat holds/releases, freeze/sold-out state), scalable across instances via Redis
- **Cashier** wrapping **Stripe Connect** for the marketplace payment split — buyer pays, Bookcat's fee is taken, remainder routes to the organizer's connected account
- **Queues + Horizon** for transactional email (QR tickets, refund confirmations) and admin alerting

**Data layer**
- **PostgreSQL** — system of record: organizers, events, seats, orders, tickets, payouts, permissions
- **Redis** — seat-hold locks (atomic `SET ... EX ... NX`, giving each hold a 10–20 minute TTL with no cleanup job needed) and the pub/sub backbone for real-time broadcasting

**Real-time seat map**
- Rendered as SVG (not Canvas) — each seat is an individually stateful, clickable element (available / held / sold / selected)
- Seat state changes broadcast over Reverb so every buyer viewing the same event sees holds/releases within seconds
- Confirmed holds and sales are the durable truth in Postgres; Redis only ever holds the ephemeral hold window

**Deployment**
- Frontend and backend containerized and deployed as separate services, each horizontally scalable
- Sticky sessions at the load balancer for WebSocket connections
- Managed Postgres + Redis rather than self-hosted alongside the app

---

## Project structure (planned)

```
bookcat/
├── frontend/            # Next.js app(s)
│   ├── apps/
│   │   ├── consumer/    # bookcat.com
│   │   └── business/    # business.bookcat.com
├── backend/             # Laravel app
│   ├── app/
│   ├── routes/
│   ├── database/
│   └── ...
└── docs/                # architecture notes, MVP scope, roles matrix, screen inventory
```

---

## Status

Currently in the specification phase — the MVP scope, roles/permissions matrix, and screen inventory are defined and being reviewed for gaps. Build hasn't started yet.

**In scope for v1:** organizer onboarding + approval, event creation/approval/activation, interactive seat selection with real-time holds, guest and member checkout, QR ticket delivery, coupons, comp tickets, organizer/admin override booking, financial reporting, manual payouts, toggle-based admin permissions.

**Explicitly deferred post-v1:** automated split payments beyond the basic Connect flow, trust badges/risk scoring, automated fraud detection, ticket transfers, AI recommendations, loyalty/reviews, subscriptions, dynamic pricing, resale, native mobile apps.

---

## Branching

- `main` — stable
- `development` — active work happens here
