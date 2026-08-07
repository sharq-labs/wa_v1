import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { adminApi } from '@/api';
import { Badge, EmptyState, Pagination, QueryError, Skeleton, TableSkeleton, statusColor } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { clsx } from 'clsx';
import type { ReactNode } from 'react';

export default function AdminPage() {
    const tabs = [
        { to: 'overview', label: 'Overview' },
        { to: 'health', label: 'System health' },
        { to: 'users', label: 'Users' },
        { to: 'workspaces', label: 'Workspaces' },
        { to: 'webhooks', label: 'Webhook logs' },
        { to: 'errors', label: 'Errors' },
        { to: 'plans', label: 'Plans' },
    ];

    return (
        <div className="flex h-full min-h-0 flex-col md:flex-row">
            <div className="flex w-full shrink-0 gap-1 overflow-x-auto border-b border-slate-200 bg-white p-2 md:w-60 md:flex-col md:gap-1 md:border-b-0 md:border-e md:p-5">
                <h2 className="mb-3 hidden px-3 text-base font-bold text-slate-900 md:block">Platform Admin</h2>
                {tabs.map((tab) => (
                    <NavLink
                        key={tab.to}
                        to={`/admin/${tab.to}`}
                        className={({ isActive }) =>
                            clsx(
                                'block shrink-0 whitespace-nowrap rounded-xl px-3.5 py-2.5 text-[15px] transition-colors',
                                isActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50',
                            )
                        }
                    >
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

/**
 * Every admin endpoint is gated on the platform-admin role, so a 403 is an
 * expected answer here rather than a transient failure — retrying it would only
 * fail again, so we explain instead.
 */
function AdminError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
    if (error instanceof ApiError && error.status === 403) {
        return <QueryError message="You do not have platform admin access." />;
    }

    return <QueryError message="This platform admin data could not be loaded." onRetry={onRetry} />;
}

/**
 * Stand-in for the stat/plan card grids. Sized to the real cards so the tab
 * does not jump when the numbers land.
 */
function CardGridSkeleton({ count = 6, className }: { count?: number; className?: string }) {
    return (
        <div className={clsx('grid w-full gap-3', className ?? 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6')}>
            {Array.from({ length: count }).map((_, i) => (
                <div key={i} className="rounded-xl border border-slate-200 bg-white p-4 shadow-card">
                    <Skeleton className="h-3 w-20" />
                    <Skeleton className="mt-2.5 h-7 w-14" />
                </div>
            ))}
        </div>
    );
}

function Overview() {
    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['admin-overview'],
        queryFn: async () => (await adminApi.overview()).data,
    });

    // Checked before the loading branch: a failed request leaves `isLoading`
    // false and `data` undefined, which used to spin forever.
    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading || !data) return <CardGridSkeleton count={8} />;

    return (
        <div className="grid w-full grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6">
            {Object.entries(data).map(([key, value]) => (
                <div key={key} className="rounded-xl border border-slate-200 bg-white p-4 shadow-card">
                    <p className="text-xs text-slate-500">{key.replaceAll('_', ' ')}</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums">{value as number}</p>
                </div>
            ))}
        </div>
    );
}

function Health() {
    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['admin-health'],
        queryFn: async () => (await adminApi.health()).data,
        refetchInterval: 15_000,
    });

    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading || !data) return <CardGridSkeleton count={6} className="grid-cols-2 lg:grid-cols-4" />;

    return (
        <div className="w-full space-y-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-card">
                    <p className="text-xs text-slate-500">Database</p>
                    <div className="mt-2">
                        <Badge color={data.database ? 'green' : 'red'}>{data.database ? 'OK' : 'DOWN'}</Badge>
                    </div>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-card">
                    <p className="text-xs text-slate-500">Redis</p>
                    <div className="mt-2">
                        <Badge color={data.redis ? 'green' : 'red'}>{data.redis ? 'OK' : 'DOWN'}</Badge>
                    </div>
                </div>
                {[
                    ['Failed jobs', data.failed_jobs],
                    ['Webhook failures (24h)', data.webhook_failures_24h],
                    ['Message failures (24h)', data.message_failures_24h],
                    ['Automation errors (24h)', data.automation_errors_24h],
                ].map(([label, value]) => (
                    <div key={label as string} className="rounded-xl border border-slate-200 bg-white p-4 shadow-card">
                        <p className="text-xs text-slate-500">{label}</p>
                        <p className={clsx('mt-1 text-2xl font-semibold tabular-nums', (value as number) > 0 ? 'text-red-600' : '')}>
                            {value as number}
                        </p>
                    </div>
                ))}
            </div>
            {data.queues && (
                <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-card">
                    <p className="mb-3 text-sm font-semibold text-slate-700">Queue depth</p>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        {Object.entries(data.queues).map(([queue, depth]) => (
                            <div key={queue} className="rounded-lg bg-slate-50 p-3 text-xs">
                                <p className="text-slate-500">{queue}</p>
                                <p className="mt-1 text-lg font-semibold tabular-nums">{depth as number}</p>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

function UsersTab() {
    const [page, setPage] = useState(1);
    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['admin-users', page],
        queryFn: async () => (await adminApi.users(page)).data,
    });

    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading) return <TableSkeleton rows={8} columns={4} />;
    if (!data?.items.length) return <EmptyState title="No users" description="Nobody has registered on this platform yet." />;

    return (
        <SimpleTable
            headers={['Name', 'Email', 'Workspaces', 'Joined']}
            rows={data.items.map((user: any) => [
                user.name,
                user.email,
                user.workspaces_count,
                new Date(user.created_at).toLocaleDateString(),
            ])}
            footer={
                <Pagination
                    page={data.meta.current_page}
                    lastPage={data.meta.last_page}
                    total={data.meta.total}
                    onChange={setPage}
                />
            }
        />
    );
}

function WorkspacesTab() {
    const [page, setPage] = useState(1);
    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['admin-workspaces', page],
        queryFn: async () => (await adminApi.workspaces(page)).data,
    });

    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading) return <TableSkeleton rows={8} columns={6} />;
    if (!data?.items.length) return <EmptyState title="No workspaces" description="No workspace has been created yet." />;

    return (
        <SimpleTable
            headers={['Name', 'Owner', 'Plan', 'Users', 'Contacts', 'Automations']}
            rows={data.items.map((workspace: any) => [
                workspace.name,
                workspace.owner?.email,
                workspace.subscription?.plan?.name ?? '—',
                workspace.users_count,
                workspace.contacts_count,
                workspace.automations_count,
            ])}
            footer={
                <Pagination
                    page={data.meta.current_page}
                    lastPage={data.meta.last_page}
                    total={data.meta.total}
                    onChange={setPage}
                />
            }
        />
    );
}

function WebhooksTab() {
    const [page, setPage] = useState(1);
    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['admin-webhooks', page],
        queryFn: async () => (await adminApi.webhookEvents(page)).data,
    });

    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading) return <TableSkeleton rows={8} columns={5} />;
    if (!data?.items.length)
        return <EmptyState title="No webhook events" description="Nothing has been received from Meta yet." />;

    return (
        <SimpleTable
            headers={['Type', 'Status', 'Attempts', 'Error', 'Received']}
            rows={data.items.map((event: any) => [
                event.event_type,
                <Badge key="s" color={statusColor(event.status === 'processed' ? 'completed' : event.status)}>{event.status}</Badge>,
                event.attempts,
                <span key="e" className="text-xs text-red-600">{event.error_message?.slice(0, 60)}</span>,
                new Date(event.created_at).toLocaleString(),
            ])}
            footer={
                <Pagination
                    page={data.meta.current_page}
                    lastPage={data.meta.last_page}
                    total={data.meta.total}
                    onChange={setPage}
                />
            }
        />
    );
}

function ErrorsTab() {
    const [runsPage, setRunsPage] = useState(1);
    const failedRuns = useQuery({
        queryKey: ['admin-failed-runs', runsPage],
        queryFn: async () => (await adminApi.failedRuns(runsPage)).data,
    });
    const failedJobs = useQuery({ queryKey: ['admin-failed-jobs'], queryFn: async () => (await adminApi.failedJobs()).data });

    return (
        <div className="space-y-6">
            <div>
                <h3 className="mb-2 text-sm font-semibold text-slate-700">Failed automation runs</h3>
                {failedRuns.isError ? (
                    <AdminError error={failedRuns.error} onRetry={() => failedRuns.refetch()} />
                ) : failedRuns.isLoading ? (
                    <TableSkeleton rows={5} columns={4} />
                ) : !failedRuns.data?.items.length ? (
                    <EmptyState title="No failed runs" description="Every automation run has completed cleanly." />
                ) : (
                    <SimpleTable
                        headers={['Automation', 'Workspace', 'Error', 'When']}
                        rows={failedRuns.data.items.map((run: any) => [
                            run.automation?.name,
                            run.workspace?.name,
                            <span key="e" className="text-xs text-red-600">{run.error?.slice(0, 80)}</span>,
                            new Date(run.created_at).toLocaleString(),
                        ])}
                        footer={
                            <Pagination
                                page={failedRuns.data.meta.current_page}
                                lastPage={failedRuns.data.meta.last_page}
                                total={failedRuns.data.meta.total}
                                onChange={setRunsPage}
                            />
                        }
                    />
                )}
            </div>
            <div>
                {/* The endpoint returns the newest 100 rows flat — there is no page meta to drive a pager. */}
                <h3 className="mb-2 text-sm font-semibold text-slate-700">Failed jobs (latest 100)</h3>
                {failedJobs.isError ? (
                    <AdminError error={failedJobs.error} onRetry={() => failedJobs.refetch()} />
                ) : failedJobs.isLoading ? (
                    <TableSkeleton rows={5} columns={3} />
                ) : !failedJobs.data?.length ? (
                    <EmptyState title="No failed jobs" description="The queue has not dropped anything." />
                ) : (
                    <SimpleTable
                        headers={['Queue', 'Exception', 'Failed at']}
                        rows={failedJobs.data.map((job: any) => [
                            job.queue,
                            <span key="e" className="text-xs text-red-600">{job.exception?.slice(0, 100)}</span>,
                            job.failed_at,
                        ])}
                    />
                )}
            </div>
        </div>
    );
}

function PlansTab() {
    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['admin-plans'],
        queryFn: async () => (await adminApi.plans()).data,
    });

    if (isError) return <AdminError error={error} onRetry={() => refetch()} />;
    if (isLoading) return <CardGridSkeleton count={3} className="gap-4 sm:grid-cols-2 xl:grid-cols-3" />;
    if (!data?.length) return <EmptyState title="No plans" description="No billing plan has been configured yet." />;

    return (
        <div className="grid w-full gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {data.map((plan) => (
                <div key={plan.id} className="rounded-xl border border-slate-200 bg-white p-5 shadow-card">
                    <p className="font-bold">{plan.name}</p>
                    <p className="mt-1 text-sm text-slate-500">
                        {(plan.price_monthly / 100).toLocaleString()} {plan.currency}/mo
                    </p>
                    <ul className="mt-3 space-y-1 text-[11px] text-slate-500">
                        {plan.features.map((feature) => (
                            <li key={feature.key}>
                                {feature.key}: <b>{feature.value}</b>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}

function SimpleTable({ headers, rows, footer }: { headers: string[]; rows: any[][]; footer?: ReactNode }) {
    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-card">
            {/* Only the table scrolls sideways — a footer pager must stay put. */}
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            {headers.map((header) => (
                                <th key={header} className="px-4 py-2.5 text-start">
                                    {header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, i) => (
                            <tr key={i} className="border-t border-slate-100">
                                {row.map((cell, j) => (
                                    <td key={j} className="px-4 py-2">
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {footer}
        </div>
    );
}
