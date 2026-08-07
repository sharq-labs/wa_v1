import { describe, expect, it } from 'vitest';
import { extractVariableIndexes, renderTemplatePreview } from './templatePreview';

describe('template variable mapper', () => {
    const body = 'Hello {{1}}, we are following up regarding {{2}}.';

    it('extracts unique sorted variable indexes', () => {
        expect(extractVariableIndexes(body)).toEqual([1, 2]);
        expect(extractVariableIndexes('No vars here')).toEqual([]);
        expect(extractVariableIndexes('{{2}} then {{1}} then {{1}}')).toEqual([1, 2]);
    });

    it('renders static mappings directly', () => {
        const preview = renderTemplatePreview(body, [
            { index: 1, source: 'static', value: 'Ahmed' },
            { index: 2, source: 'static', value: 'Website' },
        ]);

        expect(preview).toBe('Hello Ahmed, we are following up regarding Website.');
    });

    it('renders field mappings as labelled placeholders', () => {
        const preview = renderTemplatePreview(body, [
            { index: 1, source: 'contact', value: 'first_name' },
            { index: 2, source: 'custom', value: 'service' },
        ]);

        expect(preview).toBe('Hello [contact.first_name], we are following up regarding [custom.service].');
    });

    it('keeps unmapped variables visible', () => {
        expect(renderTemplatePreview(body, [])).toBe(body);
    });
});
