/**
 * Boot smoke test: does the built bundle actually mount into
 * #dashboard-widgets-wrap?
 *
 * That is the element `wp-admin/index.php` prints around the core dashboard
 * widgets, and the one the suite takes over. The failure this exists to catch is
 * silent: a classic <script> tag pointed at an ESM bundle throws a SyntaxError
 * before a single line runs, so the page renders an empty div and the REST
 * endpoints still answer 200. Nothing else in the suite notices.
 *
 * Runs the real `dist/` output under Node against a linkedom DOM, then asserts
 * the mount point gained children.
 */

import * as linkedom from 'linkedom'
import { parseHTML } from 'linkedom'

const { window, document } = parseHTML(
  `<!doctype html><html><head></head><body><div id="dashboard-widgets-wrap"></div></body></html>`,
) as unknown as { window: Record<string, unknown>; document: Document }

const href = 'http://localhost:8080/wp-admin/index.php#/dashboard'

// linkedom does not synthesise `location` for a document parsed from a string,
// and vue-router reads `location.host` while building the history base. This
// mirrors what wp-admin actually serves: a query string, a hash route, and no
// <base> tag.
const location = {
  href,
  host: 'localhost:8080',
  hostname: 'localhost',
  port: '8080',
  protocol: 'http:',
  origin: 'http://localhost:8080',
  pathname: '/wp-admin/admin.php',
  search: '?page=admin-suite',
  hash: '#/dashboard',
  assign: () => undefined,
  replace: () => undefined,
  reload: () => undefined,
  toString: () => href,
}

// Vue reads `document` off the global scope, so the DOM has to be installed
// before the bundle is imported. Node 24 defines some of these (notably
// `navigator`) as getter-only accessors, so plain assignment throws.
const g = globalThis as unknown as Record<string, unknown>
const define = (key: string, value: unknown): void => {
  Object.defineProperty(g, key, { value, writable: true, configurable: true })
}

define('window', window)
define('document', document)
define('navigator', window.navigator)
define('location', location)
window.location = location
window.history = {
  state: null,
  length: 1,
  scrollRestoration: 'auto',
  pushState: () => undefined,
  replaceState: () => undefined,
  go: () => undefined,
  back: () => undefined,
  forward: () => undefined,
}
define('history', window.history)

// Vue's runtime-dom reaches for DOM constructors as bare globals
// (`SVGElement`, `Node`, `Text`, `MutationObserver`, …). Copy every constructor
// linkedom exports rather than guessing which ones a given Vue version touches.
for (const [name, value] of Object.entries(linkedom)) {
  if (typeof value === 'function' && /^[A-Z]/.test(name)) {
    define(name, value)
  }
}

define('requestAnimationFrame', (cb: (t: number) => void): number => setTimeout(() => cb(0), 0) as unknown as number)
define('cancelAnimationFrame', (id: number): void => clearTimeout(id))

// The plugin injects this via `wp_add_inline_script( ..., 'before' )`. Without
// it `main.ts` throws on purpose, which is a legitimate failure to surface.
//
// `locale` is deliberately the underscored form WordPress actually stores
// (`determine_locale()`), not the BCP 47 form `Intl` accepts. `Intl` throws a
// RangeError on `it_IT`, so if the SPA hands the bootstrap value straight to a
// formatter the bundle dies at import — on every Italian install, and with a
// stack trace pointing at `Intl` rather than at the conversion that should have
// happened. This stub is the only thing between that regression and a dashboard
// nobody can open in their own language.
window.ADMIN_SUITE_BOOTSTRAP = {
  restUrl: 'http://localhost:8080/wp-json/admin-suite/v1/',
  nonce: 'smoke',
  homeUrl: 'http://localhost:8080/wp-admin/',
  admin: 'site',
  suiteUrl: 'http://localhost:8080/wp-admin/index.php',
  canManage: true,
  canEdit: true,
  canUpload: true,
  siteName: 'WordPress',
  locale: 'it_IT',
  pluginVer: '0.1.0',
}

/**
 * Stand-in for the catalogue `wp-i18n` provides, which `Enqueue` declares as a
 * dependency and `wp_set_script_translations()` fills in.
 *
 * Installed here so the boot test exercises the same global a browser has. The
 * assertion that matters is further down: drop `wp-i18n` from `SCRIPT_DEPS` and
 * the wrapper degrades to English *and says so*, and that warning is what turns
 * a silent regression into a red test.
 */
let i18nWarnings = 0
const realWarn = console.warn.bind(console)
console.warn = (...args: unknown[]): void => {
  i18nWarnings += 1
  realWarn(...args)
}
window.wp = {
  i18n: {
    __: (text: string): string => text,
    _x: (text: string): string => text,
    _n: (single: string, plural: string, count: number): string => (count === 1 ? single : plural),
    _nx: (single: string, plural: string, count: number): string => (count === 1 ? single : plural),
    isRTL: (): boolean => false,
  },
}

/**
 * Minimal but *shape-correct* `/dashboard` payload.
 *
 * Field names must match `DashboardController::getDashboard()`. If that shape
 * changes this stub goes stale and the view throws on an undefined property —
 * which is the intended failure mode: better a red smoke test than a silently
 * broken grid.
 */
const dashboardPayload = {
  site: {
    name: 'WordPress',
    url: 'http://localhost:8080/',
    adminUrl: 'http://localhost:8080/wp-admin/',
    language: 'en-US',
    charset: 'UTF-8',
    timezone: '+00:00',
    version: '7.1.2',
    php: '8.5.4',
  },
  counts: {
    posts: 1,
    postsDraft: 0,
    pages: 2,
    media: 0,
    users: 1,
    commentsPending: 0,
  },
  recentPosts: [
    {
      id: 1,
      title: 'Hello world!',
      status: 'publish',
      author: 'admin',
      date: '2026-09-25T19:58:21+00:00',
      url: 'http://localhost:8080/wp-admin/post.php?post=1&action=edit',
      thumb: '',
    },
  ],
  activity: [],
  widgets: [
    { id: 'suite-stats', title: 'Site overview', context: 'suite', span: 3 },
    { id: 'suite-recent-posts', title: 'Recent posts', context: 'suite', span: 1 },
  ],
  layout: [
    { id: 'suite-stats', visible: true },
    { id: 'suite-recent-posts', visible: true },
  ],
}

// The shell renders before any query resolves; stub fetch so an unreachable
// REST root surfaces as a query error instead of an unhandled rejection, and so
// the dashboard view gets data instead of an error state.
let fetchCalls = 0
define('fetch', async (input: unknown) => {
  fetchCalls += 1
  const url = String(input)
  const body = url.includes('/dashboard') ? dashboardPayload : { items: [], counts: { topLevel: 0 } }

  return {
    ok: true,
    status: 200,
    json: async () => body,
    headers: new Map(),
  } as unknown as Response
})

// Keep a handle on Node's `process` before it is removed below, so the harness
// itself can still report and exit.
const nodeProcess = process

const errors: string[] = []
const onError = (event: unknown) => errors.push(String(event))
nodeProcess.on('unhandledRejection', onError)

// Vue reports a failed render via console.error, not via a rejected promise,
// and still leaves a half-painted tree behind. Treat "Unhandled error during
// execution of" as a hard failure so a broken header cannot pass as a boot.
const consoleErrors: string[] = []
const realError = console.error.bind(console)
console.error = (...args: unknown[]) => {
  consoleErrors.push(args.map(String).join(' '))
  realError(...args)
}

/*
 * A `type="module"` script is deferred by definition, so in a browser the
 * document is always fully parsed by the time the bundle evaluates and
 * `document.readyState` is never 'loading'. linkedom does not synthesise the
 * property, which would leave the bundle waiting on a DOMContentLoaded that
 * never arrives. Pin it, so the harness exercises the path a browser takes.
 */
Object.defineProperty(document, 'readyState', { value: 'complete', configurable: true })

const root = document.getElementById('dashboard-widgets-wrap')
if (!root) throw new Error('harness setup failed: no #dashboard-widgets-wrap')

// Node exposes `process`, browsers do not. Vue's ESM build branches on
// `process.env.NODE_ENV`, and Vite does not inline that constant in library
// mode, so a bundle can ship a live `process` reference and still boot here
// while throwing `ReferenceError: process is not defined` in every real
// browser — a permanently white page that nothing else in the suite notices.
// Deleting the global immediately before the app is imported makes this harness
// behave like a browser and turns that whole class of bug red.
delete (globalThis as { process?: unknown }).process

// A module-evaluation throw (an undefined global, a bad import) surfaces as a
// rejected dynamic import. Without this the test dies with a raw stack trace
// and a non-zero exit, which is red but says nothing about the cause.
try {
  await import('../dist/admin-suite.js')
} catch (cause) {
  console.error(`  FAIL: the bundle threw while loading — ${String(cause)}`)
  nodeProcess.exit(1)
}

// Let the initial render and the microtask queue drain.
await new Promise((resolve) => setTimeout(resolve, 50))

const html = root.innerHTML
const children = root.childElementCount
const renderFailures = consoleErrors.filter((line) => /Unhandled error during execution of/.test(line))

console.log(`  child elements : ${children}`)
console.log(`  innerHTML len  : ${html.length}`)
console.log(`  fetch calls    : ${fetchCalls}`)
console.log(`  render failures: ${renderFailures.length}`)
console.log(`  i18n warnings  : ${i18nWarnings}`)

if (children === 0 || html.length < 50) {
  console.error('  FAIL: mount point is empty — the app did not mount')
  nodeProcess.exit(1)
}

if (errors.length > 0) {
  console.error(`  unhandled rejections: ${errors.join(' | ')}`)
  nodeProcess.exit(1)
}

if (renderFailures.length > 0) {
  console.error('  FAIL: a component threw during render (see output above)')
  nodeProcess.exit(1)
}

if (i18nWarnings > 0) {
  console.error('  FAIL: the i18n wrapper fell back to English — window.wp.i18n never arrived')
  nodeProcess.exit(1)
}

console.log('  boot smoke OK')

// Explicit exit: the mounted app leaves timers pending (TanStack Query retry
// backoff, router guards), so Node would otherwise never drain the event loop.
nodeProcess.exit(0)
