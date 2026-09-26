/**
 * Small display formatters.
 *
 * WordPress sends ISO 8601 strings already in the site's timezone, so they are * parsed as-is and rendered in the admin's locale. An unparseable or absent
 * value degrades to an em dash rather than "Invalid Date".
 *
 * The locale is WordPress's, not the browser's: an administrator whose dashboard
 * is in Italian should read Italian dates even if their browser asks for
 * English. It is readable at module-evaluation time because `Enqueue` emits the
 * bootstrap inline, ahead of the entry module.
 */

import { locale } from './i18n'

const EMPTY = '—'

/**
 * Build a formatter, falling back to the runtime default on an unusable tag.
 *
 * `determine_locale()` is filterable and reads a value out of user meta, so it
 * can hold anything, and `new Intl.DateTimeFormat()` throws a `RangeError` on a
 * language tag it does not recognise. A bad locale must not be able to blank the
 * dashboard, so the failure is absorbed here rather than at the call site.
 */
function safeFormat<T>(build: (tag: string | undefined) => T): T {
  const tag = locale()

  if (tag !== undefined) {
    try {
      return build(tag)
    } catch {
      // Unusable language tag. The runtime default below is the safe answer.
    }
  }

  return build(undefined)
}

const RELATIVE = safeFormat((tag) => new Intl.RelativeTimeFormat(tag, { numeric: 'auto' }))

const ABSOLUTE = safeFormat(
  (tag) =>
    new Intl.DateTimeFormat(tag, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }),
)

function toDate(iso: string): Date | null {
  if (iso === '') {
    return null
  }

  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? null : date
}

/** "5 minutes ago", or the absolute date once it is no longer recent. */
export function relativeTime(iso: string): string {
  const date = toDate(iso)

  if (date === null) {
    return EMPTY
  }

  const seconds = Math.round((date.getTime() - Date.now()) / 1000)
  const abs = Math.abs(seconds)

  if (abs < 45) {
    return RELATIVE.format(0, 'second')
  }

  if (abs < 3_600) {
    return RELATIVE.format(Math.round(seconds / 60), 'minute')
  }

  if (abs < 86_400) {
    return RELATIVE.format(Math.round(seconds / 3_600), 'hour')
  }

  if (abs < 2_592_000) {
    return RELATIVE.format(Math.round(seconds / 86_400), 'day')
  }

  if (abs < 31_536_000) {
    return RELATIVE.format(Math.round(seconds / 2_592_000), 'month')
  }

  return ABSOLUTE.format(date)
}

/** The unabbreviated timestamp, for tooltips. */
export function absoluteTime(iso: string): string {
  const date = toDate(iso)

  return date === null ? EMPTY : ABSOLUTE.format(date)
}
