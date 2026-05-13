const RELOAD_KEY = "hi-events:stale-chunk-reload-at";
const RELOAD_WINDOW_MS = 10_000;

const isChunkLoadError = (reason: unknown): boolean => {
    if (!reason) return false;

    const err = reason as { name?: string; message?: string };
    if (err.name === "ChunkLoadError") return true;

    const message = typeof err.message === "string" ? err.message : String(reason);
    return (
        message.includes("Failed to fetch dynamically imported module") ||
        message.includes("Importing a module script failed") ||
        message.includes("error loading dynamically imported module") ||
        /Loading (CSS )?chunk [^ ]+ failed/.test(message)
    );
};

const reloadOnce = (): void => {
    const last = Number(sessionStorage.getItem(RELOAD_KEY) || 0);
    if (Date.now() - last < RELOAD_WINDOW_MS) {
        return;
    }
    sessionStorage.setItem(RELOAD_KEY, String(Date.now()));
    window.location.reload();
};

export const installStaleChunkReloadHandler = (): void => {
    window.addEventListener("error", (event) => {
        if (isChunkLoadError(event.error) || isChunkLoadError(event.message)) {
            reloadOnce();
        }
    });

    window.addEventListener("unhandledrejection", (event) => {
        if (isChunkLoadError(event.reason)) {
            reloadOnce();
        }
    });
};

export const handleStaleChunkError = (error: unknown): boolean => {
    if (!isChunkLoadError(error)) return false;
    reloadOnce();
    return true;
};
