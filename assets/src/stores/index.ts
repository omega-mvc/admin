import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import type { SidebarState } from '@/types/api'
import { rest } from '@/services/rest'
import { useQuery } from '@tanstack/vue-query'

/** Global UI state: sidebar visibility, command palette, screen options. */
export const useAppStore = defineStore('app', () => {
  const sidebar = ref<SidebarState>('expanded')
  const paletteOpen = ref(false)
  const screenOptionsOpen = ref(false)
  const bootError = ref<string | null>(null)

  const sidebarCollapsed = computed(() => sidebar.value === 'collapsed')

  function toggleSidebar(): void {
    sidebar.value = sidebar.value === 'expanded' ? 'collapsed' : 'expanded'
  }

  function togglePalette(force?: boolean): void {
    paletteOpen.value = force ?? !paletteOpen.value
  }

  /**
   * The panel is rendered by `DashboardView`, not here, because the widget list
   * comes from `useDashboard()`. The button that owns it is in the toolbar in
   * `App.vue`, so the flag has to outlive the component that toggles it.
   */
  function toggleScreenOptions(force?: boolean): void {
    screenOptionsOpen.value = force ?? !screenOptionsOpen.value
  }

  function setBootError(message: string | null): void {
    bootError.value = message
  }

  return {
    sidebar,
    sidebarCollapsed,
    paletteOpen,
    screenOptionsOpen,
    bootError,
    toggleSidebar,
    togglePalette,
    toggleScreenOptions,
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
