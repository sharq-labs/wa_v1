import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { contactsApi, customFieldsApi, tagsApi } from '@/api';
import {
    Avatar,
    Badge,
    Button,
    EmptyState,
    Input,
    Label,
    Modal,
    PageHeader,
    QueryError,
    Select,
    Spinner,
    statusColor,
} from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import { confirmDialog } from '@/stores/confirmStore';
import type { Contact } from '@/types';

export default function ContactsPage() {
    const { t, statusLabel } = useI18n();
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    const [search, setSearch] = useState('');
    const [tagId, setTagId] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [editContact, setEditContact] = useState<Contact | null>(null);
    const [createOpen, setCreateOpen] = useState(false);

    const contacts = useQuery({
        queryKey: ['contacts', workspaceId, { search, tagId, status, page }],
        queryFn: async () => {
            const params: Record<string, string> = { page: String(page) };
            if (search) params.search = search;
            if (tagId) params.tag_id = tagId;
            if (status) params.status = status;
            return (await contactsApi.list(workspaceId, params)).data;
        },
    });

    const tags = useQuery({ queryKey: ['tags', workspaceId], queryFn: async () => (await tagsApi.list(workspaceId)).data });

    const remove = useMutation({
        mutationFn: (id: number) => contactsApi.remove(workspaceId, id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['contacts', workspaceId] }),
    });

    const confirmRemove = async (contact: Contact) => {
        const confirmed = await confirmDialog({
            title: t('common.delete_named', { name: contact.full_name || contact.phone_number }),
            description: t('common.irreversible'),
            confirmLabel: t('common.delete'),
            destructive: true,
        });
        if (confirmed) remove.mutate(contact.id);
    };

    const items = contacts.data?.items ?? [];

    return (
        <div className="p-4 md:p-6 lg:p-8">
            <PageHeader
                title={t('nav.contacts')}
                subtitle={t('contacts.subtitle')}
                actions={
                    <>
                        <a href={`/api/workspaces/${workspaceId}/contacts/export`} download>
                            <Button variant="secondary">
                                <Download size={15} /> {t('common.export_csv')}
                            </Button>
                        </a>
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus size={15} /> {t('common.create')}
                        </Button>
                    </>
                }
            />

            <div className="mb-3 flex flex-wrap gap-2">
                <Input
                    placeholder={t('common.search')}
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    className="!w-64"
                />
                <Select value={tagId} onChange={(e) => setTagId(e.target.value)} className="!w-40">
                    <option value="">{t('contacts.all_tags')}</option>
                    {tags.data?.map((tag) => (
                        <option key={tag.id} value={tag.id}>
                            {tag.name}
                        </option>
                    ))}
                </Select>
                <Select value={status} onChange={(e) => setStatus(e.target.value)} className="!w-40">
                    <option value="">{t('contacts.all_statuses')}</option>
                    <option value="active">{t('status.active')}</option>
                    <option value="archived">{t('status.archived')}</option>
                    <option value="blocked">{t('status.blocked')}</option>
                </Select>
            </div>

            {contacts.isLoading ? (
                <Spinner />
            ) : contacts.isError ? (
                <QueryError onRetry={() => contacts.refetch()} />
            ) : items.length === 0 ? (
                <EmptyState
                    icon={<Users size={22} />}
                    title={t('contacts.empty')}
                    action={
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus size={15} /> {t('common.create')}
                        </Button>
                    }
                />
            ) : (
                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-card">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th className="px-4 py-2.5 text-start">{t('common.name')}</th>
                                <th className="px-3 text-start">{t('common.phone')}</th>
                                <th className="px-3 text-start">{t('common.tags')}</th>
                                <th className="px-3 text-start">{t('common.status')}</th>
                                <th className="px-3 text-start">{t('contacts.last_message')}</th>
                                <th className="px-3 text-end">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((contact) => (
                                <tr key={contact.id} className="border-t border-slate-100 hover:bg-slate-50">
                                    <td className="px-4 py-2">
                                        <button className="flex items-center gap-2" onClick={() => setEditContact(contact)}>
                                            <Avatar name={contact.full_name} size={7} />
                                            <span className="font-medium text-slate-800">{contact.full_name}</span>
                                        </button>
                                    </td>
                                    <td className="px-3 text-slate-600">{contact.phone_number}</td>
                                    <td className="px-3">
                                        <div className="flex flex-wrap gap-1">
                                            {contact.tags?.map((tag) => (
                                                <span
                                                    key={tag.id}
                                                    className="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                                    style={{ backgroundColor: tag.color + '22', color: tag.color }}
                                                >
                                                    {tag.name}
                                                </span>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="px-3">
                                        <Badge color={statusColor(contact.status)}>{statusLabel(contact.status)}</Badge>
                                    </td>
                                    <td className="px-3 text-xs text-slate-500">
                                        {contact.last_message_at ? new Date(contact.last_message_at).toLocaleString() : '—'}
                                    </td>
                                    <td className="px-3 text-end">
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    navigate(`/inbox?search=${encodeURIComponent(contact.phone_number)}`)
                                                }
                                            >
                                                {t('contacts.open_inbox')}
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                aria-label={`${t('common.delete')} ${contact.full_name ?? contact.phone_number}`}
                                                onClick={() => void confirmRemove(contact)}
                                            >
                                                <Trash2 size={13} className="text-red-500" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {contacts.data && contacts.data.meta.last_page > 1 && (
                        <div className="flex justify-between border-t border-slate-100 px-4 py-2 text-xs">
                            <button disabled={page <= 1} onClick={() => setPage(page - 1)} className="text-brand-600 disabled:text-slate-300">
                                {t('common.prev')}
                            </button>
                            <span className="text-slate-500">
                                {contacts.data.meta.current_page} / {contacts.data.meta.last_page} ({contacts.data.meta.total})
                            </span>
                            <button
                                disabled={page >= contacts.data.meta.last_page}
                                onClick={() => setPage(page + 1)}
                                className="text-brand-600 disabled:text-slate-300"
                            >
                                {t('common.next')}
                            </button>
                        </div>
                    )}
                </div>
            )}

            <ContactFormModal
                open={createOpen || !!editContact}
                contact={editContact}
                onClose={() => {
                    setCreateOpen(false);
                    setEditContact(null);
                }}
            />
        </div>
    );
}

function ContactFormModal({ open, contact, onClose }: { open: boolean; contact: Contact | null; onClose: () => void }) {
    if (!open) return null;

    // Keyed so the form state re-initialises per contact (or per "new").
    return <ContactForm key={contact?.id ?? 'new'} contact={contact} onClose={onClose} />;
}

function ContactForm({ contact, onClose }: { contact: Contact | null; onClose: () => void }) {
    const workspaceId = useWorkspaceId();
    const queryClient = useQueryClient();
    const { t, statusLabel } = useI18n();

    const [form, setForm] = useState<Record<string, string>>(() => ({
        phone_number: contact?.phone_number ?? '',
        first_name: contact?.first_name ?? '',
        last_name: contact?.last_name ?? '',
        email: contact?.email ?? '',
        country: contact?.country ?? '',
        opt_in_status: contact?.opt_in_status ?? 'unknown',
    }));
    const [customValues, setCustomValues] = useState<Record<string, string>>(() =>
        Object.fromEntries((contact?.custom_fields ?? []).map((f) => [f.key, f.value ?? ''])),
    );

    const fields = useQuery({
        queryKey: ['custom-fields', workspaceId],
        queryFn: async () => (await customFieldsApi.list(workspaceId)).data,
    });

    const save = useMutation({
        mutationFn: () => {
            const body = { ...form, custom_fields: customValues };
            return contact
                ? contactsApi.update(workspaceId, contact.id, body)
                : contactsApi.create(workspaceId, body);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['contacts', workspaceId] });
            onClose();
        },
    });

    return (
        <Modal open onClose={onClose} title={contact ? t('common.edit') : t('common.create')}>
            <div className="space-y-3">
                {(['phone_number', 'first_name', 'last_name', 'email', 'country'] as const).map((name) => (
                    <div key={name}>
                        <Label>{name.replaceAll('_', ' ')}</Label>
                        <Input value={form[name] ?? ''} onChange={(e) => setForm({ ...form, [name]: e.target.value })} />
                    </div>
                ))}
                <div>
                    <Label>{t('contacts.opt_in')}</Label>
                    <Select value={form.opt_in_status} onChange={(e) => setForm({ ...form, opt_in_status: e.target.value })}>
                        <option value="unknown">{statusLabel('unknown')}</option>
                        <option value="opted_in">{statusLabel('opted_in')}</option>
                        <option value="opted_out">{statusLabel('opted_out')}</option>
                    </Select>
                </div>
                {(fields.data ?? []).length > 0 && (
                    <div className="border-t border-slate-100 pt-2">
                        <p className="mb-2 text-xs font-semibold text-slate-500">{t('inbox.custom_fields')}</p>
                        {fields.data!.map((field) => (
                            <div key={field.key} className="mb-2">
                                <Label>{field.name}</Label>
                                <Input
                                    value={customValues[field.key] ?? ''}
                                    onChange={(e) => setCustomValues({ ...customValues, [field.key]: e.target.value })}
                                />
                            </div>
                        ))}
                    </div>
                )}
                <Button onClick={() => save.mutate()} disabled={save.isPending || !form.phone_number} className="w-full">
                    {t('common.save')}
                </Button>
            </div>
        </Modal>
    );
}
