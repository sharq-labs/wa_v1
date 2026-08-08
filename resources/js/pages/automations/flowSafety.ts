import type { Edge, Node } from '@xyflow/react';

const SERVICE_WINDOW_SECONDS = 24 * 60 * 60;

const NON_TEMPLATE_OUTBOUND = new Set([
    'send_text',
    'send_image',
    'send_video',
    'send_audio',
    'send_document',
    'send_buttons',
    'send_list',
    'ask_question',
]);

const CUSTOMER_RESPONSE_NODES = new Set(['ask_question', 'send_buttons', 'send_list']);

export type WhatsAppWindowRiskReason = 'elapsed_24h' | 'scheduled_wait';

export interface WhatsAppWindowRisk {
    nodeId: string;
    reason: WhatsAppWindowRiskReason;
    elapsedSeconds: number;
}

function delaySeconds(config: Record<string, any>): number {
    const amount = Math.max(0, Number(config.amount ?? 0));
    const unit = String(config.unit ?? 'minutes');

    switch (unit) {
        case 'seconds':
            return amount;
        case 'hours':
            return amount * 60 * 60;
        case 'days':
            return amount * 24 * 60 * 60;
        case 'minutes':
        default:
            return amount * 60;
    }
}

/**
 * Detects places where a free-form WhatsApp message can execute after the
 * 24-hour customer service window. The analysis is intentionally conservative:
 * wait-until nodes are treated as potentially crossing the window, while nodes
 * that require a customer response reset the timer for the path that follows.
 *
 * This is a builder-time safety hint, not a replacement for send-time provider
 * enforcement.
 */
export function detectWhatsAppWindowRisks(nodes: Node[], edges: Edge[]): WhatsAppWindowRisk[] {
    const byId = new Map(nodes.map((node) => [node.id, node]));
    const outgoing = new Map<string, Edge[]>();

    for (const edge of edges) {
        const list = outgoing.get(edge.source) ?? [];
        list.push(edge);
        outgoing.set(edge.source, list);
    }

    const risks = new Map<string, WhatsAppWindowRisk>();
    const triggers = nodes.filter((node) => String(node.data.nodeType ?? '').startsWith('trigger_'));

    const walk = (
        nodeId: string,
        elapsedSeconds: number,
        scheduledWait: boolean,
        path: Set<string>,
    ) => {
        const node = byId.get(nodeId);
        if (!node || path.has(nodeId)) return;

        const nextPath = new Set(path);
        nextPath.add(nodeId);

        const type = String(node.data.nodeType ?? '');
        const config = (node.data.config ?? {}) as Record<string, any>;
        let elapsed = elapsedSeconds;
        let uncertain = scheduledWait;

        if (type.startsWith('trigger_')) {
            elapsed = 0;
            uncertain = false;
        }

        if (NON_TEMPLATE_OUTBOUND.has(type) && (uncertain || elapsed >= SERVICE_WINDOW_SECONDS)) {
            const candidate: WhatsAppWindowRisk = {
                nodeId,
                reason: uncertain ? 'scheduled_wait' : 'elapsed_24h',
                elapsedSeconds: elapsed,
            };
            const existing = risks.get(nodeId);

            if (!existing || candidate.reason === 'scheduled_wait' || candidate.elapsedSeconds > existing.elapsedSeconds) {
                risks.set(nodeId, candidate);
            }
        }

        if (type === 'delay') {
            elapsed = Math.min(SERVICE_WINDOW_SECONDS, elapsed + delaySeconds(config));
        } else if (type === 'wait_until') {
            uncertain = true;
        }

        // Buttons, lists and questions only continue once the customer replies,
        // which re-opens the 24-hour service window for subsequent free-form sends.
        if (CUSTOMER_RESPONSE_NODES.has(type)) {
            elapsed = 0;
            uncertain = false;
        }

        for (const edge of outgoing.get(nodeId) ?? []) {
            walk(edge.target, elapsed, uncertain, nextPath);
        }
    };

    for (const trigger of triggers) {
        walk(trigger.id, 0, false, new Set());
    }

    return [...risks.values()];
}
