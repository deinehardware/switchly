#!/usr/bin/env node

/**
 * Validates local Markdown targets and the bilingual document anchors used by
 * the repository. External URLs are intentionally left to dedicated link
 * checkers because CI may run without unrestricted network access.
 */
import fs from 'node:fs';
import path from 'node:path';

const rootFiles = ['README.md', 'SECURITY.md', 'CONTRIBUTING.md', 'CHANGELOG.md', 'THIRD_PARTY_NOTICES.md'];
const documentationFiles = fs.readdirSync('docs')
  .filter((name) => name.endsWith('.md'))
  .map((name) => path.join('docs', name));
const files = [...rootFiles, ...documentationFiles];
let failures = 0;

for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  const ids = [...source.matchAll(/<a id="([^"]+)"><\/a>/g)].map((match) => match[1]);

  for (const id of new Set(ids)) {
    if (ids.filter((candidate) => candidate === id).length > 1) {
      console.error(`Doppelter Anker in ${file}: #${id}`);
      failures += 1;
    }
  }

  if (!source.includes('<a id="deutsch"></a>') || !source.includes('<a id="english"></a>')) {
    console.error(`Sprachanker fehlt in ${file}.`);
    failures += 1;
  }

  for (const match of source.matchAll(/!?\[[^\]]*\]\(([^)]+)\)/g)) {
    const target = match[1].trim();
    if (!target || /^(?:https?:|mailto:|#)/.test(target)) continue;
    const localTarget = target.split('#')[0];
    if (!localTarget) continue;
    const resolved = path.resolve(path.dirname(file), localTarget);
    if (!fs.existsSync(resolved)) {
      console.error(`Fehlender Markdown-Verweis in ${file}: ${target}`);
      failures += 1;
    }
  }
}

if (failures > 0) process.exit(1);
console.log(`Markdown-Verweise geprüft: ${files.length} Dateien.`);
