import {
    Background,
    MarkerType,
    MiniMap,
    Panel,
    ReactFlow,
    ReactFlowProvider,
    addEdge,
    applyEdgeChanges,
    applyNodeChanges,
    useReactFlow,
    type Connection,
    type Edge,
    type EdgeChange,
    type Node,
    type NodeChange,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { useMutation, useQuery } from '@tanstack/react-query';
import {
    ArrowLeft,
    CheckCircle2,
    History,
    Lock,
    Maximize2,
    Minus,
    Play,
    Plus,
    Redo2,
    Rocket,
    Undo2,
    Unlock,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { automationsApi } from '@/api';
import { Badge, Button, Spinner, statusColor } from '@/components/ui';
import { useI18n } from '@/lib/i18n';
import { useWorkspaceId } from '@/stores/authStore';
import type { FlowDefinition } from '@/types';
import BuilderNotice from './BuilderNotice';
import FlowNodeComponent from './FlowNode';
import NodeLibrary from './NodeLibrary';
import NodeSettingsPanel from './NodeSettingsPanel';
import QuickAddEdge from './QuickAddEdge';
import SimulatorDrawer from './SimulatorDrawer';
import StarterRecipePicker from './StarterRecipePicker';
import { detectWhatsAppWindowRisks } from './flowSafety';
import { NODE_META, nodeHandles } from './nodeCatalog';
import { createStarterRecipe, type StarterRecipeKey } from './starterRecipes';

const nodeTypes = { flowNode: FlowNodeComponent };
const edgeTypes = { quickAdd: QuickAddEdge };

const ZOOM_DURATION = 320;
const FIT_DURATION = 480;

/** Animated zoom / fit / lock — avoids the default Controls’ instant jumps. */
function CanvasControls({
    locked,
    onToggleLock,
}: {
    locked: boolean;
    onToggleLock: () => void;
}) {
    const { zoomIn, zoomOut, fitView } = useReactFlow();
    const { t } = useI18n();

    const btn =
        'flex h-8 w-8 items-center justify-center text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 disabled:opacity-40';

    return (
        <Panel
            position="bottom-left"
            className="m-3 flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
        >
            <button
                type="button"
                className={btn}
                title={t('automations.zoom_in')}
                aria-label={t('automations.zoom_in')}
                onClick={() => zoomIn({ duration: ZOOM_DURATION })}
            >
                <Plus size={15} strokeWidth={2.25} />
            </button>
            <button
                type="button"
                className={`${btn} border-t border-slate-100`}
                title={t('automations.zoom_out')}
                aria-label={t('automations.zoom_out')}
                onClick={() => zoomOut({ duration: ZOOM_DURATION })}
            >
                <Minus size={15} strokeWidth={2.25} />
            </button>
            <button
                type="button"
                className={`${btn} border-t border-slate-100`}
                title={t('automations.zoom_fit')}
                aria-label={t('automations.zoom_fit')}
                onClick={() => fitView({ duration: FIT_DURATION, padding: 0.2, maxZoom: 1.15 })}
            >
                <Maximize2 size={14} strokeWidth={2.25} />
            </button>
            <button
                type="button"
                className={`${btn} border-t border-slate-100`}
                title={locked ? t('automations.zoom_unlock') : t('automations.zoom_lock')}
                aria-label={locked ? t('automations.zoom_unlock') : t('automations.zoom_lock')}
                onClick={onToggleLock}
            >
                {locked ? <Lock size={14} strokeWidth={2.25} /> : <Unlock size={14} strokeWidth={2.25} />}
            </button>
        </Panel>
    );
}

let nodeCounter = 0;
let edgeCounter = 0;
const freshNodeId = () => `n_${Date.now().toString(36)}_${++nodeCounter}`;
const freshEdgeId = () => `e_${Date.now().toString(36)}_${++edgeCounter}`;

/**
 * Branch label + color for an edge, derived from its source handle so
 * connections stay readable on the canvas (TRUE/FALSE, button titles…).
 */
function edgeDecoration(
    handle: string | null | undefined,
    sourceType?: string,
    sourceConfig?: Record<string, any>,
    labels?: { true: string; false: string; error: string },
): { label?: string; color: string } {
    if (!handle || handle === 'next') {
        return sourceType === 'http_request' || sourceType === 'send_webhook'
            ? { label: 'OK', color: '#16a34a' }
            : { color: '#94a3b8' };
    }

    if (handle === 'true') return { label: labels?.true ?? 'TRUE', color: '#16a34a' };
    if (handle === 'false') return { label: labels?.false ?? 'FALSE', color: '#dc2626' };
    if (handle === 'error') return { label: labels?.error ?? 'Error', color: '#dc2626' };

    if (sourceType === 'send_buttons') {
        const button = (sourceConfig?.buttons ?? []).find((b: any) => b.id === handle);
        return { label: button?.title ?? handle, color: '#0891b2' };
    }

    if (sourceType === 'random_split') {
        const branch = (sourceConfig?.branches ?? []).find((b: any) => b.handle === handle);
        return { label: `${handle.toUpperCase()} ${branch?.weight ?? ''}%`.trim(), color: '#2563eb' };
    }

    return { label: handle, color: '#94a3b8' };
}

type BranchLabels = { true: string; false: string; error: string };

function decorateEdge(
    edge: Edge,
    sourceType?: string,
    sourceConfig?: Record<string, any>,
    labels?: BranchLabels,
): Edge {
    const { label, color } = edgeDecoration(edge.sourceHandle, sourceType, sourceConfig, labels);

    return {
        ...edge,
        type: 'quickAdd',
        animated: false,
        label,
        data: { ...(edge.data ?? {}), edgeColor: color },
        style: { strokeWidth: 2, stroke: color },
        markerEnd: { type: MarkerType.ArrowClosed, color, width: 18, height: 18 },
    };
}

function toReactFlow(
    definition: FlowDefinition,
    highlightedId?: string | null,
    labels?: BranchLabels,
): { nodes: Node[]; edges: Edge[] } {
    const byId = new Map(definition.nodes.map((n) => [n.id, n]));

    return {
        nodes: definition.nodes.map((n) => ({
            id: n.id,
            type: 'flowNode',
            position: n.position ?? { x: 0, y: 0 },
            data: { nodeType: n.type, config: n.config ?? {}, highlighted: n.id === highlightedId },
        })),
        edges: definition.edges.map((e) => {
            const source = byId.get(e.source);

            return decorateEdge(
                {
                    id: e.id,
                    source: e.source,
                    sourceHandle: e.sourceHandle ?? undefined,
                    target: e.target,
                },
                source?.type,
                source?.config,
                labels,
            );
        }),
    };
}

function toDefinition(nodes: Node[], edges: Edge[]): FlowDefinition {
    return {
        nodes: nodes.map((n) => ({
            id: n.id,
            type: n.data.nodeType as string,
            config: (n.data.config ?? {}) as Record<string, any>,
            position: { x: Math.round(n.position.x), y: Math.round(n.position.y) },
        })),
        edges: edges.map((e) => ({
            id: e.id,
            source: e.source,
            sourceHandle: e.sourceHandle ?? null,
            target: e.target,
        })),
    };
}

function BuilderInner() {
    const { automationId } = useParams();
    const id = Number(automationId);
    const workspaceId = useWorkspaceId();
    const { t, statusLabel, locale } = useI18n();
    const { screenToFlowPosition, setCenter, getNode, fitView } = useReactFlow();
    const branchLabels = useMemo<BranchLabels>(
        () => ({
            true: t('automations.condition_true'),
            false: t('automations.condition_false'),
            error: t('automations.edge_error'),
        }),
        [t],
    );

    const [nodes, setNodes] = useState<Node[]>([]);
    const [edges, setEdges] = useState<Edge[]>([]);
    const [selectedNodeId, setSelectedNodeId] = useState<string | null>(null);
    const [saveState, setSaveState] = useState<'saved' | 'saving' | 'dirty'>('saved');
    const [validState, setValidState] = useState<'unknown' | 'valid'>('unknown');
    const [validationErrors, setValidationErrors] = useState<{ node_id: string | null; message: string }[]>([]);
    const [noticeDismissed, setNoticeDismissed] = useState(false);
    const [safetyDismissed, setSafetyDismissed] = useState(false);
    const [publishNotice, setPublishNotice] = useState<'success' | 'error' | null>(null);
    const [simulatorOpen, setSimulatorOpen] = useState(false);
    const [highlightedNode, setHighlightedNode] = useState<string | null>(null);
    const [libraryCollapsed, setLibraryCollapsed] = useState(false);
    const [canvasLocked, setCanvasLocked] = useState(false);
    const [starterPickerDismissed, setStarterPickerDismissed] = useState(false);
    const didFitView = useRef(false); // reset when definition loads

    const focusNode = useCallback(
        (nodeId: string | null) => {
            if (!nodeId) return;
            setSelectedNodeId(nodeId);
            const node = getNode(nodeId);
            if (node) {
                setCenter(node.position.x + 140, node.position.y + 48, {
                    zoom: 1.05,
                    duration: FIT_DURATION,
                });
            }
        },
        [getNode, setCenter],
    );

    const history = useRef<{ past: FlowDefinition[]; future: FlowDefinition[] }>({ past: [], future: [] });
    const saveTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const loaded = useRef(false);
    const clipboard = useRef<Node | null>(null);

    const query = useQuery({
        queryKey: ['automation', workspaceId, id],
        queryFn: async () => (await automationsApi.get(workspaceId, id)).data,
    });

    // Load definition once.
    useEffect(() => {
        if (query.data && !loaded.current) {
            loaded.current = true;
            const rf = toReactFlow(query.data.definition ?? { nodes: [], edges: [] }, null, branchLabels);
            setNodes(rf.nodes);
            setEdges(rf.edges);
            setStarterPickerDismissed(rf.nodes.length > 0);
            didFitView.current = false;
        }
    }, [query.data, branchLabels]);

    // Smooth fit after nodes land (default ReactFlow fitView jumps without animation).
    useEffect(() => {
        if (!loaded.current || didFitView.current || nodes.length === 0) return;
        didFitView.current = true;
        const timer = window.setTimeout(() => {
            fitView({ duration: FIT_DURATION, padding: 0.22, maxZoom: 1.1 });
        }, 60);
        return () => window.clearTimeout(timer);
    }, [nodes, fitView]);

    // Reflect simulator highlight on the canvas.
    const highlightNode = useCallback(
        (nodeId: string | null) => {
            setHighlightedNode(nodeId);
            setNodes((prev) => prev.map((n) => ({ ...n, data: { ...n.data, highlighted: n.id === nodeId } })));
            if (nodeId) {
                const node = getNode(nodeId);
                if (node) {
                    setCenter(node.position.x + 140, node.position.y + 48, {
                        zoom: 1.05,
                        duration: FIT_DURATION,
                    });
                }
            }
        },
        [getNode, setCenter],
    );

    const save = useMutation({
        mutationFn: (definition: FlowDefinition) => automationsApi.saveDraft(workspaceId, id, definition),
        onSuccess: () => setSaveState('saved'),
        onError: () => setSaveState('dirty'),
    });

    const scheduleSave = useCallback(
        (nextNodes: Node[], nextEdges: Edge[]) => {
            setSaveState('dirty');
            setValidState('unknown');
            clearTimeout(saveTimer.current);
            saveTimer.current = setTimeout(() => {
                setSaveState('saving');
                save.mutate(toDefinition(nextNodes, nextEdges));
            }, 800);
        },
        [save],
    );

    const pushHistory = useCallback(() => {
        history.current.past.push(toDefinition(nodes, edges));
        if (history.current.past.length > 50) history.current.past.shift();
        history.current.future = [];
    }, [nodes, edges]);

    const applyDefinition = useCallback(
        (definition: FlowDefinition) => {
            const rf = toReactFlow(definition, highlightedNode, branchLabels);
            setNodes(rf.nodes);
            setEdges(rf.edges);
            setStarterPickerDismissed(rf.nodes.length > 0);
            scheduleSave(rf.nodes, rf.edges);
        },
        [scheduleSave, highlightedNode, branchLabels],
    );

    const undo = useCallback(() => {
        const past = history.current.past.pop();
        if (!past) return;
        history.current.future.push(toDefinition(nodes, edges));
        applyDefinition(past);
    }, [nodes, edges, applyDefinition]);

    const redo = useCallback(() => {
        const future = history.current.future.pop();
        if (!future) return;
        history.current.past.push(toDefinition(nodes, edges));
        applyDefinition(future);
    }, [nodes, edges, applyDefinition]);

    const onNodesChange = useCallback(
        (changes: NodeChange[]) => {
            setNodes((prev) => {
                const next = applyNodeChanges(changes, prev);
                if (changes.some((c) => c.type === 'position' || c.type === 'remove')) {
                    scheduleSave(next, edges);
                }
                return next;
            });
        },
        [edges, scheduleSave],
    );

    const onEdgesChange = useCallback(
        (changes: EdgeChange[]) => {
            setEdges((prev) => {
                const next = applyEdgeChanges(changes, prev);
                if (changes.some((c) => c.type === 'remove')) scheduleSave(nodes, next);
                return next;
            });
        },
        [nodes, scheduleSave],
    );

    const onConnect = useCallback(
        (connection: Connection) => {
            pushHistory();
            const sourceNode = nodes.find((n) => n.id === connection.source);
            setEdges((prev) => {
                // One edge per source handle: replace existing.
                const filtered = prev.filter(
                    (e) => !(e.source === connection.source && (e.sourceHandle ?? 'next') === (connection.sourceHandle ?? 'next')),
                );
                const added = addEdge(connection, filtered);
                const next = added.map((e) =>
                    e.source === connection.source && (e.sourceHandle ?? null) === (connection.sourceHandle ?? null) && e.target === connection.target
                        ? decorateEdge(
                              e,
                              sourceNode?.data.nodeType as string,
                              sourceNode?.data.config as Record<string, any>,
                              branchLabels,
                          )
                        : e,
                );
                scheduleSave(nodes, next);
                return next;
            });
        },
        [nodes, pushHistory, scheduleSave, branchLabels],
    );

    const starterConfig = useCallback(
        (type: string) => {
            const meta = NODE_META[type];
            const config = structuredClone(meta?.defaults ?? {});

            if (type === 'send_list') {
                config.button = t('automations.list_button_placeholder');
                config.sections = [
                    {
                        title: `${t('automations.section')} 1`,
                        rows: [
                            { id: 'row_1', title: `${t('automations.option')} 1`, description: '' },
                            { id: 'row_2', title: `${t('automations.option')} 2`, description: '' },
                        ],
                    },
                ];
            }
            if (type === 'send_buttons') {
                config.buttons = [{ id: 'btn_1', title: `${t('automations.option')} 1` }];
            }

            return config;
        },
        [t],
    );

    const addNode = useCallback(
        (type: string, position?: { x: number; y: number }) => {
            pushHistory();
            const config = starterConfig(type);
            const node: Node = {
                id: freshNodeId(),
                type: 'flowNode',
                position: position ?? { x: 120 + Math.random() * 80, y: 120 + Math.random() * 80 },
                data: { nodeType: type, config },
            };
            setNodes((prev) => {
                const next = [...prev, node];
                scheduleSave(next, edges);
                return next;
            });
            setStarterPickerDismissed(true);
            setSelectedNodeId(node.id);
        },
        [edges, pushHistory, scheduleSave, starterConfig],
    );

    const insertNodeOnEdge = useCallback(
        (edgeId: string, type: string) => {
            const currentEdge = edges.find((edge) => edge.id === edgeId);
            if (!currentEdge) return;

            const sourceNode = nodes.find((node) => node.id === currentEdge.source);
            const targetNode = nodes.find((node) => node.id === currentEdge.target);
            if (!sourceNode || !targetNode) return;

            const config = starterConfig(type);
            const hasNext = nodeHandles(type, config).some((handle) => handle.id === 'next');
            if (!hasNext) return;

            pushHistory();
            const inserted: Node = {
                id: freshNodeId(),
                type: 'flowNode',
                position: {
                    x: Math.round((sourceNode.position.x + targetNode.position.x) / 2),
                    y: Math.round((sourceNode.position.y + targetNode.position.y) / 2),
                },
                data: { nodeType: type, config },
            };

            const first = decorateEdge(
                {
                    id: freshEdgeId(),
                    source: currentEdge.source,
                    sourceHandle: currentEdge.sourceHandle ?? undefined,
                    target: inserted.id,
                },
                sourceNode.data.nodeType as string,
                sourceNode.data.config as Record<string, any>,
                branchLabels,
            );
            const second = decorateEdge(
                {
                    id: freshEdgeId(),
                    source: inserted.id,
                    sourceHandle: 'next',
                    target: currentEdge.target,
                },
                type,
                config,
                branchLabels,
            );

            const nextNodes = [...nodes, inserted];
            const nextEdges = [...edges.filter((edge) => edge.id !== edgeId), first, second];
            setNodes(nextNodes);
            setEdges(nextEdges);
            setSelectedNodeId(inserted.id);
            setStarterPickerDismissed(true);
            scheduleSave(nextNodes, nextEdges);
        },
        [edges, nodes, starterConfig, pushHistory, branchLabels, scheduleSave],
    );

    const onDrop = useCallback(
        (event: React.DragEvent) => {
            event.preventDefault();
            const type = event.dataTransfer.getData('application/x-node-type');
            if (!type) return;
            addNode(type, screenToFlowPosition({ x: event.clientX, y: event.clientY }));
        },
        [addNode, screenToFlowPosition],
    );

    const updateNodeConfig = useCallback(
        (nodeId: string, config: Record<string, any>) => {
            setNodes((prev) => {
                const next = prev.map((n) => (n.id === nodeId ? { ...n, data: { ...n.data, config } } : n));
                scheduleSave(next, edges);
                return next;
            });
        },
        [edges, scheduleSave],
    );

    const deleteNode = useCallback(
        (nodeId: string) => {
            pushHistory();
            setNodes((prev) => {
                const nextNodes = prev.filter((n) => n.id !== nodeId);
                setEdges((prevEdges) => {
                    const nextEdges = prevEdges.filter((e) => e.source !== nodeId && e.target !== nodeId);
                    scheduleSave(nextNodes, nextEdges);
                    return nextEdges;
                });
                return nextNodes;
            });
            setSelectedNodeId((current) => (current === nodeId ? null : current));
        },
        [pushHistory, scheduleSave],
    );

    const duplicateNode = useCallback(
        (nodeId: string) => {
            const node = nodes.find((n) => n.id === nodeId);
            if (!node || (node.data.nodeType as string).startsWith('trigger_')) return;
            pushHistory();
            const copy: Node = {
                ...node,
                id: freshNodeId(),
                position: { x: node.position.x + 40, y: node.position.y + 40 },
                data: {
                    nodeType: node.data.nodeType,
                    config: structuredClone(node.data.config),
                    highlighted: false,
                },
                selected: false,
            };
            setNodes((prev) => {
                const next = [...prev, copy];
                scheduleSave(next, edges);
                return next;
            });
            setSelectedNodeId(copy.id);
        },
        [nodes, edges, pushHistory, scheduleSave],
    );

    const applyStarterRecipe = useCallback(
        (key: StarterRecipeKey) => {
            pushHistory();
            const definition = createStarterRecipe(key, locale);
            const rf = toReactFlow(definition, null, branchLabels);
            setNodes(rf.nodes);
            setEdges(rf.edges);
            setStarterPickerDismissed(true);
            setSelectedNodeId(rf.nodes[0]?.id ?? null);
            didFitView.current = false;
            scheduleSave(rf.nodes, rf.edges);
        },
        [pushHistory, locale, branchLabels, scheduleSave],
    );

    const deleteSelected = useCallback(() => {
        if (!selectedNodeId) return;
        deleteNode(selectedNodeId);
    }, [selectedNodeId, deleteNode]);

    const duplicateSelected = useCallback(() => {
        if (!selectedNodeId) return;
        duplicateNode(selectedNodeId);
    }, [selectedNodeId, duplicateNode]);

    const nodeActionsRef = useRef({
        updateNodeConfig,
        deleteNode,
        duplicateNode,
        openSettings: (nodeId: string) => setSelectedNodeId(nodeId),
    });
    nodeActionsRef.current = {
        updateNodeConfig,
        deleteNode,
        duplicateNode,
        openSettings: (nodeId: string) => setSelectedNodeId(nodeId),
    };

    /** Inject per-node actions without storing functions in persisted draft state. */
    const displayNodes = useMemo(
        () =>
            nodes.map((node) => ({
                ...node,
                data: {
                    ...node.data,
                    onConfigChange: (config: Record<string, any>) => nodeActionsRef.current.updateNodeConfig(node.id, config),
                    onDelete: () => nodeActionsRef.current.deleteNode(node.id),
                    onDuplicate: () => nodeActionsRef.current.duplicateNode(node.id),
                    onOpenSettings: () => nodeActionsRef.current.openSettings(node.id),
                },
            })),
        [nodes],
    );

    const displayEdges = useMemo(
        () =>
            edges.map((edge) => ({
                ...edge,
                data: {
                    ...(edge.data ?? {}),
                    onQuickAdd: (type: string) => insertNodeOnEdge(edge.id, type),
                },
            })),
        [edges, insertNodeOnEdge],
    );

    const whatsappRisks = useMemo(() => detectWhatsAppWindowRisks(nodes, edges), [nodes, edges]);
    const riskSignature = whatsappRisks.map((risk) => `${risk.nodeId}:${risk.reason}`).join('|');
    const lastRiskSignature = useRef('');

    useEffect(() => {
        if (riskSignature !== lastRiskSignature.current) {
            lastRiskSignature.current = riskSignature;
            setSafetyDismissed(false);
        }
    }, [riskSignature]);

    // Keyboard shortcuts: undo/redo/copy/paste/duplicate.
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement;
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;

            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                undo();
            } else if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
                e.preventDefault();
                redo();
            } else if ((e.ctrlKey || e.metaKey) && e.key === 'c' && selectedNodeId) {
                clipboard.current = nodes.find((n) => n.id === selectedNodeId) ?? null;
            } else if ((e.ctrlKey || e.metaKey) && e.key === 'v' && clipboard.current) {
                e.preventDefault();
                const source = clipboard.current;
                addNode(source.data.nodeType as string, { x: source.position.x + 60, y: source.position.y + 60 });
            } else if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                e.preventDefault();
                duplicateSelected();
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [undo, redo, selectedNodeId, nodes, addNode, duplicateSelected]);

    const validate = useMutation({
        mutationFn: async () => {
            clearTimeout(saveTimer.current);
            await automationsApi.saveDraft(workspaceId, id, toDefinition(nodes, edges));
            setSaveState('saved');
            return automationsApi.validate(workspaceId, id);
        },
        onSuccess: (response) => {
            setNoticeDismissed(false);
            setPublishNotice(null);
            setValidationErrors(response.data.errors);
            setValidState(response.data.valid ? 'valid' : 'unknown');
        },
    });

    const publish = useMutation({
        mutationFn: async () => {
            // Flush pending draft first.
            clearTimeout(saveTimer.current);
            await automationsApi.saveDraft(workspaceId, id, toDefinition(nodes, edges));
            setSaveState('saved');
            return automationsApi.publish(workspaceId, id);
        },
        onSuccess: () => {
            setValidationErrors([]);
            setValidState('valid');
            setNoticeDismissed(false);
            setPublishNotice('success');
            query.refetch();
        },
        onError: (error: any) => {
            setNoticeDismissed(false);
            setPublishNotice(null);
            setValidationErrors(error?.errors?.validation ?? [{ node_id: null, message: error.message }]);
        },
    });

    const selectedNode = useMemo(() => nodes.find((n) => n.id === selectedNodeId) ?? null, [nodes, selectedNodeId]);
    const automation = query.data?.automation;

    if (query.isLoading) return <Spinner className="mt-24" />;

    const savePill =
        saveState === 'saving'
            ? { label: t('automations.saving'), className: 'bg-amber-50 text-amber-700 ring-amber-200' }
            : saveState === 'dirty'
              ? { label: t('automations.unsaved'), className: 'bg-slate-100 text-slate-600 ring-slate-200' }
              : { label: t('automations.saved'), className: 'bg-brand-50 text-brand-700 ring-brand-200' };

    const showSafetyNotice =
        !publishNotice &&
        validationErrors.length === 0 &&
        validState !== 'valid' &&
        whatsappRisks.length > 0 &&
        !safetyDismissed;

    return (
        <div className="flex h-screen flex-col bg-slate-50">
            {/* Toolbar */}
            <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-4 py-2">
                <Link to="/automations" className="rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900">
                    <ArrowLeft size={18} className="rtl:rotate-180" />
                </Link>
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-900">{automation?.name}</p>
                    <div className="flex items-center gap-2 text-[11px] text-slate-500">
                        <Badge color={statusColor(automation?.status ?? 'draft')}>
                            {statusLabel(automation?.status ?? 'draft')}
                        </Badge>
                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${savePill.className}`}>
                            {savePill.label}
                        </span>
                    </div>
                </div>
                <div className="flex-1" />
                <Link
                    to={`/automations/${id}/runs`}
                    className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100"
                >
                    <History size={15} /> {t('automations.runs')}
                </Link>
                <Button variant="ghost" size="sm" onClick={undo} title="Undo (Ctrl+Z)">
                    <Undo2 size={16} />
                </Button>
                <Button variant="ghost" size="sm" onClick={redo} title="Redo (Ctrl+Y)">
                    <Redo2 size={16} />
                </Button>
                <Button variant="secondary" size="sm" onClick={() => validate.mutate()} disabled={validate.isPending}>
                    <CheckCircle2 size={14} className={validState === 'valid' ? 'text-brand-600' : ''} /> {t('automations.check')}
                </Button>
                <Button variant="secondary" size="sm" onClick={() => setSimulatorOpen(true)}>
                    <Play size={14} /> {t('automations.test')}
                </Button>
                <Button size="sm" onClick={() => publish.mutate()} disabled={publish.isPending}>
                    <Rocket size={14} /> {t('automations.publish')}
                </Button>
            </div>

            <div className="flex min-h-0 flex-1">
                <NodeLibrary
                    onAdd={addNode}
                    collapsed={libraryCollapsed}
                    onToggleCollapsed={() => setLibraryCollapsed((value) => !value)}
                />

                <div className="relative min-w-0 flex-1" onDrop={onDrop} onDragOver={(e) => e.preventDefault()}>
                    {(!noticeDismissed || showSafetyNotice) && (
                        <div className="pointer-events-none absolute inset-x-0 top-3 z-20 flex justify-center px-3">
                            {publishNotice === 'success' && !noticeDismissed && (
                                <BuilderNotice
                                    tone="success"
                                    title={t('automations.published_toast')}
                                    autoHideMs={5200}
                                    onClose={() => {
                                        setPublishNotice(null);
                                        setNoticeDismissed(true);
                                    }}
                                />
                            )}

                            {!publishNotice && validationErrors.length > 0 && !noticeDismissed && (
                                <BuilderNotice
                                    tone="error"
                                    title={t('automations.cannot_publish')}
                                    onClose={() => setNoticeDismissed(true)}
                                >
                                    <ul className="space-y-1.5">
                                        {validationErrors.map((error, i) => (
                                            <li
                                                key={i}
                                                className="flex items-start justify-between gap-2 rounded-xl bg-red-50/80 px-2.5 py-1.5"
                                            >
                                                <span className="min-w-0 flex-1 text-red-800">{error.message}</span>
                                                {error.node_id && (
                                                    <button
                                                        type="button"
                                                        className="shrink-0 rounded-lg bg-white px-2 py-0.5 text-[11px] font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50"
                                                        onClick={() => focusNode(error.node_id)}
                                                    >
                                                        {t('automations.show_node')}
                                                    </button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </BuilderNotice>
                            )}

                            {!publishNotice &&
                                validationErrors.length === 0 &&
                                validState === 'valid' &&
                                !noticeDismissed && (
                                    <BuilderNotice
                                        tone="success"
                                        title={t('automations.valid')}
                                        autoHideMs={4800}
                                        onClose={() => setNoticeDismissed(true)}
                                    />
                                )}

                            {showSafetyNotice && (
                                <BuilderNotice
                                    tone="warning"
                                    title={
                                        locale === 'ar'
                                            ? 'مراجعة نافذة واتساب 24 ساعة'
                                            : 'WhatsApp 24-hour window check'
                                    }
                                    onClose={() => setSafetyDismissed(true)}
                                >
                                    <div className="space-y-2">
                                        <p>
                                            {locale === 'ar'
                                                ? `في ${whatsappRisks.length} رسالة عادية ممكن تتبعت بعد انتهاء نافذة خدمة العميل. استخدم WhatsApp Template معتمدة لو الإرسال ممكن يحصل بعد 24 ساعة.`
                                                : `${whatsappRisks.length} free-form message(s) may run after the customer service window closes. Use an approved WhatsApp template when the send can happen after 24 hours.`}
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => focusNode(whatsappRisks[0]?.nodeId ?? null)}
                                            className="rounded-lg bg-amber-50 px-2.5 py-1 text-[11px] font-bold text-amber-800 ring-1 ring-amber-200 hover:bg-amber-100"
                                        >
                                            {locale === 'ar' ? 'اعرض أول رسالة تحتاج مراجعة' : 'Show first message to review'}
                                        </button>
                                    </div>
                                </BuilderNotice>
                            )}
                        </div>
                    )}

                    {nodes.length === 0 && !starterPickerDismissed && (
                        <div className="absolute inset-0 z-10 flex items-center justify-center bg-slate-50/35 px-5 backdrop-blur-[1px]">
                            <StarterRecipePicker
                                onSelect={applyStarterRecipe}
                                onBlank={() => addNode('trigger_incoming_message', { x: 220, y: 120 })}
                            />
                        </div>
                    )}

                    {nodes.length === 0 && starterPickerDismissed && (
                        <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
                            <div className="rounded-2xl border-2 border-dashed border-slate-300 bg-white/80 px-8 py-6 text-center">
                                <p className="text-sm font-semibold text-slate-700">{t('automations.start_building')}</p>
                                <p className="mt-1 max-w-xs text-xs text-slate-500">{t('automations.start_hint')}</p>
                            </div>
                        </div>
                    )}
                    <ReactFlow
                        nodes={displayNodes}
                        edges={displayEdges}
                        nodeTypes={nodeTypes}
                        edgeTypes={edgeTypes}
                        onNodesChange={onNodesChange}
                        onEdgesChange={onEdgesChange}
                        onConnect={onConnect}
                        onNodeClick={(_, node) => setSelectedNodeId(node.id)}
                        onNodeDragStart={pushHistory}
                        onPaneClick={() => setSelectedNodeId(null)}
                        deleteKeyCode={['Delete', 'Backspace']}
                        onBeforeDelete={async () => {
                            pushHistory();
                            return true;
                        }}
                        snapToGrid
                        snapGrid={[16, 16]}
                        minZoom={0.2}
                        maxZoom={2}
                        zoomOnScroll
                        zoomOnPinch
                        zoomOnDoubleClick={false}
                        panOnScroll={false}
                        proOptions={{ hideAttribution: true }}
                        nodesDraggable={!canvasLocked}
                        nodesConnectable={!canvasLocked}
                        elementsSelectable={!canvasLocked}
                        selectNodesOnDrag={false}
                    >
                        <Background gap={16} />
                        <MiniMap pannable zoomable className="!h-28 !w-40 !rounded-xl !border !border-slate-200 !shadow-sm" />
                        <CanvasControls locked={canvasLocked} onToggleLock={() => setCanvasLocked((v) => !v)} />
                    </ReactFlow>
                </div>

                {selectedNode && (
                    <NodeSettingsPanel
                        key={selectedNode.id}
                        node={selectedNode}
                        allNodes={nodes}
                        onChange={(config) => updateNodeConfig(selectedNode.id, config)}
                        onDelete={deleteSelected}
                        onDuplicate={duplicateSelected}
                        onClose={() => setSelectedNodeId(null)}
                    />
                )}
            </div>

            <SimulatorDrawer
                open={simulatorOpen}
                onClose={() => {
                    setSimulatorOpen(false);
                    highlightNode(null);
                }}
                automationId={id}
                onNodeHighlight={highlightNode}
            />
        </div>
    );
}

export default function AutomationBuilder() {
    return (
        <ReactFlowProvider>
            <BuilderInner />
        </ReactFlowProvider>
    );
}
