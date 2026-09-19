<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import HelpTip from './HelpTip.vue'

/*
 | The WhatsApp message editor.
 |
 | Two things make this usable by somebody who has never written a template:
 |
 |   THE PICKER shows each placeholder with an example of what it becomes.
 |   "{owner_phone}" means nothing; "{owner_phone} → +91 98200 00002" means
 |   something. Clicking one drops it into the message at the cursor, so nobody
 |   has to type braces correctly.
 |
 |   THE PREVIEW renders the message against a sample customer as it is typed.
 |   A misspelled placeholder shows up as a literal "{custamer_name}" sitting in
 |   the middle of an otherwise finished message, which is far more obvious than
 |   any validation error would be.
 |
 | The category is a real decision and not a label — utility costs roughly an
 | eighth of marketing — so it is a required choice with the difference printed
 | next to it, rather than something defaulted quietly.
 */
const props = defineProps({
  show: Boolean,
  template: { type: Object, default: null },
  // { name: { label, example } } from config/automation.php
  placeholders: { type: Object, required: true },
  // { utility: { label, cost_note, hint } }
  categories: { type: Object, required: true },
})
const emit = defineEmits(['close'])

const editing = computed(() => !!props.template)
const bodyRef = ref(null)

const form = useForm({
  name: '',
  category: 'utility',
  body: '',
  is_active: true,
})

watch(() => props.show, open => {
  if (!open) return

  form.defaults(props.template
    ? {
        name: props.template.name,
        category: props.template.category,
        body: props.template.body,
        is_active: props.template.is_active,
      }
    : { name: '', category: 'utility', body: '', is_active: true })

  form.reset()
  form.clearErrors()
})

/* ---------------- the picker ---------------- */

const placeholderList = computed(() => Object.entries(props.placeholders))

/**
 * Drop a placeholder in at the cursor.
 *
 * At the cursor rather than at the end, because the whole point is writing
 * "Namaste {first_name}, thank you…" in one pass. Falls back to appending if
 * the textarea has never been focused.
 */
const insert = name => {
  const token = `{${name}}`
  const el = bodyRef.value

  if (!el) {
    form.body += token
    return
  }

  const start = el.selectionStart ?? form.body.length
  const end = el.selectionEnd ?? form.body.length

  form.body = form.body.slice(0, start) + token + form.body.slice(end)

  // put the caret after what was just inserted, on the next tick, so typing
  // carries on where the reader's eye is
  requestAnimationFrame(() => {
    el.focus()
    el.setSelectionRange(start + token.length, start + token.length)
  })
}

/* ---------------- the preview ---------------- */

/*
 | Rendered in the browser from the same examples the picker shows, so the two
 | can never disagree about what {project} turns into. The server renders the
 | identical thing with TemplateRenderer::preview() for the list on the page —
 | the examples live in config and both sides read them.
 */
const preview = computed(() => {
  let out = form.body

  Object.entries(props.placeholders).forEach(([name, meta]) => {
    out = out.split(`{${name}}`).join(meta.example)
  })

  return out
})

/*
 | Anything in braces that is not a placeholder we know.
 |
 | A warning, never a refusal. A message may legitimately contain a brace, and
 | blocking the save on a false positive would leave the admin unable to write
 | what they meant. The preview beside it makes the mistake obvious anyway.
 */
const unknown = computed(() => {
  const known = Object.keys(props.placeholders)
  const found = form.body.match(/\{([a-z_]+)\}/g) ?? []

  return [...new Set(found.map(s => s.slice(1, -1)).filter(n => !known.includes(n)))]
})

const costNote = computed(() => props.categories[form.category]?.cost_note ?? '')
const categoryHint = computed(() => props.categories[form.category]?.hint ?? '')

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }

  editing.value
    ? form.put(route('automation.templates.update', props.template.id), options)
    : form.post(route('automation.templates.store'), options)
}
</script>

<template>
  <Modal :show="show" :title="editing ? 'Edit message' : 'New message'" max-width="max-w-3xl" @close="emit('close')">
    <div class="space-y-5">

      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Message name" required :error="form.errors.name"
                   hint="What you will pick it by in a rule. The customer never sees this.">
          <input v-model="form.name" type="text" class="w-full" maxlength="120" />
        </FormField>

        <FormField label="Category" required :error="form.errors.category" :hint="categoryHint">
          <select v-model="form.category" class="w-full">
            <option v-for="(meta, key) in categories" :key="key" :value="key">{{ meta.label }}</option>
          </select>
        </FormField>
      </div>

      <!--
        The price difference, on screen, at the moment the choice is made.
        Meta bills per conversation and marketing is roughly eight times
        utility — which is the sort of thing an office finds out from a bill.
      -->
      <div class="warn-box flex items-start gap-2">
        <span class="flex-1">
          <strong>Cost:</strong> {{ costNote }}
        </span>
        <HelpTip title="Why the category matters" align="right">
          WhatsApp charges per conversation, and the price depends on this box.
          <strong>Utility</strong> is for messages about something the customer already started —
          a brochure they asked for, a visit they booked. <strong>Marketing</strong> is for
          anything they did not ask for: offers, launches, festive wishes. Marketing costs
          roughly eight times as much, so a friendly Diwali message to two thousand leads is not
          a small decision.
          <br><br>
          This only affects billing once WhatsApp API sending is set up. Sending by hand from the
          Queue costs nothing.
        </HelpTip>
      </div>

      <!-- ---------------- the body ---------------- -->
      <FormField label="Message" required :error="form.errors.body">
        <textarea ref="bodyRef" v-model="form.body" rows="7" class="w-full font-mono text-sm"
                  maxlength="1024" placeholder="Namaste {first_name}, thank you for your interest in {project}." />
      </FormField>

      <div>
        <div class="mb-2 flex items-center gap-1.5">
          <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Drop in a detail</span>
          <HelpTip title="Placeholders">
            These are filled in with the real customer's details when the message is created.
            Click one to add it where your cursor is. Anything in braces that is not on this
            list will be sent to the customer exactly as you typed it.
          </HelpTip>
        </div>

        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="[name, meta] in placeholderList" :key="name"
            type="button" class="btn-xs text-left"
            :title="meta.label"
            @click="insert(name)"
          >
            <span class="font-mono">{{ '{' + name + '}' }}</span>
            <span class="ml-1 text-slate-400">→ {{ meta.example }}</span>
          </button>
        </div>
      </div>

      <p v-if="unknown.length" class="warn-box">
        <strong>{{ unknown.map(n => '{' + n + '}').join(', ') }}</strong>
        {{ unknown.length === 1 ? 'is not a placeholder this CRM knows' : 'are not placeholders this CRM knows' }}.
        It will be sent to the customer exactly as written. Check the spelling against the buttons above.
      </p>

      <!-- ---------------- the preview ---------------- -->
      <div>
        <div class="mb-2 flex items-center gap-1.5">
          <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">What the customer sees</span>
          <HelpTip title="Sample customer">
            Made up from the examples on the buttons above. The real message uses the actual
            lead's name, project and the staff member handling them.
          </HelpTip>
        </div>

        <!-- a WhatsApp-ish bubble, so it reads as a message rather than a field -->
        <div class="rounded-xl bg-slate-100 dark:bg-slate-700 p-3">
          <div class="max-w-md whitespace-pre-wrap rounded-xl rounded-tl-sm bg-white dark:bg-slate-800 px-3 py-2 text-sm
                      leading-relaxed text-slate-800 dark:text-slate-200 shadow-sm">
            {{ preview || 'Your message will appear here as you type it.' }}
          </div>
        </div>
      </div>

      <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
        <input v-model="form.is_active" type="checkbox" class="h-4 w-4" />
        Available to rules
        <HelpTip title="Switching a message off">
          A switched-off message disappears from the rule builder but stays here. Any rule already
          using it will skip that step and say so in the Activity tab, rather than failing.
          It is the gentle version of deleting.
        </HelpTip>
      </label>
    </div>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : (editing ? 'Save changes' : 'Save message') }}
      </button>
    </template>
  </Modal>
</template>
