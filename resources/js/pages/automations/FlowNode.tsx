import { Handle, Position, type NodeProps } from '@xyflow/react';
import { clsx } from 'clsx';
import { Copy, Settings2, Trash2 } from 'lucide-react';
import { memo, type ChangeEvent, type MouseEvent, type ReactNode } from 'react';
import { Select } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { NODE_META, nodeHandles } from './nodeCatalog';
import { nodeDescKey, nodeLabelKey } from './nodeI18n';
import { nodeIcon } from './nodeIcons';

export type FlowNodeActions = {
    onConfigChange?: (config: Record<string, any>) => void;
    onDelete?: () => void;
    onDuplicate?: () => void;
    onOpenSettings?: () => void;
};

const fieldClass =
    'nodrag nopan w-full rounded-lg border border-slate-200 bg-panel px-2.5 py-1.5 text-[12px] leading-snug text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15';

function stopNodeDrag(e: MouseEvent | ChangeEvent) {
    e.stopPropagation();
}

function Field({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
    return (
        <label className="nodrag nopan block space-y-1" onMouseDown={stopNodeDrag} onPointerDown={stopNodeDrag}>
            <span className="flex items-baseline justify-between gap-2">
                <span className="text-[11px] font-medium text-slate-500">{label}</span>
                {hint && <span className="text-[10px] text-slate-400">{hint}</span>}
            </span>
            {children}
        </label>
    );
}

function ChipRow({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap gap-1">{children}</div>;
}

function Chip({ children }: { children: ReactNode }) {
    return (
        <span className="max-w-full truncate rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
            {children}
        </span>
    );
}

function ActionButton({
    title,
    onClick,
    danger,
    children,
}: {
    title: string;
    onClick?: () => void;
    danger?: boolean;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            title={title}
            onClick={(e) => {
                e.stopPropagation();
                onClick?.();
            }}
            onMouseDown={stopNodeDrag}
            onPointerDown={stopNodeDrag}
            className={clsx(
                'nodrag nopan rounded-md p-1 transition',
                danger ? 'text-white/80 hover:bg-white/20 hover:text-white' : 'text-white/80 hover:bg-white/20 hover:text-white',
            )}
        >
            {children}
        </button>
    );
}

/**
 * Canvas node with inline config fields and per-box actions (settings / duplicate / delete).
 */
function FlowNodeComponent({ id, data, selected }: NodeProps) {
    const { t } = useI18n();
    const type = data.nodeType as string;
    const config = (data.config ?? {}) as Record<string, any>;
    const meta = NODE_META[type];
    const handles = nodeHandles(type, config);
    const isTrigger = type.startsWith('trigger_');
    const highlighted = Boolean(data.highlighted);
    const color = meta?.color ?? '#94a3b8';
    const actions = data as FlowNodeActions;
    const Icon = nodeIcon(type);
    const title = t(nodeLabelKey(type));
    const fallbackDesc = t(nodeDescKey(type));

    const patch = (partial: Record<string, any>) => {
        actions.onConfigChange?.({ ...config, ...partial });
    };

    const body = (() => {
        switch (type) {
            case 'trigger_incoming_message':
            case 'trigger_new_contact':
                return <p className="text-[12px] leading-relaxed text-slate-600">{fallbackDesc}</p>;

            case 'trigger_keyword':
                return (
                    <div className="space-y-2">
                        <Field label="Keywords">
                            <input
                                className={fieldClass}
                                value={(config.keywords ?? []).join(', ')}
                                placeholder="سعر, price, hello"
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({
                                        keywords: e.target.value
                                            .split(',')
                                            .map((s) => s.trim())
                                            .filter(Boolean),
                                    });
                                }}
                            />
                        </Field>
                        <Field label="Match">
                            <Select
                                size="sm" className="nodrag nopan"
                                value={config.match_type ?? 'contains'}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ match_type: e.target.value });
                                }}
                            >
                                <option value="contains">Contains</option>
                                <option value="exact">Exact</option>
                                <option value="starts_with">Starts with</option>
                                <option value="ends_with">Ends with</option>
                            </Select>
                        </Field>
                    </div>
                );

            case 'send_text':
            case 'add_note':
                return (
                    <Field label={type === 'add_note' ? t('automations.field_note') : t('automations.field_message')}>
                        <textarea
                            className={`${fieldClass} min-h-[72px] resize-y`}
                            rows={3}
                            value={config.text ?? config.body ?? ''}
                            placeholder={t('automations.placeholder_message')}
                            onChange={(e) => {
                                stopNodeDrag(e);
                                patch(type === 'add_note' ? { body: e.target.value } : { text: e.target.value });
                            }}
                        />
                    </Field>
                );

            case 'ask_question':
                return (
                    <div className="space-y-2">
                        <Field label={t('automations.field_question')}>
                            <textarea
                                className={`${fieldClass} min-h-[64px] resize-y`}
                                rows={2}
                                value={config.question ?? ''}
                                placeholder={t('automations.placeholder_question')}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ question: e.target.value });
                                }}
                            />
                        </Field>
                        <Field label={t('automations.save_to')}>
                            <input
                                className={fieldClass}
                                value={config.save_to ?? ''}
                                placeholder="custom.name / variables.answer"
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ save_to: e.target.value });
                                }}
                            />
                        </Field>
                        <Field label={t('automations.field_validation')}>
                            <Select
                                size="sm" className="nodrag nopan"
                                value={config.validation ?? 'text'}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ validation: e.target.value });
                                }}
                            >
                                {['text', 'number', 'email', 'phone', 'date', 'choice'].map((v) => (
                                    <option key={v} value={v}>
                                        {v}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                );

            case 'send_buttons': {
                const buttons = config.buttons ?? [];
                return (
                    <div className="space-y-2">
                        <Field label={t('automations.field_body')}>
                            <textarea
                                className={`${fieldClass} min-h-[52px] resize-y`}
                                rows={2}
                                value={config.body ?? ''}
                                placeholder={t('automations.placeholder_choose')}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ body: e.target.value });
                                }}
                            />
                        </Field>
                        <div className="space-y-1.5">
                            <p className="text-[11px] font-medium text-slate-500">{t('automations.field_buttons')}</p>
                            <ChipRow>
                                {buttons.map((button: any, i: number) => (
                                    <Chip key={button.id ?? i}>{button.title || `${t('automations.option')} ${i + 1}`}</Chip>
                                ))}
                            </ChipRow>
                            <button
                                type="button"
                                className="nodrag nopan text-[11px] font-semibold text-brand-700 hover:text-brand-800"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    actions.onOpenSettings?.();
                                }}
                                onMouseDown={stopNodeDrag}
                            >
                                {t('automations.node_settings')} →
                            </button>
                        </div>
                    </div>
                );
            }

            case 'send_list': {
                const sections = config.sections ?? [];
                const rows = sections.flatMap((section: any) => section.rows ?? []);
                return (
                    <div className="space-y-2">
                        <Field label={t('automations.field_body')}>
                            <textarea
                                className={`${fieldClass} min-h-[52px] resize-y`}
                                rows={2}
                                value={config.body ?? ''}
                                placeholder={t('automations.placeholder_list_body')}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ body: e.target.value });
                                }}
                            />
                        </Field>
                        <Field label={t('automations.list_button_label')}>
                            <input
                                className={fieldClass}
                                maxLength={20}
                                value={config.button ?? ''}
                                placeholder={t('automations.list_button_placeholder')}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ button: e.target.value });
                                }}
                            />
                        </Field>
                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-[11px] font-medium text-slate-500">{t('automations.list_sections')}</p>
                                <span className="text-[10px] text-slate-400">
                                    {sections.length} / {rows.length} {t('automations.list_rows')}
                                </span>
                            </div>
                            <ChipRow>
                                {rows.slice(0, 4).map((row: any, i: number) => (
                                    <Chip key={row.id ?? i}>{row.title || `${t('automations.option')} ${i + 1}`}</Chip>
                                ))}
                                {rows.length > 4 && <Chip>+{rows.length - 4}</Chip>}
                            </ChipRow>
                            <button
                                type="button"
                                className="nodrag nopan text-[11px] font-semibold text-brand-700 hover:text-brand-800"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    actions.onOpenSettings?.();
                                }}
                                onMouseDown={stopNodeDrag}
                            >
                                {t('automations.edit_sections')} →
                            </button>
                        </div>
                    </div>
                );
            }

            case 'send_image':
            case 'send_video':
            case 'send_audio':
            case 'send_document':
                return (
                    <div className="space-y-2">
                        <Field label="URL">
                            <input
                                className={fieldClass}
                                value={config.url ?? ''}
                                placeholder="https://…"
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ url: e.target.value });
                                }}
                            />
                        </Field>
                        {type !== 'send_audio' && (
                            <Field label="Caption">
                                <input
                                    className={fieldClass}
                                    value={config.caption ?? ''}
                                    onChange={(e) => {
                                        stopNodeDrag(e);
                                        patch({ caption: e.target.value });
                                    }}
                                />
                            </Field>
                        )}
                    </div>
                );

            case 'send_template':
                return (
                    <Field label="Template ID">
                        <input
                            className={fieldClass}
                            type="number"
                            value={config.template_id ?? ''}
                            placeholder="Select in settings…"
                            onChange={(e) => {
                                stopNodeDrag(e);
                                patch({ template_id: e.target.value ? Number(e.target.value) : null });
                            }}
                        />
                    </Field>
                );

            case 'set_custom_field':
                return (
                    <div className="space-y-2">
                        <Field label="Field key">
                            <input
                                className={fieldClass}
                                value={config.field_key ?? ''}
                                placeholder="city"
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ field_key: e.target.value });
                                }}
                            />
                        </Field>
                        <Field label="Value">
                            <input
                                className={fieldClass}
                                value={config.value ?? ''}
                                placeholder="{{variables.city}}"
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ value: e.target.value });
                                }}
                            />
                        </Field>
                    </div>
                );

            case 'clear_custom_field':
                return (
                    <Field label="Field key">
                        <input
                            className={fieldClass}
                            value={config.field_key ?? ''}
                            onChange={(e) => {
                                stopNodeDrag(e);
                                patch({ field_key: e.target.value });
                            }}
                        />
                    </Field>
                );

            case 'add_tag':
            case 'remove_tag':
                return (
                    <Field label="Tag name">
                        <input
                            className={fieldClass}
                            value={config.tag_name ?? ''}
                            placeholder="vip"
                            onChange={(e) => {
                                stopNodeDrag(e);
                                patch({ tag_name: e.target.value, tag_id: null });
                            }}
                        />
                    </Field>
                );

            case 'http_request':
            case 'send_webhook':
                return (
                    <div className="space-y-2">
                        <div className="grid grid-cols-[72px_1fr] gap-1.5">
                            <Field label="Method">
                                <Select
                                size="sm" className="nodrag nopan"
                                    value={config.method ?? 'POST'}
                                    onChange={(e) => {
                                        stopNodeDrag(e);
                                        patch({ method: e.target.value });
                                    }}
                                >
                                    {['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => (
                                        <option key={m} value={m}>
                                            {m}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="URL">
                                <input
                                    className={fieldClass}
                                    value={config.url ?? ''}
                                    placeholder="https://api.example.com"
                                    onChange={(e) => {
                                        stopNodeDrag(e);
                                        patch({ url: e.target.value });
                                    }}
                                />
                            </Field>
                        </div>
                    </div>
                );

            case 'assign_agent':
                return (
                    <div className="space-y-2">
                        <Field label="Agent ID">
                            <input
                                className={fieldClass}
                                type="number"
                                value={config.user_id ?? ''}
                                placeholder="optional"
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ user_id: e.target.value ? Number(e.target.value) : null });
                                }}
                            />
                        </Field>
                        <Field label="Team ID">
                            <input
                                className={fieldClass}
                                type="number"
                                value={config.team_id ?? ''}
                                placeholder="optional"
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ team_id: e.target.value ? Number(e.target.value) : null });
                                }}
                            />
                        </Field>
                        <Field label="Strategy">
                            <Select
                                size="sm" className="nodrag nopan"
                                value={config.strategy ?? 'round_robin'}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ strategy: e.target.value });
                                }}
                            >
                                <option value="round_robin">Round robin</option>
                                <option value="least_busy">Least busy</option>
                                <option value="random">Random</option>
                            </Select>
                        </Field>
                    </div>
                );

            case 'condition': {
                const conditions = config.conditions ?? [];
                const isAny = (config.match ?? 'all') === 'any';
                const matchLabel = isAny
                    ? t('automations.condition_match_any_short')
                    : t('automations.condition_match_all_short');

                const ruleLine = (condition: any, i: number) => {
                    const source = condition.source ?? 'message';
                    const operator = condition.operator ?? 'equals';
                    const sourceLabel = t(`automations.condition_source_${source}`);
                    const opLabel = t(`automations.op_${operator}`);
                    const keyPart = condition.key ? ` · ${condition.key}` : '';
                    const needsValue = !['empty', 'not_empty', 'exists', 'not_exists'].includes(operator);
                    let valuePart = '';
                    if (needsValue && condition.value != null && String(condition.value) !== '') {
                        if (source === 'working_hours') {
                            valuePart = ['1', 'true', 'yes'].includes(String(condition.value))
                                ? t('automations.condition_working_hours_yes')
                                : t('automations.condition_working_hours_no');
                        } else {
                            valuePart = `“${String(condition.value)}”`;
                        }
                    }
                    const text = [sourceLabel + keyPart, opLabel, valuePart].filter(Boolean).join(' ');
                    return (
                        <div
                            key={i}
                            className="flex items-start gap-1.5 rounded-lg bg-slate-50 px-2 py-1.5 text-[11px] leading-snug text-slate-700"
                        >
                            <span className="mt-px flex h-4 w-4 shrink-0 items-center justify-center rounded-md bg-white text-[10px] font-bold text-slate-400 ring-1 ring-slate-200">
                                {i + 1}
                            </span>
                            <span className="min-w-0 flex-1 truncate">{text || t('automations.condition_rule', { n: i + 1 })}</span>
                        </div>
                    );
                };

                return (
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <span className="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-2 py-1 text-[11px] font-semibold text-blue-700">
                                {matchLabel}
                                <span className="rounded bg-white/80 px-1 py-px text-[9px] font-bold tracking-wide text-blue-500">
                                    {isAny ? 'OR' : 'AND'}
                                </span>
                            </span>
                            <span className="text-[10px] font-medium text-slate-400">
                                {t('automations.condition_count', { count: conditions.length })}
                            </span>
                        </div>

                        {conditions.length === 0 ? (
                            <p className="rounded-lg border border-dashed border-amber-200 bg-amber-50/50 px-2 py-2 text-center text-[11px] text-amber-700">
                                {t('automations.condition_empty')}
                            </p>
                        ) : (
                            <div className="space-y-1">
                                {conditions.slice(0, 3).map(ruleLine)}
                                {conditions.length > 3 && (
                                    <p className="px-1 text-[10px] font-medium text-slate-400">+{conditions.length - 3}</p>
                                )}
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-1">
                            <span className="flex items-center justify-center gap-1 rounded-md bg-emerald-50 py-1 text-[10px] font-bold text-emerald-700">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                {t('automations.condition_true')}
                            </span>
                            <span className="flex items-center justify-center gap-1 rounded-md bg-rose-50 py-1 text-[10px] font-bold text-rose-700">
                                <span className="h-1.5 w-1.5 rounded-full bg-rose-500" />
                                {t('automations.condition_false')}
                            </span>
                        </div>

                        <button
                            type="button"
                            className="nodrag nopan text-[11px] font-semibold text-brand-700 hover:text-brand-800"
                            onClick={(e) => {
                                e.stopPropagation();
                                actions.onOpenSettings?.();
                            }}
                            onMouseDown={stopNodeDrag}
                        >
                            {t('automations.condition_edit_rules')} →
                        </button>
                    </div>
                );
            }

            case 'delay':
                return (
                    <div className="grid grid-cols-2 gap-1.5">
                        <Field label="Amount">
                            <input
                                className={fieldClass}
                                type="number"
                                min={1}
                                value={config.amount ?? 5}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ amount: Number(e.target.value) || 1 });
                                }}
                            />
                        </Field>
                        <Field label="Unit">
                            <Select
                                size="sm" className="nodrag nopan"
                                value={config.unit ?? 'minutes'}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ unit: e.target.value });
                                }}
                            >
                                <option value="seconds">Seconds</option>
                                <option value="minutes">Minutes</option>
                                <option value="hours">Hours</option>
                                <option value="days">Days</option>
                            </Select>
                        </Field>
                    </div>
                );

            case 'wait_until':
                return (
                    <div className="space-y-2">
                        <Field label="Mode">
                            <Select
                                size="sm" className="nodrag nopan"
                                value={config.mode ?? 'tomorrow'}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ mode: e.target.value });
                                }}
                            >
                                <option value="tomorrow">Tomorrow</option>
                                <option value="weekday">Weekday</option>
                                <option value="datetime">Date & time</option>
                            </Select>
                        </Field>
                        <Field label="Time">
                            <input
                                className={fieldClass}
                                type="time"
                                value={config.time ?? '09:00'}
                                onChange={(e) => {
                                    stopNodeDrag(e);
                                    patch({ time: e.target.value });
                                }}
                            />
                        </Field>
                    </div>
                );

            case 'random_split':
                return (
                    <div className="space-y-1.5">
                        {(config.branches ?? []).map((branch: any, i: number) => (
                            <div key={branch.handle ?? i} className="nodrag nopan flex items-center gap-1.5" onMouseDown={stopNodeDrag}>
                                <span className="w-6 text-[11px] font-bold text-slate-500">{(branch.handle ?? '').toUpperCase()}</span>
                                <input
                                    className={fieldClass}
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={branch.weight ?? 0}
                                    onChange={(e) => {
                                        stopNodeDrag(e);
                                        const branches = [...(config.branches ?? [])];
                                        branches[i] = { ...branches[i], weight: Number(e.target.value) || 0 };
                                        patch({ branches });
                                    }}
                                />
                                <span className="text-[11px] text-slate-400">%</span>
                            </div>
                        ))}
                    </div>
                );

            case 'go_to_node':
                return (
                    <Field label="Target node ID">
                        <input
                            className={fieldClass}
                            value={config.target_node_id ?? ''}
                            onChange={(e) => {
                                stopNodeDrag(e);
                                patch({ target_node_id: e.target.value });
                            }}
                        />
                    </Field>
                );

            case 'start_automation':
                return (
                    <Field label="Automation ID">
                        <input
                            className={fieldClass}
                            type="number"
                            value={config.automation_id ?? ''}
                            onChange={(e) => {
                                stopNodeDrag(e);
                                patch({ automation_id: e.target.value ? Number(e.target.value) : null });
                            }}
                        />
                    </Field>
                );

            case 'stop':
            case 'unassign_agent':
            case 'pause_bot':
            case 'resume_bot':
            case 'close_conversation':
            case 'reopen_conversation':
                return <p className="text-[12px] leading-relaxed text-slate-600">{fallbackDesc}</p>;

            default:
                return <p className="text-[12px] leading-relaxed text-slate-600">{fallbackDesc || '—'}</p>;
        }
    })();

    return (
        <div
            className={clsx(
                'w-[280px] overflow-hidden rounded-xl border bg-white shadow-xs transition-shadow',
                selected ? 'shadow-sm' : '',
                highlighted && 'ring-4 ring-amber-300',
            )}
            style={{ borderColor: selected || highlighted ? color : '#e2e8f0' }}
            data-node-id={id}
        >
            {!isTrigger && <Handle type="target" position={Position.Top} className="!h-2.5 !w-2.5 !bg-slate-400" />}

            <div className="flex items-center gap-2 px-2.5 py-2 text-white" style={{ backgroundColor: color }}>
                <span
                    className="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm"
                    style={{ color }}
                >
                    <Icon size={13} strokeWidth={2.5} />
                </span>
                <span className="min-w-0 flex-1 truncate text-[12.5px] font-bold">
                    {title || meta?.label || type}
                </span>
                <div className="flex shrink-0 items-center gap-0.5">
                    <ActionButton title={t('automations.node_settings')} onClick={actions.onOpenSettings}>
                        <Settings2 size={13} />
                    </ActionButton>
                    {!isTrigger && (
                        <ActionButton title={t('automations.node_duplicate')} onClick={actions.onDuplicate}>
                            <Copy size={13} />
                        </ActionButton>
                    )}
                    <ActionButton title={t('automations.node_delete')} danger onClick={actions.onDelete}>
                        <Trash2 size={13} />
                    </ActionButton>
                </div>
            </div>

            <div className="space-y-2 px-2.5 py-2.5">{body}</div>

            {handles.length > 0 && (
                <div className="relative flex justify-around border-t border-slate-100 px-2 pb-2 pt-1.5">
                    {handles.map((handle) => {
                        const handleColor =
                            handle.id === 'true' || handle.id === 'next'
                                ? '#16a34a'
                                : handle.id === 'false' || handle.id === 'error'
                                  ? '#dc2626'
                                  : '#64748b';
                        return (
                            <div key={handle.id} className="relative flex flex-col items-center" style={{ minWidth: 28 }}>
                                {handle.label && (
                                    <span className="mb-0.5 max-w-[72px] truncate text-[10px] font-semibold text-slate-500">
                                        {handle.id === 'true'
                                            ? t('automations.condition_true')
                                            : handle.id === 'false'
                                              ? t('automations.condition_false')
                                              : handle.label}
                                    </span>
                                )}
                                <Handle
                                    id={handle.id}
                                    type="source"
                                    position={Position.Bottom}
                                    className="!static !h-2.5 !w-2.5 !translate-x-0 !translate-y-0 !border-2 !border-white"
                                    style={{ position: 'relative', backgroundColor: handleColor }}
                                />
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default memo(FlowNodeComponent);
