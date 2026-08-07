import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Building2,
    CreditCard,
    Hash,
    MessageCircle,
    ScrollText,
    Tags,
    UserCircle2,
    Users,
    UsersRound,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import {
    agentsApi,
    analyticsApi,
    authApi,
    billingApi,
    customFieldsApi,
    tagsApi,
    whatsappApi,
    workspacesApi,
} from '@/api';
import {
    Alert,
    Avatar,
    Badge,
    Button,
    Input,
    Label,
    Modal,
    Pagination,
    QueryError,
    Select,
    Spinner,
    TableSkeleton,
    statusColor,
} from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useAuthStore, useWorkspaceId } from '@/stores/authStore';
import { confirmDialog } from '@/stores/confirmStore';
import { toast } from '@/stores/toastStore';
import { clsx } from 'clsx';
import type { ReactNode } from 'react';

function SettingsHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div className="min-w-0">
                <h3 className="text-[1.75rem] font-bold tracking-tight text-slate-900">{title}</h3>
                {subtitle && <p className="mt-1.5 text-[15px] leading-relaxed text-slate-500">{subtitle}</p>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

type SettingsTab = { to: string; label: string; icon: LucideIcon };
type SettingsGroup = { title: string; items: SettingsTab[] };

export default function SettingsPage() {
    const { t } = useI18n();

    const groups: SettingsGroup[] = [
        {
            title: t('settings.group_workspace'),
            items: [
                { to: 'workspace', label: t('settings.workspace'), icon: Building2 },
                { to: 'whatsapp', label: t('settings.whatsapp'), icon: MessageCircle },
            ],
        },
        {
            title: t('settings.group_people'),
            items: [
                { to: 'members', label: t('settings.members'), icon: Users },
                { to: 'teams', label: t('settings.teams'), icon: UsersRound },
            ],
        },
        {
            title: t('settings.group_data'),
            items: [
                { to: 'fields', label: t('settings.fields'), icon: Hash },
                { to: 'tags', label: t('settings.tags'), icon: Tags },
            ],
        },
        {
            title: t('settings.group_account'),
            items: [
                { to: 'billing', label: t('settings.billing'), icon: CreditCard },
                { to: 'audit', label: t('settings.audit'), icon: ScrollText },
                { to: 'profile', label: t('settings.profile'), icon: UserCircle2 },
            ],
        },
    ];

    return (
        <div className="flex h-full min-h-0 flex-col bg-canvas md:flex-row">
            <aside className="flex w-full shrink-0 gap-1 overflow-x-auto border-b border-slate-300/45 bg-surface p-2 md:w-[17.5rem] md:flex-col md:overflow-y-auto md:border-b-0 md:border-e md:p-4">
                <h2 className="mb-3 hidden px-2.5 text-[17px] font-bold text-slate-900 md:block">{t('nav.settings')}</h2>
                <div className="flex gap-1 md:block">
                    {groups.map((group) => (
                        <div key={group.title} className="contents md:block">
                            <p className="mb-1.5 hidden px-3 text-[12.5px] font-bold text-slate-400 md:block">{group.title}</p>
                            <div className="flex gap-1 md:mb-4 md:block md:space-y-1 md:last:mb-0">
                                {group.items.map(({ to, label, icon: Icon }) => (
                                    <NavLink
                                        key={to}
                                        to={`/settings/${to}`}
                                        className={({ isActive }) =>
                                            clsx(
                                                'group relative flex shrink-0 cursor-pointer items-center gap-2.5 whitespace-nowrap rounded-xl px-2.5 py-2.5 text-[15px] font-semibold transition-colors',
                                                isActive
                                                    ? 'bg-brand-100 text-brand-950 shadow-[inset_0_0_0_1px_rgba(22,101,52,0.1)]'
                                                    : 'text-slate-600 hover:bg-slate-200/60 hover:text-slate-900',
                                            )
                                        }
                                    >
                                        {({ isActive }) => (
                                            <>
                                                {isActive && (
                                                    <span
                                                        className="absolute inset-y-2 start-0 hidden w-1 rounded-full bg-brand-600 md:block"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                                <span
                                                    className={clsx(
                                                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors',
                                                        isActive
                                                            ? 'bg-brand-600 text-white shadow-sm'
                                                            : 'bg-slate-200/55 text-slate-500 group-hover:bg-slate-200 group-hover:text-slate-700',
                                                    )}
                                                >
                                                    <Icon size={16} strokeWidth={isActive ? 2.35 : 2.1} />
                                                </span>
                                                <span className="truncate">{label}</span>
                                            </>
                                        )}
                                    </NavLink>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </aside>
            <div className="min-w-0 flex-1 overflow-y-auto p-5 md:p-8 lg:p-10">
                <Routes>
                    <Route index element={<Navigate to="/settings/workspace" replace />} />
                    <Route path="workspace" element={<WorkspaceTab />} />
                    <Route path="whatsapp" element={<WhatsAppTab />} />
                    <Route path="members" element={<MembersTab />} />
                    <Route path="teams" element={<TeamsTab />} />
                    <Route path="fields" element={<FieldsTab />} />
                    <Route path="tags" element={<TagsTab />} />
                    <Route path="billing" element={<BillingTab />} />
                    <Route path="audit" element={<AuditTab />} />
                    <Route path="profile" element={<ProfileTab />} />
                </Routes>
            </div>
        </div>
    );
}

function WorkspaceTab() {
    const workspaceId = useWorkspaceId();

    const workspace = useQuery({
        queryKey: ['workspace', workspaceId],
        queryFn: async () => (await workspacesApi.get(workspaceId)).data,
    });

    // Checked before the loading branch: on a failed request `isLoading` is false
    // and `data` is undefined, which would otherwise spin forever.
    if (workspace.isError) return <QueryError onRetry={() => workspace.refetch()} />;
    if (workspace.isLoading || !workspace.data) return <Spinner />;

    // Keyed by workspace so the form re-initialises after switching tenants.
    return <WorkspaceForm key={workspace.data.id} workspace={workspace.data} />;
}

function WorkspaceForm({ workspace }: { workspace: import('@/types').Workspace }) {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();

    const [form, setForm] = useState<Record<string, string>>(() => ({
        name: workspace.name,
        timezone: workspace.timezone,
        currency: workspace.currency,
        locale: workspace.locale,
        website: workspace.website ?? '',
        industry: workspace.industry ?? '',
    }));
    const [fallbackMode, setFallbackMode] = useState(
        () => workspace.settings?.automation?.fallback_mode ?? 'none',
    );
    const [fallbackMessage, setFallbackMessage] = useState(
        () => workspace.settings?.automation?.fallback_message ?? '',
    );
    const [allowMultiple, setAllowMultiple] = useState(
        () => !!workspace.settings?.automation?.allow_multiple,
    );

    const save = useMutation({
        mutationFn: () =>
            workspacesApi.update(workspaceId, {
                ...form,
                website: form.website || null,
                industry: form.industry || null,
                settings: {
                    automation: {
                        fallback_mode: fallbackMode,
                        fallback_message: fallbackMessage,
                        allow_multiple: allowMultiple,
                    },
                },
            } as any),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['workspace', workspaceId] });
            toast.success('Workspace saved.');
        },
    });

    return (
        <div className="w-full space-y-6">
            <SettingsHeader
                title="Workspace"
                subtitle="Workspace name, locale, and automation defaults"
                actions={
                    <Button onClick={() => save.mutate()} loading={save.isPending}>
                        Save
                    </Button>
                }
            />

            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-card">
                <h4 className="mb-5 text-base font-semibold text-slate-800">General</h4>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {(['name', 'website', 'industry'] as const).map((field) => (
                        <div key={field}>
                            <Label>{field}</Label>
                            <Input value={form[field] ?? ''} onChange={(e) => setForm({ ...form, [field]: e.target.value })} />
                        </div>
                    ))}
                    <div>
                        <Label>Timezone</Label>
                        <Select value={form.timezone ?? 'UTC'} onChange={(e) => setForm({ ...form, timezone: e.target.value })}>
                            {['Africa/Cairo', 'Asia/Riyadh', 'Asia/Dubai', 'Europe/London', 'UTC'].map((tz) => (
                                <option key={tz}>{tz}</option>
                            ))}
                        </Select>
                    </div>
                    <div>
                        <Label>Currency</Label>
                        <Select value={form.currency ?? 'USD'} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
                            {['EGP', 'USD', 'SAR', 'AED', 'EUR'].map((c) => (
                                <option key={c}>{c}</option>
                            ))}
                        </Select>
                    </div>
                    <div>
                        <Label>Locale</Label>
                        <Select value={form.locale ?? 'en'} onChange={(e) => setForm({ ...form, locale: e.target.value })}>
                            <option value="en">English</option>
                            <option value="ar">العربية</option>
                        </Select>
                    </div>
                </div>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-card">
                <h4 className="mb-5 text-base font-semibold text-slate-800">Automation behaviour</h4>
                <div className="grid gap-5 lg:grid-cols-2">
                    <label className="flex items-start gap-3 rounded-xl border border-slate-100 bg-slate-50 px-4 py-4 text-[15px] text-slate-600">
                        <input
                            type="checkbox"
                            className="mt-0.5"
                            checked={allowMultiple}
                            onChange={(e) => setAllowMultiple(e.target.checked)}
                        />
                        <span>Allow multiple matching automations (default: highest priority only)</span>
                    </label>
                    <div>
                        <Label>Fallback when no automation matches</Label>
                        <Select value={fallbackMode} onChange={(e) => setFallbackMode(e.target.value)}>
                            <option value="none">Do nothing</option>
                            <option value="message">Send fallback message</option>
                        </Select>
                    </div>
                    {fallbackMode === 'message' && (
                        <div className="lg:col-span-2">
                            <Label>Fallback message</Label>
                            <Input
                                value={fallbackMessage}
                                onChange={(e) => setFallbackMessage(e.target.value)}
                                placeholder="لم أفهم طلبك. اختر من الخيارات التالية."
                            />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function WhatsAppTab() {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const { t, statusLabel } = useI18n();

    const accounts = useQuery({
        queryKey: ['wa-accounts', workspaceId],
        queryFn: async () => (await whatsappApi.accounts(workspaceId)).data,
    });

    const signupConfig = useQuery({
        queryKey: ['meta-signup-config', workspaceId],
        queryFn: async () => (await whatsappApi.embeddedSignupConfig(workspaceId)).data,
    });

    const connectFake = useMutation({
        mutationFn: () => whatsappApi.connectFake(workspaceId),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['wa-accounts', workspaceId] }),
    });

    const disconnect = useMutation({
        mutationFn: (id: number) => whatsappApi.disconnect(workspaceId, id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['wa-accounts', workspaceId] }),
    });

    // The synced templates live on another page, so nothing here would change.
    const sync = useMutation({
        mutationFn: (id: number) => whatsappApi.syncTemplates(workspaceId, id),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['templates', workspaceId] });
            toast.success('Templates synced.');
        },
    });

    // Embedded Signup hands off to a Meta-hosted dialog that only loads on the
    // registered production domain, so the button cannot do anything here.
    const embeddedSignup = signupConfig.data?.enabled === true;

    return (
        <div className="w-full space-y-5">
            <SettingsHeader
                title={t('settings.whatsapp')}
                subtitle="Connected numbers and messaging quality"
                actions={
                    embeddedSignup ? (
                        <Button disabled>{t('settings.connect_whatsapp')}</Button>
                    ) : (
                        <Button onClick={() => connectFake.mutate()} loading={connectFake.isPending}>
                            {t('settings.connect_whatsapp')} (sandbox)
                        </Button>
                    )
                }
            />

            {embeddedSignup && (
                <Alert tone="info" title={t('settings.embedded_signup_title')}>
                    {t('settings.embedded_signup_desc')}
                </Alert>
            )}

            {signupConfig.isError && (
                <Alert
                    tone="danger"
                    title={t('settings.signup_config_failed')}
                    action={
                        <Button size="sm" variant="secondary" onClick={() => signupConfig.refetch()}>
                            {t('common.retry')}
                        </Button>
                    }
                >
                    {t('settings.signup_config_failed_desc')}
                </Alert>
            )}

            {accounts.isError ? (
                <QueryError onRetry={() => accounts.refetch()} />
            ) : accounts.isLoading ? (
                <Spinner />
            ) : (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {(accounts.data ?? []).map((account) => (
                        <div key={account.id} className="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                            <div>
                                <p className="text-lg font-semibold text-slate-900">{account.display_phone_number}</p>
                                <p className="mt-1 text-sm text-slate-500">{account.verified_name}</p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <Badge color={statusColor(account.status)}>{statusLabel(account.status)}</Badge>
                                    {account.quality_rating && <Badge color="green">Quality: {account.quality_rating}</Badge>}
                                    {account.messaging_limit && <Badge color="blue">{account.messaging_limit}</Badge>}
                                </div>
                            </div>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    loading={sync.isPending && sync.variables === account.id}
                                    onClick={() => sync.mutate(account.id)}
                                >
                                    Sync templates
                                </Button>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    loading={disconnect.isPending && disconnect.variables === account.id}
                                    onClick={async () => {
                                        const confirmed = await confirmDialog({
                                            title: t('settings.disconnect_title'),
                                            description: t('settings.disconnect_desc'),
                                            confirmLabel: t('settings.disconnect'),
                                            destructive: true,
                                        });
                                        if (confirmed) disconnect.mutate(account.id);
                                    }}
                                >
                                    {t('settings.disconnect')}
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function MembersTab() {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const { t, statusLabel } = useI18n();
    const [inviteOpen, setInviteOpen] = useState(false);
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('agent');

    const members = useQuery({
        queryKey: ['members', workspaceId],
        queryFn: async () => (await workspacesApi.members(workspaceId)).data,
    });
    const invitations = useQuery({
        queryKey: ['invitations', workspaceId],
        queryFn: async () => (await workspacesApi.invitations(workspaceId)).data,
    });

    const invite = useMutation({
        mutationFn: () => workspacesApi.invite(workspaceId, { email, role }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['invitations', workspaceId] });
            setInviteOpen(false);
            toast.success('Invitation sent.', email);
            setEmail('');
        },
    });

    const updateRole = useMutation({
        mutationFn: ({ userId, newRole }: { userId: number; newRole: string }) =>
            workspacesApi.updateMemberRole(workspaceId, userId, newRole),
        onSuccess: (_data, variables) => {
            queryClient.invalidateQueries({ queryKey: ['members', workspaceId] });
            toast.success('Role updated.', variables.newRole);
        },
    });

    const removeMember = useMutation({
        mutationFn: (userId: number) => workspacesApi.removeMember(workspaceId, userId),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['members', workspaceId] }),
    });

    return (
        <div className="w-full space-y-5">
            <SettingsHeader
                title="Members"
                subtitle="Invite teammates and manage roles"
                actions={<Button onClick={() => setInviteOpen(true)}>Invite member</Button>}
            />

            {members.isError ? (
                <QueryError onRetry={() => members.refetch()} />
            ) : members.isLoading ? (
                <TableSkeleton rows={4} columns={4} />
            ) : (
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                    <div className="hidden grid-cols-[minmax(0,1.4fr)_140px_160px_120px] gap-4 border-b border-slate-100 bg-slate-50 px-5 py-3 text-xs font-semibold tracking-wide text-slate-500 uppercase sm:grid">
                        <span>Member</span>
                        <span>Status</span>
                        <span>Role</span>
                        <span className="text-end">Actions</span>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {members.data?.map((member: any) => (
                            <div
                                key={member.id}
                                className="grid grid-cols-1 items-center gap-4 px-5 py-4 sm:grid-cols-[minmax(0,1.4fr)_140px_160px_120px] sm:gap-4"
                            >
                                <div className="flex min-w-0 items-center gap-3.5">
                                    <Avatar name={member.name} size={11} />
                                    <div className="min-w-0">
                                        <p className="truncate text-base font-semibold text-slate-900">{member.name}</p>
                                        <p className="truncate text-sm text-slate-500">{member.email}</p>
                                    </div>
                                </div>
                                <div>
                                    {member.agent_profile ? (
                                        <Badge color={statusColor(member.agent_profile.status)}>
                                            {statusLabel(member.agent_profile.status)}
                                        </Badge>
                                    ) : (
                                        <span className="text-sm text-slate-400">—</span>
                                    )}
                                </div>
                                <div>
                                    <Select
                                        value={member.role}
                                        disabled={member.role === 'owner'}
                                        onChange={(e) => updateRole.mutate({ userId: member.id, newRole: e.target.value })}
                                        className="w-full !py-2 text-sm"
                                    >
                                        {['owner', 'admin', 'manager', 'agent', 'viewer'].map((r) => (
                                            <option key={r} value={r} disabled={r === 'owner'}>
                                                {r}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                                <div className="sm:text-end">
                                    {member.role !== 'owner' ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            loading={removeMember.isPending && removeMember.variables === member.id}
                                            onClick={async () => {
                                                const confirmed = await confirmDialog({
                                                    title: `Remove ${member.name} from this workspace?`,
                                                    description:
                                                        'They lose access immediately. Conversations they handled stay in the workspace.',
                                                    confirmLabel: 'Remove',
                                                    destructive: true,
                                                });
                                                if (confirmed) removeMember.mutate(member.id);
                                            }}
                                        >
                                            Remove
                                        </Button>
                                    ) : (
                                        <span className="text-sm text-slate-400">Owner</span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {invitations.isError && (
                <Alert
                    tone="danger"
                    title="Pending invitations could not be loaded."
                    action={
                        <Button size="sm" variant="secondary" onClick={() => invitations.refetch()}>
                            {t('common.retry')}
                        </Button>
                    }
                />
            )}

            {(invitations.data ?? []).length > 0 && (
                <div className="space-y-3">
                    <h4 className="text-base font-semibold text-slate-800">Pending invitations</h4>
                    <div className="grid gap-3 md:grid-cols-2">
                        {invitations.data!.map((invitation: any) => (
                            <div
                                key={invitation.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4"
                            >
                                <span className="flex flex-wrap items-center gap-2 text-[15px] text-slate-800">
                                    {invitation.email} <Badge color="yellow">{invitation.role}</Badge>
                                </span>
                                <code className="rounded-lg bg-slate-100 px-2 py-1 text-xs text-slate-600">
                                    token: {invitation.token}
                                </code>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <Modal open={inviteOpen} onClose={() => setInviteOpen(false)} title="Invite member">
                <div className="space-y-3">
                    <div>
                        <Label>Email</Label>
                        <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                    </div>
                    <div>
                        <Label>Role</Label>
                        <Select value={role} onChange={(e) => setRole(e.target.value)}>
                            {['admin', 'manager', 'agent', 'viewer'].map((r) => (
                                <option key={r}>{r}</option>
                            ))}
                        </Select>
                    </div>
                    <Button onClick={() => invite.mutate()} disabled={!email} loading={invite.isPending} className="w-full">
                        Send invitation
                    </Button>
                </div>
            </Modal>
        </div>
    );
}

function TeamsTab() {
    const { t } = useI18n();
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<any>(null);
    const [name, setName] = useState('');
    const [strategy, setStrategy] = useState('round_robin');
    const [memberIds, setMemberIds] = useState<number[]>([]);

    const teams = useQuery({ queryKey: ['teams', workspaceId], queryFn: async () => (await agentsApi.teams(workspaceId)).data });
    const members = useQuery({
        queryKey: ['members', workspaceId],
        queryFn: async () => (await workspacesApi.members(workspaceId)).data,
    });

    const save = useMutation({
        mutationFn: () => {
            const body = { name, assignment_strategy: strategy, member_ids: memberIds };
            return editing ? agentsApi.updateTeam(workspaceId, editing.id, body) : agentsApi.createTeam(workspaceId, body);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['teams', workspaceId] });
            setModalOpen(false);
            toast.success(editing ? t('settings.edit_team') : t('settings.create_team'), name);
        },
    });

    const remove = useMutation({
        mutationFn: (id: number) => agentsApi.removeTeam(workspaceId, id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['teams', workspaceId] }),
    });

    const openModal = (team?: any) => {
        setEditing(team ?? null);
        setName(team?.name ?? '');
        setStrategy(team?.assignment_strategy ?? 'round_robin');
        setMemberIds(team?.members?.map((m: any) => m.id) ?? []);
        setModalOpen(true);
    };

    return (
        <div className="w-full space-y-5">
            <SettingsHeader
                title={t('settings.teams')}
                subtitle={t('settings.teams_subtitle')}
                actions={<Button onClick={() => openModal()}>{t('settings.create_team')}</Button>}
            />

            {teams.isError && <QueryError onRetry={() => teams.refetch()} />}
            {teams.isLoading && <Spinner />}

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {teams.data?.map((team) => (
                    <div key={team.id} className="flex flex-col justify-between rounded-2xl border border-slate-200 bg-panel p-5 shadow-card">
                        <div>
                            <p className="text-lg font-semibold text-slate-900">{team.name}</p>
                            <p className="mt-1.5 text-sm text-slate-500">
                                {team.assignment_strategy} · {t('settings.members_count', { count: team.members?.length ?? 0 })}
                            </p>
                            {team.members && team.members.length > 0 && (
                                <p className="mt-2 line-clamp-2 text-sm text-slate-400">
                                    {team.members.map((m) => m.name).join(', ')}
                                </p>
                            )}
                        </div>
                        <div className="mt-5 flex gap-2">
                            <Button size="sm" variant="secondary" onClick={() => openModal(team)}>
                                {t('settings.edit')}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                loading={remove.isPending && remove.variables === team.id}
                                onClick={async () => {
                                    const confirmed = await confirmDialog({
                                        title: `Delete the team "${team.name}"?`,
                                        description:
                                            'Conversations already assigned to its members stay, but routing rules pointing at this team stop working.',
                                        confirmLabel: t('settings.delete_team'),
                                        destructive: true,
                                    });
                                    if (confirmed) remove.mutate(team.id);
                                }}
                            >
                                {t('settings.delete_team')}
                            </Button>
                        </div>
                    </div>
                ))}
            </div>

            <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? t('settings.edit_team') : t('settings.create_team')}>
                <div className="space-y-3">
                    <div>
                        <Label>Name</Label>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Sales" />
                    </div>
                    <div>
                        <Label>Assignment strategy</Label>
                        <Select value={strategy} onChange={(e) => setStrategy(e.target.value)}>
                            <option value="round_robin">Round robin</option>
                            <option value="least_active">Least active</option>
                        </Select>
                    </div>
                    <div>
                        <Label>Members</Label>
                        {members.isError && (
                            <div className="mb-2">
                                <Alert tone="danger" title="Members could not be loaded." />
                            </div>
                        )}
                        <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                            {members.isLoading && <Spinner className="!p-3" />}
                            {members.data?.map((member: any) => (
                                <label key={member.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={memberIds.includes(member.id)}
                                        onChange={(e) =>
                                            setMemberIds(
                                                e.target.checked
                                                    ? [...memberIds, member.id]
                                                    : memberIds.filter((id) => id !== member.id),
                                            )
                                        }
                                    />
                                    {member.name}
                                </label>
                            ))}
                        </div>
                    </div>
                    <Button onClick={() => save.mutate()} disabled={!name} loading={save.isPending} className="w-full">
                        Save
                    </Button>
                </div>
            </Modal>
        </div>
    );
}

function FieldsTab() {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const [name, setName] = useState('');
    const [type, setType] = useState('text');

    const fields = useQuery({
        queryKey: ['custom-fields', workspaceId],
        queryFn: async () => (await customFieldsApi.list(workspaceId)).data,
    });

    const create = useMutation({
        mutationFn: () => customFieldsApi.create(workspaceId, { name, type }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['custom-fields', workspaceId] });
            toast.success('Custom field created.', name);
            setName('');
        },
    });

    const remove = useMutation({
        mutationFn: (id: number) => customFieldsApi.remove(workspaceId, id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['custom-fields', workspaceId] }),
    });

    return (
        <div className="w-full space-y-5">
            <SettingsHeader title="Custom fields" subtitle="Extra attributes stored on contacts" />
            <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-card sm:flex-row sm:items-center">
                <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Field name (e.g. Budget)" className="flex-1" />
                <Select value={type} onChange={(e) => setType(e.target.value)} className="sm:w-48">
                    {['text', 'textarea', 'number', 'date', 'datetime', 'boolean', 'select', 'multi_select', 'email', 'phone'].map(
                        (fieldType) => (
                            <option key={fieldType}>{fieldType}</option>
                        ),
                    )}
                </Select>
                <Button onClick={() => create.mutate()} disabled={!name} loading={create.isPending}>
                    Add
                </Button>
            </div>
            {fields.isError ? (
                <QueryError onRetry={() => fields.refetch()} />
            ) : fields.isLoading ? (
                <TableSkeleton rows={4} columns={4} />
            ) : (
                <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-card">
                    <table className="w-full text-[15px]">
                        <thead className="bg-slate-50 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th className="px-5 py-3.5 text-start">Name</th>
                                <th className="px-5 py-3.5 text-start">Key</th>
                                <th className="px-5 py-3.5 text-start">Type</th>
                                <th className="px-5 py-3.5 text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {fields.data?.map((field) => (
                                <tr key={field.id} className="border-t border-slate-100">
                                    <td className="px-5 py-4 font-semibold text-slate-900">{field.name}</td>
                                    <td className="px-5 py-4">
                                        <code className="rounded-lg bg-slate-100 px-2 py-1 text-xs">custom.{field.key}</code>
                                    </td>
                                    <td className="px-5 py-4">
                                        <Badge color="slate">{field.type}</Badge>
                                    </td>
                                    <td className="px-5 py-4 text-end">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            loading={remove.isPending && remove.variables === field.id}
                                            onClick={async () => {
                                                const confirmed = await confirmDialog({
                                                    title: `Delete the custom field "${field.name}"?`,
                                                    description:
                                                        'Every value stored on your contacts for this field is deleted permanently. This cannot be undone.',
                                                    confirmLabel: 'Delete',
                                                    destructive: true,
                                                });
                                                if (confirmed) remove.mutate(field.id);
                                            }}
                                        >
                                            Delete
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function TagsTab() {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const [name, setName] = useState('');
    const [color, setColor] = useState('#22c55e');

    const tags = useQuery({ queryKey: ['tags', workspaceId], queryFn: async () => (await tagsApi.list(workspaceId)).data });

    const create = useMutation({
        mutationFn: () => tagsApi.create(workspaceId, { name, color }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['tags', workspaceId] });
            toast.success('Tag created.', name);
            setName('');
        },
    });

    const remove = useMutation({
        mutationFn: (id: number) => tagsApi.remove(workspaceId, id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['tags', workspaceId] }),
    });

    return (
        <div className="w-full space-y-5">
            <SettingsHeader title="Tags" subtitle="Label contacts for filtering and campaigns" />
            <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-card sm:flex-row sm:items-center">
                <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Tag name" className="flex-1" />
                <input type="color" value={color} onChange={(e) => setColor(e.target.value)} className="h-11 w-14 rounded-xl border border-slate-300" />
                <Button onClick={() => create.mutate()} disabled={!name} loading={create.isPending}>
                    Add
                </Button>
            </div>
            {tags.isError && <QueryError onRetry={() => tags.refetch()} />}
            {tags.isLoading && <Spinner />}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {tags.data?.map((tag) => (
                    <div
                        key={tag.id}
                        className="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-card"
                    >
                        <span className="flex items-center gap-2.5 text-[15px] font-semibold" style={{ color: tag.color }}>
                            <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: tag.color }} />
                            {tag.name}
                            <span className="text-sm font-normal text-slate-400">({tag.contacts_count ?? 0})</span>
                        </span>
                        <button
                            aria-label={`Delete ${tag.name}`}
                            disabled={remove.isPending && remove.variables === tag.id}
                            onClick={async () => {
                                const confirmed = await confirmDialog({
                                    title: `Delete the tag "${tag.name}"?`,
                                    description: `It is removed from ${tag.contacts_count ?? 0} contact(s). This cannot be undone.`,
                                    confirmLabel: 'Delete',
                                    destructive: true,
                                });
                                if (confirmed) remove.mutate(tag.id);
                            }}
                            className="text-base text-slate-400 hover:text-red-500 disabled:opacity-50"
                        >
                            ✕
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}

function BillingTab() {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const { t } = useI18n();

    const summary = useQuery({
        queryKey: ['billing', workspaceId],
        queryFn: async () => (await billingApi.summary(workspaceId)).data,
    });
    const plans = useQuery({ queryKey: ['plans'], queryFn: async () => (await billingApi.plans()).data });

    const subscribe = useMutation({
        mutationFn: (planId: number) => billingApi.subscribe(workspaceId, planId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['billing', workspaceId] });
            toast.success('Subscription updated.');
        },
    });

    if (summary.isError) return <QueryError onRetry={() => summary.refetch()} />;
    if (summary.isLoading) return <Spinner />;

    const platform = summary.data?.platform;
    const price = (cents: number, currency: string) => `${(cents / 100).toLocaleString()} ${currency}`;

    return (
        <div className="w-full space-y-6">
            <SettingsHeader title={t('settings.billing')} subtitle="Plan, usage limits, and Meta billing note" />

            <div className="grid gap-4 lg:grid-cols-3">
                {/* Platform subscription — OUR revenue */}
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-card lg:col-span-2">
                    <h4 className="mb-2 text-base font-bold text-slate-800">{t('billing.platform')}</h4>
                    {platform?.plan ? (
                        <>
                            <p className="text-2xl font-semibold text-slate-900">
                                {platform.plan.name}
                                <span className="ms-2 text-sm font-normal text-slate-500">
                                    {price(platform.plan.price_monthly, platform.plan.currency)} / month
                                </span>
                            </p>
                            <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                                {Object.entries(platform.usage ?? {}).map(([key, value]: [string, any]) => (
                                    <div key={key} className="rounded-lg bg-slate-50 p-3 text-xs">
                                        <p className="text-slate-500">{key.replaceAll('_', ' ')}</p>
                                        <p className="mt-0.5 font-semibold text-slate-800">
                                            {value.used} / {value.limit === null ? '∞' : value.limit || '—'}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </>
                    ) : (
                        <p className="text-sm text-slate-500">No active subscription.</p>
                    )}
                </div>

                {/* Meta usage — separate financial concept, NOT platform revenue */}
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                    <h4 className="mb-2 text-base font-bold text-amber-800">{t('billing.meta_usage')}</h4>
                    <p className="text-sm leading-relaxed text-amber-700">
                        {summary.data?.meta_usage?.note ?? t('billing.meta_note')}
                    </p>
                </div>
            </div>

            <div>
                <h4 className="mb-4 text-base font-semibold text-slate-800">Plans</h4>
                {plans.isError && (
                    <Alert
                        tone="danger"
                        title="Plans could not be loaded."
                        action={
                            <Button size="sm" variant="secondary" onClick={() => plans.refetch()}>
                                {t('common.retry')}
                            </Button>
                        }
                    />
                )}
                {plans.isLoading && <Spinner />}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {plans.data?.map((plan) => {
                        const isCurrent = platform?.plan?.id === plan.id;
                        return (
                            <div
                                key={plan.id}
                                className={clsx(
                                    'flex flex-col rounded-2xl border bg-white p-6 shadow-card',
                                    isCurrent ? 'border-brand-500 ring-2 ring-brand-100' : 'border-slate-200',
                                )}
                            >
                                <p className="text-lg font-bold text-slate-900">{plan.name}</p>
                                <p className="my-2 text-2xl font-semibold">
                                    {price(plan.price_monthly, plan.currency)}
                                    <span className="text-sm font-normal text-slate-400">/mo</span>
                                </p>
                                <ul className="mb-5 flex-1 space-y-1.5 text-sm text-slate-500">
                                    {plan.features.map((feature) => (
                                        <li key={feature.key}>
                                            {feature.key.replaceAll('_', ' ')}: <b>{feature.value}</b>
                                        </li>
                                    ))}
                                </ul>
                                <Button
                                    size="sm"
                                    variant={isCurrent ? 'secondary' : 'primary'}
                                    disabled={isCurrent || subscribe.isPending}
                                    loading={subscribe.isPending && subscribe.variables === plan.id}
                                    onClick={() => subscribe.mutate(plan.id)}
                                    className="w-full"
                                >
                                    {isCurrent ? t('billing.current_plan') : t('billing.upgrade')}
                                </Button>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function AuditTab() {
    const workspaceId = useWorkspaceId();
    const { t } = useI18n();
    const [page, setPage] = useState(1);

    const logs = useQuery({
        queryKey: ['audit-logs', workspaceId, page],
        queryFn: async () => (await analyticsApi.auditLogs(workspaceId, page)).data,
    });

    return (
        <div className="w-full space-y-5">
            <SettingsHeader title={t('settings.audit')} subtitle="Recent workspace activity" />
            {logs.isError ? (
                <QueryError onRetry={() => logs.refetch()} />
            ) : logs.isLoading ? (
                <TableSkeleton rows={6} columns={3} />
            ) : (
                <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-card">
                    <table className="w-full text-[15px]">
                        <thead className="bg-slate-50 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th className="px-5 py-3.5 text-start">Action</th>
                                <th className="px-5 py-3.5 text-start">User</th>
                                <th className="px-5 py-3.5 text-start">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data?.items.map((log: any) => (
                                <tr key={log.id} className="border-t border-slate-100">
                                    <td className="px-5 py-4">
                                        <code className="rounded-lg bg-slate-100 px-2 py-1 text-xs">{log.action}</code>
                                    </td>
                                    <td className="px-5 py-4 text-slate-700">{log.user?.name ?? 'System'}</td>
                                    <td className="px-5 py-4 text-sm text-slate-500">{new Date(log.created_at).toLocaleString()}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {logs.data && (
                        <Pagination
                            page={logs.data.meta.current_page}
                            lastPage={logs.data.meta.last_page}
                            total={logs.data.meta.total}
                            onChange={setPage}
                        />
                    )}
                </div>
            )}
        </div>
    );
}

function ProfileTab() {
    const { user, setUser } = useAuthStore();
    const [name, setName] = useState(user?.name ?? '');
    const [passwords, setPasswords] = useState({ current_password: '', password: '', password_confirmation: '' });

    const saveProfile = useMutation({
        mutationFn: () => authApi.updateProfile({ name }),
        onSuccess: (response) => {
            setUser({ ...user!, ...response.data.user });
            toast.success('Profile saved.');
        },
    });

    // Failures are surfaced by the global MutationCache error toast.
    const changePassword = useMutation({
        mutationFn: () => authApi.changePassword(passwords),
        onSuccess: () => {
            setPasswords({ current_password: '', password: '', password_confirmation: '' });
            toast.success('Password changed.');
        },
    });

    return (
        <div className="w-full space-y-5">
            <SettingsHeader title="Profile" subtitle="Your account details and password" />
            <div className="grid gap-5 lg:grid-cols-2">
                <div className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-card">
                    <h4 className="text-base font-semibold text-slate-800">Account</h4>
                    <div>
                        <Label>Name</Label>
                        <Input value={name} onChange={(e) => setName(e.target.value)} />
                    </div>
                    <div>
                        <Label>Email</Label>
                        <Input value={user?.email ?? ''} disabled />
                    </div>
                    <Button onClick={() => saveProfile.mutate()} loading={saveProfile.isPending}>
                        Save
                    </Button>
                </div>
                <div className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-card">
                    <h4 className="text-base font-semibold text-slate-800">Change password</h4>
                    {(['current_password', 'password', 'password_confirmation'] as const).map((field) => (
                        <div key={field}>
                            <Label>{field.replaceAll('_', ' ')}</Label>
                            <Input
                                type="password"
                                value={passwords[field]}
                                onChange={(e) => setPasswords({ ...passwords, [field]: e.target.value })}
                            />
                        </div>
                    ))}
                    <Button onClick={() => changePassword.mutate()} loading={changePassword.isPending}>
                        Change password
                    </Button>
                </div>
            </div>
        </div>
    );
}
