/**
 * Runtime contract between PHP and the SPA.
 *
 * Injected by `AdminSuite\Core\Enqueue::enqueue()` as
 * `window.ADMIN_SUITE_BOOTSTRAP` right before the entry module executes.
 */
/**
 * Which admin area the SPA was opened from.
 *
 * The suite is reachable from all three, and each puts it at a different path,
 * so the router base and the REST calls cannot assume `/wp-admin/`.
 * Mirrors `AdminSuite\Core\AdminContext`.
 */
export type AdminArea = 'site' | 'network' | 'user'

export interface Bootstrap {
  readonly restUrl: string
  readonly nonce: string
  /** Admin area base URL, already trailing-slashed. */
  readonly homeUrl: string
  readonly admin: AdminArea
  /** Absolute URL of the suite screen itself, used as the router base. */
  readonly suiteUrl: string
  readonly canManage: boolean
  readonly canEdit: boolean
  readonly canUpload: boolean
  readonly siteName: string
  /**
   * Locale of the current administrator, underscored the way WordPress stores
   * it (`it_IT`). `utils/i18n.ts` converts it to the BCP 47 form `Intl` wants.
   */
  readonly locale: string
  readonly pluginVer: string
}

declare global {
  interface Window {
    ADMIN_SUITE_BOOTSTRAP?: Bootstrap
  }
}

export {}
