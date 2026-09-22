<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SchedulesFollowUp;
use App\Http\Requests\Concerns\ValidatesTaxonomy;
use App\Models\ChannelPartner;
use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for both store and update.
 * Route model binding gives us {lead} on update, which we ignore in the unique rule.
 */
class LeadRequest extends FormRequest
{
    use SchedulesFollowUp;
    use ValidatesTaxonomy;

    /**
     * The lead's own core details — visible to a telecaller on this form, and
     * the fields LeadPolicy::update() lets them THROUGH without letting them
     * CHANGE. See restrictedToCoreDetails() and withValidator() below.
     *
     * Stage and the three follow-up fields are deliberately not here — a
     * telecaller keeps full control of both, exactly as before this lock
     * existed.
     *
     * `channel_partner_id`, `reason`, `booked_unit` and `requirement` are not
     * here either: the first only ever matters when `source` does, which is
     * already locked, and the other three are the direct consequence of a
     * stage a telecaller IS trusted to set — locking them would let a
     * telecaller move a lead to Lost without ever being able to say why.
     */
    private const TELECALLER_LOCKED_FIELDS = [
        'first_name', 'middle_name', 'last_name',
        'mobile_number', 'email', 'project_id', 'source',
    ];

    /**
     * Permissions, not roles.
     *
     * This used to read `in_array($this->user()->role, ['admin','salesperson'])`
     * with the same list repeated as route middleware. Both are gone: a
     * telecaller granted `add_leads` on the user management screen has to be
     * able to post this form, and a role string in either place would silently
     * make that toggle do nothing.
     *
     * The route binding is what tells the two cases apart — {lead} is present
     * on update and absent on store — so one request class still serves both
     * without being told which it is.
     */
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead
            ? $this->user()->can('update', $lead)
            : $this->user()->can('create', Lead::class);
    }

    public function rules(): array
    {
        // the lead being edited, or null on store — the taxonomy rules below
        // need its current stage and source, not just its id
        $lead = $this->route('lead');
        $leadId = $lead?->id;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],

            /*
             | The index on the table is a plain unique (mobile_number,
             | project_id) — it knows nothing about soft deletes. A rule that
             | skipped trashed rows therefore passed a number the index went
             | on to reject, and the insert came back as a 500 instead of a
             | message. This looks at exactly the rows the index looks at, and
             | says which kind of lead is holding the number.
             */
            'mobile_number' => [
                'required', 'digits:10',
                function (string $attribute, mixed $value, \Closure $fail) use ($leadId) {
                    $clash = Lead::withTrashed()
                        ->where('mobile_number', $value)
                        ->where('project_id', $this->project_id)
                        ->when($leadId, fn ($q, $id) => $q->where('id', '!=', $id))
                        ->first();

                    if (! $clash) {
                        return;
                    }

                    $fail($clash->trashed()
                        ? 'This number belongs to a deleted lead on this project. Restore that lead instead of adding it again.'
                        : 'This number already exists for this project.');
                },
            ],

            'email' => ['nullable', 'email', 'max:150'],
            'project_id' => ['required', 'exists:projects,id'],

            /*
             | Active sources, plus whichever one this lead already carries —
             | see ValidatesTaxonomy. Without the second half, switching a
             | source off would make every lead ever filed under it uneditable.
             */
            'source' => ['required', $this->activeSourceRule($lead?->source)],

            /*
             | `broker_name` IS NOT VALIDATED HERE ANY MORE, and that is the
             | whole of how the old column is protected.
             |
             | The form no longer has the field, so it no longer sends one — and
             | a key that is not validated is not in validated(), so
             | LeadController's update() never writes the column. A lead that
             | has carried "Shreeji Realty" in it since before this feature
             | existed still carries it after an admin edits the phone number,
             | which is exactly what would have been lost had the rule stayed
             | and the now-absent field posted an empty string over it.
             |
             | The column keeps its data, the form stops adding to it, and the
             | display falls back to it — see Lead::getBrokerLabelAttribute().
             */
            'channel_partner_id' => [
                'nullable',

                /*
                 | Required when the source is broker, with one exception: a
                 | lead that already carries the old free text and no partner.
                 |
                 | Without the exception, every legacy broker lead becomes
                 | unsaveable — an admin correcting a spelling in a surname
                 | would be made to attribute a lead from 2025 to a partner row,
                 | which is precisely the guess the migration refused to make on
                 | their behalf. With it, new work is attributed and old work is
                 | left alone until somebody chooses to attribute it.
                 */
                Rule::requiredIf(fn () => $this->input('source') === 'broker' && ! $this->hasLegacyBrokerName()),

                /*
                 | Active partners only, plus whichever one this lead already
                 | points at. A partner switched off after the lead was filed
                 | must not make that lead unsaveable — the picker keeps
                 | offering the lead's own partner for the same reason.
                 */
                function (string $attribute, mixed $value, \Closure $fail) {
                    $partner = ChannelPartner::find($value);

                    if (! $partner) {
                        $fail('That channel partner no longer exists.');

                        return;
                    }

                    if (! $partner->is_active
                        && (int) $value !== (int) $this->route('lead')?->channel_partner_id) {
                        $fail('That channel partner is switched off. Choose an active one.');
                    }
                },
            ],

            'stage' => ['required', $this->activeStageRule($lead?->stage)],
            'reason' => [
                'nullable', 'required_if:stage,lost',
                Rule::in(array_keys(config('crm.lost_reasons'))),
            ],

            'requirement' => ['nullable', 'string', 'max:100'],
            'booked_unit' => ['nullable', 'required_if:stage,booking_done', 'string', 'max:50'],
        ] + $this->followUpRules();
    }

    /**
     * A new lead needs its first task, and a lead whose stage is moving needs
     * the one that replaces what it was holding — in both cases only while the
     * stage it lands on is open.
     *
     * A stage that is not moving does not: the lead already has its pending
     * to-do, the user is editing a name or a phone number, and asking them to
     * re-book a follow-up they have already booked would cancel and recreate a
     * task on every save. LeadFollowUpService leaves that task alone for
     * exactly the same reason.
     */
    protected function needsFollowUp(): bool
    {
        if ($this->stageIsTerminal()) {
            return false;
        }

        $lead = $this->route('lead');

        return $lead === null || $lead->stage !== $this->input('stage');
    }

    /**
     * This edit is of a lead that predates channel partners: it names a broker
     * in the old text column and points at no partner row.
     *
     * Only ever true on an update — a lead being created has neither.
     */
    private function hasLegacyBrokerName(): bool
    {
        $lead = $this->route('lead');

        return $lead
            && $lead->channel_partner_id === null
            && filled($lead->broker_name);
    }

    public function messages(): array
    {
        return [
            'mobile_number.digits' => 'Enter a 10 digit mobile number.',
            'channel_partner_id.required' => 'Choose the channel partner this lead came through.',
            'reason.required_if' => 'Select why this lead was lost.',
        ] + $this->followUpMessages();
    }

    /**
     * True only on an update, and only for a telecaller LeadPolicy::update()
     * let through without `edit_leads`. Everyone else who reaches this class
     * already had every field to themselves, on both store and update, so
     * this is the one case that needs a second check at all.
     */
    private function restrictedToCoreDetails(): bool
    {
        $lead = $this->route('lead');

        return $lead !== null
            && $this->user()->isTelecaller()
            && ! $this->user()->can_('edit_leads');
    }

    /**
     * The server-side half of the telecaller field lock. The form disables
     * these inputs, but a disabled input is a UI courtesy, not a boundary —
     * this is what actually stops a crafted request from posting a changed
     * mobile number alongside a stage change, and it says so rather than
     * failing silently or 500ing on a write the policy never meant to allow.
     *
     * Ordinary validation rules cannot express this: whether a field is
     * "wrong" here depends on who is asking and on the row already in the
     * database, not on the value's own shape — so it runs after the rules
     * above, against the lead the request is bound to.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->restrictedToCoreDetails()) {
                return;
            }

            $lead = $this->route('lead');

            foreach (self::TELECALLER_LOCKED_FIELDS as $field) {
                if ((string) ($this->input($field) ?? '') !== (string) ($lead->{$field} ?? '')) {
                    $validator->errors()->add(
                        $field,
                        'Telecallers can change the stage and the next follow-up here, not this field.'
                    );
                }
            }
        });
    }
}
