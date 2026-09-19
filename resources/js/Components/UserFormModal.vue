<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import UserHandoverFields from './UserHandoverFields.vue'

/*
 | Add and edit staff, one modal for both — the same shape as LeadFormModal, so
 | the two forms in this application behave identically.
 |
 | Three things here are not in the lead form and each is a rule from the
 | server said again on screen. None of them is the guarantee: UserRequest is.
 |
 |   the password pair   required when adding, "leave blank to keep" when
 |                       editing.
 |   the demotion warning a salesperson moved to telecaller keeps every lead
 |                       they hold, including the ones at stages a telecaller
 |                       does not work. The admin is told how many and allowed
 |                       to proceed.
 |   the handover        switching somebody off strands their open leads. The
 |                       fields only appear on the save that actually does it.
 */
const props = defineProps({ show: Boolean, user: Object, options: Object })
const emit = defineEmits(['close'])

const blank = {
  first_name: '', last_name: '', email: '', mobile_number: '',
  role: 'telecaller', password: '', password_confirmation: '',
  is_active: true, permissions: {},
  // only ever sent when deactivating an existing user
  handover_to: '', leave_unassigned: false,
}

const form = useForm({ ...blank })
const tab = ref('details')

/** The role's baseline, which is what an untouched new user starts on. */
const defaultsFor = role => ({ ...(props.options.permissionDefaults[role] ?? {}) })

watch(() => props.show, v => {
  form.clearErrors()

  if (!v) return

  tab.value = 'details'

  if (props.user) {
    Object.assign(form, {
      ...blank,
      first_name: props.user.first_name ?? '',
      last_name: props.user.last_name ?? '',
      email: props.user.email ?? '',
      mobile_number: props.user.mobile_number ?? '',
      role: props.user.role,
      is_active: props.user.is_active,
      // the resolved set from the server: what is in force, never a blank
      // where a role default is quietly doing the work
      permissions: { ...props.user.permissions },
    })
  } else {
    Object.assign(form, { ...blank, permissions: defaultsFor(blank.role) })
  }
})

/*
 | Changing the role on a NEW user re-seeds the toggles, because nothing has
 | been chosen yet and the defaults are the point of the role. On an existing
 | user it deliberately does not: those toggles may have been set deliberately,
 | and silently rewriting them while the admin was changing a job title is how
 | a permission grant disappears without anyone noticing.
 */
watch(() => form.role, role => {
  if (!props.user) form.permissions = defaultsFor(role)
})

const isEdit = computed(() => !!props.user)

/* ---------------- the guards, mirrored from the server ---------------- */

const isSelf = computed(() => props.user?.id === props.options.currentUserId)

const isLastActiveAdmin = computed(() =>
  props.user?.role === 'admin' && props.user.is_active && props.options.activeAdminCount <= 1)

/** Why the active toggle is locked, or '' when it is not. */
const lockedReason = computed(() => {
  if (!isEdit.value) return ''
  // a sign-up is switched on by approving it — UserRequest refuses this too
  if (props.user.approval_status && props.user.approval_status !== 'approved') {
    return 'This account has not been approved. Use Approve on the Users list to let them in.'
  }
  if (isSelf.value) return 'You cannot deactivate your own account.'
  if (isLastActiveAdmin.value) return 'This is the last active admin. Promote somebody else first.'
  return ''
})

/*
 | An existing admin keeps their role and cannot be moved out of it here, the
 | same way one cannot be created here. The dropdown offers the two staff roles
 | from config; for an admin it shows their own role, disabled.
 */
const roleLocked = computed(() => isEdit.value && props.user.role === 'admin')

/*
 | The demotion warning. Only when an existing salesperson is being moved to a
 | role that does not work those stages, and only when they are actually
 | holding some — a warning that says "0 leads" trains people to dismiss
 | warnings.
 */
const demotionCount = computed(() => {
  if (!isEdit.value || props.user.role !== 'salesperson') return 0
  if (form.role === 'salesperson') return 0
  return props.user.advanced_leads_count ?? 0
})

/** This save is switching a currently-active user off. */
const deactivating = computed(() => isEdit.value && props.user.is_active && !form.is_active)

const workload = computed(() => ({
  leads: props.user?.open_leads_count ?? 0,
  todos: props.user?.pending_todos_count ?? 0,
}))

const holdsWork = computed(() => workload.value.leads + workload.value.todos > 0)

const needsHandover = computed(() => deactivating.value && holdsWork.value)

// same role only, and never the person being switched off — HandsOverWork
// checks the identical three things on the way back in
const candidates = computed(() =>
  props.options.assignable.filter(u => u.role === props.user?.role && u.id !== props.user?.id))

const submit = () => {
  const opts = { preserveScroll: true, onSuccess: () => emit('close') }

  props.user
    ? form.put(route('users.update', props.user.id), opts)
    : form.post(route('users.store'), opts)
}
</script>

<template>
  <Modal :show="show" :title="isEdit ? 'Edit user' : 'Add user'" @close="emit('close')">

    <!-- two tabs rather than one very long form; the permissions set is its
         own decision and reads better away from the name and the password -->
    <div class="mb-5 flex gap-1 border-b border-slate-100 dark:border-slate-700/60">
      <button v-for="t in [['details', 'Details'], ['permissions', 'Permissions']]" :key="t[0]"
              class="border-b-2 px-3 py-2 text-sm font-medium"
              :class="tab === t[0]
                ? 'border-teal-700 font-semibold text-slate-900 dark:text-slate-100'
                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'"
              @click="tab = t[0]">{{ t[1] }}</button>
    </div>

    <template v-if="tab === 'details'">
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="First name" required :error="form.errors.first_name">
          <input v-model="form.first_name" type="text" />
        </FormField>
        <FormField label="Last name" required :error="form.errors.last_name">
          <input v-model="form.last_name" type="text" />
        </FormField>
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <FormField label="Email" required :error="form.errors.email">
          <input v-model="form.email" type="email" autocomplete="off" />
        </FormField>
        <FormField label="Mobile number" required :error="form.errors.mobile_number">
          <input v-model="form.mobile_number" type="text" maxlength="10" inputmode="numeric" />
        </FormField>
      </div>

      <FormField class="mt-4" label="Role" required
                 :hint="roleLocked
                   ? 'An admin\'s role cannot be changed from this screen.'
                   : 'Admins are not created here — only a telecaller or a salesperson.'"
                 :error="form.errors.role">
        <select v-model="form.role" :disabled="roleLocked">
          <option v-if="roleLocked" value="admin">{{ options.roleLabels.admin }}</option>
          <option v-for="r in options.staffRoles" :key="r" :value="r">{{ options.roleLabels[r] }}</option>
        </select>
      </FormField>

      <!-- the demotion warning: a number, and permission to proceed anyway -->
      <div v-if="demotionCount" class="warn-box mt-3">
        {{ demotionCount }} of this user's leads {{ demotionCount === 1 ? 'is' : 'are' }} at
        a site-visit stage or later, which a telecaller does not work. They stay with this
        user — nothing is reassigned automatically. Move them from the Leads page if they
        should go to somebody else.
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <FormField :label="isEdit ? 'New password' : 'Password'" :required="!isEdit"
                   :hint="isEdit ? 'Leave blank to keep the current password.' : 'At least 8 characters.'"
                   :error="form.errors.password">
          <input v-model="form.password" type="password" autocomplete="new-password" />
        </FormField>
        <FormField label="Confirm password" :required="!isEdit"
                   :error="form.errors.password_confirmation">
          <input v-model="form.password_confirmation" type="password" autocomplete="new-password" />
        </FormField>
      </div>

      <FormField class="mt-4" label="Status" :error="form.errors.is_active">
        <label class="flex items-center gap-2.5 text-sm"
               :class="lockedReason ? 'cursor-not-allowed text-slate-400' : 'text-slate-700 dark:text-slate-300'">
          <input v-model="form.is_active" type="checkbox" :disabled="!!lockedReason"
                 class="h-4 w-4 rounded border-slate-300 dark:border-slate-600" />
          Active — can sign in
        </label>
        <p v-if="lockedReason" class="mt-1.5 text-xs text-slate-400">{{ lockedReason }}</p>
      </FormField>

      <!--
        Only on the save that actually strands work. UserRequest demands the
        same choice on exactly the same condition, so this cannot be skipped by
        closing the panel.
      -->
      <UserHandoverFields
        v-if="needsHandover"
        v-model:target="form.handover_to"
        v-model:confirmed="form.leave_unassigned"
        class="mt-5"
        :user="user" :workload="workload" :candidates="candidates"
        :error="form.errors.handover_to"
        verb="deactivated"
      />
    </template>

    <!--
      Permissions. Every toggle carries the value in force, whether it was set
      by hand or inherited from the role, so what is on screen is what gets
      written down.
    -->
    <template v-else>
      <p class="info-box mb-4">
        Role is still the default. These override it for this one person — useful for a
        sales manager who should see the whole pipeline.
      </p>

      <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
        <label v-for="(meta, key) in options.permissions" :key="key"
               class="flex cursor-pointer items-start gap-3 py-3">
          <input v-model="form.permissions[key]" type="checkbox"
                 class="mt-0.5 h-4 w-4 flex-none rounded border-slate-300 dark:border-slate-600" />
          <span class="min-w-0">
            <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">{{ meta.label }}</span>
            <span class="block text-xs text-slate-400">{{ meta.hint }}</span>
          </span>
        </label>
      </div>

      <p v-if="form.errors.permissions" class="mt-2 text-xs text-rose-600 dark:text-rose-400">{{ form.errors.permissions }}</p>
    </template>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn flex-1 sm:flex-none" :disabled="form.processing" @click="submit">
        {{ form.processing ? 'Saving…' : (isEdit ? 'Save changes' : 'Add user') }}
      </button>
    </template>
  </Modal>
</template>
