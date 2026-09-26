<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { Search, CornerDownLeft } from 'lucide-vue-next'
import { useQuery } from '@tanstack/vue-query'

import { rest } from '@/services/rest'
import type { SearchResult } from '@/types/api'
import { useAppStore } from '@/stores'
import { __, _n, sprintf } from '@/utils/i18n'

const MIN_TERM_LENGTH = 2
const DEBOUNCE_MS = 180

const app = useAppStore()
const term = ref('')
const debounced = ref('')
const activeIndex = ref(0)

let timer: ReturnType<typeof setTimeout> | null = null

const query = useQuery({
  queryKey: ['admin-suite', 'search', debounced],
  queryFn: ({ signal }) => rest.search(debounced.value, signal),
  enabled: () => debounced.value.trim().length >= MIN_TERM_LENGTH,
  staleTime: 30_000,
})

const isReady = computed(() => debounced.value.trim().length >= MIN_TERM_LENGTH)

const results = computed<SearchResult[]>(() => {
  const groups = query.data.value?.groups
  if (!groups) return []
  return Object.values(groups)
    .flat()
    .filter((r): r is SearchResult => Boolean(r))
})

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  if (timer) clearTimeout(timer)
})

function onInput(): void {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => {
    debounced.value = term.value
    activeIndex.value = 0
  }, DEBOUNCE_MS)
}

function onKeydown(event: KeyboardEvent): void {
  const isPaletteShortcut = (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k'

  if (isPaletteShortcut) {
    event.preventDefault()
    app.togglePalette()
    return
  }

  if (!app.paletteOpen) return

  if (event.key === 'Escape') {
    event.preventDefault()
    app.togglePalette(false)
  } else if (event.key === 'ArrowDown') {
    event.preventDefault()
    activeIndex.value = Math.min(activeIndex.value + 1, Math.max(results.value.length - 1, 0))
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = Math.max(activeIndex.value - 1, 0)
  } else if (event.key === 'Enter') {
    const target = results.value[activeIndex.value]
    if (target) {
      event.preventDefault()
      window.location.href = target.url
    }
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="app.paletteOpen"
      class="fixed inset-0 z-50 flex items-start justify-center bg-slate-950/50 p-4 pt-24 backdrop-blur-sm"
      @click.self="app.togglePalette(false)"
    >
      <div
        class="w-full max-w-xl overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
        role="dialog"
        aria-modal="true"
        :aria-label="__('Command palette')"
      >
        <div class="flex items-center gap-2 border-b border-slate-200 px-4 dark:border-slate-700">
          <Search class="size-4 shrink-0 text-slate-400" aria-hidden="true" />
          <input
            v-model="term"
            type="search"
            :placeholder="__('Search posts, media, settings, users…')"
            class="w-full bg-transparent py-3 text-sm outline-none placeholder:text-slate-400 dark:text-slate-100"
            @input="onInput"
          />
          <kbd
            class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-500 dark:border-slate-600 dark:text-slate-400"
            >esc</kbd
          >
        </div>

        <ul class="max-h-80 overflow-y-auto p-2">
          <li v-if="!isReady" class="px-3 py-6 text-center text-sm text-slate-500">
            {{
              sprintf(
                _n('Type at least %d character.', 'Type at least %d characters.', MIN_TERM_LENGTH),
                MIN_TERM_LENGTH,
              )
            }}
          </li>
          <li
            v-else-if="query.isLoading.value"
            class="px-3 py-6 text-center text-sm text-slate-500"
          >
            {{ __('Searching…') }}
          </li>
          <li v-else-if="results.length === 0" class="px-3 py-6 text-center text-sm text-slate-500">
            {{ sprintf(__('No matches for “%s”.'), debounced) }}
          </li>
          <template v-else>
            <li v-for="(item, index) in results" :key="`${item.type}-${item.id}`">
              <a
                :href="item.url"
                class="flex items-center justify-between rounded-lg px-3 py-2 text-sm"
                :class="
                  index === activeIndex
                    ? 'bg-indigo-50 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-100'
                    : 'text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800'
                "
                @mouseenter="activeIndex = index"
              >
                <span class="truncate">{{ item.label }}</span>
                <span class="ml-3 flex shrink-0 items-center gap-2 text-xs text-slate-400">
                  <span>{{ item.sub }}</span>
                  <CornerDownLeft v-if="index === activeIndex" class="size-3" aria-hidden="true" />
                </span>
              </a>
            </li>
          </template>
        </ul>
      </div>
    </div>
  </Teleport>
</template>
