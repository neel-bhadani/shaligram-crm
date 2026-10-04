<?php

namespace App\Http\Controllers;

use App\Http\Requests\MessageTemplateRequest;
use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Messages tab. Admin only, on the route group.
 *
 * A message is an 11za template and what goes into each of its variables. The
 * wording is 11za's; nothing here edits it. provider() is the only call to
 * 11za, and it only reads the template list.
 */
class MessageTemplateController extends Controller
{
    /** 11za's template list, read now, for the dropdown. Always answers. */
    public function provider(WhatsAppSender $whatsapp): JsonResponse
    {
        return response()->json($whatsapp->refreshProviderTemplates());
    }

    public function store(MessageTemplateRequest $request)
    {
        MessageTemplate::create($request->templateAttributes());

        return back()->with('success', 'Message saved.');
    }

    public function update(MessageTemplateRequest $request, MessageTemplate $template)
    {
        $template->update($request->templateAttributes());

        return back()->with('success', 'Message updated.');
    }

    /**
     * Switch a template on or off.
     *
     * A switched-off template disappears from the Auto-send dropdowns but
     * stays on the Messages tab, and a rule already pointing at it skips with
     * a line in the activity log rather than failing. That is the softer half
     * of deleting, and it is what an admin actually wants when a message is
     * wrong: stop it going out now, fix it later.
     */
    public function toggle(Request $request, MessageTemplate $template)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $template->update(['is_active' => $data['is_active']]);

        return back()->with('success', $data['is_active']
            ? "\"{$template->name}\" can be sent again."
            : "\"{$template->name}\" is switched off. Stages that send it will skip it.");
    }

    /**
     * Delete a template, but not one a rule is still pointing at.
     *
     * The foreign key would allow it — `message_logs.template_id` goes null and
     * the sent text survives on the row, which is what the log is for. The
     * refusal is about the RULES: a rule whose template vanished would skip
     * every time it fired, logging a line nobody would connect back to a
     * deletion three weeks earlier. Naming the rules is the whole value of the
     * message.
     */
    public function destroy(MessageTemplate $template)
    {
        $used = AutomationRule::query()
            ->where('actions', 'like', '%"queue_whatsapp"%')
            ->get()
            ->filter(fn (AutomationRule $rule) => collect($rule->actionList())
                ->contains(fn (array $a) => ($a['type'] ?? null) === 'queue_whatsapp'
                    && (int) ($a['template_id'] ?? 0) === $template->id))
            ->pluck('name');

        if ($used->isNotEmpty()) {
            return back()->with('error',
                "\"{$template->name}\" is used by ".$used->join(', ', ' and ')
                .'. Choose something else for those stages on Auto-send (or change those rules) first, or switch the message off instead.');
        }

        $name = $template->name;
        $template->delete();

        return back()->with('success', "\"{$name}\" deleted. Messages already sent keep their wording in the log.");
    }
}
