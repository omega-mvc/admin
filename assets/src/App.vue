<script setup lang="ts">
import { Search, Moon, Sun } from 'lucide-vue-next'

import CommandPalette from '@/components/CommandPalette.vue'
import { useAppStore } from '@/stores'
import { __ } from '@/utils/i18n'

const app = useAppStore()
</script>

<template>
  <!--
    The suite no longer draws its own admin chrome. The header and the sidebar
    that used to live here were replicas of what wp-admin had already painted
    to their left, which is what made this a dashboard inside a dashboard.
    Mounting on `#dashboard-widgets-wrap` puts the application where the core
    widgets already were, so only the panel itself is left to render.

    What remains is a toolbar carrying the two controls the core dashboard has
    no place for, the search palette and the suite's colour scheme. It sits inside the panel, not above it: core's own `<h1>` is still the page title.
  -->
  <div class="min-h-screen bg-canvas text-ink" :class="app.darkMode ? 'dark' : ''">
    <div class="flex items-center justify-end gap-2 pb-3">
      <button
        type="button"
        class="flex items-center gap-2 rounded-md border border-line bg-panel px-3 py-1.5 text-sm text-ink-muted hover:bg-sunken"
        @click="app.togglePalette(true)"
      >
        <Search class="size-4" aria-hidden="true" />
        <span class="hidden sm:inline">{{ __('Search…') }}</span>
        <kbd class="hidden rounded border border-line px-1 text-[10px] sm:inline">⌘K</kbd>
      </button>

      <button
        type="button"
        class="rounded-md bg-panel p-2 hover:bg-sunken"
        :aria-label="app.darkMode ? __('Switch to light mode') : __('Switch to dark mode')"
        @click="app.toggleDarkMode()"
      >
        <Sun v-if="app.darkMode" class="size-5" aria-hidden="true" />
        <Moon v-else class="size-5" aria-hidden="true" />
      </button>
    </div>

    <!--
      `@container` makes the content area a query context. Screens must size
      themselves from this element's width, not the viewport's. It used to sit
      beside a 16rem sidebar, so the same viewport width gave two different
      content widths; inside the dashboard it tracks the `.wrap` instead.
    -->
    <main class="@container min-w-0 p-6">
      <RouterView />
    </main>

    <CommandPalette />
  </div>
</template>
