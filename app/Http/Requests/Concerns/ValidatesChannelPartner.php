<?php

namespace App\Http\Requests\Concerns;

use App\Models\ChannelPartner;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules a channel partner has to satisfy however it arrives.
 *
 * There are two doors into this table and they are not the same door:
 *
 *   the INLINE form on the lead modal, which is where partners are actually
 *   created, open to anyone who may create a lead, four fields wide.
 *
 *   the EDIT modal on the Channel Partners page, open to every signed-in role,
 *   every field.
 *
 * Different authorisation, different field sets, one set of rules about what a
 * channel partner IS. That is what lives here — the hierarchy, and the name
 * that cannot already be taken — so the quick door cannot quietly let through a
 * shape the slow one refuses.
 */
trait ValidatesChannelPartner
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function partnerIdentityRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],

            'type' => ['required', Rule::in(array_keys(config('crm.channel_partner_types')))],

            /*
             | Only the id is checked here — that it exists, is not deleted, and
             | is a live firm. How it relates to THIS row is in
             | validatePartnerShape(), where the submitted type is available and
             | the message can name the actual problem.
             */
            'parent_id' => [
                'nullable',
                Rule::exists('channel_partners', 'id')
                    ->whereNull('deleted_at')
                    ->where('type', 'firm')
                    ->where('is_active', true),
            ],

            /*
             | Required, and deliberately looser than the ten digits `leads` and
             | `users` demand of a mobile number. A firm's number is as likely
             | to be a landline with an STD code, and a partner row is not
             | something the application dials.
             */
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
        ];
    }

    /**
     * The four shapes the schema cannot refuse, plus the name clash.
     *
     * `parent_id` is a foreign key onto the same table and no constraint can
     * say "the row you point at must have type = firm", so all of the hierarchy
     * is here:
     *
     *   a firm has no parent          a firm is the top of the tree
     *   a broker's parent is a firm   which is what caps the depth at one
     *   nothing is its own parent     a self-referencing row is a cycle of one
     *   a firm holding brokers        or those brokers would end up under a
     *   cannot become a broker        broker, which is the nesting this forbids
     */
    protected function validatePartnerShape(Validator $validator, ?ChannelPartner $partner): void
    {
        $validator->after(function ($v) use ($partner) {
            $type   = $this->input('type');
            $parent = $this->input('parent_id');
            $name   = (string) $this->input('name');

            /*
             | A firm carrying a parent is not corrected silently. Both forms
             | clear the field when the type switches to firm, so a value
             | arriving here means a stale tab or a hand-written request — and
             | quietly nulling it would hide the second case.
             */
            if ($type === 'firm' && $parent) {
                $v->errors()->add('parent_id', 'A firm does not sit under another partner. Clear the parent firm.');
            }

            if ($partner && $parent && (int) $parent === (int) $partner->id) {
                $v->errors()->add('parent_id', 'A partner cannot be filed under itself.');
            }

            if ($partner && $partner->isFirm() && $type === 'broker') {
                // trashed brokers are not counted: they are gone from every
                // screen, and blocking on them would leave an admin stuck with
                // no way to see what is in the way
                $count = $partner->brokers()->count();

                if ($count > 0) {
                    $v->errors()->add('type', "This firm has {$count} broker" . ($count === 1 ? '' : 's')
                        . ' filed under it, so it cannot become a broker itself. Move them to another firm first.');
                }
            }

            /*
             | The name clash, said as a sentence.
             |
             | The unique index on (name_key, type) is the guarantee — this is
             | the same question asked in PHP so the answer names the partner
             | that is in the way instead of arriving as a SQLSTATE. Both look
             | at live rows only, and by construction rather than by two clauses
             | kept in step: a soft-deleted row's `name_key` is null, so neither
             | the index nor findClash() can see it.
             */
            if ($name !== '' && $type) {
                $clash = ChannelPartner::findClash($name, $type, $partner?->id);

                if ($clash) {
                    $v->errors()->add('name', $clash->is_active
                        ? "\"{$clash->name}\" already exists as a " . strtolower(config("crm.channel_partner_types.$type", $type)) . '. Use that one instead.'
                        : "\"{$clash->name}\" already exists as a " . strtolower(config("crm.channel_partner_types.$type", $type))
                            . ' but is switched off. Ask an admin to reactivate it rather than adding a second one.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function partnerMessages(): array
    {
        return [
            'parent_id.exists' => 'Choose an active firm, or leave this blank for an individual broker.',
            'phone.required'   => 'A phone number is required.',
            'phone.regex'      => 'Enter a valid phone number.',
        ];
    }
}
