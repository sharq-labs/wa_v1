import { clsx } from 'clsx';
import {
    Calendar,
    Check,
    Clock3,
    Filter,
    GitBranch,
    MessageSquareText,
    Plus,
    Tag,
    Trash2,
    UserRound,
    Variable,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button, Input, Select } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import type { CustomField, Tag as TagType } from '@/types';

const OPERATORS = [
    'equals',
    'not_equals',
    'contains',
    'not_contains',
    'starts_with',
    'ends_with',
    'greater_than',
    'less_than',
    'greater_or_equal',
    'less_or_equal',
    'empty',
    'not_empty',
    'exists',
    'not_exists',
] as const;

const CONDITION_SOURCES = [
    'message',
    'contact',
    'custom',
    'variable',
    'tag',
    'conversation_status',
    'working_hours',
    'date',
    'time',
] as const;

const OPERATORS_WITHOUT_VALUE = new Set(['empty', 'not_empty', 'exists', 'not_exists']);
const SOURCES_WITH_KEY = new Set(['contact', 'custom', 'variable']);

const CONTACT_FIELDS = [
    { key: 'first_name', labelKey: 'automations.condition_contact_first_name' },
    { key: 'last_name', labelKey: 'automations.condition_contact_last_name' },
    { key: 'phone', labelKey: 'automations.condition_contact_phone' },
    { key: 'email', labelKey: 'automations.condition_contact_email' },
    { key: 'country', labelKey: 'automations.condition_contact_country' },
] as const;

const SOURCE_ICONS: Record<string, LucideIcon> = {
    message: MessageSquareText,
    contact: UserRound,
    custom: Filter,
    variable: Variable,
    tag: Tag,
    conversation_status: MessageSquareText,
    working_hours: Clock3,
    date: Calendar,
    time: Clock3,
};

type Condition = {
    source?: string;
    key?: string;
    operator?: string;
    value?: string;
};

function ruleComplete(condition: Condition): boolean {
    const source = condition.source ?? 'message';
    const operator = condition.operator ?? 'equals';
    if (SOURCES_WITH_KEY.has(source) && !String(condition.key ?? '').trim()) return false;
    if (!OPERATORS_WITHOUT_VALUE.has(operator) && source === 'tag' && !String(condition.value ?? '').trim()) return false;
    if (!OPERATORS_WITHOUT_VALUE.has(operator) && source === 'custom' && !String(condition.key ?? '').trim()) return false;
    return true;
}

function formatRuleSummary(
    condition: Condition,
    t: (key: string, params?: Record<string, string | number>) => string,
    fieldName?: string,
): string {
    const source = condition.source ?? 'message';
    const operator = condition.operator ?? 'equals';
    const sourceLabel = t(`automations.condition_source_${source}`);
    const opLabel = t(`automations.op_${operator}`);
    const keyLabel = fieldName || condition.key || '';
    const subject = keyLabel ? `${sourceLabel} · ${keyLabel}` : sourceLabel;

    if (OPERATORS_WITHOUT_VALUE.has(operator)) {
        return `${subject} — ${opLabel}`;
    }

    let valueLabel = String(condition.value ?? '').trim();
    if (source === 'working_hours') {
        valueLabel = ['1', 'true', 'yes'].includes(String(condition.value ?? '1'))
            ? t('automations.condition_working_hours_yes')
            : t('automations.condition_working_hours_no');
    }
    if (!valueLabel) valueLabel = '…';

    return `${subject} ${opLabel} “${valueLabel}”`;
}

export default function ConditionSettings({
    match,
    conditions,
    customFields,
    flowVariables,
    tags,
    onChange,
}: {
    match: string;
    conditions: Condition[];
    customFields: CustomField[];
    flowVariables: string[];
    tags: TagType[];
    onChange: (patch: { match?: string; conditions?: Condition[] }) => void;
}) {
    const { t } = useI18n();
    const matchMode = match === 'any' ? 'any' : 'all';

    const update = (index: number, patch: Partial<Condition>) => {
        const next = conditions.map((c, i) => (i === index ? { ...c, ...patch } : c));
        onChange({ conditions: next });
    };

    const remove = (index: number) => {
        onChange({ conditions: conditions.filter((_, i) => i !== index) });
    };

    const add = () => {
        onChange({
            conditions: [...conditions, { source: 'custom', key: '', operator: 'equals', value: '' }],
        });
    };

    return (
        <div className="space-y-3">
            {/* Match + outcomes */}
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
                <div className="space-y-3 p-3.5">
                    <div className="flex items-start gap-2.5">
                        <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                            <GitBranch size={15} strokeWidth={2.4} />
                        </span>
                        <div className="min-w-0">
                            <p className="text-[13px] font-semibold text-slate-900">{t('automations.condition_match')}</p>
                            <p className="mt-0.5 text-[12px] leading-snug text-slate-500">{t('automations.condition_intro')}</p>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-1 rounded-xl bg-slate-100/90 p-1">
                        {(
                            [
                                { value: 'all' as const, label: t('automations.condition_match_all_short'), code: 'AND' },
                                { value: 'any' as const, label: t('automations.condition_match_any_short'), code: 'OR' },
                            ]
                        ).map((opt) => {
                            const active = matchMode === opt.value;
                            return (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() => onChange({ match: opt.value })}
                                    className={clsx(
                                        'rounded-lg px-3 py-2.5 text-center transition-all duration-150',
                                        active
                                            ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200/80'
                                            : 'text-slate-500 hover:text-slate-700',
                                    )}
                                >
                                    <span className="block text-[13px] font-semibold">{opt.label}</span>
                                    <span className="mt-0.5 block text-[10px] font-medium tracking-wide text-slate-400">
                                        {opt.code}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-[11px] leading-snug text-slate-400">{t('automations.condition_match_hint')}</p>
                </div>

                <div className="grid grid-cols-2 gap-px border-t border-slate-100 bg-slate-100">
                    <div className="flex items-center gap-2 bg-emerald-50/70 px-3 py-2.5">
                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white">
                            <Check size={11} strokeWidth={3} />
                        </span>
                        <div className="min-w-0">
                            <p className="text-[11px] font-semibold text-emerald-800">{t('automations.condition_true')}</p>
                            <p className="truncate text-[10px] text-emerald-700/80">{t('automations.condition_outcome_true')}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 bg-rose-50/70 px-3 py-2.5">
                        <span className="flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-white">
                            <X size={11} strokeWidth={3} />
                        </span>
                        <div className="min-w-0">
                            <p className="text-[11px] font-semibold text-rose-800">{t('automations.condition_false')}</p>
                            <p className="truncate text-[10px] text-rose-700/80">{t('automations.condition_outcome_false')}</p>
                        </div>
                    </div>
                </div>
            </section>

            {/* Rules */}
            <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                        {t('automations.condition_rules')}
                    </p>
                    <span
                        className={clsx(
                            'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                            conditions.length === 0 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600',
                        )}
                    >
                        {conditions.length}
                    </span>
                </div>
                <p className="text-[12px] leading-snug text-slate-500">{t('automations.condition_rules_hint')}</p>

                {conditions.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-200 bg-gradient-to-b from-slate-50 to-white px-4 py-6 text-center">
                        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                            <Filter size={18} strokeWidth={2.2} />
                        </span>
                        <div>
                            <p className="text-[13px] font-semibold text-slate-800">{t('automations.condition_empty_title')}</p>
                            <p className="mt-1 text-[12px] leading-snug text-slate-500">{t('automations.condition_empty')}</p>
                        </div>
                        <Button variant="primary" size="sm" onClick={add}>
                            <Plus size={14} />
                            {t('automations.condition_empty_cta')}
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-2.5">
                        {conditions.map((condition, i) => {
                            const source = condition.source ?? 'message';
                            const operator = condition.operator ?? 'equals';
                            const needsKey = SOURCES_WITH_KEY.has(source);
                            const needsValue = !OPERATORS_WITHOUT_VALUE.has(operator);
                            const SourceIcon = SOURCE_ICONS[source] ?? Filter;
                            const fieldMeta = customFields.find((f) => f.key === condition.key);
                            const knownCustom = Boolean(fieldMeta);
                            const knownVariable = flowVariables.includes(condition.key ?? '');
                            const knownTag = tags.some((tag) => tag.name === condition.value);
                            const knownContact = CONTACT_FIELDS.some((f) => f.key === condition.key);
                            const complete = ruleComplete(condition);
                            const summary = formatRuleSummary(condition, t, fieldMeta?.name);

                            return (
                                <div
                                    key={i}
                                    className={clsx(
                                        'overflow-hidden rounded-xl border transition-colors duration-150',
                                        complete
                                            ? 'border-slate-200 bg-slate-50/50'
                                            : 'border-amber-200 bg-amber-50/30',
                                    )}
                                >
                                    <div className="flex items-center gap-2 border-b border-slate-200/70 bg-white/80 px-2.5 py-2">
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[11px] font-bold text-slate-500">
                                            {i + 1}
                                        </span>
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                            <SourceIcon size={12} strokeWidth={2.4} />
                                        </span>
                                        <p className="min-w-0 flex-1 truncate text-[12px] font-medium text-slate-700">
                                            {summary}
                                        </p>
                                        <button
                                            type="button"
                                            title={t('common.delete')}
                                            className="rounded-lg p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                            onClick={() => remove(i)}
                                        >
                                            <Trash2 size={14} />
                                        </button>
                                    </div>

                                    <div className="space-y-2 p-2.5">
                                        <Select
                                            value={source}
                                            className="!bg-white !py-2 !text-[13px]"
                                            onChange={(e) => update(i, { source: e.target.value, key: '', value: '' })}
                                        >
                                            {CONDITION_SOURCES.map((s) => (
                                                <option key={s} value={s}>
                                                    {t(`automations.condition_source_${s}`)}
                                                </option>
                                            ))}
                                        </Select>

                                        {needsKey && (
                                            <>
                                                {source === 'contact' ? (
                                                    <>
                                                        <Select
                                                            value={knownContact ? condition.key ?? '' : ''}
                                                            className="!bg-white !py-2 !text-[13px]"
                                                            onChange={(e) => update(i, { key: e.target.value })}
                                                        >
                                                            <option value="">{t('automations.condition_pick_field')}</option>
                                                            {CONTACT_FIELDS.map((f) => (
                                                                <option key={f.key} value={f.key}>
                                                                    {t(f.labelKey)}
                                                                </option>
                                                            ))}
                                                        </Select>
                                                        {!knownContact && (
                                                            <Input
                                                                value={condition.key ?? ''}
                                                                className="!bg-white !py-2 !text-[13px]"
                                                                placeholder={t('automations.condition_or_type')}
                                                                onChange={(e) => update(i, { key: e.target.value })}
                                                            />
                                                        )}
                                                    </>
                                                ) : source === 'custom' && customFields.length > 0 ? (
                                                    <>
                                                        <Select
                                                            value={knownCustom ? condition.key ?? '' : ''}
                                                            className="!bg-white !py-2 !text-[13px]"
                                                            onChange={(e) => update(i, { key: e.target.value })}
                                                        >
                                                            <option value="">{t('automations.condition_pick_field')}</option>
                                                            {customFields.map((f) => (
                                                                <option key={f.key} value={f.key}>
                                                                    {f.name}
                                                                </option>
                                                            ))}
                                                        </Select>
                                                        {!knownCustom && (
                                                            <Input
                                                                value={condition.key ?? ''}
                                                                className="!bg-white !py-2 !text-[13px]"
                                                                placeholder={t('automations.condition_or_type')}
                                                                onChange={(e) => update(i, { key: e.target.value })}
                                                            />
                                                        )}
                                                    </>
                                                ) : source === 'variable' && flowVariables.length > 0 ? (
                                                    <>
                                                        <Select
                                                            value={knownVariable ? condition.key ?? '' : ''}
                                                            className="!bg-white !py-2 !text-[13px]"
                                                            onChange={(e) => update(i, { key: e.target.value })}
                                                        >
                                                            <option value="">{t('automations.condition_pick_variable')}</option>
                                                            {flowVariables.map((v) => (
                                                                <option key={v} value={v}>
                                                                    {v}
                                                                </option>
                                                            ))}
                                                        </Select>
                                                        {!knownVariable && (
                                                            <Input
                                                                value={condition.key ?? ''}
                                                                className="!bg-white !py-2 !text-[13px]"
                                                                placeholder={t('automations.condition_or_type')}
                                                                onChange={(e) => update(i, { key: e.target.value })}
                                                            />
                                                        )}
                                                    </>
                                                ) : (
                                                    <Input
                                                        value={condition.key ?? ''}
                                                        className="!bg-white !py-2 !text-[13px]"
                                                        placeholder={
                                                            source === 'variable'
                                                                ? t('automations.condition_key_variable_ph')
                                                                : t('automations.condition_key_custom_ph')
                                                        }
                                                        onChange={(e) => update(i, { key: e.target.value })}
                                                    />
                                                )}
                                            </>
                                        )}

                                        <div className={clsx('grid gap-1.5', needsValue ? 'grid-cols-2' : 'grid-cols-1')}>
                                            <Select
                                                value={operator}
                                                className="!bg-white !py-2 !text-[13px]"
                                                onChange={(e) => update(i, { operator: e.target.value })}
                                            >
                                                {OPERATORS.map((op) => (
                                                    <option key={op} value={op}>
                                                        {t(`automations.op_${op}`)}
                                                    </option>
                                                ))}
                                            </Select>

                                            {needsValue &&
                                                (source === 'working_hours' ? (
                                                    <Select
                                                        value={
                                                            ['1', 'true', 'yes'].includes(String(condition.value ?? '1'))
                                                                ? '1'
                                                                : '0'
                                                        }
                                                        className="!bg-white !py-2 !text-[13px]"
                                                        onChange={(e) => update(i, { value: e.target.value })}
                                                    >
                                                        <option value="1">{t('automations.condition_working_hours_yes')}</option>
                                                        <option value="0">{t('automations.condition_working_hours_no')}</option>
                                                    </Select>
                                                ) : source === 'tag' && tags.length > 0 ? (
                                                    <div className="space-y-1.5">
                                                        <Select
                                                            value={knownTag ? condition.value ?? '' : ''}
                                                            className="!bg-white !py-2 !text-[13px]"
                                                            onChange={(e) => update(i, { value: e.target.value })}
                                                        >
                                                            <option value="">{t('automations.condition_pick_tag')}</option>
                                                            {tags.map((tag) => (
                                                                <option key={tag.id} value={tag.name}>
                                                                    {tag.name}
                                                                </option>
                                                            ))}
                                                        </Select>
                                                        {!knownTag && (
                                                            <Input
                                                                value={condition.value ?? ''}
                                                                className="!bg-white !py-2 !text-[13px]"
                                                                placeholder={t('automations.condition_or_type')}
                                                                onChange={(e) => update(i, { value: e.target.value })}
                                                            />
                                                        )}
                                                    </div>
                                                ) : (
                                                    <Input
                                                        value={condition.value ?? ''}
                                                        className="!bg-white !py-2 !text-[13px]"
                                                        placeholder={
                                                            source === 'tag'
                                                                ? t('automations.condition_key_tag_ph')
                                                                : t('automations.condition_value_ph')
                                                        }
                                                        onChange={(e) => update(i, { value: e.target.value })}
                                                    />
                                                ))}
                                        </div>

                                        {!complete && (
                                            <p className="text-[11px] font-medium text-amber-700">
                                                {t('automations.condition_incomplete')}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            );
                        })}

                        <Button variant="secondary" size="sm" className="w-full" onClick={add}>
                            <Plus size={14} />
                            {t('automations.condition_add')}
                        </Button>
                    </div>
                )}
            </section>
        </div>
    );
}
