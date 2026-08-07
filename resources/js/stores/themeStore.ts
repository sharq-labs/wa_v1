import { create } from 'zustand';

export type ThemeMode = 'light' | 'dark';

interface ThemeState {
    theme: ThemeMode;
    setTheme: (theme: ThemeMode) => void;
    toggleTheme: () => void;
}

function applyTheme(theme: ThemeMode) {
    const root = document.documentElement;
    root.classList.toggle('dark', theme === 'dark');
    root.style.colorScheme = theme;
    try {
        localStorage.setItem('theme', theme);
    } catch {
        // ignore quota / private mode
    }
}

function readStoredTheme(): ThemeMode {
    try {
        const stored = localStorage.getItem('theme');
        if (stored === 'dark' || stored === 'light') return stored;
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark';
    } catch {
        // ignore
    }
    return 'light';
}

const initial = typeof document !== 'undefined' ? readStoredTheme() : 'light';
if (typeof document !== 'undefined') applyTheme(initial);

export const useThemeStore = create<ThemeState>((set, get) => ({
    theme: initial,
    setTheme: (theme) => {
        applyTheme(theme);
        set({ theme });
    },
    toggleTheme: () => {
        const next = get().theme === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        set({ theme: next });
    },
}));
