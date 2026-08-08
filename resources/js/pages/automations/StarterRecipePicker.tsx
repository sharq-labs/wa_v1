import { CalendarDays, Headphones, MessageCircleMore, ShoppingBag, Sparkles } from 'lucide-react';
import { useI18n } from '@/lib/i18n';
import type { StarterRecipeKey } from './starterRecipes';

interface StarterRecipePickerProps {
    onSelect: (key: StarterRecipeKey) => void;
    onBlank: () => void;
}

export default function StarterRecipePicker({ onSelect, onBlank }: StarterRecipePickerProps) {
    const { locale } = useI18n();
    const ar = locale === 'ar';

    const recipes: Array<{
        key: StarterRecipeKey;
        title: string;
        description: string;
        Icon: typeof Sparkles;
    }> = [
        {
            key: 'lead_capture',
            title: ar ? 'جمع العملاء المحتملين' : 'Capture leads',
            description: ar ? 'ترحيب + اسم العميل + احتياجه + Tag تلقائي' : 'Welcome + name + need + automatic tag',
            Icon: MessageCircleMore,
        },
        {
            key: 'support_router',
            title: ar ? 'خدمة العملاء' : 'Customer support',
            description: ar ? 'اختيار بين الدعم والمبيعات بمسارات منفصلة' : 'Route customers between support and sales',
            Icon: Headphones,
        },
        {
            key: 'sales_qualification',
            title: ar ? 'تأهيل عميل مبيعات' : 'Qualify a sales lead',
            description: ar ? 'اهتمام + ميزانية + تصنيف العميل تلقائيًا' : 'Interest + budget + automatic qualification tag',
            Icon: ShoppingBag,
        },
        {
            key: 'appointment',
            title: ar ? 'حجز موعد' : 'Book appointments',
            description: ar ? 'التاريخ + رقم التواصل + تأكيد الطلب' : 'Date + contact number + confirmation',
            Icon: CalendarDays,
        },
    ];

    return (
        <div className="pointer-events-auto w-full max-w-2xl rounded-3xl border border-slate-200 bg-white/95 p-5 shadow-xl backdrop-blur">
            <div className="mb-4 text-center">
                <span className="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <Sparkles size={18} />
                </span>
                <h2 className="text-base font-bold text-slate-900">
                    {ar ? 'تحب البوت يعمل إيه؟' : 'What should this bot do?'}
                </h2>
                <p className="mt-1 text-xs text-slate-500">
                    {ar
                        ? 'ابدأ بسيناريو جاهز وعدّله براحتك، أو ابدأ من الصفر.'
                        : 'Start with a ready-made flow and customize it, or build from scratch.'}
                </p>
            </div>

            <div className="grid gap-2 sm:grid-cols-2">
                {recipes.map(({ key, title, description, Icon }) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => onSelect(key)}
                        className="group flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-3 text-start transition hover:-translate-y-0.5 hover:border-brand-200 hover:bg-white hover:shadow-md"
                    >
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-700 shadow-sm ring-1 ring-slate-200 group-hover:ring-brand-200">
                            <Icon size={16} strokeWidth={2.3} />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-bold text-slate-800">{title}</span>
                            <span className="mt-0.5 block text-[11px] leading-relaxed text-slate-500">{description}</span>
                        </span>
                    </button>
                ))}
            </div>

            <button
                type="button"
                onClick={onBlank}
                className="mt-3 w-full rounded-xl border border-dashed border-slate-300 px-3 py-2.5 text-xs font-semibold text-slate-600 transition hover:border-brand-300 hover:bg-brand-50/50 hover:text-brand-700"
            >
                {ar ? 'ابدأ من الصفر' : 'Start from scratch'}
            </button>
        </div>
    );
}
