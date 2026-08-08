import { describe, expect, it } from 'vitest';
import type { Edge, Node } from '@xyflow/react';
import { detectWhatsAppWindowRisks } from './flowSafety';

function node(id: string, type: string, config: Record<string, any> = {}): Node {
    return { id, type: 'flowNode', position: { x: 0, y: 0 }, data: { nodeType: type, config } };
}

function edge(id: string, source: string, target: string): Edge {
    return { id, source, target };
}

describe('WhatsApp flow service-window safety', () => {
    it('flags free-form sends after 24 hours', () => {
        const nodes = [
            node('trigger', 'trigger_incoming_message'),
            node('delay', 'delay', { amount: 2, unit: 'days' }),
            node('message', 'send_text', { text: 'Follow up' }),
        ];
        const edges = [edge('e1', 'trigger', 'delay'), edge('e2', 'delay', 'message')];

        expect(detectWhatsAppWindowRisks(nodes, edges)).toEqual([
            expect.objectContaining({ nodeId: 'message', reason: 'elapsed_24h' }),
        ]);
    });

    it('does not flag an approved template after 24 hours', () => {
        const nodes = [
            node('trigger', 'trigger_incoming_message'),
            node('delay', 'delay', { amount: 2, unit: 'days' }),
            node('template', 'send_template', { template_id: 1 }),
        ];
        const edges = [edge('e1', 'trigger', 'delay'), edge('e2', 'delay', 'template')];

        expect(detectWhatsAppWindowRisks(nodes, edges)).toEqual([]);
    });

    it('resets the window after a customer answer', () => {
        const nodes = [
            node('trigger', 'trigger_incoming_message'),
            node('question', 'ask_question', { question: 'Reply?' }),
            node('delay', 'delay', { amount: 23, unit: 'hours' }),
            node('message', 'send_text', { text: 'Thanks' }),
        ];
        const edges = [
            edge('e1', 'trigger', 'question'),
            edge('e2', 'question', 'delay'),
            edge('e3', 'delay', 'message'),
        ];

        expect(detectWhatsAppWindowRisks(nodes, edges)).toEqual([]);
    });

    it('treats wait-until as a possible service-window crossing', () => {
        const nodes = [
            node('trigger', 'trigger_incoming_message'),
            node('wait', 'wait_until', { mode: 'tomorrow', time: '09:00' }),
            node('message', 'send_image', { url: 'https://example.test/a.jpg' }),
        ];
        const edges = [edge('e1', 'trigger', 'wait'), edge('e2', 'wait', 'message')];

        expect(detectWhatsAppWindowRisks(nodes, edges)).toEqual([
            expect.objectContaining({ nodeId: 'message', reason: 'scheduled_wait' }),
        ]);
    });
});
