/**
 * A deploy replaces every hashed chunk under /assets. A page that was open
 * before the deploy — the scanner at the door, a buyer's order page — then
 * fails to load the next route it needs and shows the error screen.
 *
 * Vite reports that as `vite:preloadError`. The fix is a single reload: the
 * fresh HTML points at the new chunks. The timestamp guards against a loop if
 * the reload itself keeps failing (a broken build): at most one reload a minute.
 */
const RELOAD_FLAG = "passix:reloaded-for-stale-chunk";
const MIN_INTERVAL_MS = 60_000;

export const reloadOnStaleChunk = () => {
    if (typeof window === "undefined") return;

    window.addEventListener("vite:preloadError", (event) => {
        let lastReloadAt = 0;
        try {
            lastReloadAt = Number(sessionStorage.getItem(RELOAD_FLAG)) || 0;
        } catch {
            // Storage blocked: reload once anyway.
        }

        if (Date.now() - lastReloadAt < MIN_INTERVAL_MS) {
            return;
        }

        try {
            sessionStorage.setItem(RELOAD_FLAG, String(Date.now()));
        } catch {
            // ignore
        }

        event.preventDefault();
        window.location.reload();
    });
};
