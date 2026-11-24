<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppLead;
use App\Models\Integration;
use App\Services\IntegrationLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * 11za's "Customer Webhook": a WhatsApp chatbot contact, pushed to us.
 *
 * Stateless and public like the Meta routes — see routes/webhooks.php. The
 * trust boundary is the `x-api-key` header, which 11za sends with the key the
 * admin copied from the Integrations page.
 *
 * 11za wants a 2xx within ten seconds, retries five times on anything else and
 * then deactivates the webhook. So once the key matches, every answer is a 200:
 * a malformed payload will not fix itself on retry, and a 4xx would only count
 * towards switching the webhook off. What went wrong is recorded on the
 * Integrations page instead.
 */
class ElevenZaWebhookController extends Controller
{
    private const PROVIDER = 'whatsapp';

    private const TYPES = ['insert', 'update'];

    public function handle(Request $request, IntegrationLogger $log): Response
    {
        abort_unless(config('integrations.providers.'.self::PROVIDER.'.built'), 404);

        $integration = Integration::forProvider(self::PROVIDER);

        if (! $integration->apiKeyMatches($request->header('x-api-key'))) {
            /*
             | Not logged to integration_events: anyone can call this URL, and
             | a log an anonymous visitor can fill is not an activity log.
             */
            Log::warning('[integration:'.self::PROVIDER.'] webhook api key rejected', ['ip' => $request->ip()]);

            // only once there is a key for 11za to have got wrong
            if (filled($integration->setting('api_key'))) {
                Integration::recordRejection(self::PROVIDER);
            }

            return response('Invalid API key.', 401);
        }

        Integration::recordDelivery(self::PROVIDER);

        $type = $request->input('type');
        $data = $request->input('data');
        $mobileNo = is_array($data) ? trim((string) ($data['mobileNo'] ?? '')) : '';

        if (! in_array($type, self::TYPES, true) || $mobileNo === '') {
            $log->failed(
                self::PROVIDER,
                $mobileNo !== '' ? $mobileNo : null,
                'Payload from 11za was not understood: it needs a type of "insert" or "update" and a data.mobileNo.',
            );

            return response('', 200);
        }

        if (! $integration->isReady()) {
            $log->failed(self::PROVIDER, $mobileNo, 'Received while the integration was switched off or incomplete.');

            return response('', 200);
        }

        ProcessWhatsAppLead::dispatch($type, $data);

        return response('', 200);
    }
}
