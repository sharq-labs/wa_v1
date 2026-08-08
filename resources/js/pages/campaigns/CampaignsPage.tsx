import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    AlertTriangle,
    BarChart3,
    CheckCircle2,
    Clock3,
    Copy,
    Eye,
    Megaphone,
    Plus,
    Send,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { campaignsApi, contactsApi, segmentsApi, tagsApi, templatesApi, whatsappApi } from '@/api';
import { Badge, Button, EmptyState, Input, Label, Modal, PageHeader, QueryError, Select, Spinner, statusColor } from '@/components/ui';
import { api } from '@/lib/api';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import { confirmDialog } from '@/stores/confirmStore';
import type { Campaign, CampaignAnalytics, CampaignAudiencePreview, WhatsAppTemplate } from '@/types';

type CampaignDetailResponse = {
    campaign: Campaign & { template?: WhatsAppTemplate };
    audience_count: number;
    audience_preview: CampaignAudiencePreview | null;
    analytics: CampaignAnalytics;
};

type CampaignRecipient = {
    id: number;
    status: string;
    error_message: string | null;
    sent_at: string | null;
    contact?: { full_name?: string; display_name?: string; first_name?: string; last_name?: string; phone_number: string; opt_in_status: string };
    message?: { status: string; delivered_at: string | null; read_at: string | null; error_message: string | null } | null;
};

const pct = (part: number, total: number) => (total > 0 ? Math.round((part / total) * 100) : 0);

export default function CampaignsPage() {
    const { t, statusLabel, locale } = useI18n();
    const tr = (en: string, ar: string) => (locale === 'ar' ? ar : en);
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const [createOpen, setCreateOpen] = useState(false);
    const [detailId, setDetailId] = useState<number | null>(null);
    const [page] = useState(1);

    const campaigns = useQuery({
        queryKey: ['campaigns', workspaceId, page],
        queryFn: async () => (await campaignsApi.list(workspaceId, page)).data,
        refetchInterval: 10_000,
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['campaigns', workspaceId] });

    const schedule = useMutation({
        mutationFn: (id: number) => campaignsApi.schedule(workspaceId, id),
        onSuccess: invalidate,
    });
    const pause = useMutation({ mutationFn: (id: number) => campaignsApi.pause(workspaceId, id), onSuccess: invalidate });
    const resume = useMutation({ mutationFn: (id: number) => campaignsApi.resume(workspaceId, id), onSuccess: invalidate });
    const cancel = useMutation({ mutationFn: (id: number) => campaignsApi.cancel(workspaceId, id), onSuccess: invalidate });
    const duplicate = useMutation({
        mutationFn: (id: number) => api.post<Campaign>(`/api/workspaces/${workspaceId}/campaigns/${id}/duplicate`),
        onSuccess: invalidate,
    });

    const items = useMemo(() => campaigns.data?.items ?? [], [campaigns.data?.items]);
    const totals = useMemo(() => {
        const sent = items.reduce((sum, item) => sum + item.sent_count, 0);
        const delivered = items.reduce((sum, item) => sum + item.delivered_count, 0);
        const read = items.reduce((sum, item) => sum + item.read_count, 0);
        const failed = items.reduce((sum, item) => sum + item.failed_count, 0);
        return { sent, delivered, read, failed };
    }, [items]);

    const confirmSendNow = async (campaign: Campaign) => {
        const confirmed = await confirmDialog({
            title: t('campaigns.send_now_title', { name: campaign.name }),
            description: tr(
                'The audience will be checked again for WhatsApp consent before sending.',
                'سيتم فحص موافقة العملاء على واتساب مرة أخرى قبل الإرسال.',
            ),
            confirmLabel: t('campaigns.send_now'),
            destructive: true,
        });
        if (confirmed) schedule.mutate(campaign.id);
    };

    const confirmCancel = async (campaign: Campaign) => {
        const confirmed = await confirmDialog({
            title: t('campaigns.cancel_title', { name: campaign.name }),
            description: t('campaigns.cancel_desc'),
            confirmLabel: t('campaigns.cancel_confirm'),
            cancelLabel: t('campaigns.cancel_dismiss'),
            destructive: true,
        });
        if (confirmed) cancel.mutate(campaign.id);
    };

    return (
        <div className="p-4 md:p-6 lg:p-8">
            <PageHeader
                title={t('campaigns.title')}
                subtitle={tr(
                    'WhatsApp broadcasts with consent checks, scheduling and delivery analytics.',
                    'حملات واتساب مع فحص الموافقات والجدولة وتحليلات التسليم والقراءة.',
                )}
                actions={
                    <Button onClick={() => setCreateOpen(true)}>
                        <Plus size={15} /> {t('campaigns.new')}
                    </Button>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <MetricCard icon={<Send size={18} />} label={tr('Sent', 'تم الإرسال')} value={totals.sent} hint={tr('Meta accepted', 'تم قبولها من Meta')} />
                <MetricCard icon={<CheckCircle2 size={18} />} label={tr('Delivered', 'تم التسليم')} value={`${pct(totals.delivered, totals.sent)}%`} hint={`${totals.delivered} ${tr('messages', 'رسالة')}`} />
                <MetricCard icon={<Eye size={18} />} label={tr('Read', 'تمت القراءة')} value={`${pct(totals.read, totals.sent)}%`} hint={`${totals.read} ${tr('messages', 'رسالة')}`} />
                <MetricCard icon={<AlertTriangle size={18} />} label={tr('Failed', 'فشل')} value={totals.failed} hint={tr('Provider failures', 'أخطاء مزود الخدمة')} />
            </div>

            {campaigns.isLoading ? (
                <Spinner />
            ) : campaigns.isError ? (
                <QueryError onRetry={() => campaigns.refetch()} />
            ) : items.length === 0 ? (
                <EmptyState
                    icon={<Megaphone size={22} />}
                    title={t('campaigns.empty')}
                    action={<Button onClick={() => setCreateOpen(true)}>{t('campaigns.new')}</Button>}
                />
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-card">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5 text-start">{t('common.name')}</th>
                                <th className="px-3 text-start">{t('campaigns.template')}</th>
                                <th className="px-3 text-start">{t('common.status')}</th>
                                <th className="px-3 text-end">{tr('Sent', 'مرسل')}</th>
                                <th className="px-3 text-end">{tr('Delivered', 'تسليم')}</th>
                                <th className="px-3 text-end">{tr('Read', 'قراءة')}</th>
                                <th className="px-3 text-end">{t('dashboard.failed')}</th>
                                <th className="px-3 text-end">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((campaign) => (
                                <tr key={campaign.id} className="border-t border-slate-100 hover:bg-slate-50/60">
                                    <td className="px-4 py-3">
                                        <button className="text-start font-semibold text-slate-800 hover:text-brand-700" onClick={() => setDetailId(campaign.id)}>
                                            {campaign.name}
                                        </button>
                                        <p className="mt-0.5 text-[11px] text-slate-400">{campaign.whatsapp_account?.display_phone_number}</p>
                                    </td>
                                    <td className="px-3 font-mono text-xs">{campaign.template?.name}</td>
                                    <td className="px-3">
                                        <Badge color={statusColor(campaign.status)}>{statusLabel(campaign.status)}</Badge>
                                    </td>
                                    <td className="px-3 text-end font-medium">{campaign.sent_count}</td>
                                    <td className="px-3 text-end">{pct(campaign.delivered_count, campaign.sent_count)}%</td>
                                    <td className="px-3 text-end">{pct(campaign.read_count, campaign.sent_count)}%</td>
                                    <td className="px-3 text-end">{campaign.failed_count}</td>
                                    <td className="px-3 text-end">
                                        <div className="flex justify-end gap-1">
                                            <Button size="sm" variant="ghost" onClick={() => setDetailId(campaign.id)} title={tr('View', 'عرض')}>
                                                <Eye size={14} />
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                loading={duplicate.isPending && duplicate.variables === campaign.id}
                                                onClick={() => duplicate.mutate(campaign.id)}
                                                title={tr('Duplicate', 'نسخ')}
                                            >
                                                <Copy size={14} />
                                            </Button>
                                            {campaign.status === 'draft' && (
                                                <Button size="sm" onClick={() => void confirmSendNow(campaign)}>
                                                    {t('campaigns.send_now')}
                                                </Button>
                                            )}
                                            {campaign.status === 'processing' && (
                                                <Button size="sm" variant="secondary" onClick={() => pause.mutate(campaign.id)}>{t('common.pause')}</Button>
                                            )}
                                            {campaign.status === 'paused' && (
                                                <Button size="sm" onClick={() => resume.mutate(campaign.id)}>{t('campaigns.resume')}</Button>
                                            )}
                                            {['draft', 'scheduled', 'processing', 'paused'].includes(campaign.status) && (
                                                <Button size="sm" variant="ghost" onClick={() => void confirmCancel(campaign)}>{t('common.cancel')}</Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <CreateCampaignModal open={createOpen} onClose={() => setCreateOpen(false)} onCreated={invalidate} />
            <CampaignDetailModal campaignId={detailId} onClose={() => setDetailId(null)} />
        </div>
    );
}

function MetricCard({ icon, label, value, hint }: { icon: React.ReactNode; label: string; value: string | number; hint: string }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-card">
            <div className="mb-2 flex items-center gap-2 text-slate-500">{icon}<span className="text-xs font-semibold">{label}</span></div>
            <p className="text-2xl font-bold text-slate-900">{value}</p>
            <p className="mt-1 text-[11px] text-slate-400">{hint}</p>
        </div>
    );
}

function CreateCampaignModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
    const workspaceId = useWorkspaceId();
    const { t, locale } = useI18n();
    const tr = (en: string, ar: string) => (locale === 'ar' ? ar : en);
    const [step, setStep] = useState(1);
    const [form, setForm] = useState({
        name: '', whatsapp_account_id: '', whatsapp_template_id: '', audience_type: 'all', tag_id: '', segment_id: '',
        send_mode: 'draft', scheduled_at: '',
    });
    const [mappings, setMappings] = useState<Record<number, { source: 'contact' | 'static'; value: string }>>({});
    const [error, setError] = useState<string | null>(null);

    const accounts = useQuery({ queryKey: ['wa-accounts', workspaceId], queryFn: async () => (await whatsappApi.accounts(workspaceId)).data, enabled: open });
    const templates = useQuery({
        queryKey: ['templates', workspaceId, 'approved'],
        queryFn: async () => (await templatesApi.list(workspaceId, { status: 'approved' })).data,
        enabled: open,
    });
    const tags = useQuery({ queryKey: ['tags', workspaceId], queryFn: async () => (await tagsApi.list(workspaceId)).data, enabled: open });
    const segments = useQuery({ queryKey: ['segments', workspaceId], queryFn: async () => (await segmentsApi.list(workspaceId)).data, enabled: open });
    const contactCount = useQuery({
        queryKey: ['contacts-count', workspaceId],
        queryFn: async () => (await contactsApi.list(workspaceId, { per_page: '1' })).data.meta.total,
        enabled: open,
    });

    const filteredTemplates = (templates.data ?? []).filter(
        (template) => !form.whatsapp_account_id || template.whatsapp_account_id === Number(form.whatsapp_account_id),
    );
    const selectedTemplate = filteredTemplates.find((template) => template.id === Number(form.whatsapp_template_id));
    const variableIndexes = useMemo(() => {
        if (!selectedTemplate?.body) return [];
        return [...new Set(Array.from(selectedTemplate.body.matchAll(/\{\{(\d+)\}\}/g), (match) => Number(match[1])))].sort((a, b) => a - b);
    }, [selectedTemplate]);

    const audienceConfig = useMemo(() => {
        if (form.audience_type === 'tag') return form.tag_id ? { tag_id: Number(form.tag_id) } : null;
        if (form.audience_type === 'segment') return form.segment_id ? { segment_id: Number(form.segment_id) } : null;
        return {};
    }, [form.audience_type, form.tag_id, form.segment_id]);

    const previewEnabled = open && Boolean(form.whatsapp_account_id && form.whatsapp_template_id && audienceConfig !== null);
    const preview = useQuery({
        queryKey: ['campaign-preview', workspaceId, form.whatsapp_account_id, form.whatsapp_template_id, form.audience_type, audienceConfig],
        queryFn: async () => (await api.post<CampaignAudiencePreview>(`/api/workspaces/${workspaceId}/campaigns/preview`, {
            whatsapp_account_id: Number(form.whatsapp_account_id),
            whatsapp_template_id: Number(form.whatsapp_template_id),
            audience_type: form.audience_type,
            audience_config: audienceConfig,
        })).data,
        enabled: previewEnabled,
    });

    const create = useMutation({
        mutationFn: async () => {
            const variable_mappings = variableIndexes.map((index) => ({ index, ...(mappings[index] ?? { source: 'contact', value: 'first_name' }) }));
            const response = await campaignsApi.create(workspaceId, {
                name: form.name,
                whatsapp_account_id: Number(form.whatsapp_account_id),
                whatsapp_template_id: Number(form.whatsapp_template_id),
                audience_type: form.audience_type,
                audience_config: audienceConfig,
                variable_mappings,
            });

            if (form.send_mode === 'now') await campaignsApi.schedule(workspaceId, response.data.campaign.id);
            if (form.send_mode === 'schedule') await campaignsApi.schedule(workspaceId, response.data.campaign.id, form.scheduled_at);
            return response;
        },
        onSuccess: () => {
            onCreated();
            onClose();
            setStep(1);
            setForm({ name: '', whatsapp_account_id: '', whatsapp_template_id: '', audience_type: 'all', tag_id: '', segment_id: '', send_mode: 'draft', scheduled_at: '' });
            setMappings({});
            setError(null);
        },
        onError: (e: any) => setError(e.message),
    });

    const messageReady = Boolean(form.name && form.whatsapp_account_id && form.whatsapp_template_id);
    const audienceReady = preview.data && preview.data.eligible > 0;

    return (
        <Modal open={open} onClose={onClose} title={tr('Create WhatsApp campaign', 'إنشاء حملة واتساب')} wide>
            <div className="mb-5 grid grid-cols-3 gap-2">
                {[tr('Message', 'الرسالة'), tr('Audience', 'الجمهور'), tr('Review', 'المراجعة')].map((label, index) => (
                    <div key={label} className={`rounded-lg px-3 py-2 text-center text-xs font-semibold ${step === index + 1 ? 'bg-brand-600 text-white' : step > index + 1 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                        {index + 1}. {label}
                    </div>
                ))}
            </div>

            {step === 1 && (
                <div className="space-y-4">
                    <div><Label>{tr('Campaign name', 'اسم الحملة')}</Label><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder={tr('e.g. August offer', 'مثال: عرض أغسطس')} /></div>
                    <div className="grid gap-3 md:grid-cols-2">
                        <div><Label>{tr('WhatsApp number', 'رقم واتساب')}</Label><Select value={form.whatsapp_account_id} onChange={(e) => setForm({ ...form, whatsapp_account_id: e.target.value, whatsapp_template_id: '' })}><option value="">—</option>{accounts.data?.filter((a) => a.status === 'connected').map((a) => <option key={a.id} value={a.id}>{a.display_phone_number}</option>)}</Select></div>
                        <div><Label>{tr('Approved template', 'القالب المعتمد')}</Label><Select value={form.whatsapp_template_id} onChange={(e) => setForm({ ...form, whatsapp_template_id: e.target.value })}><option value="">—</option>{filteredTemplates.map((template) => <option key={template.id} value={template.id}>{template.name} ({template.language})</option>)}</Select></div>
                    </div>
                    {selectedTemplate && (
                        <div className="rounded-2xl bg-[#efeae2] p-4">
                            <div className="ms-auto max-w-sm rounded-xl rounded-se-sm bg-[#d9fdd3] p-3 shadow-sm">
                                <div className="mb-1 flex items-center justify-between gap-2 text-[10px] text-slate-500"><span>{selectedTemplate.category}</span><span>{selectedTemplate.language}</span></div>
                                <p className="whitespace-pre-wrap text-sm text-slate-800">{selectedTemplate.body}</p>
                                {selectedTemplate.footer && <p className="mt-2 text-xs text-slate-500">{selectedTemplate.footer}</p>}
                            </div>
                        </div>
                    )}
                    {variableIndexes.length > 0 && (
                        <div className="space-y-2 rounded-xl border border-slate-200 p-3">
                            <p className="text-xs font-bold text-slate-600">{tr('Template variables', 'متغيرات القالب')}</p>
                            {variableIndexes.map((index) => {
                                const mapping = mappings[index] ?? { source: 'contact' as const, value: 'first_name' };
                                return <div key={index} className="grid grid-cols-[70px_1fr_1.4fr] items-center gap-2"><code className="text-xs">{`{{${index}}}`}</code><Select value={mapping.source} onChange={(e) => setMappings({ ...mappings, [index]: { source: e.target.value as 'contact' | 'static', value: e.target.value === 'static' ? '' : 'first_name' } })}><option value="contact">{tr('Contact', 'العميل')}</option><option value="static">{tr('Static text', 'نص ثابت')}</option></Select>{mapping.source === 'contact' ? <Select value={mapping.value} onChange={(e) => setMappings({ ...mappings, [index]: { ...mapping, value: e.target.value } })}><option value="first_name">First name</option><option value="full_name">Full name</option><option value="phone_number">Phone</option><option value="email">Email</option></Select> : <Input value={mapping.value} onChange={(e) => setMappings({ ...mappings, [index]: { ...mapping, value: e.target.value } })} />}</div>;
                            })}
                        </div>
                    )}
                </div>
            )}

            {step === 2 && (
                <div className="space-y-4">
                    <div><Label>{tr('Audience', 'الجمهور')}</Label><Select value={form.audience_type} onChange={(e) => setForm({ ...form, audience_type: e.target.value, tag_id: '', segment_id: '' })}><option value="all">{tr(`All contacts (${contactCount.data ?? '…'})`, `كل العملاء (${contactCount.data ?? '…'})`)}</option><option value="tag">{tr('By tag', 'حسب الوسم')}</option><option value="segment">{tr('By segment', 'حسب الشريحة')}</option></Select></div>
                    {form.audience_type === 'tag' && <Select value={form.tag_id} onChange={(e) => setForm({ ...form, tag_id: e.target.value })}><option value="">—</option>{tags.data?.map((tag) => <option key={tag.id} value={tag.id}>{tag.name} ({tag.contacts_count})</option>)}</Select>}
                    {form.audience_type === 'segment' && <Select value={form.segment_id} onChange={(e) => setForm({ ...form, segment_id: e.target.value })}><option value="">—</option>{segments.data?.map((segment) => <option key={segment.id} value={segment.id}>{segment.name} ({segment.contact_count})</option>)}</Select>}

                    {preview.isFetching ? <Spinner /> : preview.data ? (
                        <>
                            <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                                <PreviewCard label={tr('Matched', 'مطابق')} value={preview.data.matching} icon={<Users size={15} />} />
                                <PreviewCard label={tr('Eligible', 'مسموح')} value={preview.data.eligible} icon={<ShieldCheck size={15} />} good />
                                <PreviewCard label={tr('24h window', 'داخل 24 ساعة')} value={preview.data.active_window} icon={<Clock3 size={15} />} />
                                <PreviewCard label={tr('Suppressed', 'مستبعد')} value={preview.data.blocked_opt_out + preview.data.blocked_no_consent} icon={<AlertTriangle size={15} />} warning />
                            </div>
                            {(preview.data.blocked_opt_out + preview.data.blocked_no_consent) > 0 && <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800"><strong>{tr('WhatsApp safety check:', 'فحص أمان واتساب:')}</strong> {tr(`${preview.data.blocked_opt_out} opted out and ${preview.data.blocked_no_consent} have no consent outside the 24-hour window. They will not receive this campaign.`, `${preview.data.blocked_opt_out} ألغوا الاشتراك و${preview.data.blocked_no_consent} لا توجد لهم موافقة خارج نافذة 24 ساعة. لن يتم إرسال الحملة لهم.`)}</div>}
                            {preview.data.sample.length > 0 && <div className="rounded-xl border border-slate-200"><div className="border-b border-slate-100 px-3 py-2 text-xs font-bold text-slate-600">{tr('Sample eligible contacts', 'عينة من العملاء المسموح لهم')}</div>{preview.data.sample.map((contact) => <div key={contact.id} className="flex items-center justify-between border-b border-slate-50 px-3 py-2 text-xs last:border-0"><span className="font-medium text-slate-700">{contact.full_name}</span><span className="text-slate-400">{contact.phone_number}</span></div>)}</div>}
                        </>
                    ) : null}
                </div>
            )}

            {step === 3 && (
                <div className="space-y-4">
                    <div className="grid gap-3 md:grid-cols-2">
                        <div className="rounded-xl border border-slate-200 p-4"><p className="text-xs font-bold text-slate-400 uppercase">{tr('Message', 'الرسالة')}</p><p className="mt-2 font-semibold text-slate-800">{form.name}</p><p className="text-xs text-slate-500">{selectedTemplate?.name} · {selectedTemplate?.language}</p></div>
                        <div className="rounded-xl border border-slate-200 p-4"><p className="text-xs font-bold text-slate-400 uppercase">{tr('Audience', 'الجمهور')}</p><p className="mt-2 text-2xl font-bold text-emerald-700">{preview.data?.eligible ?? 0}</p><p className="text-xs text-slate-500">{tr('eligible recipients', 'عميل مسموح له')}</p></div>
                    </div>
                    <div><Label>{tr('Send option', 'طريقة الإرسال')}</Label><Select value={form.send_mode} onChange={(e) => setForm({ ...form, send_mode: e.target.value })}><option value="draft">{tr('Save as draft', 'حفظ كمسودة')}</option><option value="now">{tr('Send now', 'إرسال الآن')}</option><option value="schedule">{tr('Schedule', 'جدولة')}</option></Select></div>
                    {form.send_mode === 'schedule' && <div><Label>{tr('Schedule time', 'وقت الإرسال')}</Label><Input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })} /></div>}
                    {form.send_mode !== 'draft' && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800"><ShieldCheck size={15} className="mb-1" />{tr('Consent is checked again when the queue actually sends each message. A contact who opts out after scheduling is automatically skipped.', 'يتم فحص الموافقة مرة أخرى لحظة إرسال كل رسالة. أي عميل يلغي الاشتراك بعد الجدولة سيتم استبعاده تلقائيًا.')}</div>}
                </div>
            )}

            {error && <p className="mt-3 rounded-lg bg-red-50 p-2 text-xs text-red-700">{error}</p>}
            <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
                <Button variant="secondary" onClick={() => step === 1 ? onClose() : setStep(step - 1)}>{step === 1 ? t('common.cancel') : t('common.back')}</Button>
                {step < 3 ? <Button onClick={() => setStep(step + 1)} disabled={step === 1 ? !messageReady : !audienceReady}>{t('common.next')}</Button> : <Button loading={create.isPending} disabled={!audienceReady || (form.send_mode === 'schedule' && !form.scheduled_at)} onClick={() => create.mutate()}>{form.send_mode === 'now' ? tr('Create & send', 'إنشاء وإرسال') : form.send_mode === 'schedule' ? tr('Create & schedule', 'إنشاء وجدولة') : tr('Save draft', 'حفظ المسودة')}</Button>}
            </div>
        </Modal>
    );
}

function PreviewCard({ label, value, icon, good = false, warning = false }: { label: string; value: number; icon: React.ReactNode; good?: boolean; warning?: boolean }) {
    return <div className={`rounded-xl border p-3 ${good ? 'border-emerald-200 bg-emerald-50' : warning ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'}`}><div className="flex items-center gap-1.5 text-xs text-slate-500">{icon}{label}</div><p className={`mt-1 text-xl font-bold ${good ? 'text-emerald-700' : warning ? 'text-amber-700' : 'text-slate-800'}`}>{value}</p></div>;
}

function CampaignDetailModal({ campaignId, onClose }: { campaignId: number | null; onClose: () => void }) {
    const workspaceId = useWorkspaceId();
    const { locale, statusLabel } = useI18n();
    const tr = (en: string, ar: string) => (locale === 'ar' ? ar : en);
    const detail = useQuery({
        queryKey: ['campaign-detail', workspaceId, campaignId],
        queryFn: async () => (await api.get<CampaignDetailResponse>(`/api/workspaces/${workspaceId}/campaigns/${campaignId}`)).data,
        enabled: campaignId !== null,
    });
    const recipients = useQuery({
        queryKey: ['campaign-recipients', workspaceId, campaignId],
        queryFn: async () => (await campaignsApi.recipients(workspaceId, campaignId!, 1)).data,
        enabled: campaignId !== null,
    });

    const campaign = detail.data?.campaign;
    const analytics = detail.data?.analytics;

    return (
        <Modal open={campaignId !== null} onClose={onClose} title={campaign?.name ?? tr('Campaign details', 'تفاصيل الحملة')} wide>
            {detail.isLoading ? <Spinner /> : campaign && analytics ? <div className="space-y-5">
                <div className="flex flex-wrap items-center gap-2"><Badge color={statusColor(campaign.status)}>{statusLabel(campaign.status)}</Badge><span className="text-xs text-slate-500">{campaign.template?.name} · {campaign.whatsapp_account?.display_phone_number}</span></div>
                <div className="grid grid-cols-2 gap-2 md:grid-cols-6">
                    <DetailMetric label={tr('Recipients', 'الجمهور')} value={campaign.total_recipients} />
                    <DetailMetric label={tr('Sent', 'مرسل')} value={campaign.sent_count} />
                    <DetailMetric label={tr('Delivered', 'تسليم')} value={campaign.delivered_count} />
                    <DetailMetric label={tr('Read', 'قراءة')} value={campaign.read_count} />
                    <DetailMetric label={tr('Failed', 'فشل')} value={campaign.failed_count} />
                    <DetailMetric label={tr('Suppressed', 'مستبعد')} value={analytics.suppressed_count} />
                </div>
                <div className="grid gap-3 md:grid-cols-2"><RateBar label={tr('Delivery rate', 'معدل التسليم')} value={analytics.delivery_rate} /><RateBar label={tr('Read rate', 'معدل القراءة')} value={analytics.read_rate} /></div>
                <div>
                    <div className="mb-2 flex items-center gap-2"><BarChart3 size={16} className="text-slate-500"/><h3 className="text-sm font-bold text-slate-700">{tr('Recipients & delivery status', 'المستلمون وحالة التسليم')}</h3></div>
                    {recipients.isLoading ? <Spinner /> : <div className="max-h-72 overflow-y-auto rounded-xl border border-slate-200">{(recipients.data?.items as CampaignRecipient[] | undefined)?.slice(0, 50).map((recipient) => {
                        const fallbackName = [recipient.contact?.first_name, recipient.contact?.last_name].filter(Boolean).join(' ');
                        const name = recipient.contact?.full_name || recipient.contact?.display_name || fallbackName || recipient.contact?.phone_number || '—';
                        return <div key={recipient.id} className="flex items-start justify-between gap-3 border-b border-slate-100 px-3 py-2.5 text-xs last:border-0"><div><p className="font-semibold text-slate-700">{name}</p><p className="text-slate-400">{recipient.contact?.phone_number}</p>{recipient.error_message && <p className="mt-1 text-amber-700">{recipient.error_message}</p>}</div><Badge color={statusColor(recipient.message?.status ?? recipient.status)}>{statusLabel(recipient.message?.status ?? recipient.status)}</Badge></div>;
                    })}</div>}
                </div>
            </div> : detail.isError ? <QueryError onRetry={() => detail.refetch()} /> : null}
        </Modal>
    );
}

function DetailMetric({ label, value }: { label: string; value: number }) {
    return <div className="rounded-xl bg-slate-50 p-3 text-center"><p className="text-xl font-bold text-slate-900">{value}</p><p className="text-[10px] font-semibold text-slate-500">{label}</p></div>;
}

function RateBar({ label, value }: { label: string; value: number }) {
    return <div className="rounded-xl border border-slate-200 p-3"><div className="mb-2 flex justify-between text-xs"><span className="font-semibold text-slate-600">{label}</span><strong className="text-slate-800">{value}%</strong></div><div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-brand-600" style={{ width: `${Math.min(100, value)}%` }} /></div></div>;
}
