import fs from 'node:fs';

function edit(path, transforms) {
    let source = fs.readFileSync(path, 'utf8');

    for (const [from, to, label] of transforms) {
        if (!source.includes(from)) {
            throw new Error(`${path}: expected source not found for ${label}`);
        }
        source = source.replace(from, to);
    }

    fs.writeFileSync(path, source);
}

edit('resources/js/components/ui.tsx', [
    [
        `    useEffect,\n    useId,`,
        `    useCallback,\n    useEffect,\n    useId,`,
        'Select useCallback import',
    ],
    [
        `    const updateMenuPosition = () => {\n        const trigger = buttonRef.current;\n        if (!trigger) return;\n\n        const rect = trigger.getBoundingClientRect();\n        const viewportH = window.innerHeight;\n        const viewportW = window.innerWidth;\n        const gap = 6;\n        const menuHeight = Math.min(280, options.length * 40 + 12);\n        const spaceBelow = viewportH - rect.bottom - gap;\n        const openUp = spaceBelow < menuHeight && rect.top > spaceBelow;\n        const width = Math.max(rect.width, 160);\n        const left = Math.min(Math.max(8, rect.left), viewportW - width - 8);\n\n        setMenuStyle({\n            position: 'fixed',\n            top: openUp ? undefined : rect.bottom + gap,\n            bottom: openUp ? viewportH - rect.top + gap : undefined,\n            left,\n            width,\n            maxHeight: Math.min(280, openUp ? rect.top - gap - 8 : spaceBelow - 8),\n            zIndex: 80,\n        });\n    };`,
        `    const updateMenuPosition = useCallback(() => {\n        const trigger = buttonRef.current;\n        if (!trigger) return;\n\n        const rect = trigger.getBoundingClientRect();\n        const viewportH = window.innerHeight;\n        const viewportW = window.innerWidth;\n        const gap = 6;\n        const menuHeight = Math.min(280, options.length * 40 + 12);\n        const spaceBelow = viewportH - rect.bottom - gap;\n        const openUp = spaceBelow < menuHeight && rect.top > spaceBelow;\n        const width = Math.max(rect.width, 160);\n        const left = Math.min(Math.max(8, rect.left), viewportW - width - 8);\n\n        setMenuStyle({\n            position: 'fixed',\n            top: openUp ? undefined : rect.bottom + gap,\n            bottom: openUp ? viewportH - rect.top + gap : undefined,\n            left,\n            width,\n            maxHeight: Math.min(280, openUp ? rect.top - gap - 8 : spaceBelow - 8),\n            zIndex: 80,\n        });\n    }, [options.length]);`,
        'memoize select position updater',
    ],
    [
        `        const onReposition = () => updateMenuPosition();\n        window.addEventListener('resize', onReposition);`,
        `        const onReposition = updateMenuPosition;\n        window.addEventListener('resize', onReposition);`,
        'stable select reposition listener',
    ],
    [
        `    }, [open, options.length]);`,
        `    }, [open, updateMenuPosition]);`,
        'select layout effect dependencies',
    ],
    [
        `        const selectedIdx = options.findIndex((o) => o.value === selectedValue);\n        setActiveIndex(selectedIdx >= 0 ? selectedIdx : (enabledIndexes[0] ?? -1));\n\n`,
        ``,
        'remove synchronous active-index effect reset',
    ],
    [
        `    }, [open, options, selectedValue, enabledIndexes]);\n\n    useEffect(() => {`,
        `    }, [open]);\n\n    useEffect(() => {`,
        'outside-click effect dependencies',
    ],
    [
        `    const commit = (next: string) => {`,
        `    const openMenu = () => {\n        const selectedIdx = options.findIndex((o) => o.value === selectedValue);\n        setActiveIndex(selectedIdx >= 0 ? selectedIdx : (enabledIndexes[0] ?? -1));\n        setOpen(true);\n    };\n\n    const commit = (next: string) => {`,
        'move active-index initialization into open event',
    ],
    [
        `                onClick={() => !disabled && setOpen((v) => !v)}`,
        `                onClick={() => {\n                    if (disabled) return;\n                    if (open) setOpen(false);\n                    else openMenu();\n                }}`,
        'select click open handler',
    ],
    [
        `                        if (!open) setOpen(true);`,
        `                        if (!open) openMenu();`,
        'select keyboard open handler down',
    ],
    [
        `                        if (!open) setOpen(true);`,
        `                        if (!open) openMenu();`,
        'select keyboard open handler up',
    ],
]);

edit('resources/js/pages/automations/AutomationBuilder.tsx', [
    [
        `    const nodeActionsRef = useRef({\n        updateNodeConfig,\n        deleteNode,\n        duplicateNode,\n        openSettings: (nodeId: string) => setSelectedNodeId(nodeId),\n    });\n    nodeActionsRef.current = {\n        updateNodeConfig,\n        deleteNode,\n        duplicateNode,\n        openSettings: (nodeId: string) => setSelectedNodeId(nodeId),\n    };\n\n    /** Inject per-node actions without storing functions in persisted draft state. */\n    const displayNodes = useMemo(\n        () =>\n            nodes.map((node) => ({\n                ...node,\n                data: {\n                    ...node.data,\n                    onConfigChange: (config: Record<string, any>) => nodeActionsRef.current.updateNodeConfig(node.id, config),\n                    onDelete: () => nodeActionsRef.current.deleteNode(node.id),\n                    onDuplicate: () => nodeActionsRef.current.duplicateNode(node.id),\n                    onOpenSettings: () => nodeActionsRef.current.openSettings(node.id),\n                },\n            })),\n        [nodes],\n    );`,
        `    /** Inject per-node actions without storing functions in persisted draft state. */\n    const displayNodes = useMemo(\n        () =>\n            nodes.map((node) => ({\n                ...node,\n                data: {\n                    ...node.data,\n                    onConfigChange: (config: Record<string, any>) => updateNodeConfig(node.id, config),\n                    onDelete: () => deleteNode(node.id),\n                    onDuplicate: () => duplicateNode(node.id),\n                    onOpenSettings: () => setSelectedNodeId(node.id),\n                },\n            })),\n        [nodes, updateNodeConfig, deleteNode, duplicateNode],\n    );`,
        'remove render-time node action ref mutation',
    ],
    [
        `            <SimulatorDrawer\n                open={simulatorOpen}\n                onClose={() => {\n                    setSimulatorOpen(false);\n                    highlightNode(null);\n                }}\n                automationId={id}\n                onNodeHighlight={highlightNode}\n            />`,
        `            {simulatorOpen && (\n                <SimulatorDrawer\n                    open\n                    onClose={() => {\n                        setSimulatorOpen(false);\n                        highlightNode(null);\n                    }}\n                    automationId={id}\n                    onNodeHighlight={highlightNode}\n                />\n            )}`,
        'unmount simulator on close',
    ],
]);

edit('resources/js/pages/automations/nodeIcons.tsx', [
    [
        `import type { LucideIcon } from 'lucide-react';`,
        `import type { LucideIcon, LucideProps } from 'lucide-react';`,
        'node icon props type',
    ],
    [
        `export function nodeIcon(type: string): LucideIcon {\n    return NODE_ICONS[type] ?? GitBranch;\n}\n`,
        `export function nodeIcon(type: string): LucideIcon {\n    return NODE_ICONS[type] ?? GitBranch;\n}\n\nexport function NodeTypeIcon({ type, ...props }: { type: string } & LucideProps) {\n    const Icon = NODE_ICONS[type] ?? GitBranch;\n\n    return <Icon {...props} />;\n}\n`,
        'stable node icon component',
    ],
]);

edit('resources/js/pages/automations/FlowNode.tsx', [
    [
        `import { nodeIcon } from './nodeIcons';`,
        `import { NodeTypeIcon } from './nodeIcons';`,
        'FlowNode icon import',
    ],
    [
        `    const Icon = nodeIcon(type);\n`,
        ``,
        'FlowNode render-time icon lookup',
    ],
    [
        `                    <Icon size={13} strokeWidth={2.5} />`,
        `                    <NodeTypeIcon type={type} size={13} strokeWidth={2.5} />`,
        'FlowNode icon render',
    ],
]);

edit('resources/js/pages/automations/NodeSettingsPanel.tsx', [
    [
        `import { nodeIcon, solidIconTileStyle } from './nodeIcons';`,
        `import { NodeTypeIcon, solidIconTileStyle } from './nodeIcons';`,
        'NodeSettings icon import',
    ],
    [
        `    const Icon = nodeIcon(type);\n`,
        ``,
        'NodeSettings render-time icon lookup',
    ],
    [
        `                            <Icon size={16} strokeWidth={2.4} />`,
        `                            <NodeTypeIcon type={type} size={16} strokeWidth={2.4} />`,
        'NodeSettings icon render',
    ],
]);

edit('resources/js/pages/automations/SimulatorDrawer.tsx', [
    [
        `    useEffect(() => {\n        if (!open) {\n            playToken.current += 1;\n            setState(null);\n            setDisplayed([]);\n            setTyping(false);\n            setBusy(false);\n            revealedCount.current = 0;\n            setText('');\n            setError(null);\n            return;\n        }\n        start.mutate();\n        // eslint-disable-next-line react-hooks/exhaustive-deps\n    }, [open]);`,
        `    useEffect(() => {\n        if (!open) return;\n        start.mutate();\n        // The parent unmounts this drawer when it closes, so local simulation state resets naturally.\n        // eslint-disable-next-line react-hooks/exhaustive-deps\n    }, [open]);`,
        'remove synchronous simulator state reset effect',
    ],
]);

edit('resources/js/pages/campaigns/CampaignsPage.tsx', [
    [
        `    const variableIndexes = useMemo(() => {\n        if (!selectedTemplate?.body) return [];\n        return [...new Set(Array.from(selectedTemplate.body.matchAll(/\\{\\{(\\d+)\\}\\}/g), (match) => Number(match[1])))].sort((a, b) => a - b);\n    }, [selectedTemplate]);`,
        `    const variableIndexes = selectedTemplate?.body\n        ? [...new Set(Array.from(selectedTemplate.body.matchAll(/\\{\\{(\\d+)\\}\\}/g), (match) => Number(match[1])))].sort((a, b) => a - b)\n        : [];`,
        'remove unnecessary campaign variable memoization',
    ],
]);

edit('resources/js/pages/inbox/ConversationList.tsx', [
    [
        `import { useEffect, useState } from 'react';`,
        `import { useState } from 'react';`,
        'ConversationList hook imports',
    ],
    [
        `    const [searchDraft, setSearchDraft] = useState(filters.search);\n    const [filtersOpen, setFiltersOpen] = useState(false);\n\n    useEffect(() => setSearchDraft(filters.search), [filters.search]);\n\n    useEffect(() => {\n        const timer = setTimeout(() => {\n            if (searchDraft !== filters.search) {\n                onFiltersChange({ ...filters, search: searchDraft });\n            }\n        }, 300);\n        return () => clearTimeout(timer);\n        // eslint-disable-next-line react-hooks/exhaustive-deps -- debounce only on draft\n    }, [searchDraft]);`,
        `    const [filtersOpen, setFiltersOpen] = useState(false);`,
        'remove mirrored/debounced search state',
    ],
    [
        `                        value={searchDraft}\n                        onChange={(e) => setSearchDraft(e.target.value)}`,
        `                        value={filters.search}\n                        onChange={(e) => onFiltersChange({ ...filters, search: e.target.value })}`,
        'make inbox search controlled by filter source',
    ],
]);

edit('resources/js/pages/inbox/InboxPage.tsx', [
    [
        `function filtersFromParams(params: URLSearchParams): InboxFilters {`,
        `function useDebouncedValue<T>(value: T, delay: number): T {\n    const [debounced, setDebounced] = useState(value);\n\n    useEffect(() => {\n        const timer = window.setTimeout(() => setDebounced(value), delay);\n        return () => window.clearTimeout(timer);\n    }, [value, delay]);\n\n    return debounced;\n}\n\nfunction filtersFromParams(params: URLSearchParams): InboxFilters {`,
        'Inbox debounced value hook',
    ],
    [
        `    const [searchParams] = useSearchParams();`,
        `    const [searchParams, setSearchParams] = useSearchParams();`,
        'Inbox search params setter',
    ],
    [
        `    const [filters, setFilters] = useState<InboxFilters>(() => filtersFromParams(searchParams));\n    const [contactOpen, setContactOpen] = useState(false);\n\n    useEffect(() => {\n        setFilters(filtersFromParams(searchParams));\n    }, [searchParams]);\n\n    const activeId = conversationId ? Number(conversationId) : null;\n\n    useEffect(() => {\n        setContactOpen(false);\n    }, [activeId]);`,
        `    const filters = filtersFromParams(searchParams);\n    const debouncedSearch = useDebouncedValue(filters.search, 300);\n    const [contactConversationId, setContactConversationId] = useState<number | null>(null);\n\n    const setFilters = (next: InboxFilters) => {\n        const params = new URLSearchParams(searchParams);\n        const values: Record<string, string> = {\n            scope: next.scope === 'all' ? '' : next.scope,\n            status: next.status,\n            unread: next.unread ? '1' : '',\n            tag_id: next.tag_id,\n            assigned_team_id: next.assigned_team_id,\n            whatsapp_account_id: next.whatsapp_account_id,\n            search: next.search,\n        };\n\n        Object.entries(values).forEach(([key, value]) => {\n            if (value) params.set(key, value);\n            else params.delete(key);\n        });\n        setSearchParams(params, { replace: true });\n    };\n\n    const activeId = conversationId ? Number(conversationId) : null;\n    const contactOpen = activeId !== null && contactConversationId === activeId;`,
        'derive Inbox filters from URL and drawer state from conversation id',
    ],
    [
        `        queryKey: ['conversations', workspaceId, filters],`,
        `        queryKey: ['conversations', workspaceId, { ...filters, search: debouncedSearch }],`,
        'debounce inbox query key',
    ],
    [
        `            if (filters.search) params.search = filters.search;`,
        `            if (debouncedSearch) params.search = debouncedSearch;`,
        'debounce inbox API search parameter',
    ],
    [
        `                        onOpenContact={() => setContactOpen(true)}`,
        `                        onOpenContact={() => setContactConversationId(activeId)}`,
        'associate contact drawer with active conversation',
    ],
    [
        `                        onDrawerClose={() => setContactOpen(false)}`,
        `                        onDrawerClose={() => setContactConversationId(null)}`,
        'close contact drawer without sync effect',
    ],
]);

console.log('React warning codemod applied successfully.');
