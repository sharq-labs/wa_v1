import { describe, expect, it } from 'vitest';
import { NODE_CATALOG, NODE_META, nodeHandles } from './nodeCatalog';

describe('node catalog (flow node configuration)', () => {
    it('covers every core node type from the spec', () => {
        const types = NODE_CATALOG.map((n) => n.type);

        for (const required of [
            'trigger_incoming_message', 'trigger_keyword', 'trigger_new_contact',
            'send_text', 'send_image', 'send_video', 'send_audio', 'send_document',
            'send_template', 'send_buttons', 'send_list', 'ask_question',
            'set_custom_field', 'clear_custom_field', 'add_tag', 'remove_tag',
            'assign_agent', 'unassign_agent', 'pause_bot', 'resume_bot',
            'close_conversation', 'reopen_conversation', 'start_automation',
            'http_request', 'send_webhook', 'add_note',
            'condition', 'delay', 'wait_until', 'random_split', 'go_to_node', 'stop',
        ]) {
            expect(types, `missing node type ${required}`).toContain(required);
        }
    });

    it('gives condition nodes TRUE and FALSE branches', () => {
        const handles = nodeHandles('condition', {});
        expect(handles.map((h) => h.id)).toEqual(['true', 'false']);
    });

    it('derives one branch per configured button', () => {
        const handles = nodeHandles('send_buttons', {
            buttons: [
                { id: 'btn_web', title: 'Website' },
                { id: 'btn_app', title: 'Mobile App' },
            ],
        });

        expect(handles).toEqual([
            { id: 'btn_web', label: 'Website' },
            { id: 'btn_app', label: 'Mobile App' },
        ]);
    });

    it('gives HTTP nodes success and error branches', () => {
        expect(nodeHandles('http_request', {}).map((h) => h.id)).toEqual(['next', 'error']);
    });

    it('terminal nodes expose no outgoing handles', () => {
        expect(nodeHandles('stop', {})).toEqual([]);
        expect(nodeHandles('close_conversation', {})).toEqual([]);
    });

    it('ask_question defaults include a save destination placeholder and validation', () => {
        const defaults = NODE_META.ask_question.defaults;
        expect(defaults).toHaveProperty('save_to');
        expect(defaults).toHaveProperty('validation', 'text');
    });

    it('random split handles reflect configured weights', () => {
        const handles = nodeHandles('random_split', {
            branches: [
                { handle: 'a', weight: 70 },
                { handle: 'b', weight: 30 },
            ],
        });
        expect(handles[0].label).toContain('70');
        expect(handles[1].label).toContain('30');
    });
});
