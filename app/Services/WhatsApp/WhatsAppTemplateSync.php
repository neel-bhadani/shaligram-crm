<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppTemplate;

/**
 * Copies the WhatsApp Business Account's templates into whatsapp_templates.
 *
 * Meta is the only authority on which templates are approved, so the CRM asks
 * rather than remembers: a template the admin sees as APPROVED here is one Meta
 * said was approved at the last sync, and one that has vanished from the
 * account is marked MISSING rather than deleted — message_logs point at it.
 *
 * The admin's variable mapping survives a sync. Only entries for variables the
 * template no longer has are dropped; a new variable arrives unmapped and the
 * template stops being sendable until somebody maps it.
 */
class WhatsAppTemplateSync
{
    public const MISSING = 'MISSING';

    /** Button types that need a parameter of their own at send time. */
    private const PARAMETERISED_BUTTONS = ['COPY_CODE', 'OTP', 'FLOW', 'MPM', 'CATALOG'];

    public function __construct(private WhatsAppCloudClient $client) {}

    /**
     * @return array{total: int, approved: int, added: int, missing: int}
     *
     * @throws \RuntimeException when Meta refuses the listing
     */
    public function sync(string $wabaId, string $token): array
    {
        $seen = [];
        $added = 0;

        foreach ($this->client->templates($wabaId, $token) as $remote) {
            $name = (string) ($remote['name'] ?? '');
            $language = (string) ($remote['language'] ?? '');

            if ($name === '' || $language === '') {
                continue;
            }

            $template = WhatsAppTemplate::firstOrNew(['name' => $name, 'language' => $language]);
            $added += $template->exists ? 0 : 1;

            $template->fill($this->parse($remote));
            $template->parameter_map = array_intersect_key(
                $template->parameter_map ?? [],
                array_flip($template->variables),
            ) ?: null;
            $template->synced_at = now();
            $template->save();

            $seen[] = $template->id;
        }

        $missing = WhatsAppTemplate::whereNotIn('id', $seen)
            ->where('status', '!=', self::MISSING)
            ->update(['status' => self::MISSING, 'synced_at' => now()]);

        return [
            'total' => count($seen),
            'approved' => WhatsAppTemplate::whereIn('id', $seen)->approved()->count(),
            'added' => $added,
            'missing' => $missing,
        ];
    }

    /**
     * The columns one of Meta's template objects fills.
     *
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    public function parse(array $remote): array
    {
        $components = (array) ($remote['components'] ?? []);
        $body = '';
        $unsupported = null;

        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'BODY') {
                $body = (string) ($component['text'] ?? '');
            }

            if ($type === 'HEADER') {
                $format = strtoupper((string) ($component['format'] ?? 'TEXT'));

                if ($format !== 'TEXT') {
                    $unsupported ??= 'it has a '.strtolower($format).' header, which the CRM cannot attach.';
                } elseif ($this->variablesIn((string) ($component['text'] ?? '')) !== []) {
                    $unsupported ??= 'it has a variable in its header.';
                }
            }

            if ($type === 'BUTTONS') {
                foreach ((array) ($component['buttons'] ?? []) as $button) {
                    $buttonType = strtoupper((string) ($button['type'] ?? ''));

                    if (in_array($buttonType, self::PARAMETERISED_BUTTONS, true)
                        || ($buttonType === 'URL' && $this->variablesIn((string) ($button['url'] ?? '')) !== [])) {
                        $unsupported ??= 'it has a button that needs a value filled in.';
                    }
                }
            }
        }

        if (strtoupper((string) ($remote['category'] ?? '')) === 'AUTHENTICATION') {
            $unsupported ??= 'it is a one-time-passcode template.';
        }

        $variables = $this->variablesIn($body);

        return [
            'meta_id' => isset($remote['id']) ? (string) $remote['id'] : null,
            'category' => $remote['category'] ?? null,
            'status' => strtoupper((string) ($remote['status'] ?? 'UNKNOWN')),
            'body' => $body,
            'variables' => $variables,
            'parameter_format' => $variables !== [] && ! ctype_digit(implode('', $variables)) ? 'named' : 'positional',
            'unsupported_reason' => $unsupported,
            'components' => $components,
        ];
    }

    /**
     * The variables a piece of template text asks for, each once.
     *
     * Positional ones are sorted numerically, because Meta reads the
     * parameters as {{1}}, {{2}}… in that order whatever order the text uses
     * them in. Named ones keep the order they first appear in.
     *
     * @return list<string>
     */
    public function variablesIn(string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $matches);

        $variables = array_values(array_unique($matches[1]));

        if ($variables !== [] && ctype_digit(implode('', $variables))) {
            usort($variables, fn ($a, $b) => (int) $a <=> (int) $b);
        }

        return $variables;
    }
}
