import { createRouter, createWebHashHistory } from 'vue-router'

import type { RouteRecordRaw } from 'vue-router'

import DashboardView from '@/views/DashboardView.vue'

/**
 * Where the SPA is mounted. It has to be the exact URL that serves the page:
 * `add_menu_page()` hands out `admin.php?page=<slug>`, and nothing in
 * WordPress rewrites any other path, so a `createWebHistory` base pointing at a
 * pretty URL would 404 on reload and resolve to the catch-all on first load.
 */
const SUITE_URL = 'admin.php?page=admin-suite'

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
 * `admin.php?page=admin-suite#/media` without needing a rewrite rule.
 */
export const router = createRouter({
  history: createWebHashHistory(`/wp-admin/${SUITE_URL}`),
  routes,
})

export default router
