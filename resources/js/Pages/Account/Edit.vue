<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'

/*
 | My profile — the signed-in user's own row, for every role.
 |
 | Five things are editable and only five. Role, permissions and account
 | status are shown in the side panel so people know what they have and who
 | to ask, but they are not in the form and never sent. That is presentation:
 | ProfileRequest refuses all of them if they are posted, which is the lock.
 |
 | No Delete button, deliberately. Removing a person means handing their open
 | leads and follow-ups to somebody first, and only an admin can decide who.
 */
const props = defineProps({ account: Object, access: Object })

const form = useForm({
  first_name: props.account.first_name ?? '',
  last_name: props.account.last_name ?? '',
  email: props.account.email ?? '',
  mobile_number: props.account.mobile_number ?? '',
  // blank means unchanged, the same convention as the Users page
  current_password: '',
  password: '',
  password_confirmation: '',
})

const submit = () => form.put(route('account.update'), {
  preserveScroll: true,
  // never leave a password sitting in the form after a save, either way
  onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
})
</script>

<template>
  <Head title="My profile" />

  <AppLayout title="My profile" subtitle="Your name, contact details and password">
    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">

      <form class="card p-5 sm:p-6" @submit.prevent="submit">
        <h2 class="mb-4 text-sm font-semibold text-slate-900 dark:text-slate-100">Details</h2>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField label="First name" required :error="form.errors.first_name">
            <input v-model="form.first_name" type="text" autocomplete="given-name" />
          </FormField>
          <FormField label="Last name" required :error="form.errors.last_name">
            <input v-model="form.last_name" type="text" autocomplete="family-name" />
          </FormField>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <FormField label="Email" required :error="form.errors.email">
            <input v-model="form.email" type="email" autocomplete="email" />
          </FormField>
          <FormField label="Mobile number" required :error="form.errors.mobile_number">
            <input v-model="form.mobile_number" type="text" maxlength="10" inputmode="numeric"
                   autocomplete="tel-national" />
          </FormField>
        </div>

        <h2 class="mb-1 mt-7 border-t border-slate-100 dark:border-slate-700/60 pt-5 text-sm font-semibold text-slate-900 dark:text-slate-100">
          Change password
        </h2>
        <p class="mb-4 text-xs text-slate-400">Leave these blank to keep your current password.</p>

        <FormField label="Current password" :error="form.errors.current_password" class="sm:max-w-[calc(50%-0.5rem)]">
          <input v-model="form.current_password" type="password" autocomplete="current-password" />
        </FormField>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <FormField label="New password" hint="At least 8 characters." :error="form.errors.password">
            <input v-model="form.password" type="password" autocomplete="new-password" />
          </FormField>
          <FormField label="Confirm new password" :error="form.errors.password_confirmation">
            <input v-model="form.password_confirmation" type="password" autocomplete="new-password" />
          </FormField>
        </div>

        <div class="mt-6 flex justify-end border-t border-slate-100 dark:border-slate-700/60 pt-5">
          <button type="submit" class="btn w-full sm:w-auto" :disabled="form.processing">
            {{ form.processing ? 'Saving…' : 'Save changes' }}
          </button>
        </div>
      </form>

      <!-- read-only: the admin's side of the account -->
      <aside class="card p-5 sm:p-6">
        <h2 class="mb-4 text-sm font-semibold text-slate-900 dark:text-slate-100">Your access</h2>

        <dl class="mb-4 text-sm">
          <dt class="text-xs font-semibold text-slate-500 dark:text-slate-400">Role</dt>
          <dd class="mt-0.5 text-slate-800 dark:text-slate-200">{{ access.role }}</dd>
        </dl>

        <div class="mb-1 text-xs font-semibold text-slate-500 dark:text-slate-400">Permissions</div>
        <ul class="mb-5 divide-y divide-slate-100 dark:divide-slate-700/60 text-sm">
          <li v-for="p in access.permissions" :key="p.label" class="flex items-center justify-between gap-3 py-2">
            <span :class="p.on ? 'text-slate-800 dark:text-slate-200' : 'text-slate-400'">{{ p.label }}</span>
            <span class="flex-none rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="p.on ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-800 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400'">
              {{ p.on ? 'Yes' : 'No' }}
            </span>
          </li>
        </ul>

        <p class="info-box">
          Your role and permissions are set by an administrator. Ask them if you need
          something changed — or if you are leaving, so your leads can be handed over.
        </p>
      </aside>
    </div>
  </AppLayout>
</template>
