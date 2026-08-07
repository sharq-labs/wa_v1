export interface WorkspaceSummary {
    id: number;
    name: string;
    slug: string;
    logo: string | null;
    role: string;
    onboarded_at: string | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    locale: string;
    avatar: string | null;
    is_super_admin: boolean;
    email_verified: boolean;
    current_workspace_id: number | null;
    workspaces?: WorkspaceSummary[];
}

export interface Workspace {
    id: number;
    name: string;
    slug: string;
    logo: string | null;
    country: string | null;
    timezone: string;
    currency: string;
    locale: string;
    status: string;
    industry: string | null;
    website: string | null;
    description: string | null;
    settings: Record<string, any> | null;
    owner_id: number;
    onboarded_at: string | null;
}

export interface Tag {
    id: number;
    name: string;
    color: string;
    description?: string | null;
    contacts_count?: number;
}

export interface CustomField {
    id: number;
    name: string;
    key: string;
    type: string;
    options: string[] | null;
    default_value: string | null;
    required: boolean;
}

export interface ContactCustomFieldValue {
    id: number;
    key: string;
    name: string;
    type: string;
    value: string | null;
}

export interface Contact {
    id: number;
    wa_id: string | null;
    phone_number: string;
    first_name: string | null;
    last_name: string | null;
    display_name: string | null;
    full_name: string;
    email: string | null;
    country: string | null;
    language: string | null;
    profile_picture: string | null;
    status: string;
    opt_in_status: string;
    whatsapp_account_id: number | null;
    last_seen_at: string | null;
    last_message_at: string | null;
    tags?: Tag[];
    custom_fields?: ContactCustomFieldValue[];
    created_at: string;
}

export interface Message {
    id: number;
    conversation_id: number;
    contact_id: number | null;
    direction: 'inbound' | 'outbound';
    sender_type: 'contact' | 'bot' | 'agent' | 'system';
    sender_user_id: number | null;
    sender_user?: { id: number; name: string; avatar: string | null } | null;
    message_type: string;
    content: string | null;
    media_url: string | null;
    media_mime_type: string | null;
    template_name: string | null;
    payload: Record<string, any> | null;
    status: string;
    error_message: string | null;
    reply_to_message_id: number | null;
    created_at: string;
}

export interface Conversation {
    id: number;
    workspace_id: number;
    whatsapp_account_id: number | null;
    contact_id: number;
    contact?: Contact;
    assigned_user_id: number | null;
    assigned_user?: { id: number; name: string; avatar: string | null } | null;
    assigned_team_id: number | null;
    assigned_team?: { id: number; name: string; color: string | null } | null;
    status: 'open' | 'pending' | 'closed';
    automation_status: 'active' | 'paused';
    last_message_at: string | null;
    unread_count: number;
    last_message?: Message | null;
    created_at: string;
}

export interface WhatsAppAccount {
    id: number;
    provider: string;
    display_phone_number: string | null;
    verified_name: string | null;
    quality_rating: string | null;
    messaging_limit: string | null;
    status: string;
    last_sync_at: string | null;
}

export interface AgentProfile {
    id: number;
    user_id: number;
    availability: 'available' | 'unavailable';
    status: 'online' | 'offline' | 'away' | 'busy';
    maximum_conversations: number;
    last_active_at: string | null;
    user?: { id: number; name: string; email: string; avatar: string | null };
}

export interface AgentTeam {
    id: number;
    name: string;
    color: string | null;
    description: string | null;
    assignment_strategy: string;
    members?: { id: number; name: string; email: string; avatar: string | null }[];
}

export interface FlowNode {
    id: string;
    type: string;
    config: Record<string, any>;
    position: { x: number; y: number };
}

export interface FlowEdge {
    id: string;
    source: string;
    sourceHandle?: string | null;
    target: string;
}

export interface FlowDefinition {
    nodes: FlowNode[];
    edges: FlowEdge[];
}

export interface Automation {
    id: number;
    name: string;
    description: string | null;
    status: 'draft' | 'published' | 'paused' | 'archived';
    priority: number;
    published_version_id: number | null;
    runs_count?: number;
    active_runs_count?: number;
    created_at: string;
}

export interface AutomationRun {
    id: number;
    uuid: string;
    status: string;
    current_node_id: string | null;
    steps_executed: number;
    error: string | null;
    started_at: string | null;
    completed_at: string | null;
    contact?: Partial<Contact>;
    version?: { id: number; version: number };
    steps?: AutomationRunStep[];
    variables?: { key: string; value: string | null }[];
    created_at: string;
}

export interface AutomationRunStep {
    id: number;
    node_id: string;
    node_type: string;
    status: string;
    input: Record<string, any> | null;
    output: Record<string, any> | null;
    error: string | null;
    created_at: string;
}

export interface WhatsAppTemplate {
    id: number;
    whatsapp_account_id: number;
    name: string;
    language: string;
    category: string;
    status: string;
    header_type: string | null;
    header_content: string | null;
    body: string;
    footer: string | null;
    buttons: any[] | null;
    variables: Record<string, string> | null;
    rejection_reason: string | null;
    usage_count: number;
    last_synced_at: string | null;
    whatsapp_account?: { id: number; display_phone_number: string | null; verified_name: string | null };
}

export interface Campaign {
    id: number;
    name: string;
    status: string;
    audience_type: string;
    audience_config: Record<string, any> | null;
    variable_mappings: any[] | null;
    scheduled_at: string | null;
    total_recipients: number;
    sent_count: number;
    delivered_count: number;
    read_count: number;
    failed_count: number;
    template?: { id: number; name: string; language: string };
    whatsapp_account?: { id: number; display_phone_number: string | null };
    created_at: string;
}

export interface Segment {
    id: number;
    name: string;
    description: string | null;
    filters: { match: 'all' | 'any'; conditions: SegmentCondition[] };
    contact_count?: number;
}

export interface SegmentCondition {
    source: 'contact' | 'custom' | 'tag';
    key?: string | null;
    operator: string;
    value?: string | null;
}

export interface Plan {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    price_monthly: number;
    price_yearly: number;
    currency: string;
    features: { key: string; value: string }[];
}

export interface PaginatedResponse<T> {
    items: T[];
    meta: { current_page: number; last_page: number; total: number; per_page?: number };
}

export interface SimulationState {
    session_id: string;
    status: 'idle' | 'running' | 'waiting_reply' | 'completed' | 'failed';
    current_node_id: string | null;
    contact: Record<string, string>;
    custom_fields: Record<string, string>;
    tags: string[];
    variables: Record<string, string>;
    transcript: {
        from: 'customer' | 'bot';
        type: string;
        text: string | null;
        buttons?: { id: string; title: string }[];
        button?: string;
        sections?: { title?: string; rows?: { id: string; title: string; description?: string }[] }[];
        media_url?: string | null;
        at: string;
    }[];
    log: { node_id: string | null; message: string; at: string }[];
    conversation: { bot: string; status: string; assigned: string | null };
}
