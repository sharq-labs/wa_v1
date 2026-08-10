import { ar as arDate, enUS } from 'date-fns/locale';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { en } from '@/locales/en';
import { ar } from '@/locales/ar';
import { enExtra } from '@/locales/en-extra';
import { arExtra } from '@/locales/ar-extra';
import { enAutomationExtra } from '@/locales/en-automation-extra';
import { arAutomationExtra } from '@/locales/ar-automation-extra';

type Locale = 'en' | 'ar';
type Dict = Record<string, string>;

export const dictionaries: Record<Locale, Dict> = {
    en: { ...en, ...enExtra, ...enAutomationExtra },
    ar: { ...ar, ...arExtra, ...arAutomationExtra },
};

interface I18nContextValue {
    locale: Locale;
    dir: 'ltr' | 'rtl';
    dateLocale: typeof enUS;
    t: (key: string, params?: Record<string, string | number>) => string;
    statusLabel: (status: string) => string;
    setLocale: (locale: Locale) => void;
}

const I18nContext = createContext<I18nContextValue>({
    locale: 'en',
    dir: 'ltr',
    dateLocale: enUS,
    t: (key) => key,
    statusLabel: (status) => status,
    setLocale: () => {},
});

export function I18nProvider({ children }: { children: ReactNode }) {
    const [locale, setLocaleState] = useState<Locale>(
        () => (localStorage.getItem('locale') as Locale) || 'en',
    );

    const dir: 'ltr' | 'rtl' = locale === 'ar' ? 'rtl' : 'ltr';
    const dateLocale = locale === 'ar' ? arDate : enUS;

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }, [locale, dir]);

    const setLocale = useCallback((next: Locale) => {
        localStorage.setItem('locale', next);
        setLocaleState(next);
    }, []);

    const t = useCallback(
        (key: string, params?: Record<string, string | number>) => {
            let text = dictionaries[locale][key] ?? dictionaries.en[key] ?? key;
            if (params) {
                for (const [name, value] of Object.entries(params)) {
                    text = text.replaceAll(`:${name}`, String(value));
                }
            }
            return text;
        },
        [locale],
    );

    const statusLabel = useCallback(
        (status: string) => {
            const key = `status.${status}`;
            const translated = t(key);
            return translated === key ? status.replaceAll('_', ' ') : translated;
        },
        [t],
    );

    const value = useMemo(
        () => ({ locale, dir, dateLocale, t, statusLabel, setLocale }),
        [locale, dir, dateLocale, t, statusLabel, setLocale],
    );

    return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
    return useContext(I18nContext);
}
