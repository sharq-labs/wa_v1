import { api } from '@/lib/api';
import type {
    AgentProfile,
    AgentTeam,
    Automation,
    AutomationRun,
    Campaign,
    Contact,
    Conversation,
    CustomField,
    FlowDefinition,
    Message,
    PaginatedResponse,
    Plan,
    Segment,
    SimulationState,
    Tag,
    User,
    WhatsAppAccount,
    WhatsAppTemplate,
    Workspace,
} from '@/types';

const ws = (id: number) => `/api/workspaces/${id}`;

export const authApi = {
    me: () => api.get<{ user: User }>('/api/auth/me'),
    login: (body: { email: string; password: string; remember?: boolean }) =>
        api.post<{ user: User }>('/api/auth/login', body),
    register: (body: {
        name: string;
        email: string;
        password: string;
        password_confirmation: string;
        workspace_name?: string;
    }) => api.post<{ user: User }>('/api/auth/register', body),
    logout: () => api.post('/api/auth/logout'),
    forgotPassword: (email: string) => api.post('/api/auth/forgot-password', { email }),
    resetPassword: (body: { token: string; email: string; password: string; password_confirmation: string }) =>
        api.post('/api/auth/reset-password', body),
    updateProfile: (body: Partial<Pick<User, 'name' | 'email' | 'locale'>>) =>
        api.put<{ user: User }>('/api/auth/profile', body),
    changePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
        api.put('/api/auth/password', body),
    resendVerification: () => api.post('/api/auth/email/resend'),
};

export const workspacesApi = {
    list: () => api.get<Workspace[]>('/api/workspaces'),
    create: (body: { name: string; timezone?: string }) => api.post<Workspace>('/api/workspaces', body),
    get: (id: number) => api.get<Workspace>(ws(id)),
    update: (id: number, body: Partial<Workspace>) => api.put<Workspace>(ws(id), body),
    remove: (id: number) => api.delete(ws(id)),
    switch: (id: number) => api.post<Workspace>(`${ws(id)}/switch`),
    completeOnboarding: (id: number) => api.post<Workspace>(`${ws(id)}/complete-onboarding`),
    members: (id: number) => api.get<any[]>(`${ws(id)}/members`),
    updateMemberRole: (id: number, userId: number, role: string) =>
        api.put(`${ws(id)}/members/${userId}/role`, { role }),
    removeMember: (id: number, userId: number) => api.delete(`${ws(id)}/members/${userId}`),
    invitations: (id: number) => api.get<any[]>(`${ws(id)}/invitations`),
    invite: (id: number, body: { email: string; role: string }) => api.post(`${ws(id)}/invitations`, body),
    revokeInvitation: (id: number, invitationId: number) => api.delete(`${ws(id)}/invitations/${invitationId}`),
    acceptInvitation: (token: string) => api.post('/api/invitations/accept', { token }),
};

export const whatsappApi = {
    accounts: (id: number) => api.get<WhatsAppAccount[]>(`${ws(id)}/whatsapp-accounts`),
    connectFake: (id: number, body: { display_phone_number?: string; verified_name?: string } = {}) =>
        api.post<WhatsAppAccount>(`${ws(id)}/whatsapp-accounts/connect-fake`, body),
    disconnect: (id: number, accountId: number) => api.delete(`${ws(id)}/whatsapp-accounts/${accountId}`),
    syncTemplates: (id: number, accountId: number) =>
        api.post(`${ws(id)}/whatsapp-accounts/${accountId}/sync-templates`),
    embeddedSignupConfig: (id: number) => api.get<any>(`${ws(id)}/meta/embedded-signup/config`),
};

export const contactsApi = {
    list: (id: number, params: Record<string, string> = {}) =>
        api.get<PaginatedResponse<Contact>>(`${ws(id)}/contacts?${new URLSearchParams(params)}`),
    get: (id: number, contactId: number) => api.get<Contact>(`${ws(id)}/contacts/${contactId}`),
    create: (id: number, body: Record<string, unknown>) => api.post<Contact>(`${ws(id)}/contacts`, body),
    update: (id: number, contactId: number, body: Record<string, unknown>) =>
        api.put<Contact>(`${ws(id)}/contacts/${contactId}`, body),
    remove: (id: number, contactId: number) => api.delete(`${ws(id)}/contacts/${contactId}`),
    setStatus: (id: number, contactId: number, status: string) =>
        api.put<Contact>(`${ws(id)}/contacts/${contactId}/status`, { status }),
    syncTags: (id: number, contactId: number, tagIds: number[]) =>
        api.put<Contact>(`${ws(id)}/contacts/${contactId}/tags`, { tag_ids: tagIds }),
    exportUrl: (id: number) => `${ws(id)}/contacts/export`,
};

export const tagsApi = {
    list: (id: number) => api.get<Tag[]>(`${ws(id)}/tags`),
    create: (id: number, body: Partial<Tag>) => api.post<Tag>(`${ws(id)}/tags`, body),
    update: (id: number, tagId: number, body: Partial<Tag>) => api.put<Tag>(`${ws(id)}/tags/${tagId}`, body),
    remove: (id: number, tagId: number) => api.delete(`${ws(id)}/tags/${tagId}`),
};

export const customFieldsApi = {
    list: (id: number) => api.get<CustomField[]>(`${ws(id)}/custom-fields`),
    create: (id: number, body: Partial<CustomField>) => api.post<CustomField>(`${ws(id)}/custom-fields`, body),
    update: (id: number, fieldId: number, body: Partial<CustomField>) =>
        api.put<CustomField>(`${ws(id)}/custom-fields/${fieldId}`, body),
    remove: (id: number, fieldId: number) => api.delete(`${ws(id)}/custom-fields/${fieldId}`),
};

export const inboxApi = {
    conversations: (id: number, params: Record<string, string> = {}) =>
        api.get<PaginatedResponse<Conversation>>(`${ws(id)}/conversations?${new URLSearchParams(params)}`),
    conversation: (id: number, conversationId: number) =>
        api.get<{ conversation: Conversation; eligibility: { can_send_free_form: boolean; requires_template: boolean; window_expires_at: string | null } }>(
            `${ws(id)}/conversations/${conversationId}`,
        ),
    messages: (id: number, conversationId: number, page = 1) =>
        api.get<PaginatedResponse<Message>>(`${ws(id)}/conversations/${conversationId}/messages?page=${page}`),
    sendText: (id: number, conversationId: number, body: { text: string; reply_to?: number }) =>
        api.post<Message>(`${ws(id)}/conversations/${conversationId}/messages/text`, body),
    sendMedia: (id: number, conversationId: number, formData: FormData) =>
        api.upload<Message>(`${ws(id)}/conversations/${conversationId}/messages/media`, formData),
    sendTemplate: (id: number, conversationId: number, body: { template_id: number; variable_mappings: any[] }) =>
        api.post<Message>(`${ws(id)}/conversations/${conversationId}/messages/template`, body),
    assign: (id: number, conversationId: number, body: { user_id?: number; team_id?: number; strategy?: string }) =>
        api.post<Conversation>(`${ws(id)}/conversations/${conversationId}/assign`, body),
    unassign: (id: number, conversationId: number) =>
        api.post<Conversation>(`${ws(id)}/conversations/${conversationId}/unassign`),
    setStatus: (id: number, conversationId: number, status: string) =>
        api.put<Conversation>(`${ws(id)}/conversations/${conversationId}/status`, { status }),
    pauseBot: (id: number, conversationId: number) =>
        api.post<Conversation>(`${ws(id)}/conversations/${conversationId}/pause-bot`),
    resumeBot: (id: number, conversationId: number) =>
        api.post<Conversation>(`${ws(id)}/conversations/${conversationId}/resume-bot`),
    markRead: (id: number, conversationId: number) => api.post(`${ws(id)}/conversations/${conversationId}/read`),
    typing: (id: number, conversationId: number, typing: boolean) =>
        api.post(`${ws(id)}/conversations/${conversationId}/typing`, { typing }),
    notes: (id: number, conversationId: number) => api.get<any[]>(`${ws(id)}/conversations/${conversationId}/notes`),
    addNote: (id: number, conversationId: number, body: string) =>
        api.post(`${ws(id)}/conversations/${conversationId}/notes`, { body }),
};

export const agentsApi = {
    list: (id: number) => api.get<AgentProfile[]>(`${ws(id)}/agents`),
    updateSelf: (id: number, body: { status?: string; availability?: string }) =>
        api.put<AgentProfile>(`${ws(id)}/agents/self`, body),
    update: (id: number, profileId: number, body: Record<string, unknown>) =>
        api.put<AgentProfile>(`${ws(id)}/agents/${profileId}`, body),
    teams: (id: number) => api.get<AgentTeam[]>(`${ws(id)}/teams`),
    createTeam: (id: number, body: Record<string, unknown>) => api.post<AgentTeam>(`${ws(id)}/teams`, body),
    updateTeam: (id: number, teamId: number, body: Record<string, unknown>) =>
        api.put<AgentTeam>(`${ws(id)}/teams/${teamId}`, body),
    removeTeam: (id: number, teamId: number) => api.delete(`${ws(id)}/teams/${teamId}`),
};

export const automationsApi = {
    list: (id: number) => api.get<Automation[]>(`${ws(id)}/automations`),
    create: (id: number, body: { name: string; description?: string; priority?: number }) =>
        api.post<Automation>(`${ws(id)}/automations`, body),
    get: (id: number, automationId: number) =>
        api.get<{ automation: Automation; definition: FlowDefinition }>(`${ws(id)}/automations/${automationId}`),
    update: (id: number, automationId: number, body: Record<string, unknown>) =>
        api.put<Automation>(`${ws(id)}/automations/${automationId}`, body),
    remove: (id: number, automationId: number) => api.delete(`${ws(id)}/automations/${automationId}`),
    saveDraft: (id: number, automationId: number, definition: FlowDefinition) =>
        api.put(`${ws(id)}/automations/${automationId}/draft`, { definition }),
    validate: (id: number, automationId: number) =>
        api.post<{ valid: boolean; errors: { node_id: string | null; message: string }[] }>(
            `${ws(id)}/automations/${automationId}/validate`,
        ),
    publish: (id: number, automationId: number) =>
        api.post<{ automation: Automation; version: number }>(`${ws(id)}/automations/${automationId}/publish`),
    setStatus: (id: number, automationId: number, status: string) =>
        api.put<Automation>(`${ws(id)}/automations/${automationId}/status`, { status }),
    runs: (id: number, automationId: number, page = 1) =>
        api.get<PaginatedResponse<AutomationRun>>(`${ws(id)}/automations/${automationId}/runs?page=${page}`),
    runDetail: (id: number, automationId: number, uuid: string) =>
        api.get<AutomationRun>(`${ws(id)}/automations/${automationId}/runs/${uuid}`),
    simulateStart: (id: number, automationId: number) =>
        api.post<SimulationState>(`${ws(id)}/automations/${automationId}/simulate/start`),
    simulateMessage: (id: number, automationId: number, body: { session_id: string; text: string; reply_id?: string }) =>
        api.post<SimulationState>(`${ws(id)}/automations/${automationId}/simulate/message`, body),
};

export const templatesApi = {
    list: (id: number, params: Record<string, string> = {}) =>
        api.get<WhatsAppTemplate[]>(`${ws(id)}/templates?${new URLSearchParams(params)}`),
    create: (id: number, body: Record<string, unknown>) => api.post<WhatsAppTemplate>(`${ws(id)}/templates`, body),
    get: (id: number, templateId: number) => api.get<WhatsAppTemplate>(`${ws(id)}/templates/${templateId}`),
    remove: (id: number, templateId: number) => api.delete(`${ws(id)}/templates/${templateId}`),
    sync: (id: number) => api.post<{ synced: number }>(`${ws(id)}/templates/sync`),
};

export const campaignsApi = {
    list: (id: number, page = 1) => api.get<PaginatedResponse<Campaign>>(`${ws(id)}/campaigns?page=${page}`),
    create: (id: number, body: Record<string, unknown>) =>
        api.post<{ campaign: Campaign; audience_count: number }>(`${ws(id)}/campaigns`, body),
    get: (id: number, campaignId: number) =>
        api.get<{ campaign: Campaign; audience_count: number }>(`${ws(id)}/campaigns/${campaignId}`),
    schedule: (id: number, campaignId: number, scheduledAt?: string) =>
        api.post<Campaign>(`${ws(id)}/campaigns/${campaignId}/schedule`, { scheduled_at: scheduledAt }),
    pause: (id: number, campaignId: number) => api.post<Campaign>(`${ws(id)}/campaigns/${campaignId}/pause`),
    resume: (id: number, campaignId: number) => api.post<Campaign>(`${ws(id)}/campaigns/${campaignId}/resume`),
    cancel: (id: number, campaignId: number) => api.post<Campaign>(`${ws(id)}/campaigns/${campaignId}/cancel`),
    recipients: (id: number, campaignId: number, page = 1) =>
        api.get<PaginatedResponse<any>>(`${ws(id)}/campaigns/${campaignId}/recipients?page=${page}`),
};

export const segmentsApi = {
    list: (id: number) => api.get<Segment[]>(`${ws(id)}/segments`),
    create: (id: number, body: Record<string, unknown>) => api.post<Segment>(`${ws(id)}/segments`, body),
    update: (id: number, segmentId: number, body: Record<string, unknown>) =>
        api.put<Segment>(`${ws(id)}/segments/${segmentId}`, body),
    remove: (id: number, segmentId: number) => api.delete(`${ws(id)}/segments/${segmentId}`),
    preview: (id: number, filters: Record<string, unknown>) =>
        api.post<{ count: number; sample: Contact[] }>(`${ws(id)}/segments/preview`, { filters }),
};

export const billingApi = {
    plans: () => api.get<Plan[]>('/api/plans'),
    summary: (id: number) => api.get<any>(`${ws(id)}/billing/summary`),
    subscribe: (id: number, planId: number, cycle: string = 'monthly') =>
        api.post(`${ws(id)}/billing/subscribe`, { plan_id: planId, billing_cycle: cycle }),
    cancel: (id: number) => api.post(`${ws(id)}/billing/cancel`),
};

export const analyticsApi = {
    dashboard: (id: number) => api.get<any>(`${ws(id)}/dashboard`),
    analytics: (id: number, params: Record<string, string> = {}) =>
        api.get<any>(`${ws(id)}/analytics?${new URLSearchParams(params)}`),
    auditLogs: (id: number, page = 1) => api.get<PaginatedResponse<any>>(`${ws(id)}/audit-logs?page=${page}`),
};

export const adminApi = {
    overview: () => api.get<any>('/api/admin/overview'),
    users: (page = 1) => api.get<PaginatedResponse<any>>(`/api/admin/users?page=${page}`),
    workspaces: (page = 1) => api.get<PaginatedResponse<any>>(`/api/admin/workspaces?page=${page}`),
    webhookEvents: (page = 1) => api.get<PaginatedResponse<any>>(`/api/admin/webhook-events?page=${page}`),
    failedRuns: (page = 1) => api.get<PaginatedResponse<any>>(`/api/admin/failed-runs?page=${page}`),
    failedJobs: () => api.get<any[]>('/api/admin/failed-jobs'),
    health: () => api.get<any>('/api/admin/health'),
    plans: () => api.get<Plan[]>('/api/admin/plans'),
    updatePlan: (planId: number, body: Record<string, unknown>) => api.put(`/api/admin/plans/${planId}`, body),
};
