import type { EventInfo } from '@/lib/api';

const ORIGIN = process.env.API_ORIGIN ?? 'http://127.0.0.1:8000';

/**
 * Server-side fetch for the event page. Cached for 15 seconds, because this call comes from the
 * Next server: every visitor would otherwise share one IP address under Laravel's rate limit.
 * The client component keeps the sale state fresh by polling.
 */
export async function fetchEvent(slug: string): Promise<EventInfo | null> {
  const res = await fetch(`${ORIGIN}/api/v1/events/${encodeURIComponent(slug)}`, {
    headers: { Accept: 'application/json' },
    next: { revalidate: 15 },
  });

  if (res.status === 404) {
    return null;
  }
  if (!res.ok) {
    throw new Error(`Event request failed (${res.status})`);
  }

  return ((await res.json()) as { event: EventInfo }).event;
}