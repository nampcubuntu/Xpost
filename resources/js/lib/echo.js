import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref } from 'vue';

/**
 * The websocket connection, made once and shared.
 *
 * Built lazily rather than at boot: a signed-out visitor on the marketing page
 * has nothing to subscribe to, and opening a socket to authorise a channel
 * nobody is watching is a connection and an auth request for nothing.
 *
 * Returns null when no key is configured, which is the case on any install
 * that has not set Reverb up.
 */
let echo = null;
let attempted = false;

/**
 * What the socket is currently doing, for pages that no longer poll.
 *
 * One of pusher-js's own states: initialized, connecting, connected,
 * unavailable, failed, disconnected. 'unconfigured' is ours, for an install
 * with no key at all.
 *
 * It exists because dropping the fallback poll makes this the difference
 * between "nothing has happened" and "nothing can reach you". A page that
 * cannot tell those apart shows a frozen counter and calls it calm.
 */
export const socketState = ref('initialized');

/** True once the socket has ever delivered, so a page can trust it. */
export const socketReady = ref(false);

export function useEcho() {
    if (attempted) {
        return echo;
    }

    attempted = true;

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (! key) {
        socketState.value = 'unconfigured';

        return null;
    }

    window.Pusher = Pusher;

    /*
     * The host the page is on, not the one in the build.
     *
     * The app is reached two ways -- straight at 127.0.0.1:8000 and through a
     * Cloudflare tunnel on https -- and a socket dialled at 127.0.0.1 from an
     * https page is mixed content, which the browser blocks silently. So a
     * tunnelled page dials its own origin and the tunnel carries the socket,
     * while a local page dials the configured host.
     */
    const tunnelled = window.location.protocol === 'https:';

    try {
        echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: tunnelled ? window.location.hostname : (import.meta.env.VITE_REVERB_HOST ?? '127.0.0.1'),
            wsPort: tunnelled ? 443 : Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
            wssPort: 443,
            forceTLS: tunnelled,
            enabledTransports: ['ws', 'wss'],
            // Channel authorisation is an ordinary session request, so it goes
            // through the same credentials-and-XSRF setup as every other call.
            authEndpoint: '/broadcasting/auth',
            withCredentials: true,
        });
        /*
         * Watched rather than assumed.
         *
         * Behind a tunnel that only forwards the app's port, the socket path
         * reaches the SPA's catch-all and gets HTML back instead of an upgrade
         * -- measured as a 502 on a real handshake. Nothing throws; the
         * connection simply never becomes 'connected'. So the state is
         * published and the pages that depend on it say so.
         */
        echo.connector.pusher.connection.bind('state_change', ({ current }) => {
            socketState.value = current;
            socketReady.value = socketReady.value || current === 'connected';
        });
    } catch {
        // A broadcaster that will not start is not a reason for the page not
        // to start.
        echo = null;
        socketState.value = 'failed';
    }

    return echo;
}

/**
 * Listen on one private channel, and hand back the way to stop.
 *
 * The unsubscribe is the point: a page that leaves its listener behind keeps
 * patching rows it no longer renders, and the leak is invisible until someone
 * has been in the app for an hour.
 */
export function listen(channel, event, handler) {
    const instance = useEcho();

    if (! instance) {
        return () => {};
    }

    instance.private(channel).listen(event, handler);

    return () => instance.leave(channel);
}
