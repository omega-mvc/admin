<script setup lang="ts">
import { computed } from 'vue'

import { FileText, Image, MessageSquare, Users, Globe } from 'lucide-vue-next'

import type { DashboardCounts } from '@/types/api'
import type { FunctionalComponent } from 'vue'

import { __, _n, sprintf } from '@/utils/i18n'

/**
 * The counters row. Each tile links to the screen that owns the number, so the
 * dashboard is a launchpad rather than a dead-end summary.
 *
 * Every label goes through the same `__()` the PHP side uses. Panel titles need
 * none of this: they arrive from the dashboard endpoint already translated,
 * because WordPress is the one that named them.
 */
const props = defineProps<{
  counts: DashboardCounts
  adminUrl: string
}>()

interface Tile {
  key: string
  label: string
  value: number
  sub: string
  href: string
  icon: FunctionalComponent
}

const tiles = computed<Tile[]>(() => {
  const counts = props.counts
  const url = props.adminUrl

  return [
    {
      key: 'posts',
      label: __('Posts'),
      value: counts.posts,
      sub: sprintf(_n('%s draft', '%s drafts', counts.postsDraft), counts.postsDraft),
      href: `${url}edit.php?post_type=post`,
      icon: FileText,
    },
    {
      key: 'pages',
      label: __('Pages'),
      value: counts.pages,
      sub: __('Published and draft'),
      href: `${url}edit.php?post_type=page`,
      icon: Globe,
    },
    {
      key: 'media',
      label: __('Media'),
      value: counts.media,
      sub: __('Attachments'),
      href: `${url}upload.php`,
      icon: Image,
    },
    {
      key: 'commentsPending',
      label: __('Comments'),
      value: counts.commentsPending,
      sub: counts.commentsPending === 0 ? __('None pending') : __('Awaiting moderation'),
      href: `${url}edit-comments.php`,
      icon: MessageSquare,
    },
    {
      key: 'users',
      label: __('Users'),
      value: counts.users,
      sub: __('All roles'),
      href: `${url}users.php`,
      icon: Users,
    },
  ]
})
</script>

<template>
  <!--
    The tiles are nested inside a dashboard panel, so they must size themselves
    from the panel's width, not the page's. `<main>` is also a `@container`, but
    a grid that reacted to it would be wrong the moment this panel stopped
    spanning the full width — hence the local container.
  -->
  <div class="@container">
    <dl class="grid grid-cols-1 gap-3 @xs:grid-cols-2 @lg:grid-cols-3 @4xl:grid-cols-5">
      <div
        v-for="tile in tiles"
        :key="tile.key"
        class="rounded-md border border-line p-3 transition-colors hover:border-line"
      >
        <dt
          class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-ink-muted"
        >
          <component :is="tile.icon" class="size-3.5 shrink-0" aria-hidden="true" />
          {{ tile.label }}
        </dt>

        <dd>
          <a
            :href="tile.href"
            class="mt-1 block text-2xl font-semibold tabular-nums hover:text-accent"
          >
            {{ tile.value }}
            <span class="sr-only">{{ tile.label }}</span>
          </a>

          <p class="mt-0.5 truncate text-xs text-ink-muted">{{ tile.sub }}</p>
        </dd>
      </div>
    </dl>
  </div>
</template>
