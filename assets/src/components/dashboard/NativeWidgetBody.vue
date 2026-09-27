<script setup lang="ts">
import { computed, watch } from 'vue'

import { useQuery } from '@tanstack/vue-query'

import type { NativeWidgetResponse } from '@/types/api'
import { WELCOME_PANEL_ID } from '@/components/dashboard/panels'
import { nativeWidgetKey } from '@/composables/useDashboard'
import { rest } from '@/services/rest'
import { __ } from '@/utils/i18n'

const props = defineProps<{
  id: string
}>()

/**
 * Raised when the widget asks to be taken off the dashboard. Not core's
 * `?welcome=0` dismiss: nothing is written to `show_welcome_panel`, the panel
 * simply leaves the suite's own layout like any other widget.
 */
const emit = defineEmits<{
  dismiss: []
}>()

/**
 * Renders one native dashboard widget.
 *
 * Trust boundary: `v-html` is deliberate and the HTML is not sanitised. It is
 * produced by the very same PHP callback WordPress runs when it renders the
 * native dashboard, for the same logged-in user, so it is no less trusted than
 * wp-admin itself. Sanitising it would silently mangle the markup third-party
 * widgets expect. Native widget callbacks that echo untrusted post content
 * escaping is their own contract, exactly as on the native screen.
 */
const widget = useQuery<NativeWidgetResponse>({
  queryKey: nativeWidgetKey(props.id),
  queryFn: ({ signal }) => rest.nativeWidget(props.id, signal),
  staleTime: 5 * 60_000,
  retry: false,
})

const html = computed(() => widget.data.value?.html ?? '')

/**
 * `v-html` inserts markup through innerHTML, so <script> never runs. Widgets
 * that depend on their own JS (Events and News fetches its feed) therefore come
 * up empty rather than half-working.
 */
const hasScripts = computed(() => /<script[\s>]/i.test(html.value))

/**
 * The re-entry points core publishes for the dashboard's own JavaScript.
 *
 * Read off `window` with a local cast rather than declared globally:
 * `utils/i18n.ts` already declares `Window.wp` for the i18n case, and a second
 * declaration of the same property would have to repeat its type verbatim.
 */
interface DashboardGlobals {
  quickPressLoad?: () => void
  wp?: { communityEvents?: { init?: () => void } }
}

/**
 * Rebind the handlers of the widgets that need JavaScript of their own.
 *
 * `wp-admin/js/dashboard.js` binds both of them at DOM ready, which is before
 * this grid exists: the markup arrives from the REST endpoint and is injected
 * here afterwards, so the selectors the bindings look for match nothing.
 * `flush: 'post'` is what guarantees the injected nodes are already in the
 * document by the time this runs.
 *
 * The two re-entry points are not alike, and the difference decides when each
 * may run. `wp.communityEvents.init()` sets its own `initialized` flag and
 * returns early on every later call. `quickPressLoad()` has no such flag: it
 * binds `submit` on `#quick-press` on every call, so a second call on a form
 * that is already bound saves the draft twice. It therefore runs only when the
 * markup carrying that form is the one just injected, which is also the only
 * moment the nodes it would bind to are new.
 */
function armCoreHandlers(): void {
  const globals = window as unknown as DashboardGlobals

  if (html.value.includes('id="quick-press"')) {
    globals.quickPressLoad?.()
  }

  globals.wp?.communityEvents?.init?.()
}

watch(html, (value) => {
  if (value !== '') {
    armCoreHandlers()
  }
})

/**
 * Take the welcome panel off the dashboard without reloading.
 *
 * Its "Dismiss" link carries `href="?welcome=0"`, which navigates: the page
 * reloads and core writes `show_welcome_panel = 0`, a per-user setting the
 * suite never reads back. Two things are wrong with that here. It is slower,
 * and it hides the panel in a store the suite does not use, so the Screen
 * Options box — which is built from `admin_suite_dashboard_layout` — would
 * still list the panel as visible while the page had it gone.
 *
 * Intercepting the click and raising `dismiss` instead sends the panel through
 * the same `toggle` every other widget uses, so it leaves the grid and turns up
 * in the box, where the pill puts it back.
 *
 * Scoped to this widget's id on purpose. The class is core's, but only this
 * widget can contain the link, and the guard keeps every other native widget on
 * the same code path without a per-widget special case.
 */
function onClick(event: MouseEvent): void {
  if (props.id !== WELCOME_PANEL_ID) {
    return
  }

  const target = event.target

  if (!(target instanceof Element) || !target.closest('.welcome-panel-close')) {
    return
  }

  event.preventDefault()

  emit('dismiss')
}
</script>

<template>
  <div v-if="widget.isLoading.value" class="py-6 text-center text-sm text-ink-muted">
    {{ __('Loading widget…') }}
  </div>

  <div
    v-else-if="widget.isError.value"
    class="rounded-md border border-negative bg-negative-soft p-3 text-sm text-negative"
  >
    <p class="font-medium">{{ __('This widget could not be rendered.') }}</p>
    <p class="mt-1 text-xs opacity-80">{{ widget.error.value?.message }}</p>
  </div>

  <template v-else>
    <!--
      The id and the `.inside` child reproduce the postbox that `wp_dashboard()`
      builds, and both are load-bearing rather than decorative. The dashboard's
      own JavaScript addresses a widget by them, and writes the reply to a save
      into `#dashboard_quick_press .inside`.
    -->
    <div v-if="html !== ''" :id="id" class="suite-native-widget text-sm" @click="onClick">
      <!--
        Deliberate: the trust boundary is documented above. This is the same
        markup, from the same PHP callback, for the same user that WordPress
        renders on the native dashboard.
      -->
      <!-- eslint-disable-next-line vue/no-v-html -->
      <div class="inside" v-html="html" />
    </div>

    <p v-else class="py-6 text-center text-sm text-ink-muted">
      {{ __('This widget produced no output outside wp-admin.') }}
    </p>

    <p v-if="hasScripts" class="mt-3 rounded-md bg-sunken p-2 text-xs text-ink-muted">
      {{ __('Scripts in this widget do not run inside the dashboard, so it may be incomplete.') }}
    </p>
  </template>
</template>
