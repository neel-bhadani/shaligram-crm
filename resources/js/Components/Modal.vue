<script setup>
import { onMounted, onUnmounted, watch } from 'vue'

const props = defineProps({
  show: Boolean,
  title: String,
  maxWidth: { type: String, default: 'max-w-2xl' },
})
const emit = defineEmits(['close'])

const onKey = e => { if (e.key === 'Escape' && props.show) emit('close') }

/*
 | Locking the page scroll takes the viewport scrollbar away, which makes the
 | page wider: the header and cards slide sideways and every chart behind the
 | modal redraws at the new width. Hand the same pixels back as padding so
 | nothing moves. Measured before the modal renders, since the watcher flushes
 | ahead of the DOM update, and zero on phones and overlay-scrollbar browsers
 | where there is no gutter to give back.
 */
const lockScroll = () => {
  const gutter = window.innerWidth - document.documentElement.clientWidth

  if (gutter > 0) document.body.style.paddingRight = `${gutter}px`

  document.body.style.overflow = 'hidden'
}

const unlockScroll = () => {
  document.body.style.overflow = ''
  document.body.style.paddingRight = ''
}

onMounted(() => document.addEventListener('keydown', onKey))
onUnmounted(() => {
  document.removeEventListener('keydown', onKey)
  unlockScroll()
})

/*
 | Only the lock happens here. Giving the scrollbar back the moment `show` goes
 | false would put that same sideways shift on screen behind the modal while it
 | is still fading out, so the unlock waits for @after-leave below.
 */
watch(() => props.show, v => { if (v) lockScroll() })
</script>

<template>
  <Teleport to="body">
    <!-- the tint and the card move separately: one fades, the other travels -->
    <Transition
      enter-active-class="transition-opacity duration-200 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div v-if="show" class="fixed inset-0 z-[60] bg-slate-900/50" />
    </Transition>

    <!--
      A sheet rising from the bottom of a phone, a card settling into the middle
      of a desktop. The transform sits on the full-screen layer rather than the
      panel, which works because the two share a centre — scaling the layer
      scales the panel about itself.
    -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0 motion-safe:translate-y-full motion-safe:sm:translate-y-0 motion-safe:sm:scale-95"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0 motion-safe:translate-y-full motion-safe:sm:translate-y-0 motion-safe:sm:scale-95"
      @after-leave="unlockScroll"
    >
      <div
        v-if="show"
        class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-5"
        @click.self="emit('close')"
      >
        <!-- header and footer stay fixed, only the body scrolls,
             so the submit button is always reachable on a phone -->
        <div
          class="flex max-h-[94vh] w-full flex-col rounded-t-2xl bg-white dark:bg-slate-800 shadow-2xl sm:max-h-[90vh] sm:rounded-xl"
          :class="maxWidth"
        >
          <div class="flex flex-none items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-700/60 px-5 py-4">
            <h3 class="min-w-0 break-words text-lg font-semibold tracking-tight">{{ title }}</h3>
            <button class="flex-none px-2 text-2xl leading-none text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                    @click="emit('close')">&times;</button>
          </div>

          <div class="flex-1 overflow-y-auto px-5 py-5">
            <slot />
          </div>

          <div v-if="$slots.footer"
               class="sticky bottom-0 flex flex-none justify-end gap-2 border-t border-slate-100 dark:border-slate-700/60 bg-white dark:bg-slate-800 px-5 py-3.5">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
