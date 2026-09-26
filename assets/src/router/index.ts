import { createRouter, createWebHashHistory } from 'vue-router'

import type { RouteRecordRaw } from 'vue-router'

import DashboardView from '@/views/DashboardView.vue'

/**
 * Where the SPA is mounted. It has to be the exact URL that serves the page.
 *
 * The suite takes over the core dashboard, so that is `index.php` and nothing
 * else. Hash history is still the right mechanism for now, because no route in
 * this app is served by WordPress: `/wp-admin/index.php#/media` reloads fine,
 * whereas a `createWebHistory` base pointing at a pretty URL would 404 on
 * reload and resolve to the catch-all on first load. Once the suite starts
 * answering for the real admin screens, those routes *are* served by WordPress
 * and the hash stops being necessary.
 */
const SUITE_URL = 'index.php'

/**
 * The same URL, but taken from the bootstrap and reduced to a path.
 *
 * The path is not always `/wp-admin/`: the dashboard exists in all three admin
 * areas, so the network admin serves it from `/wp-admin/network/index.php` and
 * the user admin from `/wp-admin/user/index.php`. PHP builds `suiteUrl` from
 * `AdminContext::baseUrl()`, so it is already correct for whichever screen is
 * rendering, and hardcoding the path here would put every one of the SPA's own
 * links and REST calls on the site admin.
 *
 * The origin has to be stripped: vue-router appends the hash to this string, so
 * an absolute base would yield `#http://host/wp-admin/…#/dashboard`.
 */
function suiteBase(): string {
  const fallback = `/wp-admin/${SUITE_URL}`
  const suiteUrl = window.ADMIN_SUITE_BOOTSTRAP?.suiteUrl

  if (!suiteUrl) {
    return fallback
  }

  try {
    const url = new URL(suiteUrl, window.location.origin)

    return `${url.pathname}${url.search}`
  } catch {
    return fallback
  }
}

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', name: 'dashboard', component: DashboardView },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/PlaceholderView.vue'),
  },
]

/**
 * Hash history, so client-side routes survive a reload as
 * `index.php#/media` without needing a rewrite rule.
 */
export const router = createRouter({
  history: createWebHashHistory(suiteBase()),
  routes,
})

export default router
