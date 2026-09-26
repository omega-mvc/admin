<script setup lang="ts">
import { ChevronUp, ChevronDown, EyeOff, RefreshCw, GripVertical } from 'lucide-vue-next'

import { __, sprintf } from '@/utils/i18n'

/**
 * Card chrome for one dashboard panel: heading, reorder controls and the slot
 * the panel body goes into.
 *
 * Pointer dragging is handled by the parent `<li>` (it owns `draggable` and the
 * drag state), so the grip here is decorative. Reordering is still reachable
 * from the keyboard through the two move buttons, which is the only path that
 * works without a pointer.
 */
const props = defineProps<{
  title: string
  upDisabled: boolean
  downDisabled: boolean
  /** Native panels are refetched on demand; SPA panels follow the dashboard query. */
  refreshable: boolean
  refreshing: boolean
}>()

const emit = defineEmits<{
  move: [delta: number]
  toggle: []
  refresh: []
}>()

const buttonClass =
  'rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-30 dark:hover:bg-slate-800 dark:hover:text-slate-200'
</script>

<template>
  <article
    class="group flex h-full flex-col rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900"
  >
    <header
      class="flex items-center gap-2 border-b border-slate-200 px-3 py-2 dark:border-slate-800"
    >
      <GripVertical
        class="size-4 shrink-0 cursor-grab text-slate-300 group-hover:text-slate-400 dark:text-slate-600"
        aria-hidden="true"
      />

      <h2 class="min-w-0 flex-1 truncate text-sm font-semibold">{{ props.title }}</h2>

      <!--
        Opacity is the only thing gating these: they stay in the tab order and
        become visible on focus, so the controls are usable without a pointer.
      -->
      <div
        class="flex items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100"
      >
        <button
          v-if="props.refreshable"
          type="button"
          :class="buttonClass"
          :aria-label="sprintf(__('Refresh %s'), props.title)"
          :disabled="props.refreshing"
          @click="emit('refresh')"
        >
          <RefreshCw
            class="size-4"
            :class="props.refreshing ? 'animate-spin' : ''"
            aria-hidden="true"
          />
        </button>

        <button
          type="button"
          :class="buttonClass"
          :aria-label="sprintf(__('Move %s up'), props.title)"
          :disabled="props.upDisabled"
          @click="emit('move', -1)"
        >
          <ChevronUp class="size-4" aria-hidden="true" />
        </button>

        <button
          type="button"
          :class="buttonClass"
          :aria-label="sprintf(__('Move %s down'), props.title)"
          :disabled="props.downDisabled"
          @click="emit('move', 1)"
        >
          <ChevronDown class="size-4" aria-hidden="true" />
        </button>

        <button
          type="button"
          :class="buttonClass"
          :aria-label="sprintf(__('Hide %s'), props.title)"
          @click="emit('toggle')"
        >
          <EyeOff class="size-4" aria-hidden="true" />
        </button>
      </div>
    </header>

    <div class="min-h-0 flex-1 p-3">
      <slot />
    </div>
  </article>
</template>
