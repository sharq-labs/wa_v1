import { useMutation, useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import {
    BarChart3,
    Bot,
    Inbox,
    Languages,
    LayoutDashboard,
    LayoutTemplate,
    LogOut,
    Megaphone,
    Menu,
    Moon,
    Settings,
    Shield,
    Sun,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { authApi, workspacesApi } from '@/api';
import ErrorBoundary from '@/components/ErrorBoundary';
import RouteProgress from '@/components/RouteProgress';
import { Avatar, Select } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';

function BrandMark({ size = 'md' }: { size?: 'sm' | 'md' }) {
    return (
        <div
            className={clsx(
                'flex items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-brand-800 font-bold text-white shadow-sm ring-1 ring-brand-700/20',
                size === 'sm' ? 'h-8 w-8 text-sm' : 'h-10 w-10 text-[15px]',
            )}
        >
            W
        </div>
    );
}

type NavItem = { to: string; icon: LucideIcon; label: string };

function NavGroup({ title, items, onNavigate }: { title: string; items: NavItem[]; onNavigate: () => void }) {
    if (items.length === 0) return null;

    return (
        <div className="mb-5 last:mb-0">
            <p className="mb-2 px-3 text-[13px] font-bold text-slate-400">{title}</p>
            <div className="space-y-1">
                {items.map(({ to, icon: Icon, label }) => (
                    <NavLink
                        key={to}
                        to={to}
                        end={to === '/'}
                        onClick={onNavigate}
                        className={({ isActive }) =>
                            clsx(
                                'group relative flex cursor-pointer items-center gap-3 rounded-2xl px-2.5 py-2.5 text-[16px] font-semibold transition-all duration-200',
                                isActive
                                    ? 'bg-brand-100 text-brand-950 shadow-[inset_0_0_0_1px_rgba(22,101,52,0.1)]'
                                    : 'text-slate-600 hover:bg-slate-200/70 hover:text-slate-900',
                            )
                        }
                    >
                        {({ isActive }) => (
                            <>
                                {isActive && (
                                    <span
                                        className="absolute inset-y-2 start-0 w-1 rounded-full bg-brand-600"
                                        aria-hidden="true"
                                    />
                                )}
                                <span
                                    className={clsx(
                                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition-colors duration-200',
                                        isActive
                                            ? 'bg-brand-600 text-white shadow-sm shadow-brand-700/25'
                                            : 'bg-slate-200/55 text-slate-500 group-hover:bg-slate-200 group-hover:text-slate-700',
                                    )}
                                >
                                    <Icon size={18} strokeWidth={isActive ? 2.4 : 2.15} />
                                </span>
                                <span className="truncate leading-snug">{label}</span>
                            </>
                        )}
                    </NavLink>
                ))}
            </div>
        </div>
    );
}

export default function Layout() {
    const { t, locale, setLocale, dir } = useI18n();
    const { user, workspaceId, setUser, setWorkspaceId } = useAuthStore();
    const { theme, toggleTheme } = useThemeStore();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [mobileOpen, setMobileOpen] = useState(false);

    const logout = useMutation({
        mutationFn: authApi.logout,
        onSuccess: () => {
            setUser(null);
            queryClient.clear();
            navigate('/login');
        },
    });

    const switchWorkspace = useMutation({
        mutationFn: (id: number) => workspacesApi.switch(id),
        onSuccess: (_, id) => {
            setWorkspaceId(id);
            queryClient.clear();
        },
    });

    const closeMobile = () => setMobileOpen(false);
    const workspaces = user?.workspaces ?? [];
    const workspaceName = workspaces.find((w) => w.id === workspaceId)?.name;
    const multiWorkspace = workspaces.length > 1;

    const mainItems: NavItem[] = [
        { to: '/', icon: LayoutDashboard, label: t('nav.dashboard') },
        { to: '/inbox', icon: Inbox, label: t('nav.inbox') },
        { to: '/contacts', icon: Users, label: t('nav.contacts') },
    ];

    const engageItems: NavItem[] = [
        { to: '/automations', icon: Bot, label: t('nav.automations') },
        { to: '/templates', icon: LayoutTemplate, label: t('nav.templates') },
        { to: '/campaigns', icon: Megaphone, label: t('nav.campaigns') },
    ];

    const growItems: NavItem[] = [{ to: '/analytics', icon: BarChart3, label: t('nav.analytics') }];

    const systemItems: NavItem[] = [{ to: '/settings', icon: Settings, label: t('nav.settings') }];
    if (user?.is_super_admin) {
        systemItems.push({ to: '/admin', icon: Shield, label: t('nav.admin') });
    }

    const sidebarContent = (
        <>
            <div className="relative shrink-0 overflow-hidden px-4 pt-5 pb-4">
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-0 top-0 h-28 bg-[radial-gradient(ellipse_at_top,_rgba(22,101,52,0.12),_transparent_70%)]"
                />
                <div className="relative flex items-center gap-3">
                    <BrandMark />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[17px] font-bold text-slate-900">WhatsFlow</p>
                        {workspaceName && (
                            <p className="mt-0.5 truncate text-[13.5px] font-medium text-slate-500">{workspaceName}</p>
                        )}
                    </div>
                    <button
                        onClick={closeMobile}
                        aria-label={t('common.close')}
                        className="rounded-xl p-2 text-slate-400 hover:bg-slate-200/60 hover:text-slate-700 lg:hidden"
                    >
                        <X size={18} />
                    </button>
                </div>

                {/* Only show a switcher when the user actually has more than one workspace. */}
                {multiWorkspace && (
                    <div className="relative mt-4">
                        <label className="mb-1.5 block px-0.5 text-[12.5px] font-bold text-slate-400" htmlFor="workspace-switcher">
                            {t('settings.workspace')}
                        </label>
                        <Select
                            id="workspace-switcher"
                            value={String(workspaceId ?? '')}
                            onChange={(e) => switchWorkspace.mutate(Number(e.target.value))}
                            aria-label={t('settings.workspace')}
                        >
                            {workspaces.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                )}
            </div>

            <nav className="sidebar-scroll flex-1 overflow-y-auto px-3 pb-4 pt-1">
                <NavGroup title={t('nav.group_main')} items={mainItems} onNavigate={closeMobile} />
                <NavGroup title={t('nav.group_engage')} items={engageItems} onNavigate={closeMobile} />
                <NavGroup title={t('nav.group_grow')} items={growItems} onNavigate={closeMobile} />
                <NavGroup title={t('nav.group_system')} items={systemItems} onNavigate={closeMobile} />
            </nav>

            <div className="shrink-0 border-t border-slate-300/40 p-3">
                <div className="rounded-2xl border border-slate-300/50 bg-panel p-3 shadow-card">
                    <Link
                        to="/settings/profile"
                        onClick={closeMobile}
                        className="flex cursor-pointer items-center gap-3 rounded-xl px-1 py-1.5 transition hover:bg-slate-200/50"
                    >
                        <Avatar name={user?.name} size={10} />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[15px] font-bold text-slate-800">{user?.name}</p>
                            <p className="mt-0.5 truncate text-[13px] text-slate-500">{user?.email}</p>
                        </div>
                    </Link>

                    <div className="mt-3 grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            onClick={toggleTheme}
                            className="flex cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-slate-300/50 bg-canvas px-2 py-2.5 text-[13.5px] font-bold text-slate-700 transition duration-200 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-800"
                        >
                            {theme === 'dark' ? <Sun size={15} strokeWidth={2.2} /> : <Moon size={15} strokeWidth={2.2} />}
                            {theme === 'dark' ? t('nav.light_mode') : t('nav.dark_mode')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setLocale(locale === 'en' ? 'ar' : 'en')}
                            className="flex cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-slate-300/50 bg-canvas px-2 py-2.5 text-[13.5px] font-bold text-slate-700 transition duration-200 hover:border-slate-400 hover:bg-slate-200/70 hover:text-slate-900"
                        >
                            <Languages size={15} />
                            {locale === 'en' ? 'العربية' : 'English'}
                        </button>
                    </div>

                    <button
                        type="button"
                        onClick={() => logout.mutate()}
                        disabled={logout.isPending}
                        className="mt-2 flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-rose-200/80 bg-rose-50/80 px-2 py-2.5 text-[13.5px] font-bold text-rose-700 transition duration-200 hover:bg-rose-100 hover:text-rose-800 disabled:cursor-not-allowed disabled:opacity-60 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-300 dark:hover:bg-rose-950/70"
                    >
                        <LogOut size={15} className="rtl:scale-x-[-1]" />
                        {t('nav.logout')}
                    </button>
                </div>
            </div>
        </>
    );

    return (
        <div className="flex h-screen flex-col bg-canvas lg:flex-row">
            <RouteProgress />

            <header className="flex shrink-0 items-center gap-3 border-b border-slate-300/50 bg-surface px-4 py-3 lg:hidden">
                <button
                    onClick={() => setMobileOpen(true)}
                    aria-label="Menu"
                    className="rounded-xl p-2 text-slate-500 transition-colors hover:bg-slate-200/60 hover:text-slate-900"
                >
                    <Menu size={22} />
                </button>
                <div className="flex min-w-0 items-center gap-2.5">
                    <BrandMark size="sm" />
                    <div className="min-w-0">
                        <p className="text-[15px] font-bold text-slate-900">WhatsFlow</p>
                        {workspaceName && <p className="truncate text-[13px] text-slate-500">{workspaceName}</p>}
                    </div>
                </div>
                <div className="ms-auto">
                    <Avatar name={user?.name} size={8} />
                </div>
            </header>

            <aside className="hidden w-[19rem] shrink-0 flex-col border-e border-slate-300/45 bg-surface lg:flex">
                {sidebarContent}
            </aside>

            {mobileOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="animate-fade-in absolute inset-0 bg-slate-900/40 backdrop-blur-[1px]" onClick={closeMobile} />
                    <aside
                        className={clsx(
                            'absolute inset-y-0 start-0 flex w-[20rem] max-w-[92vw] flex-col bg-surface shadow-pop',
                            dir === 'rtl' ? 'animate-drawer-in-rtl' : 'animate-drawer-in',
                        )}
                    >
                        {sidebarContent}
                    </aside>
                </div>
            )}

            <main className="min-w-0 flex-1 overflow-y-auto">
                <ErrorBoundary>
                    <Outlet />
                </ErrorBoundary>
            </main>
        </div>
    );
}
