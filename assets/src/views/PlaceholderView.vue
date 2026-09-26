<script setup lang="ts">
import { useRoute } from 'vue-router'

const route = useRoute()
</script>

<template>
  <section>
    <h1 class="text-xl font-semibold">Not implemented yet</h1>
    <p class="mt-1 text-sm text-slate-500">
      No SPA view is registered for
      <code class="rounded bg-slate-200 px-1 dark:bg-slate-800">{{ route.path }}</code
      >. Add a route in
      <code class="rounded bg-slate-200 px-1 dark:bg-slate-800">src/router/index.ts</code>.
    </p>
  </section>
</template>
