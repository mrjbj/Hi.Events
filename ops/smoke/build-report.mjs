#!/usr/bin/env node
// Build a self-contained HTML smoke-test report from a manifest + screenshots.
//
// Usage:
//   node ops/smoke/build-report.mjs --manifest <manifest.json> --out <report.html>
//
// Manifest shape (screenshot paths are relative to the manifest's directory):
//   {
//     "title": "...",
//     "subtitle": "...",
//     "meta": { "App URL": "...", "Branch": "...", ... },
//     "steps": [
//       { "title": "...", "description": "...", "status": "pass|fail|info",
//         "screenshot": "shots/01.png", "notes": "optional" }
//     ]
//   }
//
// Screenshots are embedded as base64 data URIs, so the output HTML is a single
// portable file you can open or email without the image files.

import {readFileSync, writeFileSync, existsSync} from 'node:fs';
import {dirname, resolve, extname} from 'node:path';

function arg(name, fallback = null) {
    const i = process.argv.indexOf(name);
    return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

const manifestPath = arg('--manifest');
const outPath = arg('--out');
if (!manifestPath || !outPath) {
    console.error('Usage: node build-report.mjs --manifest <manifest.json> --out <report.html>');
    process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const baseDir = dirname(resolve(manifestPath));

const mime = (p) => ({'.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp'}[extname(p).toLowerCase()] || 'image/png');

const dataUri = (relPath) => {
    const abs = resolve(baseDir, relPath);
    if (!existsSync(abs)) return null;
    return `data:${mime(abs)};base64,${readFileSync(abs).toString('base64')}`;
};

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

const steps = manifest.steps || [];
const counts = steps.reduce((a, s) => (a[s.status] = (a[s.status] || 0) + 1, a), {});
const overall = (counts.fail > 0) ? 'fail' : 'pass';
const generatedAt = new Date().toISOString();

const badge = (status) => {
    const colors = {pass: '#15803d', fail: '#b91c1c', info: '#1d4ed8'};
    const labels = {pass: 'PASS', fail: 'FAIL', info: 'INFO'};
    return `<span class="badge" style="background:${colors[status] || '#6b7280'}">${labels[status] || esc(status).toUpperCase()}</span>`;
};

const metaRows = Object.entries(manifest.meta || {})
    .map(([k, v]) => `<div class="metarow"><span class="metakey">${esc(k)}</span><span class="metaval">${esc(v)}</span></div>`).join('');

const stepCards = steps.map((s, i) => {
    const uri = s.screenshot ? dataUri(s.screenshot) : null;
    const img = uri
        ? `<a href="${uri}" target="_blank" rel="noopener"><img src="${uri}" alt="${esc(s.title)}"></a>`
        : `<div class="noimg">no screenshot</div>`;
    return `
    <section class="step">
      <header class="stephead">
        <span class="num">${i + 1}</span>
        <h2>${esc(s.title)}</h2>
        ${badge(s.status)}
      </header>
      ${s.description ? `<p class="desc">${esc(s.description)}</p>` : ''}
      <div class="shot">${img}</div>
      ${s.notes ? `<p class="notes">${esc(s.notes)}</p>` : ''}
    </section>`;
}).join('\n');

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(manifest.title || 'Smoke Test Report')}</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #111; background: #f6f7f9; }
  .wrap { max-width: 1000px; margin: 0 auto; padding: 24px; }
  header.top { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; }
  h1 { margin: 0 0 4px; font-size: 22px; }
  .subtitle { color: #6b7280; margin: 0 0 14px; }
  .summary { display: flex; align-items: center; gap: 10px; margin: 10px 0 4px; }
  .summary .overall { font-weight: 700; font-size: 16px; }
  .counts { color: #6b7280; font-size: 13px; }
  .meta { margin-top: 14px; display: grid; grid-template-columns: max-content 1fr; gap: 2px 16px; font-size: 13px; }
  .metarow { display: contents; }
  .metakey { color: #6b7280; }
  .metaval { color: #111; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
  .badge { color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .04em; padding: 3px 8px; border-radius: 999px; }
  section.step { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
  .stephead { display: flex; align-items: center; gap: 12px; }
  .stephead h2 { font-size: 16px; margin: 0; flex: 1; }
  .num { width: 26px; height: 26px; border-radius: 50%; background: #111; color: #fff; display: grid; place-items: center; font-size: 13px; font-weight: 700; }
  .desc { color: #374151; margin: 10px 0 12px; }
  .shot img { width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; display: block; }
  .noimg { padding: 40px; text-align: center; color: #9ca3af; border: 1px dashed #d1d5db; border-radius: 8px; }
  .notes { margin: 12px 0 0; padding: 10px 12px; background: #f0f9ff; border-left: 3px solid #38bdf8; border-radius: 4px; font-size: 13px; color: #0c4a6e; }
  footer { color: #9ca3af; font-size: 12px; text-align: center; padding: 16px; }
  @media (prefers-color-scheme: dark) {
    body { background: #0b0d10; color: #e5e7eb; }
    header.top, section.step { background: #15181d; border-color: #2a2f37; }
    h1, .stephead h2, .metaval { color: #e5e7eb; }
    .desc { color: #cbd5e1; }
    .num { background: #e5e7eb; color: #15181d; }
    .notes { background: #0c2a3a; color: #bae6fd; }
  }
</style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <h1>${esc(manifest.title || 'Smoke Test Report')}</h1>
    ${manifest.subtitle ? `<p class="subtitle">${esc(manifest.subtitle)}</p>` : ''}
    <div class="summary">
      ${badge(overall)}
      <span class="overall">${overall === 'pass' ? 'All steps passed' : 'Failures present'}</span>
      <span class="counts">${steps.length} steps · ${counts.pass || 0} pass · ${counts.fail || 0} fail · ${counts.info || 0} info</span>
    </div>
    <div class="meta">${metaRows}</div>
  </header>
  ${stepCards}
  <footer>Generated ${esc(generatedAt)} · self-contained (screenshots embedded)</footer>
</div>
</body>
</html>`;

writeFileSync(outPath, html);
console.log(`Wrote ${outPath} (${steps.length} steps, ${(html.length / 1024).toFixed(0)} KB, overall: ${overall})`);
