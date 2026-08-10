import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, MessageCircle, Plus, RefreshCw, ShieldCheck } from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import { whatsappApi } from '@/api';
import { Alert, Badge, Button, QueryError, Spinner, statusColor } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import { confirmDialog } from '@/stores/confirmStore';
import { toast } from '@/stores/toastStore';

export default function WhatsAppSettingsPage() {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const { t, statusLabel, dir, locale } = useI18n();

    const accounts = useQuery({
        queryKey: ['wa-accounts', workspaceId],
        queryFn: async () => (await whatsappApi.accounts(workspaceId)).data,
    });

    const signupConfig = useQuery({
        queryKey: ['meta-signup-config', workspaceId],
        queryFn: async () => (await whatsappApi.embeddedSignupConfig(workspaceId)).data,
    });

    const connectFake = useMutation({
        mutationFn: () => whatsappApi.connectFake(workspaceId),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['wa-accounts', workspaceId] });
            toast.success(t('wa_settings.sandbox_connected'));
        },
    });

    const disconnect = useMutation({
        mutationFn: (id: number) => whatsappApi.disconnect(workspaceId, id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['wa-accounts', workspaceId] });
            toast.success(t('wa_settings.disconnected'));
        },
    });

    const sync = useMutation({
        mutationFn: (id: number) => whatsappApi.syncTemplates(workspaceId, id),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['templates', workspaceId] }),
                queryClient.invalidateQueries({ queryKey: ['wa-accounts', workspaceId] }),
            ]);
            toast.success(t('wa_settings.templates_synced'));
        },
    });

    const embeddedSignup = signupConfig.data?.enabled === true;

    return (
        <div className="mx-auto w-full max-w-6xl space-y-6 p-5 md:p-8 lg:p-10">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link
                        to="/settings"
                        className="mb-3 inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-slate-800"
                    >
                        <ArrowLeft size={16} className={dir === 'rtl' ? 'rotate-180' : undefined} />
                        {t('nav.settings')}
                    </Link>
                    <h1 className="flex items-center gap-3 text-3xl font-bold tracking-tight text-slate-900">
                        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-sm">
                            <MessageCircle size={23} />
                        </span>
                        {t('settings.whatsapp')}
                    </h1>
                    <p className="mt-2 text-[15px] leading-relaxed text-slate-500">
                        {t('wa_settings.subtitle')}
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {signupConfig.isLoading ? (
                        <Button disabled loading>
                            {t('settings.connect_whatsapp')}
                        </Button>
                    ) : embeddedSignup ? (
                        <Button onClick={() => navigate('/settings/whatsapp/connect')}>
                            <Plus size={17} />
                            {t('settings.connect_whatsapp')}
                        </Button>
                    ) : (
                        <Button onClick={() => connectFake.mutate()} loading={connectFake.isPending}>
                            <Plus size={17} />
                            {t('settings.connect_whatsapp')} ({t('settings.sandbox')})
                        </Button>
                    )}
                </div>
            </div>

            {embeddedSignup && (
                <Alert tone="success" title={t('wa_settings.meta_ready_title')}>
                    <span className="inline-flex items-center gap-2">
                        <ShieldCheck size={17} />
                        {t('wa_settings.meta_ready_desc')}
                    </span>
                </Alert>
            )}

            {signupConfig.data && !signupConfig.data.enabled && (
                <Alert tone="warning" title={t('wa_settings.meta_missing_title')}>
                    {t('wa_settings.meta_missing_desc')}
                </Alert>
            )}

            {signupConfig.isError && (
                <Alert
                    tone="danger"
                    title={t('settings.signup_config_failed')}
                    action={
                        <Button size="sm" variant="secondary" onClick={() => signupConfig.refetch()}>
                            {t('common.retry')}
                        </Button>
                    }
                >
                    {t('settings.signup_config_failed_desc')}
                </Alert>
            )}

            {accounts.isError ? (
                <QueryError onRetry={() => accounts.refetch()} />
            ) : accounts.isLoading ? (
                <Spinner />
            ) : (accounts.data ?? []).length === 0 ? (
                <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center shadow-card">
                    <MessageCircle className="mx-auto text-slate-300" size={38} />
                    <h2 className="mt-4 text-lg font-bold text-slate-900">{t('wa_settings.no_number_title')}</h2>
                    <p className="mx-auto mt-2 max-w-lg text-sm leading-relaxed text-slate-500">
                        {t('wa_settings.no_number_desc')}
                    </p>
                </div>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {(accounts.data ?? []).map((account) => (
                        <div
                            key={account.id}
                            className="flex min-h-52 flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-card"
                        >
                            <div>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-lg font-semibold text-slate-900">
                                            {account.display_phone_number || t('wa_settings.account_fallback', { id: account.id })}
                                        </p>
                                        <p className="mt-1 truncate text-sm text-slate-500">
                                            {account.verified_name || t('wa_settings.business')}
                                        </p>
                                    </div>
                                    <Badge color={statusColor(account.status)}>{statusLabel(account.status)}</Badge>
                                </div>

                                <div className="mt-4 flex flex-wrap gap-2">
                                    <Badge color={account.provider === 'meta' ? 'green' : 'slate'}>
                                        {account.provider === 'meta' ? t('wa_settings.provider_meta') : t('wa_settings.provider_sandbox')}
                                    </Badge>
                                    {account.quality_rating && (
                                        <Badge color="green">{t('wa_settings.quality', { value: account.quality_rating })}</Badge>
                                    )}
                                    {account.messaging_limit && <Badge color="blue">{account.messaging_limit}</Badge>}
                                </div>

                                {account.last_sync_at && (
                                    <p className="mt-3 text-xs text-slate-400">
                                        {t('wa_settings.last_sync', {
                                            value: new Date(account.last_sync_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US'),
                                        })}
                                    </p>
                                )}
                            </div>

                            <div className="mt-5 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    loading={sync.isPending && sync.variables === account.id}
                                    onClick={() => sync.mutate(account.id)}
                                >
                                    <RefreshCw size={15} />
                                    {t('wa_settings.sync_templates')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    loading={disconnect.isPending && disconnect.variables === account.id}
                                    onClick={async () => {
                                        const confirmed = await confirmDialog({
                                            title: t('settings.disconnect_title'),
                                            description: t('settings.disconnect_desc'),
                                            confirmLabel: t('settings.disconnect'),
                                            destructive: true,
                                        });

                                        if (confirmed) disconnect.mutate(account.id);
                                    }}
                                >
                                    {t('settings.disconnect')}
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
