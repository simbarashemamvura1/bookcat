export type SaleState = 'on_sale' | 'countdown' | 'paused' | 'sold_out';
export type SeatState = 'available' | 'held' | 'mine' | 'unavailable';

export type EventInfo = {
  slug: string;
  title: string;
  description: string | null;
  category: string | null;
  restrictions: string | null;
  refundPolicy: string | null;
  startsAt: string | null;
  endsAt: string | null;
  currency: string;
  isFree: boolean;
  organizer: { name: string };
  venue: {
    name: string;
    addressLine: string;
    city: string;
    region: string;
    country: string;
    timezone: string;
  } | null;
  priceFromCents: number | null;
  priceToCents: number | null;
  saleState: SaleState;
};

export type Seat = {
  key: string;
  section: string;
  row: string | null;
  number: string;
  priceCents: number | null;
  state: SeatState;
  heldUntilMs: number | null;
};

export type SeatsResponse = { serverTimeMs: number; seats: Seat[] };

export type CartState = {
  state: 'none' | 'selecting' | 'paying';
  seats: string[];
  remainingMs: number | null;
  expiresAtMs: number | null;
};

export type OrderTicket = { seat: string | null; name: string; isYours: boolean; token: string };

export type Order = {
  reference: string;
  status: 'pending_payment' | 'paid' | 'cancelled' | 'partially_refunded' | 'refunded';
  currency: string;
  subtotalCents: number;
  discountCents: number;
  totalCents: number;
  expiresAtMs: number | null;
  tickets?: OrderTicket[];
};

export type CheckoutBody = {
  buyer_email: string;
  own_seat: string;
  attendees: Record<string, { name: string; email?: string }>;
  coupon?: string;
};

export type CheckoutResponse = {
  order: Order;
  payment: { provider: string; status: string } | null;
  clientSecret: string | null;
};

export type DevResult = { result: string; orderStatus: string | null };

export class ApiError extends Error {
  status: number;
  code: string;
  problems: unknown;

  constructor(status: number, code: string, problems: unknown) {
    super(code);
    this.status = status;
    this.code = code;
    this.problems = problems;
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase();
  const headers: Record<string, string> = { Accept: 'application/json' };
  let body = init.body;

  // The API only accepts JSON for anything that changes data.
  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    body = body ?? '{}';
  }

  const res = await fetch(`/api/v1${path}`, {
    ...init,
    method,
    headers,
    body,
    credentials: 'same-origin',
    cache: 'no-store',
  });

  let data: unknown = null;
  try {
    data = await res.json();
  } catch {
    // empty or non-JSON body
  }

  if (!res.ok) {
    const d = (data ?? {}) as { error?: string; problems?: unknown };
    throw new ApiError(res.status, d.error ?? 'error', d.problems ?? []);
  }

  return data as T;
}

const enc = encodeURIComponent;
const json = (value: unknown) => JSON.stringify(value);

export const api = {
  event: (slug: string) => request<{ event: EventInfo }>(`/events/${enc(slug)}`).then((r) => r.event),
  seats: (slug: string) => request<SeatsResponse>(`/events/${enc(slug)}/seats`),
  cart: (slug: string) => request<CartState>(`/events/${enc(slug)}/cart`),
  select: (slug: string, seats: string[]) =>
    request<CartState>(`/events/${enc(slug)}/cart/seats`, { method: 'POST', body: json({ seats }) }),
  remove: (slug: string, seats: string[]) =>
    request<CartState>(`/events/${enc(slug)}/cart/seats/remove`, { method: 'POST', body: json({ seats }) }),
  clear: (slug: string) => request<CartState>(`/events/${enc(slug)}/cart`, { method: 'DELETE' }),
  checkout: (slug: string, body: CheckoutBody) =>
    request<CheckoutResponse>(`/events/${enc(slug)}/checkout`, { method: 'POST', body: json(body) }),
  abort: (slug: string) => request<CartState>(`/events/${enc(slug)}/checkout/abort`, { method: 'POST' }),
  order: (reference: string) => request<{ order: Order }>(`/orders/${enc(reference)}`).then((r) => r.order),

  // Local development only: these routes exist only on a local Laravel with the fake payment provider.
  devPay: (reference: string) => request<DevResult>(`/dev/orders/${enc(reference)}/pay`, { method: 'POST' }),
  devFail: (reference: string) => request<DevResult>(`/dev/orders/${enc(reference)}/fail`, { method: 'POST' }),
};