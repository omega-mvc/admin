<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  BookOpen,
  ExternalLink,
  PanelLeftClose,
  PanelLeftOpen,
  Plus,
  Search,
} from 'lucide-vue-next'

import CommandPalette from '@/components/CommandPalette.vue'
import { useAppStore } from '@/stores'
import { __, sprintf } from '@/utils/i18n'

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

  if (!menuFolded.value) {
    newMenuOpen.value = false
  }
}

function toggleMenu(): void {
  document.getElementById('collapse-button')?.click()
}

/*
 * Core's admin bar "New" menu, already rebuilt by `Enqueue::newContentMenu()`
 * out of core's own function, so the entries, their order, their capability
 * checks and their labels are core's rather than a reimplementation. The empty
 * state is meaningful: it is what the network and user admins get, and also
 * what a user who cannot create anything gets.
 */
const newMenu = window.ADMIN_SUITE_BOOTSTRAP?.newContent ?? { label: '', items: [] }

const newMenuOpen = ref(false)

const newMenuRef = ref<HTMLElement | null>(null)

/*
 * Two more menus straight out of core's admin bar, built by
 * `Enqueue::documentationMenu()` and `Enqueue::siteFrontUrl()`. Unlike the "New"
 * menu these are registered outside the network and user admin guard, so they
 * are present in all three areas; what can still be empty is the front-end link,
 * which core simply does not publish in some setups.
 *
 * The documentation menu is the WordPress logo: the first menu on the left of
 * the admin bar, holding About WordPress, Get Involved, WordPress.org,
 * Documentation, Learn WordPress, Support and Feedback.
 */
const docsMenu = window.ADMIN_SUITE_BOOTSTRAP?.docs ?? { label: '', items: [] }
const siteFrontUrl = window.ADMIN_SUITE_BOOTSTRAP?.siteFrontUrl ?? ''
const siteName = window.ADMIN_SUITE_BOOTSTRAP?.siteName ?? ''

const docsOpen = ref(false)

const docsRef = ref<HTMLElement | null>(null)

/*
 * Only while the admin menu is folded. With the menu open, WordPress's own `+`
 * is already there in the admin bar, and a second copy of the same list one
 * screen lower would just be noise.
 */
const showNewMenu = computed(() => menuFolded.value && newMenu.items.length > 0)

function toggleNewMenu(): void {
  newMenuOpen.value = !newMenuOpen.value
  docsOpen.value = false
}

function toggleDocs(): void {
  docsOpen.value = !docsOpen.value
  newMenuOpen.value = false
}

function onPointerDown(event: MouseEvent): void {
  const target = event.target as Node

  if (!newMenuRef.value?.contains(target)) {
    newMenuOpen.value = false
  }

  if (!docsRef.value?.contains(target)) {
    docsOpen.value = false
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    newMenuOpen.value = false
    docsOpen.value = false
  }
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
  document.addEventListener('pointerdown', onPointerDown)
  document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  classObserver?.disconnect()
  window.removeEventListener('resize', readMenuState)
  document.removeEventListener('pointerdown', onPointerDown)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <!--
    The suite no longer draws its own admin chrome. The header and the sidebar
    that used to live here were replicas of what wp-admin had already painted
    to their left, which is what made this a dashboard inside a dashboard.
    Mounting on `#dashboard-widgets-wrap` puts the application where the core
    widgets already were, so only the panel itself is left to render.

    What remains is a toolbar with the controls the core dashboard has nowhere
    to put. The admin menu's own collapse and core's "New" menu sit on the left
    of the content column, the search palette next, and the front-end link and
    core's documentation menu close the bar on the right. None of them runs
    across the whole admin.
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
      <div class="mb-4 flex items-center gap-2">
        <button
          type="button"
          class="flex size-8 shrink-0 items-center justify-center rounded-md border border-line bg-panel text-ink-muted hover:bg-sunken"
          :aria-label="menuFolded ? __('Expand the main menu') : __('Collapse the main menu')"
          @click="toggleMenu"
        >
          <PanelLeftOpen v-if="menuFolded" class="size-4" aria-hidden="true" />
          <PanelLeftClose v-else class="size-4" aria-hidden="true" />
        </button>

        <div v-if="showNewMenu" ref="newMenuRef" class="relative">
          <button
            type="button"
            class="flex size-8 shrink-0 items-center justify-center rounded-md border border-line bg-panel text-ink-muted hover:bg-sunken"
            :aria-label="__('Create new')"
            :aria-expanded="newMenuOpen"
            aria-controls="suite-new-menu"
            @click="toggleNewMenu"
          >
            <Plus class="size-4" aria-hidden="true" />
          </button>

          <!--
            A disclosure, not a menu. The contents are a short list of links, so
            an `aria-haspopup` menu would promise arrow-key navigation this popup
            does not implement; `aria-expanded` plus `aria-controls` is the
            pattern that matches what it actually is.
          -->
          <ul
            v-if="newMenuOpen"
            id="suite-new-menu"
            class="absolute top-full left-0 z-10 mt-1 min-w-44 overflow-hidden rounded-md border border-line bg-panel py-1 shadow-lg"
          >
            <li v-for="item in newMenu.items" :key="item.id">
              <a
                :href="item.url"
                class="block px-3 py-1.5 text-sm text-ink-muted hover:bg-sunken hover:text-ink"
              >
                {{ item.label }}
              </a>
            </li>
          </ul>
        </div>

        <!--
          Right-aligned, and the one control that still compacts: the label and
          the shortcut hint hide at the same container width the grid drops to a
          single column, so the bar and the dashboard fold together.
        -->
        <button
          type="button"
          class="ml-auto flex items-center gap-2 rounded-md border border-line bg-panel px-3 py-1.5 text-sm text-ink-muted hover:bg-sunken"
          @click="app.togglePalette(true)"
        >
          <Search class="size-4" aria-hidden="true" />
          <!-- `@2xl` is Tailwind's default container scale: 42rem, the same width the grid goes to two columns. -->
          <span class="hidden @2xl:inline">{{ __('Search…') }}</span>
          <kbd class="hidden rounded border border-line px-1 text-[10px] @2xl:inline">⌘K</kbd>
        </button>

        <!--
          The front-end link, pointed at by core's own `view-site` node rather
          than at a guessed `home_url()`. A plain link, so it is an `<a>` and not
          a button: it navigates away from wp-admin.
        -->
        <a
          v-if="siteFrontUrl"
          :href="siteFrontUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="flex size-8 shrink-0 items-center justify-center rounded-md border border-line bg-panel text-ink-muted hover:bg-sunken"
          :aria-label="
            sprintf(
              // translators: %s: site name, so the label says which site opens.
              __('Visit %s'),
              siteName,
            )
          "
        >
          <ExternalLink class="size-4" aria-hidden="true" />
        </a>

        <div v-if="docsMenu.items.length" ref="docsRef" class="relative">
          <button
            type="button"
            class="flex size-8 shrink-0 items-center justify-center rounded-md border border-line bg-panel text-ink-muted hover:bg-sunken"
            :aria-label="__('Documentation')"
            :aria-expanded="docsOpen"
            aria-controls="suite-docs-menu"
            @click="toggleDocs"
          >
            <BookOpen class="size-4" aria-hidden="true" />
          </button>

          <!--
            A disclosure for the same reason as the "New" popup: a list of links,
            not an arrow-key navigable menu. It opens to the left because this
            button is the rightmost thing in the bar.
          -->
          <ul
            v-if="docsOpen"
            id="suite-docs-menu"
            class="absolute top-full right-0 z-10 mt-1 min-w-48 overflow-hidden rounded-md border border-line bg-panel py-1 shadow-lg"
          >
            <li v-for="item in docsMenu.items" :key="item.id">
              <a
                :href="item.url"
                :target="item.newTab ? '_blank' : undefined"
                :rel="item.newTab ? 'noopener noreferrer' : undefined"
                class="block px-3 py-1.5 text-sm text-ink-muted hover:bg-sunken hover:text-ink"
              >
                {{ item.label }}
              </a>
            </li>
          </ul>
        </div>
      </div>

      <main>
        <RouterView />
      </main>
    </div>

    <CommandPalette />
  </div>
</template>
