/**
 * Throwaway test for the pure layout helpers in useDashboard.ts.
 * Not part of the build; run via the esbuild bundle command in the shell.
 */
import assert from 'node:assert/strict'

import { moveEntry, reorderEntries, toggleEntry } from '../src/composables/useDashboard'
import type { WidgetLayoutEntry } from '../src/types/api'

const layout = (ids: string[]): WidgetLayoutEntry[] =>
  ids.map((id) => ({ id, visible: true }))

const ids = (list: WidgetLayoutEntry[]) => list.map((e) => e.id).join(',')

/* moveEntry */
assert.equal(ids(moveEntry(layout(['a', 'b', 'c']), 'a', 1)), 'b,a,c')
assert.equal(ids(moveEntry(layout(['a', 'b', 'c']), 'c', -1)), 'a,c,b')
// Clamped at both ends.
assert.equal(ids(moveEntry(layout(['a', 'b', 'c']), 'a', -1)), 'a,b,c')
assert.equal(ids(moveEntry(layout(['a', 'b', 'c']), 'c', 1)), 'a,b,c')
// Unknown id is a no-op, not a corruption.
assert.equal(ids(moveEntry(layout(['a', 'b']), 'zz', 1)), 'a,b')

/* reorderEntries: drop the source at the target's current index */
assert.equal(ids(reorderEntries(layout(['a', 'b', 'c']), 'c', 'a')), 'c,a,b')
assert.equal(ids(reorderEntries(layout(['a', 'b', 'c']), 'a', 'c')), 'b,c,a')
// Dropping onto itself changes nothing.
assert.equal(ids(reorderEntries(layout(['a', 'b', 'c']), 'b', 'b')), 'a,b,c')
// Unknown target is a no-op.
assert.equal(ids(reorderEntries(layout(['a', 'b']), 'a', 'zz')), 'a,b')

/*
 * Crosses hidden panels. moveEntry() is a raw index-delta move, so this is the
 * delta useDashboard.move() would compute after targetIndex() skipped the
 * hidden entry at index 1 and returned 2.
 */
const withHidden = [
  { id: 'a', visible: true },
  { id: 'b', visible: false },
  { id: 'c', visible: true },
]
assert.equal(ids(moveEntry(withHidden, 'a', 2)), 'b,c,a')
// The raw +1 lands on the hidden entry's own slot, which is the helper's contract.
assert.equal(ids(moveEntry(withHidden, 'a', 1)), 'b,a,c')

/* toggleEntry */
const toggled = toggleEntry(withHidden, 'b')
assert.equal(toggled[1]?.visible, true)
assert.equal(toggled[0]?.visible, true, 'other entries untouched')
assert.equal(withHidden[1]?.visible, false, 'input array not mutated')

console.log('layout helpers OK')
