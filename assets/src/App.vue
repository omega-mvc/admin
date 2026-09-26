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
    no place for: the search palette and the suite's colour scheme. It sits above
    the grid, inside the content column, not across the whole admin.
  -->
  <div class="min-h-screen bg-canvas text-ink" :class="app.darkMode ? 'dark' : ''">
    <!--
      One query context for the bar and the grid together. The bar compacts at
      the same width the grid drops to a single column, so the two move as one:
      the requirement is that the bar folds with the dashboard, not that both
      happen to be narrow at once.

      It has to be a single container. With the bar outside and `<main>` inside,
      the bar's `@2xl:` would resolve against the viewport while the grid's
      resolved against the content column, and the two thresholds would drift
      apart by however much the admin menu takes.
    -->
    <div class="@container min-w-0 p-6">
      <div class="mb-4 flex items-center justify-end gap-2">
        <button
          type="button"
          class="flex items-center gap-2 rounded-md border border-line bg-panel px-3 py-1.5 text-sm text-ink-muted hover:bg-sunken"
          @click="app.togglePalette(true)"
        >
          <Search class="size-4" aria-hidden="true" />
          <!-- `@2xl` is Tailwind's default container scale: 42rem, the same width the grid goes to two columns. -->
          <span class="hidden @2xl:inline">{{ __('Search…') }}</span>
          <kbd class="hidden rounded border border-line px-1 text-[10px] @2xl:inline">⌘K</kbd>
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

      <main>
        <RouterView />
      </main>
    </div>

    <CommandPalette />
  </div>
</template>
