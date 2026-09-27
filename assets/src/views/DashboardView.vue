<script setup lang="ts">
import { computed, ref } from 'vue'

import { useQueryClient } from '@tanstack/vue-query'

import type { Panel } from '@/composables/useDashboard'
import ActivityFeed from '@/components/dashboard/ActivityFeed.vue'
import NativeWidgetBody from '@/components/dashboard/NativeWidgetBody.vue'
import PanelCard from '@/components/dashboard/PanelCard.vue'
import RecentPostsList from '@/components/dashboard/RecentPostsList.vue'
import ScreenOptionsPanel from '@/components/dashboard/ScreenOptionsPanel.vue'
import StatTiles from '@/components/dashboard/StatTiles.vue'
import { WELCOME_PANEL_ID, isSuitePanel } from '@/components/dashboard/panels'
import { nativeWidgetKey, useDashboard } from '@/composables/useDashboard'
import { useAppStore } from '@/stores'
import { __ } from '@/utils/i18n'

/*
 * Destructured rather than namespaced behind `dashboard.`: the refs are then
 * top-level bindings, which templates unwrap automatically.
 *
 * The panel is rendered here, not in the toolbar, and that placement is forced:
 * `useDashboard()` keeps its optimistic draft in a local ref, so a second call
 * from `App.vue` would be a second, independent draft and the grid would not
 * react to a widget taken off the dashboard. One instance, one draft.
 */
const {
  query,
  visiblePanels,
  hiddenPanels,
  canMove,
  move,
  reorder,
  toggle,
  toggleCollapsed,
  isSaving,
} = useDashboard()

const app = useAppStore()

/** Mirrors `Enqueue`'s capability read; the dashboard payload does not carry it. */
const canEdit = window.ADMIN_SUITE_BOOTSTRAP?.canEdit ?? false

const queryClient = useQueryClient()

const data = computed(() => query.data.value)

/* --------------------------------------------------------------- drag state */

const draggingId = ref<string | null>(null)
const dropTargetId = ref<string | null>(null)

function onDragStart(event: DragEvent, id: string): void {
  draggingId.value = id

  // Firefox will not start a drag unless some payload is set.
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', id)
  }
}

function onDragOver(event: DragEvent, id: string): void {
  // Without a preventDefault here the drop event never fires.
  if (draggingId.value === null || draggingId.value === id) {
    return
  }

  event.preventDefault()

  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move'
  }

  dropTargetId.value = id
}

function onDrop(event: DragEvent, id: string): void {
  event.preventDefault()

  if (draggingId.value !== null && draggingId.value !== id) {
    reorder(draggingId.value, id)
  }

  clearDrag()
}

function clearDrag(): void {
  draggingId.value = null
  dropTargetId.value = null
}

/* ----------------------------------------------------------- panel helpers */

/**
 * Column span per `span` value, mirroring `DashboardController::spanOf()`.
 *
 * Container variants (`@…:`) rather than viewport ones: this grid sits inside
 * the `@container` wrapper in App.vue, whose width shrinks by 16rem when core's
 * menu folds, while the viewport stays the same. The classes must cover every tier, because a
 * panel that spans 3 but whose tier only offers 2 columns would either overflow
 * or be silently clamped to a single column.
 */
const spanClass: Record<number, string> = {
  1: '@2xl:col-span-1 @5xl:col-span-1',
  2: '@2xl:col-span-2 @5xl:col-span-2',
  3: '@2xl:col-span-2 @5xl:col-span-3',
}

/** Native panels re-fetch on demand; the SPA panels share the dashboard query. */
function isNative(panel: Panel): boolean {
  return panel.widget.context !== 'suite'
}

function refresh(panel: Panel): void {
  void queryClient.invalidateQueries({ queryKey: nativeWidgetKey(panel.widget.id) })
}

function isRefreshing(panel: Panel): boolean {
  return (
    isNative(panel) && queryClient.isFetching({ queryKey: nativeWidgetKey(panel.widget.id) }) > 0
  )
}
</script>

<template>
  <section>
    <div
      v-if="query.isError.value"
      class="rounded-lg border border-negative bg-negative-soft p-4 text-sm text-negative"
    >
      <p class="font-medium">{{ __('The dashboard could not be loaded.') }}</p>
      <p class="mt-1">{{ query.error.value?.message }}</p>
      <button
        type="button"
        class="mt-3 rounded-md border border-current px-3 py-1.5 text-xs font-medium"
        @click="query.refetch()"
      >
        {{ __('Try again') }}
      </button>
    </div>

    <template v-else-if="data">
      <!--
        Sits above the grid, which reads as directly below the toolbar that owns
        the button. `mb-4` is on the wrapper rather than the grid so the grid
        keeps its own class list untouched.
      -->
      <div v-if="app.screenOptionsOpen" class="mb-4">
        <ScreenOptionsPanel
          id="suite-screen-options"
          :hidden="hiddenPanels"
          :is-saving="isSaving"
          :can-edit="canEdit"
          @toggle="toggle"
        />
      </div>

      <!--
        Two columns from 42rem of *content* width, three from 64rem. The widest
        realistic content area (1440px viewport, sidebar collapsed, minus
        padding) is ~87rem, so three columns is the ceiling.
      -->
      <ul class="grid grid-cols-1 gap-4 @2xl:grid-cols-2 @5xl:grid-cols-3">
        <li
          v-for="panel in visiblePanels"
          :key="panel.widget.id"
          class="min-w-0"
          :class="[
            spanClass[panel.widget.span] ?? '@2xl:col-span-1 @5xl:col-span-1',
            draggingId === panel.widget.id ? 'opacity-40' : '',
            dropTargetId === panel.widget.id
              ? 'ring-2 ring-accent ring-offset-2 ring-offset-canvas'
              : '',
          ]"
          draggable="true"
          @dragstart="onDragStart($event, panel.widget.id)"
          @dragover="onDragOver($event, panel.widget.id)"
          @drop="onDrop($event, panel.widget.id)"
          @dragend="clearDrag"
        >
          <PanelCard
            :title="panel.widget.title"
            :up-disabled="!canMove(panel.widget.id, -1)"
            :down-disabled="!canMove(panel.widget.id, 1)"
            :refreshable="isNative(panel)"
            :refreshing="isRefreshing(panel)"
            :show-toggle="app.screenOptionsOpen && panel.widget.id !== WELCOME_PANEL_ID"
            :collapsible="panel.widget.id !== WELCOME_PANEL_ID"
            :movable="panel.widget.id !== WELCOME_PANEL_ID"
            :grip="panel.widget.id !== WELCOME_PANEL_ID"
            :collapsed="panel.collapsed"
            :visible="panel.visible"
            @move="move(panel.widget.id, $event)"
            @toggle="toggle(panel.widget.id)"
            @fold="toggleCollapsed(panel.widget.id)"
            @refresh="refresh(panel)"
          >
            <StatTiles
              v-if="isSuitePanel(panel.widget, 'stats')"
              :counts="data.counts"
              :admin-url="data.site.adminUrl"
            />

            <RecentPostsList
              v-else-if="isSuitePanel(panel.widget, 'recentPosts')"
              :posts="data.recentPosts"
            />

            <ActivityFeed
              v-else-if="isSuitePanel(panel.widget, 'activity')"
              :entries="data.activity"
            />

            <NativeWidgetBody v-else :id="panel.widget.id" @dismiss="toggle(panel.widget.id)" />
          </PanelCard>
        </li>
      </ul>
    </template>
  </section>
</template>
