import { useMutation } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { templatesApi } from '@/api';
import { Button, FieldError, Input, Label, Modal, Select, Textarea } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import type { WhatsAppAccount } from '@/types';

const STEPS = ['Basics', 'Header', 'Body', 'Footer', 'Buttons', 'Preview & submit'];

interface TemplateForm {
    whatsapp_account_id: string;
    name: string;
    category: string;
    language: string;
    header_type: string;
    header_content: string;
    body: string;
    footer: string;
    buttons: { type: string; text: string; url?: string; phone?: string }[];
    variables: Record<string, string>;
}

export default function TemplateWizard({
    open,
    onClose,
    accounts,
    onCreated,
}: {
    open: boolean;
    onClose: () => void;
    accounts: WhatsAppAccount[];
    onCreated: () => void;
}) {
    const workspaceId = useWorkspaceId();
    const { t } = useI18n();
    const [step, setStep] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [form, setForm] = useState<TemplateForm>({
        whatsapp_account_id: '',
        name: '',
        category: 'MARKETING',
        language: 'en',
        header_type: 'none',
        header_content: '',
        body: '',
        footer: '',
        buttons: [],
        variables: {},
    });

    const set = (patch: Partial<TemplateForm>) => setForm((prev) => ({ ...prev, ...patch }));

    const variableIndexes = useMemo(() => {
        const matches = [...form.body.matchAll(/\{\{(\d+)\}\}/g)];
        return [...new Set(matches.map((m) => m[1]))].sort();
    }, [form.body]);

    const submit = useMutation({
        mutationFn: () =>
            templatesApi.create(workspaceId, {
                ...form,
                whatsapp_account_id: Number(form.whatsapp_account_id || accounts[0]?.id),
                header_type: form.header_type === 'none' ? null : form.header_type,
                header_content: form.header_content || null,
                footer: form.footer || null,
                buttons: form.buttons.length ? form.buttons : null,
                variables: Object.keys(form.variables).length ? form.variables : null,
            }),
        onSuccess: () => {
            onCreated();
            onClose();
            setStep(0);
            setError(null);
        },
        onError: (e) => setError(e instanceof ApiError ? e.message : 'Failed'),
    });

    const previewBody = form.body.replace(/\{\{(\d+)\}\}/g, (_, i) => form.variables[i] || `{{${i}}}`);

    return (
        <Modal open={open} onClose={onClose} title={`${t('templates.new')} — ${STEPS[step]}`} wide>
            <div className="mb-4 flex gap-1">
                {STEPS.map((label, i) => (
                    <div key={label} className={`h-1 flex-1 rounded-full ${i <= step ? 'bg-brand-600' : 'bg-slate-200'}`} />
                ))}
            </div>

            <div className="min-h-56 space-y-3">
                {step === 0 && (
                    <>
                        <div>
                            <Label>WhatsApp number</Label>
                            <Select
                                value={form.whatsapp_account_id}
                                onChange={(e) => set({ whatsapp_account_id: e.target.value })}
                            >
                                <option value="">—</option>
                                {accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.display_phone_number} ({account.verified_name})
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div>
                            <Label>Template name (lowercase, underscores)</Label>
                            <Input
                                value={form.name}
                                onChange={(e) => set({ name: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })}
                                placeholder="order_update"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label>{t('templates.category')}</Label>
                                <Select value={form.category} onChange={(e) => set({ category: e.target.value })}>
                                    <option value="MARKETING">Marketing</option>
                                    <option value="UTILITY">Utility</option>
                                    <option value="AUTHENTICATION">Authentication</option>
                                </Select>
                            </div>
                            <div>
                                <Label>{t('templates.language')}</Label>
                                <Select value={form.language} onChange={(e) => set({ language: e.target.value })}>
                                    <option value="en">English</option>
                                    <option value="ar">العربية</option>
                                    <option value="en_US">English (US)</option>
                                </Select>
                            </div>
                        </div>
                    </>
                )}

                {step === 1 && (
                    <>
                        <div>
                            <Label>Header type</Label>
                            <Select value={form.header_type} onChange={(e) => set({ header_type: e.target.value })}>
                                {['none', 'text', 'image', 'video', 'document'].map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        {form.header_type === 'text' && (
                            <div>
                                <Label>Header text</Label>
                                <Input value={form.header_content} onChange={(e) => set({ header_content: e.target.value })} />
                            </div>
                        )}
                        {['image', 'video', 'document'].includes(form.header_type) && (
                            <div>
                                <Label>Sample media URL</Label>
                                <Input value={form.header_content} onChange={(e) => set({ header_content: e.target.value })} placeholder="https://…" />
                            </div>
                        )}
                    </>
                )}

                {step === 2 && (
                    <>
                        <div>
                            <Label>{t('templates.body')} — use {'{{1}}, {{2}}'} for variables</Label>
                            <Textarea rows={5} value={form.body} onChange={(e) => set({ body: e.target.value })}
                                placeholder="Hello {{1}}, we are following up regarding {{2}}." />
                        </div>
                        {variableIndexes.length > 0 && (
                            <div>
                                <Label>Sample values (required by Meta review)</Label>
                                {variableIndexes.map((index) => (
                                    <div key={index} className="mb-1.5 flex items-center gap-2">
                                        <span className="w-12 rounded bg-slate-100 px-1.5 py-1 text-center font-mono text-xs">
                                            {'{{' + index + '}}'}
                                        </span>
                                        <Input
                                            value={form.variables[index] ?? ''}
                                            onChange={(e) => set({ variables: { ...form.variables, [index]: e.target.value } })}
                                        />
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                )}

                {step === 3 && (
                    <div>
                        <Label>Footer (optional, max 60 chars)</Label>
                        <Input maxLength={60} value={form.footer} onChange={(e) => set({ footer: e.target.value })} />
                    </div>
                )}

                {step === 4 && (
                    <div className="space-y-2">
                        {form.buttons.map((button, i) => (
                            <div key={i} className="flex gap-1.5">
                                <Select
                                    value={button.type}
                                    onChange={(e) => {
                                        const buttons = [...form.buttons];
                                        buttons[i] = { ...buttons[i], type: e.target.value };
                                        set({ buttons });
                                    }}
                                    className="!w-32"
                                >
                                    <option value="quick_reply">Quick reply</option>
                                    <option value="url">URL</option>
                                    <option value="phone">Phone</option>
                                </Select>
                                <Input
                                    value={button.text}
                                    placeholder="Button text"
                                    onChange={(e) => {
                                        const buttons = [...form.buttons];
                                        buttons[i] = { ...buttons[i], text: e.target.value };
                                        set({ buttons });
                                    }}
                                />
                                {button.type === 'url' && (
                                    <Input
                                        value={button.url ?? ''}
                                        placeholder="https://…"
                                        onChange={(e) => {
                                            const buttons = [...form.buttons];
                                            buttons[i] = { ...buttons[i], url: e.target.value };
                                            set({ buttons });
                                        }}
                                    />
                                )}
                                {button.type === 'phone' && (
                                    <Input
                                        value={button.phone ?? ''}
                                        placeholder="+20…"
                                        onChange={(e) => {
                                            const buttons = [...form.buttons];
                                            buttons[i] = { ...buttons[i], phone: e.target.value };
                                            set({ buttons });
                                        }}
                                    />
                                )}
                                <Button variant="ghost" size="sm" onClick={() => set({ buttons: form.buttons.filter((_, j) => j !== i) })}>
                                    ✕
                                </Button>
                            </div>
                        ))}
                        {form.buttons.length < 3 && (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => set({ buttons: [...form.buttons, { type: 'quick_reply', text: '' }] })}
                            >
                                + Add button
                            </Button>
                        )}
                    </div>
                )}

                {step === 5 && (
                    <div className="rounded-xl bg-[#efe7dd] p-4">
                        <div className="mx-auto max-w-xs rounded-lg rounded-es-none bg-white p-3 text-sm shadow">
                            {form.header_type === 'text' && form.header_content && (
                                <p className="mb-1 font-bold">{form.header_content}</p>
                            )}
                            {['image', 'video', 'document'].includes(form.header_type) && (
                                <div className="mb-2 rounded bg-slate-100 py-6 text-center text-xs text-slate-400">
                                    [{form.header_type} header]
                                </div>
                            )}
                            <p className="whitespace-pre-wrap">{previewBody}</p>
                            {form.footer && <p className="mt-1.5 text-[11px] text-slate-400">{form.footer}</p>}
                            {form.buttons.length > 0 && (
                                <div className="mt-2 space-y-1 border-t border-slate-100 pt-2">
                                    {form.buttons.map((button, i) => (
                                        <p key={i} className="text-center text-xs font-medium text-[#128c7e]">
                                            {button.text}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}

                <FieldError error={error ?? undefined} />
            </div>

            <div className="mt-4 flex justify-between">
                <Button variant="secondary" onClick={() => (step === 0 ? onClose() : setStep(step - 1))}>
                    {step === 0 ? t('common.cancel') : t('common.back')}
                </Button>
                {step < STEPS.length - 1 ? (
                    <Button
                        onClick={() => setStep(step + 1)}
                        disabled={(step === 0 && (!form.name || !form.whatsapp_account_id)) || (step === 2 && !form.body)}
                    >
                        {t('common.next')}
                    </Button>
                ) : (
                    <Button onClick={() => submit.mutate()} disabled={submit.isPending}>
                        Submit for review
                    </Button>
                )}
            </div>
        </Modal>
    );
}
