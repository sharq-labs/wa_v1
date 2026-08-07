import { useQuery } from '@tanstack/react-query';
import { clsx } from 'clsx';
import {
    AlertTriangle,
    Bot,
    CheckCheck,
    Eye,
    Inbox,
    MessageSquare,
    Send,
    Timer,
    TrendingUp,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';
import { analyticsApi } from '@/api';
import { Card, EmptyState, PageHeader, QueryError, Skeleton } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';

const PERIODS = ['today', 'yesterday', '7d', '30d'] as const;

function formatSeconds(seconds: number | null): string {
    if (seconds === null) return '—';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
    return `${(seconds / 3600).toFixed(1)}h`;
}

function percent(part: number, whole: number): number | null {
    return whole > 0 ? Math.round((part / whole) * 1000) / 10 : null;
}

/**
 * Sent → Delivered → Read. Bar widths are relative to `sent` so the drop-off at
 * each stage is readable at a glance rather than needing the numbers compared.
 */
function DeliveryFunnel({ sent, delivered, read, failed }: { sent: number; delivered: number; read: number; failed: number }) {
    const { t } = useI18n();

    const stages: { key: string; label: string; value: number; bar: string; Icon: LucideIcon }[] = [
        { key: 'sent', label: t('analytics.sent'), value: sent, bar: 'bg-brand-500', Icon: Send },
        { key: 'delivered', label: t('analytics.delivered'), value: delivered, bar: 'bg-sky-500', Icon: CheckCheck },
        { key: 'read', label: t('analytics.read'), value: read, bar: 'bg-violet-500', Icon: Eye },
    ];

    return (
        <Card className="p-5">
            <div className="mb-4 flex items-baseline justify-between gap-3">
                <h3 className="text-sm font-semibold text-slate-800">{t('analytics.delivery')}</h3>
                {failed > 0 && (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700">
                        <AlertTriangle size={12} strokeWidth={2.5} />
                        {failed} {t('analytics.failed')}
                    </span>
                )}
            </div>

            {sent === 0 ? (
                <EmptyState title={t('analytics.no_messages')} description={t('analytics.no_messages_desc')} />
            ) : (
                <div className="space-y-3.5">
                    {stages.map(({ key, label, value, bar, Icon }) => {
                        const rate = percent(value, sent);
                        return (
                            <div key={key}>
                                <div className="mb-1.5 flex items-center gap-2">
                                    <Icon size={14} strokeWidth={2.3} className="shrink-0 text-slate-400" />
                                    <span className="text-xs font-medium text-slate-600">{label}</span>
                                    <span className="ms-auto text-sm font-bold text-slate-900 tabular-nums">{value}</span>
                                    {key !== 'sent' && rate !== null && (
                                        <span className="w-12 text-end text-[11px] font-semibold text-slate-400 tabular-nums">
                                            {rate}%
                                        </span>
                                    )}
                                </div>
                                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        className={clsx('h-full rounded-full transition-[width] duration-500', bar)}
                                        style={{ width: `${Math.max(percent(value, sent) ?? 0, value > 0 ? 2 : 0)}%` }}
                                    />
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </Card>
    );
}

function TrafficChart({ series }: { series: { date: string; inbound: number; outbound: number }[] }) {
    const { t } = useI18n();
    const max = Math.max(1, ...series.flatMap((d) => [d.inbound, d.outbound]));
    const hasData = series.some((d) => d.inbound > 0 || d.outbound > 0);
    const ticks = new Set([0, Math.floor((series.length - 1) / 2), series.length - 1]);

    return (
        <Card className="p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-slate-800">{t('analytics.traffic')}</h3>
                <div className="flex items-center gap-3 text-[11px] font-medium text-slate-500">
                    <span className="flex items-center gap-1.5">
                        <span className="h-2 w-2 rounded-full bg-brand-500" /> {t('analytics.incoming')}
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-2 w-2 rounded-full bg-sky-400" /> {t('analytics.outgoing')}
                    </span>
                </div>
            </div>

            {!hasData ? (
                <EmptyState title={t('dashboard.chart_empty_title')} />
            ) : (
                <>
                    <div className="flex h-40 items-end gap-1.5">
                        {series.map((day) => (
                            <div
                                key={day.date}
                                className="flex h-full flex-1 items-end justify-center gap-[3px]"
                                title={`${day.date} — ${t('analytics.incoming')}: ${day.inbound}, ${t('analytics.outgoing')}: ${day.outbound}`}
                            >
                                <div
                                    className="w-full max-w-3 rounded-t-md bg-brand-500 transition-[height] duration-500"
                                    style={{ height: `${(day.inbound / max) * 100}%`, minHeight: day.inbound ? 3 : 0 }}
                                />
                                <div
                                    className="w-full max-w-3 rounded-t-md bg-sky-400 transition-[height] duration-500"
                                    style={{ height: `${(day.outbound / max) * 100}%`, minHeight: day.outbound ? 3 : 0 }}
                                />
                            </div>
                        ))}
                    </div>
                    <div className="mt-2 flex justify-between text-[11px] font-medium text-slate-400">
                        {series.map((day, i) =>
                            ticks.has(i) ? <span key={day.date}>{day.date.slice(5)}</span> : <span key={day.date} />,
                        )}
                    </div>
                </>
            )}
        </Card>
    );
}

function Stat({
    label,
    value,
    icon: Icon,
    tone = 'slate',
    hint,
}: {
    label: string;
    value: number | string;
    icon: LucideIcon;
    tone?: 'brand' | 'sky' | 'violet' | 'amber' | 'rose' | 'slate';
    hint?: string;
}) {
    const iconTone = {
        brand: 'bg-brand-50 text-brand-600',
        sky: 'bg-sky-50 text-sky-600',
        violet: 'bg-violet-50 text-violet-600',
        amber: 'bg-amber-50 text-amber-600',
        rose: 'bg-rose-50 text-rose-600',
        slate: 'bg-slate-100 text-slate-500',
    }[tone];

    return (
        <Card className="flex items-center gap-3.5 p-4">
            <span className={clsx('flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', iconTone)}>
                <Icon size={17} strokeWidth={2.2} />
            </span>
            <div className="min-w-0">
                <p className="truncate text-[11.5px] font-semibold text-slate-500">{label}</p>
                <p className="text-[1.35rem] leading-tight font-bold text-slate-900 tabular-nums">{value}</p>
                {hint && <p className="truncate text-[10.5px] text-slate-400">{hint}</p>}
            </div>
        </Card>
    );
}

function AnalyticsSkeleton() {
    return (
        <div className="space-y-5">
            <div className="grid gap-4 lg:grid-cols-2">
                <Skeleton className="h-56" />
                <Skeleton className="h-56" />
            </div>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                {Array.from({ length: 8 }).map((_, i) => (
                    <Skeleton key={i} className="h-[4.5rem]" />
                ))}
            </div>
        </div>
    );
}

export default function AnalyticsPage() {
    const { t } = useI18n();
    const workspaceId = useWorkspaceId();
    const [period, setPeriod] = useState<(typeof PERIODS)[number]>('7d');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['analytics', workspaceId, period],
        queryFn: async () => (await analyticsApi.analytics(workspaceId, { period })).data,
    });

    return (
        <div className="p-4 md:p-6 lg:p-8">
            <PageHeader
                title={t('nav.analytics')}
                subtitle={t('analytics.subtitle')}
                actions={
                    <div className="flex gap-0.5 rounded-xl bg-white p-1 shadow-xs ring-1 ring-slate-200">
                        {PERIODS.map((key) => (
                            <button
                                key={key}
                                onClick={() => setPeriod(key)}
                                aria-pressed={period === key}
                                className={clsx(
                                    'rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                    period === key
                                        ? 'bg-brand-600 text-white shadow-xs'
                                        : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800',
                                )}
                            >
                                {t(`analytics.period_${key}`)}
                            </button>
                        ))}
                    </div>
                }
            />

            {isLoading ? (
                <AnalyticsSkeleton />
            ) : isError || !data ? (
                <QueryError onRetry={() => refetch()} />
            ) : (
                <div className="space-y-5">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <DeliveryFunnel
                            sent={data.messages.sent}
                            delivered={data.messages.delivered}
                            read={data.messages.read}
                            failed={data.messages.failed}
                        />
                        <TrafficChart series={data.series ?? []} />
                    </div>

                    <section>
                        <h3 className="mb-2.5 text-[11px] font-bold tracking-[0.08em] text-slate-400 uppercase">
                            {t('analytics.conversations_section')}
                        </h3>
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <Stat label={t('analytics.incoming')} value={data.messages.incoming} icon={Inbox} tone="brand" />
                            <Stat
                                label={t('analytics.agent_messages')}
                                value={data.messages.agent_messages}
                                icon={MessageSquare}
                                tone="sky"
                            />
                            <Stat
                                label={t('analytics.avg_first_response')}
                                value={formatSeconds(data.agents.avg_first_response_seconds)}
                                icon={Timer}
                                tone="violet"
                            />
                            <Stat
                                label={t('analytics.avg_resolution')}
                                value={formatSeconds(data.agents.avg_resolution_seconds)}
                                icon={CheckCheck}
                                tone="slate"
                            />
                        </div>
                    </section>

                    <section>
                        <h3 className="mb-2.5 text-[11px] font-bold tracking-[0.08em] text-slate-400 uppercase">
                            {t('analytics.growth_section')}
                        </h3>
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <Stat label={t('analytics.new_contacts')} value={data.contacts} icon={UserPlus} tone="brand" />
                            <Stat
                                label={t('analytics.conversations')}
                                value={data.conversations}
                                icon={MessageSquare}
                                tone="sky"
                            />
                            <Stat
                                label={t('analytics.bot_starts')}
                                value={data.automation.starts}
                                icon={Bot}
                                tone="violet"
                                hint={`${data.automation.completions} ${t('analytics.completed')}`}
                            />
                            <Stat
                                label={t('analytics.completion_rate')}
                                value={data.automation.completion_rate !== null ? `${data.automation.completion_rate}%` : '—'}
                                icon={TrendingUp}
                                tone={data.automation.failures > 0 ? 'rose' : 'amber'}
                                hint={
                                    data.automation.failures > 0
                                        ? `${data.automation.failures} ${t('analytics.bot_failures')}`
                                        : undefined
                                }
                            />
                        </div>
                    </section>
                </div>
            )}
        </div>
    );
}
