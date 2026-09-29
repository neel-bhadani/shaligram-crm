<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
use App\Services\WhatsApp\WhatsAppTemplateSync;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Meta's templates on the Templates tab. Admin only, on the route group.
 *
 * The CRM cannot create or approve a Meta template — that is WhatsApp Manager.
 * It can ask Meta which exist and which are approved (sync), and it can record
 * which of its own placeholders fills each variable (map), which Meta has no
 * way to know and which is never guessed.
 */
class WhatsAppTemplateController extends Controller
{
    public function sync(WhatsAppSender $whatsapp, WhatsAppTemplateSync $sync)
    {
        $integration = $whatsapp->integration();
        $wabaId = (string) $integration->setting('whatsapp_business_account_id');
        $token = (string) $integration->setting('access_token');

        if ($wabaId === '' || $token === '') {
            return back()->with('error', 'Save the WhatsApp Business Account ID and the System User token before syncing templates.');
        }

        try {
            $counts = $sync->sync($wabaId, $token);
        } catch (Throwable $e) {
            return back()->with('error', 'Could not sync the templates: '.$e->getMessage());
        }

        return back()->with('success', "{$counts['total']} templates found on the WhatsApp account, "
            ."{$counts['approved']} approved, {$counts['added']} new"
            .($counts['missing'] ? ", {$counts['missing']} no longer there" : '').'.');
    }

    /**
     * Save which placeholder fills each variable.
     *
     * Every variable must be answered and only with a placeholder the CRM
     * knows. A partial map is refused rather than saved: a template with a gap
     * would sit in the list looking finished and fail at the first send.
     */
    public function map(Request $request, WhatsAppTemplate $template, TemplateRenderer $renderer)
    {
        $variables = $template->variables ?? [];
        $placeholders = array_keys($renderer->placeholders());

        $rules = ['parameter_map' => ['present', 'array']];

        foreach ($variables as $variable) {
            $rules["parameter_map.$variable"] = ['required', 'string', Rule::in($placeholders)];
        }

        $data = $request->validate($rules, [
            'parameter_map.*.required' => 'Choose what fills this variable.',
            'parameter_map.*.in' => 'Choose one of the listed placeholders.',
        ]);

        $template->update([
            'parameter_map' => collect($variables)
                ->mapWithKeys(fn (string $v) => [$v => $data['parameter_map'][$v]])
                ->all() ?: null,
        ]);

        return back()->with('success', "Variables saved for “{$template->name}”.");
    }
}
