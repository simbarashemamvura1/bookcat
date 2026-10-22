'use client';

import { useMemo, useState, type KeyboardEvent } from 'react';
import type { Seat } from '@/lib/api';
import { formatMoney, seatLabel } from '@/lib/format';
import { layoutSeats, SEAT } from '@/lib/layout';

type Props = {
  seats: Seat[];
  currency: string;
  pendingKeys: string[];
  locked: boolean; // true while the buyer is past the picking step
  onToggle: (seat: Seat) => void;
};

// Every state differs by more than colour: dashed outline, a tick, a cross.
const SEAT_CLASS: Record<Seat['state'], string> = {
  available: 'fill-white stroke-neutral-400 hover:fill-emerald-100 hover:stroke-emerald-600',
  mine: 'fill-emerald-600 stroke-emerald-800',
  held: 'fill-amber-100 stroke-amber-500',
  unavailable: 'fill-neutral-300 stroke-neutral-300',
};

const STATE_WORD: Record<Seat['state'], string> = {
  available: 'available',
  mine: 'selected by you',
  held: 'held by another buyer',
  unavailable: 'not available',
};

export default function SeatMap({ seats, currency, pendingKeys, locked, onToggle }: Props) {
  const [zoom, setZoom] = useState(1);
  const layout = useMemo(() => layoutSeats(seats), [seats]);

  const prices = useMemo(() => {
    const bySection = new Map<string, number>();
    for (const seat of seats) {
      if (seat.priceCents !== null && !bySection.has(seat.section)) {
        bySection.set(seat.section, seat.priceCents);
      }
    }
    return bySection;
  }, [seats]);

  const showNumbers = zoom >= 1.2;
  const setClamped = (value: number) => setZoom(Math.min(2.5, Math.max(0.5, Math.round(value * 100) / 100)));

  const onKey = (e: KeyboardEvent<SVGGElement>, seat: Seat) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onToggle(seat);
    }
  };

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-3 text-neutral-900 shadow-sm">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-600">
          <li className="flex items-center gap-1.5"><span className="h-4 w-4 rounded border border-neutral-400 bg-white" />Available</li>
          <li className="flex items-center gap-1.5"><span className="h-4 w-4 rounded border border-emerald-800 bg-emerald-600" />Yours</li>
          <li className="flex items-center gap-1.5"><span className="h-4 w-4 rounded border border-dashed border-amber-500 bg-amber-100" />Held by someone</li>
          <li className="flex items-center gap-1.5"><span className="h-4 w-4 rounded border border-neutral-300 bg-neutral-300" />Unavailable</li>
        </ul>
        <div className="flex items-center gap-1 text-sm">
          <button type="button" aria-label="Zoom out" onClick={() => setClamped(zoom - 0.25)} className="h-8 w-8 rounded-lg border border-neutral-300 hover:bg-neutral-100">−</button>
          <button type="button" aria-label="Reset zoom" onClick={() => setZoom(1)} className="h-8 min-w-14 rounded-lg border border-neutral-300 px-2 text-xs hover:bg-neutral-100">{Math.round(zoom * 100)}%</button>
          <button type="button" aria-label="Zoom in" onClick={() => setClamped(zoom + 0.25)} className="h-8 w-8 rounded-lg border border-neutral-300 hover:bg-neutral-100">+</button>
        </div>
      </div>

      <div className="max-h-[70vh] overflow-auto rounded-lg bg-neutral-50">
        <svg
          width={layout.width * zoom}
          height={layout.height * zoom}
          viewBox={`0 0 ${layout.width} ${layout.height}`}
          role="group"
          aria-label="Seat map"
          className="mx-auto block"
        >
          <rect x={layout.stage.x} y={layout.stage.y} width={layout.stage.width} height={layout.stage.height} rx={6} className="fill-neutral-200" />
          <text x={layout.stage.x + layout.stage.width / 2} y={layout.stage.y + layout.stage.height / 2} textAnchor="middle" dominantBaseline="central" fontSize={11} letterSpacing={3} className="fill-neutral-500" pointerEvents="none">
            STAGE
          </text>

          {layout.sectionLabels.map((label) => {
            const price = prices.get(label.text);
            return (
              <text key={label.text} x={label.x} y={label.y} textAnchor="middle" fontSize={12} fontWeight={600} className="fill-neutral-700">
                {price === undefined ? label.text : `${label.text} · ${formatMoney(price, currency)}`}
              </text>
            );
          })}

          {layout.rowLabels.map((label, i) => (
            <text key={i} x={label.x} y={label.y} textAnchor="middle" dominantBaseline="central" fontSize={11} className="fill-neutral-400" pointerEvents="none">
              {label.text}
            </text>
          ))}

          {layout.placed.map(({ seat, x, y }) => {
            const interactive = !locked && (seat.state === 'available' || seat.state === 'mine');
            const busy = pendingKeys.includes(seat.key);
            const price = seat.priceCents !== null ? formatMoney(seat.priceCents, currency) : 'not for sale';
            const label = `${seatLabel(seat)}, ${price}, ${STATE_WORD[seat.state]}`;
            const glyph = seat.state === 'mine' ? '✓' : seat.state === 'unavailable' ? '×' : showNumbers ? seat.number : '';

            return (
              <g
                key={seat.key}
                transform={`translate(${x} ${y})`}
                role={interactive ? 'button' : 'img'}
                aria-label={label}
                aria-pressed={interactive ? seat.state === 'mine' : undefined}
                tabIndex={interactive ? 0 : -1}
                onClick={interactive && !busy ? () => onToggle(seat) : undefined}
                onKeyDown={interactive && !busy ? (e) => onKey(e, seat) : undefined}
                className={`focus:outline-none [&:focus-visible>rect]:stroke-sky-600 [&:focus-visible>rect]:stroke-[3px] ${busy ? 'opacity-50' : ''}`}
              >
                <title>{label}</title>
                <rect
                  width={SEAT}
                  height={SEAT}
                  rx={6}
                  strokeWidth={1.5}
                  strokeDasharray={seat.state === 'held' ? '3 2' : undefined}
                  className={`${SEAT_CLASS[seat.state]} ${interactive ? 'cursor-pointer' : 'cursor-default'}`}
                />
                <text
                  x={SEAT / 2}
                  y={SEAT / 2}
                  textAnchor="middle"
                  dominantBaseline="central"
                  fontSize={seat.state === 'mine' || seat.state === 'unavailable' ? 13 : 9}
                  pointerEvents="none"
                  className={seat.state === 'mine' ? 'fill-white' : seat.state === 'unavailable' ? 'fill-neutral-500' : 'fill-neutral-700'}
                >
                  {glyph}
                </text>
              </g>
            );
          })}
        </svg>
      </div>

      {locked && <p className="mt-2 text-xs text-neutral-500">Seats are locked while you check out. Go back to change them.</p>}
    </div>
  );
}