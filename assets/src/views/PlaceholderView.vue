<script setup lang="ts">
import { useRoute } from 'vue-router'

import { __, sprintf } from '@/utils/i18n'

const route = useRoute()
</script>

<template>
  <section>
    <h1 class="text-xl font-semibold">{{ __('Not implemented yet') }}</h1>
    <p class="mt-1 text-sm text-slate-500">
      {{
        sprintf(
          __('No SPA view is registered for %s. Add a route in src/router/index.ts.'),
          route.path,
        )
      }}
    </p>
  </section>
</template>
