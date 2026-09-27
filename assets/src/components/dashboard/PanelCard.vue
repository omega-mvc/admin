<script setup lang="ts">
import { useId } from 'vue'

import { ChevronUp, ChevronDown, RefreshCw, GripVertical } from 'lucide-vue-next'

import { __, sprintf } from '@/utils/i18n'

/**
 * Card chrome for one dashboard panel: heading, reorder and fold controls, and the slot
 * the panel body goes into.
 *
 * Pointer dragging is handled by the parent `<li>` (it owns `draggable` and the
 * drag state), so the grip here is decorative. Reordering is still reachable
 * from the keyboard through the two move buttons, which is the only path that
 * works without a pointer.
 */
const props = defineProps<{
  title: string
  upDisabled: boolean
  downDisabled: boolean
  /** Native panels are refetched on demand; SPA panels follow the dashboard query. */
  refreshable: boolean
  refreshing: boolean
  /**
   * Whether the panel is on the dashboard. Only ever `true` today, because the
   * grid renders `visiblePanels`, but the checkbox states it rather than
   * assuming it, so the card stays honest if it is ever reused elsewhere.
   */
  visible: boolean
  /**
   * Whether the show/hide checkbox is on screen at all. Driven by the same flag
   * that opens the Screen Options box, so the two appear and disappear together.
   */
  showToggle: boolean
  /**
   * Whether the card can be folded down to just its header. Off for the welcome
   * panel, which brings its own "Dismiss" cross: a fold control there would be a
   * second way of doing the same thing in the same corner.
   */
  collapsible: boolean
  /**
   * Whether the card can be reordered. Off for the welcome panel, which holds
   * its slot in the layout: it is not rendered with the move buttons at all,
   * rather than with them greyed out, because there is no arrangement the
   * buttons could produce.
   */
  movable: boolean
  /**
   * Whether the card is currently folded down to its header.
   *
   * Not local state: the flag is a field of the saved layout, so it survives a
   * reload and a failed write reverts the fold the same way it reverts a hide.
   */
  collapsed: boolean
}>()

const emit = defineEmits<{
  move: [delta: number]
  toggle: []
  fold: []
  refresh: []
}>()

/**
 * Binds `aria-expanded` to the region it controls, so the two cannot drift.
 */
const bodyId = useId()

const buttonClass =
  'rounded p-1 text-ink-faint hover:bg-sunken hover:text-ink disabled:pointer-events-none disabled:opacity-30'
</script>

<template>
  <!--
    `h-fit` while folded. The grid stretches every item to the tallest in its
    row, so `h-full` would pad the folded card back out to its neighbours'
    height and the fold would look like it had done nothing.
  -->
  <article
    class="group flex flex-col rounded-lg border border-line bg-panel shadow-sm"
    :class="collapsed ? 'h-fit' : 'h-full'"
  >
    <header
      class="flex items-center gap-2 px-3 py-2"
      :class="collapsed ? '' : 'border-b border-line'"
    >
      <GripVertical
        class="size-4 shrink-0 cursor-grab text-ink-faint group-hover:text-ink-faint"
        aria-hidden="true"
      />

      <h2 class="min-w-0 flex-1 truncate text-sm font-semibold">{{ props.title }}</h2>

      <!--
        Opacity is the only thing gating these: they stay in the tab order and
        become visible on focus, so the controls are usable without a pointer.
      -->
      <div
        class="flex items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100"
      >
        <button
          v-if="props.refreshable"
          type="button"
          :class="buttonClass"
          :aria-label="sprintf(__('Refresh %s'), props.title)"
          :disabled="props.refreshing"
          @click="emit('refresh')"
        >
          <RefreshCw
            class="size-4"
            :class="props.refreshing ? 'animate-spin' : ''"
            aria-hidden="true"
          />
        </button>
      </div>

      <!--
        Move up and move down, permanently visible, in core's own order, and
        immediately left of the fold control.

        These are the three buttons `postbox_toggle_div()` prints inside a single
        `handle-actions` row -- move up, move down, then show or hide panel --
        and core shows all three at once, with no hover involved. So the order
        here is core's order, and the fold control that follows is the same
        "Show or hide panel" button core prints third, at the same end of the
        row.

        They sit outside the hover group above for the reason the fold control
        does too: reordering is a decision, not a hover gesture, and a control
        that only exists under the pointer cannot be reached by touch, nor by
        keyboard without first discovering that it is there. That group is now
        left holding only refresh, which is a suite control with no core
        equivalent, and is a gesture rather than a decision.

        The labels are core's own strings: the three `screen-reader-text` spans
        inside the three `handle-actions` buttons at
        `wp-admin/includes/template.php` lines 1404, 1413 and 1423. They come
        from the catalogue WordPress translates the rest of the admin from, not
        from a second wording of it, which is why each carries the explicit
        `'default'` domain -- `__()` here would otherwise look them up in the
        plugin's own empty catalogue and leave them English forever.

        Hence no sprintf, which is a change from the two hover controls these
        replace: those interpolated the panel title as "Move *title* up" and
        "Move *title* down". Core's own buttons do not interpolate either, and
        the title sits right beside them either way, so the surroundings carry
        the context and the label says only what the control does. If two
        identically labelled buttons turn out to be ambiguous for someone
        navigating by label, the titles go back in, one line each.

        Both buttons are absent on the welcome panel, which holds its slot. They
        are removed rather than disabled, because `upDisabled` and
        `downDisabled` answer "is there anywhere to go from here", and on a
        pinned panel the honest answer is that no such place exists at all --
        two permanently greyed buttons would be decoration pretending to be
        controls. The panel directly under welcome is the other half of the same
        rule, and it is handled where the move itself is computed, in
        `targetIndex`, so the greyed-out state and the refused gesture are one
        fact and not two that can drift.
      -->
      <button
        v-if="props.movable"
        type="button"
        :class="buttonClass"
        :aria-label="__('Move up', 'default')"
        :disabled="props.upDisabled"
        @click="emit('move', -1)"
      >
        <ChevronUp class="size-4" aria-hidden="true" />
      </button>

      <button
        v-if="props.movable"
        type="button"
        :class="buttonClass"
        :aria-label="__('Move down', 'default')"
        :disabled="props.downDisabled"
        @click="emit('move', 1)"
      >
        <ChevronDown class="size-4" aria-hidden="true" />
      </button>

      <!--
        The fold control, at the right-hand end of the header, which is where
        core puts its own `handlediv` on a postbox. The show/hide checkbox
        follows it when the Screen Options box is open, so the fold button is
        not the outermost thing in the corner; the two keep a fixed order rather
        than depending on which one happens to be rendered. It sits outside the
        hover group above on purpose: folding a panel is not a hover gesture,
        and a control that only exists under the pointer is unreachable by
        touch.

        The label is core's own string, the screen-reader text inside that same
        `handlediv` at `wp-admin/includes/template.php:1423`, so it is translated
        along with everything else rather than being a second wording of it.

        Hence the explicit `'default'` domain. The wrapper defaults every call to
        the plugin's own `admin-suite` catalogue, which is deliberately empty, so
        without the domain the lookup would miss and the string would stay
        English forever. Naming the domain is what routes it to the catalogue
        WordPress actually ships admin strings in — the case `i18n.ts` documents.

        Core also points `aria-describedby` at the box title; the title here is
        the visible text right beside the button, which is the same context
        without inventing a second id to keep in step.
      -->
      <button
        v-if="props.collapsible"
        type="button"
        :class="buttonClass"
        :aria-expanded="!collapsed"
        :aria-controls="bodyId"
        :aria-label="__('Show or hide panel', 'default')"
        @click="emit('fold')"
      >
        <ChevronDown
          class="size-4 transition-transform"
          :class="collapsed ? '' : 'rotate-180'"
          aria-hidden="true"
        />
      </button>

      <!--
        The show/hide control sits outside the group above, which stays
        opacity-0 until hover or focus, so it is not competing with them for
        attention.

        It is rendered only while the Screen Options box is open, and that is
        the whole contract: the box is where you go to decide what is on the
        dashboard, so the control that changes it belongs to the same gesture,
        the same way core hides its own "Screen Options" tab when the tab is
        unused. It sits outside the group above so it never inherits its
        opacity-0. `draggable="false"` because the grid row is draggable and
        would otherwise swallow the click as the start of a drag.

        Hence the two `!`s, which are not decoration. Core sizes this element
        twice, and both rules are unlayered, and unlayered author CSS outranks
        every `@layer` — including the one Tailwind puts its utilities in. A
        plain size utility here is silently dead:

          wp-admin/css/forms.css:136,142   height: 1rem;  width: 1rem;
          wp-admin/css/forms.css:1632-5   height: 1.5625rem; width: 1.5625rem;

        The second one wins on order alone, so the box is 25x25. Forcing
        `width` is only half the job, though: `forms.css:143` also sets
        `min-width: 1rem`, and a minimum is applied after the width, so the
        height would shrink while the width stayed pinned at 16px and the control
        rendered as a 16x8 rectangle. Hence `min-w-0!` beside the size, and the
        trailing `!` on both is the Tailwind v4 spelling of `!important`.

        12.5px is half of core's 25px, which is what "make it at least half the
        size" means against what was actually on screen. `size-3!` would be
        12px and tidier, but it would stop being the half it was asked to be.
      -->
      <input
        v-if="props.showToggle"
        :checked="props.visible"
        :aria-label="sprintf(__('Show %s on the dashboard'), props.title)"
        class="size-[12.5px]! min-w-0! shrink-0 rounded-sm border-line text-accent focus:ring-accent"
        draggable="false"
        type="checkbox"
        @change="emit('toggle')"
      />
    </header>

    <!--
      `v-show` rather than `v-if`: a native widget's body is a query, so
      unmounting it would throw the instance away and refetch on every unfold.
    -->
    <div v-show="!collapsed" :id="bodyId" class="min-h-0 flex-1 p-3">
      <slot />
    </div>
  </article>
</template>
