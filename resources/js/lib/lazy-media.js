/**
 * Load a video's metadata when it is about to be looked at, not before.
 *
 * `preload="metadata"` on a list of rows fetches every one of them the moment
 * the page renders. That is expensive here for a reason worth spelling out:
 * five of eight clips in this library are not "faststart" -- their `moov` atom
 * sits at the end of the file, so the browser reads the head, fails to find it,
 * and range-requests the tail. Two or three round trips each, twenty-five rows
 * at a time, through a tunnel.
 *
 * So nothing loads until it is near the viewport. What is on screen behaves
 * exactly as before; what is four screens down costs nothing until it is
 * scrolled to.
 *
 * The margin is deliberate. Loading precisely at the edge means every scroll
 * shows a row of black boxes that fill in afterwards; a screen of warning is
 * enough for the frame to be there by the time it is.
 */
const AHEAD = '300px';

export const lazyMedia = {
    mounted(el) {
        // Nothing at all to start with -- not even the metadata request.
        el.preload = 'none';

        if (! ('IntersectionObserver' in window)) {
            // No observer, no laziness: correctness first, and this is the
            // behaviour the page had before.
            el.preload = 'metadata';

            return;
        }

        const observer = new IntersectionObserver(([entry]) => {
            if (! entry.isIntersecting) {
                return;
            }

            el.preload = 'metadata';
            // Explicit, because changing the attribute after mount does not by
            // itself start a fetch.
            el.load();

            // Once is enough: metadata does not need reloading, and leaving the
            // observer attached keeps it firing on every scroll past.
            observer.disconnect();
        }, { rootMargin: AHEAD });

        observer.observe(el);
        el._lazyMedia = observer;
    },

    unmounted(el) {
        // A list that re-renders on every filter change would otherwise leave
        // an observer behind per row, per render.
        el._lazyMedia?.disconnect();
        delete el._lazyMedia;
    },
};
