<script setup>
import { Head, Link } from '@inertiajs/vue3'

/*
 | The end of a sign-up. Nobody is logged in: the account exists, switched off
 | and pending, and this page's whole job is to say so plainly enough that the
 | person does not try to sign in straight away and conclude it is broken.
 |
 | `account` is a one-time flash — present on the redirect from the form, gone
 | on a refresh. The page reads the same without it, just less personally.
 */
defineProps({ account: Object })
</script>

<template>
  <Head title="Request sent" />

  <div class="flex min-h-screen items-center justify-center bg-slate-100 dark:bg-slate-700 px-6 py-10">
    <div class="card w-full max-w-md p-7 sm:p-9">
      <div class="mb-6 flex items-center gap-2.5 font-bold">
        <span class="block h-6 w-6 rounded border-2 border-teal-600"></span> Shaligram CRM
      </div>

      <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-full bg-teal-50 dark:bg-teal-500/10 text-teal-700 dark:text-teal-300">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 6 9 17l-5-5" />
        </svg>
      </div>

      <h1 class="mb-2 text-2xl font-semibold tracking-tight">
        {{ account?.name ? `Thanks, ${account.name}` : 'Request sent' }}
      </h1>

      <p class="mb-4 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
        Your account request has been sent to an administrator.
        <b class="text-slate-800 dark:text-slate-200">You will be able to sign in once they approve it.</b>
      </p>

      <p v-if="account?.email" class="mb-6 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
        When you are approved, sign in with <b class="text-slate-800 dark:text-slate-200">{{ account.email }}</b>
        or your mobile number and the password you just chose.
      </p>
      <p v-else class="mb-6 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
        When you are approved, sign in with your email or mobile number and the password you chose.
      </p>

      <div class="info-box mb-7">
        Until then, signing in will tell you the account is waiting for approval. If it has been a
        while, ask your administrator to check the Users page.
      </div>

      <Link :href="route('login')" class="btn w-full py-2.5">Back to sign in</Link>
    </div>
  </div>
</template>
