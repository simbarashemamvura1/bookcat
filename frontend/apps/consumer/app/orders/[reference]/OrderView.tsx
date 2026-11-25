'use client';

import { useEffect, useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { api, ApiError, type Order } from '@/lib/api';
import { formatMoney, prettySeatKey } from '@/lib/format';

type View = 'loading' | 'ready' | 'missing' | 'error';

export default function OrderView({ reference }: { reference: string }) {
  const [order, setOrder] = useState<Order | null>(null);
  const [view, setView] = useState<View>('loading');

  useEffect(() => {
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const load = async () => {
      try {
        const next = await api.order(reference);
        if (cancelled) return;

        setOrder(next);
        setView('ready');

        // Still waiting for the payment to be confirmed: look again shortly.
        if (next.status === 'pending_payment') {
          timer = setTimeout(load, 2000);
        }
      } catch (e) {
        if (cancelled) return;
        setView(e instanceof ApiError && e.status === 404 ? 'missing' : 'error');
      }
    };

    void load();

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [reference]);

  const card = 'rounded-xl border border-neutral-200 bg-white p-6 text-neutral-900 shadow-sm';

  if (view === 'loading') {
    return <p className={card}>Loading your order…</p>;
  }
  if (view === 'missing') {
    return (
      <div className={card}>
        <h1 className="text-xl font-semibold">We can&apos;t show this order here</h1>
        <p className="mt-2 text-sm text-neutral-600">This page only works in the browser that was used to buy the tickets.</p>
      </div>
    );
  }
  if (view === 'error' || !order) {
    return <p className={card}>Something went wrong loading your order. Please refresh the page.</p>;
  }

  return (
    <div className="space-y-4">
      <div className={card}>
        <p className="text-sm text-neutral-500">Order</p>
        <h1 className="text-2xl font-bold">{order.reference}</h1>

        {order.status === 'pending_payment' && <p className="mt-2 text-sm">Waiting for your payment to be confirmed…</p>}
        {order.status === 'cancelled' && <p className="mt-2 text-sm">This order was cancelled and your seats were released.</p>}
        {order.status === 'paid' && (
          <p className="mt-2 text-sm">
            Paid: <strong>{formatMoney(order.totalCents, order.currency)}</strong>
            {order.discountCents > 0 && ` (you saved ${formatMoney(order.discountCents, order.currency)})`}
          </p>
        )}
      </div>

      {order.status === 'paid' && order.tickets && (
        <ul className="space-y-3">
          {order.tickets.map((ticket) => (
            <li key={ticket.token} className={`${card} sm:flex sm:items-center sm:gap-5`}>
              <div className="shrink-0 rounded-lg bg-white p-2">
                <QRCodeSVG value={ticket.token} size={132} level="M" />
              </div>
              <div className="mt-3 sm:mt-0">
                <p className="text-xs uppercase tracking-wide text-neutral-500">{ticket.seat ? prettySeatKey(ticket.seat) : 'Entry'}</p>
                <p className="text-lg font-semibold">{ticket.name}</p>
                {ticket.isYours && <span className="mt-1 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Your seat</span>}
                <p className="mt-2 text-xs text-neutral-500">Show this QR code at the door.</p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}