<script setup lang="ts">
import { FileText } from 'lucide-vue-next'

import type { RecentPost } from '@/types/api'
import { absoluteTime, relativeTime } from '@/utils/format'
import { __, _x, sprintf } from '@/utils/i18n'

defineProps<{ posts: RecentPost[] }>()

/**
 * The human label for a post status.
 *
 * A status arrives as a slug, so showing it with the dash swapped for a space
 * yields `in-review` and `auto-draft`: English-ish fragments no translator ever
 * saw, and `in-review` is not what WordPress calls it. `_x` with a `post status`
 * context is the same disambiguation core uses, because "Published" and "Draft"
 * are ordinary words that mean other things elsewhere in the admin.
 */
function statusLabel(status: string): string {
  switch (status) {
    case 'publish':
      return _x('Published', 'post status')
    case 'future':
      return _x('Scheduled', 'post status')
    case 'draft':
      return _x('Draft', 'post status')
    case 'pending':
      return _x('Pending Review', 'post status')
    case 'private':
      return _x('Private', 'post status')
    case 'trash':
      return _x('Trash', 'post status')
    case 'auto-draft':
      return _x('Auto Draft', 'post status')
    case 'inherit':
      return _x('Inherit', 'post status')
    default:
      return status.replace(/-/g, ' ')
  }
}

/** Status badge colours, keyed by post status. */
const badge: Record<string, string> = {
  publish: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  pending: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
  future: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
}
</script>

<template>
  <ul v-if="posts.length > 0" class="space-y-2">
    <li
      v-for="post in posts"
      :key="post.id"
      class="flex items-start gap-3 rounded-md p-1.5 hover:bg-slate-50 dark:hover:bg-slate-800/60"
    >
      <img
        v-if="post.thumb"
        :src="post.thumb"
        alt=""
        class="size-10 shrink-0 rounded object-cover"
        loading="lazy"
      />
      <span
        v-else
        class="flex size-10 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500"
      >
        <FileText class="size-5" aria-hidden="true" />
      </span>

      <div class="min-w-0 flex-1">
        <a
          :href="post.url"
          class="block truncate text-sm font-medium hover:text-accent"
          :title="post.title"
        >
          {{ post.title === '' ? sprintf(__('Post #%s'), post.id) : post.title }}
        </a>

        <p
          class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400"
        >
          <span
            class="rounded px-1.5 py-0.5 font-medium capitalize"
            :class="
              badge[post.status] ??
              'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
            "
          >
            {{ statusLabel(post.status) }}
          </span>

          <span class="truncate">{{ post.author }}</span>

          <time :datetime="post.date" :title="absoluteTime(post.date)">
            {{ relativeTime(post.date) }}
          </time>
        </p>
      </div>
    </li>
  </ul>

  <p v-else class="py-4 text-center text-sm text-slate-500 dark:text-slate-400">
    {{ __('No posts yet.') }}
  </p>
</template>
