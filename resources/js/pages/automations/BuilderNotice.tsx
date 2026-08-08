import { AlertTriangle, CheckCircle2, Info, X } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import { clsx } from 'clsx';

type NoticeTone = 'success' | 'error' | 'warning' | 'info';

const TONE: Record<
    NoticeTone,
    { wrap: string; iconWrap: string; Icon: typeof CheckCircle2; title: string }
> = {
    success: {
        wrap: 'border-emerald-200/80 bg-white shadow-emerald-100/80',
        iconWrap: 'bg-emerald-50 text-emerald-600',
        Icon: CheckCircle2,
        title: 'text-emerald-900',
    },
    error: {
        wrap: 'border-red-200/80 bg-white shadow-red-100/80',
        iconWrap: 'bg-red-50 text-red-600',
        Icon: AlertTriangle,
        title: 'text-red-900',
    },
    warning: {
        wrap: 'border-amber-200/80 bg-white shadow-amber-100/80',
        iconWrap: 'bg-amber-50 text-amber-600',
        Icon: AlertTriangle,
        title: 'text-amber-900',
    },
    info: {
        wrap: 'border-sky-200/80 bg-white shadow-sky-100/80',
        iconWrap: 'bg-sky-50 text-sky-600',
        Icon: Info,
        title: 'text-sky-900',
    },
};

/**
 * Floating canvas notice (toast-style) — replaces full-width validation banners.
 */
export default function BuilderNotice({
    tone = 'info',
    title,
    children,
    onClose,
    autoHideMs,
}: {
    tone?: NoticeTone;
    title: string;
    children?: ReactNode;
    onClose?: () => void;
    autoHideMs?: number;
}) {
    const style = TONE[tone];
    const Icon = style.Icon;

    useEffect(() => {
        if (!autoHideMs || !onClose) return;
        const timer = window.setTimeout(onClose, autoHideMs);
        return () => window.clearTimeout(timer);
    }, [autoHideMs, onClose]);

    return (
        <div
            role="status"
            className={clsx(
                'pointer-events-auto w-full max-w-md rounded-2xl border px-3.5 py-3 shadow-lg',
                'animate-[builder-notice-in_220ms_cubic-bezier(0.16,1,0.3,1)]',
                style.wrap,
            )}
        >
            <div className="flex items-start gap-3">
                <span className={clsx('mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl', style.iconWrap)}>
                    <Icon size={16} strokeWidth={2.4} />
                </span>
                <div className="min-w-0 flex-1">
                    <p className={clsx('text-sm font-semibold leading-snug', style.title)}>{title}</p>
                    {children && <div className="mt-1.5 text-xs leading-relaxed text-slate-600">{children}</div>}
                </div>
                {onClose && (
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                        aria-label="Close"
                    >
                        <X size={14} />
                    </button>
                )}
            </div>
        </div>
    );
}
