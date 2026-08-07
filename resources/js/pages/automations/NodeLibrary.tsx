import { PanelLeftClose, PanelLeftOpen, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Input } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { NODE_CATALOG, type NodeMeta } from './nodeCatalog';
import { nodeDescKey, nodeLabelKey } from './nodeI18n';
import { CATEGORY_ICONS, nodeIcon, solidIconTileStyle } from './nodeIcons';

const CATEGORIES = ['trigger', 'message', 'data', 'routing', 'control'] as NodeMeta['category'][];

const CATEGORY_KEYS: Record<NodeMeta['category'], string> = {
    trigger: 'automations.cat_trigger',
    message: 'automations.cat_message',
    data: 'automations.cat_data',
    routing: 'automations.cat_routing',
    control: 'automations.cat_control',
};

export default function NodeLibrary({
    onAdd,
    collapsed,
    onToggleCollapsed,
}: {
    onAdd: (type: string) => void;
    collapsed?: boolean;
    onToggleCollapsed?: () => void;
}) {
    const { t } = useI18n();
    const [search, setSearch] = useState('');

    const grouped = useMemo(() => {
        const q = search.trim().toLowerCase();

        return CATEGORIES.map((category) => ({
            category,
            nodes: NODE_CATALOG.filter((n) => {
                if (n.category !== category) return false;
                if (!q) return true;

                const label = t(nodeLabelKey(n.type)).toLowerCase();
                const desc = t(nodeDescKey(n.type)).toLowerCase();
                return (
                    label.includes(q) ||
                    desc.includes(q) ||
                    n.label.toLowerCase().includes(q) ||
                    n.type.toLowerCase().includes(q)
                );
            }),
        }));
    }, [search, t]);

    if (collapsed) {
        return (
            <div className="flex w-12 shrink-0 flex-col items-center gap-2 border-e border-slate-200 bg-white py-3">
                <button
                    type="button"
                    onClick={onToggleCollapsed}
                    className="rounded-xl p-2.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                    title={t('automations.expand_library')}
                    aria-label={t('automations.expand_library')}
                >
                    <PanelLeftOpen size={18} />
                </button>
            </div>
        );
    }

    return (
        <div className="flex w-[272px] shrink-0 flex-col border-e border-slate-200 bg-[#f7f8fa]">
            <div className="border-b border-slate-200/80 bg-white px-3 py-3">
                <div className="mb-2 flex items-center justify-between gap-2">
                    <div>
                        <p className="text-sm font-bold text-slate-900">{t('automations.library_title')}</p>
                        <p className="text-[11px] text-slate-500">{t('automations.library_hint')}</p>
                    </div>
                    <button
                        type="button"
                        onClick={onToggleCollapsed}
                        className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                        title={t('automations.collapse_library')}
                        aria-label={t('automations.collapse_library')}
                    >
                        <PanelLeftClose size={16} />
                    </button>
                </div>
                <Input
                    placeholder={t('automations.search_nodes')}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="!rounded-xl !border-slate-200 !bg-slate-50 !py-2 text-sm"
                />
            </div>

            <div className="flex-1 overflow-y-auto px-2.5 py-3">
                {grouped.map(
                    ({ category, nodes }) =>
                        nodes.length > 0 && (
                            <div key={category} className="mb-4">
                                {(() => {
                                    const CatIcon = CATEGORY_ICONS[category];
                                    return (
                                        <div className="mb-2 flex items-center gap-1.5 px-1.5">
                                            <CatIcon size={12} className="text-slate-400" strokeWidth={2.5} />
                                            <p className="text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                                {t(CATEGORY_KEYS[category])}
                                            </p>
                                        </div>
                                    );
                                })()}
                                <div className="space-y-1.5">
                                    {nodes.map((node) => {
                                        const Icon = nodeIcon(node.type);
                                        const label = t(nodeLabelKey(node.type));
                                        const desc = t(nodeDescKey(node.type));

                                        return (
                                            <button
                                                key={node.type}
                                                type="button"
                                                draggable
                                                onDragStart={(e) => {
                                                    e.dataTransfer.setData('application/x-node-type', node.type);
                                                    e.dataTransfer.effectAllowed = 'copy';
                                                }}
                                                onClick={() => onAdd(node.type)}
                                                title={desc}
                                                className="group flex w-full cursor-grab items-center gap-2.5 rounded-2xl border border-transparent bg-white px-2 py-2 text-start shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-px hover:border-slate-200 hover:shadow-md active:cursor-grabbing active:translate-y-0"
                                            >
                                                <span
                                                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] shadow-sm"
                                                    style={solidIconTileStyle(node.color)}
                                                >
                                                    <Icon size={16} strokeWidth={2.4} />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-[13px] font-semibold text-slate-800">
                                                        {label}
                                                    </span>
                                                    <span className="mt-0.5 block truncate text-[11px] text-slate-400 group-hover:text-slate-500">
                                                        {desc}
                                                    </span>
                                                </span>
                                                <Plus
                                                    size={14}
                                                    className="shrink-0 text-slate-300 opacity-0 transition group-hover:opacity-100"
                                                />
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ),
                )}
            </div>
        </div>
    );
}
