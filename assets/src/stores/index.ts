import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import type { SidebarState } from '@/types/api'
import { rest } from '@/services/rest'
import { useQuery } from '@tanstack/vue-query'

/** Global UI state: sidebar visibility and command palette. */
export const useAppStore = defineStore('app', () => {
  const sidebar = ref<SidebarState>('expanded')
  const paletteOpen = ref(false)
  const bootError = ref<string | null>(null)

  const sidebarCollapsed = computed(() => sidebar.value === 'collapsed')

  function toggleSidebar(): void {
    sidebar.value = sidebar.value === 'expanded' ? 'collapsed' : 'expanded'
  }

  function togglePalette(force?: boolean): void {
    paletteOpen.value = force ?? !paletteOpen.value
  }

  function setBootError(message: string | null): void {
    bootError.value = message
  }

  return {
    sidebar,
    sidebarCollapsed,
    paletteOpen,
    bootError,
    toggleSidebar,
    togglePalette,
    setBootError,
  }
})

/** The menu tree, fetched once and cached by TanStack Query. */
export const useMenuStore = defineStore('menu', () => {
  const query = useQuery({
    queryKey: ['admin-suite', 'menu'],
    queryFn: ({ signal }) => rest.menu('view', signal),
    staleTime: 5 * 60_000,
  })

  return {
    items: computed(() => query.data.value?.items ?? []),
    isLoading: query.isLoading,
    error: query.error,
    refetch: query.refetch,
  }
})
