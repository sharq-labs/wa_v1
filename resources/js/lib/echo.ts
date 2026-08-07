import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

let instance: Echo<'reverb'> | null = null;

export function getEcho(): Echo<'reverb'> {
    if (instance) return instance;

    window.Pusher = Pusher;

    const host = import.meta.env.VITE_REVERB_HOST ?? window.location.hostname;
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);

    instance = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY ?? 'local-reverb-key',
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        withCredentials: true,
    });

    /**
     * Reverb is frequently not running locally. Pusher retries the socket for as
     * long as the tab is open, so without this the only trace is an endless run of
     * anonymous `WebSocket connection failed` lines with nothing naming the cause.
     * Say it once, clearly; re-arm on reconnect so a later outage is still reported.
     */
    let warned = false;
    const warnOnce = (reason: string) => {
        if (warned) return;
        warned = true;
        console.warn(
            `[echo] Real-time updates are offline (${reason}). No Reverb server is reachable at ` +
                `${host}:${port} — start it with \`php artisan reverb:start\`. ` +
                'The app still works; live updates resume automatically once it is back.',
        );
    };

    const { connection } = instance.connector.pusher;
    connection.bind('error', () => warnOnce('connection error'));
    connection.bind('unavailable', () => warnOnce('unavailable'));
    connection.bind('failed', () => warnOnce('failed'));
    connection.bind('connected', () => {
        warned = false;
    });

    window.Echo = instance;
    return instance;
}

export function leaveChannel(name: string): void {
    instance?.leave(name);
}
