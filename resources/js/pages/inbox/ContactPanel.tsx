import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import { useState } from 'react';
import { contactsApi, inboxApi, tagsApi } from '@/api';
import { Avatar, Badge, Button, Spinner } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';

export default function ContactPanel({
    conversationId,
    drawerOpen = false,
    onDrawerClose,
}: {
    conversationId: number;
    drawerOpen?: boolean;
    onDrawerClose?: () => void;
}) {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const { t, statusLabel } = useI18n();
    const [editingTags, setEditingTags] = useState(false);

    const detail = useQuery({
        queryKey: ['conversation', workspaceId, conversationId],
        queryFn: async () => (await inboxApi.conversation(workspaceId, conversationId)).data,
    });

    const allTags = useQuery({
        queryKey: ['tags', workspaceId],
        queryFn: async () => (await tagsApi.list(workspaceId)).data,
        enabled: editingTags,
    });

    const contact = detail.data?.conversation.contact;

    const toggleTag = useMutation({
        mutationFn: (tagId: number) => {
            const current = contact?.tags?.map((tag) => tag.id) ?? [];
            const next = current.includes(tagId) ? current.filter((id) => id !== tagId) : [...current, tagId];
            return contactsApi.syncTags(workspaceId, contact!.id, next);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['conversation', workspaceId, conversationId] }),
    });

    if (detail.isLoading) {
        return (
            <>
                <Spinner className="hidden w-72 xl:flex" />
                {drawerOpen && (
                    <div className="fixed inset-0 z-40 flex xl:hidden">
                        <div className="absolute inset-0 bg-slate-900/50" onClick={onDrawerClose} />
                        <aside className="absolute inset-y-0 end-0 flex w-80 max-w-[90vw] items-center justify-center bg-white shadow-pop">
                            <Spinner />
                        </aside>
                    </div>
                )}
            </>
        );
    }

    if (!contact) return null;

    const content = (
        <>
            <div className="flex flex-col items-center gap-2 border-b border-slate-100 pb-4">
                <Avatar name={contact.full_name} size={14} />
                <p className="text-sm font-semibold text-slate-800">{contact.full_name}</p>
                <p className="text-xs text-slate-500">{contact.phone_number}</p>
                <Badge color={contact.opt_in_status === 'opted_in' ? 'green' : 'slate'}>
                    {statusLabel(contact.opt_in_status)}
                </Badge>
            </div>

            <div className="space-y-4 pt-4">
                <section>
                    <div className="mb-1.5 flex items-center justify-between">
                        <h4 className="text-xs font-bold tracking-wide text-slate-400 uppercase">{t('common.tags')}</h4>
                        <Button size="sm" variant="ghost" onClick={() => setEditingTags(!editingTags)}>
                            {editingTags ? t('common.done') : t('common.edit')}
                        </Button>
                    </div>
                    <div className="flex flex-wrap gap-1">
                        {(editingTags ? allTags.data ?? [] : contact.tags ?? []).map((tag) => {
                            const active = contact.tags?.some((item) => item.id === tag.id);
                            return (
                                <button
                                    key={tag.id}
                                    disabled={!editingTags}
                                    onClick={() => toggleTag.mutate(tag.id)}
                                    className="rounded-full px-2 py-0.5 text-[11px] font-medium transition-opacity disabled:cursor-default"
                                    style={{
                                        backgroundColor: tag.color + (active ? '33' : '11'),
                                        color: active ? tag.color : '#94a3b8',
                                        outline: editingTags && active ? `1px solid ${tag.color}` : undefined,
                                    }}
                                >
                                    {tag.name}
                                </button>
                            );
                        })}
                        {!editingTags && (contact.tags ?? []).length === 0 && (
                            <span className="text-xs text-slate-400">{t('inbox.no_tags')}</span>
                        )}
                    </div>
                </section>

                <section>
                    <h4 className="mb-1.5 text-xs font-bold tracking-wide text-slate-400 uppercase">{t('common.details')}</h4>
                    <dl className="space-y-1.5 text-xs">
                        {contact.email && (
                            <div className="flex justify-between gap-2">
                                <dt className="text-slate-500">{t('common.email')}</dt>
                                <dd className="truncate text-slate-800">{contact.email}</dd>
                            </div>
                        )}
                        {contact.country && (
                            <div className="flex justify-between gap-2">
                                <dt className="text-slate-500">{t('common.country')}</dt>
                                <dd className="text-slate-800">{contact.country}</dd>
                            </div>
                        )}
                        {contact.last_seen_at && (
                            <div className="flex justify-between gap-2">
                                <dt className="text-slate-500">{t('inbox.last_seen')}</dt>
                                <dd className="text-slate-800">{new Date(contact.last_seen_at).toLocaleString()}</dd>
                            </div>
                        )}
                    </dl>
                </section>

                <section>
                    <h4 className="mb-1.5 text-xs font-bold tracking-wide text-slate-400 uppercase">{t('inbox.custom_fields')}</h4>
                    {(contact.custom_fields ?? []).length === 0 ? (
                        <p className="text-xs text-slate-400">{t('inbox.no_field_values')}</p>
                    ) : (
                        <dl className="space-y-1.5 text-xs">
                            {contact.custom_fields!.map((field) => (
                                <div key={field.id} className="flex justify-between gap-2">
                                    <dt className="text-slate-500">{field.name}</dt>
                                    <dd className="truncate font-medium text-slate-800">{field.value ?? '—'}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </section>

                <section>
                    <h4 className="mb-1.5 text-xs font-bold tracking-wide text-slate-400 uppercase">{t('inbox.assignment')}</h4>
                    <p className="text-xs text-slate-700">
                        {detail.data?.conversation.assigned_user?.name ?? t('common.unassigned')}
                        {detail.data?.conversation.assigned_team && (
                            <span className="text-slate-500"> · {detail.data.conversation.assigned_team.name}</span>
                        )}
                    </p>
                </section>
            </div>
        </>
    );

    return (
        <>
            <div className="hidden w-72 shrink-0 overflow-y-auto border-s border-slate-200 bg-white p-4 xl:block">{content}</div>

            {drawerOpen && (
                <div className="fixed inset-0 z-40 xl:hidden">
                    <div className="animate-fade-in absolute inset-0 bg-slate-900/50" onClick={onDrawerClose} />
                    <aside className="absolute inset-y-0 end-0 flex w-80 max-w-[90vw] flex-col overflow-y-auto bg-white p-4 shadow-pop">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-slate-800">{t('inbox.contact')}</h3>
                            <button
                                type="button"
                                onClick={onDrawerClose}
                                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                aria-label={t('common.close')}
                            >
                                <X size={16} />
                            </button>
                        </div>
                        {content}
                    </aside>
                </div>
            )}
        </>
    );
}
