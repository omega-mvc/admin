<script setup lang="ts">
import { computed } from 'vue'

import { Menu, Search, Moon, Sun, LayoutDashboard } from 'lucide-vue-next'

import CommandPalette from '@/components/CommandPalette.vue'
import { useAppStore, useMenuStore } from '@/stores'

const app = useAppStore()
const menu = useMenuStore()

const widthClass = computed(() => (app.sidebarCollapsed ? 'w-16' : 'w-64'))
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <header
      class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900"
    >
      <button
        type="button"
        class="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-slate-800"
        :aria-expanded="!app.sidebarCollapsed"
        aria-label="Toggle sidebar"
        @click="app.toggleSidebar()"
      >
        <Menu class="size-5" aria-hidden="true" />
      </button>

      <span class="font-semibold">Admin Suite</span>

      <button
        type="button"
        class="ml-auto flex items-center gap-2 rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800"
        @click="app.togglePalette(true)"
      >
        <Search class="size-4" aria-hidden="true" />
        <span class="hidden sm:inline">Search…</span>
        <kbd
          class="hidden rounded border border-slate-200 px-1 text-[10px] sm:inline dark:border-slate-700"
          >⌘K</kbd
        >
      </button>

      <button
        type="button"
        class="rounded-md p-2 hover:bg-slate-100 dark:hover:bg-slate-800"
        :aria-label="app.darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
        @click="app.toggleDarkMode()"
      >
        <Sun v-if="app.darkMode" class="size-5" aria-hidden="true" />
        <Moon v-else class="size-5" aria-hidden="true" />
      </button>
    </header>

    <div class="flex">
      <aside
        class="sticky top-14 h-[calc(100vh-3.5rem)] shrink-0 overflow-y-auto border-r border-slate-200 bg-white transition-[width] dark:border-slate-800 dark:bg-slate-900"
        :class="widthClass"
      >
        <nav class="p-2" aria-label="Main">
          <template v-if="menu.isLoading">
            <p class="px-3 py-2 text-xs text-slate-400">Loading menu…</p>
          </template>
          <ul v-else class="space-y-0.5">
            <li v-for="item in menu.items" :key="item.id">
              <a
                :href="item.url"
                class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                :title="app.sidebarCollapsed ? item.label : undefined"
              >
                <LayoutDashboard
                  v-if="item.id === 'index.php'"
                  class="size-4 shrink-0"
                  aria-hidden="true"
                />
                <span v-if="!app.sidebarCollapsed" class="truncate">{{ item.label }}</span>
              </a>
            </li>
          </ul>
        </nav>
      </aside>

      <!--
        `@container` makes the content area a query context. Screens must size
        themselves from this element's width, not the viewport's: the sidebar
        takes 16rem, so at a 1280px viewport the content is 1024px wide with the
        sidebar open and 1216px with it collapsed. Viewport breakpoints cannot
        see that difference, which is why the grid used to compress.
      -->
      <main class="@container min-w-0 flex-1 p-6">
        <RouterView />
      </main>
    </div>

    <CommandPalette />
  </div>
</template>
