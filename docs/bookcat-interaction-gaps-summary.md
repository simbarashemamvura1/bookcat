Screen Inventory v2
1. Additional Attendees have zero self-service access to their own ticket.
Right now an Additional Attendee only ever receives a CC'd QR email — no account, no dashboard, no lookup. If they lose the email, need a refund, or want to check event status, their only path is going back through the Buyer. That's a real interaction model, not a missing screen — worth deciding deliberately: do Additional Attendees stay purely passive recipients forever, or should there be a lightweight way for them to claim/view their own seat (e.g., a "your ticket" link that works without an account)? This affects a meaningful share of actual attendees at any multi-seat event.
2. No notification path to people who already have a ticket when the event status changes after purchase.
Freeze/Sold-Out messaging is deliberately generic for visitors browsing the event page — that's fine. But there's no mention of anything happening for people who already bought a ticket before a freeze, cancellation, or other status change. Right now a ticket-holder finds out an event was frozen/changed only by coincidentally revisiting the event page. This is a real gap in the attendee experience, not a UI detail.
4. Unclear whether a guest's completed order links to the account they create right after.
The post-purchase Club Member signup prompt is there specifically to hook a guest into an account — but nothing confirms whether the order they just placed as a guest actually shows up in their new "Upcoming Tickets" once they sign up. If it doesn't retroactively link (same email), the incentive to sign up right then is much weaker and the first thing a new member sees is an empty dashboard despite having just bought a ticket.

Stitch Prompts
5. Coupon behavior with multiple seats in one order isn't defined.
The checkout prompt has a single coupon field for the whole order, but attendee-details is per-seat. Does the coupon discount apply once to the order total, or per seat/per ticket? This isn't a visual detail — it changes what the buyer sees as their running total and what "coupon-discounted ticket" even means in the Financial Report breakdown (which lists it per-ticket-type, implying per-seat application).
6. Change-request flow ends in silence for the organizer.
The #26a prompt is "text area → Send Request button," full stop. After submitting, there's no confirmation state, no visible "request pending" indicator on the event page. An organizer has no way to know if their request was received, is being looked at, or was ignored — they'd only find out when someone eventually emails back. Worth a status indicator on the event itself once a change request is outstanding.

MVP v1
7. "Transactional email" is the only notification mechanism mentioned anywhere — admins have no proactive alerting.
As written, an admin finds out about a new organizer application, a pending change request, or a refund request only by manually checking each queue screen. At low volume that's fine; the moment there's more than a couple of admins or events, "check five different queues by hand" becomes the actual operational bottleneck — which is a real interaction pattern (or lack of one) for the people running the platform daily, not a cosmetic gap.
