/** Shared REST payload shapes. Mirrors includes/Api/*.php. */

export interface MenuChild {
  id: string
  label: string
  url: string
  icon: string
  capability: string
  context: 'view' | 'edit'
  children: MenuChild[]
}

export type MenuItem = MenuChild

export interface MenuResponse {
  items: MenuItem[]
  counts: { topLevel: number }
}

export type SearchResultType = 'post' | 'term' | 'user' | 'setting'

export interface SearchResult {
  id: number | string
  type: SearchResultType
  label: string
  sub: string
  url: string
}

export interface SearchResponse {
  term: string
  groups: Partial<Record<SearchResultType, SearchResult[]>>
  total: number
}

export type Accent = 'indigo' | 'emerald' | 'amber' | 'rose' | 'slate'
export type Density = 'compact' | 'comfortable'
export type SidebarState = 'expanded' | 'collapsed'

export interface SuiteSettings {
  accent: Accent
  density: Density
  sidebar: SidebarState
  enablePlugins: boolean
}

export interface UserPreferences {
  theme: boolean
  sidebar: SidebarState
  density: Density
  lastRoute: string
}

/* ---------------------------------------------------------------- dashboard */

export interface DashboardSite {
  name: string
  url: string
  adminUrl: string
  language: string
  charset: string
  timezone: string
  version: string
  php: string
}

export interface DashboardCounts {
  posts: number
  postsDraft: number
  pages: number
  media: number
  users: number
  commentsPending: number
}

export interface RecentPost {
  id: number
  title: string
  status: string
  author: string
  /** ISO 8601, or '' when WordPress could not format it. */
  date: string
  url: string
  /** Featured image URL, or '' when the post has none. */
  thumb: string
}

export interface ActivityEntry {
  id: string
  type: 'comment' | 'update'
  actor: string
  subject: string
  summary: string
  /** ISO 8601, or '' when the stored record has no usable timestamp. */
  time: string
}

/** `suite` marks a panel the SPA renders itself; the rest are meta box contexts. */
export type WidgetContext = 'suite' | 'side' | 'normal' | 'high'

export interface DashboardWidget {
  id: string
  title: string
  context: WidgetContext
  /** Grid columns the panel spans on wide screens. Sent by the API, not guessed. */
  span: 1 | 2 | 3
}

export interface WidgetLayoutEntry {
  id: string
  visible: boolean
}

export interface DashboardResponse {
  site: DashboardSite
  counts: DashboardCounts
  recentPosts: RecentPost[]
  activity: ActivityEntry[]
  widgets: DashboardWidget[]
  layout: WidgetLayoutEntry[]
}

export interface NativeWidgetResponse {
  id: string
  title: string
  html: string
}

/**
 * One contextual help tab, as WordPress would render it.
 *
 * `content` and `sidebar` are HTML, already translated server-side with the
 * `default` textdomain so a non-English site gets core's own strings. They are
 * only ever set by `Core\DashboardHelp::payload()`, which is the other end of
 * this contract.
 */
export interface HelpTab {
  id: string
  title: string
  content: string
}

export interface HelpPayload {
  tabs: HelpTab[]
  sidebar: string
}
