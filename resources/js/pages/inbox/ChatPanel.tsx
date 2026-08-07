import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { format } from 'date-fns';
import {
    ArrowLeft,
    Bot,
    Check,
    CheckCheck,
    Clock,
    File,
    FileText,
    Image as ImageIcon,
    MoreVertical,
    Paperclip,
    Send,
    StickyNote,
    UserRound,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { agentsApi, inboxApi } from '@/api';
import { Badge, Button, Label, Modal, Select, Spinner, statusColor } from '@/components/ui';
import { getEcho, leaveChannel } from '@/lib/echo';
import { useI18n } from '@/lib/i18n';
import { useAuthStore, useWorkspaceId } from '@/stores/authStore';
import type { Message } from '@/types';
import { clsx } from 'clsx';
import TemplatePickerModal from './TemplatePickerModal';

export default function ChatPanel({
    conversationId,
    onOpenContact,
}: {
    conversationId: number;
    onOpenContact?: () => void;
}) {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const { t, statusLabel } = useI18n();
    const navigate = useNavigate();
    const currentUser = useAuthStore((s) => s.user);

    const [text, setText] = useState('');
    const [noteMode, setNoteMode] = useState(false);
    const [templateOpen, setTemplateOpen] = useState(false);
    const [assignOpen, setAssignOpen] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const [viewers, setViewers] = useState<{ id: number; name: string }[]>([]);
    const [typingUser, setTypingUser] = useState<string | null>(null);
    const bottomRef = useRef<HTMLDivElement>(null);
    const typingTimeout = useRef<ReturnType<typeof setTimeout>>(undefined);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!menuOpen) return;
        const onClick = (e: MouseEvent) => {
            if (!menuRef.current?.contains(e.target as Node)) setMenuOpen(false);
        };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, [menuOpen]);

    const detail = useQuery({
        queryKey: ['conversation', workspaceId, conversationId],
        queryFn: async () => (await inboxApi.conversation(workspaceId, conversationId)).data,
    });

    const messages = useQuery({
        queryKey: ['messages', workspaceId, conversationId],
        queryFn: async () => (await inboxApi.messages(workspaceId, conversationId)).data,
    });

    const notes = useQuery({
        queryKey: ['notes', workspaceId, conversationId],
        queryFn: async () => (await inboxApi.notes(workspaceId, conversationId)).data,
    });

    // Presence channel for collision protection + typing.
    useEffect(() => {
        const echo = getEcho();
        const presence = echo.join(`conversation.${conversationId}`);

        presence
            .here((users: any[]) => setViewers(users.filter((u) => u.id !== currentUser?.id)))
            .joining((user: any) => {
                if (user.id !== currentUser?.id) setViewers((v) => [...v.filter((x) => x.id !== user.id), user]);
            })
            .leaving((user: any) => setViewers((v) => v.filter((x) => x.id !== user.id)));

        const workspaceChannel = echo.private(`workspace.${workspaceId}`);
        const onTyping = (payload: any) => {
            if (payload.conversation_id === conversationId && payload.user_id !== currentUser?.id) {
                setTypingUser(payload.typing ? payload.user_name : null);
                if (payload.typing) {
                    setTimeout(() => setTypingUser(null), 4000);
                }
            }
        };
        workspaceChannel.listen('.agent.typing', onTyping);

        inboxApi.markRead(workspaceId, conversationId).catch(() => {});

        return () => {
            leaveChannel(`presence-conversation.${conversationId}`);
            workspaceChannel.stopListening('.agent.typing', onTyping);
        };
    }, [conversationId, workspaceId, currentUser?.id]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.data?.items?.length, notes.data?.length]);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['messages', workspaceId, conversationId] });
        queryClient.invalidateQueries({ queryKey: ['conversation', workspaceId, conversationId] });
        queryClient.invalidateQueries({ queryKey: ['conversations', workspaceId] });
    };

    const sendText = useMutation({
        mutationFn: () => inboxApi.sendText(workspaceId, conversationId, { text }),
        onSuccess: () => {
            setText('');
            invalidate();
        },
    });

    const addNote = useMutation({
        mutationFn: () => inboxApi.addNote(workspaceId, conversationId, text),
        onSuccess: () => {
            setText('');
            setNoteMode(false);
            queryClient.invalidateQueries({ queryKey: ['notes', workspaceId, conversationId] });
        },
    });

    const sendMedia = useMutation({
        mutationFn: (file: File) => {
            const formData = new FormData();
            formData.append('file', file);
            return inboxApi.sendMedia(workspaceId, conversationId, formData);
        },
        onSuccess: invalidate,
    });

    const botToggle = useMutation({
        mutationFn: (pause: boolean) =>
            pause ? inboxApi.pauseBot(workspaceId, conversationId) : inboxApi.resumeBot(workspaceId, conversationId),
        onSuccess: invalidate,
    });

    const setStatus = useMutation({
        mutationFn: (status: string) => inboxApi.setStatus(workspaceId, conversationId, status),
        onSuccess: invalidate,
    });

    const conversation = detail.data?.conversation;
    const eligibility = detail.data?.eligibility;
    const canFreeForm = eligibility?.can_send_free_form ?? true;

    const notifyTyping = () => {
        clearTimeout(typingTimeout.current);
        inboxApi.typing(workspaceId, conversationId, true).catch(() => {});
        typingTimeout.current = setTimeout(() => inboxApi.typing(workspaceId, conversationId, false).catch(() => {}), 3000);
    };

    if (detail.isLoading) return <Spinner className="flex-1" />;

    // Merge messages and notes into a single timeline.
    const timeline: ({ kind: 'message'; item: Message } | { kind: 'note'; item: any })[] = [
        ...(messages.data?.items ?? []).map((m) => ({ kind: 'message' as const, item: m })),
        ...(notes.data ?? []).map((n: any) => ({ kind: 'note' as const, item: n })),
    ].sort((a, b) => new Date(a.item.created_at).getTime() - new Date(b.item.created_at).getTime());

    return (
        <div className="flex min-w-0 flex-1 flex-col bg-slate-100">
            {/* Header */}
            <div className="flex items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 py-2.5 md:px-4">
                <div className="flex min-w-0 items-center gap-1.5">
                    <button
                        onClick={() => navigate('/inbox')}
                        aria-label={t('common.back')}
                        className="rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900 md:hidden"
                    >
                        <ArrowLeft size={18} className="rtl:rotate-180" />
                    </button>
                    <button
                        type="button"
                        onClick={onOpenContact}
                        className="min-w-0 rounded-lg px-1 py-0.5 text-start transition-colors hover:bg-slate-50 xl:pointer-events-none xl:hover:bg-transparent"
                    >
                        <p className="truncate text-sm font-semibold text-slate-800">{conversation?.contact?.full_name}</p>
                        <div className="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                            <span>{conversation?.contact?.phone_number}</span>
                            {conversation && (
                                <Badge color={statusColor(conversation.status)}>{statusLabel(conversation.status)}</Badge>
                            )}
                            <span className="flex items-center gap-1">
                                <Bot
                                    size={12}
                                    className={conversation?.automation_status === 'active' ? 'text-brand-600' : 'text-amber-500'}
                                />
                                {conversation?.automation_status === 'active' ? t('inbox.bot_active') : t('inbox.bot_paused')}
                            </span>
                        </div>
                    </button>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    <Button size="sm" variant="ghost" className="xl:hidden" onClick={onOpenContact} aria-label={t('inbox.contact')}>
                        <UserRound size={16} />
                    </Button>
                    <div className="hidden items-center gap-1.5 md:flex">
                        <Button size="sm" variant="secondary" onClick={() => setAssignOpen(true)}>
                            {t('inbox.assign')}
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => botToggle.mutate(conversation?.automation_status === 'active')}
                        >
                            {conversation?.automation_status === 'active' ? t('inbox.pause_bot') : t('inbox.resume_bot')}
                        </Button>
                        <Select
                            value={conversation?.status ?? 'open'}
                            onChange={(e) => setStatus.mutate(e.target.value)}
                            className="!w-auto !py-2 text-sm"
                        >
                            <option value="open">{t('inbox.open')}</option>
                            <option value="pending">{t('inbox.pending')}</option>
                            <option value="closed">{t('inbox.closed')}</option>
                        </Select>
                    </div>
                    <div className="relative md:hidden" ref={menuRef}>
                        <button
                            type="button"
                            onClick={() => setMenuOpen((open) => !open)}
                            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                            aria-label={t('common.more')}
                        >
                            <MoreVertical size={18} />
                        </button>
                        {menuOpen && (
                            <div className="absolute end-0 z-20 mt-1 w-48 rounded-xl border border-slate-200 bg-white p-1.5 shadow-pop">
                                <button
                                    type="button"
                                    className="flex w-full rounded-lg px-3 py-2 text-start text-sm text-slate-700 hover:bg-slate-50"
                                    onClick={() => {
                                        setMenuOpen(false);
                                        setAssignOpen(true);
                                    }}
                                >
                                    {t('inbox.assign')}
                                </button>
                                <button
                                    type="button"
                                    className="flex w-full rounded-lg px-3 py-2 text-start text-sm text-slate-700 hover:bg-slate-50"
                                    onClick={() => {
                                        setMenuOpen(false);
                                        botToggle.mutate(conversation?.automation_status === 'active');
                                    }}
                                >
                                    {conversation?.automation_status === 'active' ? t('inbox.pause_bot') : t('inbox.resume_bot')}
                                </button>
                                <div className="border-t border-slate-100 px-2 py-2">
                                    <Select
                                        value={conversation?.status ?? 'open'}
                                        onChange={(e) => {
                                            setStatus.mutate(e.target.value);
                                            setMenuOpen(false);
                                        }}
                                        className="!py-2 text-sm"
                                    >
                                        <option value="open">{t('inbox.open')}</option>
                                        <option value="pending">{t('inbox.pending')}</option>
                                        <option value="closed">{t('inbox.closed')}</option>
                                    </Select>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Collision + typing banners */}
            {viewers.length > 0 && (
                <div className="border-b border-amber-200 bg-amber-50 px-4 py-1 text-[11px] text-amber-800">
                    {t('inbox.viewing', { name: viewers.map((v) => v.name).join(', ') })}
                </div>
            )}
            {typingUser && (
                <div className="border-b border-blue-100 bg-blue-50 px-4 py-1 text-[11px] text-blue-700">
                    {t('inbox.typing', { name: typingUser })}
                </div>
            )}

            {/* Timeline */}
            <div className="chat-bg flex-1 space-y-2 overflow-y-auto p-4">
                {messages.isLoading ? (
                    <Spinner />
                ) : (
                    timeline.map(({ kind, item }) =>
                        kind === 'note' ? (
                            <div key={`note-${item.id}`} className="mx-auto w-full max-w-xl">
                                <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    <div className="mb-0.5 flex items-center gap-1 font-semibold">
                                        <StickyNote size={12} />
                                        {item.user?.name ?? t('inbox.bot')} — {t('inbox.internal_note')}
                                    </div>
                                    {item.body}
                                </div>
                            </div>
                        ) : (
                            <MessageBubble key={`message-${item.id}`} message={item} />
                        ),
                    )
                )}
                <div ref={bottomRef} />
            </div>

            {/* Composer */}
            <div className="border-t border-slate-200 bg-white p-3">
                {!canFreeForm && !noteMode && (
                    <div className="mb-2 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <span>{t('inbox.window_closed')}</span>
                        <Button size="sm" onClick={() => setTemplateOpen(true)}>
                            {t('inbox.send_template')}
                        </Button>
                    </div>
                )}
                <div className="flex items-end gap-2">
                    <label className="cursor-pointer rounded-lg p-2 text-slate-500 hover:bg-slate-100">
                        <Paperclip size={18} />
                        <input
                            type="file"
                            className="hidden"
                            disabled={!canFreeForm}
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file) sendMedia.mutate(file);
                                e.target.value = '';
                            }}
                        />
                    </label>
                    <button
                        onClick={() => setTemplateOpen(true)}
                        className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                        title={t('inbox.send_template')}
                    >
                        <FileText size={18} />
                    </button>
                    <button
                        onClick={() => setNoteMode(!noteMode)}
                        className={clsx('rounded-lg p-2 hover:bg-slate-100', noteMode ? 'text-amber-600' : 'text-slate-500')}
                        title={t('inbox.add_note')}
                    >
                        <StickyNote size={18} />
                    </button>
                    <textarea
                        rows={1}
                        value={text}
                        disabled={!noteMode && !canFreeForm}
                        onChange={(e) => {
                            setText(e.target.value);
                            if (!noteMode) notifyTyping();
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                if (!text.trim()) return;
                                if (noteMode) {
                                    addNote.mutate();
                                } else {
                                    sendText.mutate();
                                }
                            }
                        }}
                        placeholder={noteMode ? t('inbox.note_placeholder') : t('inbox.type_message')}
                        className={clsx(
                            'max-h-32 flex-1 resize-none rounded-xl border px-3 py-2 text-sm shadow-xs transition-colors focus:outline-none focus:ring-[3px]',
                            noteMode
                                ? 'border-amber-300 bg-amber-50 focus:border-amber-400 focus:ring-amber-500/15'
                                : 'border-slate-300 bg-white focus:border-brand-500 focus:ring-brand-500/15 disabled:bg-slate-50',
                        )}
                    />
                    <Button
                        onClick={() => (noteMode ? addNote.mutate() : sendText.mutate())}
                        disabled={!text.trim() || sendText.isPending || addNote.isPending || (!noteMode && !canFreeForm)}
                        aria-label={noteMode ? t('inbox.add_note') : t('inbox.type_message')}
                        className="h-9 w-9 shrink-0 !rounded-xl !p-0"
                    >
                        <Send size={16} className="rtl:-scale-x-100" />
                    </Button>
                </div>
            </div>

            <TemplatePickerModal
                open={templateOpen}
                onClose={() => setTemplateOpen(false)}
                conversationId={conversationId}
                onSent={invalidate}
            />
            <AssignModal
                open={assignOpen}
                onClose={() => setAssignOpen(false)}
                conversationId={conversationId}
                onAssigned={invalidate}
            />
        </div>
    );
}

function MessageBubble({ message }: { message: Message }) {
    const { t, dateLocale } = useI18n();
    const outbound = message.direction === 'outbound';

    const StatusIcon =
        message.status === 'read' ? CheckCheck
            : message.status === 'delivered' ? CheckCheck
            : message.status === 'sent' ? Check
            : message.status === 'failed' ? XCircle
            : Clock;

    const senderLabel =
        message.sender_type === 'bot'
            ? t('inbox.bot')
            : message.sender_type === 'system'
              ? t('inbox.system')
              : (message.sender_user?.name ?? t('inbox.agent'));

    const mediaKey = `inbox.media.${message.message_type}`;
    const mediaLabel = t(mediaKey);
    const MediaIcon = message.message_type === 'image' ? ImageIcon : File;

    return (
        <div className={clsx('flex', outbound ? 'justify-end' : 'justify-start')}>
            <div
                className={clsx(
                    'max-w-[min(36rem,78%)] rounded-2xl px-3 py-2 text-sm shadow-xs ring-1 ring-black/5',
                    outbound ? 'rounded-ee-sm bg-brand-100 text-slate-900' : 'rounded-es-sm bg-white text-slate-900',
                )}
            >
                {outbound && (
                    <p className="mb-0.5 flex items-center gap-1 text-[10px] font-semibold text-brand-700">
                        {message.sender_type === 'bot' ? <Bot size={11} /> : null}
                        {senderLabel}
                        {message.message_type === 'template' && ` · ${t('inbox.template')}: ${message.template_name}`}
                    </p>
                )}
                {message.media_url && (
                    <a
                        href={message.media_url}
                        target="_blank"
                        rel="noreferrer"
                        className="mb-1 flex items-center gap-1 text-xs text-blue-600 underline"
                    >
                        <MediaIcon size={12} />
                        {mediaLabel === mediaKey ? message.message_type : mediaLabel}
                    </a>
                )}
                <p className="whitespace-pre-wrap">{message.content}</p>
                {message.payload?.interactive?.buttons && (
                    <div className="mt-1.5 flex flex-wrap gap-1">
                        {message.payload.interactive.buttons.map((b: any) => (
                            <span key={b.id} className="rounded-full border border-brand-300 px-2 py-0.5 text-[11px] text-brand-700">
                                {b.title}
                            </span>
                        ))}
                    </div>
                )}
                <div className={clsx('mt-1 flex items-center gap-1 text-[10px] text-slate-400', outbound && 'justify-end')}>
                    {format(new Date(message.created_at), 'HH:mm', { locale: dateLocale })}
                    {outbound && (
                        <StatusIcon
                            size={12}
                            className={message.status === 'read' ? 'text-blue-500' : message.status === 'failed' ? 'text-red-500' : ''}
                        />
                    )}
                </div>
                {message.error_message && <p className="mt-1 text-[10px] text-red-600">{message.error_message}</p>}
            </div>
        </div>
    );
}

function AssignModal({
    open,
    onClose,
    conversationId,
    onAssigned,
}: {
    open: boolean;
    onClose: () => void;
    conversationId: number;
    onAssigned: () => void;
}) {
    const workspaceId = useWorkspaceId();
    const { t, statusLabel } = useI18n();
    const [userId, setUserId] = useState('');
    const [teamId, setTeamId] = useState('');
    const [strategy, setStrategy] = useState('round_robin');

    const agents = useQuery({
        queryKey: ['agents', workspaceId],
        queryFn: async () => (await agentsApi.list(workspaceId)).data,
        enabled: open,
    });
    const teams = useQuery({
        queryKey: ['teams', workspaceId],
        queryFn: async () => (await agentsApi.teams(workspaceId)).data,
        enabled: open,
    });

    const assign = useMutation({
        mutationFn: () =>
            inboxApi.assign(workspaceId, conversationId, {
                user_id: userId ? Number(userId) : undefined,
                team_id: teamId ? Number(teamId) : undefined,
                strategy: teamId && !userId ? strategy : undefined,
            }),
        onSuccess: () => {
            onAssigned();
            onClose();
        },
    });

    const unassign = useMutation({
        mutationFn: () => inboxApi.unassign(workspaceId, conversationId),
        onSuccess: () => {
            onAssigned();
            onClose();
        },
    });

    return (
        <Modal open={open} onClose={onClose} title={t('inbox.assign')}>
            <div className="space-y-3">
                <div>
                    <Label>{t('common.agent')}</Label>
                    <Select value={userId} onChange={(e) => setUserId(e.target.value)}>
                        <option value="">{t('inbox.pick_agent')}</option>
                        {agents.data?.map((agent) => (
                            <option key={agent.user_id} value={agent.user_id}>
                                {agent.user?.name} ({statusLabel(agent.status)})
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <Label>{t('common.team')}</Label>
                    <Select value={teamId} onChange={(e) => setTeamId(e.target.value)}>
                        <option value="">—</option>
                        {teams.data?.map((team) => (
                            <option key={team.id} value={team.id}>
                                {team.name}
                            </option>
                        ))}
                    </Select>
                </div>
                {teamId && !userId && (
                    <div>
                        <Label>{t('common.strategy')}</Label>
                        <Select value={strategy} onChange={(e) => setStrategy(e.target.value)}>
                            <option value="round_robin">{t('inbox.round_robin')}</option>
                            <option value="least_active">{t('inbox.least_active')}</option>
                        </Select>
                    </div>
                )}
                <div className="flex justify-between pt-2">
                    <Button variant="ghost" onClick={() => unassign.mutate()}>
                        {t('inbox.unassign')}
                    </Button>
                    <Button onClick={() => assign.mutate()} disabled={(!userId && !teamId) || assign.isPending}>
                        {t('inbox.assign')}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
