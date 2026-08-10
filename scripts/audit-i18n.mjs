import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const jsRoot = path.join(root, 'resources', 'js');
const localeFiles = {
    en: path.join(jsRoot, 'locales', 'en.ts'),
    ar: path.join(jsRoot, 'locales', 'ar.ts'),
};

const read = (file) => fs.readFileSync(file, 'utf8');
const keyPattern = /^\s*['"]([^'"]+)['"]\s*:/gm;
const usedKeyPattern = /\bt\(\s*['"]([^'"]+)['"]/g;

function extractKeys(source) {
    return new Set([...source.matchAll(keyPattern)].map((match) => match[1]));
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

const enSource = read(localeFiles.en);
const arSource = read(localeFiles.ar);
const enKeys = extractKeys(enSource);
const arKeys = extractKeys(arSource);
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

// Keep this list deliberately focused on user-facing UI surfaces. It catches
// untranslated English literals without flagging code identifiers, API values,
// CSS class names, example payloads, or customer-generated content.
const uiFiles = files.filter((file) =>
    /resources[\\/]js[\\/](?:pages|components)[\\/]/.test(file),
);

const englishWords = /\b(?:Add|Back|Cancel|Choose|Close|Confirm|Connect|Create|Delete|Disconnect|Edit|Email|Error|Field|General|Import|Invite|Loading|Member|Members|Message|Name|No|Notification|Notifications|Password|Plan|Plans|Profile|Quality|Refresh|Remove|Retry|Role|Save|Search|Settings|Status|Sync|Tag|Tags|Team|Teams|Template|Templates|Update|User|WhatsApp|Workspace)\b/;
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
        if (line.includes("t('") || line.includes('t("')) return;
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
    console.error(`i18n audit failed with ${problems.length} issue(s):`);
    for (const problem of [...new Set(problems)]) console.error(`- ${problem}`);
    process.exit(1);
}

console.log(`i18n audit passed: ${enKeys.size} English keys, ${arKeys.size} Arabic keys, ${files.length} frontend source files checked.`);
