import {useEffect} from 'react';

export interface EscapeClearStep {
    isActive: () => boolean;
    clear: () => void;
}

interface UseEscapeClearsFiltersOptions {
    steps: EscapeClearStep[];
    onCleared?: () => void;
    enabled?: boolean;
}

/**
 * Document-level Escape handler that clears search/filter state one step at a
 * time, in the order given. Bails while a modal is open so Mantine's
 * close-on-Escape wins, and only swallows the key when it actually clears
 * something (so Esc still bubbles to close dropdowns etc. when nothing is set).
 */
export const useEscapeClearsFilters = ({steps, onCleared, enabled = true}: UseEscapeClearsFiltersOptions): void => {
    useEffect(() => {
        if (!enabled || typeof document === 'undefined') {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key !== 'Escape') {
                return;
            }
            if (document.querySelector('[role="dialog"]')) {
                return;
            }
            for (const step of steps) {
                if (step.isActive()) {
                    event.preventDefault();
                    step.clear();
                    onCleared?.();
                    return;
                }
            }
        };

        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [steps, onCleared, enabled]);
};
