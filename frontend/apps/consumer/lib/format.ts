export function formatMoney(cents: number, currency: string): string {
  return new Intl.NumberFormat('en-CA', { style: 'currency', currency }).format(cents / 100);
}

/** 14:32 style countdown. */
export function formatCountdown(ms: number): string {
  const total = Math.max(0, Math.ceil(ms / 1000));
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;

  return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

/** "ORCH-C-12" becomes "ORCH · C · 12". */
export function prettySeatKey(key: string): string {
  return key.split('-').join(' · ');
}

export function seatLabel(seat: { section: string; row: string | null; number: string }): string {
  return seat.row ? `${seat.section}, row ${seat.row}, seat ${seat.number}` : `${seat.section}, seat ${seat.number}`;
}

/** Always shown in the venue's own time zone, so the server and the browser print the same text. */
export function formatEventDate(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', { dateStyle: 'full', timeStyle: 'short', timeZone }).format(new Date(iso));
}