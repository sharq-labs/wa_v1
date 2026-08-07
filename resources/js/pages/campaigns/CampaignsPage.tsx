import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Megaphone, Plus } from 'lucide-react';
import { useState } from 'react';
import { campaignsApi, contactsApi, segmentsApi, tagsApi, templatesApi, whatsappApi } from '@/api';
import { Badge, Button, EmptyState, Input, Label, Modal, PageHeader, QueryError, Select, Spinner, statusColor } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import { confirmDialog } from '@/stores/confirmStore';
import type { Campaign } from '@/types';

export default function CampaignsPage() {
    const { t, statusLabel } = useI18n();
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const [createOpen, setCreateOpen] = useState(false);
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

    // A blast cannot be recalled once it starts, so it never fires on a single click.
    const confirmSendNow = async (campaign: Campaign) => {
        const confirmed = await confirmDialog({
            title: t('campaigns.send_now_title', { name: campaign.name }),
            description: campaign.total_recipients
                ? t('campaigns.send_now_desc', { count: campaign.total_recipients })
                : t('campaigns.send_now_desc_unknown'),
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
                subtitle={t('campaigns.subtitle')}
                actions={
                    <Button onClick={() => setCreateOpen(true)}>
                        <Plus size={15} /> {t('campaigns.new')}
                    </Button>
                }
            />

            {campaigns.isLoading ? (
                <Spinner />
            ) : campaigns.isError ? (
                <QueryError onRetry={() => campaigns.refetch()} />
            ) : (campaigns.data?.items ?? []).length === 0 ? (
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
                                <th className="px-3 text-end">{t('dashboard.recipients')}</th>
                                <th className="px-3 text-end">{t('dashboard.sent')}</th>
                                <th className="px-3 text-end">{t('dashboard.failed')}</th>
                                <th className="px-3 text-end">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {campaigns.data!.items.map((campaign) => (
                                <tr key={campaign.id} className="border-t border-slate-100">
                                    <td className="px-4 py-2.5 font-medium text-slate-800">{campaign.name}</td>
                                    <td className="px-3 font-mono text-xs">{campaign.template?.name}</td>
                                    <td className="px-3">
                                        <Badge color={statusColor(campaign.status)}>{statusLabel(campaign.status)}</Badge>
                                    </td>
                                    <td className="px-3 text-end">{campaign.total_recipients}</td>
                                    <td className="px-3 text-end">{campaign.sent_count}</td>
                                    <td className="px-3 text-end">{campaign.failed_count}</td>
                                    <td className="px-3 text-end">
                                        <div className="flex justify-end gap-1">
                                            {campaign.status === 'draft' && (
                                                <Button size="sm" onClick={() => void confirmSendNow(campaign)}>
                                                    {t('campaigns.send_now')}
                                                </Button>
                                            )}
                                            {campaign.status === 'processing' && (
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    loading={pause.isPending && pause.variables === campaign.id}
                                                    onClick={() => pause.mutate(campaign.id)}
                                                >
                                                    {t('common.pause')}
                                                </Button>
                                            )}
                                            {campaign.status === 'paused' && (
                                                <Button
                                                    size="sm"
                                                    loading={resume.isPending && resume.variables === campaign.id}
                                                    onClick={() => resume.mutate(campaign.id)}
                                                >
                                                    {t('campaigns.resume')}
                                                </Button>
                                            )}
                                            {['draft', 'scheduled', 'processing', 'paused'].includes(campaign.status) && (
                                                <Button size="sm" variant="ghost" onClick={() => void confirmCancel(campaign)}>
                                                    {t('common.cancel')}
                                                </Button>
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
        </div>
    );
}

function CreateCampaignModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
    const workspaceId = useWorkspaceId();
    const { t } = useI18n();
    const [form, setForm] = useState({
        name: '',
        whatsapp_account_id: '',
        whatsapp_template_id: '',
        audience_type: 'all',
        tag_id: '',
        segment_id: '',
        scheduled_at: '',
    });
    const [error, setError] = useState<string | null>(null);

    const accounts = useQuery({
        queryKey: ['wa-accounts', workspaceId],
        queryFn: async () => (await whatsappApi.accounts(workspaceId)).data,
        enabled: open,
    });
    const templates = useQuery({
        queryKey: ['templates', workspaceId, 'approved'],
        queryFn: async () => (await templatesApi.list(workspaceId, { status: 'approved' })).data,
        enabled: open,
    });
    const tags = useQuery({
        queryKey: ['tags', workspaceId],
        queryFn: async () => (await tagsApi.list(workspaceId)).data,
        enabled: open,
    });
    const segments = useQuery({
        queryKey: ['segments', workspaceId],
        queryFn: async () => (await segmentsApi.list(workspaceId)).data,
        enabled: open,
    });
    const contactCount = useQuery({
        queryKey: ['contacts-count', workspaceId],
        queryFn: async () => (await contactsApi.list(workspaceId, { per_page: '1' })).data.meta.total,
        enabled: open,
    });

    const create = useMutation({
        mutationFn: async () => {
            const audience_config =
                form.audience_type === 'tag'
                    ? { tag_id: Number(form.tag_id) }
                    : form.audience_type === 'segment'
                      ? { segment_id: Number(form.segment_id) }
                      : null;

            const response = await campaignsApi.create(workspaceId, {
                name: form.name,
                whatsapp_account_id: Number(form.whatsapp_account_id),
                whatsapp_template_id: Number(form.whatsapp_template_id),
                audience_type: form.audience_type,
                audience_config,
            });

            if (form.scheduled_at) {
                await campaignsApi.schedule(workspaceId, response.data.campaign.id, form.scheduled_at);
            }

            return response;
        },
        onSuccess: () => {
            onCreated();
            onClose();
            setError(null);
        },
        onError: (e: any) => setError(e.message),
    });

    return (
        <Modal open={open} onClose={onClose} title={t('campaigns.new')} wide>
            <div className="space-y-3">
                <div>
                    <Label>{t('common.name')}</Label>
                    <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <div>
                        <Label>WhatsApp number</Label>
                        <Select
                            value={form.whatsapp_account_id}
                            onChange={(e) => setForm({ ...form, whatsapp_account_id: e.target.value })}
                        >
                            <option value="">—</option>
                            {accounts.data?.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.display_phone_number}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div>
                        <Label>Approved template</Label>
                        <Select
                            value={form.whatsapp_template_id}
                            onChange={(e) => setForm({ ...form, whatsapp_template_id: e.target.value })}
                        >
                            <option value="">—</option>
                            {templates.data?.map((template) => (
                                <option key={template.id} value={template.id}>
                                    {template.name} ({template.language})
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
                <div>
                    <Label>{t('campaigns.audience')}</Label>
                    <Select value={form.audience_type} onChange={(e) => setForm({ ...form, audience_type: e.target.value })}>
                        <option value="all">All contacts ({contactCount.data ?? '…'})</option>
                        <option value="tag">By tag</option>
                        <option value="segment">By segment</option>
                    </Select>
                </div>
                {form.audience_type === 'tag' && (
                    <Select value={form.tag_id} onChange={(e) => setForm({ ...form, tag_id: e.target.value })}>
                        <option value="">—</option>
                        {tags.data?.map((tag) => (
                            <option key={tag.id} value={tag.id}>
                                {tag.name} ({tag.contacts_count})
                            </option>
                        ))}
                    </Select>
                )}
                {form.audience_type === 'segment' && (
                    <Select value={form.segment_id} onChange={(e) => setForm({ ...form, segment_id: e.target.value })}>
                        <option value="">—</option>
                        {segments.data?.map((segment) => (
                            <option key={segment.id} value={segment.id}>
                                {segment.name} ({segment.contact_count})
                            </option>
                        ))}
                    </Select>
                )}
                <div>
                    <Label>{t('campaigns.schedule')} (empty = save as draft)</Label>
                    <Input
                        type="datetime-local"
                        value={form.scheduled_at}
                        onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })}
                    />
                </div>
                {error && <p className="text-xs text-red-600">{error}</p>}
                <Button
                    onClick={() => create.mutate()}
                    disabled={!form.name || !form.whatsapp_account_id || !form.whatsapp_template_id || create.isPending}
                    className="w-full"
                >
                    {t('common.create')}
                </Button>
            </div>
        </Modal>
    );
}
