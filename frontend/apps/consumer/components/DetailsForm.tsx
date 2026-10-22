'use client';

import type { FormEvent } from 'react';

export type Details = {
  buyerEmail: string;
  ownSeat: string;
  coupon: string;
  attendees: Record<string, { name: string; email: string }>;
};

type Props = {
  seats: { key: string; label: string }[];
  details: Details;
  errors: Record<string, string>;
  busy: boolean;
  onChange: (next: Details) => void;
  onSubmit: () => void;
  onBack: () => void;
};

const inputClass =
  'mt-1 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/30';
const errorClass = 'mt-1 text-xs text-red-600';

export default function DetailsForm({ seats, details, errors, busy, onChange, onSubmit, onBack }: Props) {
  const own = seats.some((s) => s.key === details.ownSeat) ? details.ownSeat : (seats[0]?.key ?? '');

  const setAttendee = (key: string, patch: Partial<{ name: string; email: string }>) =>
    onChange({
      ...details,
      attendees: {
        ...details.attendees,
        [key]: { ...(details.attendees[key] ?? { name: '', email: '' }), ...patch },
      },
    });

  return (
    <form
      className="space-y-4"
      onSubmit={(e: FormEvent) => {
        e.preventDefault();
        onSubmit();
      }}
    >
      <h2 className="text-base font-semibold">Who is coming?</h2>

      <label className="block text-sm font-medium">
        Your email
        <input
          type="email"
          required
          autoComplete="email"
          value={details.buyerEmail}
          onChange={(e) => onChange({ ...details, buyerEmail: e.target.value })}
          className={inputClass}
          placeholder="you@example.com"
        />
        {errors.buyer_email && <span className={errorClass}>{errors.buyer_email}</span>}
      </label>

      {errors.own_seat && <p className={errorClass}>{errors.own_seat}</p>}

      {seats.map((seat) => {
        const a = details.attendees[seat.key] ?? { name: '', email: '' };
        const isOwn = own === seat.key;

        return (
          <fieldset key={seat.key} className="rounded-lg border border-neutral-200 p-3">
            <legend className="px-1 text-sm font-semibold">{seat.label}</legend>

            <label className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                name="own-seat"
                checked={isOwn}
                onChange={() => onChange({ ...details, ownSeat: seat.key })}
              />
              This is my own seat
            </label>

            <label className="mt-2 block text-sm font-medium">
              Name
              <input
                type="text"
                required
                maxLength={120}
                value={a.name}
                onChange={(e) => setAttendee(seat.key, { name: e.target.value })}
                className={inputClass}
              />
              {errors[`name:${seat.key}`] && <span className={errorClass}>{errors[`name:${seat.key}`]}</span>}
            </label>

            {!isOwn && (
              <label className="mt-2 block text-sm font-medium">
                Email (optional)
                <input
                  type="email"
                  value={a.email}
                  onChange={(e) => setAttendee(seat.key, { email: e.target.value })}
                  className={inputClass}
                />
                {errors[`email:${seat.key}`] && <span className={errorClass}>{errors[`email:${seat.key}`]}</span>}
              </label>
            )}
          </fieldset>
        );
      })}

      <label className="block text-sm font-medium">
        Discount code (optional)
        <input
          type="text"
          maxLength={32}
          value={details.coupon}
          onChange={(e) => onChange({ ...details, coupon: e.target.value })}
          className={inputClass}
        />
        {errors.coupon && <span className={errorClass}>{errors.coupon}</span>}
      </label>

      <div className="flex gap-2">
        <button
          type="button"
          onClick={onBack}
          disabled={busy}
          className="rounded-lg border border-neutral-300 px-4 py-2 text-sm hover:bg-neutral-100 disabled:opacity-50"
        >
          Back to seats
        </button>
        <button
          type="submit"
          disabled={busy}
          className="flex-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
        >
          {busy ? 'Please wait…' : 'Continue to payment'}
        </button>
      </div>
    </form>
  );
}