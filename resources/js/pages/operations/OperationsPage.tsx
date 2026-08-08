import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Bell, CheckCircle2, FileUp, HeartPulse, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { productionApi, type ContactImportUploadResult } from '@/api/production';
import { Button, Select, Spinner } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';

const TARGETS = [
    ['phone_number', 'Phone number'],
    ['first_name', 'First name'],
    ['last_name', 'Last name'],
    ['display_name', 'Display name'],
    ['email', 'Email'],
    ['country', 'Country'],
    ['language', 'Language'],
    ['opt_in_status', 'Marketing consent'],
] as const;

export default function OperationsPage() {
    const workspaceId = useWorkspaceId();
    const { locale } = useI18n();
    const ar = locale === 'ar';
    const queryClient = useQueryClient();
    const [tab, setTab] = useState<'health' | 'imports' | 'notifications'>('health');
    const [uploaded, setUploaded] = useState<ContactImportUploadResult | null>(null);
    const [mapping, setMapping] = useState<Record<string, string>>({});

    const health = useQuery({
        queryKey: ['whatsapp-health', workspaceId],
        queryFn: async () => (await productionApi.health(workspaceId)).data,
        enabled: tab === 'health',
    });
    const imports = useQuery({
        queryKey: ['contact-imports', workspaceId],
        queryFn: async () => (await productionApi.imports(workspaceId)).data,
        enabled: tab === 'imports',
        refetchInterval: (query) => {
            const items = query.state.data?.items ?? [];
            return items.some((item) => ['queued', 'processing'].includes(item.status)) ? 2500 : false;
        },
    });
    const notifications = useQuery({
        queryKey: ['notifications', workspaceId],
        queryFn: async () => (await productionApi.notifications(workspaceId)).data,
        enabled: tab === 'notifications',
        refetchInterval: 15_000,
    });

    const upload = useMutation({
        mutationFn: (file: File) => productionApi.uploadImport(workspaceId, file),
        onSuccess: (response) => {
            setUploaded(response.data);
            setMapping(response.data.suggested_mapping ?? {});
            queryClient.invalidateQueries({ queryKey: ['contact-imports', workspaceId] });
        },
    });

    const startImport = useMutation({
        mutationFn: () =>
            productionApi.startImport(workspaceId, uploaded!.import.id, {
                mapping,
                update_existing: true,
                overwrite_empty: false,
            }),
        onSuccess: () => {
            setUploaded(null);
            setMapping({});
            queryClient.invalidateQueries({ queryKey: ['contact-imports', workspaceId] });
        },
    });

    const read = useMutation({
        mutationFn: (id: string) => productionApi.readNotification(workspaceId, id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications', workspaceId] }),
    });
    const readAll = useMutation({
        mutationFn: () => productionApi.readAllNotifications(workspaceId),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications', workspaceId] }),
    });

    const tabs = [
        { key: 'health' as const, icon: HeartPulse, label: ar ? 'صحة واتساب' : 'WhatsApp Health' },
        { key: 'imports' as const, icon: FileUp, label: ar ? 'استيراد العملاء' : 'Contact Imports' },
        { key: 'notifications' as const, icon: Bell, label: ar ? 'الإشعارات' : 'Notifications' },
    ];

    return (
        <div className="mx-auto max-w-7xl space-y-5 p-4 md:p-6">
            <div>
                <h1 className="text-2xl font-bold text-slate-900">{ar ? 'مركز التشغيل' : 'Operations Center'}</h1>
                <p className="mt-1 text-sm text-slate-500">
                    {ar ? 'راقب صحة واتساب والاستيراد والتنبيهات التشغيلية من مكان واحد.' : 'Monitor WhatsApp health, imports and operational alerts in one place.'}
                </p>
            </div>

            <div className="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
                {tabs.map(({ key, icon: Icon, label }) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setTab(key)}
                        className={`flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition ${
                            tab === key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        <Icon size={16} /> {label}
                    </button>
                ))}
            </div>

            {tab === 'health' && <HealthTab data={health.data} loading={health.isLoading} onRefresh={() => health.refetch()} ar={ar} />}
            {tab === 'imports' && (
                <ImportsTab
                    items={imports.data?.items ?? []}
                    loading={imports.isLoading}
                    uploaded={uploaded}
                    mapping={mapping}
                    setMapping={setMapping}
                    onFile={(file: File) => upload.mutate(file)}
                    uploading={upload.isPending}
                    onStart={() => startImport.mutate()}
                    starting={startImport.isPending}
                    ar={ar}
                />
            )}
            {tab === 'notifications' && (
                <NotificationsTab
                    items={notifications.data?.items ?? []}
                    unread={notifications.data?.meta.unread ?? 0}
                    loading={notifications.isLoading}
                    onRead={(id: string) => read.mutate(id)}
                    onReadAll={() => readAll.mutate()}
                    ar={ar}
                />
            )}
        </div>
    );
}

function HealthTab({ data, loading, onRefresh, ar }: any) {
    if (loading) return <Spinner className="mx-auto mt-16" />;
    const accounts = data?.accounts ?? [];
    const tone = data?.status === 'healthy' ? 'text-emerald-700 bg-emerald-50' : data?.status === 'critical' ? 'text-red-700 bg-red-50' : 'text-amber-700 bg-amber-50';

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4">
                <div>
                    <p className="text-sm text-slate-500">{ar ? 'الحالة العامة' : 'Overall status'}</p>
                    <p className={`mt-1 inline-flex rounded-full px-3 py-1 text-sm font-bold ${tone}`}>{data?.status ?? 'disconnected'}</p>
                </div>
                <Button variant="secondary" onClick={onRefresh}><RefreshCw size={15} /> {ar ? 'تحديث' : 'Refresh'}</Button>
            </div>

            {accounts.length === 0 && <Empty text={ar ? 'لا يوجد رقم واتساب متصل بعد.' : 'No WhatsApp account connected yet.'} />}
            <div className="grid gap-4 lg:grid-cols-2">
                {accounts.map((account: any) => (
                    <div key={account.id} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="font-bold text-slate-900">{account.verified_name || account.display_phone_number || `#${account.id}`}</p>
                                <p className="text-sm text-slate-500">{account.display_phone_number}</p>
                            </div>
                            {account.health === 'healthy' ? <CheckCircle2 className="text-emerald-600" /> : <AlertTriangle className={account.health === 'critical' ? 'text-red-600' : 'text-amber-600'} />}
                        </div>
                        <div className="mt-4 grid grid-cols-2 gap-2 text-sm">
                            <Metric label={ar ? 'الجودة' : 'Quality'} value={account.quality_rating || 'Unknown'} />
                            <Metric label={ar ? 'حد الرسائل' : 'Messaging limit'} value={account.messaging_limit || 'Unknown'} />
                            <Metric label={ar ? 'القوالب المعتمدة' : 'Approved templates'} value={account.templates.approved} />
                            <Metric label={ar ? 'فشل آخر 24 ساعة' : '24h failures'} value={account.outbound_failures_24h} />
                        </div>
                        {account.issues.length > 0 && (
                            <div className="mt-4 space-y-1 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">
                                {account.issues.map((issue: string) => <p key={issue}>• {issue}</p>)}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

function ImportsTab({ items, loading, uploaded, mapping, setMapping, onFile, uploading, onStart, starting, ar }: any) {
    return (
        <div className="space-y-4">
            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 className="font-bold text-slate-900">{ar ? 'استيراد CSV / XLSX' : 'Import CSV / XLSX'}</h2>
                <p className="mt-1 text-sm text-slate-500">{ar ? 'ارفع الملف ثم راجع ربط الأعمدة قبل التنفيذ.' : 'Upload a file, then review the field mapping before processing.'}</p>
                <label className="mt-4 flex cursor-pointer items-center justify-center rounded-xl border-2 border-dashed border-slate-300 p-5 text-sm font-semibold text-slate-600 hover:bg-slate-50">
                    {uploading ? (ar ? 'جاري القراءة…' : 'Reading…') : (ar ? 'اختيار ملف' : 'Choose file')}
                    <input
                        type="file"
                        accept=".csv,.xlsx"
                        className="hidden"
                        disabled={uploading}
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            if (file) onFile(file);
                            event.currentTarget.value = '';
                        }}
                    />
                </label>
            </div>

            {uploaded && (
                <div className="rounded-2xl border border-brand-200 bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="font-bold text-slate-900">{uploaded.import.original_filename}</p>
                            <p className="text-sm text-slate-500">{uploaded.import.total_rows} {ar ? 'صف' : 'rows'}</p>
                        </div>
                    </div>
                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                        {TARGETS.map(([target, label]) => (
                            <div key={target}>
                                <label className="mb-1 block text-xs font-semibold text-slate-500">{target === 'phone_number' ? `${label} *` : label}</label>
                                <Select value={mapping[target] ?? ''} onChange={(e) => setMapping((prev: Record<string, string>) => ({ ...prev, [target]: e.target.value }))}>
                                    <option value="">—</option>
                                    {uploaded.headers.map((header: string) => <option key={header} value={header}>{header}</option>)}
                                </Select>
                            </div>
                        ))}
                    </div>
                    <Button className="mt-4" disabled={!mapping.phone_number || starting} onClick={onStart}>
                        {starting ? (ar ? 'جاري البدء…' : 'Starting…') : (ar ? 'بدء الاستيراد' : 'Start import')}
                    </Button>
                </div>
            )}

            <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-100 px-4 py-3 font-bold text-slate-900">{ar ? 'آخر عمليات الاستيراد' : 'Recent imports'}</div>
                {loading ? <Spinner className="mx-auto my-10" /> : items.length === 0 ? <Empty text={ar ? 'لا توجد عمليات استيراد.' : 'No imports yet.'} /> : (
                    <div className="divide-y divide-slate-100">
                        {items.map((item: any) => (
                            <div key={item.id} className="p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div><p className="font-semibold text-slate-800">{item.original_filename}</p><p className="text-xs text-slate-500">{item.status}</p></div>
                                    <div className="flex gap-3 text-xs">
                                        <span className="text-emerald-700">+{item.imported_count}</span>
                                        <span className="text-blue-700">↻{item.updated_count}</span>
                                        <span className="text-slate-500">−{item.skipped_count}</span>
                                        <span className="text-red-700">!{item.failed_count}</span>
                                    </div>
                                </div>
                                {item.errors?.length > 0 && <p className="mt-2 text-xs text-red-600">{item.errors[0].message}</p>}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

function NotificationsTab({ items, unread, loading, onRead, onReadAll, ar }: any) {
    if (loading) return <Spinner className="mx-auto mt-16" />;
    return (
        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <div><p className="font-bold text-slate-900">{ar ? 'الإشعارات' : 'Notifications'}</p><p className="text-xs text-slate-500">{unread} {ar ? 'غير مقروء' : 'unread'}</p></div>
                {unread > 0 && <Button variant="secondary" size="sm" onClick={onReadAll}>{ar ? 'قراءة الكل' : 'Mark all read'}</Button>}
            </div>
            {items.length === 0 ? <Empty text={ar ? 'لا توجد إشعارات.' : 'No notifications.'} /> : (
                <div className="divide-y divide-slate-100">
                    {items.map((item: any) => (
                        <button key={item.id} type="button" onClick={() => !item.read_at && onRead(item.id)} className={`block w-full p-4 text-start hover:bg-slate-50 ${item.read_at ? '' : 'bg-brand-50/40'}`}>
                            <div className="flex items-start gap-3"><span className={`mt-1 h-2.5 w-2.5 rounded-full ${item.read_at ? 'bg-slate-300' : 'bg-brand-600'}`} /><div><p className="font-semibold text-slate-900">{item.data.title}</p><p className="mt-1 text-sm text-slate-600">{item.data.message}</p></div></div>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function Metric({ label, value }: { label: string; value: string | number }) {
    return <div className="rounded-xl bg-slate-50 p-3"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 font-bold text-slate-800">{value}</p></div>;
}

function Empty({ text }: { text: string }) {
    return <div className="p-8 text-center text-sm text-slate-500">{text}</div>;
}
