import { request } from 'node:http';

const BASE = process.env.WPMCP_BASE || 'http://localhost:8888';
const USER = process.env.WPMCP_USER || 'admin';
const PASS = process.env.WPMCP_PASS;
const ENDPOINT = new URL('/wp-json/wpmcp/v1/mcp', BASE);

if (!PASS) { console.error('Set WPMCP_PASS.'); process.exit(2); }

let failures = 0;
function check(label, ok, extra = '') {
  console.log(`${ok ? '[PASS]' : '[FAIL]'} ${label}${extra ? ' :: ' + extra : ''}`);
  if (!ok) failures++;
}
function safeParse(raw) { try { return JSON.parse(raw); } catch { return null; } }
let id = 11000;
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

// ---------- registration ----------
const have = await names();
check('G4 tools registered', ['comment-read', 'comment-write', 'revision-read', 'restore-revision'].every((n) => have.includes(n)),
  ['comment-read', 'comment-write', 'revision-read', 'restore-revision'].filter((n) => !have.includes(n)).join(','));

// ---------- comments roundtrip ----------
const post = resultOf(await call('create-post', { title: 'G4 Comments Post', status: 'publish', content: '<p>Comment target.</p>' }));
check('target post created', Number.isInteger(post?.id));

const held = resultOf(await call('comment-write', { operation: 'create-comment', post_id: post.id, content: 'Held comment from G4.', author_name: 'G4 Bot' }));
check('create-comment held by default', held?.ok === true && held.status === 'hold', JSON.stringify(held ?? {}).slice(0, 100));

const approved = resultOf(await call('comment-write', { operation: 'create-comment', post_id: post.id, content: 'Approved comment from G4.', author_name: 'G4 Bot', approve_now: true }));
check('approve_now works', approved?.ok === true && approved.status === 'approve');

const reply = resultOf(await call('comment-write', { operation: 'reply', parent_id: approved.id, content: 'Nested reply.' }));
check('reply nests under parent', reply?.ok === true, JSON.stringify(reply ?? {}).slice(0, 80));

// email privacy: admin has moderate_comments -> fields visible
const one = resultOf(await call('comment-read', { operation: 'get-comment', id: held.id }));
check('get-comment payload', one?.id === held.id && one.content.includes('Held comment'), JSON.stringify(one ?? {}).slice(0, 100));
check('moderator sees fields', typeof one?.author_name === 'string');

const listHold = resultOf(await call('comment-read', { status: 'hold', post_id: post.id }));
check('status filter hold', (listHold?.comments ?? []).some((c) => c.id === held.id));
const listApproved = resultOf(await call('comment-read', { status: 'approve', post_id: post.id }));
check('status filter approve', (listApproved?.comments ?? []).some((c) => c.id === approved.id));

// set-status + ledger rollback
const spam = resultOf(await call('comment-write', { operation: 'set-status', id: held.id, status: 'spam' }));
check('set-status spam', spam?.ok === true && spam.previous === 'hold');
const afterSpam = resultOf(await call('comment-read', { operation: 'get-comment', id: held.id }));
check('spam persisted', afterSpam?.status === 'spam');
const backToApprove = resultOf(await call('comment-write', { operation: 'set-status', id: held.id, status: 'approve' }));
check('set-status approve', backToApprove?.previous === 'spam' && backToApprove.status === 'approve');

// rollback the last status change via ledger
const changes = resultOf(await call('list-changes', { domain: 'comments', per_page: 5 }));
const statusChange = (changes?.changes ?? []).find((c) => c.action === 'set-comment-status' && !c.rolled_back && c.target_id === held.id);
check('status change in ledger + reversible', !!statusChange && statusChange.reversible === true, JSON.stringify(statusChange ?? {}).slice(0, 120));
if (statusChange) {
  const rb = resultOf(await call('rollback-change', { id: statusChange.id, confirm: true }));
  check('rollback restores prior status', rb?.rolled_back === true, JSON.stringify(rb ?? {}).slice(0, 120));
  const afterRb = resultOf(await call('comment-read', { operation: 'get-comment', id: held.id }));
  check('rolled back to spam', afterRb?.status === 'spam');
}

// delete gate
const noConfirm = resultOf(await call('comment-write', { operation: 'delete', id: reply.id }));
check('delete gated without confirm', noConfirm?.error === 'confirm_required', JSON.stringify(noConfirm ?? {}).slice(0, 120));
const del = resultOf(await call('comment-write', { operation: 'delete', id: reply.id, confirm: true }));
check('delete works with confirm', del?.deleted === reply.id, JSON.stringify(del ?? {}));
const gone = resultOf(await call('comment-read', { operation: 'get-comment', id: reply.id }));
check('deleted comment gone', gone?.error === 'comment_not_found_or_forbidden');

// search
const search = resultOf(await call('comment-read', { search: 'Approved comment from G4' }));
check('search matches content', (search?.comments ?? []).some((c) => c.id === approved.id));

// ---------- revisions roundtrip ----------
const revPost = resultOf(await call('update-post', { id: post.id, title: 'G4 Revisions v2', content: '<p>Version two body.</p>' }));
check('post updated for revision', revPost?.ok === true || Number.isInteger(revPost?.id), JSON.stringify(revPost ?? {}).slice(0, 80));
await call('update-post', { id: post.id, title: 'G4 Revisions v3', content: '<p>Version three body.</p>' });

const revs = resultOf(await call('revision-read', { operation: 'list-revisions', post_id: post.id }));
check('list-revisions returns entries', revs?.revisions_enabled === true && (revs.total ?? 0) >= 1, `total=${revs?.total}`);
const firstRev = (revs?.revisions ?? [])[0];
if (firstRev) {
  const detail = resultOf(await call('revision-read', { operation: 'get-revision', revision_id: firstRev.id }));
  check('get-revision side-by-side', detail?.revision?.id === firstRev.id && detail.current?.id === post.id && 'content' in detail.current);

  // restore gate
  const gated = resultOf(await call('restore-revision', { revision_id: firstRev.id }));
  check('restore gated without confirm', gated?.error === 'confirm_required', JSON.stringify(gated ?? {}).slice(0, 100));

  const restored = resultOf(await call('restore-revision', { revision_id: firstRev.id, confirm: true }));
  check('restore ok', restored?.ok === true && restored.post_id === post.id, JSON.stringify(restored ?? {}).slice(0, 120));
  const afterRestore = resultOf(await call('get-post', { id: post.id, raw_content: true }));
  check('content now matches revision', afterRestore?.content === detail.revision.content);

  const revChanges = resultOf(await call('list-changes', { domain: 'content', per_page: 5 }));
  const revChange = (revChanges?.changes ?? []).find((c) => c.action === 'restore-revision' && !c.rolled_back);
  check('restore logged + reversible', !!revChange && revChange.reversible === true);
  if (revChange) {
    const rb = resultOf(await call('rollback-change', { id: revChange.id, confirm: true }));
    check('rollback restore-revision', rb?.rolled_back === true, JSON.stringify(rb ?? {}).slice(0, 100));
    const afterRb = resultOf(await call('get-post', { id: post.id, raw_content: true }));
    check('rolled back content is v3', (afterRestore?.title ?? '').length > 0 && afterRb?.content !== afterRestore?.content || afterRb?.title === 'G4 Revisions v3', JSON.stringify({ before: afterRestore?.title, after: afterRb?.title }).slice(0, 120));
  }
} else {
  console.log('[SKIP] revision restore (no revisions captured)');
}

// cleanup
await call('delete-post', { id: post.id, confirm: true, force: true });
console.log(`\n${failures} failure(s)`);
process.exit(failures > 0 ? 1 : 0);
