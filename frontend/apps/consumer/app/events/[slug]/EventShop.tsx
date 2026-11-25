'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api, ApiError, type CartState, type CheckoutResponse, type EventInfo, type Seat } from '@/lib/api';
import { describeError, fieldErrors } from '@/lib/messages';
import { formatCountdown, formatMoney, prettySeatKey } from '@/lib/format';
import { connectLive, type SeatChange, type SeatMessage } from '@/lib/live';
import SeatMap from '@/components/SeatMap';
import DetailsForm, { type Details } from '@/components/DetailsForm';

const EMPTY_CART: CartState = { state: 'none', seats: [], remainingMs: null, expiresAtMs: null };
const POLL_FALLBACK_MS = 4000; // while the live connection is down
const POLL_LIVE_MS = 20000; // a safety net while it is up
const MAX_SEATS = 8;
const IS_DEV = process.env.NODE_ENV !== 'production';

type Step = 'pick' | 'details' | 'pay';

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitForPaid(reference: string): Promise<boolean> {
  for (let i = 0; i < 20; i++) {
    const order = await api.order(reference);

    if (order.status === 'paid') return true;
    if (order.status === 'cancelled') return false;

    await sleep(1500);
  }

  return false;
}

export default function EventShop({ initialEvent }: { initialEvent: EventInfo }) {
  const slug = initialEvent.slug;
  const router = useRouter();

  const [event, setEvent] = useState(initialEvent);
  const [seats, setSeats] = useState<Seat[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [live, setLive] = useState(false);
  const [cart, setCart] = useState<CartState>(EMPTY_CART);
  const [offset, setOffset] = useState(0); // server clock minus this browser's clock
  const [now, setNow] = useState(0);
  const [pending, setPending] = useState<string[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [step, setStep] = useState<Step>('pick');
  const [details, setDetails] = useState<Details>({ buyerEmail: '', ownSeat: '', coupon: '', attendees: {} });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [leaving, setLeaving] = useState(false);
  const [checkout, setCheckout] = useState<CheckoutResponse | null>(null);

  const cartRef = useRef(cart);
  const pendingRef = useRef<string[]>([]); // seats this browser is changing right now
  const lastAt = useRef(new Map<string, number>()); // newest live message applied, per seat
  const gen = useRef(0); // bumped by every change, so a slow poll can never overwrite a newer answer
  const lastExpiryCheck = useRef(0);

  const applyCart = useCallback((next: CartState) => {
    cartRef.current = next;
    setCart(next);

    if (next.state === 'none') {
      setStep('pick');
      setCheckout(null);
    }
  }, []);

  const fail = useCallback(
    (e: unknown) => {
      setNotice(describeError(e));

      if (e instanceof ApiError && e.code === 'event_not_purchasable') {
        api.event(slug).then(setEvent).catch(() => {});
      }
    },
    [slug],
  );

  const loadSeats = useCallback(async () => {
    const mine = gen.current;
    const res = await api.seats(slug);

    if (gen.current !== mine) return;

    setSeats(res.seats);
    setOffset(res.serverTimeMs - Date.now());
    setNow(Date.now());
    setLoaded(true);

    // The seat list says which seats are ours. If that disagrees with our cart (it expired, or this
    // is a fresh page load), ask for the cart itself. This keeps the cart endpoint's rate limit for clicks.
    const ours = res.seats.filter((s) => s.state === 'mine').map((s) => s.key).sort().join();
    const have = [...cartRef.current.seats].sort().join();

    if (ours !== have) {
      const c = await api.cart(slug);
      if (gen.current === mine) applyCart(c);
    }
  }, [slug, applyCart]);

  // A live message from the server: another buyer took, released or bought some seats.
  const applyMessage = useCallback((message: SeatMessage) => {
    // Our own seats and any seat we are changing right now are already handled by our own requests.
    const skip = new Set([...cartRef.current.seats, ...pendingRef.current]);
    const fresh = new Map<string, SeatChange>();

    for (const change of message.changes) {
      if (skip.has(change.key)) continue;
      if ((lastAt.current.get(change.key) ?? 0) > message.at) continue; // an older message arriving late

      lastAt.current.set(change.key, message.at);
      fresh.set(change.key, change);
    }

    if (fresh.size === 0) return;

    setSeats((prev) =>
      prev.map((seat) => {
        const change = fresh.get(seat.key);

        return change ? { ...seat, state: change.state, heldUntilMs: change.heldUntilMs } : seat;
      }),
    );
  }, []);

  // The live connection. If it is not set up or drops, polling takes over.
  useEffect(() => connectLive({ slug, onMessage: applyMessage, onConnection: setLive }), [slug, applyMessage]);

  // Poll the seat map while the tab is visible. Live updates make this a safety net, so it slows down
  // while the live connection is up. Reconnecting restarts it, which also catches anything missed.
  useEffect(() => {
    const tick = async () => {
      if (document.visibilityState !== 'visible') return;

      try {
        await loadSeats();
      } catch {
        // a missed poll is fine, the next one retries
      }
    };

    void tick();
    const id = setInterval(tick, live ? POLL_LIVE_MS : POLL_FALLBACK_MS);
    const onVisible = () => {
      if (document.visibilityState === 'visible') void tick();
    };

    document.addEventListener('visibilitychange', onVisible);

    return () => {
      clearInterval(id);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [loadSeats, live]);

  // Keep the sale state fresh (paused, sold out) without reloading the page.
  useEffect(() => {
    const id = setInterval(async () => {
      if (document.visibilityState !== 'visible') return;

      try {
        setEvent(await api.event(slug));
      } catch (e) {
        if (e instanceof ApiError && e.status === 404) {
          setEvent((prev) => ({ ...prev, saleState: 'paused' }));
        }
      }
    }, 20000);

    return () => clearInterval(id);
  }, [slug]);

  // One-second clock for the countdowns.
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, []);

  const serverNow = now + offset;
  const remainingMs = cart.expiresAtMs ? cart.expiresAtMs - serverNow : null;

  // The hold ran out: ask the server what is left, and tell the buyer.
  useEffect(() => {
    if (cart.state === 'none' || remainingMs === null || remainingMs > 0) return;
    if (Date.now() - lastExpiryCheck.current < 2000) return;

    lastExpiryCheck.current = Date.now();

    void (async () => {
      try {
        gen.current++;
        const c = await api.cart(slug);
        applyCart(c);

        if (c.state === 'none') {
          setNotice('Your hold expired. Pick your seats again.');
        }

        await loadSeats();
      } catch {
        // the next second tries again
      }
    })();
  }, [remainingMs, cart.state, slug, applyCart, loadSeats]);

  // Other buyers' holds expire on their own clock, so a hold that has run out shows as available.
  const displaySeats = useMemo(
    () => seats.map((s) => (s.state === 'held' && s.heldUntilMs !== null && s.heldUntilMs <= serverNow ? { ...s, state: 'available' as const } : s)),
    [seats, serverNow],
  );

  const byKey = useMemo(() => new Map(seats.map((s) => [s.key, s])), [seats]);
  const subtotal = cart.seats.reduce((sum, key) => sum + (byKey.get(key)?.priceCents ?? 0), 0);

  const toggleSeat = async (seat: Seat) => {
    if (pendingRef.current.includes(seat.key)) return;

    if (cart.state === 'paying') {
      setNotice('Your seats are locked while you pay. Go back to change them.');
      return;
    }

    // Updated straight away (not after the next render), so a live message for this seat that
    // arrives before our own answer does is ignored.
    pendingRef.current = [...pendingRef.current, seat.key];
    setPending((p) => [...p, seat.key]);
    setNotice(null);
    gen.current++;

    try {
      const next = seat.state === 'mine' ? await api.remove(slug, [seat.key]) : await api.select(slug, [seat.key]);
      applyCart(next);
    } catch (e) {
      fail(e);
    } finally {
      pendingRef.current = pendingRef.current.filter((k) => k !== seat.key);
      setPending((p) => p.filter((k) => k !== seat.key));
      void loadSeats().catch(() => {});
    }
  };

  const goDetails = () => {
    setNotice(null);
    setErrors({});
    setDetails((d) => ({ ...d, ownSeat: cart.seats.includes(d.ownSeat) ? d.ownSeat : (cart.seats[0] ?? '') }));
    setStep('details');
  };

  const submit = async () => {
    if (busy) return;

    setBusy(true);
    setErrors({});
    setNotice(null);
    gen.current++;

    try {
      const attendees: Record<string, { name: string; email?: string }> = {};

      for (const key of cart.seats) {
        const a = details.attendees[key];
        const email = a?.email.trim();

        attendees[key] = { name: (a?.name ?? '').trim(), ...(email ? { email } : {}) };
      }

      const own = cart.seats.includes(details.ownSeat) ? details.ownSeat : cart.seats[0];
      const coupon = details.coupon.trim();

      const res = await api.checkout(slug, {
        buyer_email: details.buyerEmail.trim(),
        own_seat: own,
        attendees,
        ...(coupon ? { coupon } : {}),
      });

      // A fully discounted order needs no payment and is already done.
      if (res.order.status === 'paid') {
        setLeaving(true);
        router.push(`/orders/${res.order.reference}`);
        return;
      }

      setCheckout(res);
      applyCart(await api.cart(slug));
      setStep('pay');
    } catch (e) {
      const fields = fieldErrors(e);

      if (Object.keys(fields).length > 0) {
        setErrors(fields);
        setNotice('Please check the highlighted details.');
      } else {
        fail(e);
      }

      try {
        applyCart(await api.cart(slug)); // the cart may have changed under us
      } catch {
        // ignore
      }
    } finally {
      setBusy(false);
    }
  };

  const backToDetails = async () => {
    setBusy(true);
    gen.current++;

    try {
      applyCart(await api.abort(slug)); // cancels the pending order and unlocks the cart
      setCheckout(null);
      setStep('details');
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  };

  const clearAll = async () => {
    setBusy(true);
    setNotice(null);
    gen.current++;

    try {
      applyCart(await api.clear(slug));
      await loadSeats();
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  };

  // Development only: stand-ins for the payment provider's webhook.
  const simulate = async (outcome: 'pay' | 'fail') => {
    if (!checkout || busy) return;

    const reference = checkout.order.reference;

    setBusy(true);
    setNotice(null);

    try {
      if (outcome === 'fail') {
        await api.devFail(reference);
        applyCart(await api.abort(slug));
        setCheckout(null);
        setStep('details');
        setNotice('Your payment was declined. You can try again.');
        return;
      }

      const result = await api.devPay(reference);

      if (result.result === 'ok') {
        if (await waitForPaid(reference)) {
          setLeaving(true);
          router.push(`/orders/${reference}`);
          return;
        }

        setNotice('Payment is taking longer than expected. Check your order page in a moment.');
        return;
      }

      // The money arrived but a seat was taken in the meantime, so the payment was returned.
      setNotice(
        result.result === 'refunded'
          ? 'A seat was taken before your payment finished, so your payment was refunded.'
          : 'A seat was taken before your payment finished. Your refund is being processed.',
      );
      applyCart(await api.cart(slug));
      setStep('pick');
      await loadSeats();
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  };

  const onSale = event.saleState === 'on_sale';
  const hasCart = cart.state !== 'none';

  if (!onSale && !hasCart) {
    const text =
      event.saleState === 'countdown'
        ? 'Counting down to start booking, be ready'
        : event.saleState === 'sold_out'
          ? 'This event is sold out'
          : 'Booking temporarily paused';

    return (
      <div className="mt-8 rounded-xl border border-neutral-200 bg-white p-8 text-center text-neutral-900 shadow-sm">
        <p className="text-lg font-semibold">{text}</p>
      </div>
    );
  }

  const locked = step !== 'pick' || cart.state === 'paying';
  const order = checkout?.order;
  const low = remainingMs !== null && remainingMs < 120000;

  return (
    <div className="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
      <section aria-label="Choose your seats">
        {loaded ? (
          <SeatMap seats={displaySeats} currency={event.currency} pendingKeys={pending} locked={locked} onToggle={toggleSeat} />
        ) : (
          <div className="rounded-xl border border-neutral-200 bg-white p-8 text-center text-sm text-neutral-500">Loading seats…</div>
        )}

        {loaded && (
          <p className="mt-2 text-xs text-neutral-500">
            {live ? 'Seat availability is updating live.' : 'Seat availability refreshes every few seconds.'}
          </p>
        )}
      </section>

      <aside className="h-fit rounded-xl border border-neutral-200 bg-white p-4 text-neutral-900 shadow-sm lg:sticky lg:top-4">
        {notice && (
          <p role="alert" className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
            {notice}
          </p>
        )}

        {leaving && <p className="text-sm">Taking you to your tickets…</p>}

        {!leaving && step === 'pick' && cart.state === 'paying' && (
          <div className="space-y-3 text-sm">
            <p>Your seats are locked for a payment in progress.</p>
            <button type="button" onClick={clearAll} disabled={busy} className="w-full rounded-lg border border-neutral-300 px-4 py-2 hover:bg-neutral-100 disabled:opacity-50">
              Cancel payment and start over
            </button>
          </div>
        )}

        {!leaving && step === 'pick' && cart.state !== 'paying' && (
          <div>
            <h2 className="text-base font-semibold">Your seats</h2>

            {cart.seats.length === 0 ? (
              <p className="mt-2 text-sm text-neutral-600">Select seats on the map. You can book up to {MAX_SEATS} at once.</p>
            ) : (
              <>
                <ul className="mt-3 divide-y divide-neutral-100 text-sm">
                  {cart.seats.map((key) => {
                    const seat = byKey.get(key);

                    return (
                      <li key={key} className="flex justify-between py-1.5">
                        <span>{prettySeatKey(key)}</span>
                        <span>{seat && seat.priceCents !== null ? formatMoney(seat.priceCents, event.currency) : ''}</span>
                      </li>
                    );
                  })}
                </ul>

                <p className="mt-3 flex justify-between text-sm font-medium">
                  <span>Subtotal</span>
                  <span>{formatMoney(subtotal, event.currency)}</span>
                </p>
                <p className="mt-1 text-xs text-neutral-500">Discount codes are applied at the next step.</p>

                {remainingMs !== null && loaded && (
                  <p className={`mt-3 text-sm ${low ? 'font-semibold text-red-600' : 'text-neutral-600'}`}>
                    Seats held for <strong>{formatCountdown(remainingMs)}</strong>
                  </p>
                )}

                <div className="mt-4 flex gap-2">
                  <button type="button" onClick={clearAll} disabled={busy} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm hover:bg-neutral-100 disabled:opacity-50">
                    Clear
                  </button>
                  <button type="button" onClick={goDetails} disabled={busy} className="flex-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                    Continue
                  </button>
                </div>
              </>
            )}
          </div>
        )}

        {!leaving && step === 'details' && (
          <DetailsForm
            seats={cart.seats.map((key) => ({ key, label: prettySeatKey(key) }))}
            details={details}
            errors={errors}
            busy={busy}
            onChange={setDetails}
            onSubmit={submit}
            onBack={() => setStep('pick')}
          />
        )}

        {!leaving && step === 'pay' && order && (
          <div>
            <h2 className="text-base font-semibold">Review and pay</h2>

            <dl className="mt-3 space-y-1 text-sm">
              <div className="flex justify-between">
                <dt>Subtotal</dt>
                <dd>{formatMoney(order.subtotalCents, order.currency)}</dd>
              </div>
              {order.discountCents > 0 && (
                <div className="flex justify-between text-emerald-700">
                  <dt>Discount</dt>
                  <dd>−{formatMoney(order.discountCents, order.currency)}</dd>
                </div>
              )}
              <div className="flex justify-between border-t border-neutral-200 pt-2 text-base font-semibold">
                <dt>Total</dt>
                <dd>{formatMoney(order.totalCents, order.currency)}</dd>
              </div>
            </dl>

            {remainingMs !== null && loaded && (
              <p className={`mt-3 text-sm ${low ? 'font-semibold text-red-600' : 'text-neutral-600'}`}>
                Complete payment within <strong>{formatCountdown(remainingMs)}</strong>
              </p>
            )}

            {IS_DEV ? (
              <div className="mt-4 space-y-2 rounded-lg border border-dashed border-neutral-300 p-3">
                <p className="text-xs text-neutral-500">Development only: no real payment is taken.</p>
                <button type="button" onClick={() => simulate('pay')} disabled={busy} className="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                  {busy ? 'Please wait…' : 'Simulate successful payment'}
                </button>
                <button type="button" onClick={() => simulate('fail')} disabled={busy} className="w-full rounded-lg border border-neutral-300 px-4 py-2 text-sm hover:bg-neutral-100 disabled:opacity-50">
                  Simulate declined payment
                </button>
              </div>
            ) : (
              <p className="mt-4 rounded-lg bg-neutral-100 p-3 text-sm text-neutral-600">Card payment is not connected yet.</p>
            )}

            <button type="button" onClick={backToDetails} disabled={busy} className="mt-3 w-full rounded-lg border border-neutral-300 px-4 py-2 text-sm hover:bg-neutral-100 disabled:opacity-50">
              Back
            </button>
          </div>
        )}
      </aside>
    </div>
  );
}