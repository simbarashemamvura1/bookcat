import { notFound } from 'next/navigation';
import { fetchEvent } from '@/lib/server-api';
import { formatEventDate, formatMoney } from '@/lib/format';
import EventShop from './EventShop';

export default async function EventPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const event = await fetchEvent(slug);

  if (!event) {
    notFound();
  }

  const venue = event.venue;
  const from = event.priceFromCents;
  const to = event.priceToCents;

  return (
    <main className="mx-auto max-w-6xl px-4 py-8">
      <header>
        <p className="text-sm opacity-70">{event.organizer.name}</p>
        <h1 className="mt-1 text-3xl font-bold">{event.title}</h1>

        <p className="mt-2 text-sm opacity-80">
          {event.startsAt && venue ? formatEventDate(event.startsAt, venue.timezone) : null}
          {venue ? ` · ${venue.name}, ${venue.city}` : null}
        </p>

        {from !== null && to !== null && (
          <p className="mt-1 text-sm opacity-80">
            {event.isFree ? 'Free' : from === to ? formatMoney(from, event.currency) : `${formatMoney(from, event.currency)} to ${formatMoney(to, event.currency)}`}
          </p>
        )}

        {event.description && <p className="mt-4 max-w-3xl opacity-90">{event.description}</p>}

        {(event.restrictions || event.refundPolicy) && (
          <details className="mt-3 max-w-3xl text-sm opacity-80">
            <summary className="cursor-pointer">Restrictions and refund policy</summary>
            {event.restrictions && <p className="mt-2">{event.restrictions}</p>}
            {event.refundPolicy && <p className="mt-2">{event.refundPolicy}</p>}
          </details>
        )}
      </header>

      <EventShop initialEvent={event} />
    </main>
  );
}