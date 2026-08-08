import type { FlowDefinition } from '@/types';

export type StarterRecipeKey = 'lead_capture' | 'support_router' | 'sales_qualification' | 'appointment';

type Locale = 'en' | 'ar';

function edge(id: string, source: string, target: string, sourceHandle?: string | null) {
    return { id, source, target, sourceHandle: sourceHandle ?? null };
}

export function createStarterRecipe(key: StarterRecipeKey, locale: Locale): FlowDefinition {
    const prefix = `starter_${key}_${Date.now().toString(36)}`;
    const id = (name: string) => `${prefix}_${name}`;
    const ar = locale === 'ar';

    if (key === 'support_router') {
        const trigger = id('trigger');
        const welcome = id('welcome');
        const buttons = id('buttons');
        const supportTag = id('support_tag');
        const salesTag = id('sales_tag');

        return {
            nodes: [
                {
                    id: trigger,
                    type: 'trigger_incoming_message',
                    config: {},
                    position: { x: 220, y: 80 },
                },
                {
                    id: welcome,
                    type: 'send_text',
                    config: {
                        text: ar
                            ? 'أهلًا {{first_name}} 👋\nإحنا هنا علشان نساعدك.'
                            : 'Hi {{first_name}} 👋\nWe are here to help.',
                    },
                    position: { x: 220, y: 260 },
                },
                {
                    id: buttons,
                    type: 'send_buttons',
                    config: {
                        body: ar ? 'تحب تتواصل مع مين؟' : 'How can we help you?',
                        header: '',
                        footer: '',
                        buttons: [
                            { id: 'support', title: ar ? 'الدعم' : 'Support' },
                            { id: 'sales', title: ar ? 'المبيعات' : 'Sales' },
                        ],
                        save_to: 'custom.intent',
                    },
                    position: { x: 220, y: 440 },
                },
                {
                    id: supportTag,
                    type: 'add_tag',
                    config: { tag_id: null, tag_name: 'support_request' },
                    position: { x: 20, y: 650 },
                },
                {
                    id: salesTag,
                    type: 'add_tag',
                    config: { tag_id: null, tag_name: 'sales_lead' },
                    position: { x: 420, y: 650 },
                },
            ],
            edges: [
                edge(id('e1'), trigger, welcome),
                edge(id('e2'), welcome, buttons),
                edge(id('e3'), buttons, supportTag, 'support'),
                edge(id('e4'), buttons, salesTag, 'sales'),
            ],
        };
    }

    if (key === 'sales_qualification') {
        const trigger = id('trigger');
        const message = id('message');
        const budget = id('budget');
        const service = id('service');
        const tag = id('tag');

        return {
            nodes: [
                {
                    id: trigger,
                    type: 'trigger_keyword',
                    config: { keywords: ['price', 'buy', 'سعر', 'شراء'], match_type: 'contains' },
                    position: { x: 220, y: 80 },
                },
                {
                    id: message,
                    type: 'send_text',
                    config: {
                        text: ar ? 'تمام 👌 خلّيني أعرف احتياجك بسرعة.' : 'Great 👌 Let me understand what you need.',
                    },
                    position: { x: 220, y: 260 },
                },
                {
                    id: service,
                    type: 'ask_question',
                    config: {
                        question: ar ? 'إيه الخدمة أو المنتج اللي مهتم بيه؟' : 'Which product or service are you interested in?',
                        save_to: 'custom.interest',
                        validation: 'text',
                        error_message: '',
                    },
                    position: { x: 220, y: 440 },
                },
                {
                    id: budget,
                    type: 'ask_question',
                    config: {
                        question: ar ? 'إيه الميزانية التقريبية؟' : 'What is your approximate budget?',
                        save_to: 'custom.budget',
                        validation: 'text',
                        error_message: '',
                    },
                    position: { x: 220, y: 620 },
                },
                {
                    id: tag,
                    type: 'add_tag',
                    config: { tag_id: null, tag_name: 'qualified_lead' },
                    position: { x: 220, y: 800 },
                },
            ],
            edges: [
                edge(id('e1'), trigger, message),
                edge(id('e2'), message, service),
                edge(id('e3'), service, budget),
                edge(id('e4'), budget, tag),
            ],
        };
    }

    if (key === 'appointment') {
        const trigger = id('trigger');
        const date = id('date');
        const phone = id('phone');
        const confirmation = id('confirmation');
        const tag = id('tag');

        return {
            nodes: [
                {
                    id: trigger,
                    type: 'trigger_keyword',
                    config: { keywords: ['appointment', 'book', 'موعد', 'حجز'], match_type: 'contains' },
                    position: { x: 220, y: 80 },
                },
                {
                    id: date,
                    type: 'ask_question',
                    config: {
                        question: ar ? 'تحب تحجز في أنهي يوم؟' : 'Which date would you like to book?',
                        save_to: 'custom.appointment_date',
                        validation: 'date',
                        error_message: '',
                    },
                    position: { x: 220, y: 270 },
                },
                {
                    id: phone,
                    type: 'ask_question',
                    config: {
                        question: ar ? 'اكتب رقم التواصل المناسب.' : 'What is the best phone number to reach you?',
                        save_to: 'custom.phone',
                        validation: 'phone',
                        error_message: '',
                    },
                    position: { x: 220, y: 460 },
                },
                {
                    id: confirmation,
                    type: 'send_text',
                    config: {
                        text: ar ? 'تم استلام طلب الحجز ✅ وهنأكد معاك التفاصيل.' : 'Booking request received ✅ We will confirm the details with you.',
                    },
                    position: { x: 220, y: 650 },
                },
                {
                    id: tag,
                    type: 'add_tag',
                    config: { tag_id: null, tag_name: 'appointment_request' },
                    position: { x: 220, y: 830 },
                },
            ],
            edges: [
                edge(id('e1'), trigger, date),
                edge(id('e2'), date, phone),
                edge(id('e3'), phone, confirmation),
                edge(id('e4'), confirmation, tag),
            ],
        };
    }

    const trigger = id('trigger');
    const welcome = id('welcome');
    const name = id('name');
    const need = id('need');
    const tag = id('tag');

    return {
        nodes: [
            {
                id: trigger,
                type: 'trigger_new_contact',
                config: {},
                position: { x: 220, y: 80 },
            },
            {
                id: welcome,
                type: 'send_text',
                config: {
                    text: ar ? 'أهلًا بيك 👋 خلّينا نعرفك أكتر.' : 'Welcome 👋 Let us get to know you.',
                },
                position: { x: 220, y: 260 },
            },
            {
                id: name,
                type: 'ask_question',
                config: {
                    question: ar ? 'ممكن أعرف اسمك؟' : 'What is your name?',
                    save_to: 'custom.name',
                    validation: 'text',
                    error_message: '',
                },
                position: { x: 220, y: 440 },
            },
            {
                id: need,
                type: 'ask_question',
                config: {
                    question: ar ? 'محتاج مساعدتنا في إيه؟' : 'What can we help you with?',
                    save_to: 'custom.need',
                    validation: 'text',
                    error_message: '',
                },
                position: { x: 220, y: 620 },
            },
            {
                id: tag,
                type: 'add_tag',
                config: { tag_id: null, tag_name: 'new_lead' },
                position: { x: 220, y: 800 },
            },
        ],
        edges: [
            edge(id('e1'), trigger, welcome),
            edge(id('e2'), welcome, name),
            edge(id('e3'), name, need),
            edge(id('e4'), need, tag),
        ],
    };
}
