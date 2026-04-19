import {useCallback, useEffect, useRef} from "react";
import {getConfig} from "../utilites/config.ts";
import {isSsr} from "../utilites/helpers.ts";

const SCRIPT_URL = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";

declare global {
    interface Window {
        turnstile?: {
            render: (el: HTMLElement, opts: {
                sitekey: string;
                size?: "invisible" | "normal" | "compact";
                callback?: (token: string) => void;
                "error-callback"?: () => void;
            }) => string;
            execute: (id: string) => void;
            reset: (id: string) => void;
            remove: (id: string) => void;
            getResponse: (id: string) => string | undefined;
        };
    }
}

let scriptLoading: Promise<void> | null = null;

const loadScript = (): Promise<void> => {
    if (isSsr()) return Promise.resolve();
    if (window.turnstile) return Promise.resolve();
    if (scriptLoading) return scriptLoading;

    scriptLoading = new Promise<void>((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(`script[src^="${SCRIPT_URL.split("?")[0]}"]`);
        if (existing) {
            if (window.turnstile) return resolve();
            existing.addEventListener("load", () => resolve());
            existing.addEventListener("error", () => reject(new Error("Turnstile script failed to load")));
            return;
        }
        const tag = document.createElement("script");
        tag.src = SCRIPT_URL;
        tag.async = true;
        tag.defer = true;
        tag.addEventListener("load", () => resolve());
        tag.addEventListener("error", () => reject(new Error("Turnstile script failed to load")));
        document.head.appendChild(tag);
    });

    return scriptLoading;
};

/**
 * Renders an invisible Turnstile widget and returns getToken() that resolves
 * to a fresh challenge token. Returns null token when VITE_TURNSTILE_SITE_KEY
 * is unset (dev default), letting the caller send no token — the backend
 * middleware is a no-op in that case.
 */
export const useTurnstile = (): {getToken: () => Promise<string | null>} => {
    const siteKey = getConfig("VITE_TURNSTILE_SITE_KEY");
    const widgetIdRef = useRef<string | null>(null);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const resolverRef = useRef<((token: string) => void) | null>(null);
    const rejecterRef = useRef<(() => void) | null>(null);

    useEffect(() => {
        if (!siteKey || isSsr()) return;

        const container = document.createElement("div");
        container.style.position = "absolute";
        container.style.left = "-9999px";
        container.style.top = "-9999px";
        container.setAttribute("aria-hidden", "true");
        document.body.appendChild(container);
        containerRef.current = container;

        let cancelled = false;
        loadScript()
            .then(() => {
                if (cancelled || !window.turnstile || !containerRef.current) return;
                widgetIdRef.current = window.turnstile.render(containerRef.current, {
                    sitekey: siteKey,
                    size: "invisible",
                    callback: (token) => {
                        resolverRef.current?.(token);
                        resolverRef.current = null;
                        rejecterRef.current = null;
                    },
                    "error-callback": () => {
                        rejecterRef.current?.();
                        resolverRef.current = null;
                        rejecterRef.current = null;
                    },
                });
            })
            .catch(() => {
                // Script load failure: getToken() will continue to resolve null
                // and the request will go out tokenless. Backend will reject
                // (403) if TURNSTILE_ENABLED=true on the server.
            });

        return () => {
            cancelled = true;
            if (widgetIdRef.current && window.turnstile) {
                try {
                    window.turnstile.remove(widgetIdRef.current);
                } catch {
                    /* ignore */
                }
            }
            if (containerRef.current && containerRef.current.parentNode) {
                containerRef.current.parentNode.removeChild(containerRef.current);
            }
            widgetIdRef.current = null;
            containerRef.current = null;
        };
    }, [siteKey]);

    const getToken = useCallback((): Promise<string | null> => {
        if (!siteKey || isSsr() || !widgetIdRef.current || !window.turnstile) {
            return Promise.resolve(null);
        }
        return new Promise<string | null>((resolve) => {
            resolverRef.current = (token) => resolve(token);
            rejecterRef.current = () => resolve(null);
            try {
                window.turnstile!.reset(widgetIdRef.current!);
                window.turnstile!.execute(widgetIdRef.current!);
            } catch {
                resolve(null);
            }
        });
    }, [siteKey]);

    return {getToken};
};
