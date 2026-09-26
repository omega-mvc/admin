/**
 * Throwaway test for the i18n wrapper in utils/i18n.ts.
 * Not part of the build; run via the esbuild bundle command in the shell.
 *
 * Runs on bare Node, so there is no `window` to begin with — which is the
 * strongest version of the "every export is total" contract: the first block
 * calls the whole surface with no DOM in sight and expects the English source
 * strings back rather than a ReferenceError.
 */
import assert from 'node:assert/strict'

import { __, _x, _n, _nx, isRTL, locale } from '../src/utils/i18n'

/*
 * The wrapper must complain about a missing catalogue exactly once per session,
 * no matter how many strings go through the fallback. Counting them is the only
 * way to catch a regression to once-per-call, which would flood the console of
 * a broken page and bury the one line that explains why.
 */
let warnings = 0
const realWarn = console.warn.bind(console)
console.warn = (...args: unknown[]): void => {
  warnings += 1
  realWarn(...args)
}

const setWindow = (value: unknown): void => {
  Object.defineProperty(globalThis, 'window', { value, writable: true, configurable: true })
}

/* ---- no window at all ---- */
assert.equal(typeof window, 'undefined', 'precondition: this file runs on bare Node')

assert.equal(__('Hello'), 'Hello')
assert.equal(_x('Post', 'post type'), 'Post')
assert.equal(_n('%d item', '%d items', 1), '%d item')
assert.equal(_n('%d item', '%d items', 3), '%d items')
// gettext treats 0 as plural, unlike a naive `count ? plural : singular`.
assert.equal(_n('%d item', '%d items', 0), '%d items', 'zero is plural')
assert.equal(_nx('%d item', '%d items', 1, 'list item'), '%d item')
assert.equal(isRTL(), false)
assert.equal(locale(), undefined)

/* ---- with a catalogue: every function has to reach wp.i18n ---- */
const calls: string[] = []
const fake = {
  __: (text: string, domain?: string): string => {
    calls.push(`__:${text}:${domain}`)
    return `[${text}]`
  },
  _x: (text: string, context: string, domain?: string): string => {
    calls.push(`_x:${text}:${context}:${domain}`)
    return `[${text}|${context}]`
  },
  _n: (single: string, plural: string, count: number, domain?: string): string => {
    calls.push(`_n:${count}:${domain}`)
    return count === 1 ? `[${single}]` : `[${plural}]`
  },
  _nx: (single: string, plural: string, count: number, context: string, domain?: string): string => {
    calls.push(`_nx:${count}:${context}:${domain}`)
    return count === 1 ? `[${single}]` : `[${plural}]`
  },
  isRTL: (): boolean => {
    calls.push('isRTL')
    return true
  },
}

setWindow({ ADMIN_SUITE_BOOTSTRAP: { locale: 'it_IT' }, wp: { i18n: fake } })

assert.equal(__('Hello'), '[Hello]')
assert.equal(_x('Post', 'post type'), '[Post|post type]')
assert.equal(_n('%d item', '%d items', 1), '[%d item]')
assert.equal(_n('%d item', '%d items', 5), '[%d items]')
assert.equal(_nx('%d item', '%d items', 5, 'list item'), '[%d items]')
assert.equal(isRTL(), true)

assert.deepEqual(
  calls,
  [
    '__:Hello:admin-suite',
    '_x:Post:post type:admin-suite',
    '_n:1:admin-suite',
    '_n:5:admin-suite',
    '_nx:5:list item:admin-suite',
    'isRTL',
  ],
  'every call reached the catalogue, defaulting to the plugin text domain',
)

/*
 * A string that means something different in core's catalogue has to be able to
 * say so: `_x( 'Post', 'post type' )` is ambiguous, and the disambiguation
 * matters to the translator, so the domain cannot be hardcoded to the plugin's.
 */
assert.equal(__('Post', 'default'), '[Post]')
assert.equal(calls.at(-1), '__:Post:default', 'an explicit domain reaches the catalogue')

/* ---- locale: WordPress underscores it, Intl wants dashes ---- */
assert.equal(locale(), 'it-IT', 'it_IT is not a tag Intl accepts')

/* ---- window present, dependency missing ---- */
setWindow({ ADMIN_SUITE_BOOTSTRAP: { locale: 'it_IT' } })
assert.equal(__('Hello'), 'Hello', 'falls back to the source string')
assert.equal(_n('%d item', '%d items', 1), '%d item')
assert.equal(isRTL(), false)
assert.equal(locale(), 'it-IT', 'locale does not depend on the catalogue being loaded')

/* ---- locale edge cases ---- */
setWindow({ ADMIN_SUITE_BOOTSTRAP: { locale: '' } })
assert.equal(locale(), undefined, 'an empty locale hands Intl the runtime default')

setWindow({})
assert.equal(locale(), undefined, 'a bootstrap without a locale is not an error')

setWindow({ wp: { i18n: fake } })
assert.equal(locale(), undefined, 'a catalogue without a bootstrap is not an error')

assert.equal(warnings, 1, 'the missing-catalogue warning is logged once, not once per string')

console.log('i18n wrapper OK')
