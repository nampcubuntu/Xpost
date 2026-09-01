import axios from 'axios';
import { forgetAll } from './cache';

/**
 * Sanctum cookie auth: the SPA and the API share a session, so every request
 * carries credentials and the XSRF cookie. `withXSRFToken` is what makes axios
 * echo the cookie back as a header -- without it every POST is a 419.
 */
const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/** Pages that must render for signed-out visitors -- never bounce away from these. */
const PUBLIC_PATHS = new Set(['/', '/brand', '/login']);

let csrfReady = null;

/** Laravel issues the XSRF cookie from this endpoint; fetch it once per session. */
export function ensureCsrf() {
    csrfReady ??= axios.get('/sanctum/csrf-cookie', { withCredentials: true });

    return csrfReady;
}

api.interceptors.request.use(async (config) => {
    if (['post', 'put', 'patch', 'delete'].includes(config.method)) {
        await ensureCsrf();
    }

    return config;
});

api.interceptors.response.use(
    (response) => {
        /*
         * A write invalidates every cached list.
         *
         * Done here rather than at each call site, because the call sites are
         * the thing that gets forgotten: a page grows a new action, nobody adds
         * the matching forget(), and the symptom is a deleted row reappearing
         * for a second on the next visit. There is no version of that bug worth
         * the cache hits it would save.
         *
         * Everything, not just the caller's own key: a proxy assignment changes
         * the accounts page, deleting an app changes the accounts on it, and
         * guessing which lists a write touches is the same mistake one level up.
         */
        if (! ['get', 'head', 'options'].includes(response.config?.method ?? 'get')) {
            forgetAll();
        }

        return response;
    },
    (error) => {
        const status = error.response?.status;

        // A 419 means the session expired mid-session; retry once with a fresh
        // token rather than dumping the user back at the login screen.
        if (status === 419) {
            csrfReady = null;
        }

        // A 401 is only a reason to bounce to the login screen when the caller
        // actually needed to be signed in.
        //
        // The identity probe (GET /me) 401s by design for signed-out visitors,
        // and the marketing pages run it on every load. Redirecting on that
        // response made the homepage unreachable once you logged out.
        const isProbe = error.config?.skipAuthRedirect === true;
        const onPublicPage = PUBLIC_PATHS.has(window.location.pathname);

        if (status === 401 && !isProbe && !onPublicPage) {
            window.location.href = '/login';
        }

        return Promise.reject(error);
    },
);

/** Pull Laravel's 422 bag into a flat {field: message} map for the forms. */
export function validationErrors(error) {
    const errors = error?.response?.data?.errors;

    if (!errors) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(errors).map(([field, messages]) => [field, messages[0]]),
    );
}

export default api;
