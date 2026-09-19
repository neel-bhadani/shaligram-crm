<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'

/*
 | EDITING a channel partner, or ADDING one — the same modal, the same fields,
 | the same rule-set, one modal on the roster page.
 |
 | `partner` is what says which. Present, the title reads "Edit channel
 | partner", the form is loaded from the row and the submit is a PUT. Absent
 | (created by the page's Add button or the `adding` prop), it reads "Add
 | channel partner", the form starts blank and the submit is a POST to
 | channel-partners.store. A modal that served only one of those would hide a
 | dead branch; serving both is what keeps one partner form from drifting into
 | two.
 |
 | One field is conditional and it is the whole of the hierarchy: Parent firm
 | appears only for a broker. A firm is the top of the tree and has no parent,
 | and a broker's parent may only be a firm, which is what caps the nesting at
 | one level. ChannelPartnerRequest refuses every shape this hides, on the
 | create POST exactly as on the edit PUT, so the conditional is an explanation
 | rather than the guarantee.
 */
const props = defineProps({ show: Boolean, partner: Object, options: Object })
const emit = defineEmits(['close'])

const blank = {
  name: '', type: 'broker', parent_id: '',
  contact_person: '', phone: '', alt_phone: '', email: '', address: '',
  is_active: true,
}

const form = useForm({ ...blank })

/** Absent `partner` is the create branch — the Add button opens the modal with editing = null. */
const isAdd = computed(() => !props.partner)

watch(() => props.show, v => {
  form.clearErrors()

  if (!v) return

  // blank first, so a create that follows an edit (or vice versa) never
  // carries a row's stale values into another mode
  Object.assign(form, { ...blank })

  if (!props.partner) return

  Object.assign(form, {
    ...blank,
    name: props.partner.name ?? '',
    type: props.partner.type,
    // '' rather than null: the <select> has an empty option, and null would
    // leave it showing nothing selected at all
    parent_id: props.partner.parent_id ?? '',
    contact_person: props.partner.contact_person ?? '',
    phone: props.partner.phone ?? '',
    alt_phone: props.partner.alt_phone ?? '',
    email: props.partner.email ?? '',
    address: props.partner.address ?? '',
    is_active: props.partner.is_active,
  })
})

const isBroker = computed(() => form.type === 'broker')

/*
 | Clear the parent the moment the type switches to firm, or a stale value gets
 | saved: the field goes off screen but the form object would keep whatever was
 | last chosen in it. ChannelPartnerRequest rejects a firm that arrives carrying
 | a parent, and channelPartnerAttributes() nulls it a second time — three
 | guards for one field, because the shape it lets through is the one nothing
 | downstream knows how to read.
 */
watch(isBroker, broker => { if (!broker) form.parent_id = '' })

/*
 | A firm cannot become a broker while brokers are filed under it — those
 | brokers would end up under a broker, which is the nesting this table forbids.
 | The number is on screen because "you cannot" without "here is how many" is a
 | dead end; the server repeats the refusal either way.
 */
const blockedDemotion = computed(() =>
  props.partner?.type === 'firm' && form.type === 'broker'
    ? props.partner.brokers_count ?? 0
    : 0)

/*
 | A firm never offers itself as its own parent. It cannot get here — the field
 | is hidden for firms — but a row edited from broker to firm and back again
 | would flash it into the list, and the server refuses it anyway.
 */
const firms = computed(() =>
  props.options.firms.filter(f => f.id !== props.partner?.id))

const submit = () => {
  const onSuccess = () => emit('close')

  if (props.partner) {
    form.put(route('channel-partners.update', props.partner.id), {
      preserveScroll: true,
      onSuccess,
    })
  } else {
    form.post(route('channel-partners.store'), {
      preserveScroll: true,
      onSuccess,
    })
  }
}
</script>

<template>
  <Modal :show="show" :title="isAdd ? 'Add channel partner' : 'Edit channel partner'"
         @close="emit('close')">

    <div class="grid gap-4 sm:grid-cols-2">
      <FormField label="Type" required
                 hint="A firm is an organisation. A broker is a person, alone or under a firm."
                 :error="form.errors.type">
        <select v-model="form.type">
          <option v-for="(label, key) in options.types" :key="key" :value="key">{{ label }}</option>
        </select>
      </FormField>

      <FormField label="Name" required
                 hint="Renaming is how a spelling entered mid-call gets fixed. Merge is how two rows for one partner get joined."
                 :error="form.errors.name">
        <input v-model="form.name" type="text"
               :placeholder="isBroker ? 'Ravi Kumar' : 'Shreeji Realty'" />
      </FormField>
    </div>

    <div v-if="blockedDemotion" class="warn-box mt-3">
      {{ blockedDemotion }} broker{{ blockedDemotion === 1 ? ' is' : 's are' }} filed under this
      firm, so it cannot become a broker itself — a broker cannot sit under another broker.
      Move them to a different firm first, from this same list.
    </div>

    <!--
      Brokers only. A firm is the top of the tree, and one level is the whole
      depth this table has: a broker's parent must be a firm, and a firm has
      none.
    -->
    <FormField v-if="isBroker" class="mt-4" label="Parent firm"
               hint="Leave blank for an individual broker who does not work under a firm."
               :error="form.errors.parent_id">
      <select v-model="form.parent_id">
        <option value="">Independent — no firm</option>
        <option v-for="f in firms" :key="f.id" :value="f.id">{{ f.name }}</option>
      </select>
    </FormField>

    <FormField class="mt-4" label="Contact person"
               :hint="isBroker ? 'Optional. Usually only needed for a firm.' : 'Who you actually ask for at this firm.'"
               :error="form.errors.contact_person">
      <input v-model="form.contact_person" type="text" />
    </FormField>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <FormField label="Phone" required :error="form.errors.phone">
        <input v-model="form.phone" type="text" inputmode="tel" />
      </FormField>
      <FormField label="Alternate phone" :error="form.errors.alt_phone">
        <input v-model="form.alt_phone" type="text" inputmode="tel" />
      </FormField>
    </div>

    <FormField class="mt-4" label="Email" :error="form.errors.email">
      <input v-model="form.email" type="email" autocomplete="off" />
    </FormField>

    <FormField class="mt-4" label="Address" :error="form.errors.address">
      <textarea v-model="form.address" rows="2"></textarea>
    </FormField>

    <FormField class="mt-4" label="Status" :error="form.errors.is_active">
      <label class="flex items-center gap-2.5 text-sm text-slate-700 dark:text-slate-300">
        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 dark:border-slate-600" />
        Active — offered on the lead form
      </label>
      <!--
        Said out loud, because "inactive" in most applications means "hidden".
        Here it means "no new leads", and the leads already attributed to this
        partner keep counting on every report.
      -->
      <p class="mt-1.5 text-xs text-slate-400">
        Switching a partner off takes them out of the lead form's picker. Leads already
        filed against them keep their attribution and stay on the reports.
      </p>
    </FormField>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : isAdd ? 'Add channel partner' : 'Save changes' }}
      </button>
    </template>
  </Modal>
</template>
