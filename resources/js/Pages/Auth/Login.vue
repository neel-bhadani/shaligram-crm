<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import FormField from '@/Components/FormField.vue'

/*
 | One page, two tabs. /login opens it on Sign in and /signup on Sign up, so
 | each tab has an address of its own: a failed sign-up redirects back to the
 | tab it came from, and a link to /signup lands on the right form.
 |
 | The tab switch is a visit with preserve-state, so the component — and both
 | forms with whatever was typed into them — survives the move between the two.
 |
 | Sign up asks for an account, it does not make one that works. The server
 | writes it switched off and pending; the role list comes from
 | config('crm.staff_roles') by way of the controller, and SignupRequest refuses
 | anything else — admin included — whatever this dropdown offers.
 */
const props = defineProps({
  status: String,
  tab: { type: String, default: 'signin' },
  roles: { type: Array, default: () => [] },
})

const isSignup = computed(() => props.tab === 'signup')

const tabs = [
  { key: 'signin', label: 'Sign in', href: () => route('login') },
  { key: 'signup', label: 'Sign up', href: () => route('signup') },
]

/* ---------------- sign in ---------------- */

const showPw = ref(false)

const login = useForm({
  login: '',
  password: '',
  remember: true,
})

const submitLogin = () => login.post(route('login'), {
  onFinish: () => login.reset('password'),
})

/* ---------------- sign up ---------------- */

const signup = useForm({
  first_name: '',
  last_name: '',
  email: '',
  mobile_number: '',
  role: '',
  password: '',
  password_confirmation: '',
})

const submitSignup = () => signup.post(route('signup.store'), {
  onFinish: () => signup.reset('password', 'password_confirmation'),
})
</script>

<template>
  <Head :title="isSignup ? 'Sign up' : 'Sign in'" />

  <div class="flex min-h-screen">

    <!-- left: image panel, hidden on phones -->
    <div class="relative hidden w-[52%] flex-col justify-between overflow-hidden bg-slate-900 p-12 text-white md:flex">
      <div
        class="absolute inset-0 opacity-[0.06]"
        style="background-image:linear-gradient(#fff 1px,transparent 1px),linear-gradient(90deg,#fff 1px,transparent 1px);background-size:44px 44px"
      />
      <div class="absolute -bottom-40 -right-36 h-[520px] w-[520px] rounded-full
                  bg-[radial-gradient(circle_at_30%_30%,rgba(15,118,110,.55),transparent_62%)]" />

      <div class="relative flex items-center gap-2.5 font-bold">
        <span class="block h-6 w-6 rounded border-2 border-teal-500"></span> Shaligram CRM
      </div>

      <div class="relative">
        <h2 class="mb-4 max-w-[15ch] text-4xl font-bold leading-tight tracking-tight">
          Every enquiry, followed up on time.
        </h2>
        <p class="max-w-[44ch] text-slate-300">
          Track site visits across your projects, know which channel brought each buyer,
          and never lose a lead to a forgotten callback.
        </p>
      </div>

      <div class="relative flex gap-9 border-t border-white/15 pt-7">
        <div><div class="text-2xl font-bold">312</div><div class="text-xs text-slate-400">Leads this quarter</div></div>
        <div><div class="text-2xl font-bold">86</div><div class="text-xs text-slate-400">Site visits</div></div>
        <div><div class="text-2xl font-bold">19</div><div class="text-xs text-slate-400">Bookings</div></div>
      </div>
    </div>

    <!-- right: the forms -->
    <div class="flex flex-1 items-center justify-center bg-white dark:bg-slate-800 px-6 py-10">
      <div class="w-full max-w-sm">

        <div class="mb-7 flex gap-1 border-b border-slate-100 dark:border-slate-700/60" role="tablist">
          <Link v-for="t in tabs" :key="t.key" :href="t.href()" preserve-state
                role="tab" :aria-selected="tab === t.key"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                :class="tab === t.key
                  ? 'border-teal-700 font-semibold text-slate-900 dark:text-slate-100'
                  : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'">
            {{ t.label }}
          </Link>
        </div>

        <!-- ================= sign in ================= -->
        <template v-if="!isSignup">
          <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Sign in</h1>
          <p class="mb-7 text-sm text-slate-500 dark:text-slate-400">Use your email address or mobile number.</p>

          <div v-if="status" class="mb-4 text-sm font-medium text-teal-700 dark:text-teal-300">{{ status }}</div>

          <!--
            Waiting for approval, or turned down. Not a red line under the email
            field: the person typed nothing wrong, and a field error would send
            them round retyping a password that was right.
          -->
          <div v-if="login.errors.approval" class="warn-box mb-4 !text-sm" role="status">
            {{ login.errors.approval }}
          </div>

          <form class="space-y-4" @submit.prevent="submitLogin">

            <FormField label="Email or mobile number" required :error="login.errors.login">
              <input v-model="login.login" type="text" autocomplete="username" autofocus />
            </FormField>

            <FormField label="Password" required :error="login.errors.password">
              <div class="relative">
                <input v-model="login.password" :type="showPw ? 'text' : 'password'" autocomplete="current-password" />
                <button
                  type="button"
                  class="absolute right-2 top-1/2 -translate-y-1/2 px-2 text-xs text-slate-400"
                  @click="showPw = !showPw"
                >{{ showPw ? 'Hide' : 'Show' }}</button>
              </div>
            </FormField>

            <label class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
              <input v-model="login.remember" type="checkbox" class="!min-h-0 !w-auto rounded" />
              Keep me signed in
            </label>

            <button type="submit" class="btn w-full py-2.5" :disabled="login.processing">
              {{ login.processing ? 'Signing in…' : 'Sign in' }}
            </button>
          </form>

          <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
            New here?
            <Link :href="route('signup')" preserve-state class="font-semibold text-teal-700 dark:text-teal-300 hover:underline">
              Request an account
            </Link>
          </p>
        </template>

        <!-- ================= sign up ================= -->
        <template v-else>
          <h1 class="mb-1.5 text-2xl font-semibold tracking-tight">Request an account</h1>
          <p class="mb-7 text-sm text-slate-500 dark:text-slate-400">
            An administrator approves every new account. You can sign in once they have.
          </p>

          <!-- the rate limit: about the whole form, not one field -->
          <div v-if="signup.errors.signup" class="warn-box mb-4 !text-sm" role="alert">
            {{ signup.errors.signup }}
          </div>

          <form class="space-y-4" @submit.prevent="submitSignup">
            <div class="grid gap-4 sm:grid-cols-2">
              <FormField label="First name" required :error="signup.errors.first_name">
                <input v-model="signup.first_name" type="text" autocomplete="given-name" />
              </FormField>
              <FormField label="Last name" required :error="signup.errors.last_name">
                <input v-model="signup.last_name" type="text" autocomplete="family-name" />
              </FormField>
            </div>

            <FormField label="Email" required :error="signup.errors.email">
              <input v-model="signup.email" type="email" autocomplete="email" />
            </FormField>

            <FormField label="Mobile number" required :error="signup.errors.mobile_number">
              <input v-model="signup.mobile_number" type="text" maxlength="10" inputmode="numeric"
                     autocomplete="tel-national" />
            </FormField>

            <FormField label="Role" required :error="signup.errors.role">
              <select v-model="signup.role">
                <option value="" disabled>Choose your role</option>
                <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
              </select>
            </FormField>

            <FormField label="Password" required hint="At least 8 characters." :error="signup.errors.password">
              <input v-model="signup.password" type="password" autocomplete="new-password" />
            </FormField>

            <FormField label="Confirm password" required :error="signup.errors.password_confirmation">
              <input v-model="signup.password_confirmation" type="password" autocomplete="new-password" />
            </FormField>

            <button type="submit" class="btn w-full py-2.5" :disabled="signup.processing">
              {{ signup.processing ? 'Sending request…' : 'Request account' }}
            </button>
          </form>

          <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
            Already have an account?
            <Link :href="route('login')" preserve-state class="font-semibold text-teal-700 dark:text-teal-300 hover:underline">
              Sign in
            </Link>
          </p>
        </template>
      </div>
    </div>
  </div>
</template>
