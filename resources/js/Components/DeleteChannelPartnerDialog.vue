<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'

/*
 | The delete confirmation, which is a soft delete and says so — the same shape
 | as DeleteUserDialog, and here for the same reason ConfirmDialog was not
 | enough there: this one has to carry a number before it asks.
 |
 | Two facts belong on screen before an admin clicks:
 |
 |   how many leads point at this partner. Not an obstacle — a soft-deleted
 |   partner keeps every one of them and keeps counting on the reports under a
 |   name marked "(removed)" — but it is the size of what is being put away.
 |
 |   whether this is a firm with live brokers under it, which is the one case
 |   the server refuses outright. Those brokers would be left pointing at a
 |   deleted parent, losing the half of "Ravi Kumar — Shreeji Realty" that tells
 |   two Ravis apart, with nothing on screen able to put it right.
 |
 | The button is disabled for that second case and the server refuses it again
 | through ChannelPartnerController::destroy() — disabling a button the server
 | would reject anyway is the difference between an explanation and a dead end.
 */
const props = defineProps({ show: Boolean, partner: Object })
const emit = defineEmits(['close'])

const form = useForm({})

watch(() => props.show, v => { if (v) form.clearErrors() })

const isFirm = computed(() => props.partner?.type === 'firm')
const activeBrokers = computed(() => props.partner?.active_brokers_count ?? 0)
const leads = computed(() => props.partner?.leads_count ?? 0)

const blocked = computed(() => isFirm.value && activeBrokers.value > 0)

const submit = () => form.delete(route('channel-partners.destroy', props.partner.id), {
  preserveScroll: true,
  onSuccess: () => emit('close'),
})
</script>

<template>
  <Modal :show="show" title="Delete channel partner" max-width="max-w-lg" @close="emit('close')">
    <p class="text-sm text-slate-600 dark:text-slate-300">
      <span class="font-semibold text-slate-800 dark:text-slate-200">{{ partner?.display_label }}</span>
      will no longer appear on the lead form or in this list.
    </p>

    <!-- the number this dialog exists to put on screen -->
    <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">
      <template v-if="leads">
        <span class="font-semibold text-slate-800 dark:text-slate-200">{{ leads }}</span>
        lead{{ leads === 1 ? '' : 's' }} came through this partner.
      </template>
      <template v-else>
        No leads have been filed against this partner yet.
      </template>
    </p>

    <p class="info-box mt-3">
      This is a soft delete. Every lead that came through this partner keeps pointing at
      it, so the leads report goes on crediting the business to it — the row is simply
      marked as removed. Nothing is recounted and nothing moves into another broker's column.
    </p>

    <!-- the one refusal, stated with the number that makes it actionable -->
    <div v-if="blocked" class="warn-box mt-3">
      This firm has <span class="font-semibold">{{ activeBrokers }}</span>
      active broker{{ activeBrokers === 1 ? '' : 's' }} filed under it. Reassign them to
      another firm, or switch them off, before deleting the firm — otherwise they are left
      pointing at a firm nothing on this page can name any more.
    </div>

    <!-- the same refusal coming back from the server, if the button is bypassed -->
    <p v-if="form.errors.partner" class="mt-3 text-sm font-medium text-rose-700 dark:text-rose-300">
      {{ form.errors.partner }}
    </p>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn-danger flex-1 sm:flex-none disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="form.processing || blocked" @click="submit">
        {{ form.processing ? 'Working…' : 'Delete partner' }}
      </button>
    </template>
  </Modal>
</template>
