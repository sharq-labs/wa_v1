import type { LucideIcon, LucideProps } from 'lucide-react';
import {
    Bot,
    CircleStop,
    ClipboardList,
    Clock3,
    Dice5,
    FileText,
    Filter,
    GitBranch,
    Globe,
    Hash,
    Headphones,
    Image,
    ListOrdered,
    MessageCircle,
    MessageSquarePlus,
    MessageSquareText,
    MousePointerClick,
    PauseCircle,
    PlayCircle,
    Redo2,
    Shuffle,
    Sparkles,
    StickyNote,
    Tag,
    Tags,
    TextCursorInput,
    UserMinus,
    UserPlus,
    UserRound,
    Video,
    Webhook,
    XCircle,
    Zap,
} from 'lucide-react';
import type { NodeMeta } from './nodeCatalog';

/** Distinct icons per node — Zap for triggers (ManyChat-style “When…” feel). */
export const NODE_ICONS: Record<string, LucideIcon> = {
    trigger_incoming_message: Zap,
    trigger_keyword: Hash,
    trigger_new_contact: UserPlus,
    send_text: MessageSquareText,
    send_image: Image,
    send_video: Video,
    send_audio: Headphones,
    send_document: FileText,
    send_template: ClipboardList,
    send_buttons: MousePointerClick,
    send_list: ListOrdered,
    ask_question: MessageSquarePlus,
    set_custom_field: TextCursorInput,
    clear_custom_field: XCircle,
    add_tag: Tag,
    remove_tag: Tags,
    http_request: Globe,
    send_webhook: Webhook,
    add_note: StickyNote,
    assign_agent: UserRound,
    unassign_agent: UserMinus,
    pause_bot: PauseCircle,
    resume_bot: PlayCircle,
    close_conversation: CircleStop,
    reopen_conversation: Redo2,
    start_automation: Bot,
    condition: Filter,
    delay: Clock3,
    wait_until: Clock3,
    random_split: Dice5,
    go_to_node: Shuffle,
    stop: CircleStop,
};

export const CATEGORY_ICONS: Record<NodeMeta['category'], LucideIcon> = {
    trigger: Zap,
    message: MessageCircle,
    data: Sparkles,
    routing: UserRound,
    control: Filter,
};

export function nodeIcon(type: string): LucideIcon {
    return NODE_ICONS[type] ?? GitBranch;
}

export function NodeTypeIcon({ type, ...props }: { type: string } & LucideProps) {
    const Icon = NODE_ICONS[type] ?? GitBranch;

    return <Icon {...props} />;
}

/** Soft tint (settings panel, subtle surfaces). */
export function iconTileStyle(color: string): { background: string; color: string } {
    return {
        background: `${color}18`,
        color,
    };
}

/** Solid ManyChat-style badge: filled color + white glyph. */
export function solidIconTileStyle(color: string): { background: string; color: string } {
    return {
        background: color,
        color: '#ffffff',
    };
}
