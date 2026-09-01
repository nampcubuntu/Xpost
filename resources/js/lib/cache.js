/**
 * Show what we had, then replace it with what is true.
 *
 * Every list page fetched from scratch on arrival, so moving between them
 * cleared the table to "Đang tải…" and waited -- for rows that were, nearly
 * always, the same rows. Behind a tunnel that is most of a second of blank
 * table per navigation, and the Short link page pays a Cloudflare round trip on
 * top.
 *
 * So a page renders its last answer immediately and refreshes underneath. The
 * data on screen is at most one navigation old and is corrected within the
 * second; the alternative was showing nothing at all for the same second.
 *
 * Held in memory, deliberately. Switching team reloads the window, so a cache
 * that lives in the tab cannot outlive the tenant it belongs to -- which is the
 * one bug a cache like this must never have.
 */
const store = new Map();

/** Rough ceiling, so a long session cannot grow this without bound. */
const MAX_ENTRIES = 60;

export function cached(key) {
    return store.get(key);
}

export function remember(key, value) {
    // Oldest out first. Map iterates in insertion order, so the first key is
    // the least recently written.
    if (store.size >= MAX_ENTRIES && ! store.has(key)) {
        store.delete(store.keys().next().value);
    }

    store.set(key, value);
}

/**
 * Everything, which is what a write invalidates.
 *
 * Called from the api interceptor rather than from each action, because the
 * call sites are the part that gets forgotten: a page grows a new button,
 * nobody adds the matching invalidation, and the symptom is a deleted row
 * reappearing for a second on the next visit.
 *
 * All of it rather than the caller's own list, because a write reaches further
 * than the page it was made on -- assigning a proxy changes the accounts page,
 * deleting an app changes the accounts that were on it. Guessing which lists a
 * write touches is the same mistake one level up.
 */
export function forgetAll() {
    store.clear();
}

/**
 * Fetch, showing the previous answer first if there is one.
 *
 * `apply` is called once when there is nothing cached, and twice when there is
 * -- so it has to be safe to run against the same component twice, which for
 * assigning refs it is.
 *
 * Returns whether anything was shown immediately, which is what a page uses to
 * decide between rendering a spinner and rendering the rows it already has.
 */
export async function swr(key, request, apply) {
    const hit = cached(key);
    const warm = hit !== undefined;

    if (warm) {
        apply(hit);
    }

    const fresh = await request();

    remember(key, fresh);
    apply(fresh);

    return warm;
}
