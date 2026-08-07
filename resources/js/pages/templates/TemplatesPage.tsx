import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LayoutTemplate, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { templatesApi, whatsappApi } from '@/api';
import { Badge, Button, EmptyState, PageHeader, QueryError, Spinner, statusColor } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import { confirmDialog } from '@/stores/confirmStore';
import type { WhatsAppTemplate } from '@/types';
import TemplateWizard from './TemplateWizard';

export default function TemplatesPage() {
    const { t, statusLabel } = useI18n();
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const [wizardOpen, setWizardOpen] = useState(false);

    const templates = useQuery({
        queryKey: ['templates', workspaceId, 'all'],
        queryFn: async () => (await templatesApi.list(workspaceId)).data,
    });

    const accounts = useQuery({
        queryKey: ['wa-accounts', workspaceId],
        queryFn: async () => (await whatsappApi.accounts(workspaceId)).data,
    });

    const sync = useMutation({
        mutationFn: () => templatesApi.sync(workspaceId),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['templates', workspaceId] }),
    });

    const remove = useMutation({
        mutationFn: (id: number) => templatesApi.remove(workspaceId, id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['templates', workspaceId] }),
    });

    const confirmRemove = async (template: WhatsAppTemplate) => {
        const confirmed = await confirmDialog({
            title: t('common.delete_named', { name: template.name }),
            description: t('common.irreversible'),
            confirmLabel: t('common.delete'),
            destructive: true,
        });
        if (confirmed) remove.mutate(template.id);
    };

    return (
        <div className="p-4 md:p-6 lg:p-8">
            <PageHeader
                title={t('templates.title')}
                subtitle={t('templates.subtitle')}
                actions={
                    <>
                        <Button variant="secondary" onClick={() => sync.mutate()} disabled={sync.isPending}>
                            <RefreshCw size={15} className={sync.isPending ? 'animate-spin' : ''} /> {t('templates.sync')}
                        </Button>
                        <Button onClick={() => setWizardOpen(true)} disabled={(accounts.data ?? []).length === 0}>
                            <Plus size={15} /> {t('templates.new')}
                        </Button>
                    </>
                }
            />

            {templates.isLoading ? (
                <Spinner />
            ) : templates.isError ? (
                <QueryError onRetry={() => templates.refetch()} />
            ) : (templates.data ?? []).length === 0 ? (
                <EmptyState
                    icon={<LayoutTemplate size={22} />}
                    title={t('templates.empty')}
                    action={
                        (accounts.data ?? []).length > 0 ? (
                            <Button onClick={() => setWizardOpen(true)}>{t('templates.new')}</Button>
                        ) : (
                            <p className="text-xs text-slate-400">{t('templates.connect_first')}</p>
                        )
                    }
                />
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-card">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5 text-start">{t('common.name')}</th>
                                <th className="px-3 text-start">{t('templates.language')}</th>
                                <th className="px-3 text-start">{t('templates.category')}</th>
                                <th className="px-3 text-start">{t('common.status')}</th>
                                <th className="px-3 text-start">{t('templates.number')}</th>
                                <th className="px-3 text-end">{t('templates.usage')}</th>
                                <th className="px-3 text-start">{t('templates.last_sync')}</th>
                                <th className="px-3 text-end">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {templates.data!.map((template) => (
                                <tr key={template.id} className="border-t border-slate-100 hover:bg-slate-50">
                                    <td className="px-4 py-2.5">
                                        <p className="font-mono text-xs font-medium text-slate-800">{template.name}</p>
                                        <p className="line-clamp-1 max-w-72 text-[11px] text-slate-400">{template.body}</p>
                                    </td>
                                    <td className="px-3">{template.language}</td>
                                    <td className="px-3 text-xs">{template.category}</td>
                                    <td className="px-3">
                                        <Badge color={statusColor(template.status)}>{statusLabel(template.status)}</Badge>
                                        {template.rejection_reason && (
                                            <p className="mt-0.5 max-w-40 truncate text-[10px] text-red-500">{template.rejection_reason}</p>
                                        )}
                                    </td>
                                    <td className="px-3 text-xs text-slate-500">
                                        {template.whatsapp_account?.display_phone_number}
                                    </td>
                                    <td className="px-3 text-end">{template.usage_count}</td>
                                    <td className="px-3 text-xs text-slate-500">
                                        {template.last_synced_at ? new Date(template.last_synced_at).toLocaleDateString() : '—'}
                                    </td>
                                    <td className="px-3 text-end">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label={`${t('common.delete')} ${template.name}`}
                                            onClick={() => void confirmRemove(template)}
                                        >
                                            <Trash2 size={13} className="text-red-500" />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <TemplateWizard
                open={wizardOpen}
                onClose={() => setWizardOpen(false)}
                accounts={accounts.data ?? []}
                onCreated={() => queryClient.invalidateQueries({ queryKey: ['templates', workspaceId] })}
            />
        </div>
    );
}
