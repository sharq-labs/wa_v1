import { create } from 'zustand';

export interface ConfirmRequest {
    title: string;
    description?: string;
    /** Defaults to the translated "Confirm". */
    confirmLabel?: string;
    cancelLabel?: string;
    /** Renders the confirm button in red. Use for anything that destroys data. */
    destructive?: boolean;
}

interface ConfirmState {
    request: (ConfirmRequest & { resolve: (ok: boolean) => void }) | null;
    ask: (request: ConfirmRequest) => Promise<boolean>;
    answer: (ok: boolean) => void;
}

export const useConfirmStore = create<ConfirmState>((set, get) => ({
    request: null,
    ask: (request) =>
        new Promise<boolean>((resolve) => {
            // A second prompt while one is open would strand the first promise.
            get().request?.resolve(false);
            set({ request: { ...request, resolve } });
        }),
    answer: (ok) => {
        get().request?.resolve(ok);
        set({ request: null });
    },
}));

/**
 * Drop-in replacement for `window.confirm` — returns a promise instead of
 * blocking, and renders in-app chrome that respects the active direction.
 */
export function confirmDialog(request: ConfirmRequest): Promise<boolean> {
    return useConfirmStore.getState().ask(request);
}
