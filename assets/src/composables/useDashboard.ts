import { computed, ref } from 'vue'

import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

import type { DashboardResponse, DashboardWidget, WidgetLayoutEntry } from '@/types/api'
import { rest } from '@/services/rest'

/** Shared with the native widget queries so both live in one cache namespace. */
export const dashboardKey = ['admin-suite', 'dashboard'] as const

/** Cache key for one native widget's captured HTML. */
export const nativeWidgetKey = (id: string) => ['admin-suite', 'dashboard-widget', id] as const

export interface Panel {
  widget: DashboardWidget
  visible: boolean
}

function indexOfId(entries: readonly WidgetLayoutEntry[], id: string): number {
  return entries.findIndex((entry) => entry.id === id)
}

/** Move `id` by `delta` positions, clamped to the ends of the list. */
export function moveEntry(
  entries: readonly WidgetLayoutEntry[],
  id: string,
  delta: number,
): WidgetLayoutEntry[] {
  const next = [...entries]
  const from = indexOfId(next, id)

  if (from === -1) {
    return next
  }

  const to = from + delta

  if (to < 0 || to >= next.length) {
    return next
  }

  const [moved] = next.splice(from, 1)

  if (moved === undefined) {
    return next
  }

  next.splice(to, 0, moved)

  return next
}

/** Lift `id` out and drop it where `targetId` currently sits. */
export function reorderEntries(
  entries: readonly WidgetLayoutEntry[],
  id: string,
  targetId: string,
): WidgetLayoutEntry[] {
  const from = indexOfId(entries, id)
  // Measured before the removal below: the target keeps its own slot and the
  // dragged entry moves into it, which is how a drop reads to the user.
  const to = indexOfId(entries, targetId)

  if (id === targetId || from === -1 || to === -1) {
    return [...entries]
  }

  const next = [...entries]
  const [moved] = next.splice(from, 1)

  if (moved === undefined) {
    return next
  }

  next.splice(to, 0, moved)

  return next
}

export function toggleEntry(
  entries: readonly WidgetLayoutEntry[],
  id: string,
): WidgetLayoutEntry[] {
  return entries.map((entry) => (entry.id === id ? { ...entry, visible: !entry.visible } : entry))
}

/**
 * Dashboard data plus the layout editing state machine.
 *
 * The server's copy stays the source of truth. An edit is written to `draft`
 * immediately so the grid reacts without waiting for the round trip, and the
 * reconciled layout comes back from `POST /dashboard` to replace both the draft
 * and the cached payload. A failure drops the draft, which reverts the grid to
 * the last layout the server confirmed.
 */
export function useDashboard() {
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: dashboardKey,
    queryFn: ({ signal }) => rest.dashboard(signal),
    staleTime: 30_000,
  })

  /** `null` renders the server layout; an array is an edit in flight. */
  const draft = ref<WidgetLayoutEntry[] | null>(null)

  /**
   * Mutations are not serialised, so a slow reply could land after a newer edit
   * and undo it. Every commit takes a ticket and only the newest one settles.
   */
  let ticket = 0

  const save = useMutation({
    mutationFn: (input: { layout: WidgetLayoutEntry[]; ticket: number }) =>
      rest.saveDashboardLayout(input.layout),
    onSuccess: (result, input) => {
      if (input.ticket !== ticket) {
        return
      }

      queryClient.setQueryData<DashboardResponse>(dashboardKey, (previous) =>
        previous ? { ...previous, layout: result.layout } : previous,
      )

      draft.value = null
    },
    onError: (_error, input) => {
      if (input.ticket !== ticket) {
        return
      }

      draft.value = null
    },
  })

  const widgets = computed<DashboardWidget[]>(() => query.data.value?.widgets ?? [])

  const entries = computed<WidgetLayoutEntry[]>(() => draft.value ?? query.data.value?.layout ?? [])

  /**
   * Layout entries joined to their panel definition. Entries whose widget is
   * gone are dropped here; the server already does the same, so this is only a
   * guard against a stale cache.
   */
  const panels = computed<Panel[]>(() => {
    const byId = new Map(widgets.value.map((widget) => [widget.id, widget]))

    return entries.value.flatMap((entry) => {
      const widget = byId.get(entry.id)

      return widget ? [{ widget, visible: entry.visible }] : []
    })
  })

  const visiblePanels = computed(() => panels.value.filter((panel) => panel.visible))

  const hiddenPanels = computed(() => panels.value.filter((panel) => !panel.visible))

  const hiddenCount = computed(() => hiddenPanels.value.length)

  /** The server's default order: every panel, visible, in registration order. */
  const isDefaultLayout = computed(
    () =>
      entries.value.length === widgets.value.length &&
      entries.value.every((entry, index) => entry.id === widgets.value[index]?.id && entry.visible),
  )

  function commit(next: WidgetLayoutEntry[]): void {
    ticket += 1
    draft.value = next
    save.mutate({ layout: next, ticket })
  }

  /**
   * The index a move of `delta` would land on, skipping hidden panels so the
   * visible grid shifts by exactly one. -1 when the move is not possible.
   */
  function targetIndex(id: string, delta: number): number {
    const list = entries.value
    const from = list.findIndex((entry) => entry.id === id)

    if (from === -1) {
      return -1
    }

    for (let to = from + delta; to >= 0 && to < list.length; to += delta) {
      if (list[to]?.visible === true) {
        return to
      }
    }

    return -1
  }

  function canMove(id: string, delta: number): boolean {
    return targetIndex(id, delta) !== -1
  }

  function move(id: string, delta: number): void {
    const from = entries.value.findIndex((entry) => entry.id === id)
    const to = targetIndex(id, delta)

    if (from === -1 || to === -1) {
      return
    }

    commit(moveEntry(entries.value, id, to - from))
  }

  function reorder(id: string, targetId: string): void {
    commit(reorderEntries(entries.value, id, targetId))
  }

  function toggle(id: string): void {
    commit(toggleEntry(entries.value, id))
  }

  function reset(): void {
    commit(widgets.value.map((widget) => ({ id: widget.id, visible: true })))
  }

  return {
    query,
    panels,
    visiblePanels,
    hiddenPanels,
    hiddenCount,
    isDefaultLayout,
    isSaving: save.isPending,
    saveError: save.error,
    saveFailed: save.isError,
    canMove,
    move,
    reorder,
    toggle,
    reset,
  }
}
