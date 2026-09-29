<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormField from '@/Components/FormField.vue'
import CopyField from '@/Components/CopyField.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

/*
 | Facebook lead ads configuration.
 |
 | Two halves, and the split is the point. The top half is what the admin gives
 | US — tokens, page id, where the leads should land. The bottom half is what
 | they take FROM us and paste into the Meta app dashboard. Getting those the
 | wrong way round is the commonest way this setup fails, so they are labelled
 | as directions rather than as fields.
 |
 | The two token fields never hold a stored value. The server sends only the
 | last four characters, and an empty field means "keep what is saved" — so an
 | admin changing the project does not have to re-paste a 200-character token
 | to avoid wiping it. That is stated on screen, not just implied.
 */
const props = defineProps({
  show: Boolean,
  card: Object,
  options: Object,
})

const emit = defineEmits(['close'])

const form = useForm({
  page_access_token: '',
  app_secret: '',
  page_id: '',
  default_project_id: '',
  assign_to_user_id: '',
  is_active: false,
  forms: [],
  removed_forms: [],
})

const formRow = f => ({
  form_id: f.form_id,
  form_name: f.form_name ?? '',
  project_id: f.project_id ?? '',
  assign_to_user_id: f.assign_to_user_id ?? '',
  unrouted_leads: f.unrouted_leads ?? 0,
})

/*
 | Reset every time the modal opens rather than once at setup: the card's
 | settings change under it after each save, and the two token fields must come
 | back empty every time — a stale token left in a field would be re-sent and
 | re-saved as if the admin had typed it.
 */
watch(() => props.show, (open) => {
  if (!open) return

  const s = props.card?.settings ?? {}

  form.clearErrors()
  form.defaults({
    page_access_token: '',
    app_secret: '',
    page_id: s.page_id ?? '',
    default_project_id: s.default_project_id ?? '',
    assign_to_user_id: s.assign_to_user_id ?? '',
    is_active: props.card?.is_active ?? false,
    forms: (props.card?.forms ?? []).map(formRow),
    removed_forms: [],
  })
  form.reset()
  newFormId.value = ''
  newFormIdError.value = ''
  showManualAdd.value = false
})

const hasToken = computed(() => Boolean(props.card?.settings?.page_access_token_hint))
const hasSecret = computed(() => Boolean(props.card?.settings?.app_secret_hint))

/* ---------------- the lead-form table ---------------- */

const newFormId = ref('')
const newFormIdError = ref('')
const showManualAdd = ref(false)
const syncing = ref(false)

// the same check IntegrationSettingsRequest makes — LeadFormRoute::FORM_ID_PATTERN —
// here so a typo is refused before the admin has filled in the rest of the row
const FORM_ID_PATTERN = /^\d{15,16}$/
const FORM_ID_HELP = "That doesn't look like a Facebook form ID. Use Load forms from Facebook to pick one."

const addForm = () => {
  const id = newFormId.value.replace(/\s+/g, '')
  newFormIdError.value = ''
  if (!id) return
  if (!FORM_ID_PATTERN.test(id)) {
    newFormIdError.value = FORM_ID_HELP
    return
  }
  if (form.forms.some(f => f.form_id === id)) {
    newFormIdError.value = 'That form is already in the list.'
    return
  }
  form.forms.push(formRow({ form_id: id }))
  form.removed_forms = form.removed_forms.filter(r => r !== id)
  newFormId.value = ''
}

/*
 | Removing asks first, naming the form. Nothing is deleted until Save, and
 | then only the row — see IntegrationController::saveForms().
 */
const removing = ref(null)

const askRemove = index => { removing.value = index }

const removingLabel = computed(() => {
  const f = form.forms[removing.value]
  return f ? (f.form_name || f.form_id) : ''
})

const confirmRemove = () => {
  const [removed] = form.forms.splice(removing.value, 1)
  form.removed_forms.push(removed.form_id)
  removing.value = null
}

/*
 | Cleared on the next tick: Escape reaches this dialog's Modal and the settings
 | Modal in the same keydown, and the settings one must still see a removal in
 | progress so it does not close and throw away every unsaved edit.
 */
const cancelRemove = () => setTimeout(() => (removing.value = null))

const closeSettings = () => { if (removing.value === null) emit('close') }

const unmappedCount = computed(() => form.forms.filter(f => !f.project_id).length)

const canSync = computed(() => hasToken.value && Boolean(props.card?.settings?.page_id))

/*
 | The server adds the page's forms to the table; this folds them into the rows
 | being edited without discarding the admin's unsaved choices.
 */
const syncForms = () => {
  syncing.value = true
  router.post(route('integrations.forms.sync', { provider: props.card.provider }), {}, {
    preserveScroll: true,
    onSuccess: () => {
      for (const f of props.card?.forms ?? []) {
        const row = form.forms.find(r => r.form_id === f.form_id)
        if (!row) {
          if (!form.removed_forms.includes(f.form_id)) form.forms.push(formRow(f))
        } else if (!row.form_name && f.form_name) {
          row.form_name = f.form_name
        }
      }
    },
    onFinish: () => (syncing.value = false),
  })
}

const submit = () => {
  form
    .transform(data => ({
      ...data,
      forms: data.forms.map(({ unrouted_leads, ...f }) => ({
        ...f,
        project_id: f.project_id || null,
        assign_to_user_id: f.assign_to_user_id || null,
      })),
    }))
    .put(route('integrations.update', { provider: props.card.provider }), {
      preserveScroll: true,
      onSuccess: () => emit('close'),
    })
}
</script>

<template>
  <Modal :show="show" title="Facebook Lead Ads" max-width="max-w-3xl" @close="closeSettings">
    <form id="fb-settings" class="space-y-6" @submit.prevent="submit">

      <!-- ---------- what we need from the client ---------- -->
      <section class="space-y-4">
        <header>
          <h4 class="text-sm font-semibold text-slate-900">From the Meta app dashboard</h4>
          <p class="mt-0.5 text-xs text-slate-500">
            Get these from the client's Meta Business account. Both are stored encrypted
            and are never shown again.
          </p>
        </header>

        <FormField
          label="Page access token" :required="!hasToken"
          :error="form.errors.page_access_token"
          :hint="hasToken
            ? `Stored: ${card.settings.page_access_token_hint} — leave blank to keep it, or paste a new one to replace it.`
            : 'A long-lived page token with leads_retrieval and pages_manage_metadata.'"
        >
          <input v-model="form.page_access_token" type="password" autocomplete="off"
                 :placeholder="hasToken ? 'Leave blank to keep the stored token' : 'EAAG…'" />
        </FormField>

        <FormField
          label="App secret" :required="!hasSecret"
          :error="form.errors.app_secret"
          :hint="hasSecret
            ? `Stored: ${card.settings.app_secret_hint} — leave blank to keep it, or paste a new one to replace it.`
            : 'Settings → Basic in the Meta app. Every webhook call is signed with this.'"
        >
          <input v-model="form.app_secret" type="password" autocomplete="off"
                 :placeholder="hasSecret ? 'Leave blank to keep the stored secret' : 'App secret'" />
        </FormField>

        <FormField label="Page ID" required :error="form.errors.page_id"
                   hint="The Facebook page the lead forms are on. Used to load its forms below.">
          <input v-model="form.page_id" type="text" placeholder="1029384756…" />
        </FormField>
      </section>

      <!-- ---------- where the leads go ---------- -->
      <section class="space-y-4 border-t border-slate-100 pt-5">
        <header>
          <h4 class="text-sm font-semibold text-slate-900">Where these leads land</h4>
        </header>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField label="Fallback project" required :error="form.errors.default_project_id"
                     hint="Where leads go when their form has no project below.">
            <select v-model="form.default_project_id">
              <option value="">Choose a project</option>
              <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </FormField>

          <!--
            Telecallers only. A new lead is Fresh, and anyone off the telecaller
            desk is passed over for the first active telecaller — so offering a
            salesperson here would be a choice that saves and does nothing.
          -->
          <FormField label="Assign leads to" required :error="form.errors.assign_to_user_id"
                     hint="A telecaller. They get the lead and a follow-up due immediately.">
            <select v-model="form.assign_to_user_id">
              <option value="">Choose a telecaller</option>
              <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.label }}</option>
            </select>
          </FormField>
        </div>

        <p class="info-box">
          Leads are routed by the lead form they were submitted on. A form with no project
          below sends its leads to the fallback project.
        </p>

        <!-- ---------- lead forms ---------- -->
        <div class="space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h5 class="text-sm font-semibold text-slate-800">
              Lead forms
              <span v-if="unmappedCount" class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                {{ unmappedCount }} without a project
              </span>
            </h5>
            <button type="button" class="btn !px-3 !py-1.5 !text-xs"
                    :disabled="!canSync || syncing"
                    :title="canSync ? 'Adds every form on the page to this list, with its exact ID' : 'Save the page ID and access token first'"
                    @click="syncForms">
              {{ syncing ? 'Loading…' : 'Load forms from Facebook' }}
            </button>
          </div>

          <p class="text-xs text-slate-500">
            Use <strong>Load forms from Facebook</strong> to list the page's forms with their exact IDs,
            then choose a project for each. A form that sends a lead also appears here on its own.
          </p>

          <div v-for="(f, i) in form.forms" :key="f.form_id"
               class="rounded-lg border p-3"
               :class="f.project_id ? 'border-slate-200' : 'border-amber-200 bg-amber-50/40'">
            <div class="mb-2 flex items-start justify-between gap-2">
              <div class="min-w-0">
                <input v-model="f.form_name" type="text" class="!py-1 text-sm font-medium"
                       :placeholder="`Form ${f.form_id}`" />
                <p class="mt-0.5 font-mono text-[11px] text-slate-400">{{ f.form_id }}</p>
                <p v-if="form.errors[`forms.${i}.form_id`]" class="text-xs text-rose-600">
                  {{ form.errors[`forms.${i}.form_id`] }}
                </p>
                <p v-if="!f.project_id" class="mt-0.5 text-[11px] font-semibold text-amber-800">
                  Waiting for a project<template v-if="f.unrouted_leads">
                    · {{ f.unrouted_leads }} {{ f.unrouted_leads === 1 ? 'lead' : 'leads' }} went to the fallback</template>
                </p>
              </div>
              <button type="button" class="text-xs text-slate-400 hover:text-rose-600" @click="askRemove(i)">
                Remove
              </button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
              <FormField label="Project" :error="form.errors[`forms.${i}.project_id`]">
                <select v-model="f.project_id">
                  <option value="">No project — use the fallback</option>
                  <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
              </FormField>
              <FormField label="Assign leads to" :error="form.errors[`forms.${i}.assign_to_user_id`]">
                <select v-model="f.assign_to_user_id">
                  <option value="">The telecaller above</option>
                  <option v-for="u in options.users" :key="u.id" :value="u.id">{{ u.label }}</option>
                </select>
              </FormField>
            </div>
          </div>

          <!--
            Typing an id is the fallback, not the way: one wrong digit and the
            real form's leads all go to the fallback project with nothing to
            say why. Tucked behind a link so the button above is the obvious path.
          -->
          <button v-if="!showManualAdd" type="button" class="text-xs text-slate-500 underline hover:text-slate-700"
                  @click="showManualAdd = true">
            Or add a form by its ID
          </button>
          <div v-else>
            <div class="flex gap-2">
              <input v-model="newFormId" type="text" inputmode="numeric" placeholder="Form ID, 15–16 digits"
                     @keydown.enter.prevent="addForm" />
              <button type="button" class="btn-ghost flex-none !px-3 !text-xs" @click="addForm">Add form</button>
            </div>
            <p v-if="newFormIdError" class="mt-1 text-xs text-rose-600">{{ newFormIdError }}</p>
          </div>
        </div>

        <label class="flex items-start gap-2.5 text-sm">
          <input v-model="form.is_active" type="checkbox" class="mt-0.5 h-4 w-4 flex-none" />
          <span>
            <span class="font-medium text-slate-800">Accept leads from Facebook</span>
            <span class="block text-xs text-slate-500">
              When off, signed deliveries from Meta are logged and discarded rather than imported.
            </span>
          </span>
        </label>
        <p v-if="form.errors.is_active" class="text-xs text-rose-600">{{ form.errors.is_active }}</p>
      </section>

      <!-- ---------- what they paste into Meta ---------- -->
      <section class="space-y-4 border-t border-slate-100 pt-5">
        <header>
          <h4 class="text-sm font-semibold text-slate-900">Paste these into Meta</h4>
          <p class="mt-0.5 text-xs text-slate-500">
            Meta app dashboard → Webhooks → Page → Subscribe to <code class="rounded bg-slate-100 px-1">leadgen</code>.
          </p>
        </header>

        <template v-if="card.webhook?.verify_token">
          <CopyField label="Callback URL" :value="card.webhook.url"
                     hint="Must be https and reachable from the internet. Meta will not accept a localhost address." />
          <CopyField label="Verify token" :value="card.webhook.verify_token"
                     hint="Meta sends this back once to confirm the endpoint is yours. It does not change." />
        </template>

        <p v-else class="info-box">
          Save these settings once and the callback URL and verify token will appear here,
          ready to paste into the Meta app dashboard.
        </p>
      </section>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="fb-settings" class="btn" :disabled="form.processing">
        {{ form.processing ? 'Saving…' : 'Save settings' }}
      </button>
    </template>

    <ConfirmDialog
      :show="removing !== null"
      :title="`Remove '${removingLabel}'?`"
      message="Leads from this form will go to the fallback project. Leads that already came in through it are not changed. The form is removed when you save."
      confirm-text="Remove"
      @close="cancelRemove"
      @confirm="confirmRemove"
    />
  </Modal>
</template>
