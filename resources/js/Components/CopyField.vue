<script setup>
import { ref, onUnmounted } from 'vue'

/*
 | A read-only value with a copy button — the webhook URL and the verify token.
 |
 | Both exist to be pasted into somewhere else entirely (the Meta app
 | dashboard), and both are long enough that retyping one is how a webhook ends
 | up misconfigured in a way nobody can see. The input is readonly rather than
 | disabled so it can still be selected and read by a screen reader.
 */
defineProps({
  label: String,
  value: String,
  hint: String,
  // long values wrap rather than scroll sideways in a narrow modal
  mono: { type: Boolean, default: true },
})

const copied = ref(false)
let timer = null

/*
 | The clipboard API needs a secure context. On plain http — which is exactly
 | where this feature gets configured, on a laptop before a domain exists — it
 | is undefined, so the old selection-based path is the fallback rather than an
 | unexplained dead button.
 */
const copy = async (text) => {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      const el = document.createElement('textarea')
      el.value = text
      el.setAttribute('readonly', '')
      el.style.position = 'fixed'
      el.style.opacity = '0'
      document.body.appendChild(el)
      el.select()
      document.execCommand('copy')
      document.body.removeChild(el)
    }

    copied.value = true
    clearTimeout(timer)
    timer = setTimeout(() => (copied.value = false), 1800)
  } catch {
    // nothing to say that the user cannot already see: the value is on screen
    // and selectable, so a failed copy is an inconvenience, not an error
  }
}

onUnmounted(() => clearTimeout(timer))
</script>

<template>
  <div>
    <label class="mb-1.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">{{ label }}</label>

    <div class="flex gap-2">
      <input
        :value="value" readonly
        class="min-w-0 flex-1 !bg-slate-50 dark:bg-slate-900/60 text-slate-600 dark:text-slate-300"
        :class="mono ? 'font-mono text-xs' : ''"
        @focus="$event.target.select()"
      />
      <button type="button" class="btn-ghost flex-none whitespace-nowrap !px-3"
              :disabled="!value" @click="copy(value)">
        {{ copied ? 'Copied' : 'Copy' }}
      </button>
    </div>

    <p v-if="hint" class="mt-1.5 text-xs text-slate-400">{{ hint }}</p>
  </div>
</template>
