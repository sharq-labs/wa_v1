import { clsx } from 'clsx';
import { AlertTriangle, CheckCircle2, Info, X } from 'lucide-react';
import { useEffect } from 'react';
import { useI18n } from '@/lib/i18n';
import { useToastStore, type Toast, type ToastTone } from '@/stores/toastStore';

const TONE: Record<ToastTone, { accent: string; iconWrap: string; Icon: typeof CheckCircle2 }> = {
    success: { accent: 'bg-emerald-500', iconWrap: 'bg-emerald-50 text-emerald-600', Icon: CheckCircle2 },
    error: { accent: 'bg-red-500', iconWrap: 'bg-red-50 text-red-600', Icon: AlertTriangle },
    info: { accent: 'bg-sky-500', iconWrap: 'bg-sky-50 text-sky-600', Icon: Info },
};

function ToastRow({ toast }: { toast: Toast }) {
    const dismiss = useToastStore((s) => s.dismiss);
    const { t } = useI18n();
    const { accent, iconWrap, Icon } = TONE[toast.tone];

    useEffect(() => {
        if (toast.duration <= 0) return;
        const timer = window.setTimeout(() => dismiss(toast.id), toast.duration);
        return () => window.clearTimeout(timer);
    }, [toast.id, toast.duration, dismiss]);

    return (
        <div
            role={toast.tone === 'error' ? 'alert' : 'status'}
            className="animate-toast-in pointer-events-auto relative w-full overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-pop"
        >
            <span className={clsx('absolute inset-y-0 start-0 w-1', accent)} aria-hidden="true" />
            <div className="flex items-start gap-3 py-3 pe-2.5 ps-4">
                <span className={clsx('mt-px flex h-7 w-7 shrink-0 items-center justify-center rounded-lg', iconWrap)}>
                    <Icon size={15} strokeWidth={2.4} />
                </span>
                <div className="min-w-0 flex-1 py-0.5">
                    <p className="text-[13.5px] leading-snug font-semibold text-slate-800">{toast.title}</p>
                    {toast.description && (
                        <p className="mt-1 text-xs leading-relaxed break-words text-slate-500">{toast.description}</p>
                    )}
                </div>
                <button
                    type="button"
                    onClick={() => dismiss(toast.id)}
                    aria-label={t('common.close')}
                    className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                >
                    <X size={14} />
                </button>
            </div>
        </div>
    );
}

/**
 * Global toast outlet. Mounted once at the app root so both React callers and
 * the QueryClient's error handlers land in the same place.
 */
export default function Toaster() {
    const toasts = useToastStore((s) => s.toasts);

    if (toasts.length === 0) return null;

    return (
        <div
            aria-live="polite"
            className="pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-center gap-2 p-4 sm:inset-x-auto sm:bottom-4 sm:end-4 sm:w-[22rem] sm:items-stretch sm:p-0"
        >
            {toasts.map((toast) => (
                <ToastRow key={toast.id} toast={toast} />
            ))}
        </div>
    );
}
