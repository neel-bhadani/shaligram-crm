<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'

/*
 | The guide.
 |
 | Written for the person who runs a builder's office, not for a developer. No
 | jargon, short paragraphs, and one worked example carried the whole way
 | through — the Facebook rule, because it is the one most offices switch on
 | first and it uses a trigger, a condition and two actions.
 |
 | A separate page rather than a panel on the tabs, because this is read once,
 | properly, by somebody who has just been handed the feature. A collapsible box
 | on the Rules tab would be skipped by exactly the person it is written for.
 |
 | Everything measurable on this page comes from the server: the thresholds, the
 | loop cap, the trigger and action lists. A guide that quoted "three days"
 | while the config said five would be worse than no guide.
 */
defineProps({
  triggers: Object,
  conditions: Object,
  actions: Object,
  categories: Object,
  thresholds: Object,
  loop: Object,
  whatsapp: Object,
})
</script>

<template>
  <AppLayout title="How automation works" subtitle="Everything on this page in plain words.">
    <template #actions>
      <Link :href="route('automation.index')" class="btn-ghost">Back to Automation</Link>
    </template>

    <div class="mx-auto max-w-3xl space-y-8 pb-8">

      <!-- ---------------- what it is ---------------- -->
      <section class="card p-5">
        <h2 class="text-base font-semibold text-slate-900">What this is for</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          Some things happen in the same way every single time. A Facebook lead comes in and
          somebody has to pick it up. A site visit finishes and somebody should call the next day.
          A follow-up gets forgotten and nobody notices for a week.
        </p>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          A <strong>rule</strong> does that part for you. You describe what to watch for and what
          to do about it, once, and it happens every time from then on.
        </p>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          You do not need to know anything technical. Every rule is written out as an ordinary
          sentence as you build it, and you can check exactly which leads it would affect before
          you switch it on.
        </p>
      </section>

      <!-- ---------------- the three parts ---------------- -->
      <section class="card p-5">
        <h2 class="text-base font-semibold text-slate-900">A rule has three parts</h2>

        <div class="mt-4 space-y-4">
          <div>
            <h3 class="text-sm font-semibold text-teal-800">1. The trigger — what has to happen</h3>
            <p class="mt-1 text-sm leading-relaxed text-slate-600">
              Every rule watches for exactly one thing. A lead being added. A lead moving to a
              particular stage. A follow-up going overdue.
            </p>
            <p class="mt-1 text-sm leading-relaxed text-slate-600">
              Some of these happen the instant somebody presses Save. Others — “a follow-up is
              overdue”, “a lead has been sitting still” — are questions rather than events, so the
              system asks them once an hour instead.
            </p>
            <ul class="mt-2 space-y-1 text-xs text-slate-500">
              <li v-for="(meta, key) in triggers" :key="key">
                · <strong class="text-slate-700">{{ meta.label }}</strong> — {{ meta.hint }}
              </li>
            </ul>
          </div>

          <div>
            <h3 class="text-sm font-semibold text-teal-800">2. The conditions — narrowing it down</h3>
            <p class="mt-1 text-sm leading-relaxed text-slate-600">
              Optional. Without any, the rule runs every time the trigger happens. With them, the
              rule only runs when <strong>all</strong> of them are true.
            </p>
            <p class="mt-1 text-sm leading-relaxed text-slate-600">
              There is deliberately no “either/or”. If you want Facebook leads and Instagram leads
              treated the same way, that is two rules. Two rules you can read at a glance are
              better than one rule you have to work out.
            </p>
            <ul class="mt-2 space-y-1 text-xs text-slate-500">
              <li v-for="(meta, key) in conditions" :key="key">
                · <strong class="text-slate-700">{{ meta.label }}</strong> — {{ meta.hint }}
              </li>
            </ul>
          </div>

          <div>
            <h3 class="text-sm font-semibold text-teal-800">3. The actions — what it does</h3>
            <p class="mt-1 text-sm leading-relaxed text-slate-600">
              One or more, in the order you arrange them. They all happen together or none of them
              do, so a lead can never end up half-processed.
            </p>
            <ul class="mt-2 space-y-1 text-xs text-slate-500">
              <li v-for="(meta, key) in actions" :key="key">
                · <strong class="text-slate-700">{{ meta.label }}</strong> — {{ meta.hint }}
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- ---------------- worked example ---------------- -->
      <section class="card p-5">
        <h2 class="text-base font-semibold text-slate-900">A real example, start to finish</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          Say you are running Facebook ads. Leads arrive at all hours and you want them picked up
          the same day rather than whenever somebody happens to look.
        </p>

        <ol class="mt-4 space-y-3 text-sm leading-relaxed text-slate-600">
          <li>
            <strong class="text-slate-800">Trigger:</strong> “A new lead is added.”
            The rule now watches every lead that arrives.
          </li>
          <li>
            <strong class="text-slate-800">Condition:</strong> Source is Facebook.
            Now it only cares about the ones from your ads — walk-ins and broker leads are left
            alone.
          </li>
          <li>
            <strong class="text-slate-800">First action:</strong> Share it out among the
            telecallers. They take turns, so nobody gets three in a row.
          </li>
          <li>
            <strong class="text-slate-800">Second action:</strong> Create a call follow-up in
            1 hour. It appears on that telecaller's Follow-ups page like any other call.
          </li>
        </ol>

        <div class="mt-4 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3.5">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-teal-700">
            What the page shows you as you build it
          </p>
          <p class="mt-1 text-sm font-medium leading-relaxed text-teal-900">
            When a lead is created, and the source is Facebook, assign it round-robin to a
            telecaller and create a call follow-up in 1 hour.
          </p>
        </div>

        <p class="mt-4 text-sm leading-relaxed text-slate-600">
          Read that sentence back. If it says what you meant, press <strong>Test</strong> — it
          will tell you how many of your existing leads fit, and show you the first ten. Nothing
          runs; it is just counting.
        </p>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          Then switch it on. You will be asked to confirm, with the number in front of you.
        </p>
      </section>

      <!-- ---------------- alerts vs follow-ups ---------------- -->
      <section class="card p-5">
        <h2 class="text-base font-semibold text-slate-900">Alerts are not follow-ups</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          This is the one distinction worth getting straight, because they look similar and behave
          completely differently.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div class="rounded-lg border border-slate-200 p-3.5">
            <h3 class="text-sm font-semibold text-slate-800">A follow-up is work</h3>
            <p class="mt-1.5 text-xs leading-relaxed text-slate-600">
              It belongs to a lead and to a person. It has a date. It shows on the Follow-ups page.
              Completing it records what happened and moves the lead along. Every open lead has
              exactly one, always.
            </p>
          </div>
          <div class="rounded-lg border border-slate-200 p-3.5">
            <h3 class="text-sm font-semibold text-slate-800">An alert is a message</h3>
            <p class="mt-1.5 text-xs leading-relaxed text-slate-600">
              It appears under the bell. It has no date and it is on nobody's list of work.
              Reading it changes <strong>nothing</strong> about the lead — not the stage, not the
              follow-up, not who it belongs to. It just stops showing as new.
            </p>
          </div>
        </div>

        <h3 class="mt-5 text-sm font-semibold text-slate-800">Alerts you get without writing a rule</h3>
        <ul class="mt-2 space-y-1.5 text-sm leading-relaxed text-slate-600">
          <li>· A follow-up more than <strong>{{ thresholds.overdue_days }} days</strong> overdue —
            the person holding it is told.</li>
          <li>· A lead that has not moved stage in <strong>{{ thresholds.stuck_days }} days</strong> —
            the person holding it is told.</li>
          <li>· Somebody switched off who still holds open leads — all admins are told. Those leads
            are on nobody's list until they are handed over.</li>
          <li>· Automation stopping itself to prevent a loop — all admins are told.</li>
        </ul>

        <p class="mt-3 text-sm leading-relaxed text-slate-600">
          The same alert is never repeated for the same lead and person within
          <strong>{{ thresholds.dedupe_hours }} hours</strong>. Without that, the hourly check
          would put the same message under your bell twenty-four times a day and you would stop
          looking at it by Tuesday.
        </p>

        <p class="mt-3 text-sm leading-relaxed text-slate-600">
          Nobody is ever alerted about a lead they are not allowed to see. If a rule says “tell
          every telecaller” and a lead belongs to one of them, only that one is told.
        </p>
      </section>

      <!-- ---------------- the queue ---------------- -->
      <section class="card p-5">
        <h2 class="text-base font-semibold text-slate-900">The Queue, and why messages are not sent for you</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          A rule can prepare a WhatsApp message — with the customer's name, project and your staff
          member's number already filled in — and put it in the Queue. It does not send it.
        </p>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          Somebody opens the Queue, reads the message, and presses “Open in WhatsApp”. WhatsApp
          opens with the message already typed and they press send. Because we hand it over at
          that point, the CRM records the message as <strong>opened</strong> rather than sent —
          we genuinely cannot tell whether the send button was pressed, and saying otherwise would
          make the message log worthless.
        </p>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          This costs nothing and works right now.
        </p>
      </section>

      <!-- ---------------- what WhatsApp API needs ---------------- -->
      <section class="card p-5">
        <h2 class="text-base font-semibold text-slate-900">What you need before messages can send themselves</h2>

        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          There are two different WhatsApp products and the difference matters:
        </p>

        <div class="mt-3 space-y-3">
          <div class="rounded-lg border border-slate-200 p-3.5">
            <h3 class="text-sm font-semibold text-slate-800">The WhatsApp Business app</h3>
            <p class="mt-1.5 text-xs leading-relaxed text-slate-600">
              Free, runs on a phone, what most offices already have. It has <strong>no way</strong>
              for software to send messages through it — not with any setting, any plugin or any
              amount of configuration. Click-to-send works with it, because a person is doing the
              sending.
            </p>
          </div>
          <div class="rounded-lg border border-slate-200 p-3.5">
            <h3 class="text-sm font-semibold text-slate-800">WhatsApp Business Platform</h3>
            <p class="mt-1.5 text-xs leading-relaxed text-slate-600">
              The paid one. Applied for through Meta or through a provider. This is what lets
              software send messages on its own, and what the “Send by API” button needs.
            </p>
          </div>
        </div>

        <h3 class="mt-5 text-sm font-semibold text-slate-800">Roughly what it involves</h3>
        <ul class="mt-2 space-y-1.5 text-sm leading-relaxed text-slate-600">
          <li>· A Meta Business account, verified — which means submitting company documents.</li>
          <li>· A phone number that is <strong>not</strong> already on the WhatsApp Business app.</li>
          <li>· A provider, or Meta directly. Providers charge a monthly fee on top of Meta's
            per-message cost.</li>
          <li>· Message templates approved by Meta before they can be sent. Approval usually takes
            a day or two.</li>
        </ul>

        <h3 class="mt-5 text-sm font-semibold text-slate-800">What it costs</h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          Meta charges per conversation, and the price depends on what kind of message it is:
        </p>
        <ul class="mt-2 space-y-1.5 text-sm leading-relaxed text-slate-600">
          <li v-for="(meta, key) in categories" :key="key">
            · <strong class="text-slate-800">{{ meta.label }}</strong> — {{ meta.cost_note }}
            <span class="block pl-3 text-xs text-slate-500">{{ meta.hint }}</span>
          </li>
        </ul>
        <p class="mt-3 text-sm leading-relaxed text-slate-600">
          The exact rates change, so ask your provider for current India pricing. The ratio is the
          part that matters when you are writing messages: a friendly festive greeting to two
          thousand leads costs around eight times what the same number of booking confirmations
          would.
        </p>

        <p class="mt-3 text-sm leading-relaxed text-slate-600">
          Until all of that is in place, the “Send by API” button will tell you plainly that it is
          not set up rather than appearing to work.
        </p>

        <p v-if="!whatsapp.configured" class="warn-box mt-4">{{ whatsapp.not_configured }}</p>
      </section>

      <!-- ---------------- safety ---------------- -->
      <section class="card p-5">
        <h2 class="text-base font-semibold text-slate-900">What stops a rule going wrong</h2>

        <div class="mt-3 space-y-3 text-sm leading-relaxed text-slate-600">
          <p>
            <strong class="text-slate-800">New rules start switched off.</strong>
            There is no “save and turn on”. You write it, read the sentence, test it, and switch it
            on as a separate decision.
          </p>
          <p>
            <strong class="text-slate-800">Switching one on asks first,</strong>
            and tells you how many of your existing leads it applies to before you agree.
          </p>
          <p>
            <strong class="text-slate-800">Rules cannot run away.</strong>
            One rule changing a stage can set off another rule watching for that change, which
            could in theory bounce a lead back and forth for ever. Automation stops after
            <strong>{{ loop.max_touches_per_chain }}</strong> changes to the same lead in a row,
            and no single rule will act on the same lead twice within
            <strong>{{ loop.cooldown_minutes }} minutes</strong>. Every time it holds back, it is
            written to the Activity tab and all admins are told — so a rule that is switched on
            and quietly doing nothing will never stay a mystery.
          </p>
          <p>
            <strong class="text-slate-800">Nothing is sent to a customer automatically.</strong>
            Not in this version. Messages wait in the Queue for a person.
          </p>
          <p>
            <strong class="text-slate-800">Everything is written down.</strong>
            The Activity tab lists every action of every rule — what it did, to which lead, whether
            it worked, and why not when it did not. If a lead turns up somewhere unexpected, that
            is where the answer is.
          </p>
          <p>
            <strong class="text-slate-800">Automation is never recorded as you.</strong>
            Work done by a rule is filed as done by the system, not by whoever wrote the rule.
          </p>
        </div>
      </section>

      <!-- ---------------- where to start ---------------- -->
      <section class="card p-5">
        <h2 class="text-base font-semibold text-slate-900">Where to start</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          There are ready-made rules on the Rules tab, all switched off. Open one, read what it
          does, change anything you like, and press Test. They are there to be edited, not
          admired.
        </p>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          Most offices switch on <strong>“Chase ignored leads”</strong> first. It tells people
          about their own overdue follow-ups and changes nothing else — the cheapest possible way
          to stop leads going quiet.
        </p>
        <Link :href="route('automation.index')" class="btn mt-4">Go to the rules</Link>
      </section>
    </div>
  </AppLayout>
</template>
