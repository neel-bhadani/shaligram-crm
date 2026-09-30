<?php

namespace App\Http\Requests;

use App\Models\MessageTemplate;
use App\Services\Automation\RuleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A rule, checked against the catalogue it was built from.
 *
 * The builder renders its dropdowns from config('automation.php') via
 * RuleCatalog, and this validates against the same RuleCatalog. That is the
 * whole point of resolving the options in one place: a value the form could not
 * have offered cannot be stored, and a value the form does offer is never
 * rejected. Two lists — one for the dropdown, one for the rule — is how a
 * "Project" condition ends up accepting a project that was deleted last March.
 *
 * The parameter rules cannot be written out statically, because which
 * parameters exist depends on which action was chosen. They are assembled in
 * after() instead, walking the catalogue for the action actually submitted.
 *
 * `is_active` is not here at all. A new rule is inactive, full stop — see
 * AutomationRuleController::store(). Switching one on is its own request, with
 * its own confirmation showing how many leads it is about to apply to.
 */
class AutomationRuleRequest extends FormRequest
{
    /**
     * Resolved on demand rather than injected.
     *
     * A FormRequest is not constructed by the container in the usual way — it
     * is built from the incoming request and then hydrated — so a constructor
     * dependency here is a footgun that works until somebody calls
     * Request::createFrom(). One line and a property is the boring, correct
     * shape.
     */
    private ?RuleCatalog $catalogue = null;

    private function catalogue(): RuleCatalog
    {
        return $this->catalogue ??= app(RuleCatalog::class);
    }

    public function authorize(): bool
    {
        // the route group is already `role:admin`; this is the second lock on
        // the same door, and the one that survives somebody reorganising
        // routes/web.php
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],

            'trigger' => ['required', 'string', 'in:'.implode(',', array_keys(config('automation.triggers')))],
            'trigger_config' => ['array'],

            'conditions' => ['array', 'max:6'],
            'conditions.*.field' => ['required', 'string', 'in:'.implode(',', array_keys(config('automation.conditions')))],
            'conditions.*.value' => ['required'],

            // at least one: a rule with a trigger and no action is a rule that
            // fires, logs, and does nothing, which reads on the Activity tab as
            // a bug in the engine
            'actions' => ['required', 'array', 'min:1', 'max:6'],
            'actions.*.type' => ['required', 'string', 'in:'.implode(',', array_keys(config('automation.actions')))],
        ];
    }

    public function messages(): array
    {
        return [
            'actions.required' => 'A rule has to do something. Add at least one action.',
            'actions.min' => 'A rule has to do something. Add at least one action.',
            'name.required' => 'Give the rule a name you will recognise in a list.',
        ];
    }

    /**
     * The half of the rules that depend on what was chosen.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->checkParams(
                    $validator,
                    'trigger_config',
                    config("automation.triggers.{$this->input('trigger')}.params", []),
                    $this->input('trigger_config', []) ?? [],
                );

                foreach ((array) $this->input('actions', []) as $index => $action) {
                    $type = $action['type'] ?? null;

                    if (! $type || ! config("automation.actions.$type")) {
                        continue; // already reported by the static rule above
                    }

                    $this->checkParams(
                        $validator,
                        "actions.$index",
                        config("automation.actions.$type.params", []),
                        $action,
                    );

                    if ($type === 'queue_whatsapp') {
                        $this->checkApiTemplate($validator, "actions.$index", $action);
                    }
                }

                $this->checkConditionValues($validator);
            },
        ];
    }

    /**
     * Every parameter one trigger or action declares, checked for presence and
     * for being one of the values its dropdown offered.
     *
     * A parameter with a `when` clause is only required when that clause holds
     * — the alert's "which role" box does not exist unless the recipient is a
     * role, so demanding it would make an alert to one named person
     * unsaveable.
     */
    private function checkParams(Validator $validator, string $prefix, array $params, array $values): void
    {
        foreach ($params as $key => $meta) {
            if (isset($meta['when']) && ! $this->whenHolds($meta['when'], $values)) {
                continue;
            }

            $value = $values[$key] ?? null;
            $missing = $value === null || $value === '' || $value === [];

            if (($meta['required'] ?? false) && $missing) {
                $validator->errors()->add("$prefix.$key", "Choose {$this->lower($meta['label'])}.");

                continue;
            }

            if ($missing) {
                continue;
            }

            if (($meta['type'] ?? null) === 'select' && isset($meta['options'])) {
                $allowed = array_map('strval', $this->catalogue()->allowed($meta['options']));

                if (! in_array((string) $value, $allowed, true)) {
                    $validator->errors()->add(
                        "$prefix.$key",
                        "That is no longer an option for {$this->lower($meta['label'])}. Choose again."
                    );
                }
            }

            if (($meta['type'] ?? null) === 'number') {
                $number = filter_var($value, FILTER_VALIDATE_INT);
                $min = $meta['min'] ?? 1;
                $max = $meta['max'] ?? PHP_INT_MAX;

                if ($number === false || $number < $min || $number > $max) {
                    $validator->errors()->add(
                        "$prefix.$key",
                        "{$meta['label']} has to be a whole number between {$min} and {$max}."
                    );
                }
            }
        }
    }

    /**
     * An API-mode WhatsApp action has to name a message Meta will accept:
     * linked to an APPROVED template, with the same number of variables.
     *
     * Refused here, at Save, because the alternative is a rule that looks
     * fine and fails on every lead it fires for.
     *
     * @param  array<string, mixed>  $action
     */
    private function checkApiTemplate(Validator $validator, string $prefix, array $action): void
    {
        if (($action['mode'] ?? 'click') !== 'api' || blank($action['template_id'] ?? null)) {
            return;
        }

        $template = MessageTemplate::with('whatsappTemplate')->find($action['template_id']);
        $reason = $template?->apiUnsendableReason();

        if ($reason) {
            $validator->errors()->add("$prefix.template_id", "{$reason} Choose another message or use click-to-send.");
        }
    }

    /**
     * A condition's value has to be one the condition's own list offers.
     *
     * Without this a condition could carry any string at all, and
     * ConditionMatcher would put it straight into a `where` — matching nothing,
     * for ever, on a rule that looks perfectly reasonable on screen.
     */
    private function checkConditionValues(Validator $validator): void
    {
        foreach ((array) $this->input('conditions', []) as $index => $condition) {
            $field = $condition['field'] ?? null;
            $meta = config("automation.conditions.$field");

            if (! $meta) {
                continue;
            }

            $allowed = array_map('strval', $this->catalogue()->allowed($meta['options']));

            if (! in_array((string) ($condition['value'] ?? ''), $allowed, true)) {
                $validator->errors()->add(
                    "conditions.$index.value",
                    "That is no longer an option for {$this->lower($meta['label'])}. Choose again."
                );
            }
        }
    }

    /** @param array<string, mixed> $when */
    private function whenHolds(array $when, array $values): bool
    {
        foreach ($when as $key => $expected) {
            if (($values[$key] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** "Which stage" reads badly mid-sentence; "which stage" reads fine. */
    private function lower(string $label): string
    {
        return mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }

    /**
     * What actually gets stored: the catalogue's parameters and nothing else.
     *
     * A form that posted an extra key — a stale field from an action the admin
     * switched away from — would otherwise have it saved into the JSON and
     * carried around for ever. This rebuilds both blobs from the catalogue, so
     * what is stored is exactly what the rule can use.
     *
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        $trigger = $this->input('trigger');

        return [
            'name' => trim($this->input('name')),
            'description' => $this->filled('description') ? trim($this->input('description')) : null,
            'trigger' => $trigger,

            'trigger_config' => $this->pickParams(
                config("automation.triggers.$trigger.params", []),
                $this->input('trigger_config', []) ?? [],
            ),

            'conditions' => collect($this->input('conditions', []))
                ->filter(fn ($c) => ! blank($c['field'] ?? null) && ! blank($c['value'] ?? null))
                ->map(fn ($c) => ['field' => $c['field'], 'value' => $c['value']])
                ->values()
                ->all(),

            'actions' => collect($this->input('actions', []))
                ->map(function ($action) {
                    $type = $action['type'];

                    return ['type' => $type] + $this->pickParams(
                        config("automation.actions.$type.params", []),
                        $action,
                    );
                })
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function pickParams(array $params, array $values): array
    {
        $kept = [];

        foreach ($params as $key => $meta) {
            if (isset($meta['when']) && ! $this->whenHolds($meta['when'], $values)) {
                continue;
            }

            if (! array_key_exists($key, $values) || $values[$key] === '' || $values[$key] === null) {
                continue;
            }

            $kept[$key] = ($meta['type'] ?? null) === 'number'
                ? (int) $values[$key]
                : $values[$key];
        }

        return $kept;
    }
}
