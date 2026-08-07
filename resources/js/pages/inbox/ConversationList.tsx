import { useQuery } from '@tanstack/react-query';
import { formatDistanceToNowStrict } from 'date-fns';
import { Bot, Inbox, PauseCircle, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { agentsApi, tagsApi, whatsappApi } from '@/api';
import { Avatar, Badge, Button, Input, Select, Spinner, statusColor } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import type { Conversation } from '@/types';
import type { InboxFilters } from './InboxPage';
import { clsx } from 'clsx';

export default function ConversationList({
    conversations,
    loading,
    activeId,
    filters,
    onFiltersChange,
    onSelect,
    className,
}: {
    conversations: Conversation[];
    loading: boolean;
    activeId: number | null;
    filters: InboxFilters;
    onFiltersChange: (filters: InboxFilters) => void;
    onSelect: (id: number) => void;
    className?: string;
}) {
    const { t, statusLabel, dateLocale } = useI18n();
    const workspaceId = useWorkspaceId();
    const [searchDraft, setSearchDraft] = useState(filters.search);
    const [filtersOpen, setFiltersOpen] = useState(false);

    useEffect(() => setSearchDraft(filters.search), [filters.search]);

    useEffect(() => {
        const timer = setTimeout(() => {
            if (searchDraft !== filters.search) {
                onFiltersChange({ ...filters, search: searchDraft });
            }
        }, 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- debounce only on draft
    }, [searchDraft]);

    const tags = useQuery({ queryKey: ['tags', workspaceId], queryFn: async () => (await tagsApi.list(workspaceId)).data });
    const teams = useQuery({ queryKey: ['teams', workspaceId], queryFn: async () => (await agentsApi.teams(workspaceId)).data });
    const accounts = useQuery({
        queryKey: ['wa-accounts', workspaceId],
        queryFn: async () => (await whatsappApi.accounts(workspaceId)).data,
    });

    const scopes: { key: InboxFilters['scope']; label: string }[] = [
        { key: 'all', label: t('inbox.all') },
        { key: 'mine', label: t('inbox.mine') },
        { key: 'unassigned', label: t('inbox.unassigned') },
    ];

    const activeFilterCount = [filters.status, filters.tag_id, filters.assigned_team_id, filters.whatsapp_account_id].filter(Boolean)
        .length + (filters.unread ? 1 : 0);

    return (
        <div className={clsx('w-full shrink-0 flex-col border-e border-slate-200 bg-white md:flex md:w-80', className)}>
            <div className="space-y-2 border-b border-slate-200 p-3">
                <div className="flex gap-2">
                    <Input
                        placeholder={t('common.search')}
                        value={searchDraft}
                        onChange={(e) => setSearchDraft(e.target.value)}
                        className="flex-1"
                    />
                    <button
                        type="button"
                        onClick={() => setFiltersOpen((open) => !open)}
                        className={clsx(
                            'relative flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border transition-colors',
                            filtersOpen || activeFilterCount > 0
                                ? 'border-brand-300 bg-brand-50 text-brand-700'
                                : 'border-slate-300 bg-white text-slate-500 hover:bg-slate-50',
                        )}
                        title={t('inbox.filters')}
                        aria-label={t('inbox.filters')}
                    >
                        <SlidersHorizontal size={16} />
                        {activeFilterCount > 0 && (
                            <span className="absolute -top-1 -end-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">
                                {activeFilterCount}
                            </span>
                        )}
                    </button>
                </div>
                <div className="flex gap-1 rounded-lg bg-slate-100 p-0.5">
                    {scopes.map(({ key, label }) => (
                        <button
                            key={key}
                            onClick={() => onFiltersChange({ ...filters, scope: key })}
                            className={clsx(
                                'flex-1 truncate rounded-md px-2 py-1.5 text-xs font-medium transition-colors',
                                filters.scope === key
                                    ? 'bg-white text-slate-900 shadow-xs'
                                    : 'text-slate-500 hover:text-slate-700',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {filtersOpen && (
                    <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <div className="flex items-center justify-between">
                            <p className="text-xs font-semibold text-slate-600">{t('inbox.filters')}</p>
                            <button
                                type="button"
                                onClick={() => setFiltersOpen(false)}
                                className="rounded-lg p-1 text-slate-400 hover:bg-white hover:text-slate-600"
                                aria-label={t('common.close')}
                            >
                                <X size={14} />
                            </button>
                        </div>
                        <div className="grid grid-cols-1 gap-2">
                            <Select
                                value={filters.status}
                                onChange={(e) => onFiltersChange({ ...filters, status: e.target.value })}
                                className="!py-2 text-sm"
                            >
                                <option value="">{t('common.status')}</option>
                                <option value="open">{t('inbox.open')}</option>
                                <option value="pending">{t('inbox.pending')}</option>
                                <option value="closed">{t('inbox.closed')}</option>
                            </Select>
                            <Select
                                value={filters.tag_id}
                                onChange={(e) => onFiltersChange({ ...filters, tag_id: e.target.value })}
                                className="!py-2 text-sm"
                            >
                                <option value="">{t('inbox.tag')}</option>
                                {tags.data?.map((tag) => (
                                    <option key={tag.id} value={tag.id}>
                                        {tag.name}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                value={filters.assigned_team_id}
                                onChange={(e) => onFiltersChange({ ...filters, assigned_team_id: e.target.value })}
                                className="!py-2 text-sm"
                            >
                                <option value="">{t('inbox.team')}</option>
                                {teams.data?.map((team) => (
                                    <option key={team.id} value={team.id}>
                                        {team.name}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                value={filters.whatsapp_account_id}
                                onChange={(e) => onFiltersChange({ ...filters, whatsapp_account_id: e.target.value })}
                                className="!py-2 text-sm"
                            >
                                <option value="">{t('inbox.number')}</option>
                                {accounts.data?.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.display_phone_number}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                            <input
                                type="checkbox"
                                className="h-4 w-4 accent-brand-600"
                                checked={filters.unread}
                                onChange={(e) => onFiltersChange({ ...filters, unread: e.target.checked })}
                            />
                            {t('inbox.unread')}
                        </label>
                        {activeFilterCount > 0 && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="w-full"
                                onClick={() =>
                                    onFiltersChange({
                                        ...filters,
                                        status: '',
                                        tag_id: '',
                                        assigned_team_id: '',
                                        whatsapp_account_id: '',
                                        unread: false,
                                    })
                                }
                            >
                                {t('common.cancel')}
                            </Button>
                        )}
                    </div>
                )}
            </div>

            <div className="flex-1 overflow-y-auto">
                {loading ? (
                    <Spinner />
                ) : conversations.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 p-8 text-center">
                        <Inbox size={20} className="text-slate-300" />
                        <p className="text-xs text-slate-400">{t('common.empty')}</p>
                    </div>
                ) : (
                    conversations.map((conversation) => (
                        <button
                            key={conversation.id}
                            onClick={() => onSelect(conversation.id)}
                            className={clsx(
                                'relative flex w-full items-start gap-2.5 border-b border-slate-100 p-3 text-start transition-colors hover:bg-slate-50',
                                activeId === conversation.id && 'bg-brand-50 hover:bg-brand-50',
                            )}
                        >
                            {activeId === conversation.id && (
                                <span className="absolute inset-y-0 start-0 w-0.5 bg-brand-600" aria-hidden="true" />
                            )}
                            <Avatar name={conversation.contact?.full_name} size={9} />
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="truncate text-sm font-semibold text-slate-800">
                                        {conversation.contact?.full_name ?? conversation.contact?.phone_number}
                                    </span>
                                    {conversation.last_message_at && (
                                        <span className="shrink-0 text-[10px] text-slate-400">
                                            {formatDistanceToNowStrict(new Date(conversation.last_message_at), {
                                                locale: dateLocale,
                                            })}
                                        </span>
                                    )}
                                </div>
                                <p className="truncate text-xs text-slate-500">
                                    {conversation.last_message?.content ?? conversation.contact?.phone_number}
                                </p>
                                <div className="mt-1 flex flex-wrap items-center gap-1">
                                    <Badge color={statusColor(conversation.status)}>{statusLabel(conversation.status)}</Badge>
                                    {conversation.automation_status === 'paused' ? (
                                        <span title={t('inbox.bot_paused')}>
                                            <PauseCircle size={13} className="text-amber-500" />
                                        </span>
                                    ) : (
                                        <span title={t('inbox.bot_active')}>
                                            <Bot size={13} className="text-brand-600" />
                                        </span>
                                    )}
                                    {conversation.assigned_user && (
                                        <span className="text-[10px] text-slate-500">→ {conversation.assigned_user.name}</span>
                                    )}
                                    {conversation.assigned_team && (
                                        <Badge color="blue">{conversation.assigned_team.name}</Badge>
                                    )}
                                    {conversation.contact?.tags?.slice(0, 2).map((tag) => (
                                        <span
                                            key={tag.id}
                                            className="rounded-full px-1.5 text-[10px] font-medium"
                                            style={{ backgroundColor: tag.color + '22', color: tag.color }}
                                        >
                                            {tag.name}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            {conversation.unread_count > 0 && (
                                <span className="mt-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">
                                    {conversation.unread_count}
                                </span>
                            )}
                        </button>
                    ))
                )}
            </div>
        </div>
    );
}
