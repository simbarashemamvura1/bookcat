import { ApiError } from '@/lib/api';

/** Plain-language text for any failed API call. */
export function describeError(e: unknown): string {
  if (!(e instanceof ApiError)) {
    return 'We could not reach the server. Check your connection and try again.';
  }
  if (e.status === 429 || e.code === 'too_many_carts') {
    return 'Too many requests. Please wait a moment and try again.';
  }

  switch (e.code) {
    case 'seats_unavailable':
      return 'Someone else just took that seat.';
    case 'seats_not_sellable':
      return 'That seat is no longer available.';
    case 'too_many_seats':
      return 'You can book up to 8 seats at once.';
    case 'cart_locked':
      return 'Your seats are locked while you pay. Go back to change them.';
    case 'event_not_purchasable':
      return 'Ticket sales for this event are paused right now.';
    case 'expired':
      return 'Your hold expired. Please pick your seats again.';
    case 'cart_changed':
    case 'seat_lost':
      return 'Your seats changed. Please review them and try again.';
    case 'payment_unavailable':
      return 'Payments are unavailable right now. Please try again shortly.';
    case 'invalid_seats':
      return 'That seat does not exist.';
    case 'coupon_invalid':
      return 'That code is not valid for this event.';
    case 'coupon_unavailable':
      return 'That code has been used up or has expired.';
    default:
      return 'Something went wrong. Please try again.';
  }
}

/** Turns a checkout error into messages next to the right form fields. Empty if it is not a form problem. */
export function fieldErrors(e: unknown): Record<string, string> {
  const out: Record<string, string> = {};

  if (!(e instanceof ApiError)) {
    return out;
  }

  if (e.code === 'coupon_invalid' || e.code === 'coupon_unavailable') {
    out.coupon = describeError(e);
    return out;
  }

  // Laravel-style validation errors: { "attendees.ORCH-A-1.name": [...], "buyer_email": [...] }
  if (e.code === 'validation_failed' && e.problems && typeof e.problems === 'object' && !Array.isArray(e.problems)) {
    for (const path of Object.keys(e.problems as Record<string, unknown>)) {
      const m = path.match(/^attendees\.(.+)\.(name|email)$/);

      if (m) {
        out[`${m[2]}:${m[1]}`] = m[2] === 'name' ? 'Enter a name for this seat.' : 'Enter a valid email, or leave it blank.';
      } else if (path === 'buyer_email') {
        out.buyer_email = 'Enter a valid email address.';
      } else if (path === 'coupon') {
        out.coupon = 'That code is too long.';
      } else if (path === 'own_seat') {
        out.own_seat = 'Choose which seat is yours.';
      }
    }

    return out;
  }

  // Our own checks: ["buyer_email", "own_seat", "name:ORCH-A-1", "email:ORCH-A-1", "missing:ORCH-A-1"]
  if (e.code === 'invalid_attendees' && Array.isArray(e.problems)) {
    for (const problem of e.problems as string[]) {
      const [kind, key] = problem.split(':');

      if (problem === 'buyer_email') {
        out.buyer_email = 'Enter a valid email address.';
      } else if (problem === 'own_seat') {
        out.own_seat = 'Choose which seat is yours.';
      } else if ((kind === 'name' || kind === 'missing') && key) {
        out[`name:${key}`] = 'Enter a name for this seat.';
      } else if (kind === 'email' && key) {
        out[`email:${key}`] = 'Enter a valid email, or leave it blank.';
      }
    }
  }

  return out;
}