<?php

namespace App\Services\WhatsApp;

use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Support\CrmTaxonomy;

/**
 * Turns a template into the text that actually gets sent, and a mobile number
 * into something wa.me will accept.
 *
 * Both halves are fiddlier than they look, and both fail silently when they go
 * wrong — a placeholder nobody replaced goes out as "{first_name}" to a real
 * customer, and a number with a space in it opens WhatsApp on nothing at all.
 */
class TemplateRenderer
{
    /**
     * The named placeholders a body may use, in the order the picker lists them.
     *
     * @return array<string, array{label: string, example: string}>
     */
    public function placeholders(): array
    {
        return config('automation.whatsapp.placeholders', []);
    }

    /**
     * Fill a body in for one lead.
     *
     * Anything the lead cannot answer becomes an empty string rather than
     * staying as "{owner_name}". A message with a gap in it reads like a
     * mistake; a message with braces in it reads like the CRM is broken.
     */
    public function render(string $body, Lead $lead): string
    {
        $values = $this->valuesFor($lead);

        return $this->replace($body, $values);
    }

    /**
     * The same, against an invented lead, for the live preview in the editor.
     *
     * The examples come from config so the preview and the placeholder picker
     * cannot disagree about what {project} turns into.
     */
    public function preview(string $body): string
    {
        $values = collect($this->placeholders())
            ->map(fn (array $meta) => $meta['example'])
            ->all();

        return $this->replace($body, $values);
    }

    /**
     * What each placeholder is worth for this lead.
     *
     * @return array<string, string>
     */
    public function valuesFor(Lead $lead): array
    {
        $owner = $lead->owner;

        return [
            'lead_name'   => $lead->full_name,
            'first_name'  => (string) $lead->first_name,
            'project'     => (string) ($lead->project?->name ?? ''),
            'owner_name'  => (string) ($owner?->display_name ?? ''),
            'owner_phone' => $owner?->mobile_number
                ? config('crm.country_code') . ' ' . $owner->mobile_number
                : '',
            'stage'       => CrmTaxonomy::stageLabel($lead->stage),
        ];
    }

    /**
     * Which placeholders a body actually uses, in the order they first appear.
     *
     * This is the numbering Meta will want. A submitted template carries {{1}},
     * {{2}} and so on, and Meta has no idea what they mean — the mapping is the
     * application's to keep. Working it out at submission time would mean
     * guessing the order for every template already written, so it is stored
     * the moment the body is saved. See MessageTemplate::$placeholder_map.
     *
     * @return array<int, string>  ['lead_name', 'project'] — {{1}}, {{2}}
     */
    public function mapFor(string $body): array
    {
        $known = array_keys($this->placeholders());
        $order = [];

        if (preg_match_all('/\{([a-z_]+)\}/', $body, $matches)) {
            foreach ($matches[1] as $name) {
                if (in_array($name, $known, true) && ! in_array($name, $order, true)) {
                    $order[] = $name;
                }
            }
        }

        return $order;
    }

    /**
     * The body as Meta would receive it: {{1}}, {{2}}, numbered by that map.
     *
     * Nothing submits templates yet — there are no credentials — but the
     * numbering is stored now precisely so that nothing has to be rewritten
     * when there are.
     */
    public function toMetaBody(string $body, ?array $map = null): string
    {
        $map = $map ?? $this->mapFor($body);

        foreach ($map as $index => $name) {
            $body = str_replace('{' . $name . '}', '{{' . ($index + 1) . '}}', $body);
        }

        return $body;
    }

    /**
     * Placeholders written in a body that this application does not know.
     *
     * The editor shows these as a warning rather than refusing to save: a body
     * may legitimately contain a brace, and refusing on a false positive is
     * worse than pointing at it.
     *
     * @return array<int, string>
     */
    public function unknownPlaceholders(string $body): array
    {
        $known   = array_keys($this->placeholders());
        $unknown = [];

        if (preg_match_all('/\{([a-z_]+)\}/', $body, $matches)) {
            foreach ($matches[1] as $name) {
                if (! in_array($name, $known, true) && ! in_array($name, $unknown, true)) {
                    $unknown[] = $name;
                }
            }
        }

        return $unknown;
    }

    /* ---------------- the number ---------------- */

    /**
     * A stored mobile number as wa.me needs it: country code and ten digits,
     * nothing else.
     *
     * Numbers in this application are stored as bare digits, but they are typed
     * by people and imported from Facebook, so this has to survive
     * "+91 98765-43210", "(98765) 43210" and "09876543210". Everything that is
     * not a digit goes; anything longer than ten digits is trimmed from the
     * LEFT, because what a long number has on the front is a country code or a
     * trunk zero and what it has on the end is the actual subscriber number.
     *
     * Returns null when there are not ten digits to work with. A wa.me link
     * built on a short number does not fail — it opens WhatsApp on a search for
     * a person who does not exist, which the user reads as the CRM sending the
     * message to a stranger.
     */
    public function waNumber(?string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile);

        if (strlen($digits) < 10) {
            return null;
        }

        $local = substr($digits, -10);
        $code  = preg_replace('/\D+/', '', (string) config('crm.country_code', '+91'));

        return $code . $local;
    }

    /**
     * The click-to-send link.
     *
     * https://wa.me/919876543210?text=<url-encoded body>
     *
     * rawurlencode, not urlencode: the difference is the space, which urlencode
     * turns into "+" and WhatsApp shows as a literal plus sign in the middle of
     * every sentence.
     */
    public function clickUrl(?string $mobile, string $body): ?string
    {
        $number = $this->waNumber($mobile);

        if (! $number) {
            return null;
        }

        return config('automation.whatsapp.link_base', 'https://wa.me/')
            . $number
            . '?text=' . rawurlencode($body);
    }

    /* ---------------- internals ---------------- */

    /** @param array<string, string> $values */
    private function replace(string $body, array $values): string
    {
        foreach ($this->placeholders() as $name => $meta) {
            $body = str_replace('{' . $name . '}', (string) ($values[$name] ?? ''), $body);
        }

        return $body;
    }

    /**
     * A template rendered for a lead, ready to be logged and sent.
     *
     * @return array{body: string, number: ?string, url: ?string}
     */
    public function build(MessageTemplate $template, Lead $lead): array
    {
        $body = $this->render($template->body, $lead);

        return [
            'body'   => $body,
            'number' => $this->waNumber($lead->mobile_number),
            'url'    => $this->clickUrl($lead->mobile_number, $body),
        ];
    }
}
