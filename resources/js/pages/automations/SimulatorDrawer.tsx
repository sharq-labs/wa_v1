import { useMutation } from '@tanstack/react-query';
import { clsx } from 'clsx';
import { RotateCcw, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { automationsApi } from '@/api';
import { Badge, Button, Input } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import type { SimulationState } from '@/types';

type TranscriptEntry = SimulationState['transcript'][number];

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/** Typing delay: slower for longer messages, capped for comfort. */
function typingDelayMs(entry: TranscriptEntry): number {
    if (entry.from === 'customer') return 180;
    const len = (entry.text ?? '').length;
    return Math.min(2200, Math.max(700, 450 + len * 28));
}

function gapAfterMs(entry: TranscriptEntry): number {
    if (entry.from === 'customer') return 220;
    if (entry.buttons?.length || entry.sections?.length) return 520;
    return 380;
}

function TypingDots() {
    return (
        <div className="flex justify-start">
            <div className="flex items-center gap-1 rounded-2xl rounded-es-md bg-white px-3.5 py-2.5 shadow-sm">
                <span className="sim-typing-dot" />
                <span className="sim-typing-dot [animation-delay:160ms]" />
                <span className="sim-typing-dot [animation-delay:320ms]" />
            </div>
        </div>
    );
}

/**
 * WhatsApp-style flow simulator with paced message reveal —
 * typing indicator + staggered bot replies so the chat feels natural.
 */
export default function SimulatorDrawer({
    open,
    onClose,
    automationId,
    onNodeHighlight,
}: {
    open: boolean;
    onClose: () => void;
    automationId: number;
    onNodeHighlight: (nodeId: string | null) => void;
}) {
    const workspaceId = useWorkspaceId();
    const { t, statusLabel, dir } = useI18n();
    const [state, setState] = useState<SimulationState | null>(null);
    const [displayed, setDisplayed] = useState<TranscriptEntry[]>([]);
    const [typing, setTyping] = useState(false);
    const [text, setText] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const bottomRef = useRef<HTMLDivElement>(null);
    const revealedCount = useRef(0);
    const playToken = useRef(0);

    const playTranscript = useCallback(
        async (transcript: TranscriptEntry[], fromIndex: number, token: number) => {
            setBusy(true);
            for (let i = fromIndex; i < transcript.length; i++) {
                if (playToken.current !== token) return;

                const entry = transcript[i];
                if (entry.from === 'bot') {
                    setTyping(true);
                    await sleep(typingDelayMs(entry));
                    if (playToken.current !== token) return;
                    setTyping(false);
                    await sleep(120);
                } else {
                    await sleep(typingDelayMs(entry));
                }

                if (playToken.current !== token) return;
                setDisplayed((prev) => [...prev, entry]);
                revealedCount.current = i + 1;
                await sleep(gapAfterMs(entry));
            }

            if (playToken.current === token) {
                setBusy(false);
            }
        },
        [],
    );

    const applyState = useCallback(
        (next: SimulationState, options?: { restart?: boolean }) => {
            setState(next);
            setError(null);

            if (options?.restart) {
                playToken.current += 1;
                revealedCount.current = 0;
                setDisplayed([]);
                setTyping(false);
            }

            const from = revealedCount.current;
            if (next.transcript.length > from) {
                const token = ++playToken.current;
                void playTranscript(next.transcript, from, token).then(() => {
                    if (playToken.current === token) {
                        onNodeHighlight(next.current_node_id);
                    }
                });
            } else {
                onNodeHighlight(next.current_node_id);
                setBusy(false);
            }
        },
        [onNodeHighlight, playTranscript],
    );

    const start = useMutation({
        mutationFn: () => automationsApi.simulateStart(workspaceId, automationId),
        onSuccess: (response) => applyState(response.data, { restart: true }),
        onError: (e: any) => setError(e.message),
    });

    const send = useMutation({
        mutationFn: (body: { text: string; reply_id?: string }) =>
            automationsApi.simulateMessage(workspaceId, automationId, {
                session_id: state!.session_id,
                ...body,
            }),
        onSuccess: (response) => applyState(response.data),
        onError: (e: any) => {
            setError(e.message);
            setBusy(false);
        },
    });

    useEffect(() => {
        if (!open) return;
        start.mutate();
        // The parent unmounts this drawer when it closes, so local simulation state resets naturally.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [displayed.length, typing]);

    const submit = (payload: { text: string; reply_id?: string }) => {
        if (!payload.text.trim() || busy || send.isPending || !state) return;
        if (state.status === 'completed' || state.status === 'failed') return;

        // Show the customer bubble immediately for snappy feel, then wait for bot pacing.
        const optimistic: TranscriptEntry = {
            from: 'customer',
            type: 'text',
            text: payload.text,
            at: new Date().toISOString(),
        };
        setDisplayed((prev) => [...prev, optimistic]);
        revealedCount.current += 1;
        setBusy(true);
        send.mutate(payload);
    };

    if (!open) return null;

    const inputLocked = busy || send.isPending || start.isPending || state?.status === 'completed' || state?.status === 'failed';

    return (
        <div
            className="fixed inset-0 z-40 flex justify-end bg-slate-900/25 backdrop-blur-[2px] animate-[fade-in_320ms_ease-out]"
            onClick={onClose}
        >
            <div
                className={clsx(
                    'flex h-full w-[min(680px,100%)] shadow-2xl',
                    // `justify-end` on the overlay parks this drawer against the *end* edge —
                    // physically right in LTR, left in RTL — so it must slide in from the
                    // mirror side of Layout's `start-0` sidebar. The keyframes are physical:
                    // `drawer-in` enters from translateX(-100%), `drawer-in-rtl` from +100%.
                    dir === 'rtl'
                        ? 'animate-[drawer-in_380ms_cubic-bezier(0.16,1,0.3,1)]'
                        : 'animate-[drawer-in-rtl_380ms_cubic-bezier(0.16,1,0.3,1)]',
                )}
                onClick={(e) => e.stopPropagation()}
            >
                {/* State panel */}
                <div className="hidden w-56 shrink-0 flex-col overflow-y-auto border-e border-slate-200 bg-white p-3 md:flex">
                    <h3 className="mb-2 text-xs font-bold tracking-wide text-slate-400 uppercase">
                        {t('automations.sim_state')}
                    </h3>
                    <div className="space-y-3 text-xs">
                        <div>
                            <p className="font-semibold text-slate-600">{t('automations.sim_status')}</p>
                            <Badge
                                color={
                                    state?.status === 'failed'
                                        ? 'red'
                                        : state?.status === 'completed'
                                          ? 'green'
                                          : 'blue'
                                }
                            >
                                {state ? statusLabel(state.status) : '—'}
                            </Badge>
                        </div>
                        <div>
                            <p className="font-semibold text-slate-600">{t('automations.contact')}</p>
                            {Object.entries(state?.contact ?? {}).map(([k, v]) => (
                                <p key={k} className="text-slate-500">
                                    {k}: <span className="text-slate-800">{v}</span>
                                </p>
                            ))}
                        </div>
                        <div>
                            <p className="font-semibold text-slate-600">{t('automations.var_group_custom')}</p>
                            {Object.keys(state?.custom_fields ?? {}).length === 0 && (
                                <p className="text-slate-400">—</p>
                            )}
                            {Object.entries(state?.custom_fields ?? {}).map(([k, v]) => (
                                <p key={k} className="text-slate-500">
                                    {k}: <span className="font-medium text-slate-800">{v}</span>
                                </p>
                            ))}
                        </div>
                        <div>
                            <p className="font-semibold text-slate-600">{t('automations.variables')}</p>
                            {Object.keys(state?.variables ?? {}).length === 0 && (
                                <p className="text-slate-400">—</p>
                            )}
                            {Object.entries(state?.variables ?? {}).map(([k, v]) => (
                                <p key={k} className="text-slate-500">
                                    {k}: <span className="font-medium text-slate-800">{v}</span>
                                </p>
                            ))}
                        </div>
                        <div>
                            <p className="mb-1 font-semibold text-slate-600">{t('automations.sim_log')}</p>
                            <div className="space-y-1">
                                {state?.log.map((entry, i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        onClick={() => onNodeHighlight(entry.node_id)}
                                        className="block w-full rounded-lg bg-slate-50 px-2 py-1.5 text-start text-xs text-slate-600 transition hover:bg-slate-100"
                                    >
                                        {entry.message}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>

                {/* WhatsApp-style chat */}
                <div className="flex min-w-0 flex-1 flex-col bg-[#e5ddd5]">
                    <div className="flex items-center justify-between bg-[#075e54] px-3 py-2.5 text-white shadow-sm">
                        <div className="flex items-center gap-2.5">
                            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-white/20 text-sm font-bold">
                                ب
                            </div>
                            <div>
                                <p className="text-sm font-semibold">{t('automations.sim_customer')}</p>
                                <p className="text-[11px] opacity-80">
                                    {typing
                                        ? t('automations.sim_typing')
                                        : busy
                                          ? t('automations.sim_thinking')
                                          : t('automations.sim_subtitle')}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                onClick={() => start.mutate()}
                                disabled={start.isPending || busy}
                                className="rounded-lg p-1.5 transition hover:bg-white/10 disabled:opacity-40"
                                title={t('automations.sim_restart')}
                            >
                                <RotateCcw size={16} className={start.isPending ? 'animate-spin' : ''} />
                            </button>
                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded-lg p-1.5 transition hover:bg-white/10"
                            >
                                <X size={16} />
                            </button>
                        </div>
                    </div>

                    <div className="sim-chat-bg flex-1 space-y-2 overflow-y-auto px-3 py-3">
                        {error && (
                            <p className="rounded-xl bg-red-100 px-3 py-2 text-xs text-red-700 shadow-sm">{error}</p>
                        )}

                        {displayed.map((entry, i) => (
                            <div
                                key={`${entry.at}-${i}`}
                                className={clsx(
                                    'flex animate-[sim-bubble-in_320ms_cubic-bezier(0.16,1,0.3,1)]',
                                    entry.from === 'customer' ? 'justify-end' : 'justify-start',
                                )}
                            >
                                <div
                                    className={clsx(
                                        'max-w-[78%] rounded-2xl px-3 py-2 text-sm shadow-sm',
                                        entry.from === 'customer'
                                            ? 'rounded-ee-md bg-[#dcf8c6]'
                                            : 'rounded-es-md bg-white',
                                    )}
                                >
                                    {entry.media_url && (
                                        <p className="mb-1 text-[11px] text-blue-600 underline">📎 {entry.type}</p>
                                    )}
                                    {entry.text && (
                                        <p className="whitespace-pre-wrap leading-relaxed text-slate-800">{entry.text}</p>
                                    )}

                                    {entry.buttons && entry.buttons.length > 0 && (
                                        <div className="mt-2 space-y-1.5 border-t border-slate-100 pt-2">
                                            {entry.buttons.map((button) => (
                                                <button
                                                    key={button.id}
                                                    type="button"
                                                    disabled={inputLocked}
                                                    onClick={() =>
                                                        submit({ text: button.title, reply_id: button.id })
                                                    }
                                                    className="block w-full rounded-xl border border-[#128c7e]/40 py-1.5 text-center text-xs font-semibold text-[#128c7e] transition hover:bg-[#128c7e]/8 disabled:opacity-40"
                                                >
                                                    {button.title}
                                                </button>
                                            ))}
                                        </div>
                                    )}

                                    {entry.sections && entry.sections.length > 0 && (
                                        <div className="mt-2 space-y-2 border-t border-slate-100 pt-2">
                                            <p className="text-center text-[11px] font-medium text-[#128c7e]">
                                                {entry.button || t('automations.list_button_placeholder')}
                                            </p>
                                            {entry.sections.map((section, si) => (
                                                <div key={si} className="space-y-1">
                                                    {section.title && (
                                                        <p className="text-[10px] font-bold text-slate-400 uppercase">
                                                            {section.title}
                                                        </p>
                                                    )}
                                                    {(section.rows ?? []).map((row) => (
                                                        <button
                                                            key={row.id}
                                                            type="button"
                                                            disabled={inputLocked}
                                                            onClick={() =>
                                                                submit({ text: row.title, reply_id: row.id })
                                                            }
                                                            className="block w-full rounded-xl border border-slate-200 px-2 py-1.5 text-start transition hover:border-[#128c7e]/40 hover:bg-[#128c7e]/5 disabled:opacity-40"
                                                        >
                                                            <span className="block text-xs font-semibold text-[#075e54]">
                                                                {row.title}
                                                            </span>
                                                            {row.description && (
                                                                <span className="mt-0.5 block text-[10px] text-slate-500">
                                                                    {row.description}
                                                                </span>
                                                            )}
                                                        </button>
                                                    ))}
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    <p className="mt-1 text-end text-[10px] text-slate-400">
                                        {entry.at
                                            ? new Date(entry.at).toLocaleTimeString([], {
                                                  hour: '2-digit',
                                                  minute: '2-digit',
                                              })
                                            : ''}
                                    </p>
                                </div>
                            </div>
                        ))}

                        {typing && <TypingDots />}

                        {!busy && state?.status === 'completed' && (
                            <p className="py-2 text-center text-[11px] font-medium text-slate-500">
                                — {t('automations.sim_completed')} —
                            </p>
                        )}
                        {!busy && state?.status === 'failed' && (
                            <p className="py-2 text-center text-[11px] font-medium text-red-500">
                                — {t('automations.sim_failed')} —
                            </p>
                        )}
                        <div ref={bottomRef} />
                    </div>

                    <div className="flex items-center gap-2 border-t border-black/5 bg-[#f0f0f0] p-2.5">
                        <Input
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && text.trim() && !inputLocked) {
                                    const value = text.trim();
                                    setText('');
                                    submit({ text: value });
                                }
                            }}
                            placeholder={t('automations.sim_placeholder')}
                            disabled={inputLocked}
                            className="!rounded-full !border-transparent bg-white !py-2.5 shadow-sm"
                        />
                        <Button
                            onClick={() => {
                                const value = text.trim();
                                if (!value) return;
                                setText('');
                                submit({ text: value });
                            }}
                            disabled={inputLocked || !text.trim()}
                            className="!h-11 !w-11 !rounded-full !bg-[#128c7e] !p-0 hover:!bg-[#075e54]"
                        >
                            <Send size={16} />
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
