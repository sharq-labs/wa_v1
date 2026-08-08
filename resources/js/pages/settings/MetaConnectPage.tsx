import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, ExternalLink, LockKeyhole, MessageCircle, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { metaEmbeddedSignupApi } from '@/api/metaEmbeddedSignup';
import { Alert, Button, Input, Label, Spinner } from '@/components/ui';
import { launchMetaEmbeddedSignup } from '@/lib/metaEmbeddedSignup';
import { useWorkspaceId } from '@/stores/authStore';
import { toast } from '@/stores/toastStore';

export default function MetaConnectPage() {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [pin, setPin] = useState('');
    const [confirmPin, setConfirmPin] = useState('');
    const [accepted, setAccepted] = useState(false);

    const config = useQuery({
        queryKey: ['meta-signup-config', workspaceId],
        queryFn: async () => (await metaEmbeddedSignupApi.config(workspaceId)).data,
    });

    const connect = useMutation({
        mutationFn: async () => {
            if (!config.data) throw new Error('Meta configuration is not loaded yet.');
            if (!/^\d{6}$/.test(pin)) throw new Error('Choose a six-digit WhatsApp two-step verification PIN.');
            if (pin !== confirmPin) throw new Error('The two PIN values do not match.');
            if (!accepted) throw new Error('Confirm that you understand the PIN must be kept securely.');

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
            toast.success('WhatsApp connected.', 'The number is registered and subscribed to WhatsApp webhooks.');
            setPin('');
            setConfirmPin('');
            navigate('/settings/whatsapp');
        },
    });

    const ready = config.data?.enabled === true;
    const pinValid = /^\d{6}$/.test(pin) && pin === confirmPin && accepted;

    return (
        <div className="mx-auto w-full max-w-4xl space-y-6 p-5 md:p-8 lg:p-10">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link
                        to="/settings/whatsapp"
                        className="mb-3 inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-slate-800"
                    >
                        <ArrowLeft size={16} />
                        Back to WhatsApp settings
                    </Link>
                    <h1 className="flex items-center gap-3 text-3xl font-bold tracking-tight text-slate-900">
                        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-sm">
                            <MessageCircle size={23} />
                        </span>
                        Connect WhatsApp with Meta
                    </h1>
                    <p className="mt-2 max-w-2xl text-[15px] leading-relaxed text-slate-500">
                        Use Meta Embedded Signup to connect a real WhatsApp Business Account and phone number to this workspace.
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
                    title="Could not load Meta configuration."
                    action={
                        <Button size="sm" variant="secondary" onClick={() => config.refetch()}>
                            Retry
                        </Button>
                    }
                >
                    Check the server configuration, then try again.
                </Alert>
            )}

            {config.data && !ready && (
                <Alert tone="warning" title="Meta Embedded Signup is not ready on this server.">
                    Missing: {(config.data.missing ?? []).join(', ') || 'required Meta configuration'}. Add these production values and reload this page.
                </Alert>
            )}

            {config.data && ready && (
                <>
                    <div className="grid gap-4 md:grid-cols-3">
                        {[
                            ['1', 'Meta sign-in', 'Choose the Business Portfolio, WABA and phone number in Meta.'],
                            ['2', 'Register number', 'WhatsFlow registers the selected Cloud API number with your six-digit PIN.'],
                            ['3', 'Subscribe webhooks', 'WhatsFlow subscribes the WABA so inbound messages and status events arrive automatically.'],
                        ].map(([number, title, description]) => (
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
                                    <h2 className="text-lg font-bold text-slate-900">Choose a two-step verification PIN</h2>
                                    <p className="mt-1 text-sm leading-relaxed text-slate-500">
                                        Meta requires a six-digit PIN when registering a WhatsApp Cloud API phone number. WhatsFlow uses it for the registration request and does not store it.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Six-digit PIN</Label>
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
                                    <Label>Confirm PIN</Label>
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
                                <p className="mt-2 text-sm font-medium text-red-600">The PIN values do not match.</p>
                            )}

                            <label className="mt-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-900">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={accepted}
                                    onChange={(event) => setAccepted(event.target.checked)}
                                />
                                <span>
                                    I will keep this PIN securely. Meta may require it again for phone registration or account changes.
                                </span>
                            </label>

                            <Button
                                className="mt-5 w-full"
                                disabled={!pinValid || connect.isPending}
                                loading={connect.isPending}
                                onClick={() => connect.mutate()}
                            >
                                Continue with Meta
                            </Button>
                        </div>

                        <div className="space-y-4">
                            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                                <div className="flex items-center gap-2 font-semibold text-emerald-900">
                                    <ShieldCheck size={19} />
                                    Secure onboarding
                                </div>
                                <ul className="mt-3 space-y-2 text-sm leading-relaxed text-emerald-800">
                                    <li className="flex gap-2"><CheckCircle2 className="mt-0.5 shrink-0" size={16} /> Access token is exchanged server-side.</li>
                                    <li className="flex gap-2"><CheckCircle2 className="mt-0.5 shrink-0" size={16} /> Token is never returned to the browser.</li>
                                    <li className="flex gap-2"><CheckCircle2 className="mt-0.5 shrink-0" size={16} /> WABA and phone IDs must match Meta data before saving.</li>
                                    <li className="flex gap-2"><CheckCircle2 className="mt-0.5 shrink-0" size={16} /> Webhook subscription must succeed before the account is connected.</li>
                                </ul>
                            </div>

                            <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                                <p className="font-semibold text-slate-900">Before you start</p>
                                <p className="mt-2 text-sm leading-relaxed text-slate-500">
                                    Open this page on the production HTTPS domain registered in your Meta app. Popup blockers and unapproved domains can prevent the Meta dialog from opening.
                                </p>
                                <a
                                    href="/legal/privacy"
                                    target="_blank"
                                    rel="noreferrer"
                                    className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:text-brand-900"
                                >
                                    Privacy policy <ExternalLink size={14} />
                                </a>
                            </div>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
