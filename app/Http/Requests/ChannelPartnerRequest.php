<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesChannelPartner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Editing a channel partner from the Channel Partners page — and creating one
 * from the same page's Add modal.
 *
 * EDIT AND CREATE share one form and one rule-set. The route is a PUT with a
 * bound {partner} when the row already exists, and a POST when it does not;
 * route('partner') is therefore null on the create branch, exactly as it is on
 * QuickChannelPartnerRequest, and validatePartnerShape() treats a null partner
 * the way the quick door does — no self-parent, no demotion guard, and a name
 * clash against every live row.
 *
 * Every field, unlike QuickChannelPartnerRequest's four. This is the screen
 * where the contact person, the email and the address get filled in, at a desk,
 * after the lead that produced the row has been logged — or from the start,
 * when the row is being entered by hand on the roster page.
 *
 * The rules about what a partner IS — the hierarchy and the name that cannot
 * already be taken — are shared with the quick door through
 * ValidatesChannelPartner, so the two cannot disagree about the shape of a row
 * while disagreeing about who may write one.
 */
class ChannelPartnerRequest extends FormRequest
{
    use ValidatesChannelPartner;

    /**
     * Every signed-in role may edit a partner, and the same crowd may add one:
     * the crowd that reads the roster and names the broker on a lead. The
     * routes this serves sit on the `auth` group rather than the admin one —
     * `web` + `Authenticate` is the whole door — and this second lock says the
     * same thing, so a route moved out of that group, or added without the
     * middleware, cannot change who reaches here either.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->partnerIdentityRules() + [
            'contact_person' => ['nullable', 'string', 'max:150'],
            'alt_phone'      => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'],
            'email'          => ['nullable', 'email', 'max:150'],
            'address'        => ['nullable', 'string', 'max:255'],
            'is_active'      => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validatePartnerShape($validator, $this->route('partner'));
    }

    public function messages(): array
    {
        return $this->partnerMessages() + [
            'alt_phone.regex' => 'Enter a valid phone number.',
        ];
    }

    /**
     * The row's own columns, with the one field that depends on another
     * normalised here rather than in the controller.
     *
     * `parent_id` is forced to null for a firm. validatePartnerShape() has
     * already refused a submission that carried one, so this is not the guard —
     * it is what stops a stale value being saved in the case the guard cannot
     * see: a row edited from broker to firm, where the modal cleared the field
     * on screen and the type is the only thing that says so.
     */
    public function channelPartnerAttributes(): array
    {
        $data = $this->safe()->only([
            'name', 'type', 'parent_id', 'contact_person',
            'phone', 'alt_phone', 'email', 'address',
        ]);

        $data['parent_id'] = $data['type'] === 'firm' ? null : ($data['parent_id'] ?? null);
        $data['is_active'] = $this->boolean('is_active');

        return $data;
    }
}
