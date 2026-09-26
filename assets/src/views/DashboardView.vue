<script setup lang="ts">
import { computed, ref } from 'vue'

import { useQueryClient } from '@tanstack/vue-query'
import { AlertTriangle, Eye, Loader2, RotateCcw } from 'lucide-vue-next'

import type { Panel } from '@/composables/useDashboard'
import ActivityFeed from '@/components/dashboard/ActivityFeed.vue'
import NativeWidgetBody from '@/components/dashboard/NativeWidgetBody.vue'
import PanelCard from '@/components/dashboard/PanelCard.vue'
import RecentPostsList from '@/components/dashboard/RecentPostsList.vue'
import StatTiles from '@/components/dashboard/StatTiles.vue'
import { isSuitePanel } from '@/components/dashboard/panels'
import { nativeWidgetKey, useDashboard } from '@/composables/useDashboard'
import { RestError } from '@/services/rest'
import { __, _n, sprintf } from '@/utils/i18n'

/*
 * Destructured rather than namespaced behind `dashboard.`: the refs are then
 * top-level bindings, which templates unwrap automatically.
 */
const {
  query,
  visiblePanels,
  hiddenPanels,
  hiddenCount,
  isDefaultLayout,
  isSaving,
  saveFailed,
  saveError,
  canMove,
  move,
  reorder,
  toggle,
  reset,
} = useDashboard()

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
 * `<main class="@container">`, whose width shrinks by the sidebar's 16rem while
 * the viewport stays the same. The classes must cover every tier, because a
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

const saveMessage = computed(() => {
  if (saveFailed.value) {
    const error = saveError.value

    if (error instanceof RestError && error.isAuthError) {
      return __('Session expired — reload the page, then try again.')
    }

    return __('Layout not saved — the previous layout was restored.')
  }

  if (isSaving.value) {
    return __('Saving layout…')
  }

  if (hiddenCount.value > 0) {
    return sprintf(_n('%s panel hidden', '%s panels hidden', hiddenCount.value), hiddenCount.value)
  }

  return __('Layout saved')
})
</script>

<template>
  <section>
    <header class="mb-5">
      <h1 class="text-xl font-semibold">{{ __('Dashboard') }}</h1>

      <p v-if="data" class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        <a :href="data.site.url" class="hover:text-accent">{{ data.site.name }}</a>
        <span class="mx-1.5 text-slate-300 dark:text-slate-700">·</span>
        WordPress {{ data.site.version }}
        <span class="mx-1.5 text-slate-300 dark:text-slate-700">·</span>
        PHP {{ data.site.php }}
        <span class="mx-1.5 text-slate-300 dark:text-slate-700">·</span>
        {{ data.site.language }}
      </p>
    </header>

    <div v-if="query.isLoading.value" class="flex items-center gap-2 py-16 text-sm text-slate-500">
      <Loader2 class="size-4 animate-spin" aria-hidden="true" />
      {{ __('Loading dashboard…') }}
    </div>

    <div
      v-else-if="query.isError.value"
      class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
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
      <div class="mb-3 flex min-h-6 items-center gap-3">
        <p
          class="flex items-center gap-1.5 text-xs"
          :class="
            saveFailed ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400'
          "
          role="status"
          aria-live="polite"
        >
          <AlertTriangle v-if="saveFailed" class="size-3.5" aria-hidden="true" />
          <Loader2 v-else-if="isSaving" class="size-3.5 animate-spin" aria-hidden="true" />
          {{ saveMessage }}
        </p>

        <div class="ml-auto flex items-center gap-2">
          <button
            v-if="!isDefaultLayout"
            type="button"
            class="flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            @click="reset()"
          >
            <RotateCcw class="size-3.5" aria-hidden="true" />
            {{ __('Reset layout') }}
          </button>

          <button
            type="button"
            class="rounded-md border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
            @click="query.refetch()"
          >
            {{ __('Refresh') }}
          </button>
        </div>
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
              ? 'ring-2 ring-accent ring-offset-2 dark:ring-offset-slate-950'
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
            @move="move(panel.widget.id, $event)"
            @toggle="toggle(panel.widget.id)"
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

            <NativeWidgetBody v-else :id="panel.widget.id" :admin-url="data.site.adminUrl" />
          </PanelCard>
        </li>
      </ul>

      <section
        v-if="hiddenPanels.length > 0"
        class="mt-5 rounded-lg border border-dashed border-slate-300 p-3 dark:border-slate-700"
      >
        <h2 class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
          {{ __('Hidden panels') }}
        </h2>

        <ul class="mt-2 flex flex-wrap gap-2">
          <li v-for="panel in hiddenPanels" :key="panel.widget.id">
            <button
              type="button"
              class="flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
              @click="toggle(panel.widget.id)"
            >
              <Eye class="size-3.5" aria-hidden="true" />
              {{ panel.widget.title }}
            </button>
          </li>
        </ul>
      </section>
    </template>
  </section>
</template>
