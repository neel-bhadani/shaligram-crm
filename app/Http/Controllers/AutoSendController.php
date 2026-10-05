<?php

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use App\Models\Project;
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
 *
 * With a project_id the row is that project's: `choice` is same, own (with
 * template_id) or nothing. Without one it is the All-projects default, as
 * before.
 */
class AutoSendController extends Controller
{
    public function __construct(private AutoSend $autoSend) {}

    public function update(Request $request, string $slot): RedirectResponse
    {
        abort_unless(array_key_exists($slot, $this->autoSend->slots()), 404);

        $data = $request->validate([
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'choice' => ['required_with:project_id', Rule::in([AutoSend::SAME, AutoSend::OWN, AutoSend::NOTHING])],
            'template_id' => [
                Rule::requiredIf($request->input('choice') === AutoSend::OWN),
                'nullable', 'integer', Rule::exists('message_templates', 'id')->where('is_active', true),
            ],
        ], [
            'project_id.exists' => 'That project has been deleted.',
            'template_id.required' => 'Choose a tag.',
            'template_id.exists' => 'That tag has been deleted or switched off. Choose another.',
        ]);

        $label = $this->autoSend->slots()[$slot]['label'];
        $project = filled($data['project_id'] ?? null) ? Project::find($data['project_id']) : null;
        $choice = $project ? $data['choice'] : null;
        $template = in_array($choice, [null, AutoSend::OWN], true) && filled($data['template_id'] ?? null)
            ? MessageTemplate::find($data['template_id'])
            : null;

        if ($project && $choice === AutoSend::SAME) {
            $this->autoSend->setForProject($slot, $project, AutoSend::SAME, null, $request->user());

            return back()->with('success', "{$project->name} sends the same as all projects at {$label} now.");
        }

        if ($project && $choice === AutoSend::NOTHING) {
            $this->autoSend->setForProject($slot, $project, AutoSend::NOTHING, null, $request->user());

            return back()->with('success', "Nothing is sent automatically at {$label} for {$project->name} now.");
        }

        if (! $template) {
            $this->autoSend->set($slot, null, $request->user());

            return back()->with('success', "Nothing is sent automatically at {$label} now.");
        }

        // API needs a named 11za template; click-to-send does not
        if ($this->autoSend->modeFor($slot, $project) === 'api' && ($reason = $template->apiUnsendableReason())) {
            return back()->withErrors(['template_id' => $reason]);
        }

        if ($project) {
            $this->autoSend->setForProject($slot, $project, AutoSend::OWN, $template, $request->user());

            return back()->with('success', "\"{$template->name}\" is now sent when a {$project->name} lead reaches {$label}.");
        }

        $this->autoSend->set($slot, $template, $request->user());

        return back()->with('success', "\"{$template->name}\" is now sent when a lead reaches {$label}.");
    }
}
