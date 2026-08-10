import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, ExternalLink, LockKeyhole, MessageCircle, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { metaEmbeddedSignupApi } from '@/api/metaEmbeddedSignup';
import { Alert, Button, Input, Label, Spinner } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { launchMetaEmbeddedSignup } from '@/lib/metaEmbeddedSignup';
import { useWorkspaceId } from '@/stores/authStore';
import { toast } from '@/stores/toastStore';

export default function MetaConnectPage() {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const { t, dir } = useI18n();
    const [pin, setPin] = useState('');
    const [confirmPin, setConfirmPin] = useState('');
    const [accepted, setAccepted] = useState(false);

    const config = useQuery({
        queryKey: ['meta-signup-config', workspaceId],
        queryFn: async () => (await metaEmbeddedSignupApi.config(workspaceId)).data,
    });

    const connect = useMutation({
        mutationFn: async () => {
            if (!config.data) throw new Error(t('meta_connect.config_not_loaded'));
            if (!/^\d{6}$/.test(pin)) throw new Error(t('meta_connect.pin_invalid'));
            if (pin !== confirmPin) throw new Error(t('meta_connect.pin_mismatch'));
            if (!accepted) throw new Error(t('meta_connect.pin_ack_required'));

            const result = await launchMetaEmbeddedSignup(config.data);

            return metaEmbeddedSignupApi.complete(workspaceId, {
                code: result.code,
                waba_id: result.wabaId,
                phone_number_id: result.phoneNumberId,
                business_id: result.businessId,
                pin,
            });
        },
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['wa-accounts', workspaceId] }),
                queryClient.invalidateQueries({ queryKey: ['whatsapp-health', workspaceId] }),
                queryClient.invalidateQueries({ queryKey: ['meta-signup-config', workspaceId] }),
            ]);
            toast.success(t('meta_connect.success_title'), t('meta_connect.success_desc'));
            setPin('');
            setConfirmPin('');
            navigate('/settings/whatsapp');
        },
    });

    const ready = config.data?.enabled === true;
    const pinValid = /^\d{6}$/.test(pin) && pin === confirmPin && accepted;

    const steps = [
        ['1', t('meta_connect.step_meta_title'), t('meta_connect.step_meta_desc')],
        ['2', t('meta_connect.step_pin_title'), t('meta_connect.step_pin_desc')],
        ['3', t('meta_connect.step_webhook_title'), t('meta_connect.step_webhook_desc')],
    ];

    return (
        <div className="mx-auto w-full max-w-4xl space-y-6 p-5 md:p-8 lg:p-10">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link
                        to="/settings/whatsapp"
                        className="mb-3 inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-slate-800"
                    >
                        <ArrowLeft size={16} className={dir === 'rtl' ? 'rotate-180' : undefined} />
                        {t('meta_connect.back')}
                    </Link>
                    <h1 className="flex items-center gap-3 text-3xl font-bold tracking-tight text-slate-900">
                        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-sm">
                            <MessageCircle size={23} />
                        </span>
                        {t('meta_connect.title')}
                    </h1>
                    <p className="mt-2 max-w-2xl text-[15px] leading-relaxed text-slate-500">
                        {t('meta_connect.subtitle')}
                    </p>
                </div>
            </div>

            {config.isLoading && (
                <div className="rounded-2xl border border-slate-200 bg-white p-8 shadow-card">
                    <Spinner />
                </div>
            )}

            {config.isError && (
                <Alert
                    tone="danger"
                    title={t('meta_connect.config_failed_title')}
                    action={
                        <Button size="sm" variant="secondary" onClick={() => config.refetch()}>
                            {t('common.retry')}
                        </Button>
                    }
                >
                    {t('meta_connect.config_failed_desc')}
                </Alert>
            )}

            {config.data && !ready && (
                <Alert tone="warning" title={t('meta_connect.not_configured_title')}>
                    {t('meta_connect.not_configured_desc', {
                        fields: (config.data.missing ?? []).join(', ') || 'META_*',
                    })}
                </Alert>
            )}

            {config.data && ready && (
                <>
                    <div className="grid gap-4 md:grid-cols-3">
                        {steps.map(([number, title, description]) => (
                            <div key={number} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                                <div className="mb-3 flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-800">
                                    {number}
                                </div>
                                <p className="font-semibold text-slate-900">{title}</p>
                                <p className="mt-1 text-sm leading-relaxed text-slate-500">{description}</p>
                            </div>
                        ))}
                    </div>

                    <div className="grid gap-5 lg:grid-cols-[1fr_0.8fr]">
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-card">
                            <div className="mb-5 flex items-start gap-3">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                                    <LockKeyhole size={20} />
                                </span>
                                <div>
                                    <h2 className="text-lg font-bold text-slate-900">{t('meta_connect.pin_title')}</h2>
                                    <p className="mt-1 text-sm leading-relaxed text-slate-500">
                                        {t('meta_connect.pin_desc')}
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>{t('meta_connect.pin_label')}</Label>
                                    <Input
                                        type="password"
                                        inputMode="numeric"
                                        autoComplete="new-password"
                                        maxLength={6}
                                        value={pin}
                                        onChange={(event) => setPin(event.target.value.replace(/\D/g, '').slice(0, 6))}
                                        placeholder="••••••"
                                    />
                                </div>
                                <div>
                                    <Label>{t('meta_connect.pin_confirm_label')}</Label>
                                    <Input
                                        type="password"
                                        inputMode="numeric"
                                        autoComplete="new-password"
                                        maxLength={6}
                                        value={confirmPin}
                                        onChange={(event) => setConfirmPin(event.target.value.replace(/\D/g, '').slice(0, 6))}
                                        placeholder="••••••"
                                    />
                                </div>
                            </div>

                            {pin.length === 6 && confirmPin.length === 6 && pin !== confirmPin && (
                                <p className="mt-2 text-sm font-medium text-red-600">{t('meta_connect.pin_mismatch')}</p>
                            )}

                            <label className="mt-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-900">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={accepted}
                                    onChange={(event) => setAccepted(event.target.checked)}
                                />
                                <span>{t('meta_connect.pin_ack')}</span>
                            </label>

                            <Button
                                className="mt-5 w-full"
                                disabled={!pinValid || connect.isPending}
                                loading={connect.isPending}
                                onClick={() => connect.mutate()}
                            >
                                {t('meta_connect.continue')}
                            </Button>
                        </div>

                        <div className="space-y-4">
                            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                                <div className="flex items-center gap-2 font-semibold text-emerald-900">
                                    <ShieldCheck size={19} />
                                    {t('meta_connect.secure_title')}
                                </div>
                                <ul className="mt-3 space-y-2 text-sm leading-relaxed text-emerald-800">
                                    {[t('meta_connect.secure_token'), t('meta_connect.secure_pin'), t('meta_connect.secure_validation'), t('meta_connect.secure_storage')].map((item) => (
                                        <li key={item} className="flex gap-2">
                                            <CheckCircle2 className="mt-0.5 shrink-0" size={16} />
                                            {item}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                                <p className="font-semibold text-slate-900">{t('meta_connect.before_title')}</p>
                                <p className="mt-2 text-sm leading-relaxed text-slate-500">
                                    {t('meta_connect.before_desc')}
                                </p>
                                <a
                                    href="/legal/privacy"
                                    target="_blank"
                                    rel="noreferrer"
                                    className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-900"
                                >
                                    {t('meta_connect.privacy')} <ExternalLink size={14} />
                                </a>
                            </div>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
