import type { Bootstrap } from '@/types/bootstrap'
import type {
  DashboardResponse,
  MenuResponse,
  NativeWidgetResponse,
  SearchResponse,
  SuiteSettings,
  UserPreferences,
  WidgetLayoutEntry,
} from '@/types/api'

/**
 * Thrown for any non-2xx REST response. Carries the WordPress error code so
 * callers can branch on 401/403 (`rest_cookie_invalid_nonce`) distinctly.
 */
export class RestError extends Error {
  readonly code: string
  readonly status: number

  constructor(code: string, message: string, status: number) {
    super(message)
    this.name = 'RestError'
    this.code = code
    this.status = status
  }

  /** True when the request failed because the nonce expired or is wrong. */
  get isAuthError(): boolean {
    return this.status === 401 || this.status === 403
  }
}

function bootstrap(): Bootstrap {
  const data = window.ADMIN_SUITE_BOOTSTRAP

  if (!data) {
    throw new RestError(
      'missing_bootstrap',
      'window.ADMIN_SUITE_BOOTSTRAP is not set. The PHP bootstrap script did not run — check that the plugin is active and the manifest was built.',
      500,
    )
  }

  return data
}

/** The nonce is the only mutable part, so it is fetched per request. */
function headers(json: boolean): Headers {
  const { nonce } = bootstrap()
  const h = new Headers({ 'X-WP-Nonce': nonce })

  if (json) h.set('Content-Type', 'application/json')

  return h
}

async function request<T>(path: string, init: RequestInit = {}, json = true): Promise<T> {
  const { restUrl } = bootstrap()
  const response = await fetch(`${restUrl}${path}`, {
    credentials: 'same-origin',
    ...init,
    headers: headers(json),
  })

  const payload: unknown = await response.json().catch(() => null)

  if (!response.ok) {
    const err = (payload ?? {}) as { code?: string; message?: string }
    throw new RestError(
      err.code ?? 'unknown_error',
      err.message ?? `Request failed with status ${response.status}`,
      response.status,
    )
  }

  return payload as T
}

/**
 * `exactOptionalPropertyTypes` forbids passing `signal: undefined`, so the key
 * is only present when TanStack Query actually supplies an AbortSignal.
 */
const withSignal = (signal?: AbortSignal): RequestInit => (signal ? { signal } : {})

/**
 * The admin area this page was served from, as a ready-to-use query fragment.
 *
 * PHP cannot infer it during a REST request: the `WP_NETWORK_ADMIN` and
 * `WP_USER_ADMIN` constants are only defined by `wp-admin/admin.php`, and
 * `is_network_admin()` / `is_user_admin()` read the current screen first, which
 * a REST request does not have. So the area travels with the request instead,
 * read from the same bootstrap the router base comes from. Absent bootstrap
 * means an empty fragment, and the server falls back to the site admin.
 */
const adminParam = (): string | undefined => {
  const admin = window.ADMIN_SUITE_BOOTSTRAP?.admin

  return admin ? `admin=${encodeURIComponent(admin)}` : undefined
}

/**
 * Join query fragments onto a path, omitting the `?` when there are none.
 */
const withQuery = (path: string, ...params: Array<string | undefined>): string => {
  const search = params.filter(Boolean).join('&')

  return search ? `${path}?${search}` : path
}

export const rest = {
  menu: (context: 'view' | 'edit' = 'view', signal?: AbortSignal) =>
    request<MenuResponse>(
      withQuery('menu', `context=${context}`, adminParam()),
      withSignal(signal),
    ),

  search: (q: string, signal?: AbortSignal) =>
    request<SearchResponse>(`search?q=${encodeURIComponent(q)}`, withSignal(signal)),

  getSettings: (signal?: AbortSignal) => request<SuiteSettings>('settings', withSignal(signal)),

  saveSettings: (patch: Partial<SuiteSettings>) =>
    request<SuiteSettings>('settings', {
      method: 'POST',
      body: JSON.stringify(patch),
    }),

  getPreferences: (signal?: AbortSignal) =>
    request<UserPreferences>('user-preferences', withSignal(signal)),

  savePreferences: (patch: Partial<UserPreferences>) =>
    request<UserPreferences>('user-preferences', {
      method: 'POST',
      body: JSON.stringify(patch),
    }),

  /**
   * The whole dashboard in one round trip: site info, counters, recent posts,
   * activity, the panel inventory and the saved layout.
   */
  dashboard: (signal?: AbortSignal) =>
    request<DashboardResponse>(withQuery('dashboard', adminParam()), withSignal(signal)),

  /**
   * The rendered HTML of one native widget. SPA panels have no server-side
   * markup and answer 409 `admin_suite_widget_is_builtin`.
   */
  nativeWidget: (id: string, signal?: AbortSignal) =>
    request<NativeWidgetResponse>(
      withQuery(`dashboard/widget/${encodeURIComponent(id)}`, adminParam()),
      withSignal(signal),
    ),

  /**
   * Persist panel order and visibility. The response is the layout the server
   * reconciled, which is authoritative: unknown ids are dropped and newly
   * registered widgets are appended.
   */
  saveDashboardLayout: (layout: WidgetLayoutEntry[]) =>
    request<{ layout: WidgetLayoutEntry[] }>('dashboard', {
      method: 'POST',
      body: JSON.stringify({ layout }),
    }),
}
