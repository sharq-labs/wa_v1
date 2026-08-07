/**
 * Shared template preview logic used by the template mapper UI.
 */

export interface VariableMapping {
    index: number;
    source: 'static' | 'contact' | 'custom' | 'workspace' | 'agent';
    value: string;
}

export function extractVariableIndexes(body: string): number[] {
    const matches = [...body.matchAll(/\{\{(\d+)\}\}/g)];
    return [...new Set(matches.map((m) => Number(m[1])))].sort((a, b) => a - b);
}

export function renderTemplatePreview(body: string, mappings: VariableMapping[]): string {
    return body.replace(/\{\{(\d+)\}\}/g, (_, raw) => {
        const mapping = mappings.find((m) => m.index === Number(raw));
        if (!mapping || !mapping.value) return `{{${raw}}}`;
        if (mapping.source === 'static') return mapping.value;
        return `[${mapping.source}.${mapping.value}]`;
    });
}
