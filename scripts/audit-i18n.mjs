import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const jsRoot = path.join(root, 'resources', 'js');
const localeFiles = {
    en: [
        path.join(jsRoot, 'locales', 'en.ts'),
        path.join(jsRoot, 'locales', 'en-extra.ts'),
        path.join(jsRoot, 'locales', 'en-automation-extra.ts'),
    ],
    ar: [
        path.join(jsRoot, 'locales', 'ar.ts'),
        path.join(jsRoot, 'locales', 'ar-extra.ts'),
        path.join(jsRoot, 'locales', 'ar-automation-extra.ts'),
    ],
};

const read = (file) => fs.readFileSync(file, 'utf8');
const keyPattern = /^\s*['"]([^'"]+)['"]\s*:/gm;
const usedKeyPattern = /\bt\(\s*['"]([^'"]+)['"]/g;

function extractKeys(sources) {
    return new Set(sources.flatMap((source) => [...source.matchAll(keyPattern)].map((match) => match[1])));
}

function walk(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return walk(full);
        if (!/\.(?:ts|tsx)$/.test(entry.name)) return [];
        if (full.includes(`${path.sep}locales${path.sep}`)) return [];
        return [full];
    });
}

const enKeys = extractKeys(localeFiles.en.map(read));
const arKeys = extractKeys(localeFiles.ar.map(read));
const files = walk(jsRoot);
const problems = [];

for (const key of enKeys) {
    if (!arKeys.has(key)) problems.push(`Arabic dictionary is missing key: ${key}`);
}
for (const key of arKeys) {
    if (!enKeys.has(key)) problems.push(`English dictionary is missing key: ${key}`);
}

for (const file of files) {
    const source = read(file);
    const rel = path.relative(root, file).replaceAll('\\', '/');
    for (const match of source.matchAll(usedKeyPattern)) {
        const key = match[1];
        if (!enKeys.has(key)) problems.push(`${rel}: used translation key missing from English dictionary: ${key}`);
        if (!arKeys.has(key)) problems.push(`${rel}: used translation key missing from Arabic dictionary: ${key}`);
    }
}

// Heuristic for user-facing literals. Technical identifiers and customer/API
// content are intentionally excluded; visible product copy should go through
// i18n or an explicit bilingual branch.
const uiFiles = files.filter((file) => /resources[\\/]js[\\/](?:pages|components)[\\/]/.test(file));
const englishWords = /\b(?:add|actions?|automation|back|body|cancel|choose|close|confirm|connect|create|custom|delete|disconnect|edit|email|error|field|general|import|invite|joined|keywords|loading|mapping|match|member|members|message|mode|name|no|notification|notifications|owner|password|plan|plans|profile|quality|received|refresh|remove|response|retry|role|save|search|settings|specific|status|strategy|sync|tag|tags|target|team|teams|template|templates|update|user|value|variable|whatsapp|workspace)\b/i;
const ignoredFragments = [
    'Meta Cloud API',
    'WhatsApp Business',
    'WhatsApp',
    'HTTP',
    'API',
    'CSV',
    'XLSX',
    'UTC',
];

function ignored(text) {
    const compact = text.trim();
    if (!compact) return true;
    if (/^[A-Z0-9_./:+#-]+$/.test(compact)) return true;
    if (/^[a-z0-9_.-]+$/.test(compact)) return true;
    if (ignoredFragments.includes(compact)) return true;
    if (compact.includes('{{') || compact.includes('}}')) return true;
    return false;
}

for (const file of uiFiles) {
    const source = read(file);
    const rel = path.relative(root, file).replaceAll('\\', '/');
    const lines = source.split(/\r?\n/);

    lines.forEach((line, index) => {
        if (line.includes("t('") || line.includes('t("') || line.includes('t(`')) return;
        if (line.includes("locale === 'ar'") || line.includes("ar ? '") || line.includes('ar ? "')) return;
        if (line.trim().startsWith('//') || line.trim().startsWith('*')) return;

        const candidates = [];
        for (const match of line.matchAll(/>([^<>{}][^<>{}]*)</g)) candidates.push(match[1]);
        for (const match of line.matchAll(/\b(?:title|subtitle|placeholder|aria-label|description|confirmLabel)=['"]([^'"]+)['"]/g)) candidates.push(match[1]);
        for (const match of line.matchAll(/\btoast\.(?:success|error|warning|info)\(\s*['"]([^'"]+)['"]/g)) candidates.push(match[1]);
        for (const match of line.matchAll(/\bnew Error\(\s*['"]([^'"]+)['"]/g)) candidates.push(match[1]);

        for (const candidate of candidates) {
            const text = candidate.trim();
            if (ignored(text) || !englishWords.test(text)) continue;
            problems.push(`${rel}:${index + 1}: possible untranslated UI text: ${JSON.stringify(text)}`);
        }
    });
}

if (problems.length) {
    const uniqueProblems = [...new Set(problems)];
    console.error(`i18n audit failed with ${uniqueProblems.length} issue(s):`);
    for (const problem of uniqueProblems) console.error(`- ${problem}`);
    process.exit(1);
}

console.log(`i18n audit passed: ${enKeys.size} English keys, ${arKeys.size} Arabic keys, ${files.length} frontend source files checked.`);
