<script setup>
import { reactive, computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import FormField from '@/Components/FormField.vue'
import CopyField from '@/Components/CopyField.vue'

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
  })
  form.reset()
})

const hasToken = computed(() => Boolean(props.card?.settings?.page_access_token_hint))
const hasSecret = computed(() => Boolean(props.card?.settings?.app_secret_hint))

const submit = () => {
  form.put(route('integrations.update', { provider: props.card.provider }), {
    preserveScroll: true,
    onSuccess: () => emit('close'),
  })
}
</script>

<template>
  <Modal :show="show" title="Facebook Lead Ads" max-width="max-w-3xl" @close="emit('close')">
    <form id="fb-settings" class="space-y-6" @submit.prevent="submit">

      <!-- ---------- what we need from the client ---------- -->
      <section class="space-y-4">
        <header>
          <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">From the Meta app dashboard</h4>
          <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
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

        <FormField label="Page ID" :error="form.errors.page_id"
                   hint="Optional. Recorded so it is obvious which Facebook page these leads come from.">
          <input v-model="form.page_id" type="text" placeholder="1029384756…" />
        </FormField>
      </section>

      <!-- ---------- where the leads go ---------- -->
      <section class="space-y-4 border-t border-slate-100 dark:border-slate-700/60 pt-5">
        <header>
          <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Where these leads land</h4>
        </header>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField label="Default project" required :error="form.errors.default_project_id">
            <select v-model="form.default_project_id">
              <option value="">Choose a project</option>
              <option v-for="p in options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </FormField>

          <FormField label="Assign leads to" required :error="form.errors.assign_to_user_id"
                     hint="They get the lead and a follow-up due immediately.">
            <select v-model="form.assign_to_user_id">
              <option value="">Choose a person</option>
              <option v-for="u in options.users" :key="u.id" :value="u.id">
                {{ u.label }} · {{ u.role }}
              </option>
            </select>
          </FormField>
        </div>

        <!--
          The single most important sentence on this page. Meta's payload says
          nothing about which project an advert was for, so every lead from this
          page lands on the one project chosen above — and a client advertising
          three towers who does not know that will find three towers' worth of
          leads in one.
        -->
        <p class="warn-box">
          <strong>Facebook does not tell us which project a lead is for.</strong>
          Every lead from this page will be filed under the project chosen above.
          If the client advertises more than one project, they need a separate lead
          form and a separate Facebook page connection per project — otherwise the
          leads all arrive against the same one and have to be sorted by hand.
        </p>

        <label class="flex items-start gap-2.5 text-sm">
          <input v-model="form.is_active" type="checkbox" class="mt-0.5 h-4 w-4 flex-none" />
          <span>
            <span class="font-medium text-slate-800 dark:text-slate-200">Accept leads from Facebook</span>
            <span class="block text-xs text-slate-500 dark:text-slate-400">
              When off, signed deliveries from Meta are logged and discarded rather than imported.
            </span>
          </span>
        </label>
        <p v-if="form.errors.is_active" class="text-xs text-rose-600 dark:text-rose-400">{{ form.errors.is_active }}</p>
      </section>

      <!-- ---------- what they paste into Meta ---------- -->
      <section class="space-y-4 border-t border-slate-100 dark:border-slate-700/60 pt-5">
        <header>
          <h4 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Paste these into Meta</h4>
          <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            Meta app dashboard → Webhooks → Page → Subscribe to <code class="rounded bg-slate-100 dark:bg-slate-700 px-1">leadgen</code>.
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
  </Modal>
</template>
