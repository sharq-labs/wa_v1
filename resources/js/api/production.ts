import { api } from '@/lib/api';

export interface WhatsAppHealthAccount {
    id: number;
    provider: string;
    display_phone_number: string | null;
    verified_name: string | null;
    connected: boolean;
    status: string;
    health: 'healthy' | 'warning' | 'critical';
    quality_rating: string | null;
    messaging_limit: string | null;
    last_sync_at: string | null;
    templates: {
        total: number;
        approved: number;
        pending: number;
        rejected: number;
        paused: number;
        disabled: number;
    };
    outbound_failures_24h: number;
    latest_failure: { id: number; error_code: string | null; error_message: string | null; failed_at: string | null } | null;
    last_webhook: { id: number; event_type: string; status: string; attempts: number; error_message: string | null; created_at: string } | null;
    issues: string[];
}

export interface WhatsAppHealth {
    status: 'healthy' | 'warning' | 'critical' | 'disconnected';
    accounts_count: number;
    connected_count: number;
    critical_count: number;
    warning_count: number;
    accounts: WhatsAppHealthAccount[];
}

export interface ContactImportRecord {
    id: number;
    original_filename: string;
    status: 'uploaded' | 'queued' | 'processing' | 'completed' | 'failed';
    headers: string[] | null;
    mapping: Record<string, string> | null;
    total_rows: number;
    imported_count: number;
    updated_count: number;
    skipped_count: number;
    failed_count: number;
    errors: { row: number | null; message: string }[] | null;
    created_at: string;
    completed_at: string | null;
}

export interface ContactImportUploadResult {
    import: ContactImportRecord;
    headers: string[];
    sample_rows: Record<string, unknown>[];
    suggested_mapping: Record<string, string>;
    supported_targets: string[];
}

export interface AppNotification {
    id: string;
    type: string;
    data: {
        workspace_id: number;
        type: string;
        title: string;
        message: string;
        url: string | null;
        severity: 'info' | 'success' | 'warning' | 'error';
        meta?: Record<string, unknown>;
    };
    read_at: string | null;
    created_at: string;
}

export interface AutomationFlowAnalytics {
    period_days: number;
    runs: { total: number; completed: number; failed: number; waiting: number; running: number };
    conversion: { converted_runs: number; rate: number };
    nodes: {
        node_id: string;
        node_type: string;
        entered: number;
        executions: number;
        failed: number;
        waiting: number;
        reach_rate: number;
        config?: { goal_name?: string } | null;
    }[];
    goals: {
        goal_name: string;
        currency: string | null;
        conversions: number;
        total_value: number | null;
        average_value: number | null;
    }[];
}

export const productionApi = {
    health: (workspaceId: number) => api.get<WhatsAppHealth>(`/api/workspaces/${workspaceId}/whatsapp-health`),

    imports: (workspaceId: number) =>
        api.get<{ items: ContactImportRecord[]; meta: { total: number } }>(`/api/workspaces/${workspaceId}/contact-imports`),
    uploadImport: (workspaceId: number, file: File) => {
        const form = new FormData();
        form.append('file', file);
        return api.upload<ContactImportUploadResult>(`/api/workspaces/${workspaceId}/contact-imports/upload`, form);
    },
    startImport: (
        workspaceId: number,
        importId: number,
        payload: {
            mapping: Record<string, string>;
            update_existing?: boolean;
            overwrite_empty?: boolean;
            tag_ids?: number[];
            whatsapp_account_id?: number | null;
        },
    ) => api.post<ContactImportRecord>(`/api/workspaces/${workspaceId}/contact-imports/${importId}/start`, payload),
    importStatus: (workspaceId: number, importId: number) =>
        api.get<ContactImportRecord>(`/api/workspaces/${workspaceId}/contact-imports/${importId}`),

    notifications: (workspaceId: number, unread = false) =>
        api.get<{ items: AppNotification[]; meta: { total: number; unread: number } }>(
            `/api/workspaces/${workspaceId}/notifications${unread ? '?unread=1' : ''}`,
        ),
    readNotification: (workspaceId: number, notificationId: string) =>
        api.post(`/api/workspaces/${workspaceId}/notifications/${notificationId}/read`),
    readAllNotifications: (workspaceId: number) => api.post(`/api/workspaces/${workspaceId}/notifications/read-all`),

    automationAnalytics: (workspaceId: number, automationId: number, days = 30) =>
        api.get<AutomationFlowAnalytics>(`/api/workspaces/${workspaceId}/automations/${automationId}/analytics?days=${days}`),
};
