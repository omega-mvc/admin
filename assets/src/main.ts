import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'

import App from '@/App.vue'
import router from '@/router'
import '@/style.css'

const root = document.getElementById('admin-suite-root')

if (!root) {
  throw new Error(
    'Mount point #admin-suite-root not found. The plugin must be active for the SPA shell to render.',
  )
}

if (!window.ADMIN_SUITE_BOOTSTRAP) {
  throw new Error(
    'window.ADMIN_SUITE_BOOTSTRAP missing: run `npm run build` so the Vite manifest exists, then reload wp-admin.',
  )
}

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
      staleTime: 30_000,
    },
  },
})

createApp(App).use(createPinia()).use(router).use(VueQueryPlugin, { queryClient }).mount(root)
