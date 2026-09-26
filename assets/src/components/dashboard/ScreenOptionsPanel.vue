<script setup lang="ts">
/**
 * The "Screen Options" counterpart of WordPress's own dashboard.
 *
 * Core builds this list in `meta_box_prefs()`
 * (`wp-admin/includes/screen.php:96-142`), which reads `$wp_meta_boxes`
 * directly and therefore cannot be filtered. The list is rebuilt here from the
 * widget inventory the dashboard endpoint already returns, so SPA panels and
 * native widgets sit in the same list and the same checkbox toggles both.
 *
 * Visibility is persisted through the existing `admin_suite_dashboard_layout`
 * user meta, not through core's `closedpostboxes_*`, so the two dashboards do
 * not fight over the same setting.
 */
import { Loader2, RotateCcw } from 'lucide-vue-next'

import type { Panel } from '@/composables/useDashboard'

defineProps<{
  panels: Panel[]
  hiddenCount: number
  isSaving: boolean
  isDefaultLayout: boolean
  saveFailed: boolean
  saveError: string | null
  canEdit: boolean
}>()

const emit = defineEmits<{
  toggle: [id: string]
  reset: []
}>()
</script>

<template>
  <div
    class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-800 dark:bg-slate-900"
  >
    <fieldset class="min-w-0">
      <legend class="mb-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
        Dashboard panels
      </legend>

      <p class="mb-3 text-slate-600 dark:text-slate-400">
        Tick a panel to show it on the dashboard. Changes save automatically.
      </p>

      <ul class="grid gap-x-4 gap-y-1.5 @xs:grid-cols-2">
        <li v-for="panel in panels" :key="panel.widget.id" class="min-w-0">
          <label
            class="flex cursor-pointer items-center gap-2 py-0.5"
            :for="`panel-toggle-${panel.widget.id}`"
          >
            <input
              :id="`panel-toggle-${panel.widget.id}`"
              class="size-4 shrink-0 rounded-sm border-slate-300 text-accent focus:ring-accent"
              type="checkbox"
              :checked="panel.visible"
              :disabled="!canEdit || isSaving"
              @change="emit('toggle', panel.widget.id)"
            />
            <span class="truncate text-slate-700 dark:text-slate-300">{{ panel.widget.title }}</span>
          </label>
        </li>
      </ul>
    </fieldset>

    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-3 dark:border-slate-800">
      <p
        class="text-xs text-slate-500 dark:text-slate-400"
        aria-live="polite"
        role="status"
      >
        <template v-if="saveFailed">
          <span class="text-rose-600 dark:text-rose-400">Not saved: {{ saveError }}</span>
        </template>
        <template v-else-if="isSaving">
          <span class="inline-flex items-center gap-1.5"
            ><Loader2 class="size-3 animate-spin" aria-hidden="true" /> Saving…</span
          >
        </template>
        <template v-else-if="hiddenCount > 0"> {{ hiddenCount }} hidden </template>
        <template v-else> All panels shown. </template>
      </p>

      <button
        class="ml-auto inline-flex items-center gap-1.5 rounded-sm border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        type="button"
        :disabled="!canEdit || isDefaultLayout || isSaving"
        @click="emit('reset')"
      >
        <RotateCcw class="size-3" aria-hidden="true" />
        Reset layout
      </button>
    </div>
  </div>
</template>
