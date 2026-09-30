<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FacebookSettingsModal from '@/Components/FacebookSettingsModal.vue'

/*
 | Integrations, admin only.
 |
 | `role:admin` on the route group is what enforces that — the sidebar hides the
 | link for everyone else, but hiding a link is presentation and the middleware
 | is the answer. Nothing on this page is a permission check.
 |
 | One card per platform, and only Facebook is built. The other three are drawn
 | from the same config and the same component, disabled, so adding one later is
 | a `built` flag and a settings modal rather than a new page — and so that the
 | client can see on day one that the shape of the thing is planned rather than
 | forgotten.
 |
 | The activity log underneath is not decoration. An integration that stops
 | delivering leads is completely silent otherwise: nobody notices until someone
 | asks why Facebook has gone quiet, and by then it has been weeks.
 */
const props = defineProps({
  cards: Array,
  events: Array,
  options: Object,
})

const configuring = ref(null)
const testing = ref(null)

const facebookCard = computed(() => props.cards.find(c => c.provider === 'facebook'))

const configureOpen = computed({
  get: () => configuring.value !== null,
  set: v => { if (!v) configuring.value = null },
})

const openConfigure = card => { if (card.built) configuring.value = card.provider }

// the same job the webhook queues, with invented answers — see
// IntegrationController::test()
const sendTest = card => {
  testing.value = card.provider
  router.post(route('integrations.test', { provider: card.provider }), {}, {
    preserveScroll: true,
    onFinish: () => (testing.value = null),
  })
}

/* ---------------- display helpers ---------------- */

const status = card => {
  if (!card.built) return { label: 'Coming soon', class: 'bg-slate-100 text-slate-500' }
  if (card.connected) return { label: 'Connected', class: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' }
  if (card.configured) return { label: 'Switched off', class: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' }
  return { label: 'Not connected', class: 'bg-slate-100 text-slate-500' }
}

const resultChip = result => {
  const tone = props.options.results[result]?.tone ?? 'muted'
  return {
    good: 'bg-emerald-50 text-emerald-700',
    warn: 'bg-amber-50 text-amber-800',
    bad: 'bg-rose-50 text-rose-700',
    muted: 'bg-slate-100 text-slate-600',
  }[tone]
}

const resultLabel = result => props.options.results[result]?.label ?? result

// Asia/Kolkata is the application timezone and the server sends an ISO string
// with the offset baked in, so this renders the same instant wherever it is read
const dateTime = v => v
  ? new Date(v).toLocaleString('en-IN', {
      day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true,
    })
  : null

const lastReceived = card => dateTime(card.last_received_at) ?? 'No leads yet'

/*
 | A lead created in the fallback project because its form has no project. Its
 | result is still "Lead created" — the lead exists — so without this chip it
 | would look exactly like a routed one, and the alert would be the only sign a
 | mapping is missing. `routed` is null on rows where it does not apply.
 */
const unrouted = e => e.result === 'created' && e.routed === false

const unmappedForms = computed(() =>
  (facebookCard.value?.forms ?? []).filter(f => !f.project_id).length)
</script>

<template>

  <AppLayout title="Integrations" subtitle="Where leads come in from">

    <!-- ---------------- the platform cards ---------------- -->
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="card in cards" :key="card.provider"
           class="card flex flex-col p-4" :class="card.built ? '' : 'opacity-70'">

        <div class="mb-2 flex items-start justify-between gap-2">
          <h3 class="text-sm font-semibold text-slate-900">{{ card.name }}</h3>
          <span class="flex-none rounded-full px-2 py-0.5 text-[11px] font-semibold"
                :class="status(card).class">
            {{ status(card).label }}
          </span>
        </div>

        <p class="mb-4 flex-1 text-xs leading-relaxed text-slate-500">{{ card.description }}</p>

        <dl class="mb-4 space-y-1 border-t border-slate-100 pt-3 text-xs">
          <div class="flex justify-between gap-2">
            <dt class="text-slate-400">Last lead received</dt>
            <dd class="text-right font-medium text-slate-600">
              {{ card.built ? lastReceived(card) : '—' }}
            </dd>
          </div>
        </dl>

        <div class="flex gap-2">
          <button class="btn-ghost flex-1 !px-3 !py-1.5 !text-xs"
                  :disabled="!card.built" @click="openConfigure(card)">
            Configure
          </button>

          <!--
            Only on a connected card: a test lead runs the real import, so
            offering it before there is a project and an owner to file one
            against would only ever produce a failure in the log.
          -->
          <button v-if="card.built" class="btn flex-1 !px-3 !py-1.5 !text-xs"
                  :disabled="!card.connected || testing === card.provider"
                  :title="card.connected ? 'Runs the real import with a fake lead' : 'Connect the integration first'"
                  @click="sendTest(card)">
            {{ testing === card.provider ? 'Sending…' : 'Send test lead' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ---------------- what to do next ---------------- -->
    <div v-if="facebookCard && !facebookCard.connected" class="info-box mt-4">
      <strong>To switch Facebook on:</strong>
      configure it with the page access token and app secret from the client's Meta app,
      choose the fallback project and the telecaller the leads should go to, then paste the callback URL and
      verify token into the Meta app dashboard under Webhooks → Page → <code>leadgen</code>.
      Use <em>Send test lead</em> to prove the whole path works before Meta approval lands.
    </div>

    <div v-if="unmappedForms" class="warn-box mt-4 flex flex-wrap items-center justify-between gap-2">
      <span>
        <strong>{{ unmappedForms }} Facebook lead {{ unmappedForms === 1 ? 'form has' : 'forms have' }} no project.</strong>
        {{ unmappedForms === 1 ? 'Its' : 'Their' }} leads go to the fallback project until one is chosen.
      </span>
      <button class="btn-ghost !px-3 !py-1.5 !text-xs" @click="openConfigure(facebookCard)">Choose projects</button>
    </div>

    <!-- ---------------- activity log ---------------- -->
    <div class="card mt-6 overflow-hidden">
      <div class="border-b border-slate-100 px-4 py-3.5 sm:px-5">
        <h3 class="text-sm font-semibold text-slate-900">Recent activity</h3>
        <p class="mt-0.5 text-xs text-slate-500">
          The last {{ events.length }} incoming enquiries and what happened to each.
          Leads that stop arriving show up here as nothing new — that is what this table is for.
        </p>
      </div>

      <div v-if="!events.length" class="px-5 py-14 text-center text-sm text-slate-500">
        <p class="mb-1 font-semibold text-slate-700">Nothing has come in yet</p>
        Incoming leads and any errors will be listed here.
      </div>

      <!-- table on desktop, cards on mobile: the same pattern as the other pages -->
      <table v-else class="hidden w-full text-sm lg:table">
        <thead>
          <tr class="bg-slate-50 text-left text-xs text-slate-500">
            <th class="px-4 py-2.5 font-semibold">When</th>
            <th class="px-4 py-2.5 font-semibold">Platform</th>
            <th class="px-4 py-2.5 font-semibold">Result</th>
            <th class="px-4 py-2.5 font-semibold">Details</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in events" :key="e.id" class="border-t border-slate-100">
            <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ dateTime(e.created_at) }}</td>
            <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ e.provider_name }}</td>
            <td class="px-4 py-2.5">
              <div class="flex flex-col items-start gap-1">
                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="resultChip(e.result)">
                  {{ resultLabel(e.result) }}
                </span>
                <span v-if="unrouted(e)" class="whitespace-nowrap rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200">
                  Unrouted · fallback project
                </span>
              </div>
            </td>
            <td class="px-4 py-2.5 text-slate-600">
              <span v-if="e.form_name" class="block text-xs text-slate-500">Form: {{ e.form_name }}</span>
              <span class="break-words">{{ e.message }}</span>
              <span v-if="e.external_id" class="mt-0.5 block font-mono text-[11px] text-slate-400">
                {{ e.external_id }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="events.length" class="divide-y divide-slate-100 lg:hidden">
        <div v-for="e in events" :key="e.id" class="px-4 py-3">
          <div class="mb-1 flex items-center justify-between gap-2">
            <span class="flex flex-wrap gap-1">
              <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="resultChip(e.result)">
                {{ resultLabel(e.result) }}
              </span>
              <span v-if="unrouted(e)" class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200">
                Unrouted · fallback project
              </span>
            </span>
            <span class="text-xs text-slate-400">{{ dateTime(e.created_at) }}</span>
          </div>
          <p class="text-xs font-medium text-slate-700">
            {{ e.provider_name }}<template v-if="e.form_name"> · {{ e.form_name }}</template>
          </p>
          <p class="mt-0.5 break-words text-xs text-slate-500">{{ e.message }}</p>
        </div>
      </div>
    </div>

    <FacebookSettingsModal
      v-if="facebookCard"
      :show="configuring === 'facebook'"
      :card="facebookCard"
      :options="options"
      @close="configuring = null"
    />
  </AppLayout>
</template>
