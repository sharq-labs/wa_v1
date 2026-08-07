import { useMemo, useState, type RefObject } from 'react';
import { useI18n } from '@/lib/i18n';

export type VariableItem = {
    path: string;
    label: string;
};

export type VariableGroup = {
    id: 'contact' | 'workspace' | 'agent' | 'message' | 'custom' | 'variables';
    items: VariableItem[];
};

const CONTACT_VARS: VariableItem[] = [
    { path: 'contact.first_name', label: 'First name' },
    { path: 'contact.last_name', label: 'Last name' },
    { path: 'contact.full_name', label: 'Full name' },
    { path: 'contact.phone', label: 'Phone' },
    { path: 'contact.email', label: 'Email' },
    { path: 'contact.country', label: 'Country' },
    { path: 'contact.language', label: 'Language' },
];

const WORKSPACE_VARS: VariableItem[] = [
    { path: 'workspace.name', label: 'Name' },
    { path: 'workspace.timezone', label: 'Timezone' },
    { path: 'workspace.currency', label: 'Currency' },
];

const AGENT_VARS: VariableItem[] = [
    { path: 'agent.name', label: 'Name' },
    { path: 'agent.email', label: 'Email' },
];

const MESSAGE_VARS: VariableItem[] = [
    { path: 'message.text', label: 'Last message' },
    { path: 'message.type', label: 'Message type' },
];

const GROUP_LABEL_KEYS = {
    contact: 'automations.var_group_contact',
    workspace: 'automations.var_group_workspace',
    agent: 'automations.var_group_agent',
    message: 'automations.var_group_message',
    custom: 'automations.var_group_custom',
    variables: 'automations.var_group_flow',
} as const;

/** Insert `{{path}}` at the caret of an input/textarea, or append if no selection. */
export function insertAtCursor(
    el: HTMLTextAreaElement | HTMLInputElement | null,
    value: string,
    path: string,
    onChange: (next: string) => void,
) {
    const token = `{{${path}}}`;
    if (!el) {
        onChange(value + token);
        return;
    }

    const start = el.selectionStart ?? value.length;
    const end = el.selectionEnd ?? value.length;
    const next = value.slice(0, start) + token + value.slice(end);
    onChange(next);

    requestAnimationFrame(() => {
        el.focus();
        const pos = start + token.length;
        el.setSelectionRange(pos, pos);
    });
}

export function buildVariableGroups(options?: {
    customFields?: { key: string; name: string }[];
    flowVariables?: string[];
}): VariableGroup[] {
    const groups: VariableGroup[] = [
        { id: 'contact', items: CONTACT_VARS },
        { id: 'workspace', items: WORKSPACE_VARS },
        { id: 'agent', items: AGENT_VARS },
        { id: 'message', items: MESSAGE_VARS },
        {
            id: 'custom',
            items: (options?.customFields ?? []).map((f) => ({
                path: `custom.${f.key}`,
                label: f.name || f.key,
            })),
        },
        {
            id: 'variables',
            items: (options?.flowVariables ?? []).map((key) => ({
                path: `variables.${key}`,
                label: key,
            })),
        },
    ];

    return groups;
}

/**
 * Clickable variable chips grouped by namespace. Click inserts into the bound field.
 */
export default function VariableChips({
    value,
    onChange,
    inputRef,
    customFields,
    flowVariables,
}: {
    value: string;
    onChange: (next: string) => void;
    inputRef?: RefObject<HTMLTextAreaElement | HTMLInputElement | null>;
    customFields?: { key: string; name: string }[];
    flowVariables?: string[];
}) {
    const { t } = useI18n();
    const [openGroup, setOpenGroup] = useState<VariableGroup['id']>('contact');

    const groups = useMemo(
        () => buildVariableGroups({ customFields, flowVariables }),
        [customFields, flowVariables],
    );

    const active = groups.find((g) => g.id === openGroup) ?? groups[0];

    return (
        <div className="mt-2 rounded-xl border border-slate-200 bg-slate-50/80 p-2">
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <p className="text-[11px] font-semibold text-slate-600">{t('automations.insert_variable')}</p>
                <p className="text-[10px] text-slate-400">{t('automations.insert_variable_hint')}</p>
            </div>

            <div className="mb-2 flex flex-wrap gap-1">
                {groups.map((group) => (
                    <button
                        key={group.id}
                        type="button"
                        onClick={() => setOpenGroup(group.id)}
                        className={
                            group.id === active?.id
                                ? 'rounded-full bg-slate-800 px-2.5 py-1 text-[11px] font-semibold text-white'
                                : 'rounded-full bg-white px-2.5 py-1 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'
                        }
                    >
                        {t(GROUP_LABEL_KEYS[group.id])}
                        {(group.id === 'custom' || group.id === 'variables') && (
                            <span className="ms-1 opacity-70">({group.items.length})</span>
                        )}
                    </button>
                ))}
            </div>

            <div className="flex flex-wrap gap-1.5">
                {active?.items.map((item) => (
                    <button
                        key={item.path}
                        type="button"
                        title={`{{${item.path}}}`}
                        onClick={() => insertAtCursor(inputRef?.current ?? null, value, item.path, onChange)}
                        className="inline-flex max-w-full items-center gap-1 rounded-lg border border-emerald-200 bg-white px-2 py-1 text-[11px] font-medium text-emerald-800 shadow-xs transition hover:border-emerald-400 hover:bg-emerald-50 active:scale-[0.98]"
                    >
                        <span className="truncate">{item.label}</span>
                        <code className="truncate text-[10px] text-emerald-600/80">{`{{${item.path}}}`}</code>
                    </button>
                ))}
                {active && active.items.length === 0 && (
                    <p className="px-1 py-1 text-[11px] text-slate-400">{t('automations.no_variables_in_group')}</p>
                )}
            </div>
        </div>
    );
}
