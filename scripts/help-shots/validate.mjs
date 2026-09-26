// Cross-checks every Help guide against the captured pictures: each figure's shot exists and every mark names a target that
// was recorded for that shot. Run after scripts/help-shots/run.sh, and whenever a guide is edited:
//   node scripts/help-shots/validate.mjs           (add --prune to delete pictures no guide uses)
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/pages/help');
const ann = JSON.parse(fs.readFileSync(`${ROOT}/shots/annotations.json`, 'utf8'));
const files = fs.readdirSync(`${ROOT}/guides`).filter((f) => f.endsWith('.js') && f !== 'index.js');
const guides = files.map((f) => ({ file: f, g: new Function(fs.readFileSync(`${ROOT}/guides/${f}`, 'utf8').replace('export default', 'return'))() }));
const slugs = new Set(guides.map((x) => x.g.slug));
const index = fs.readFileSync(`${ROOT}/guides/index.js`, 'utf8');
let problems = 0;
const bad = (m) => { problems++; console.log('  PROBLEM:', m); };
const used = new Set();
for (const { file, g } of guides) {
  if (!index.includes(`from './${file.replace('.js', '')}'`)) bad(`${file} is not imported by index.js`);
  for (const n of g.next ?? []) if (!slugs.has(n)) bad(`${g.slug}: next "${n}" does not exist`);
  for (const r of g.roles) if (!['CASHIER', 'MANAGER', 'ADMIN'].includes(r)) bad(`${g.slug}: role ${r}`);
  g.steps.forEach((s, i) => {
    const where = `${g.slug} step ${i + 1} "${s.title}"`;
    for (const t of [s.title, s.body, s.tip, s.note, s.warning, ...(s.dos ?? []), ...(s.donts ?? [])]) {
      if (t && ((t.match(/\*\*/g) ?? []).length % 2 || (t.match(/`/g) ?? []).length % 2)) bad(`${where}: unbalanced markup in "${t.slice(0, 40)}"`);
    }
    if (!s.figure) return;
    const f = s.figure;
    used.add(f.shot);
    if (!ann[f.shot]) { bad(`${where}: shot ${f.shot} missing`); return; }
    if (!f.alt) bad(`${where}: no alt`);
    const actions = (f.marks ?? []).filter((m) => ['click', 'type'].includes(m.type)).length;
    if (!f.marks?.length) bad(`${where}: figure without marks`);
    for (const m of f.marks ?? []) {
      if (!['click', 'type', 'avoid', 'look'].includes(m.type)) bad(`${where}: mark type ${m.type}`);
      if (!ann[f.shot].t[m.target]) bad(`${where}: shot ${f.shot} has no target "${m.target}" (has: ${Object.keys(ann[f.shot].t).join(', ')})`);
      if (m.type === 'type' && !m.text) bad(`${where}: type mark without text`);
      if (!m.label) bad(`${where}: mark without label`);
    }
  });
}
const shotFiles = fs.readdirSync(`${ROOT}/shots`).filter((f) => f.endsWith('.webp')).map((f) => f.replace('.webp', ''));
console.log(`${guides.length} guides, ${guides.reduce((n, x) => n + x.g.steps.length, 0)} steps, ${used.size} shots used of ${shotFiles.length} captured, ${problems} problems`);
console.log('unused shots:', shotFiles.filter((s) => !used.has(s)).join(', ') || 'none');
console.log('annotations without file:', Object.keys(ann).filter((s) => !shotFiles.includes(s)).join(', ') || 'none');

if (process.argv.includes('--prune')) {
  const unused = shotFiles.filter((id) => !used.has(id));
  for (const id of unused) { fs.rmSync(`${ROOT}/shots/${id}.webp`); delete ann[id]; }
  for (const id of Object.keys(ann)) if (!used.has(id)) delete ann[id];
  fs.writeFileSync(`${ROOT}/shots/annotations.json`, JSON.stringify(ann, null, 1) + '\n');
  console.log(`pruned ${unused.length} unused pictures`);
}
process.exit(problems > 0 ? 1 : 0);
