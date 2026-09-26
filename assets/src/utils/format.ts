/**
 * Small display formatters.
 *
 * WordPress sends ISO 8601 strings already in the site's timezone, so they are
 * parsed as-is and rendered in the visitor's locale. An unparseable or absent
 * value degrades to an em dash rather than "Invalid Date".
 */

const EMPTY = '—'

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })

const ABSOLUTE = new Intl.DateTimeFormat(undefined, {
  dateStyle: 'medium',
  timeStyle: 'short',
})

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
