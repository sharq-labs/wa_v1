import { MutationCache, QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { authApi } from '@/api';
import ConfirmDialogHost from '@/components/ConfirmDialogHost';
import Layout from '@/components/Layout';
import Toaster from '@/components/Toaster';
import { ApiError } from '@/lib/api';
import { I18nProvider } from '@/lib/i18n';
import { en } from '@/locales/en';
import { ar } from '@/locales/ar';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import { describeError, toast } from '@/stores/toastStore';
import { Spinner } from '@/components/ui';

// Ensure theme class is applied as soon as the client bundle boots.
useThemeStore.getState();

import Login from '@/pages/auth/Login';
import Register from '@/pages/auth/Register';
import ForgotPassword from '@/pages/auth/ForgotPassword';
import ResetPassword from '@/pages/auth/ResetPassword';
import Onboarding from '@/pages/Onboarding';
import Dashboard from '@/pages/Dashboard';
import InboxPage from '@/pages/inbox/InboxPage';
import ContactsPage from '@/pages/contacts/ContactsPage';
import AutomationsPage from '@/pages/automations/AutomationsPage';
import AutomationBuilder from '@/pages/automations/AutomationBuilder';
import AutomationRuns from '@/pages/automations/AutomationRuns';
import TemplatesPage from '@/pages/templates/TemplatesPage';
import CampaignsPage from '@/pages/campaigns/CampaignsPage';
import AnalyticsPage from '@/pages/AnalyticsPage';
import SettingsPage from '@/pages/settings/SettingsPage';
import AdminPage from '@/pages/admin/AdminPage';

/**
 * The toast layer lives outside React, so it cannot use the i18n context. The
 * locale lookup is the same one `I18nProvider` performs on boot.
 */
function translate(key: string): string {
    const locale = localStorage.getItem('locale') === 'ar' ? ar : en;
    return locale[key] ?? en[key] ?? key;
}

const queryClient = new QueryClient({
    defaultOptions: {
        queries: { retry: 1, staleTime: 15_000, refetchOnWindowFocus: false },
    },
    /**
     * Every mutation reports its own failure. Without this, a rejected save is
     * indistinguishable from a successful one — the button simply re-enables.
     * Individual mutations can still add an `onError` for bespoke handling;
     * this only guarantees the user always hears about it.
     */
    mutationCache: new MutationCache({
        onError: (error, _variables, _context, mutation) => {
            if (mutation.options.meta?.silent) return;

            // A 401 means the session expired; the auth gate already redirects.
            if (error instanceof ApiError && error.status === 401) return;

            const { title, description } = describeError(error, translate('common.action_failed'));
            toast.error(title, description);
        },
    }),
});

function Bootstrapped() {
    const { user, initialized, setUser, setInitialized } = useAuthStore();
    const location = useLocation();

    useQuery({
        queryKey: ['auth', 'me'],
        queryFn: async () => {
            try {
                const response = await authApi.me();
                setUser(response.data.user);
                return response.data.user;
            } catch {
                setUser(null);
                return null;
            } finally {
                setInitialized(true);
            }
        },
        staleTime: Infinity,
        retry: false,
    });

    if (!initialized) {
        return (
            <div className="flex h-screen items-center justify-center">
                <Spinner />
            </div>
        );
    }

    const guestRoutes = (
        <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/register" element={<Register />} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/reset-password" element={<ResetPassword />} />
            <Route path="*" element={<Navigate to="/login" state={{ from: location }} replace />} />
        </Routes>
    );

    if (!user) return guestRoutes;

    const activeWorkspace = user.workspaces?.find((w) => w.id === useAuthStore.getState().workspaceId);
    const needsOnboarding = activeWorkspace && !activeWorkspace.onboarded_at && activeWorkspace.role === 'owner';

    return (
        <Routes>
            <Route path="/onboarding" element={<Onboarding />} />
            {needsOnboarding && <Route path="*" element={<Navigate to="/onboarding" replace />} />}
            <Route element={<Layout />}>
                <Route path="/" element={<Dashboard />} />
                <Route path="/inbox" element={<InboxPage />} />
                <Route path="/inbox/:conversationId" element={<InboxPage />} />
                <Route path="/contacts" element={<ContactsPage />} />
                <Route path="/automations" element={<AutomationsPage />} />
                <Route path="/automations/:automationId/runs" element={<AutomationRuns />} />
                <Route path="/templates" element={<TemplatesPage />} />
                <Route path="/campaigns" element={<CampaignsPage />} />
                <Route path="/analytics" element={<AnalyticsPage />} />
                <Route path="/settings/*" element={<SettingsPage />} />
                <Route path="/admin/*" element={<AdminPage />} />
            </Route>
            <Route path="/automations/:automationId" element={<AutomationBuilder />} />
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}

const container = document.getElementById('root') as (HTMLElement & { _reactRoot?: ReturnType<typeof createRoot> }) | null;

if (container) {
    // Reuse the same React root across Vite HMR updates of this entry module.
    const root = (container._reactRoot ??= createRoot(container));
    root.render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <I18nProvider>
                    <BrowserRouter>
                        <Bootstrapped />
                    </BrowserRouter>
                    <Toaster />
                    <ConfirmDialogHost />
                </I18nProvider>
            </QueryClientProvider>
        </StrictMode>,
    );
}
