import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'

import App from '@/App.vue'
import router from '@/router'
import '@/style.css'

/**
 * The element the application takes over.
 *
 * `wp-admin/index.php` prints this itself, wrapped around the core dashboard
 * widgets, and `mount()` replaces an element's contents while keeping the * element. That is the whole reason the takeover needs no output buffering: * `wp_dashboard()` is called inline rather than through a `do_action()`, so * there is no hook to detach the core widgets with. The pleasant side effect * is that a bundle which fails to boot leaves WordPress's own dashboard on
 * screen instead of an empty box.
 */
const MOUNT_ID = 'dashboard-widgets-wrap'

if (!window.ADMIN_SUITE_BOOTSTRAP) {
  throw new Error(
    'window.ADMIN_SUITE_BOOTSTRAP missing: run `npm run build` so the Vite manifest exists, then reload wp-admin.',
  )
}

const queryClient = new QueryClient({
  defaultOptions: { queries: { refetchOnWindowFocus: false, retry: 1, staleTime: 30_000 } },
})

function mount(): void {
  const root = document.getElementById(MOUNT_ID)

  if (!root) {
    throw new Error(
      `Mount point #${MOUNT_ID} not found: the suite only takes over the wp-admin dashboard.`,
    )
  }

  createApp(App).use(createPinia()).use(router).use(VueQueryPlugin, { queryClient }).mount(root)
}

// The script tag is `type="module"`, so the element has already been parsed by
// the time this runs. Guarding on readyState anyway keeps the module honest if
// the tag ever moves to the footer.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount, { once: true })
} else {
  mount()
}
