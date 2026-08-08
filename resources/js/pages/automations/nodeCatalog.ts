/**
 * Node type metadata for the flow builder: labels, palette grouping, colors
 * and the source handles each node exposes.
 */

export interface NodeMeta {
    type: string;
    label: string;
    category: 'trigger' | 'message' | 'data' | 'routing' | 'control';
    color: string;
    description: string;
    defaults: Record<string, any>;
    handles?: { id: string; label: string }[];
}

export const NODE_CATALOG: NodeMeta[] = [
    // Triggers
    {
        type: 'trigger_incoming_message',
        label: 'Incoming Message',
        category: 'trigger',
        color: '#8b5cf6',
        description: 'Fires on any inbound message',
        defaults: {},
    },
    {
        type: 'trigger_keyword',
        label: 'Keyword',
        category: 'trigger',
        color: '#8b5cf6',
        description: 'Fires when a message matches keywords',
        defaults: { keywords: [], match_type: 'contains' },
    },
    {
        type: 'trigger_new_contact',
        label: 'New Contact',
        category: 'trigger',
        color: '#8b5cf6',
        description: 'Fires on the first message from a new contact',
        defaults: {},
    },
    {
        type: 'trigger_tag_added',
        label: 'Tag Added',
        category: 'trigger',
        color: '#8b5cf6',
        description: 'Fires when a tag is added to a contact',
        defaults: { tag_id: null, tag_name: '' },
    },
    {
        type: 'trigger_tag_removed',
        label: 'Tag Removed',
        category: 'trigger',
        color: '#8b5cf6',
        description: 'Fires when a tag is removed from a contact',
        defaults: { tag_id: null, tag_name: '' },
    },
    {
        type: 'trigger_field_changed',
        label: 'Field Changed',
        category: 'trigger',
        color: '#8b5cf6',
        description: 'Fires when a contact custom field changes',
        defaults: { field_key: '', change_type: 'any' },
    },
    {
        type: 'trigger_webhook',
        label: 'Signed Webhook',
        category: 'trigger',
        color: '#8b5cf6',
        description: 'Fires from this automation’s signed webhook endpoint',
        defaults: {},
    },
    {
        type: 'trigger_scheduled',
        label: 'Schedule',
        category: 'trigger',
        color: '#8b5cf6',
        description: 'Runs on a recurring schedule for a contact audience',
        defaults: {
            frequency: 'daily',
            time: '09:00',
            weekday: 1,
            timezone: '',
            audience_type: 'all',
            tag_id: null,
            contact_id: null,
        },
    },

    // Messages
    {
        type: 'send_text',
        label: 'Send Text',
        category: 'message',
        color: '#16a34a',
        description: 'Send a text message (supports variables)',
        defaults: { text: '' },
    },
    {
        type: 'send_image',
        label: 'Send Image',
        category: 'message',
        color: '#16a34a',
        description: 'Send an image by URL',
        defaults: { url: '', caption: '' },
    },
    {
        type: 'send_video',
        label: 'Send Video',
        category: 'message',
        color: '#16a34a',
        description: 'Send a video by URL',
        defaults: { url: '', caption: '' },
    },
    {
        type: 'send_audio',
        label: 'Send Audio',
        category: 'message',
        color: '#16a34a',
        description: 'Send an audio file by URL',
        defaults: { url: '' },
    },
    {
        type: 'send_document',
        label: 'Send Document',
        category: 'message',
        color: '#16a34a',
        description: 'Send a document by URL',
        defaults: { url: '', caption: '' },
    },
    {
        type: 'send_template',
        label: 'Send Template',
        category: 'message',
        color: '#16a34a',
        description: 'Send an approved WhatsApp template',
        defaults: { template_id: null, variable_mappings: [] },
    },
    {
        type: 'send_buttons',
        label: 'Buttons',
        category: 'message',
        color: '#0891b2',
        description: 'Interactive reply buttons; each button gets its own branch',
        defaults: {
            body: '',
            header: '',
            footer: '',
            buttons: [{ id: 'btn_1', title: 'Option 1' }],
            save_to: '',
        },
    },
    {
        type: 'send_list',
        label: 'Send List',
        category: 'message',
        color: '#0891b2',
        description: 'Interactive list message',
        defaults: {
            body: '',
            header: '',
            footer: '',
            button: 'Select',
            sections: [
                {
                    title: 'Section 1',
                    rows: [
                        { id: 'row_1', title: 'Option 1', description: '' },
                        { id: 'row_2', title: 'Option 2', description: '' },
                    ],
                },
            ],
            save_to: '',
        },
    },
    {
        type: 'ask_question',
        label: 'Ask Question',
        category: 'message',
        color: '#0891b2',
        description: 'Ask and wait for the reply; save the answer',
        defaults: { question: '', save_to: '', validation: 'text', error_message: '' },
    },

    // Data
    {
        type: 'set_custom_field',
        label: 'Set Custom Field',
        category: 'data',
        color: '#d97706',
        description: 'Write a value to a contact custom field',
        defaults: { field_key: '', value: '' },
    },
    {
        type: 'clear_custom_field',
        label: 'Clear Custom Field',
        category: 'data',
        color: '#d97706',
        description: 'Clear a contact custom field',
        defaults: { field_key: '' },
    },
    {
        type: 'add_tag',
        label: 'Add Tag',
        category: 'data',
        color: '#d97706',
        description: 'Add a tag to the contact',
        defaults: { tag_id: null, tag_name: '' },
    },
    {
        type: 'remove_tag',
        label: 'Remove Tag',
        category: 'data',
        color: '#d97706',
        description: 'Remove a tag from the contact',
        defaults: { tag_id: null, tag_name: '' },
    },
    {
        type: 'goal',
        label: 'Conversion Goal',
        category: 'data',
        color: '#d97706',
        description: 'Record a conversion goal and optional value',
        defaults: { goal_name: 'Conversion', value: '', currency: '' },
    },
    {
        type: 'http_request',
        label: 'HTTP Request',
        category: 'data',
        color: '#d97706',
        description: 'Call an external API and map the response',
        defaults: { url: '', method: 'POST', headers: [], query: [], body: '', response_mappings: [], timeout: 10 },
        handles: [
            { id: 'next', label: 'Success' },
            { id: 'error', label: 'Error' },
        ],
    },
    {
        type: 'send_webhook',
        label: 'Send Webhook',
        category: 'data',
        color: '#d97706',
        description: 'POST flow context to your endpoint',
        defaults: { url: '', method: 'POST', payload: null },
        handles: [
            { id: 'next', label: 'Success' },
            { id: 'error', label: 'Error' },
        ],
    },
    {
        type: 'add_note',
        label: 'Internal Note',
        category: 'data',
        color: '#d97706',
        description: 'Add an internal note to the conversation',
        defaults: { body: '' },
    },

    // Routing
    {
        type: 'assign_agent',
        label: 'Assign Agent',
        category: 'routing',
        color: '#dc2626',
        description: 'Hand off to an agent or team',
        defaults: { user_id: null, team_id: null, strategy: 'round_robin', pause_bot: false },
    },
    {
        type: 'unassign_agent',
        label: 'Unassign',
        category: 'routing',
        color: '#dc2626',
        description: 'Remove the current assignment',
        defaults: {},
    },
    {
        type: 'pause_bot',
        label: 'Pause Bot',
        category: 'routing',
        color: '#dc2626',
        description: 'Stop automated replies in this conversation',
        defaults: {},
    },
    {
        type: 'resume_bot',
        label: 'Resume Bot',
        category: 'routing',
        color: '#dc2626',
        description: 'Re-enable automated replies',
        defaults: {},
    },
    {
        type: 'close_conversation',
        label: 'Close Conversation',
        category: 'routing',
        color: '#dc2626',
        description: 'Close the conversation',
        defaults: {},
    },
    {
        type: 'reopen_conversation',
        label: 'Reopen Conversation',
        category: 'routing',
        color: '#dc2626',
        description: 'Reopen the conversation',
        defaults: {},
    },
    {
        type: 'start_automation',
        label: 'Start Automation',
        category: 'routing',
        color: '#dc2626',
        description: 'Run another published automation',
        defaults: { automation_id: null, stop_parent: false },
    },

    // Control
    {
        type: 'condition',
        label: 'Condition',
        category: 'control',
        color: '#2563eb',
        description: 'Branch on contact data, variables, tags…',
        defaults: { match: 'all', conditions: [] },
        handles: [
            { id: 'true', label: 'TRUE' },
            { id: 'false', label: 'FALSE' },
        ],
    },
    {
        type: 'delay',
        label: 'Delay',
        category: 'control',
        color: '#2563eb',
        description: 'Wait for a duration before continuing',
        defaults: { amount: 5, unit: 'minutes' },
    },
    {
        type: 'wait_until',
        label: 'Wait Until',
        category: 'control',
        color: '#2563eb',
        description: 'Wait until a specific moment',
        defaults: { mode: 'tomorrow', time: '09:00' },
    },
    {
        type: 'random_split',
        label: 'Random Split',
        category: 'control',
        color: '#2563eb',
        description: 'A/B split traffic by weight',
        defaults: { branches: [{ handle: 'a', weight: 50 }, { handle: 'b', weight: 50 }] },
        handles: [
            { id: 'a', label: 'A' },
            { id: 'b', label: 'B' },
        ],
    },
    {
        type: 'go_to_node',
        label: 'Go To Node',
        category: 'control',
        color: '#2563eb',
        description: 'Jump to another node in the flow',
        defaults: { target_node_id: '' },
    },
    {
        type: 'stop',
        label: 'Stop',
        category: 'control',
        color: '#64748b',
        description: 'End the flow',
        defaults: {},
    },
];

export const NODE_META: Record<string, NodeMeta> = Object.fromEntries(NODE_CATALOG.map((n) => [n.type, n]));

export const CATEGORY_LABELS: Record<NodeMeta['category'], string> = {
    trigger: 'Triggers',
    message: 'Messages',
    data: 'Data & Integrations',
    routing: 'Agents & Routing',
    control: 'Control',
};

export function nodeHandles(type: string, config: Record<string, any>): { id: string; label: string }[] {
    if (type === 'send_buttons') {
        return (config.buttons ?? []).map((b: any, i: number) => ({
            id: b.id ?? `btn_${i}`,
            label: b.title ?? `Option ${i + 1}`,
        }));
    }

    if (type === 'random_split') {
        return (config.branches ?? []).map((b: any, i: number) => ({
            id: b.handle ?? String.fromCharCode(97 + i),
            label: (b.handle ?? '').toUpperCase() + ` ${b.weight ?? 0}%`,
        }));
    }

    const meta = NODE_META[type];
    if (meta?.handles) return meta.handles;

    if (['stop', 'close_conversation'].includes(type)) return [];

    return [{ id: 'next', label: '' }];
}
