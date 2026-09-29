/*
 | A rule, said out loud.
 |
 |   "When a lead is created, and the source is Facebook, assign it round-robin
 |    to a telecaller and create a follow-up in 1 hour."
 |
 | This is the single most valuable piece of guidance on the Automation page. It
 | turns four dropdowns into a sentence the office admin can read back and
 | confirm, and it updates as they type — a rule they can say out loud is a rule
 | they will not switch on by mistake.
 |
 | ONE IMPLEMENTATION, deliberately. The fragments live in
 | config/automation.php, next to the triggers and actions they belong to, and
 | this file is the only thing that joins them up. A second copy in PHP — for
 | the rules list, say — would drift within a month, and a preview that lies is
 | worse than no preview: the admin has read it and believed it.
 |
 | Everything is looked up through the catalogue the server sent, so no
 | trigger, stage, source or role is named here. Adding a trigger is a config
 | entry with a `phrase` on it, and this file does not change.
 */

/** What an unfilled dropdown looks like mid-sentence. */
const BLANK = '…'

/**
 * The label for a value, from the option list a parameter points at.
 *
 * Falls back to the raw value rather than to nothing: a project that was
 * deleted after the rule was written should read as its id, not vanish and
 * leave a sentence that quietly means something else.
 */
function labelFor(catalog, token, value, lower = false) {
  if (value === null || value === undefined || value === '') return BLANK

  const options = (catalog.options || {})[token] || []
  const hit = options.find(o => String(o.value) === String(value))
  const label = hit ? hit.label : String(value)

  /*
   | `lower` comes from the catalogue, per parameter, and it is the difference
   | between a sentence and a mail merge. "Telecaller" is right on a dropdown
   | and wrong in "assign it to a Telecaller"; "Skyline Residency" and "Site
   | visit done" are names and keep their capitals wherever they appear. Which
   | is which is a fact about the vocabulary, so it is declared beside the
   | vocabulary in config/automation.php rather than guessed at here.
   */
  return lower ? label.toLowerCase() : label
}

/**
 * A number with its unit, pluralised.
 *
 * "1 hour" and "24 hours", not "1 hours". It is a small thing and it is the
 * difference between a sentence that reads as English and one that reads as a
 * template with values dropped into it — which is exactly the impression this
 * whole feature is trying not to give.
 */
function quantity(value, unit) {
  if (value === null || value === undefined || value === '') return BLANK

  const n = Number(value)

  if (!Number.isFinite(n)) return BLANK
  if (!unit) return String(n)

  return `${n} ${n === 1 ? unit.replace(/s$/, '') : unit}`
}

/** One parameter, rendered for the sentence. */
function paramPhrase(catalog, meta, value) {
  if (!meta) return value === undefined || value === '' ? BLANK : String(value)

  if (meta.type === 'number') return quantity(value, meta.unit)
  if (meta.options) return labelFor(catalog, meta.options, value, meta.lower)

  return value === null || value === undefined || value === '' ? BLANK : String(value)
}

/**
 * Replace every {name} in a fragment using the parameter definitions beside it.
 */
function fill(catalog, phrase, params, values) {
  return String(phrase || '').replace(/\{(\w+)\}/g, (_, key) =>
    paramPhrase(catalog, (params || {})[key], (values || {})[key]),
  )
}

/* ---------------- the three parts ---------------- */

export function triggerPhrase(rule, catalog) {
  const meta = (catalog.triggers || {})[rule.trigger]

  if (!meta) return 'When something happens'

  return fill(catalog, meta.phrase, meta.params, rule.trigger_config || {})
}

export function conditionPhrases(rule, catalog) {
  return (rule.conditions || [])
    .filter(c => c && c.field)
    .map(c => {
      const meta = (catalog.conditions || {})[c.field]

      if (!meta) return null

      return String(meta.phrase).replace('{value}', labelFor(catalog, meta.options, c.value, meta.lower))
    })
    .filter(Boolean)
}

/**
 * An alert's recipient, which is the one nested case.
 *
 * "alert {recipient}" is not enough on its own — the recipient is itself a
 * phrase, and two of the four carry a placeholder of their own ("every
 * {recipient_role}"). So the recipient's phrase is resolved first, against the
 * same action's parameters, and the result is dropped into the action's.
 */
function recipientPhrase(catalog, action) {
  const meta = (catalog.alert_recipients || {})[action.recipient]

  if (!meta) return BLANK

  const params = ((catalog.actions || {}).raise_alert || {}).params || {}

  return fill(catalog, meta.phrase, params, action)
}

export function actionPhrases(rule, catalog) {
  return (rule.actions || [])
    .filter(a => a && a.type)
    .map(a => {
      const meta = (catalog.actions || {})[a.type]

      if (!meta) return null

      // the recipient is resolved before the action's own fragment, because
      // the action's fragment is where the result has to land
      const values = a.type === 'raise_alert'
        ? { ...a, recipient: recipientPhrase(catalog, a) }
        : a

      const params = a.type === 'raise_alert'
        ? { ...meta.params, recipient: {} }   // already a phrase; do not re-label it
        : meta.params

      return fill(catalog, meta.phrase, params, values)
    })
    .filter(Boolean)
}

/**
 * "a, b and c" — an Oxford-comma-free list, because this is a sentence a person
 * reads rather than a list they scan.
 */
function join(parts) {
  if (parts.length === 0) return ''
  if (parts.length === 1) return parts[0]

  return `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`
}

/**
 * The whole sentence.
 *
 * @param {{trigger: string, trigger_config: object, conditions: array, actions: array}} rule
 * @param {{triggers: object, conditions: object, actions: object, options: object, alert_recipients: object}} catalog
 * @returns {string}
 */
export function rulePhrase(rule, catalog) {
  if (!rule || !rule.trigger || !catalog) return ''

  const trigger = triggerPhrase(rule, catalog)
  const conditions = conditionPhrases(rule, catalog)
  const actions = actionPhrases(rule, catalog)

  let sentence = trigger

  if (conditions.length) sentence += `, and ${join(conditions)}`

  sentence += actions.length
    ? `, ${join(actions)}`
    // said plainly rather than left hanging: a rule with no action yet is the
    // normal state of a rule being written, not an error to shout about
    : ', do nothing yet — add an action below'

  return `${sentence}.`
}
