import { AlertTriangle } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useConfirmStore } from '@/stores/confirmStore';

/**
 * Renders whatever `confirmDialog()` most recently asked. Mounted once at the
 * app root; there is never more than one prompt on screen.
 */
export default function ConfirmDialogHost() {
    const request = useConfirmStore((s) => s.request);
    const answer = useConfirmStore((s) => s.answer);
    const { t } = useI18n();
    const confirmRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!request) return;
        confirmRef.current?.focus();
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') answer(false);
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [request, answer]);

    if (!request) return null;

    return (
        <div
            className="animate-fade-in fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-[2px]"
            onClick={() => answer(false)}
        >
            <div
                role="alertdialog"
                aria-modal="true"
                aria-label={request.title}
                className="animate-modal-in w-full max-w-md rounded-2xl bg-white p-6 shadow-pop"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-start gap-3.5">
                    {request.destructive && (
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600">
                            <AlertTriangle size={18} strokeWidth={2.3} />
                        </span>
                    )}
                    <div className="min-w-0 flex-1">
                        <h2 className="text-base font-semibold text-slate-900">{request.title}</h2>
                        {request.description && (
                            <p className="mt-1.5 text-sm leading-relaxed text-slate-500">{request.description}</p>
                        )}
                    </div>
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <Button variant="secondary" size="sm" onClick={() => answer(false)}>
                        {request.cancelLabel ?? t('common.cancel')}
                    </Button>
                    <Button
                        ref={confirmRef}
                        size="sm"
                        variant={request.destructive ? 'danger' : 'primary'}
                        onClick={() => answer(true)}
                    >
                        {request.confirmLabel ?? t('common.confirm')}
                    </Button>
                </div>
            </div>
        </div>
    );
}
