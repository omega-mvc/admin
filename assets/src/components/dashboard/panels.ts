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

/**
 * The one native widget that brings its own dismiss control.
 *
 * Core's welcome panel ships a "Dismiss" cross in its top right corner, and
 * `NativeWidgetBody` intercepts that click so the widget is hidden through the
 * suite's own layout instead of reloading the page. So the card checkbox and
 * the cross would be two controls for the same action in the same corner, and
 * the cross alone is the one that is always there. The checkbox is therefore
 * kept off this widget.
 */
export const WELCOME_PANEL_ID = 'welcome-panel'

/**
 * Whether a panel holds its slot in the layout.
 *
 * The welcome panel is pinned: it does not move, and nothing moves above it.
 * So it is rendered without the move controls at all, and the panel sitting
 * directly under it has its up arrow disabled, because swapping the two would
 * displace it. `targetIndex()` in `useDashboard` is where the second half is
 * enforced, so the disabled arrow and the refused move can never disagree.
 */
export function isPinnedPanel(id: string): boolean {
  return id === WELCOME_PANEL_ID
}
