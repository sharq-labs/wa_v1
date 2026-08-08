import {
    BaseEdge,
    EdgeLabelRenderer,
    getSmoothStepPath,
    type EdgeProps,
} from '@xyflow/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import QuickAddMenu from './QuickAddMenu';

export interface QuickAddEdgeData extends Record<string, unknown> {
    edgeColor?: string;
    onQuickAdd?: (type: string) => void;
}

export default function QuickAddEdge({
    id,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    markerEnd,
    style,
    label,
    data,
}: EdgeProps) {
    const [open, setOpen] = useState(false);
    const edgeData = (data ?? {}) as QuickAddEdgeData;
    const [edgePath, labelX, labelY] = getSmoothStepPath({
        sourceX,
        sourceY,
        sourcePosition,
        targetX,
        targetY,
        targetPosition,
        borderRadius: 16,
    });

    return (
        <>
            <BaseEdge
                id={id}
                path={edgePath}
                markerEnd={markerEnd}
                style={style}
                interactionWidth={28}
            />
            <EdgeLabelRenderer>
                <div
                    className="nodrag nopan pointer-events-auto absolute z-10 flex -translate-x-1/2 -translate-y-1/2 flex-col items-center"
                    style={{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)` }}
                >
                    {label && (
                        <span
                            className="mb-1 max-w-[112px] truncate rounded-md bg-white/95 px-1.5 py-0.5 text-[10px] font-bold shadow-sm ring-1 ring-slate-200"
                            style={{ color: edgeData.edgeColor ?? '#64748b' }}
                        >
                            {String(label)}
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            setOpen((value) => !value);
                        }}
                        className="flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-md transition hover:scale-105 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                        aria-label="Add step"
                        title="Add step"
                    >
                        <Plus size={14} strokeWidth={2.5} />
                    </button>

                    {open && (
                        <div className="absolute start-1/2 top-9 z-50 -translate-x-1/2 rtl:translate-x-1/2">
                            <QuickAddMenu
                                mode="insert"
                                onClose={() => setOpen(false)}
                                onSelect={(type) => {
                                    edgeData.onQuickAdd?.(type);
                                    setOpen(false);
                                }}
                            />
                        </div>
                    )}
                </div>
            </EdgeLabelRenderer>
        </>
    );
}
