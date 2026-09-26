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

/**
 * One entry in core's admin bar "New" menu.
 *
 * `url` is an absolute admin URL. The list is whatever
 * `wp_admin_bar_new_content_menu()` produced for the current user, in core's
 * order, behind core's own capability checks. Mirrors `Enqueue::newContentMenu()`.
 */
export interface NewContentItem {
  readonly id: string
  readonly label: string
  readonly url: string
}

/**
 * Core's admin bar "New" menu.
 *
 * Both fields are empty when the menu does not exist at all, which happens in
 * two entirely legitimate ways: WordPress only registers it outside the network
 * and user admins, and it emits nothing at all for a user who cannot create
 * anything. An empty list means the button is not shown, not that it is broken.
 */
export interface NewContentMenu {
  readonly label: string
  readonly items: readonly NewContentItem[]
}

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
  /** Core's admin bar "New" menu, ready for the toolbar's `+`. */
  readonly newContent: NewContentMenu
}

declare global {
  interface Window {
    ADMIN_SUITE_BOOTSTRAP?: Bootstrap
  }
}

export {}
