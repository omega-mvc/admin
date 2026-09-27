<script setup lang="ts">
/**
 * The "Screen Options" counterpart of WordPress's own dashboard.
 *
 * Core builds this list in `meta_box_prefs()`
 * (`wp-admin/includes/screen.php:96-142`), which reads `$wp_meta_boxes`
 * directly and therefore cannot be filtered. The list is rebuilt here from the
 * widget inventory the dashboard endpoint already returns, so SPA panels and
 * native widgets sit in the same list and the same control toggles both.
 *
 * Visibility is persisted through the existing `admin_suite_dashboard_layout`
 * user meta, not through core's `closedpostboxes_*`, so the two dashboards do
 * not fight over the same setting.
 *
 * Only the hidden widgets are listed. Taking one off the grid is done from the
 * widget itself, through the checkbox in its top right corner, which is
 * rendered only while this panel is open, so a widget that is still on the
 * dashboard needs no row here. What is left is the recovery list: the way back
 * to something that is no longer on screen.
 *
 * The widget name is the control rather than the label of one. There is no
 * checkbox to tick, because the row exists only to put a widget back and a
 * checkbox would have to be ticked to say that, which is the opposite of what
 * it looks like it does. The pill is a button, so it also carries a real
 * `disabled` state and takes focus in the tab order.
 *
 * The panel carries no prose at all — no heading, no caption, no empty state —
 * so a failed save is not reported here. The grid is what tells the user
 * instead: `useDashboard` drops its draft on error, so an unticked checkbox
 * coming back ticked is the signal that the change was refused.
 */
import type { Panel } from '@/composables/useDashboard'

import { __, sprintf } from '@/utils/i18n'

defineProps<{
  /** Widgets currently hidden, in layout order. */
  hidden: Panel[]
  /** Guards the pills while a save is in flight. */
  isSaving: boolean
  canEdit: boolean
}>()

const emit = defineEmits<{
  toggle: [id: string]
}>()
</script>

<template>
  <div class="rounded-md border border-line bg-panel p-4 text-sm">
    <!--
      `flex-wrap` because the row is horizontal, not a fixed column: with nine
      widgets hidden there is no width at which an unwrapped row fits, and
      overflow would push the panel wider than its container. `gap-0.5` is the
      2px separation.
    -->
    <ul class="flex flex-wrap items-center gap-0.5">
      <li v-for="panel in hidden" :key="panel.widget.id" class="min-w-0">
        <button
          type="button"
          class="max-w-full truncate rounded-full border border-line bg-panel px-2 py-0.5 text-xs text-ink transition-colors hover:bg-sunken hover:text-ink-muted disabled:pointer-events-none disabled:opacity-40"
          :disabled="!canEdit || isSaving"
          :aria-label="sprintf(__('Show %s on the dashboard'), panel.widget.title)"
          @click="emit('toggle', panel.widget.id)"
        >
          {{ panel.widget.title }}
        </button>
      </li>
    </ul>
  </div>
</template>
