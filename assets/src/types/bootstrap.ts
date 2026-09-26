/**
 * Runtime contract between PHP and the SPA.
 *
 * Injected by `AdminSuite\Core\Enqueue::enqueue()` as
 * `window.ADMIN_SUITE_BOOTSTRAP` right before the entry module executes.
 */
export interface Bootstrap {
  readonly restUrl: string
  readonly nonce: string
  readonly homeUrl: string
  readonly canManage: boolean
  readonly canEdit: boolean
  readonly canUpload: boolean
  readonly siteName: string
  readonly pluginVer: string
}

declare global {
  interface Window {
    ADMIN_SUITE_BOOTSTRAP?: Bootstrap
  }
}

export {}
