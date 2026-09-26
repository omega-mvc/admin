<script setup lang="ts">
import { MessageSquare, PencilLine } from 'lucide-vue-next'

import type { ActivityEntry } from '@/types/api'
import { absoluteTime, relativeTime } from '@/utils/format'

defineProps<{ entries: ActivityEntry[] }>()
</script>

<template>
  <ul v-if="entries.length > 0" class="space-y-3">
    <li v-for="entry in entries" :key="entry.id" class="flex items-start gap-2.5 text-sm">
      <span
        class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"
      >
        <MessageSquare v-if="entry.type === 'comment'" class="size-3" aria-hidden="true" />
        <PencilLine v-else class="size-3" aria-hidden="true" />
      </span>

      <div class="min-w-0 flex-1">
        <p class="text-slate-700 dark:text-slate-300">
          <span v-if="entry.actor" class="font-medium">{{ entry.actor }}</span>
          <span v-if="entry.summary"> {{ entry.summary }}</span>
          <span v-if="!entry.actor && !entry.summary" class="text-slate-500">
            Recorded activity
          </span>
        </p>

        <p
          class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400"
          :title="entry.subject"
        >
          <span v-if="entry.subject">{{ entry.subject }} · </span>
          <time v-if="entry.time" :datetime="entry.time" :title="absoluteTime(entry.time)">
            {{ relativeTime(entry.time) }}
          </time>
        </p>
      </div>
    </li>
  </ul>

  <p v-else class="py-4 text-center text-sm text-slate-500 dark:text-slate-400">
    Nothing has happened yet. WordPress records activity here as you work.
  </p>
</template>
