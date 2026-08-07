import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { authApi, automationsApi, whatsappApi, workspacesApi } from '@/api';
import { Alert, Button, Input, Label, Select, Spinner } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useAuthStore, useWorkspaceId } from '@/stores/authStore';

const STEP_KEYS = ['business', 'connect', 'bot', 'done'] as const;

export default function Onboarding() {
    const workspaceId = useWorkspaceId();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const { t, locale, setLocale } = useI18n();
    const setUser = useAuthStore((s) => s.setUser);
    const [step, setStep] = useState(0);
    const [business, setBusiness] = useState({ name: '', industry: '', timezone: 'Africa/Cairo' });
    const [botId, setBotId] = useState<number | null>(null);

    const accounts = useQuery({
        queryKey: ['wa-accounts', workspaceId],
        queryFn: async () => (await whatsappApi.accounts(workspaceId)).data,
    });

    const saveBusiness = useMutation({
        mutationFn: () =>
            workspacesApi.update(workspaceId, {
                ...(business.name ? { name: business.name } : {}),
                industry: business.industry || null,
                timezone: business.timezone,
                locale,
            } as any),
        onSuccess: () => setStep(1),
    });

    const connectFake = useMutation({
        mutationFn: () => whatsappApi.connectFake(workspaceId),
        onSuccess: () => {
            accounts.refetch();
        },
    });

    const createBot = useMutation({
        mutationFn: () => automationsApi.create(workspaceId, { name: t('onboarding.first_bot_name') }),
        onSuccess: (response) => {
            setBotId(response.data.id);
            setStep(3);
        },
    });

    const finish = useMutation({
        mutationFn: () => workspacesApi.completeOnboarding(workspaceId),
        onSuccess: async () => {
            const me = await authApi.me();
            setUser(me.data.user);
            queryClient.clear();
            navigate(botId ? `/automations/${botId}` : '/');
        },
    });

    const connected = (accounts.data ?? []).length > 0;

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-slate-50 p-4">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-0 top-0 h-72 bg-linear-to-b from-brand-100/70 via-brand-50/40 to-transparent"
            />
            <div className="relative w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-8 shadow-card">
                <button
                    type="button"
                    onClick={() => setLocale(locale === 'en' ? 'ar' : 'en')}
                    className="absolute end-4 top-4 rounded-lg px-2.5 py-1.5 text-[11px] font-semibold text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800"
                >
                    {locale === 'en' ? 'العربية' : 'English'}
                </button>

                <div className="mb-6 flex items-center gap-2">
                    {STEP_KEYS.map((key, i) => (
                        <div key={key} className="flex flex-1 flex-col items-center gap-1">
                            <div
                                className={clsx(
                                    'flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold transition-colors',
                                    i < step
                                        ? 'bg-brand-600 text-white'
                                        : i === step
                                          ? 'bg-brand-100 text-brand-700 ring-2 ring-brand-500/30'
                                          : 'bg-slate-100 text-slate-400',
                                )}
                            >
                                {i < step ? <Check size={15} strokeWidth={3} /> : i + 1}
                            </div>
                            <span className="hidden text-center text-[10px] text-slate-500 sm:block">
                                {t(`onboarding.step_${key}`)}
                            </span>
                        </div>
                    ))}
                </div>

                {step === 0 && (
                    <div className="space-y-4">
                        <h2 className="text-lg font-semibold text-slate-900">{t('onboarding.business_title')}</h2>
                        <div>
                            <Label>{t('onboarding.business_name')}</Label>
                            <Input
                                value={business.name}
                                onChange={(e) => setBusiness({ ...business, name: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label>{t('onboarding.industry')}</Label>
                            <Input
                                value={business.industry}
                                onChange={(e) => setBusiness({ ...business, industry: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label>{t('onboarding.timezone')}</Label>
                            <Select
                                value={business.timezone}
                                onChange={(e) => setBusiness({ ...business, timezone: e.target.value })}
                            >
                                {['Africa/Cairo', 'Asia/Riyadh', 'Asia/Dubai', 'Europe/London', 'UTC'].map((tz) => (
                                    <option key={tz}>{tz}</option>
                                ))}
                            </Select>
                        </div>
                        <Button onClick={() => saveBusiness.mutate()} loading={saveBusiness.isPending} className="w-full">
                            {t('onboarding.continue')}
                        </Button>
                    </div>
                )}

                {step === 1 && (
                    <div className="space-y-4">
                        <h2 className="text-lg font-semibold text-slate-900">{t('onboarding.connect_title')}</h2>
                        {accounts.isLoading ? (
                            <Spinner />
                        ) : connected ? (
                            <Alert tone="success" title={t('onboarding.connected')}>
                                {accounts.data![0].display_phone_number} · {accounts.data![0].verified_name}
                            </Alert>
                        ) : (
                            <>
                                <p className="text-sm leading-relaxed text-slate-600">{t('onboarding.connect_desc')}</p>
                                <Button onClick={() => connectFake.mutate()} loading={connectFake.isPending} className="w-full">
                                    {t('onboarding.connect_sandbox')}
                                </Button>
                            </>
                        )}
                        <Button variant={connected ? 'primary' : 'secondary'} onClick={() => setStep(2)} className="w-full">
                            {connected ? t('onboarding.continue') : t('onboarding.skip_for_now')}
                        </Button>
                    </div>
                )}

                {step === 2 && (
                    <div className="space-y-4">
                        <h2 className="text-lg font-semibold text-slate-900">{t('onboarding.bot_title')}</h2>
                        <p className="text-sm leading-relaxed text-slate-600">{t('onboarding.bot_desc')}</p>
                        <Button onClick={() => createBot.mutate()} loading={createBot.isPending} className="w-full">
                            {t('onboarding.create_bot')}
                        </Button>
                        <Button variant="secondary" onClick={() => setStep(3)} className="w-full">
                            {t('onboarding.skip')}
                        </Button>
                    </div>
                )}

                {step === 3 && (
                    <div className="space-y-4">
                        <h2 className="text-lg font-semibold text-slate-900">{t('onboarding.done_title')}</h2>
                        <p className="text-sm leading-relaxed text-slate-600">{t('onboarding.done_desc')}</p>
                        <Button onClick={() => finish.mutate()} loading={finish.isPending} className="w-full">
                            {t('onboarding.go_to_app')}
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}
