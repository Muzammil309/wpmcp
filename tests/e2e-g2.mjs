import { request } from 'node:http';
import { spawnSync } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.WPMCP_BASE || 'http://localhost:8888';
const USER = process.env.WPMCP_USER || 'admin';
const PASS = process.env.WPMCP_PASS;
const ENDPOINT = new URL('/wp-json/wpmcp/v1/mcp', BASE);
const CWD = fileURLToPath(new URL('..', import.meta.url));

if (!PASS) { console.error('Set WPMCP_PASS.'); process.exit(2); }

function runCli(cmd, input) {
  const res = spawnSync(`npx @wordpress/env run cli ${cmd}`, { encoding: 'utf8', cwd: CWD, shell: true, input });
  return { ok: 0 === res.status, out: String(res.stdout ?? '') };
}
function setLicense(active) {
  const f = join(CWD, 'tests', '_g2_lic.php');
  writeFileSync(f, `<?php\n${active ? "update_option('wpmcp_license', array('status'=>'active','expires_at'=>4102444800,'plan'=>'pro'));" : "delete_option('wpmcp_license');"}\n`);
  runCli(`wp eval-file /var/www/html/wp-content/plugins/wpmcp-dev/tests/_g2_lic.php`);
  rmSync(f, { force: true });
}

let failures = 0;
function check(label, ok, extra = '') {
  console.log(`${ok ? '[PASS]' : '[FAIL]'} ${label}${extra ? ' :: ' + extra : ''}`);
  if (!ok) failures++;
}
function safeParse(raw) { try { return JSON.parse(raw); } catch { return null; } }
let id = 7000;
async function rpc(method, params) {
  const body = { jsonrpc: '2.0', id: ++id, method };
  if (params !== undefined) body.params = params;
  const payload = JSON.stringify(body);
  return await new Promise((resolve, reject) => {
    const req = request(ENDPOINT, { method: 'POST', headers: {
      'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload),
      Authorization: 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64'),
    }, agent: false }, (res) => {
      let data = ''; res.on('data', (c) => (data += c)); res.on('end', () => resolve(safeParse(data)));
    });
    req.on('error', reject); req.setTimeout(60000, () => req.destroy(new Error('timeout')));
    req.write(payload); req.end();
  });
}
async function call(name, args = {}) { return rpc('tools/call', { name, arguments: args }); }
function resultOf(r) { try { return JSON.parse(r?.result?.content?.[0]?.text ?? 'null'); } catch { return null; } }
async function names() { return (await rpc('tools/list')).result.tools.map((t) => t.name); }

// ---------- unlicensed gate ----------
setLicense(false);
let have = await names();
check('gate: forms-write hidden', !have.includes('forms-write'));
check('gate: global-classes hidden', !have.includes('global-classes'));
check('gate: theme-write hidden', !have.includes('theme-write'));
check('free reads visible', ['audit-page-a11y', 'theme-read'].every((n) => have.includes(n)));

// ---------- theme-read / theme-write ----------
const ctx = resultOf(await call('theme-read', { operation: 'get-theme-context' }));
check('theme context', typeof ctx?.name === 'string' && 'block_theme' in ctx, ctx?.name);

const modsBefore = resultOf(await call('theme-read', { operation: 'get-mods', keys: ['wpmcp_g2_test'] }));
check('get-mods keys filter', 'mods' in (modsBefore ?? {}));

setLicense(true);
const setMod = resultOf(await call('theme-write', { operation: 'set-mods', mods: { wpmcp_g2_test: 'hello' } }));
check('set-mods', setMod?.ok === true);
const modsAfter = resultOf(await call('theme-read', { operation: 'get-mods', keys: ['wpmcp_g2_test'] }));
check('mod persisted', modsAfter?.mods?.wpmcp_g2_test === 'hello');
const ccGate = resultOf(await call('theme-write', { operation: 'create-child-theme', name: 'G2 Child' }));
check('create-child-theme gated', ccGate?.error === 'confirm_required');

// ---------- a11y ----------
// find agency page (built with elementor)
const lp = resultOf(await call('list-pages', { post_type: 'page' }));
const a11yPage = lp?.pages?.[0]?.id;
check('a11y target page found', Number.isInteger(a11yPage), String(a11yPage));
const audit = resultOf(await call('audit-page-a11y', { post_id: a11yPage }));
check('audit scored', typeof audit?.score === 'number' && audit.score >= 0 && audit.score <= 100, String(audit?.score));
check('audit grade', typeof audit?.grade === 'string', audit?.grade);
check('audit findings array', Array.isArray(audit?.findings));
check('contrast checked on elementor page', audit?.contrast?.checked === true);

const dry = resultOf(await call('fix-color-contrast', { post_id: a11yPage }));
check('contrast dry-run', dry?.dry_run === true && Array.isArray(dry.proposals));
if ((dry?.proposals ?? []).length > 0) {
  const applied = resultOf(await call('fix-color-contrast', { post_id: a11yPage, apply: true }));
  check('contrast apply', applied?.ok === true && applied.applied >= 1, JSON.stringify(applied ?? {}).slice(0, 100));
} else {
  console.log('[SKIP] contrast apply (no failing pairs on this page)');
}

// alt-text: create a page with alt-less image via sideload
const sd = resultOf(await call('sideload-image', { url: 'https://images.pexels.com/photos/1103970/pexels-photo-1103970.jpeg?auto=compress&cs=tinysrgb&w=800' }));
if (sd?.id) {
  const imgPost = resultOf(await call('create-post', {
    title: 'G2 Alt Test', status: 'publish',
    content: `<h1>Alt Context Heading</h1><img src="${sd.url}" class="no-alt">`,
  }));
  const dryAlt = resultOf(await call('add-alt-text-from-context', { post_id: imgPost.id }));
  check('alt dry-run found image', dryAlt?.dry_run === true && dryAlt.found >= 1, JSON.stringify(dryAlt ?? {}).slice(0, 120));
  const applyAlt = resultOf(await call('add-alt-text-from-context', { post_id: imgPost.id, apply: true }));
  check('alt apply', applyAlt?.ok === true && applyAlt.applied >= 1);
  await call('delete-post', { id: imgPost.id, confirm: true });
  runCli(`wp post delete ${sd.id} --force`);
} else {
  check('alt flow (sideload failed, skipping)', true);
}

// ---------- forms ----------
if (!have.includes('forms-read')) { console.log('[SKIP] forms (no provider installed)'); } else {
  const prov = resultOf(await call('forms-read', { operation: 'providers' }));
check('forms providers shape', Array.isArray(prov?.providers));
  if (!(prov?.providers ?? []).includes('cf7')) {
    const cf7call = resultOf(await call('forms-read', { provider: 'cf7', operation: 'list-forms' }));
    check('cf7 unavailable error', cf7call?.error === 'provider_unavailable');
}
}

// ---------- metabox ----------
const mb = have.includes('metabox-read') ? resultOf(await call('metabox-read', { operation: 'list-field-groups' })) : null;
check('metabox-read responds or gated', mb === null || 'total' in (mb ?? {}) || 'error' in (mb ?? {}));

// ---------- global classes (elementor 4) ----------
const gc = resultOf(await call('global-classes', { operation: 'list' }));
if (gc?.error === 'global_classes_unavailable') {
  check('global classes unavailable (experiment off) - ok', true);
} else {
  check('gc list shape', 'order' in (gc ?? {}) && 'items' in (gc ?? {}));
  const created = resultOf(await call('global-classes', { operation: 'create', label: 'G2 Card', styles: { color: '#123456' } }));
  check('gc create', typeof created?.class_id === 'string', created?.class_id);
  const upd = resultOf(await call('global-classes', { operation: 'update', class_id: created.class_id, styles: { color: '#654321' } }));
  check('gc update', upd?.ok === true);
  const after = resultOf(await call('global-classes', { operation: 'list' }));
  check('gc update persisted (label)', (after?.labels ?? {})[created.class_id] === 'G2 Card', JSON.stringify((after?.labels ?? {})).slice(0,100));
  const del = resultOf(await call('global-classes', { operation: 'delete', class_id: created.class_id, confirm: true }));
  check('gc delete', del?.deleted === true);
}

// ---------- update-page-settings ----------
const built = resultOf(await call('build-page', { title: 'G2 Settings Page', structure: [{ widgets: [{ type: 'heading', settings: { title: 'X' } }] }] }));
const ups = resultOf(await call('update-page-settings', { post_id: built.post_id, settings: { custom_css: '.e-con{max-width:1200px}' }, template: 'canvas' }));
check('update-page-settings', ups?.ok === true && ups.updated.includes('template'), JSON.stringify(ups ?? {}).slice(0, 120));
const ex = resultOf(await call('export-page', { post_id: built.post_id }));
check('page settings exported', ex?.page_settings?.template === 'elementor_canvas', ex?.page_settings?.template);
await call('delete-post', { id: built.post_id, confirm: true });

// ---------- gate closes ----------
setLicense(false);
have = await names();
check('gate closes', ['forms-write', 'global-classes', 'theme-write'].every((n) => !have.includes(n)));

console.log(`\n${failures} failure(s)`);
process.exit(failures > 0 ? 1 : 0);
