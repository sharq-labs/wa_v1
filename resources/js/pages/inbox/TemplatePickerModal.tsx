import { useMutation, useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { customFieldsApi, inboxApi, templatesApi } from '@/api';
import { Button, Label, Modal, Select } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { extractVariableIndexes, renderTemplatePreview, type VariableMapping as Mapping } from '@/lib/templatePreview';
import { useWorkspaceId } from '@/stores/authStore';

/**
 * Template variable mapper: maps {{1}}, {{2}}... to contact fields, custom
 * fields, workspace/agent values or static text, with a live preview.
 */
export default function TemplatePickerModal({
    open,
    onClose,
    conversationId,
    onSent,
}: {
    open: boolean;
    onClose: () => void;
    conversationId: number;
    onSent: () => void;
}) {
    const workspaceId = useWorkspaceId();
    const { t } = useI18n();
    const [templateId, setTemplateId] = useState('');
    const [mappings, setMappings] = useState<Mapping[]>([]);

    const templates = useQuery({
        queryKey: ['templates', workspaceId, 'approved'],
        queryFn: async () => (await templatesApi.list(workspaceId, { status: 'approved' })).data,
        enabled: open,
    });

    const customFields = useQuery({
        queryKey: ['custom-fields', workspaceId],
        queryFn: async () => (await customFieldsApi.list(workspaceId)).data,
        enabled: open,
    });

    const template = templates.data?.find((item) => String(item.id) === templateId);

    const variableIndexes = useMemo(() => (template ? extractVariableIndexes(template.body) : []), [template]);

    const setMapping = (index: number, patch: Partial<Mapping>) => {
        setMappings((prev) => {
            const existing = prev.find((m) => m.index === index);
            if (existing) {
                return prev.map((m) => (m.index === index ? { ...m, ...patch } : m));
            }
            return [...prev, { index, source: 'static', value: '', ...patch }];
        });
    };

    const previewText = useMemo(
        () => (template ? renderTemplatePreview(template.body, mappings) : ''),
        [template, mappings],
    );

    const send = useMutation({
        mutationFn: () =>
            inboxApi.sendTemplate(workspaceId, conversationId, {
                template_id: Number(templateId),
                variable_mappings: mappings,
            }),
        onSuccess: () => {
            onSent();
            onClose();
            setTemplateId('');
            setMappings([]);
        },
    });

    const contactFieldOptions = ['first_name', 'last_name', 'full_name', 'phone_number', 'email'];
    const contactFieldLabel = (field: string) => t(`contact.${field}`);

    return (
        <Modal open={open} onClose={onClose} title={t('inbox.send_template')} wide>
            <div className="space-y-4">
                <div>
                    <Label>{t('inbox.template')}</Label>
                    <Select value={templateId} onChange={(e) => { setTemplateId(e.target.value); setMappings([]); }}>
                        <option value="">—</option>
                        {templates.data?.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name} ({item.language})
                            </option>
                        ))}
                    </Select>
                </div>

                {template && variableIndexes.length > 0 && (
                    <div className="space-y-2">
                        <p className="text-xs font-semibold text-slate-600">{t('inbox.variable_mapping')}</p>
                        {variableIndexes.map((index) => {
                            const mapping = mappings.find((m) => m.index === index) ?? { index, source: 'static' as const, value: '' };
                            return (
                                <div key={index} className="flex items-center gap-2">
                                    <span className="w-12 shrink-0 rounded bg-slate-100 px-1.5 py-1 text-center text-xs font-mono">
                                        {'{{' + index + '}}'}
                                    </span>
                                    <Select
                                        value={mapping.source}
                                        onChange={(e) => setMapping(index, { source: e.target.value as Mapping['source'], value: '' })}
                                        className="!w-32"
                                    >
                                        <option value="static">{t('inbox.source_static')}</option>
                                        <option value="contact">{t('inbox.source_contact')}</option>
                                        <option value="custom">{t('inbox.source_custom')}</option>
                                        <option value="workspace">{t('inbox.source_workspace')}</option>
                                        <option value="agent">{t('inbox.source_agent')}</option>
                                    </Select>
                                    {mapping.source === 'static' ? (
                                        <input
                                            value={mapping.value}
                                            onChange={(e) => setMapping(index, { value: e.target.value })}
                                            placeholder={t('inbox.value_placeholder')}
                                            className="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-sm"
                                        />
                                    ) : (
                                        <Select value={mapping.value} onChange={(e) => setMapping(index, { value: e.target.value })} className="flex-1">
                                            <option value="">—</option>
                                            {mapping.source === 'contact' &&
                                                contactFieldOptions.map((field) => <option key={field} value={field}>{contactFieldLabel(field)}</option>)}
                                            {mapping.source === 'custom' &&
                                                customFields.data?.map((field) => <option key={field.key} value={field.key}>{field.name}</option>)}
                                            {mapping.source === 'workspace' && <option value="name">{t('common.name')}</option>}
                                            {mapping.source === 'agent' && <option value="name">{t('common.name')}</option>}
                                        </Select>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {template && (
                    <div>
                        <p className="mb-1 text-xs font-semibold text-slate-600">{t('templates.preview')}</p>
                        <div className="rounded-xl bg-[#e7f8f0] p-3">
                            <div className="max-w-xs rounded-lg rounded-es-sm bg-white p-2.5 text-sm shadow-sm">
                                {template.header_type === 'text' && template.header_content && (
                                    <p className="mb-1 font-semibold">{template.header_content}</p>
                                )}
                                <p className="whitespace-pre-wrap">{previewText}</p>
                                {template.footer && <p className="mt-1 text-[11px] text-slate-400">{template.footer}</p>}
                            </div>
                        </div>
                    </div>
                )}

                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={() => send.mutate()} disabled={!templateId || send.isPending}>
                        {t('inbox.send_template')}
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
