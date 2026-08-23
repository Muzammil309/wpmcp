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

function setLicense(active) {
  const f = join(CWD, 'tests', '_g3_lic.php');
  writeFileSync(f, `<?php\n${active ? "update_option('wpmcp_license', array('status'=>'active','expires_at'=>4102444800,'plan'=>'pro'));" : "delete_option('wpmcp_license');"}\n`);
  const r = spawnSync(`npx @wordpress/env run cli wp eval-file /var/www/html/wp-content/plugins/wpmcp-dev/tests/_g3_lic.php`, { encoding: 'utf8', cwd: CWD, shell: true });
  rmSync(f, { force: true });
}

let failures = 0;
function check(label, ok, extra = '') {
  console.log(`${ok ? '[PASS]' : '[FAIL]'} ${label}${extra ? ' :: ' + extra : ''}`);
  if (!ok) failures++;
}
function safeParse(raw) { try { return JSON.parse(raw); } catch { return null; } }
let id = 9000;
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
    req.on('error', () => resolve(null)); req.setTimeout(180000, () => req.destroy());
    req.write(payload); req.end();
  });
}
async function call(name, args = {}) { return rpc('tools/call', { name, arguments: args }); }
function resultOf(r) { try { return JSON.parse(r?.result?.content?.[0]?.text ?? 'null'); } catch { return null; } }
async function names() { return (await rpc('tools/list')).result.tools.map((t) => t.name); }

setLicense(true);
const have = await names();
check('G3 tools registered', ['memory-read', 'memory-write', 'brand-kits-list', 'brand-kit-apply', 'export-content', 'list-exports', 'restore-content'].every((n) => have.includes(n)), ['memory-read','memory-write','brand-kits-list','brand-kit-apply','export-content','list-exports','restore-content'].filter(n=>!have.includes(n)).join(','));

// memory roundtrip
const prop = resultOf(await call('memory-write', { operation: 'propose', type: 'convention', text: 'G2 test convention: always prefix custom classes with g2-' }));
check('memory propose', prop?.status === 'pending_approval', prop?.id);
const pendRead = resultOf(await call('memory-read', { operation: 'pending' }));
check('pending visible to admin', (pendRead?.pending ?? []).some((i) => i.id === prop.id));
const appr = resultOf(await call('memory-write', { operation: 'approve', id: prop.id }));
check('approve', appr?.ok === true);
const apprRead = resultOf(await call('memory-read', { operation: 'approved' }));
check('approved readable', (apprRead?.items ?? []).some((i) => i.id === prop.id));
const sesSave = resultOf(await call('memory-write', { operation: 'save-session', text: 'G2 session: built agency page + a11y fixes.' }));
check('save-session', sesSave?.saved === true);
const sesRead = resultOf(await call('memory-read', { operation: 'sessions' }));
check('sessions listed', (sesRead?.sessions ?? []).some((s) => s.summary.includes('G2 session')));
const forget = resultOf(await call('memory-write', { operation: 'forget', id: prop.id }));
check('forget', forget?.forgotten === true);

// brand kits
const kits = resultOf(await call('brand-kits-list', {}));
check('brand-kits-list', kits?.total >= 6);
const applyKit = resultOf(await call('brand-kit-apply', { kit: 'mono-slate' }));
check('apply kit', applyKit?.ok === true, applyKit?.kit);
// verify ledger snapshot exists
const changes = resultOf(await call('list-changes', { per_page: 10 }));
const kitChange = (changes?.changes ?? []).find((c) => c.action === 'apply-brand-kit');
check('kit apply logged + reversible', kitChange && kitChange.reversible === true);

// content export / restore
const title = 'G3 Export Source ' + Date.now();
const src = resultOf(await call('create-post', { title, status: 'publish', content: '<p>Export me.</p>' }));
const exp = resultOf(await call('export-content', { post_type: 'post', search: title }));
check('export-content', exp?.exported >= 1, JSON.stringify(exp ?? {}).slice(0, 100));
const listExp = resultOf(await call('list-exports', {}));
check('list-exports', (listExp?.files ?? []).length >= 1);
const file = ((exp.files ?? []).find((f) => f.id === src.id)?.file) ?? (listExp.files ?? [])[0]?.file;
if (file) {
  // delete source then restore
  await call('delete-post', { id: src.id, confirm: true, force: true });
  const res = resultOf(await call('restore-content', { file, confirm: true }));
  check('restore-content recreates', res?.ok === true && res.post_id > 0, JSON.stringify(res ?? {}));
  const got = resultOf(await call('get-post', { id: res.post_id }));
  check('restored content matches', got?.id === res.post_id && (got?.content ?? '').includes('Export me') && (got?.title ?? '').startsWith('G3 Export Source'), JSON.stringify(got ?? {}).slice(0,120));
  await call('delete-post', { id: res.post_id, confirm: true, force: true });
}

setLicense(false);
console.log(`\n${failures} failure(s)`);
process.exit(failures > 0 ? 1 : 0);
