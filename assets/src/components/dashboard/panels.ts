import type { DashboardWidget } from '@/types/api'

/**
 * Panel ids the SPA renders itself.
 *
 * Mirrors `DashboardController::builtInPanels()` on the PHP side. The ids are
 * whitelisted there, so anything not in this map is a native widget whose HTML
 * is fetched from `GET /dashboard/widget/<id>`.
 */
export const SUITE_PANEL = {
  stats: 'suite-stats',
  recentPosts: 'suite-recent-posts',
  activity: 'suite-activity',
} as const

export type SuitePanel = keyof typeof SUITE_PANEL

export function isSuitePanel(widget: DashboardWidget, panel: SuitePanel): boolean {
  return widget.id === SUITE_PANEL[panel]
}
