import { useQuery } from '@tanstack/react-query';
import type { Node } from '@xyflow/react';
import { Copy, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import { agentsApi, automationsApi, customFieldsApi, tagsApi, templatesApi } from '@/api';
import { Button, Input, Label, Select, Textarea } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import { NODE_META } from './nodeCatalog';
import { nodeDescKey, nodeLabelKey } from './nodeI18n';
import { nodeIcon, solidIconTileStyle } from './nodeIcons';
import ConditionSettings from './ConditionSettings';
import VariableChips from './VariableChips';

/** Collect `variables.*` keys written by ask/buttons/HTTP mappings in this flow. */
function collectFlowVariables(allNodes: Node[]): string[] {
    const keys = new Set<string>();

    for (const node of allNodes) {
        const config = (node.data.config ?? {}) as Record<string, any>;
        const saveTo = config.save_to;
        if (typeof saveTo === 'string' && saveTo.startsWith('variables.')) {
            const key = saveTo.slice('variables.'.length).trim();
            if (key) keys.add(key);
        }

        for (const mapping of config.response_mappings ?? []) {
            const target = mapping.variable ?? mapping.target ?? mapping.to;
            if (typeof target === 'string') {
                const key = target.startsWith('variables.') ? target.slice('variables.'.length) : target;
                if (key) keys.add(key.trim());
            }
        }
    }

    return [...keys].sort();
}

/**
 * Dynamic settings form for the selected node. Every change flows straight
 * into the node config (and the debounced autosave).
 */
export default function NodeSettingsPanel({
    node,
    allNodes,
    onChange,
    onDelete,
    onDuplicate,
    onClose,
}: {
    node: Node;
    allNodes: Node[];
    onChange: (config: Record<string, any>) => void;
    onDelete: () => void;
    onDuplicate: () => void;
    onClose: () => void;
}) {
    const { t } = useI18n();
    const workspaceId = useWorkspaceId();
    const type = node.data.nodeType as string;
    const config = (node.data.config ?? {}) as Record<string, any>;
    const meta = NODE_META[type];
    const Icon = nodeIcon(type);
    const textFieldRef = useRef<HTMLTextAreaElement>(null);
    const valueFieldRef = useRef<HTMLInputElement>(null);
    const bodyFieldRef = useRef<HTMLTextAreaElement>(null);
    const captionFieldRef = useRef<HTMLInputElement>(null);
    const questionFieldRef = useRef<HTMLTextAreaElement>(null);

    const set = (patch: Record<string, any>) => onChange({ ...config, ...patch });

    const tags = useQuery({ queryKey: ['tags', workspaceId], queryFn: async () => (await tagsApi.list(workspaceId)).data });
    const fields = useQuery({
        queryKey: ['custom-fields', workspaceId],
        queryFn: async () => (await customFieldsApi.list(workspaceId)).data,
    });
    const teams = useQuery({ queryKey: ['teams', workspaceId], queryFn: async () => (await agentsApi.teams(workspaceId)).data });
    const agents = useQuery({ queryKey: ['agents', workspaceId], queryFn: async () => (await agentsApi.list(workspaceId)).data });
    const templates = useQuery({
        queryKey: ['templates', workspaceId, 'approved'],
        queryFn: async () => (await templatesApi.list(workspaceId, { status: 'approved' })).data,
    });
    const automations = useQuery({
        queryKey: ['automations', workspaceId],
        queryFn: async () => (await automationsApi.list(workspaceId)).data,
    });

    const flowVariables = useMemo(() => collectFlowVariables(allNodes), [allNodes]);
    const customFieldOptions = useMemo(
        () => (fields.data ?? []).map((f) => ({ key: f.key, name: f.name })),
        [fields.data],
    );

    // Older list nodes shipped with empty sections — seed a usable default once.
    useEffect(() => {
        if (type !== 'send_list') return;
        if ((config.sections ?? []).length > 0) return;
        onChange({
            ...config,
            button: config.button || 'Select',
            sections: structuredClone(NODE_META.send_list.defaults.sections),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [type, node.id]);

    const saveTargetSelect = (value: string, onValue: (v: string) => void) => (
        <div className="flex gap-1.5">
            <Select
                value={value.startsWith('variables.') ? 'variables' : 'custom'}
                onChange={(e) => onValue(e.target.value === 'variables' ? 'variables.' : 'custom.')}
                className="!w-28"
            >
                <option value="custom">Custom field</option>
                <option value="variables">Variable</option>
            </Select>
            {value.startsWith('custom.') || value === '' ? (
                <Select value={value.replace('custom.', '')} onChange={(e) => onValue(`custom.${e.target.value}`)}>
                    <option value="">—</option>
                    {fields.data?.map((f) => (
                        <option key={f.key} value={f.key}>
                            {f.name}
                        </option>
                    ))}
                </Select>
            ) : (
                <Input
                    value={value.replace('variables.', '')}
                    onChange={(e) => onValue(`variables.${e.target.value}`)}
                    placeholder="variable_name"
                />
            )}
        </div>
    );

    const listRowCount = (config.sections ?? []).reduce(
        (n: number, s: any) => n + (s.rows?.length ?? 0),
        0,
    );

    return (
        <div className="flex w-[24rem] shrink-0 flex-col border-s border-slate-200 bg-[#fafbfc]">
            <div className="border-b border-slate-200 bg-white px-4 py-3">
                <div className="flex items-start justify-between gap-2">
                    <div className="flex min-w-0 items-start gap-2.5">
                        <span
                            className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] shadow-sm"
                            style={solidIconTileStyle(meta?.color ?? '#94a3b8')}
                        >
                            <Icon size={16} strokeWidth={2.4} />
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-[15px] font-semibold text-slate-900">
                                {t(nodeLabelKey(type)) || meta?.label}
                            </p>
                            <p className="mt-0.5 line-clamp-2 text-[12px] leading-snug text-slate-500">
                                {t(nodeDescKey(type))}
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-0.5">
                        <button
                            type="button"
                            onClick={onDuplicate}
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                            title={t('automations.node_duplicate')}
                        >
                            <Copy size={15} />
                        </button>
                        <button
                            type="button"
                            onClick={onDelete}
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                            title={t('automations.node_delete')}
                        >
                            <Trash2 size={15} />
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                            title={t('common.close')}
                        >
                            <X size={15} />
                        </button>
                    </div>
                </div>
            </div>

            <div className="flex-1 space-y-4 overflow-y-auto px-4 py-4 text-sm">
                {/* ---- Triggers ---- */}
                {type === 'trigger_keyword' && (
                    <>
                        <div>
                            <Label>Keywords (comma separated)</Label>
                            <Input
                                value={(config.keywords ?? []).join(', ')}
                                onChange={(e) =>
                                    set({ keywords: e.target.value.split(',').map((s) => s.trim()).filter(Boolean) })
                                }
                                placeholder="سعر, price"
                            />
                        </div>
                        <div>
                            <Label>Match type</Label>
                            <Select value={config.match_type ?? 'contains'} onChange={(e) => set({ match_type: e.target.value })}>
                                <option value="contains">Contains text</option>
                                <option value="exact">Exact message</option>
                                <option value="starts_with">Starts with</option>
                                <option value="ends_with">Ends with</option>
                            </Select>
                        </div>
                    </>
                )}

                {/* ---- Messages ---- */}
                {(type === 'send_text' || type === 'add_note') && (
                    <div>
                        <Label>{type === 'add_note' ? t('automations.field_note') : t('automations.field_message')}</Label>
                        <Textarea
                            ref={textFieldRef}
                            rows={5}
                            value={config.text ?? config.body ?? ''}
                            onChange={(e) => set(type === 'add_note' ? { body: e.target.value } : { text: e.target.value })}
                            placeholder={t('automations.placeholder_message')}
                        />
                        <VariableChips
                            value={config.text ?? config.body ?? ''}
                            onChange={(next) => set(type === 'add_note' ? { body: next } : { text: next })}
                            inputRef={textFieldRef}
                            customFields={customFieldOptions}
                            flowVariables={flowVariables}
                        />
                    </div>
                )}

                {['send_image', 'send_video', 'send_audio', 'send_document'].includes(type) && (
                    <>
                        <div>
                            <Label>{t('automations.field_file_url')}</Label>
                            <Input value={config.url ?? ''} onChange={(e) => set({ url: e.target.value })} placeholder="https://…" />
                        </div>
                        {type !== 'send_audio' && (
                            <div>
                                <Label>{t('automations.field_caption')}</Label>
                                <Input
                                    ref={captionFieldRef}
                                    value={config.caption ?? ''}
                                    onChange={(e) => set({ caption: e.target.value })}
                                />
                                <VariableChips
                                    value={config.caption ?? ''}
                                    onChange={(next) => set({ caption: next })}
                                    inputRef={captionFieldRef}
                                    customFields={customFieldOptions}
                                    flowVariables={flowVariables}
                                />
                            </div>
                        )}
                    </>
                )}

                {type === 'send_template' && (
                    <div>
                        <Label>{t('automations.field_template')}</Label>
                        <Select
                            value={config.template_id ?? ''}
                            onChange={(e) => set({ template_id: e.target.value ? Number(e.target.value) : null })}
                        >
                            <option value="">—</option>
                            {templates.data?.map((tpl) => (
                                <option key={tpl.id} value={tpl.id}>
                                    {tpl.name} ({tpl.language})
                                </option>
                            ))}
                        </Select>
                    </div>
                )}

                {type === 'ask_question' && (
                    <>
                        <div>
                            <Label>{t('automations.field_question')}</Label>
                            <Textarea
                                ref={questionFieldRef}
                                rows={3}
                                value={config.question ?? ''}
                                onChange={(e) => set({ question: e.target.value })}
                            />
                            <VariableChips
                                value={config.question ?? ''}
                                onChange={(next) => set({ question: next })}
                                inputRef={questionFieldRef}
                                customFields={customFieldOptions}
                                flowVariables={flowVariables}
                            />
                        </div>
                        <div>
                            <Label>{t('automations.save_to')}</Label>
                            {saveTargetSelect(config.save_to ?? '', (save_to) => set({ save_to }))}
                        </div>
                        <div>
                            <Label>{t('automations.field_validation')}</Label>
                            <Select value={config.validation ?? 'text'} onChange={(e) => set({ validation: e.target.value })}>
                                {['text', 'number', 'email', 'phone', 'date', 'choice'].map((v) => (
                                    <option key={v}>{v}</option>
                                ))}
                            </Select>
                        </div>
                        {config.validation === 'choice' && (
                            <div>
                                <Label>{t('automations.allowed_choices')}</Label>
                                <Input
                                    value={(config.choices ?? []).join(', ')}
                                    onChange={(e) => set({ choices: e.target.value.split(',').map((s) => s.trim()).filter(Boolean) })}
                                />
                            </div>
                        )}
                        <div>
                            <Label>{t('automations.invalid_answer')}</Label>
                            <Input value={config.error_message ?? ''} onChange={(e) => set({ error_message: e.target.value })} />
                        </div>
                    </>
                )}

                {type === 'send_buttons' && (
                    <>
                        <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
                            <p className="text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                {t('automations.field_body')}
                            </p>
                            <div>
                                <Label>{t('automations.field_header')}</Label>
                                <Input
                                    value={config.header ?? ''}
                                    maxLength={60}
                                    onChange={(e) => set({ header: e.target.value })}
                                    placeholder={t('automations.optional')}
                                />
                            </div>
                            <div>
                                <Label>{t('automations.field_body')}</Label>
                                <Textarea
                                    ref={bodyFieldRef}
                                    rows={3}
                                    value={config.body ?? ''}
                                    onChange={(e) => set({ body: e.target.value })}
                                    placeholder={t('automations.placeholder_choose')}
                                />
                                <VariableChips
                                    value={config.body ?? ''}
                                    onChange={(next) => set({ body: next })}
                                    inputRef={bodyFieldRef}
                                    customFields={customFieldOptions}
                                    flowVariables={flowVariables}
                                />
                            </div>
                            <div>
                                <Label>{t('automations.field_footer')}</Label>
                                <Input
                                    value={config.footer ?? ''}
                                    maxLength={60}
                                    onChange={(e) => set({ footer: e.target.value })}
                                    placeholder={t('automations.optional')}
                                />
                            </div>
                        </section>

                        <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                    {t('automations.field_buttons')}
                                </p>
                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                    {(config.buttons ?? []).length}/3
                                </span>
                            </div>
                            <p className="text-[12px] leading-snug text-slate-500">{t('automations.buttons_hint')}</p>
                            <div className="space-y-2">
                                {(config.buttons ?? []).map((button: any, i: number) => (
                                    <div
                                        key={button.id ?? i}
                                        className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/60 px-2.5 py-2"
                                    >
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-white text-[11px] font-bold text-slate-500 ring-1 ring-slate-200">
                                            {i + 1}
                                        </span>
                                        <Input
                                            value={button.title ?? ''}
                                            maxLength={20}
                                            className="!border-transparent !bg-white !shadow-none"
                                            placeholder={`${t('automations.option')} ${i + 1}`}
                                            onChange={(e) => {
                                                const buttons = [...(config.buttons ?? [])];
                                                buttons[i] = {
                                                    ...buttons[i],
                                                    id: buttons[i].id || `btn_${i + 1}`,
                                                    title: e.target.value,
                                                };
                                                set({ buttons });
                                            }}
                                        />
                                        <button
                                            type="button"
                                            disabled={(config.buttons ?? []).length <= 1}
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30"
                                            onClick={() =>
                                                set({
                                                    buttons: (config.buttons ?? []).filter((_: any, j: number) => j !== i),
                                                })
                                            }
                                        >
                                            <Trash2 size={14} />
                                        </button>
                                    </div>
                                ))}
                            </div>
                            {(config.buttons ?? []).length < 3 && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    className="w-full"
                                    onClick={() =>
                                        set({
                                            buttons: [
                                                ...(config.buttons ?? []),
                                                {
                                                    id: `btn_${Date.now().toString(36)}`,
                                                    title: `${t('automations.option')} ${(config.buttons ?? []).length + 1}`,
                                                },
                                            ],
                                        })
                                    }
                                >
                                    + {t('automations.add_button')}
                                </Button>
                            )}
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
                            <Label>{t('automations.save_selection')}</Label>
                            {saveTargetSelect(config.save_to ?? '', (save_to) => set({ save_to }))}
                        </section>
                    </>
                )}

                {type === 'send_list' && (
                    <>
                        <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
                            <p className="text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                {t('automations.field_body')}
                            </p>
                            <div>
                                <Label>{t('automations.field_header')}</Label>
                                <Input
                                    value={config.header ?? ''}
                                    maxLength={60}
                                    onChange={(e) => set({ header: e.target.value })}
                                    placeholder={t('automations.optional')}
                                />
                            </div>
                            <div>
                                <Label>{t('automations.field_body')}</Label>
                                <Textarea
                                    ref={bodyFieldRef}
                                    rows={3}
                                    value={config.body ?? ''}
                                    onChange={(e) => set({ body: e.target.value })}
                                    placeholder={t('automations.placeholder_list_body')}
                                />
                                <VariableChips
                                    value={config.body ?? ''}
                                    onChange={(next) => set({ body: next })}
                                    inputRef={bodyFieldRef}
                                    customFields={customFieldOptions}
                                    flowVariables={flowVariables}
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-2.5">
                                <div>
                                    <Label>{t('automations.list_button_label')}</Label>
                                    <Input
                                        value={config.button ?? ''}
                                        maxLength={20}
                                        onChange={(e) => set({ button: e.target.value })}
                                        placeholder={t('automations.list_button_placeholder')}
                                    />
                                </div>
                                <div>
                                    <Label>{t('automations.field_footer')}</Label>
                                    <Input
                                        value={config.footer ?? ''}
                                        maxLength={60}
                                        onChange={(e) => set({ footer: e.target.value })}
                                        placeholder={t('automations.optional')}
                                    />
                                </div>
                            </div>
                        </section>

                        <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                        {t('automations.list_sections')}
                                    </p>
                                    <p className="mt-1 text-[12px] leading-snug text-slate-500">
                                        {t('automations.list_sections_hint')}
                                    </p>
                                </div>
                                <span className="shrink-0 rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold text-brand-700 ring-1 ring-brand-100">
                                    {listRowCount}/10
                                </span>
                            </div>

                            <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div
                                    className="h-full rounded-full bg-brand-500 transition-all"
                                    style={{ width: `${Math.min(100, (listRowCount / 10) * 100)}%` }}
                                />
                            </div>

                            <div className="space-y-4">
                                {(config.sections ?? []).map((section: any, si: number) => (
                                    <div key={si} className="border-t border-slate-200 pt-3 first:border-t-0 first:pt-0">
                                        <div className="mb-2 flex items-center gap-2">
                                            <span className="shrink-0 text-[12px] font-semibold text-slate-700">
                                                {t('automations.section')} {si + 1}
                                            </span>
                                            <Input
                                                value={section.title ?? ''}
                                                maxLength={24}
                                                className="!rounded-lg !py-1.5 !text-[13px]"
                                                placeholder={`${t('automations.section')} ${si + 1}`}
                                                onChange={(e) => {
                                                    const sections = [...(config.sections ?? [])];
                                                    sections[si] = { ...sections[si], title: e.target.value };
                                                    set({ sections });
                                                }}
                                            />
                                            <button
                                                type="button"
                                                disabled={(config.sections ?? []).length <= 1}
                                                className="shrink-0 rounded-md p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600 disabled:opacity-30"
                                                title={t('automations.node_delete')}
                                                onClick={() =>
                                                    set({
                                                        sections: (config.sections ?? []).filter(
                                                            (_: any, j: number) => j !== si,
                                                        ),
                                                    })
                                                }
                                            >
                                                <Trash2 size={15} />
                                            </button>
                                        </div>

                                        <div className="ms-1 border-s-2 border-slate-200 ps-3">
                                            <p className="mb-2 text-[12px] font-medium text-slate-600">
                                                {t('automations.list_rows')}
                                            </p>
                                            <div className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                                                {(section.rows ?? []).map((row: any, ri: number) => (
                                                    <div key={row.id ?? ri} className="flex gap-2 px-2.5 py-2.5">
                                                        <span className="mt-2 w-4 shrink-0 text-[12px] font-semibold text-slate-500">
                                                            {ri + 1}.
                                                        </span>
                                                        <div className="min-w-0 flex-1 space-y-1.5">
                                                            <Input
                                                                value={row.title ?? ''}
                                                                maxLength={24}
                                                                className="!rounded-md !border-slate-300 !py-1.5 !text-start !text-[13px] !font-medium"
                                                                placeholder={`${t('automations.option')} ${ri + 1}`}
                                                                onChange={(e) => {
                                                                    const sections = [...(config.sections ?? [])];
                                                                    const rows = [...(sections[si].rows ?? [])];
                                                                    rows[ri] = {
                                                                        ...rows[ri],
                                                                        id:
                                                                            rows[ri].id ||
                                                                            `row_${Date.now().toString(36)}`,
                                                                        title: e.target.value,
                                                                    };
                                                                    sections[si] = { ...sections[si], rows };
                                                                    set({ sections });
                                                                }}
                                                            />
                                                            <Input
                                                                value={row.description ?? ''}
                                                                maxLength={72}
                                                                className="!rounded-md !border-slate-200 !bg-slate-50 !py-1.5 !text-start !text-[12px] !text-slate-700"
                                                                placeholder={t('automations.row_description')}
                                                                onChange={(e) => {
                                                                    const sections = [...(config.sections ?? [])];
                                                                    const rows = [...(sections[si].rows ?? [])];
                                                                    rows[ri] = {
                                                                        ...rows[ri],
                                                                        description: e.target.value,
                                                                    };
                                                                    sections[si] = { ...sections[si], rows };
                                                                    set({ sections });
                                                                }}
                                                            />
                                                        </div>
                                                        <button
                                                            type="button"
                                                            disabled={(section.rows ?? []).length <= 1}
                                                            className="mt-1.5 shrink-0 rounded-md p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600 disabled:opacity-30"
                                                            title={t('automations.node_delete')}
                                                            onClick={() => {
                                                                const sections = [...(config.sections ?? [])];
                                                                sections[si] = {
                                                                    ...sections[si],
                                                                    rows: (sections[si].rows ?? []).filter(
                                                                        (_: any, j: number) => j !== ri,
                                                                    ),
                                                                };
                                                                set({ sections });
                                                            }}
                                                        >
                                                            <Trash2 size={15} />
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>

                                            {listRowCount < 10 && (
                                                <button
                                                    type="button"
                                                    className="mt-2 text-[12px] font-semibold text-brand-700 hover:text-brand-800"
                                                    onClick={() => {
                                                        const sections = [...(config.sections ?? [])];
                                                        const rows = [...(sections[si].rows ?? [])];
                                                        rows.push({
                                                            id: `row_${Date.now().toString(36)}`,
                                                            title: `${t('automations.option')} ${rows.length + 1}`,
                                                            description: '',
                                                        });
                                                        sections[si] = { ...sections[si], rows };
                                                        set({ sections });
                                                    }}
                                                >
                                                    + {t('automations.add_row')}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {(config.sections ?? []).length < 10 && listRowCount < 10 && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    className="w-full"
                                    onClick={() =>
                                        set({
                                            sections: [
                                                ...(config.sections ?? []),
                                                {
                                                    title: `${t('automations.section')} ${(config.sections ?? []).length + 1}`,
                                                    rows: [
                                                        {
                                                            id: `row_${Date.now().toString(36)}`,
                                                            title: `${t('automations.option')} 1`,
                                                            description: '',
                                                        },
                                                    ],
                                                },
                                            ],
                                        })
                                    }
                                >
                                    + {t('automations.add_section')}
                                </Button>
                            )}
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
                            <Label>{t('automations.save_selection')}</Label>
                            {saveTargetSelect(config.save_to ?? '', (save_to) => set({ save_to }))}
                        </section>
                    </>
                )}

                {/* ---- Data ---- */}
                {(type === 'set_custom_field' || type === 'clear_custom_field') && (
                    <>
                        <div>
                            <Label>Custom field</Label>
                            <Select value={config.field_key ?? ''} onChange={(e) => set({ field_key: e.target.value })}>
                                <option value="">—</option>
                                {fields.data?.map((f) => (
                                    <option key={f.key} value={f.key}>
                                        {f.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        {type === 'set_custom_field' && (
                            <div>
                                <Label>Value (supports variables)</Label>
                                <Input
                                    ref={valueFieldRef}
                                    value={config.value ?? ''}
                                    onChange={(e) => set({ value: e.target.value })}
                                />
                                <VariableChips
                                    value={config.value ?? ''}
                                    onChange={(next) => set({ value: next })}
                                    inputRef={valueFieldRef}
                                    customFields={customFieldOptions}
                                    flowVariables={flowVariables}
                                />
                            </div>
                        )}
                    </>
                )}

                {(type === 'add_tag' || type === 'remove_tag') && (
                    <div>
                        <Label>Tag</Label>
                        <Select
                            value={config.tag_id ?? ''}
                            onChange={(e) => {
                                const tag = tags.data?.find((t) => String(t.id) === e.target.value);
                                set({ tag_id: tag?.id ?? null, tag_name: tag?.name ?? '' });
                            }}
                        >
                            <option value="">—</option>
                            {tags.data?.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </Select>
                        {type === 'add_tag' && (
                            <>
                                <Label className="mt-2">Or create by name</Label>
                                <Input
                                    value={config.tag_name ?? ''}
                                    onChange={(e) => set({ tag_name: e.target.value, tag_id: null })}
                                />
                            </>
                        )}
                    </div>
                )}

                {(type === 'http_request' || type === 'send_webhook') && (
                    <>
                        <div className="flex gap-1.5">
                            <Select value={config.method ?? 'POST'} onChange={(e) => set({ method: e.target.value })} className="!w-24">
                                {['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => (
                                    <option key={m}>{m}</option>
                                ))}
                            </Select>
                            <Input value={config.url ?? ''} onChange={(e) => set({ url: e.target.value })} placeholder="https://api.example.com/…" />
                        </div>
                        {type === 'http_request' && (
                            <>
                                <div>
                                    <Label>Body (JSON, supports variables)</Label>
                                    <Textarea
                                        ref={bodyFieldRef}
                                        rows={4}
                                        value={config.body ?? ''}
                                        onChange={(e) => set({ body: e.target.value })}
                                    />
                                    <VariableChips
                                        value={config.body ?? ''}
                                        onChange={(next) => set({ body: next })}
                                        inputRef={bodyFieldRef}
                                        customFields={customFieldOptions}
                                        flowVariables={flowVariables}
                                    />
                                </div>
                                <div>
                                    <Label>Response mappings (path → variable)</Label>
                                    <div className="space-y-1.5">
                                        {(config.response_mappings ?? []).map((mapping: any, i: number) => (
                                            <div key={i} className="flex gap-1.5">
                                                <Input
                                                    value={mapping.path ?? ''}
                                                    placeholder="data.customer_id"
                                                    onChange={(e) => {
                                                        const response_mappings = [...(config.response_mappings ?? [])];
                                                        response_mappings[i] = { ...response_mappings[i], path: e.target.value };
                                                        set({ response_mappings });
                                                    }}
                                                />
                                                <Input
                                                    value={mapping.variable ?? ''}
                                                    placeholder="customer_id"
                                                    onChange={(e) => {
                                                        const response_mappings = [...(config.response_mappings ?? [])];
                                                        response_mappings[i] = { ...response_mappings[i], variable: e.target.value };
                                                        set({ response_mappings });
                                                    }}
                                                />
                                            </div>
                                        ))}
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => set({ response_mappings: [...(config.response_mappings ?? []), { path: '', variable: '' }] })}
                                        >
                                            + Add mapping
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}
                    </>
                )}

                {/* ---- Routing ---- */}
                {type === 'assign_agent' && (
                    <>
                        <div>
                            <Label>Specific agent (optional)</Label>
                            <Select
                                value={config.user_id ?? ''}
                                onChange={(e) => set({ user_id: e.target.value ? Number(e.target.value) : null })}
                            >
                                <option value="">—</option>
                                {agents.data?.map((a) => (
                                    <option key={a.user_id} value={a.user_id}>
                                        {a.user?.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div>
                            <Label>Team</Label>
                            <Select
                                value={config.team_id ?? ''}
                                onChange={(e) => set({ team_id: e.target.value ? Number(e.target.value) : null })}
                            >
                                <option value="">—</option>
                                {teams.data?.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div>
                            <Label>Strategy</Label>
                            <Select value={config.strategy ?? 'round_robin'} onChange={(e) => set({ strategy: e.target.value })}>
                                <option value="round_robin">Round robin</option>
                                <option value="least_active">Least active</option>
                            </Select>
                        </div>
                        <label className="flex items-center gap-2 text-xs text-slate-600">
                            <input type="checkbox" checked={!!config.pause_bot} onChange={(e) => set({ pause_bot: e.target.checked })} />
                            Pause bot after assignment
                        </label>
                    </>
                )}

                {type === 'start_automation' && (
                    <>
                        <div>
                            <Label>Automation</Label>
                            <Select
                                value={config.automation_id ?? ''}
                                onChange={(e) => set({ automation_id: e.target.value ? Number(e.target.value) : null })}
                            >
                                <option value="">—</option>
                                {automations.data
                                    ?.filter((a) => a.status === 'published')
                                    .map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                            </Select>
                        </div>
                        <label className="flex items-center gap-2 text-xs text-slate-600">
                            <input type="checkbox" checked={!!config.stop_parent} onChange={(e) => set({ stop_parent: e.target.checked })} />
                            Stop this flow after starting
                        </label>
                    </>
                )}

                {/* ---- Control ---- */}
                {type === 'condition' && (
                    <ConditionSettings
                        match={config.match ?? 'all'}
                        conditions={config.conditions ?? []}
                        customFields={fields.data ?? []}
                        flowVariables={flowVariables}
                        tags={tags.data ?? []}
                        onChange={(patch) => set(patch)}
                    />
                )}

                {type === 'delay' && (
                    <div className="flex gap-1.5">
                        <Input
                            type="number"
                            min={1}
                            value={config.amount ?? 5}
                            onChange={(e) => set({ amount: Number(e.target.value) })}
                            className="!w-24"
                        />
                        <Select value={config.unit ?? 'minutes'} onChange={(e) => set({ unit: e.target.value })}>
                            {['seconds', 'minutes', 'hours', 'days'].map((u) => (
                                <option key={u}>{u}</option>
                            ))}
                        </Select>
                    </div>
                )}

                {type === 'wait_until' && (
                    <>
                        <div>
                            <Label>Mode</Label>
                            <Select value={config.mode ?? 'tomorrow'} onChange={(e) => set({ mode: e.target.value })}>
                                <option value="tomorrow">Tomorrow at time</option>
                                <option value="datetime">Specific date/time</option>
                                <option value="custom_field">Custom field date</option>
                                <option value="working_hours">Next working hours</option>
                            </Select>
                        </div>
                        {config.mode === 'tomorrow' && (
                            <Input type="time" value={config.time ?? '09:00'} onChange={(e) => set({ time: e.target.value })} />
                        )}
                        {config.mode === 'datetime' && (
                            <Input
                                type="datetime-local"
                                value={config.datetime ?? ''}
                                onChange={(e) => set({ datetime: e.target.value })}
                            />
                        )}
                        {config.mode === 'custom_field' && (
                            <Select value={config.field_key ?? ''} onChange={(e) => set({ field_key: e.target.value })}>
                                <option value="">—</option>
                                {fields.data
                                    ?.filter((f) => ['date', 'datetime'].includes(f.type))
                                    .map((f) => (
                                        <option key={f.key} value={f.key}>
                                            {f.name}
                                        </option>
                                    ))}
                            </Select>
                        )}
                    </>
                )}

                {type === 'random_split' && (
                    <div className="space-y-1.5">
                        {(config.branches ?? []).map((branch: any, i: number) => (
                            <div key={i} className="flex items-center gap-1.5">
                                <span className="w-8 text-xs font-bold uppercase text-slate-500">{branch.handle}</span>
                                <Input
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={branch.weight ?? 0}
                                    onChange={(e) => {
                                        const branches = [...(config.branches ?? [])];
                                        branches[i] = { ...branches[i], weight: Number(e.target.value) };
                                        set({ branches });
                                    }}
                                />
                                <span className="text-xs text-slate-400">%</span>
                            </div>
                        ))}
                    </div>
                )}

                {type === 'go_to_node' && (
                    <div>
                        <Label>Target node</Label>
                        <Select value={config.target_node_id ?? ''} onChange={(e) => set({ target_node_id: e.target.value })}>
                            <option value="">—</option>
                            {allNodes
                                .filter((n) => n.id !== node.id)
                                .map((n) => (
                                    <option key={n.id} value={n.id}>
                                        {t(nodeLabelKey(n.data.nodeType as string))} ({n.id.slice(-4)})
                                    </option>
                                ))}
                        </Select>
                    </div>
                )}

            </div>
        </div>
    );
}
