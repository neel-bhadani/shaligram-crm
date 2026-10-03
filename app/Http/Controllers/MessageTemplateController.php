<?php

namespace App\Http\Controllers;

use App\Http\Requests\MessageTemplateRequest;
use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;

/**
 * The WhatsApp templates. Admin only, on the route group.
 *
 * Nothing here talks to 11za. A template is a piece of text with placeholders
 * in it, and click-to-send needs no approval from anybody. Sending by API
 * needs the 11za template name and language typed in beside it.
 */
class MessageTemplateController extends Controller
{
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
     * A switched-off template disappears from the rule builder's dropdown but
     * stays on the Templates tab, and a rule already pointing at it skips with
     * a line in the activity log rather than failing. That is the softer half
     * of deleting, and it is what an admin actually wants when a message is
     * wrong: stop it going out now, fix it later.
     */
    public function toggle(Request $request, MessageTemplate $template)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $template->update(['is_active' => $data['is_active']]);

        return back()->with('success', $data['is_active']
            ? "\"{$template->name}\" is available to rules again."
            : "\"{$template->name}\" is switched off. Rules that use it will skip it.");
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
                .'. Change or delete those rules first, or switch the message off instead.');
        }

        $name = $template->name;
        $template->delete();

        return back()->with('success', "\"{$name}\" deleted. Messages already sent keep their wording in the log.");
    }
}
