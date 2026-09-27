import { computed, ref } from 'vue'

import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

import type { DashboardResponse, DashboardWidget, WidgetLayoutEntry } from '@/types/api'
import { isPinnedPanel } from '@/components/dashboard/panels'
import { rest } from '@/services/rest'

/** Shared with the native widget queries so both live in one cache namespace. */
export const dashboardKey = ['admin-suite', 'dashboard'] as const

/** Cache key for one native widget's captured HTML. */
export const nativeWidgetKey = (id: string) => ['admin-suite', 'dashboard-widget', id] as const

export interface Panel {
  widget: DashboardWidget
  visible: boolean
  collapsed: boolean
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

/**
 * Fold one panel down to its header, or back.
 *
 * Kept separate from `toggleEntry` because the two are different gestures on
 * different fields: one removes the panel from the dashboard entirely, the
 * other only hides its body while the panel stays put.
 */
export function toggleCollapsedEntry(
  entries: readonly WidgetLayoutEntry[],
  id: string,
): WidgetLayoutEntry[] {
  return entries.map((entry) =>
    entry.id === id ? { ...entry, collapsed: !entry.collapsed } : entry,
  )
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

      return widget ? [{ widget, visible: entry.visible, collapsed: entry.collapsed }] : []
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
   *
   * A pinned panel -- the welcome panel -- is a wall rather than a destination.
   * It does not move at all, and the panel directly under it cannot swap
   * upward past it, so a scan heading that way stops there and reports no
   * target. Returning -1 is what both disables the up arrow and refuses the
   * move, because `canMove` and `move` are this function's only callers, so the
   * greyed-out button and the refused gesture cannot drift apart.
   *
   * The wall applies to visible panels, like the rest of the scan. A hidden
   * welcome panel is off the dashboard and must not freeze the panel above it,
   * or hiding it would leave a widget at the top of the grid with a disabled up
   * arrow and other widgets visibly above it in the layout.
   */
  function targetIndex(id: string, delta: number): number {
    const list = entries.value
    const from = list.findIndex((entry) => entry.id === id)

    if (from === -1 || isPinnedPanel(id)) {
      return -1
    }

    for (let to = from + delta; to >= 0 && to < list.length; to += delta) {
      const entry = list[to]

      if (!entry || !entry.visible) {
        continue
      }

      return isPinnedPanel(entry.id) ? -1 : to
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

  /**
   * The drag has to honour the same wall as the arrows, or the pin would only
   * be true of the buttons: dragging the welcome panel, or dropping anything
   * onto its slot, is refused here for exactly the reason `targetIndex` stops.
   * Without this the up arrow would be greyed out while the drag happily put a
   * panel above it.
   */
  function reorder(id: string, targetId: string): void {
    if (isPinnedPanel(id) || isPinnedPanel(targetId)) {
      return
    }

    commit(reorderEntries(entries.value, id, targetId))
  }

  function toggle(id: string): void {
    commit(toggleEntry(entries.value, id))
  }

  function toggleCollapsed(id: string): void {
    commit(toggleCollapsedEntry(entries.value, id))
  }

  function reset(): void {
    commit(widgets.value.map((widget) => ({ id: widget.id, visible: true, collapsed: false })))
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
    toggleCollapsed,
    reset,
  }
}
