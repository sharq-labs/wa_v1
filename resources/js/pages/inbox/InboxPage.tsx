import { useQuery, useQueryClient } from '@tanstack/react-query';
import { MessageSquare } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { inboxApi } from '@/api';
import { QueryError } from '@/components/ui';
import { getEcho, leaveChannel } from '@/lib/echo';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import ConversationList from './ConversationList';
import ChatPanel from './ChatPanel';
import ContactPanel from './ContactPanel';

export interface InboxFilters {
    scope: 'all' | 'mine' | 'unassigned';
    status: string;
    unread: boolean;
    tag_id: string;
    assigned_team_id: string;
    whatsapp_account_id: string;
    search: string;
}

function filtersFromParams(params: URLSearchParams): InboxFilters {
    const scope = params.get('scope');
    return {
        scope: scope === 'mine' || scope === 'unassigned' ? scope : 'all',
        status: params.get('status') ?? '',
        unread: params.get('unread') === '1',
        tag_id: params.get('tag_id') ?? '',
        assigned_team_id: params.get('assigned_team_id') ?? '',
        whatsapp_account_id: params.get('whatsapp_account_id') ?? '',
        search: params.get('search') ?? '',
    };
}

export default function InboxPage() {
    const workspaceId = useWorkspaceId();
    const { conversationId } = useParams();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const queryClient = useQueryClient();
    const { t } = useI18n();

    const [filters, setFilters] = useState<InboxFilters>(() => filtersFromParams(searchParams));
    const [contactOpen, setContactOpen] = useState(false);

    useEffect(() => {
        setFilters(filtersFromParams(searchParams));
    }, [searchParams]);

    const activeId = conversationId ? Number(conversationId) : null;

    useEffect(() => {
        setContactOpen(false);
    }, [activeId]);

    const conversationsQuery = useQuery({
        queryKey: ['conversations', workspaceId, filters],
        queryFn: async () => {
            const params: Record<string, string> = {};
            if (filters.scope !== 'all') params.scope = filters.scope;
            if (filters.status) params.status = filters.status;
            if (filters.unread) params.unread = '1';
            if (filters.tag_id) params.tag_id = filters.tag_id;
            if (filters.assigned_team_id) params.assigned_team_id = filters.assigned_team_id;
            if (filters.whatsapp_account_id) params.whatsapp_account_id = filters.whatsapp_account_id;
            if (filters.search) params.search = filters.search;
            return (await inboxApi.conversations(workspaceId, params)).data;
        },
        refetchInterval: 30_000,
    });

    useEffect(() => {
        const channel = getEcho().private(`workspace.${workspaceId}`);

        const refreshConversations = () => {
            queryClient.invalidateQueries({ queryKey: ['conversations', workspaceId] });
        };

        channel.listen('.message.new', (payload: any) => {
            refreshConversations();
            queryClient.invalidateQueries({ queryKey: ['messages', workspaceId, payload.conversation_id] });
        });
        channel.listen('.message.status', (payload: any) => {
            queryClient.invalidateQueries({ queryKey: ['messages', workspaceId, payload.conversation_id] });
        });
        channel.listen('.conversation.updated', refreshConversations);
        channel.listen('.conversation.assigned', () => {
            refreshConversations();
            if (activeId) queryClient.invalidateQueries({ queryKey: ['conversation', workspaceId, activeId] });
        });
        channel.listen('.automation.status', () => {
            refreshConversations();
            if (activeId) queryClient.invalidateQueries({ queryKey: ['conversation', workspaceId, activeId] });
        });
        channel.listen('.contact.updated', () => {
            if (activeId) queryClient.invalidateQueries({ queryKey: ['conversation', workspaceId, activeId] });
        });

        return () => {
            leaveChannel(`private-workspace.${workspaceId}`);
        };
    }, [workspaceId, activeId, queryClient]);

    return (
        <div className="flex h-full">
            <ConversationList
                conversations={conversationsQuery.data?.items ?? []}
                loading={conversationsQuery.isLoading}
                activeId={activeId}
                filters={filters}
                onFiltersChange={setFilters}
                onSelect={(id) => navigate(`/inbox/${id}`)}
                className={activeId ? 'hidden md:flex' : 'flex'}
            />
            {conversationsQuery.isError && !activeId ? (
                <QueryError onRetry={() => conversationsQuery.refetch()} />
            ) : activeId ? (
                <>
                    <ChatPanel
                        key={activeId}
                        conversationId={activeId}
                        onOpenContact={() => setContactOpen(true)}
                    />
                    <ContactPanel
                        conversationId={activeId}
                        drawerOpen={contactOpen}
                        onDrawerClose={() => setContactOpen(false)}
                    />
                </>
            ) : (
                <div className="hidden flex-1 flex-col items-center justify-center gap-3 md:flex">
                    <div className="flex h-14 w-14 items-center justify-center rounded-full bg-white text-slate-400 shadow-xs ring-1 ring-slate-200">
                        <MessageSquare size={24} />
                    </div>
                    <p className="text-sm text-slate-500">{t('inbox.no_conversation')}</p>
                </div>
            )}
        </div>
    );
}
