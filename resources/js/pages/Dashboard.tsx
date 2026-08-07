import { useQuery } from '@tanstack/react-query';
import { format } from 'date-fns';
import {
    AlertTriangle,
    ArrowDownLeft,
    ArrowRight,
    ArrowUpRight,
    Bot,
    Check,
    Inbox,
    Megaphone,
    MessageSquare,
    PlayCircle,
    UserCheck,
    UserX,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { analyticsApi } from '@/api';
import { Badge, QueryError, Skeleton, statusColor } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useAuthStore, useWorkspaceId } from '@/stores/authStore';
import { clsx } from 'clsx';

function chartHasData(data: any[], keys: string[]) {
    return data.some((d) => keys.some((k) => (d[k] ?? 0) > 0));
}

function MetricCard({
    label,
    value,
    icon: Icon,
    to,
    accent = 'brand',
    hint,
}: {
    label: string;
    value: number | string;
    icon: LucideIcon;
    to?: string;
    accent?: 'brand' | 'sky' | 'amber' | 'slate';
    hint?: string;
}) {
    const accents = {
        brand: {
            card: 'border-brand-200/70 bg-[linear-gradient(165deg,#eef7f1_0%,#fbfcfb_55%)]',
            icon: 'bg-brand-100 text-brand-800',
            bar: 'bg-brand-600',
        },
        sky: {
            card: 'border-sky-200/70 bg-[linear-gradient(165deg,#eef6fb_0%,#fbfcfb_55%)]',
            icon: 'bg-sky-100 text-sky-800',
            bar: 'bg-sky-600',
        },
        amber: {
            card: 'border-amber-200/70 bg-[linear-gradient(165deg,#faf3e6_0%,#fbfcfb_55%)]',
            icon: 'bg-amber-100 text-amber-800',
            bar: 'bg-amber-600',
        },
        slate: {
            card: 'border-slate-300/60 bg-[linear-gradient(165deg,#eef1f3_0%,#fbfcfb_55%)]',
            icon: 'bg-slate-200/80 text-slate-700',
            bar: 'bg-slate-500',
        },
    }[accent];

    const body = (
        <div
            className={clsx(
                'group relative flex h-full flex-col justify-between overflow-hidden rounded-2xl border p-4 shadow-card transition duration-300 ease-out',
                accents.card,
                to && 'hover:-translate-y-0.5 hover:shadow-pop',
            )}
        >
            <span className={clsx('absolute inset-y-3 start-0 w-[3px] rounded-full', accents.bar)} aria-hidden="true" />
            <div className="flex items-start justify-between gap-3 ps-2">
                <p className="text-[14.5px] font-medium text-slate-600">{label}</p>
                <span className={clsx('flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', accents.icon)}>
                    <Icon size={18} strokeWidth={2.2} />
                </span>
            </div>
            <div className="ps-2 pt-3">
                <p className="text-[2rem] font-bold tracking-tight text-slate-900 tabular-nums leading-none">{value}</p>
                {hint && <p className="mt-1.5 text-[13px] text-slate-500">{hint}</p>}
            </div>
            {to && (
                <span className="absolute end-3 bottom-3 text-slate-400 opacity-0 transition duration-300 group-hover:opacity-100">
                    <ArrowRight size={14} className="rtl:rotate-180" />
                </span>
            )}
        </div>
    );

    return to ? <Link to={to} className="min-w-0">{body}</Link> : <div className="min-w-0">{body}</div>;
}

function StatPill({
    label,
    value,
    icon: Icon,
    to,
    alert,
}: {
    label: string;
    value: number | string;
    icon: LucideIcon;
    to?: string;
    alert?: boolean;
}) {
    const body = (
        <div
            className={clsx(
                'flex items-center gap-3 rounded-2xl border px-3.5 py-3 shadow-card transition duration-300',
                alert && Number(value) > 0
                    ? 'border-rose-300/70 bg-rose-50 hover:border-rose-400'
                    : 'border-slate-300/55 bg-panel hover:border-slate-400/60 hover:shadow-pop',
            )}
        >
            <span
                className={clsx(
                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl',
                    alert && Number(value) > 0 ? 'bg-rose-100 text-rose-700' : 'bg-slate-200/70 text-slate-700',
                )}
            >
                <Icon size={16} strokeWidth={2.2} />
            </span>
            <div className="min-w-0">
                <p className="truncate text-[11.5px] font-medium text-slate-600">{label}</p>
                <p className="text-[1.15rem] font-bold text-slate-900 tabular-nums leading-tight">{value}</p>
            </div>
        </div>
    );

    return to ? (
        <Link to={to} className="min-w-0">
            {body}
        </Link>
    ) : (
        <div className="min-w-0">{body}</div>
    );
}

function MiniBarChart({
    data,
    keys,
    colors,
    labels,
}: {
    data: any[];
    keys: string[];
    colors: string[];
    labels: Record<string, string>;
}) {
    const max = Math.max(1, ...data.flatMap((d) => keys.map((k) => d[k] ?? 0)));
    const ticks = new Set([0, Math.floor((data.length - 1) / 2), data.length - 1]);

    return (
        <div>
            <div className="flex h-40 items-end gap-1.5">
                {data.map((d, i) => (
                    <div
                        key={i}
                        className="flex flex-1 flex-col items-center justify-end gap-0.5"
                        title={`${d.date} — ${keys.map((k) => `${labels[k]}: ${d[k] ?? 0}`).join(', ')}`}
                    >
                        {keys.map((k, ki) => (
                            <div
                                key={k}
                                className={clsx('w-full max-w-[14px] rounded-md transition-[height] duration-700 ease-out', colors[ki])}
                                style={{ height: `${((d[k] ?? 0) / max) * 100}%`, minHeight: d[k] ? 3 : 0 }}
                            />
                        ))}
                    </div>
                ))}
            </div>
            <div className="mt-2.5 flex justify-between text-[11px] font-medium text-slate-400">
                {data.map((d, i) => (ticks.has(i) ? <span key={i}>{String(d.date).slice(5)}</span> : <span key={i} />))}
            </div>
        </div>
    );
}

function StartStep({
    index,
    done,
    label,
    to,
}: {
    index: number;
    done: boolean;
    label: string;
    to: string;
}) {
    return (
        <Link
            to={to}
            className={clsx(
                'group flex items-center gap-3 rounded-xl px-3 py-3 transition duration-200',
                done ? 'bg-brand-100/70' : 'bg-slate-200/40 hover:bg-slate-200/70',
            )}
        >
            <span
                className={clsx(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-bold',
                    done ? 'bg-brand-600 text-white' : 'bg-panel text-slate-600 ring-1 ring-slate-300/70',
                )}
            >
                {done ? <Check size={14} strokeWidth={2.8} /> : index}
            </span>
            <span className={clsx('min-w-0 flex-1 text-[13.5px] font-semibold', done ? 'text-brand-800' : 'text-slate-700')}>
                {label}
            </span>
            <ArrowRight
                size={15}
                className={clsx('shrink-0 rtl:rotate-180', done ? 'text-brand-400' : 'text-slate-300 group-hover:text-brand-600')}
            />
        </Link>
    );
}

function DashboardSkeleton() {
    return (
        <div className="mx-auto max-w-[1280px] space-y-6 p-4 md:p-6 lg:p-8">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="space-y-2">
                    <Skeleton className="h-3.5 w-28" />
                    <Skeleton className="h-8 w-56" />
                    <Skeleton className="h-4 w-72" />
                </div>
                <div className="flex gap-2">
                    <Skeleton className="h-10 w-28 rounded-xl" />
                    <Skeleton className="h-10 w-32 rounded-xl" />
                </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: 4 }).map((_, i) => (
                    <Skeleton key={i} className="h-[124px] rounded-2xl" />
                ))}
            </div>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {Array.from({ length: 6 }).map((_, i) => (
                    <Skeleton key={i} className="h-[68px] rounded-2xl" />
                ))}
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <Skeleton className="h-64 rounded-2xl" />
                <Skeleton className="h-64 rounded-2xl" />
            </div>
        </div>
    );
}

export default function Dashboard() {
    const { t, statusLabel, dateLocale, locale } = useI18n();
    const workspaceId = useWorkspaceId();
    const user = useAuthStore((s) => s.user);
    const firstName = user?.name?.trim().split(/\s+/)[0] || '';

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['dashboard', workspaceId],
        queryFn: async () => (await analyticsApi.dashboard(workspaceId)).data,
    });

    if (isLoading) return <DashboardSkeleton />;
    if (isError || !data) return <QueryError onRetry={() => refetch()} />;

    const m = data.metrics;
    const hasContacts = m.contacts > 0;
    const hasBots = m.published_bots > 0;
    const hasCampaigns = data.campaigns.length > 0;
    const messagesLive = chartHasData(data.charts.messages_by_date, ['inbound', 'outbound']);
    const automationLive = chartHasData(data.charts.automation_by_date, ['started', 'completed']);

    const setupDone = [hasContacts, hasBots, hasCampaigns].filter(Boolean).length;
    const setupTotal = 3;
    const setupProgress = Math.round((setupDone / setupTotal) * 100);
    const showGetStarted = setupDone < setupTotal;

    const todayLabel = format(new Date(), locale === 'ar' ? 'EEEE، d MMMM' : 'EEEE, MMM d', { locale: dateLocale });

    return (
        <div className="relative min-h-full">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-0 top-0 h-48 bg-[radial-gradient(ellipse_at_top,_rgba(22,101,52,0.07),_transparent_70%)]"
            />

            <div className="relative mx-auto max-w-[1280px] space-y-6 p-4 md:p-6 lg:p-8">
                <header className="dash-reveal flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-[14.5px] font-medium text-slate-600">{todayLabel}</p>
                        <h1 className="mt-1 text-[1.85rem] font-bold tracking-tight text-slate-900 md:text-[2.1rem]">
                            {firstName ? t('dashboard.greeting_name', { name: firstName }) : t('dashboard.greeting')}
                        </h1>
                        <p className="mt-1.5 max-w-lg text-[15.5px] leading-relaxed text-slate-600">{t('dashboard.subtitle')}</p>
                    </div>
                    <div className="flex flex-wrap gap-2.5">
                        <Link
                            to="/inbox"
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-300/60 bg-panel px-4 py-2.5 text-[13.5px] font-semibold text-slate-700 shadow-card transition duration-200 hover:border-slate-400/50 hover:bg-white"
                        >
                            <Inbox size={16} />
                            {t('dashboard.open_inbox')}
                        </Link>
                        <Link
                            to="/campaigns"
                            className="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-[13.5px] font-semibold text-white shadow-sm shadow-brand-900/15 transition duration-200 hover:bg-brand-800"
                        >
                            <Megaphone size={16} />
                            {t('dashboard.create_campaign')}
                        </Link>
                    </div>
                </header>

                {(m.unassigned_conversations > 0 || m.failed_automations_today > 0) && (
                    <div className="dash-reveal dash-reveal-delay-1 flex flex-wrap gap-2.5">
                        {m.unassigned_conversations > 0 && (
                            <Link
                                to="/inbox?scope=unassigned"
                                className="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2 text-[13px] font-semibold text-amber-900 transition hover:bg-amber-100"
                            >
                                <UserX size={15} />
                                {t('dashboard.attention_unassigned', { count: m.unassigned_conversations })}
                                <ArrowRight size={13} className="rtl:rotate-180 opacity-60" />
                            </Link>
                        )}
                        {m.failed_automations_today > 0 && (
                            <Link
                                to="/automations"
                                className="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-[13px] font-semibold text-rose-900 transition hover:bg-rose-100"
                            >
                                <AlertTriangle size={15} />
                                {t('dashboard.attention_failed', { count: m.failed_automations_today })}
                                <ArrowRight size={13} className="rtl:rotate-180 opacity-60" />
                            </Link>
                        )}
                    </div>
                )}

                {/* Primary metrics */}
                <section className="dash-reveal dash-reveal-delay-1 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        label={t('dashboard.messages_today')}
                        value={m.messages_today}
                        icon={MessageSquare}
                        accent="brand"
                        hint={t('dashboard.hint_messages')}
                    />
                    <MetricCard
                        label={t('dashboard.open_conversations')}
                        value={m.open_conversations}
                        icon={Inbox}
                        accent="sky"
                        to="/inbox?status=open"
                        hint={t('dashboard.hint_open')}
                    />
                    <MetricCard
                        label={t('dashboard.unassigned')}
                        value={m.unassigned_conversations}
                        icon={UserX}
                        accent="amber"
                        to="/inbox?scope=unassigned"
                        hint={t('dashboard.hint_unassigned')}
                    />
                    <MetricCard
                        label={t('dashboard.published_bots')}
                        value={m.published_bots}
                        icon={Bot}
                        accent="slate"
                        to="/automations"
                        hint={t('dashboard.hint_bots')}
                    />
                </section>

                {/* Secondary stats */}
                <section className="dash-reveal dash-reveal-delay-2 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <StatPill label={t('dashboard.incoming')} value={m.incoming_today} icon={ArrowDownLeft} />
                    <StatPill label={t('dashboard.outgoing')} value={m.outgoing_today} icon={ArrowUpRight} />
                    <StatPill label={t('dashboard.contacts')} value={m.contacts} icon={Users} to="/contacts" />
                    <StatPill label={t('dashboard.active_agents')} value={m.active_agents} icon={UserCheck} />
                    <StatPill label={t('dashboard.runs_today')} value={m.automation_runs_today} icon={PlayCircle} to="/automations" />
                    <StatPill
                        label={t('dashboard.failed_runs')}
                        value={m.failed_automations_today}
                        icon={AlertTriangle}
                        alert
                        to="/automations"
                    />
                </section>

                {/* Setup + charts */}
                <div className={clsx('dash-reveal dash-reveal-delay-3 grid gap-4', showGetStarted ? 'xl:grid-cols-[0.9fr_1.1fr]' : '')}>
                    {showGetStarted && (
                        <section className="rounded-2xl border border-slate-300/55 bg-panel p-5 shadow-card sm:p-6">
                            <div className="mb-4 flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-[1.05rem] font-bold text-slate-900">{t('dashboard.get_started')}</h2>
                                    <p className="mt-1 text-[13px] text-slate-600">{t('dashboard.get_started_desc')}</p>
                                </div>
                                <span className="shrink-0 rounded-full bg-brand-100 px-2.5 py-1 text-[12px] font-bold text-brand-800">
                                    {setupDone}/{setupTotal}
                                </span>
                            </div>
                            <div className="mb-4 h-1.5 overflow-hidden rounded-full bg-slate-200/80">
                                <div
                                    className="h-full rounded-full bg-brand-600 transition-[width] duration-700 ease-out"
                                    style={{ width: `${setupProgress}%` }}
                                />
                            </div>
                            <div className="space-y-2">
                                <StartStep
                                    index={1}
                                    done={hasContacts}
                                    label={hasContacts ? t('dashboard.step_contacts_done') : t('dashboard.step_contacts')}
                                    to="/contacts"
                                />
                                <StartStep
                                    index={2}
                                    done={hasBots}
                                    label={hasBots ? t('dashboard.step_bots_done') : t('dashboard.step_bots')}
                                    to="/automations"
                                />
                                <StartStep
                                    index={3}
                                    done={hasCampaigns}
                                    label={t('dashboard.step_campaign')}
                                    to="/campaigns"
                                />
                            </div>
                        </section>
                    )}

                    <div className={clsx('grid gap-4', showGetStarted ? '' : 'lg:grid-cols-2')}>
                        <section className="rounded-2xl border border-slate-300/55 bg-panel p-4 shadow-card sm:p-5">
                            <div className="mb-4 flex items-center justify-between gap-2">
                                <h3 className="text-[14px] font-bold text-slate-800">{t('dashboard.messages_chart')}</h3>
                                <div className="flex gap-2.5 text-[11px] font-semibold text-slate-600">
                                    <span className="flex items-center gap-1">
                                        <span className="h-2 w-2 rounded-sm bg-brand-600" />
                                        {t('dashboard.inbound')}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <span className="h-2 w-2 rounded-sm bg-sky-500" />
                                        {t('dashboard.outbound')}
                                    </span>
                                </div>
                            </div>
                            {messagesLive ? (
                                <MiniBarChart
                                    data={data.charts.messages_by_date}
                                    keys={['inbound', 'outbound']}
                                    colors={['bg-brand-600', 'bg-sky-500']}
                                    labels={{ inbound: t('dashboard.inbound'), outbound: t('dashboard.outbound') }}
                                />
                            ) : (
                                <div className="flex h-40 flex-col items-center justify-center rounded-xl bg-slate-200/35 px-4 text-center">
                                    <MessageSquare size={22} className="mb-2 text-slate-400" />
                                    <p className="text-[13px] font-medium text-slate-600">{t('dashboard.chart_empty')}</p>
                                </div>
                            )}
                        </section>

                        <section className="rounded-2xl border border-slate-300/55 bg-panel p-4 shadow-card sm:p-5">
                            <div className="mb-4 flex items-center justify-between gap-2">
                                <h3 className="text-[14px] font-bold text-slate-800">{t('dashboard.automation_chart')}</h3>
                                <div className="flex gap-2.5 text-[11px] font-semibold text-slate-600">
                                    <span className="flex items-center gap-1">
                                        <span className="h-2 w-2 rounded-sm bg-slate-500" />
                                        {t('dashboard.started')}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <span className="h-2 w-2 rounded-sm bg-brand-600" />
                                        {t('dashboard.completed')}
                                    </span>
                                </div>
                            </div>
                            {automationLive ? (
                                <MiniBarChart
                                    data={data.charts.automation_by_date}
                                    keys={['started', 'completed']}
                                    colors={['bg-slate-500', 'bg-brand-600']}
                                    labels={{ started: t('dashboard.started'), completed: t('dashboard.completed') }}
                                />
                            ) : (
                                <div className="flex h-40 flex-col items-center justify-center rounded-xl bg-slate-200/35 px-4 text-center">
                                    <Bot size={22} className="mb-2 text-slate-400" />
                                    <p className="text-[13px] font-medium text-slate-600">{t('dashboard.chart_empty')}</p>
                                </div>
                            )}
                        </section>
                    </div>
                </div>

                <section className="dash-reveal dash-reveal-delay-4 overflow-hidden rounded-2xl border border-slate-300/55 bg-panel shadow-card">
                    <div className="flex items-center justify-between gap-2 border-b border-slate-200/80 px-5 py-4">
                        <h3 className="text-[15px] font-bold text-slate-800">{t('dashboard.recent_campaigns')}</h3>
                        <Link
                            to="/campaigns"
                            className="inline-flex items-center gap-1 text-[13px] font-bold text-brand-800 transition hover:text-brand-900"
                        >
                            {t('dashboard.view_all')}
                            <ArrowRight size={14} className="rtl:rotate-180" />
                        </Link>
                    </div>
                    {data.campaigns.length === 0 ? (
                        <div className="flex flex-wrap items-center justify-between gap-4 px-5 py-7">
                            <div className="flex min-w-0 items-center gap-4">
                                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-100 text-brand-800">
                                    <Megaphone size={20} />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-[15px] font-bold text-slate-800">{t('dashboard.campaigns_empty_title')}</p>
                                    <p className="mt-0.5 text-[13px] text-slate-600">{t('dashboard.campaigns_empty_desc')}</p>
                                </div>
                            </div>
                            <Link
                                to="/campaigns"
                                className="inline-flex shrink-0 items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-[13.5px] font-semibold text-white shadow-sm transition hover:bg-brand-800"
                            >
                                {t('dashboard.create_campaign')}
                            </Link>
                        </div>
                    ) : (
                        <div className="overflow-x-auto px-5 py-2">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">
                                        <th className="py-2.5 text-start">{t('common.name')}</th>
                                        <th className="py-2.5 text-start">{t('common.status')}</th>
                                        <th className="py-2.5 text-end">{t('dashboard.recipients')}</th>
                                        <th className="py-2.5 text-end">{t('dashboard.sent')}</th>
                                        <th className="py-2.5 text-end">{t('dashboard.failed')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.campaigns.map((c: any) => (
                                        <tr key={c.id} className="border-t border-slate-200/70 transition-colors hover:bg-slate-200/30">
                                            <td className="py-3.5 font-semibold text-slate-800">
                                                <Link to="/campaigns" className="hover:text-brand-800">
                                                    {c.name}
                                                </Link>
                                            </td>
                                            <td>
                                                <Badge color={statusColor(c.status)}>{statusLabel(c.status)}</Badge>
                                            </td>
                                            <td className="text-end tabular-nums font-medium text-slate-700">{c.total_recipients}</td>
                                            <td className="text-end tabular-nums font-medium text-slate-700">{c.sent_count}</td>
                                            <td className="text-end tabular-nums font-medium text-slate-700">{c.failed_count}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </div>
    );
}
