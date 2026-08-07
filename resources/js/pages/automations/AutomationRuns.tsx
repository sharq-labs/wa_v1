import { useQuery } from '@tanstack/react-query';
import { format } from 'date-fns';
import { ArrowLeft, History } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { automationsApi } from '@/api';
import { Badge, Button, EmptyState, Modal, PageHeader, QueryError, Spinner, statusColor } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import { nodeLabelKey } from './nodeI18n';

export default function AutomationRuns() {
    const { automationId } = useParams();
    const id = Number(automationId);
    const { t, statusLabel, dateLocale } = useI18n();
    const workspaceId = useWorkspaceId();
    const [page, setPage] = useState(1);
    const [openRun, setOpenRun] = useState<string | null>(null);

    const runs = useQuery({
        queryKey: ['automation-runs', workspaceId, id, page],
        queryFn: async () => (await automationsApi.runs(workspaceId, id, page)).data,
    });

    const detail = useQuery({
        queryKey: ['automation-run', workspaceId, id, openRun],
        queryFn: async () => (await automationsApi.runDetail(workspaceId, id, openRun!)).data,
        enabled: !!openRun,
    });

    const items = runs.data?.items ?? [];

    return (
        <div className="p-4 md:p-6 lg:p-8">
            <PageHeader
                title={t('automations.runs_title')}
                actions={
                    <div className="flex items-center gap-2">
                        <Link to={`/automations/${id}`}>
                            <Button variant="secondary" size="sm">
                                {t('automations.open_builder')}
                            </Button>
                        </Link>
                        <Link
                            to="/automations"
                            className="flex items-center gap-1 text-sm text-slate-500 transition-colors hover:text-slate-800"
                        >
                            <ArrowLeft size={15} className="rtl:rotate-180" /> {t('common.back')}
                        </Link>
                    </div>
                }
            />

            {runs.isLoading ? (
                <Spinner />
            ) : runs.isError ? (
                <QueryError onRetry={() => runs.refetch()} />
            ) : items.length === 0 ? (
                <EmptyState icon={<History size={22} />} title={t('automations.runs_empty')} />
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-card">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5 text-start">{t('automations.contact')}</th>
                                <th className="px-3 text-start">{t('common.status')}</th>
                                <th className="px-3 text-end">{t('automations.steps')}</th>
                                <th className="px-3 text-start">{t('automations.version')}</th>
                                <th className="px-3 text-start">{t('automations.started')}</th>
                                <th className="px-3 text-start">{t('automations.error')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((run) => (
                                <tr
                                    key={run.uuid}
                                    className="cursor-pointer border-t border-slate-100 hover:bg-slate-50"
                                    onClick={() => setOpenRun(run.uuid)}
                                >
                                    <td className="px-4 py-2.5">
                                        {(run.contact as any)?.display_name ??
                                            (run.contact as any)?.first_name ??
                                            (run.contact as any)?.phone_number ??
                                            '—'}
                                    </td>
                                    <td className="px-3">
                                        <Badge color={statusColor(run.status)}>{statusLabel(run.status)}</Badge>
                                    </td>
                                    <td className="px-3 text-end">{run.steps_executed}</td>
                                    <td className="px-3">v{run.version?.version}</td>
                                    <td className="px-3 text-xs text-slate-500">
                                        {run.started_at &&
                                            format(new Date(run.started_at), 'MMM d HH:mm:ss', { locale: dateLocale })}
                                    </td>
                                    <td className="max-w-48 truncate px-3 text-xs text-red-600">{run.error}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {runs.data && runs.data.meta.last_page > 1 && (
                        <div className="flex justify-between border-t border-slate-100 px-4 py-2 text-xs">
                            <button
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                                className="text-brand-600 disabled:text-slate-300"
                            >
                                {t('common.prev')}
                            </button>
                            <span className="text-slate-500">
                                {runs.data.meta.current_page} / {runs.data.meta.last_page}
                            </span>
                            <button
                                disabled={page >= runs.data.meta.last_page}
                                onClick={() => setPage(page + 1)}
                                className="text-brand-600 disabled:text-slate-300"
                            >
                                {t('common.next')}
                            </button>
                        </div>
                    )}
                </div>
            )}

            <Modal open={!!openRun} onClose={() => setOpenRun(null)} title={t('automations.timeline')} wide>
                {detail.isLoading ? (
                    <Spinner />
                ) : (
                    <div className="space-y-3">
                        <div className="flex flex-wrap gap-2 text-xs">
                            <Badge color={statusColor(detail.data?.status ?? '')}>
                                {statusLabel(detail.data?.status ?? '')}
                            </Badge>
                            <span className="text-slate-500">
                                {t('automations.contact')}: {(detail.data?.contact as any)?.phone_number}
                            </span>
                            <span className="text-slate-500">
                                {t('automations.version')}: v{detail.data?.version?.version}
                            </span>
                        </div>
                        {detail.data?.error && (
                            <p className="rounded-lg bg-red-50 p-2 text-xs text-red-700">{detail.data.error}</p>
                        )}
                        <div className="space-y-1.5">
                            {detail.data?.steps?.map((step) => (
                                <div key={step.id} className="rounded-lg border border-slate-200 p-2 text-xs">
                                    <div className="flex items-center justify-between">
                                        <span className="font-semibold text-slate-700">
                                            {t(nodeLabelKey(step.node_type)) || step.node_type}
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <Badge
                                                color={statusColor(step.status === 'executed' ? 'completed' : step.status)}
                                            >
                                                {statusLabel(step.status === 'executed' ? 'completed' : step.status)}
                                            </Badge>
                                            <span className="text-slate-400">
                                                {format(new Date(step.created_at), 'HH:mm:ss', { locale: dateLocale })}
                                            </span>
                                        </span>
                                    </div>
                                    {step.output && (
                                        <details className="mt-1">
                                            <summary className="cursor-pointer text-[11px] text-slate-500">
                                                {t('common.details')}
                                            </summary>
                                            <pre className="mt-1 overflow-x-auto rounded bg-slate-50 p-1.5 text-[10px] text-slate-600">
                                                {JSON.stringify(step.output, null, 1)}
                                            </pre>
                                        </details>
                                    )}
                                    {step.error && <p className="mt-1 text-red-600">{step.error}</p>}
                                </div>
                            ))}
                        </div>
                        {(detail.data?.variables ?? []).length > 0 && (
                            <div>
                                <p className="mb-1 text-xs font-semibold text-slate-600">{t('automations.variables')}</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {detail.data!.variables!.map((variable) => (
                                        <span key={variable.key} className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px]">
                                            {variable.key} = {variable.value}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </Modal>
        </div>
    );
}
