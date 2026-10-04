<?php

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use App\Services\Automation\AutoSend;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Auto-send tab's one action: choose what a stage sends. Admin only, on
 * the route group.
 *
 * A message chosen here is on immediately. The rule builder's "a new rule
 * starts switched off" does not apply: picking a message on this screen IS
 * the decision to send it, and there is no conditions list whose blast radius
 * needs testing first.
 */
class AutoSendController extends Controller
{
    public function __construct(private AutoSend $autoSend) {}

    public function update(Request $request, string $slot): RedirectResponse
    {
        abort_unless(array_key_exists($slot, $this->autoSend->slots()), 404);

        $data = $request->validate([
            'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('is_active', true)],
        ], [
            'template_id.exists' => 'That message has been deleted or switched off. Choose another.',
        ]);

        $label = $this->autoSend->slots()[$slot]['label'];
        $template = filled($data['template_id'] ?? null) ? MessageTemplate::find($data['template_id']) : null;

        if (! $template) {
            $this->autoSend->set($slot, null, $request->user());

            return back()->with('success', "Nothing is sent automatically at {$label} now.");
        }

        // a row's rule sends by API unless it was set to click-to-send before
        // this screen existed, and API needs a named 11za template
        $mode = $this->autoSend->ruleFor($slot)?->actionList()[0]['mode'] ?? 'api';

        if ($mode === 'api' && ($reason = $template->apiUnsendableReason())) {
            return back()->withErrors(['template_id' => $reason]);
        }

        $this->autoSend->set($slot, $template, $request->user());

        return back()->with('success', "\"{$template->name}\" is now sent when a lead reaches {$label}.");
    }
}
