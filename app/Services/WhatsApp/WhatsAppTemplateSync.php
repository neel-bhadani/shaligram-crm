<?php

namespace App\Services\WhatsApp;

use App\Models\MessageTemplate;
use App\Models\WhatsAppTemplate;

/**
 * Pull the account's templates from Meta and record what it says about them.
 *
 * One direction only. Templates are written and submitted in Meta's WhatsApp
 * Manager; this reads back name, language, category, status and body so the
 * CRM knows which ones it may send and can say why the others are refused.
 *
 * A template Meta stops returning is marked DELETED, not removed: a message
 * may be linked to it, and the link is what tells the admin why that message
 * stopped going out by API.
 */
class WhatsAppTemplateSync
{
    public function __construct(
        private WhatsAppCloudClient $client,
        private WhatsAppSender $sender,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function run(): array
    {
        $integration = $this->sender->integration();
        $token = (string) $integration->setting('access_token');
        $wabaId = (string) $integration->setting('waba_id');

        if ($token === '' || $wabaId === '') {
            return ['ok' => false, 'message' => 'Save the WhatsApp Business Account ID and access token before syncing templates.'];
        }

        try {
            $remote = $this->client->templates($token, $wabaId);
        } catch (WhatsAppApiException $e) {
            return ['ok' => false, 'message' => 'Templates could not be synced. '.$e->explain()];
        }

        $seen = [];

        foreach ($remote as $row) {
            if (blank($row['name'] ?? null) || blank($row['language'] ?? null)) {
                continue;
            }

            $template = WhatsAppTemplate::updateOrCreate(
                ['name' => $row['name'], 'language' => $row['language']],
                [
                    'meta_id' => $row['id'] ?? null,
                    'status' => strtoupper((string) ($row['status'] ?? 'UNKNOWN')),
                    'category' => isset($row['category']) ? strtolower((string) $row['category']) : null,
                    'body' => $this->bodyOf($row['components'] ?? []),
                    'components' => $row['components'] ?? [],
                    'synced_at' => now(),
                ],
            );

            $seen[] = $template->id;
        }

        WhatsAppTemplate::whereNotIn('id', $seen)->update(['status' => 'DELETED', 'synced_at' => now()]);

        $this->mirrorOntoMessages();

        $approved = WhatsAppTemplate::approved()->count();

        return [
            'ok' => true,
            'message' => count($seen).' template'.(count($seen) === 1 ? '' : 's')." synced from Meta, {$approved} approved.",
        ];
    }

    /**
     * Copy each linked template's name and status onto the CRM message, so the
     * Templates tab and anything else reading `approval_status` agree with
     * Meta without a join.
     */
    public function mirrorOntoMessages(): void
    {
        MessageTemplate::with('whatsappTemplate')->whereNotNull('whatsapp_template_id')->get()
            ->each(fn (MessageTemplate $message) => $message->update([
                'meta_template_name' => $message->whatsappTemplate?->name,
                'approval_status' => strtolower((string) $message->whatsappTemplate?->status),
            ]));
    }

    /** @param  array<int, array<string, mixed>>  $components */
    private function bodyOf(array $components): ?string
    {
        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                return $component['text'] ?? null;
            }
        }

        return null;
    }
}
