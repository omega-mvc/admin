<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { PanelLeftClose, PanelLeftOpen, Search } from 'lucide-vue-next'

import CommandPalette from '@/components/CommandPalette.vue'
import { useAppStore } from '@/stores'
import { __ } from '@/utils/i18n'

const app = useAppStore()

/**
 * Whether WordPress's own admin menu is folded.
 *
 * This button does not fold the menu. It clicks core's `#collapse-button`, so
 * `common.js` does the work and WordPress persists the choice with
 * `setUserSetting()` and keeps its own ARIA in step. Writing the `folded` class
 * ourselves would look correct for one page load and then be silently undone,
 * because `admin-header.php` re-applies that class from the stored setting on
 * every single request.
 *
 * Below 960px WordPress auto-folds and that same button toggles `auto-fold`
 * instead of `folded`, so both classes are consulted.
 */
const menuFolded = ref(false)

function readMenuState(): void {
  const body = document.body

  if (!body) {
    return
  }

  const narrow =
    typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 960px)').matches

  menuFolded.value =
    body.classList.contains('folded') || (narrow && body.classList.contains('auto-fold'))
}

function toggleMenu(): void {
  document.getElementById('collapse-button')?.click()
}

/*
 * Core announces the change with `wp-collapse-menu`, but it fires that through
 * jQuery's own event system, which does not reach a native `addEventListener`.
 * Rather than depend on the event name, this watches the body class instead —
 * which covers core's own button, this one, and anything else that folds it.
 */
let classObserver: MutationObserver | null = null

onMounted(() => {
  readMenuState()

  if (typeof MutationObserver !== 'undefined' && document.body) {
    classObserver = new MutationObserver(readMenuState)
    classObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] })
  }

  window.addEventListener('resize', readMenuState)
})

onBeforeUnmount(() => {
  classObserver?.disconnect()
  window.removeEventListener('resize', readMenuState)
})
</script>

<template>
  <!--
    The suite no longer draws its own admin chrome. The header and the sidebar
    that used to live here were replicas of what wp-admin had already painted
    to their left, which is what made this a dashboard inside a dashboard.
    Mounting on `#dashboard-widgets-wrap` puts the application where the core
    widgets already were, so only the panel itself is left to render.

    What remains is a toolbar carrying the two controls the core dashboard has
    no place for: the search palette, and a shortcut for the admin menu's own
    collapse. It sits above the grid, inside the content column, not across the
    whole admin.
  -->
  <div class="min-h-screen bg-canvas text-ink">
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
          class="flex size-8 shrink-0 items-center justify-center rounded-md border border-line bg-panel text-ink-muted hover:bg-sunken"
          :aria-label="menuFolded ? __('Expand the main menu') : __('Collapse the main menu')"
          @click="toggleMenu"
        >
          <PanelLeftOpen v-if="menuFolded" class="size-4" aria-hidden="true" />
          <PanelLeftClose v-else class="size-4" aria-hidden="true" />
        </button>

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
      </div>

      <main>
        <RouterView />
      </main>
    </div>

    <CommandPalette />
  </div>
</template>
