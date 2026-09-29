<?php

namespace App\Services\Automation;

use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\User;
use App\Support\CrmTaxonomy;

/**
 * The rule vocabulary, resolved.
 *
 * config/automation.php declares what a rule may say; several of those choices
 * are lists that only the database knows — the projects, the staff, the message
 * templates. This class turns the whole catalogue into one payload the builder
 * can render and the preview sentence can read, so no Vue file ever names a
 * trigger, a stage, a role or a source.
 *
 * The same resolution answers the validator, which is the point of doing it in
 * one place: AutomationRuleRequest checks a submitted value against exactly the
 * list the dropdown offered, so the two cannot drift.
 */
class RuleCatalog
{
    /**
     * Everything the builder needs, in one prop.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'triggers' => config('automation.triggers'),
            'conditions' => config('automation.conditions'),
            'actions' => config('automation.actions'),
            'options' => $this->options(),
            /*
             | The recipient phrases as well as the recipient labels. A label
             | names a dropdown option ("Everybody in a role"); a phrase is the
             | fragment that goes into the plain-words sentence ("every
             | telecaller"), and it can itself carry a placeholder. The preview
             | needs both, and neither can be derived from the other.
             */
            'alert_recipients' => config('automation.alert_recipients'),
            'loop' => config('automation.loop_protection'),
        ];
    }

    /**
     * Every option list a dropdown in the builder can be pointed at, keyed by
     * the token config/automation.php names it with.
     *
     * @return array<string, array<int, array{value: string|int, label: string}>>
     */
    public function options(): array
    {
        return [
            /*
             | ACTIVE stages and sources, plus any retired one an existing rule
             | already names — marked as retired rather than silently dropped.
             |
             | Dropping it is what would break a rule quietly: the builder's
             | select would find no option matching the saved value, fall back
             | to its placeholder, and the next person to press Save on that
             | rule would blank a condition they never touched. An option
             | reading "In discussion (no longer in use)" tells them instead.
             */
            'stages' => $this->fromMap(CrmTaxonomy::stages(), CrmTaxonomy::allStages()),
            'sources' => $this->fromMap(CrmTaxonomy::sources(), CrmTaxonomy::allSources()),
            'todo_types' => $this->fromMap(config('crm.todo_types')),

            /*
             | Staff roles only. Admin is not an assignment target. It can be a
             | value of `leads.assigned_role` — that column is always the
             | owner's own role, and an admin holds a lead when no telecaller
             | was active to take it — but a rule sharing work out "among the
             | admins" is not something to offer. Alerting all admins is its
             | own recipient option rather than a role.
             */
            /*
             | `role_words`, not `role_labels`. The abbreviated labels are for
             | table cells; these fragments end up mid-sentence in the rule
             | preview, where "assign it round-robin to a sales" is not English.
             */
            'roles' => collect(config('crm.staff_roles'))
                ->map(fn (string $role) => [
                    'value' => $role,
                    'label' => config("crm.role_words.$role", $role),
                ])
                ->values()
                ->all(),

            'projects' => Project::active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Project $p) => ['value' => $p->id, 'label' => $p->name])
                ->all(),

            /*
             | Active staff only. A rule pointing at somebody who has left is
             | not silently dropped — ActionRunner skips it and writes why to
             | the activity log — but there is no reason to offer them.
             */
            'users' => User::active()
                ->whereIn('role', ['admin', 'telecaller', 'salesperson'])
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'role'])
                ->map(fn (User $u) => [
                    'value' => $u->id,
                    'label' => $u->display_name.' · '.config("crm.role_labels.$u->role", $u->role),
                ])
                ->all(),

            'templates' => MessageTemplate::active()
                ->orderBy('name')
                ->get(['id', 'name', 'category'])
                ->map(fn (MessageTemplate $t) => ['value' => $t->id, 'label' => $t->name])
                ->all(),

            'alert_recipients' => collect(config('automation.alert_recipients'))
                ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label']])
                ->values()
                ->all(),

            'severities' => collect(config('automation.severities'))
                ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label']])
                ->values()
                ->all(),
        ];
    }

    /**
     * The values one option list allows, for validation.
     *
     * @return array<int, string|int>
     */
    public function allowed(string $token): array
    {
        return array_column($this->options()[$token] ?? [], 'value');
    }

    /** One option's label, for a log line or an alert title. */
    public function label(string $token, mixed $value): string
    {
        foreach ($this->options()[$token] ?? [] as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['label'];
            }
        }

        return (string) $value;
    }

    /** @param array<string, string> $map */
    private function fromMap(?array $map, ?array $withRetired = null): array
    {
        $options = collect($map ?? [])
            ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
            ->values()
            ->all();

        /*
         | The retired half of a vocabulary that has one: every key in
         | `$withRetired` that `$map` no longer offers, appended in its own
         | order and labelled as retired. See the call site for why an existing
         | rule's saved value has to stay selectable.
         */
        foreach ($withRetired ?? [] as $key => $label) {
            if (! array_key_exists($key, $map ?? [])) {
                $options[] = ['value' => $key, 'label' => $label.' (no longer in use)'];
            }
        }

        return $options;
    }
}
