<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Dial or message the lead, from wherever the number is already on screen.
 *
 * Both are plain links and nothing else. Clicking one writes nothing: no todo,
 * no stage change, no request of any kind. The call is still logged afterwards
 * through CompleteTaskModal, which is the only thing that records it.
 */
const props = defineProps({
  mobile: String,

  // icon only, for the desktop table and the dashboard panels where the row
  // is already carrying its own buttons
  compact: Boolean,
})

/*
 | tel: and wa.me both break on formatting characters, so what goes into the
 | href is digits and nothing else. Stored numbers are the bare 10, but a
 | number that arrived with spaces or dashes is cleaned here rather than being
 | trusted to be clean.
 */
const digits = computed(() => (props.mobile ?? '').replace(/\D/g, ''))

// config/crm.php owns the dialling code and reaches here through the page's
// options prop, the same way StageBadge reads its palette
const code = computed(() => usePage().props.options?.countryCode ?? '')

const telHref = computed(() => `tel:${code.value}${digits.value}`)

// wa.me wants bare digits, so the leading + comes off; tel: keeps it
const waHref = computed(() => `https://wa.me/${code.value.replace(/\D/g, '')}${digits.value}`)

const label = computed(() => `${code.value} ${digits.value}`.trim())
</script>

<template>
  <div v-if="digits" class="flex items-center gap-1.5">
    <!--
      target="_self" and no rel: handing the number to the device's own dialler
      is not a navigation to another site.
    -->
    <a
      :href="telHref"
      target="_self"
      :class="compact ? 'btn-xs inline-flex items-center' : 'btn-ghost flex-1 gap-1.5 px-3 py-1.5 text-xs'"
      :title="compact ? `Call ${label}` : null"
      :aria-label="`Call ${label}`"
    >
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round"
           class="h-3.5 w-3.5 shrink-0" aria-hidden="true">
        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6
                 A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81
                 a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45
                 c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
      </svg>
      <span v-if="!compact" class="truncate">{{ label }}</span>
    </a>

    <!-- WhatsApp really is another site, so this one opens away and gets rel -->
    <a
      :href="waHref"
      target="_blank"
      rel="noopener"
      :class="[
        compact ? 'btn-xs inline-flex items-center' : 'btn-ghost flex-1 gap-1.5 px-3 py-1.5 text-xs',
        'hover:!border-emerald-600 hover:!text-emerald-700 dark:text-emerald-300',
      ]"
      :title="compact ? `WhatsApp ${label}` : null"
      :aria-label="`Message ${label} on WhatsApp`"
    >
      <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5 shrink-0" aria-hidden="true">
        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94
                 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8
                 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462
                 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227
                 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421
                 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86
                 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825
                 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0
                 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882
                 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0 0 20.464 3.488" />
      </svg>
      <span v-if="!compact" class="truncate">WhatsApp</span>
    </a>
  </div>
</template>
