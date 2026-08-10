import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { adminApi } from '@/api';
import { Badge, EmptyState, Pagination, QueryError, Skeleton, TableSkeleton, statusColor } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { useI18n } from '@/lib/i18n';
import { clsx } from 'clsx';
import type { ReactNode } from 'react';

export default function AdminPage() {
    const { t } = useI18n();
    const tabs = [
        { to: 'overview', label: t('admin.overview') },
        { to: 'health', label: t('admin.health') },
        { to: 'users', label: t('admin.users') },
        { to: 'workspaces', label: t('admin.workspaces') },
        { to: 'webhooks', label: t('admin.webhooks') },
        { to: 'errors', label: t('admin.errors') },
        { to: 'plans', label: t('admin.plans') },
    ];

    return (
        <div className="flex h-full min-h-0 flex-col md:flex-row">
            <div className="flex w-full shrink-0 gap-1 overflow-x-auto border-b border-slate-200 bg-white p-2 md:w-60 md:flex-col md:gap-1 md:border-b-0 md:border-e md:p-5">
                <h2 className="mb-3 hidden px-3 text-base font-bold text-slate-900 md:block">{t('admin.title')}</h2>
                {tabs.map((tab) => (
                    <NavLink key={tab.to} to={`/admin/${tab.to}`} className={({ isActive }) => clsx('block shrink-0 whitespace-nowrap rounded-xl px-3.5 py-2.5 text-[15px] transition-colors', isActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50')}>
                        {tab.label}
                    </NavLink>
                ))}
            </div>
            <div className="min-w-0 flex-1 overflow-y-auto p-5 md:p-8 lg:p-10">
                <Routes>
                    <Route index element={<Navigate to="/admin/overview" replace />} />
                    <Route path="overview" element={<Overview />} />
                    <Route path="health" element={<Health />} />
                    <Route path="users" element={<UsersTab />} />
                    <Route path="workspaces" element={<WorkspacesTab />} />
                    <Route path="webhooks" element={<WebhooksTab />} />
                    <Route path="errors" element={<ErrorsTab />} />
                    <Route path="plans" element={<PlansTab />} />
                </Routes>
            </div>
        </div>
    );
}

function AdminError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
    const { t } = useI18n();
    if (error instanceof ApiError && error.status === 403) return <QueryError message={t('admin.no_access')} />;
    return <QueryError message={t('admin.load_failed')} onRetry={onRetry} />;
}

function CardGridSkeleton({ count = 6, className }: { count?: number; className?: string }) {
    return (
        <div className={clsx('grid w-full gap-3', className ?? 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6')}>
            {Array.from({ length: count }).map((_, i) => <div key={i} className="rounded-xl border border-slate-200 bg-white p-4 shadow-card"><Skeleton className="h-3 w-20" /><Skeleton className="mt-2.5 h-7 w-14" /></div>)}
        </div>
    );
}

function Overview() {
    const { t } = useI18n();
    const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['admin-overview'], queryFn: async () => (await adminApi.overview()).data });
    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading || !data) return <CardGridSkeleton count={8} />;
    return (
        <div className="grid w-full grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6">
            {Object.entries(data).map(([key, value]) => {
                const translationKey = `admin.metric.${key}`;
                const label = t(translationKey);
                return <div key={key} className="rounded-xl border border-slate-200 bg-white p-4 shadow-card"><p className="text-xs text-slate-500">{label === translationKey ? key.replaceAll('_', ' ') : label}</p><p className="mt-1 text-2xl font-semibold tabular-nums">{value as number}</p></div>;
            })}
        </div>
    );
}

function Health() {
    const { t } = useI18n();
    const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['admin-health'], queryFn: async () => (await adminApi.health()).data, refetchInterval: 15_000 });
    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading || !data) return <CardGridSkeleton count={6} className="grid-cols-2 lg:grid-cols-4" />;
    const stats = [
        [t('admin.failed_jobs'), data.failed_jobs],
        [t('admin.webhook_failures_24h'), data.webhook_failures_24h],
        [t('admin.message_failures_24h'), data.message_failures_24h],
        [t('admin.automation_errors_24h'), data.automation_errors_24h],
    ];
    return (
        <div className="w-full space-y-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {[[t('admin.database'), data.database], [t('admin.redis'), data.redis]].map(([label, healthy]) => (
                    <div key={label as string} className="rounded-xl border border-slate-200 bg-white p-4 shadow-card"><p className="text-xs text-slate-500">{label as string}</p><div className="mt-2"><Badge color={healthy ? 'green' : 'red'}>{healthy ? t('admin.ok') : t('admin.down')}</Badge></div></div>
                ))}
                {stats.map(([label, value]) => <div key={label as string} className="rounded-xl border border-slate-200 bg-white p-4 shadow-card"><p className="text-xs text-slate-500">{label}</p><p className={clsx('mt-1 text-2xl font-semibold tabular-nums', (value as number) > 0 ? 'text-red-600' : '')}>{value as number}</p></div>)}
            </div>
            {data.queues && <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-card"><p className="mb-3 text-sm font-semibold text-slate-700">{t('admin.queue_depth')}</p><div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">{Object.entries(data.queues).map(([queue, depth]) => <div key={queue} className="rounded-lg bg-slate-50 p-3 text-xs"><p className="text-slate-500">{queue}</p><p className="mt-1 text-lg font-semibold tabular-nums">{depth as number}</p></div>)}</div></div>}
        </div>
    );
}

function UsersTab() {
    const { t, locale } = useI18n();
    const [page, setPage] = useState(1);
    const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['admin-users', page], queryFn: async () => (await adminApi.users(page)).data });
    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading) return <TableSkeleton rows={8} columns={4} />;
    if (!data?.items.length) return <EmptyState title={t('admin.no_users')} description={t('admin.no_users_desc')} />;
    return <SimpleTable headers={[t('common.name'), t('common.email'), t('admin.workspaces'), t('admin.joined')]} rows={data.items.map((user: any) => [user.name, user.email, user.workspaces_count, new Date(user.created_at).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US')])} footer={<Pagination page={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />} />;
}

function WorkspacesTab() {
    const { t } = useI18n();
    const [page, setPage] = useState(1);
    const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['admin-workspaces', page], queryFn: async () => (await adminApi.workspaces(page)).data });
    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading) return <TableSkeleton rows={8} columns={6} />;
    if (!data?.items.length) return <EmptyState title={t('admin.no_workspaces')} description={t('admin.no_workspaces_desc')} />;
    return <SimpleTable headers={[t('common.name'), t('common.owner'), t('admin.plan'), t('admin.users'), t('admin.contacts'), t('admin.automations')]} rows={data.items.map((workspace: any) => [workspace.name, workspace.owner?.email, workspace.subscription?.plan?.name ?? '—', workspace.users_count, workspace.contacts_count, workspace.automations_count])} footer={<Pagination page={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />} />;
}

function WebhooksTab() {
    const { t, statusLabel, locale } = useI18n();
    const [page, setPage] = useState(1);
    const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['admin-webhooks', page], queryFn: async () => (await adminApi.webhookEvents(page)).data });
    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading) return <TableSkeleton rows={8} columns={5} />;
    if (!data?.items.length) return <EmptyState title={t('admin.no_webhooks')} description={t('admin.no_webhooks_desc')} />;
    return <SimpleTable headers={[t('common.type'), t('common.status'), t('admin.attempts'), t('admin.error'), t('admin.received')]} rows={data.items.map((event: any) => [event.event_type, <Badge key="s" color={statusColor(event.status === 'processed' ? 'completed' : event.status)}>{statusLabel(event.status === 'processed' ? 'completed' : event.status)}</Badge>, event.attempts, <span key="e" className="text-xs text-red-600">{event.error_message?.slice(0, 60)}</span>, new Date(event.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')])} footer={<Pagination page={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />} />;
}

function ErrorsTab() {
    const { t, locale } = useI18n();
    const [runsPage, setRunsPage] = useState(1);
    const failedRuns = useQuery({ queryKey: ['admin-failed-runs', runsPage], queryFn: async () => (await adminApi.failedRuns(runsPage)).data });
    const failedJobs = useQuery({ queryKey: ['admin-failed-jobs'], queryFn: async () => (await adminApi.failedJobs()).data });
    return (
        <div className="space-y-6">
            <div><h3 className="mb-2 text-sm font-semibold text-slate-700">{t('admin.failed_runs')}</h3>{failedRuns.isError ? <AdminError error={failedRuns.error} onRetry={() => failedRuns.refetch()} /> : failedRuns.isLoading ? <TableSkeleton rows={5} columns={4} /> : !failedRuns.data?.items.length ? <EmptyState title={t('admin.no_failed_runs')} description={t('admin.no_failed_runs_desc')} /> : <SimpleTable headers={[t('admin.automation'), t('admin.workspaces'), t('admin.error'), t('admin.when')]} rows={failedRuns.data.items.map((run: any) => [run.automation?.name, run.workspace?.name, <span key="e" className="text-xs text-red-600">{run.error?.slice(0, 80)}</span>, new Date(run.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')])} footer={<Pagination page={failedRuns.data.meta.current_page} lastPage={failedRuns.data.meta.last_page} total={failedRuns.data.meta.total} onChange={setRunsPage} />} />}</div>
            <div><h3 className="mb-2 text-sm font-semibold text-slate-700">{t('admin.failed_jobs_latest')}</h3>{failedJobs.isError ? <AdminError error={failedJobs.error} onRetry={() => failedJobs.refetch()} /> : failedJobs.isLoading ? <TableSkeleton rows={5} columns={3} /> : !failedJobs.data?.length ? <EmptyState title={t('admin.no_failed_jobs')} description={t('admin.no_failed_jobs_desc')} /> : <SimpleTable headers={[t('admin.queue'), t('admin.exception'), t('admin.failed_at')]} rows={failedJobs.data.map((job: any) => [job.queue, <span key="e" className="text-xs text-red-600">{job.exception?.slice(0, 100)}</span>, job.failed_at])} />}</div>
        </div>
    );
}

function PlansTab() {
    const { t } = useI18n();
    const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['admin-plans'], queryFn: async () => (await adminApi.plans()).data });
    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading) return <CardGridSkeleton count={3} className="gap-4 sm:grid-cols-2 xl:grid-cols-3" />;
    if (!data?.length) return <EmptyState title={t('admin.no_plans')} description={t('admin.no_plans_desc')} />;
    const featureLabel = (key: string) => { const translationKey = `billing.feature.${key}`; const translated = t(translationKey); return translated === translationKey ? key.replaceAll('_', ' ') : translated; };
    const featureValue = (value: unknown) => { const key = `billing.value.${String(value)}`; const translated = t(key); return translated === key ? String(value) : translated; };
    return <div className="grid w-full gap-4 sm:grid-cols-2 xl:grid-cols-3">{data.map((plan) => <div key={plan.id} className="rounded-xl border border-slate-200 bg-white p-5 shadow-card"><p className="font-bold">{plan.name}</p><p className="mt-1 text-sm text-slate-500">{(plan.price_monthly / 100).toLocaleString()} {plan.currency}{t('settings.per_month_short')}</p><ul className="mt-3 space-y-1 text-[11px] text-slate-500">{plan.features.map((feature) => <li key={feature.key}>{featureLabel(feature.key)}: <b>{featureValue(feature.value)}</b></li>)}</ul></div>)}</div>;
}

function SimpleTable({ headers, rows, footer }: { headers: string[]; rows: any[][]; footer?: ReactNode }) {
    return <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-card"><div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr>{headers.map((header) => <th key={header} className="px-4 py-2.5 text-start">{header}</th>)}</tr></thead><tbody>{rows.map((row, i) => <tr key={i} className="border-t border-slate-100">{row.map((cell, j) => <td key={j} className="px-4 py-2">{cell}</td>)}</tr>)}</tbody></table></div>{footer}</div>;
}
