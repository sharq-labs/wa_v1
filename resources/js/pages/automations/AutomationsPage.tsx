import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bot, Pause, Play, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { automationsApi } from '@/api';
import { Badge, Button, EmptyState, Input, Label, Modal, PageHeader, QueryError, Select, Spinner, statusColor } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import { confirmDialog } from '@/stores/confirmStore';
import type { Automation } from '@/types';

export default function AutomationsPage() {
    const { t, statusLabel } = useI18n();
    const workspaceId = useWorkspaceId();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [createOpen, setCreateOpen] = useState(false);
    const [name, setName] = useState('');
    const [priority, setPriority] = useState(0);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');

    const query = useQuery({
        queryKey: ['automations', workspaceId],
        queryFn: async () => (await automationsApi.list(workspaceId)).data,
    });

    const create = useMutation({
        mutationFn: () => automationsApi.create(workspaceId, { name, priority }),
        onSuccess: (response) => {
            setCreateOpen(false);
            setName('');
            navigate(`/automations/${response.data.id}`);
        },
    });

    const remove = useMutation({
        mutationFn: (id: number) => automationsApi.remove(workspaceId, id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['automations', workspaceId] }),
    });

    const toggleStatus = useMutation({
        mutationFn: ({ id, status }: { id: number; status: string }) => automationsApi.setStatus(workspaceId, id, status),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['automations', workspaceId] }),
    });

    const confirmRemove = async (automation: Automation) => {
        const confirmed = await confirmDialog({
            title: t('common.delete_named', { name: automation.name }),
            description: t('common.irreversible'),
            confirmLabel: t('common.delete'),
            destructive: true,
        });
        if (confirmed) remove.mutate(automation.id);
    };

    const filtered = useMemo(() => {
        const items = query.data ?? [];
        const q = search.trim().toLowerCase();
        return items.filter((automation) => {
            if (statusFilter && automation.status !== statusFilter) return false;
            if (q && !automation.name.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [query.data, search, statusFilter]);

    return (
        <div className="p-4 md:p-6 lg:p-8">
            <PageHeader
                title={t('automations.title')}
                subtitle={t('automations.subtitle')}
                actions={
                    <Button onClick={() => setCreateOpen(true)}>
                        <Plus size={15} /> {t('automations.new')}
                    </Button>
                }
            />

            {(query.data ?? []).length > 0 && (
                <div className="mb-4 flex flex-wrap gap-2">
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('automations.search')}
                        className="!w-64"
                    />
                    <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="!w-44">
                        <option value="">{t('automations.all_statuses')}</option>
                        <option value="draft">{statusLabel('draft')}</option>
                        <option value="published">{statusLabel('published')}</option>
                        <option value="paused">{statusLabel('paused')}</option>
                    </Select>
                </div>
            )}

            {query.isLoading ? (
                <Spinner />
            ) : query.isError ? (
                <QueryError onRetry={() => query.refetch()} />
            ) : (query.data ?? []).length === 0 ? (
                <EmptyState
                    icon={<Bot size={22} />}
                    title={t('automations.empty')}
                    action={<Button onClick={() => setCreateOpen(true)}>{t('automations.new')}</Button>}
                />
            ) : filtered.length === 0 ? (
                <EmptyState icon={<Bot size={22} />} title={t('common.empty')} />
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-card">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th className="px-4 py-3 text-start">{t('common.name')}</th>
                                <th className="px-3 text-start">{t('common.status')}</th>
                                <th className="px-3 text-end">{t('automations.priority')}</th>
                                <th className="px-3 text-end">{t('automations.runs')}</th>
                                <th className="px-3 text-end">{t('automations.active')}</th>
                                <th className="px-3 text-end">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.map((automation) => (
                                <tr key={automation.id} className="border-t border-slate-100 hover:bg-slate-50">
                                    <td className="px-4 py-3">
                                        <Link
                                            to={`/automations/${automation.id}`}
                                            className="flex items-center gap-2 font-medium text-slate-800 hover:text-brand-700"
                                        >
                                            <Bot size={16} className="shrink-0 text-brand-600" />
                                            {automation.name}
                                        </Link>
                                    </td>
                                    <td className="px-3">
                                        <Badge color={statusColor(automation.status)}>{statusLabel(automation.status)}</Badge>
                                    </td>
                                    <td className="px-3 text-end tabular-nums">{automation.priority}</td>
                                    <td className="px-3 text-end">
                                        <Link
                                            to={`/automations/${automation.id}/runs`}
                                            className="font-medium text-brand-600 hover:underline"
                                        >
                                            {automation.runs_count ?? 0}
                                        </Link>
                                    </td>
                                    <td className="px-3 text-end tabular-nums">{automation.active_runs_count ?? 0}</td>
                                    <td className="px-3 text-end">
                                        <div className="flex items-center justify-end gap-1">
                                            {automation.status === 'published' ? (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => toggleStatus.mutate({ id: automation.id, status: 'paused' })}
                                                >
                                                    <Pause size={14} /> {t('common.pause')}
                                                </Button>
                                            ) : automation.published_version_id ? (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        toggleStatus.mutate({ id: automation.id, status: 'published' })
                                                    }
                                                >
                                                    <Play size={14} /> {t('automations.resume')}
                                                </Button>
                                            ) : null}
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                aria-label={`${t('common.delete')} ${automation.name}`}
                                                onClick={() => void confirmRemove(automation)}
                                            >
                                                <Trash2 size={14} className="text-red-500" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <Modal open={createOpen} onClose={() => setCreateOpen(false)} title={t('automations.new')}>
                <div className="space-y-3">
                    <div>
                        <Label>{t('common.name')}</Label>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Lead Qualification Bot" />
                    </div>
                    <div>
                        <Label>
                            {t('automations.priority')} — {t('automations.priority_hint')}
                        </Label>
                        <Input type="number" value={priority} onChange={(e) => setPriority(Number(e.target.value))} />
                    </div>
                    <Button onClick={() => create.mutate()} disabled={!name.trim() || create.isPending} className="w-full">
                        {t('common.create')}
                    </Button>
                </div>
            </Modal>
        </div>
    );
}
