export type SeatChange = {
  key: string;
  state: 'held' | 'available' | 'unavailable';
  heldUntilMs: number | null;
};

export type SeatMessage = { at: number; changes: SeatChange[] };

type Options = {
  slug: string;
  onMessage: (message: SeatMessage) => void;
  onConnection: (connected: boolean) => void;
};

/**
 * Opens the live seat channel for one event. Returns a function that closes it.
 * If live updates are not configured it quietly does nothing, and the page keeps polling.
 */
export function connectLive({ slug, onMessage, onConnection }: Options): () => void {
  const key = process.env.NEXT_PUBLIC_REVERB_APP_KEY;

  if (!key) {
    onConnection(false);
    return () => {};
  }

  let closed = false;
  let cleanup = () => {};

  // Loaded only in the browser, when needed.
  void import('pusher-js')
    .then(({ default: Pusher }) => {
      if (closed) return;

      const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? 'http';
      const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? (scheme === 'https' ? 443 : 8080));

      const pusher = new Pusher(key, {
        cluster: 'mt1', // required by the library's types, ignored because we set our own host
        wsHost: process.env.NEXT_PUBLIC_REVERB_HOST ?? 'localhost',
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
      });

      pusher.connection.bind('state_change', (states: { current: string }) => {
        onConnection(states.current === 'connected');
      });

      const name = `event.${slug}`;
      const channel = pusher.subscribe(name);
      channel.bind('seats.changed', (data: SeatMessage) => onMessage(data));

      cleanup = () => {
        channel.unbind_all();
        pusher.unsubscribe(name);
        pusher.disconnect();
      };
    })
    .catch(() => onConnection(false));

  return () => {
    closed = true;
    cleanup();
  };
}