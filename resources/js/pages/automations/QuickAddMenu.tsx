import { Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useI18n } from '@/lib/i18n';
import { NODE_META } from './nodeCatalog';
import { nodeLabelKey } from './nodeI18n';
import { nodeIcon, solidIconTileStyle } from './nodeIcons';

export type QuickAddMode = 'insert' | 'append';

interface QuickAddMenuProps {
    onSelect: (type: string) => void;
    onClose?: () => void;
    mode?: QuickAddMode;
}

const GROUPS = [
    { key: 'message', label: { en: 'WhatsApp message', ar: 'رسالة واتساب' }, types: ['send_text', 'send_image', 'send_video', 'send_audio', 'send_document', 'send_template', 'ask_question'] },
    { key: 'action', label: { en: 'Actions', ar: 'إجراءات' }, types: ['add_tag', 'set_custom_field', 'add_note', 'assign_agent'] },
    { key: 'timing', label: { en: 'Timing', ar: 'التوقيت' }, types: ['delay', 'wait_until'] },
] as const;

export default function QuickAddMenu({ onSelect, onClose, mode = 'insert' }: QuickAddMenuProps) {
    const { locale, t } = useI18n();
    const [search, setSearch] = useState('');
    const groups = useMemo(() => {
        const query = search.trim().toLowerCase();
        return GROUPS.map((group) => ({
            ...group,
            types: group.types.filter((type) => {
                const label = t(nodeLabelKey(type));
                const meta = NODE_META[type];
                return !query || label.toLowerCase().includes(query) || meta?.label.toLowerCase().includes(query);
            }),
        })).filter((group) => group.types.length > 0);
    }, [search, t]);

    return (
        <div className="nodrag nopan w-[300px] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl" onClick={(event) => event.stopPropagation()} onMouseDown={(event) => event.stopPropagation()}>
            <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-3.5 py-3">
                <div>
                    <p className="text-sm font-bold text-slate-900">{locale === 'ar' ? 'أضف الخطوة التالية' : 'Add next step'}</p>
                    <p className="mt-0.5 text-[11px] text-slate-500">{mode === 'insert' ? locale === 'ar' ? 'سيتم إدراجها بين الخطوتين تلقائيًا' : 'It will be inserted between these steps automatically' : locale === 'ar' ? 'اختر ما تريد أن يحدث بعد ذلك' : 'Choose what should happen next'}</p>
                </div>
                {onClose && <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label={t('common.close')}><X size={14} /></button>}
            </div>
            <div className="border-b border-slate-100 p-2.5"><div className="relative"><Search size={14} className="pointer-events-none absolute start-2.5 top-1/2 -translate-y-1/2 text-slate-400" /><input autoFocus value={search} onChange={(event) => setSearch(event.target.value)} placeholder={locale === 'ar' ? 'ابحث عن خطوة...' : 'Search steps...'} className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pe-2.5 ps-8 text-xs text-slate-800 outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" /></div></div>
            <div className="max-h-[360px] overflow-y-auto p-2.5">
                {groups.map((group) => <div key={group.key} className="mb-3 last:mb-0"><p className="mb-1.5 px-1 text-[10px] font-bold tracking-wide text-slate-400 uppercase">{group.label[locale]}</p><div className="grid grid-cols-2 gap-1.5">{group.types.map((type) => { const meta = NODE_META[type]; const Icon = nodeIcon(type); return <button key={type} type="button" onClick={() => onSelect(type)} className="flex min-w-0 items-center gap-2 rounded-xl border border-transparent bg-slate-50 px-2 py-2 text-start transition hover:border-slate-200 hover:bg-white hover:shadow-sm"><span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg shadow-sm" style={solidIconTileStyle(meta?.color ?? '#64748b')}><Icon size={13} strokeWidth={2.4} /></span><span className="min-w-0 truncate text-[11px] font-semibold text-slate-700">{t(nodeLabelKey(type))}</span></button>; })}</div></div>)}
                {groups.length === 0 && <p className="px-3 py-6 text-center text-xs text-slate-400">{locale === 'ar' ? 'لا توجد نتائج' : 'No matching steps'}</p>}
            </div>
        </div>
    );
}
