import { create } from 'zustand';
import { ApiError } from '@/lib/api';

export type ToastTone = 'success' | 'error' | 'info';

export interface Toast {
    id: number;
    tone: ToastTone;
    title: string;
    description?: string;
    /** Milliseconds before auto-dismiss; 0 keeps the toast until dismissed. */
    duration: number;
}

interface ToastState {
    toasts: Toast[];
    push: (toast: Omit<Toast, 'id' | 'duration'> & { duration?: number }) => number;
    dismiss: (id: number) => void;
}

let nextId = 1;

/** Errors stay up longer than confirmations — the user may need to read them. */
const DEFAULT_DURATION: Record<ToastTone, number> = {
    success: 3200,
    info: 4000,
    error: 7000,
};

export const useToastStore = create<ToastState>((set) => ({
    toasts: [],
    push: ({ tone, title, description, duration }) => {
        const id = nextId++;
        set((state) => ({
            toasts: [
                ...state.toasts,
                { id, tone, title, description, duration: duration ?? DEFAULT_DURATION[tone] },
            ].slice(-4),
        }));
        return id;
    },
    dismiss: (id) => set((state) => ({ toasts: state.toasts.filter((toast) => toast.id !== id) })),
}));

/**
 * Callable from anywhere — including outside React, which is how the shared
 * QueryClient reports mutation failures.
 */
export const toast = {
    success: (title: string, description?: string) =>
        useToastStore.getState().push({ tone: 'success', title, description }),
    info: (title: string, description?: string) =>
        useToastStore.getState().push({ tone: 'info', title, description }),
    error: (title: string, description?: string) =>
        useToastStore.getState().push({ tone: 'error', title, description }),
    dismiss: (id: number) => useToastStore.getState().dismiss(id),
};

/**
 * Turns a thrown value into toast copy. Laravel validation errors (422) carry a
 * per-field bag; surfacing the first one is far more actionable than "Request
 * failed." `fallback` should already be translated by the caller.
 */
export function describeError(error: unknown, fallback: string): { title: string; description?: string } {
    if (error instanceof ApiError) {
        const firstFieldError = Object.values(error.errors)[0]?.[0];

        // Laravel's 422 message is a copy of the first field error, so only show
        // both when the field actually adds something.
        if (error.status === 422 && firstFieldError && firstFieldError !== error.message) {
            return { title: error.message, description: firstFieldError };
        }

        return { title: error.message || fallback };
    }

    if (error instanceof Error) {
        // A fetch that never reached the server has no useful message of its own.
        return { title: fallback, description: error.message };
    }

    return { title: fallback };
}
