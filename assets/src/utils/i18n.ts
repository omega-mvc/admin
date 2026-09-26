/**
 * WordPress string translation, without a second i18n library.
 *
 * Core registers the `wp-i18n` script, `Enqueue` declares it as a dependency of
 * the entry module, and `wp_set_script_translations()` attaches the JSON
 * catalogue WordPress generates, so `window.wp.i18n.__()` is the same `__()` the
 * PHP side calls. The suite therefore translates through WordPress instead of
 * shipping a second implementation of gettext, and a translator works on one
 * string table rather than two.
 *
 * `sprintf()` is implemented here rather than wrapped, because core's `wp-i18n`
 * build does not export it: its export object is `setLocaleData`, `getLocaleData`,
 * `resetLocaleData`, `subscribe`, `__`, `_x`, `_n`, `_nx`, `isRTL` and
 * `hasTranslation`, and wrapping it would wrap a function that is not there. The
 * npm package `@wordpress/i18n` does export one, but it would be a second copy of
 * a runtime core already enqueues. The local version covers the conversions the
 * suite uses — `%s`, `%d`, `%i`, positional `%1$s`, `%%` — and leaves every other
 * token exactly as it found it, so an unsupported conversion stays visible instead
 * of quietly rendering as `NaN`.
 *
 * Every export is total. With no `window.wp.i18n` — a stripped build, or a page
 * where the dependency was dropped — the source string comes back unchanged and
 * `_n` falls back to the `count === 1` rule, with one console warning so the
 * real cause stays visible. A missing translation script must never be the
 * reason the panel renders blank.
 */

/**
 * The part of `window.wp.i18n` the suite uses, with the PHP signatures.
 *
 * Declared here rather than pulled from a `@wordpress/*` types package so the
 * suite needs no type dependency to describe an object core already provides.
 */
interface WpI18n {
  __(text: string, domain?: string): string
  _x(text: string, context: string, domain?: string): string
  _n(single: string, plural: string, count: number, domain?: string): string
  _nx(single: string, plural: string, count: number, context: string, domain?: string): string
  isRTL(): boolean
}

declare global {
  interface Window {
    wp?: { i18n?: WpI18n }
  }
}

/**
 * The plugin's text domain, and the default for every call.
 *
 * Matches the `Text Domain` plugin header and `Enqueue::TEXT_DOMAIN`, so a
 * string only has to name a domain when it is meant to come from core's
 * catalogue instead — `_x( 'Post', 'post type' )` is ambiguous on its own, and
 * core disambiguates its own copy in `'default'`.
 */
const DOMAIN = 'admin-suite'

let warned = false

/**
 * Read `window.wp.i18n`, warning once if it is not there.
 *
 * Read on every call rather than captured at module load: `wp-i18n` is a
 * separate script, and a module that snapshotted it at evaluation time would
 * quietly fall back to English for the rest of the session if the load order
 * ever changed.
 */
function i18n(): WpI18n | undefined {
  const found = typeof window === 'undefined' ? undefined : window.wp?.i18n

  if (found === undefined && !warned) {
    warned = true
    console.warn(
      'admin-suite: window.wp.i18n is missing, so strings fall back to English. ' +
        'Check that `wp-i18n` is still declared in Enqueue::SCRIPT_DEPS.',
    )
  }

  return found
}

/** Translate a string. */
export function __(text: string, domain: string = DOMAIN): string {
  return i18n()?.__(text, domain) ?? text
}

/** Translate a string that needs a disambiguating context. */
export function _x(text: string, context: string, domain: string = DOMAIN): string {
  return i18n()?._x(text, context, domain) ?? text
}

/** Translate a string, picking the plural form `count` calls for. */
export function _n(single: string, plural: string, count: number, domain: string = DOMAIN): string {
  return i18n()?._n(single, plural, count, domain) ?? (count === 1 ? single : plural)
}

/** `_n` for strings that also need a disambiguating context. */
export function _nx(
  single: string,
  plural: string,
  count: number,
  context: string,
  domain: string = DOMAIN,
): string {
  return i18n()?._nx(single, plural, count, context, domain) ?? (count === 1 ? single : plural)
}

/**
 * The conversions the suite uses. Everything else is left alone on purpose.
 *
 * Guessing at `%f` and rendering a plausible-looking number is worse than leaving
 * the token in place: the first is invisible until someone notices the wrong
 * figure, the second is visible immediately.
 */
const CONVERSION = /%(%)|%(?:(\d+)\$)?([sdi])/g

/**
 * Interpolate arguments into a translated string, like PHP's `sprintf()`.
 *
 * The placeholders travel inside the translated string, so a translator stays free
 * to move `%s` to the front for a language that puts the count last. A template
 * literal would pin the order to English and the translation would come out wrong
 * rather than merely stiff, which is the failure this avoids.
 *
 * Positional and sequential arguments mix the way PHP allows them to: a
 * positional token reads its argument by index, and a sequential one takes the
 * next argument not already claimed by position, in the order the tokens appear.
 */
export function sprintf(format: string, ...args: Array<string | number>): string {
  let next = 0

  return format.replace(CONVERSION, (token, literal, position, type) => {
    if (literal !== undefined) {
      return '%'
    }

    const index = position === undefined ? next++ : Number(position) - 1
    const value = args[index]

    if (value === undefined) {
      return token
    }

    if (type === 's') {
      return String(value)
    }

    const numeric = Number(value)

    return Number.isFinite(numeric) ? String(Math.trunc(numeric)) : String(value)
  })
}

/** Whether the active locale is written right to left. */
export function isRTL(): boolean {
  return i18n()?.isRTL() ?? false
}

/**
 * The locale `Intl` should format dates and numbers in.
 *
 * Deliberately WordPress's locale and not the browser's: an administrator whose
 * dashboard is in Italian should read Italian dates even when their browser asks
 * for English. It is `determine_locale()` — the same function
 * `wp_set_script_translations()` names the catalogue files after — so the
 * translated strings and the formatted dates cannot disagree about which
 * language this is.
 *
 * @returns A BCP 47 tag, or `undefined` when there is no bootstrap so `Intl`
 *           uses the runtime default instead of being handed `undefined`.
 */
export function locale(): string | undefined {
  const raw = typeof window === 'undefined' ? undefined : window.ADMIN_SUITE_BOOTSTRAP?.locale

  return raw === undefined || raw === '' ? undefined : raw.replace(/_/g, '-')
}
