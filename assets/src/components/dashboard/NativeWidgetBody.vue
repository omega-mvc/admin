<script setup lang="ts">
import { computed } from 'vue'

import { useQuery } from '@tanstack/vue-query'

import type { NativeWidgetResponse } from '@/types/api'
import { nativeWidgetKey } from '@/composables/useDashboard'
import { rest } from '@/services/rest'
import { __ } from '@/utils/i18n'

const props = defineProps<{
  id: string
  adminUrl: string
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
 * A form inside the widget posts to the current document, which is the SPA
 * shell — WordPress would never see the submission. The native dashboard still
 * handles it, so offer that as the way out.
 */
const hasForm = computed(() => /<form[\s>]/i.test(html.value))
</script>

<template>
  <div v-if="widget.isLoading.value" class="py-6 text-center text-sm text-slate-500">
    {{ __('Loading widget…') }}
  </div>

  <div
    v-else-if="widget.isError.value"
    class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
  >
    <p class="font-medium">{{ __('This widget could not be rendered.') }}</p>
    <p class="mt-1 text-xs opacity-80">{{ widget.error.value?.message }}</p>
  </div>

  <template v-else>
    <!--
      Deliberate: the trust boundary is documented above. This is the same
      markup, from the same PHP callback, for the same user that WordPress
      renders on the native dashboard.
    -->
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div v-if="html !== ''" class="suite-native-widget text-sm" v-html="html" />

    <p v-else class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
      {{ __('This widget produced no output outside wp-admin.') }}
    </p>

    <p
      v-if="hasScripts"
      class="mt-3 rounded-md bg-slate-50 p-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"
    >
      {{ __('Scripts in this widget do not run inside the dashboard, so it may be incomplete.') }}
    </p>

    <p
      v-if="hasForm"
      class="mt-2 rounded-md bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
    >
      {{ __('This widget has a form, which WordPress can only submit from the native dashboard.') }}
      <a :href="`${adminUrl}index.php`" class="underline">
        {{ __('Open the native dashboard') }}
      </a>
    </p>
  </template>
</template>
