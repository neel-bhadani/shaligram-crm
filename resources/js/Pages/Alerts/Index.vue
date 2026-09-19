<script setup>
import AppLayout from '../../Layouts/AppLayout.vue'
import AlertList from '../../Components/AlertList.vue'
import HelpTip from '../../Components/HelpTip.vue'

/*
 | The alerts page, open to every role.
 |
 | Not behind `role:admin`, and it must not be: a telecaller is told when their
 | own follow-up is three days overdue, and the bell in the header carries their
 | count on every page. A page they could not open would be a count pointing at
 | a 403.
 |
 | Each person sees the alerts addressed to them and nobody else's — including
 | admins, who get their own rather than everybody's. The rows are the same
 | component the Automation page's Alerts tab renders.
 */
defineProps({
  alerts: Object,
  filters: Object,
  counts: Object,
  thresholds: Object,
})
</script>

<template>
  <AppLayout title="Alerts" subtitle="Things worth knowing. Not tasks.">
    <div class="mb-4 flex items-start gap-1.5">
      <p class="max-w-3xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">
        Alerts tell you something has happened. They are separate from your Follow-ups on purpose —
        reading one does not change the lead, complete anything, or count as having called.
      </p>
      <HelpTip title="Alerts and follow-ups are different things">
        A <strong>follow-up</strong> is work: it belongs to a lead, it has a date, and completing
        it moves the lead along. Every open lead has exactly one.
        <br><br>
        An <strong>alert</strong> is a message to you. It has no date, it is not on anybody's list
        of work, and marking it read changes nothing except that it stops showing as new.
      </HelpTip>
    </div>

    <AlertList
      :alerts="alerts" :filters="filters" :counts="counts" :thresholds="thresholds"
    />
  </AppLayout>
</template>
