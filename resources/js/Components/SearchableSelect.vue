<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

/*
 | A <select> you can type into.
 |
 | Built rather than installed: the app carries axios and chart.js and nothing
 | else, and a combobox is a text input, a filtered list and four key handlers.
 |
 | It exists because of one problem the plain select could not solve. Channel
 | partners are people, and two of them are called Ravi — so the list has to be
 | searchable to be usable past about thirty rows, and every option has to carry
 | the firm that tells the two Ravis apart. The parent supplies the label
 | already joined ("Ravi Kumar — Shreeji Realty"); this file only searches it
 | and draws it.
 |
 | The chrome is the global input styling from app.css, so it sits in a
 | FormField next to a real <select> without looking like a different control.
 */
const props = defineProps({
  /** The selected option's value, or '' / null for none. */
  modelValue: { type: [Number, String], default: '' },

  /** @type {{ value: number|string, label: string }[]} */
  options: { type: Array, required: true },

  placeholder: { type: String, default: 'Search…' },
  emptyText: { type: String, default: 'No matches' },
  disabled: Boolean,

  /*
   | When set, a pinned row appears at the TOP of the list carrying this label,
   | and choosing it emits `create` with whatever has been typed so far instead
   | of selecting anything.
   |
   | Top rather than bottom, which is the opposite of where a "create" row
   | usually goes, and the position is doing real work: the caller wants the
   | typed text to arrive in the new-partner form already filled in, and a row
   | the user has to scroll past forty names to reach is a row they will not
   | find. It sits above the matches and the matches stay visible under it, so
   | the existing partner is still the easier of the two to pick — which is the
   | whole point of the typeahead being here at all.
   */
  createLabel: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'create'])

const open = ref(false)
const query = ref('')
const cursor = ref(0)
const root = ref(null)
const input = ref(null)
const list = ref(null)

const selected = computed(() =>
  props.options.find(o => String(o.value) === String(props.modelValue)) ?? null)

/*
 | While the list is shut the input SHOWS the selection; while it is open it
 | holds whatever is being typed. One input doing both jobs is what keeps this
 | the height of a select rather than a select with a search box stacked on it.
 */
const display = computed(() => (open.value ? query.value : selected.value?.label ?? ''))

const matches = computed(() => {
  const q = query.value.trim().toLowerCase()

  if (!q) return props.options

  // every word has to appear somewhere in the label, so "ravi shreeji" finds
  // "Ravi Kumar — Shreeji Realty" without the user guessing at the order
  const words = q.split(/\s+/)

  return props.options.filter(o => {
    const label = o.label.toLowerCase()
    return words.every(w => label.includes(w))
  })
})

/*
 | The create row is index -1, so the cursor arithmetic below never has to know
 | whether it exists: -1 is "the pinned row", 0 and up are matches, and a list
 | with no create row simply never reaches -1.
 */
const CREATE = -1

const canCreate = computed(() => !!props.createLabel)

const openList = async () => {
  if (props.disabled) return

  open.value = true
  query.value = ''
  // start on the current selection so Enter without typing changes nothing
  cursor.value = Math.max(0, props.options.findIndex(o => String(o.value) === String(props.modelValue)))

  await nextTick()
  scrollToCursor()
}

const close = () => { open.value = false; query.value = '' }

const choose = option => {
  emit('update:modelValue', option ? option.value : '')
  close()
  input.value?.blur()
}

/*
 | Hand the typed text to the caller and get out of the way. The list closes but
 | the input is NOT blurred and nothing is selected — the caller is about to
 | open a form, and stealing focus back here would fight it for the cursor.
 */
const startCreate = () => {
  const typed = query.value.trim()

  open.value = false
  query.value = ''
  emit('create', typed)
}

const move = step => {
  if (!open.value) { openList(); return }

  // -1 when the create row is present, 0 otherwise
  const first = canCreate.value ? CREATE : 0
  const last  = matches.value.length - 1

  if (last < first) return

  const span = last - first + 1
  cursor.value = ((cursor.value - first + step + span) % span) + first
  scrollToCursor()
}

const scrollToCursor = async () => {
  await nextTick()
  list.value?.querySelector('[data-cursor="true"]')?.scrollIntoView({ block: 'nearest' })
}

const enter = () => {
  if (!open.value) { openList(); return }

  if (cursor.value === CREATE && canCreate.value) { startCreate(); return }

  const option = matches.value[cursor.value]

  if (option) choose(option)
}

/*
 | Typing is always a fresh search, so the highlight goes back to the top — and
 | the top is a MATCH whenever there is one, never the create row. Landing on
 | "Add new" after every keystroke would make Enter add a duplicate of the
 | partner sitting one row below it, which is the exact failure the near-match
 | warning exists to catch after the fact.
 |
 | With nothing matching there is nothing else to land on, so the create row
 | takes the highlight and Enter does the only useful thing left.
 */
watch(query, () => {
  cursor.value = matches.value.length ? 0 : (canCreate.value ? CREATE : 0)
})

/*
 | A click anywhere else closes the list and, crucially, restores the input to
 | the selected label — leaving a half-typed query on screen next to a
 | selection it does not match is the thing that makes a home-made combobox
 | look broken.
 */
const onDocumentClick = e => {
  if (open.value && root.value && !root.value.contains(e.target)) close()
}

onMounted(() => document.addEventListener('mousedown', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocumentClick))
</script>

<template>
  <div ref="root" class="relative">
    <input
      ref="input"
      type="text"
      role="combobox"
      autocomplete="off"
      :aria-expanded="open"
      :value="display"
      :placeholder="selected ? selected.label : placeholder"
      :disabled="disabled"
      :class="disabled ? 'cursor-not-allowed bg-slate-50 dark:bg-slate-900/60 text-slate-400' : ''"
      class="!pr-16"
      @input="query = $event.target.value"
      @focus="openList"
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
      @keydown.enter.prevent="enter"
      @keydown.esc.prevent="close"
      @keydown.tab="close"
    />

    <!-- clear, then the chevron: the same two affordances a select has, in the
         order a user reaches for them -->
    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center gap-1 pr-2.5">
      <button
        v-if="selected && !disabled"
        type="button"
        class="pointer-events-auto px-1 text-base leading-none text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
        aria-label="Clear selection"
        @click.stop="choose(null)"
      >&times;</button>

      <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M6 9l6 6 6-6" />
      </svg>
    </div>

    <!--
      Absolute, so opening the list never resizes the form under it — the modal
      body scrolls and a list that pushed the buttons down would move the target
      out from under the pointer.
    -->
    <ul
      v-if="open"
      ref="list"
      class="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700
             bg-white dark:bg-slate-800 py-1 shadow-lg"
      role="listbox"
    >
      <!--
        Pinned above the matches, and visibly a different kind of row: a dashed
        rule under it and a + rather than a name, so it does not read as the
        first search result.
      -->
      <li
        v-if="canCreate"
        :data-cursor="cursor === -1"
        role="option"
        :aria-selected="false"
        class="mb-1 flex cursor-pointer items-center gap-2 border-b border-dashed border-slate-200 dark:border-slate-700
               px-3 py-2 text-sm font-semibold"
        :class="cursor === -1 ? 'bg-teal-50 dark:bg-teal-500/10 text-teal-900 dark:text-teal-200' : 'text-teal-800 dark:text-teal-300'"
        @mouseenter="cursor = -1"
        @mousedown.prevent="startCreate"
      >
        <span class="text-base leading-none">+</span>
        {{ createLabel }}
        <span v-if="query.trim()" class="truncate font-normal text-slate-500 dark:text-slate-400">“{{ query.trim() }}”</span>
      </li>

      <li v-if="!matches.length" class="px-3 py-2 text-sm text-slate-400">{{ emptyText }}</li>

      <li
        v-for="(option, i) in matches"
        :key="option.value"
        :data-cursor="i === cursor"
        role="option"
        :aria-selected="String(option.value) === String(modelValue)"
        class="cursor-pointer px-3 py-2 text-sm"
        :class="[
          i === cursor ? 'bg-teal-50 dark:bg-teal-500/10 text-teal-900 dark:text-teal-200' : 'text-slate-700 dark:text-slate-300',
          String(option.value) === String(modelValue) ? 'font-semibold' : '',
        ]"
        @mouseenter="cursor = i"
        @mousedown.prevent="choose(option)"
      >{{ option.label }}</li>
    </ul>
  </div>
</template>
