<?php

use App\Http\Controllers\Webhooks\ElevenZaWebhookController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Incoming webhooks
|--------------------------------------------------------------------------
|
| The only routes in this application reachable without signing in.
|
| Registered in bootstrap/app.php WITHOUT the `web` middleware group, which is
| how the CSRF requirement is met: Meta cannot hold a session cookie or carry a
| token, and a stateless route never enters the middleware that would ask for
| one. There is no session here to fixate and no cookie to steal.
|
| What stands in for the session is the signature — see
| MetaWebhookController::signatureIsValid(). Every POST is an HMAC of its own
| body under the app secret, compared in constant time, and a body that does not
| match is refused with a 403 before anything parses it.
|
| Throttled by IP. Meta's real delivery rate is far below this; the limit is
| there because the URL is public and the alternative to a 429 is an unbounded
| number of HMAC computations on attacker-supplied bodies.
|
| `provider` is constrained to the platforms that are actually built, so
| /webhooks/whatsapp/leads is a 404 rather than a route that accepts a payload
| nothing will ever process.
*/

$built = collect(config('integrations.providers'))
    ->filter(fn (array $meta) => $meta['built'] && $meta['family'] === 'meta')
    ->keys()
    ->all();

Route::middleware('throttle:webhooks')
    ->prefix('webhooks/{provider}')
    ->whereIn('provider', $built)
    ->group(function () {
        // Meta's one-time handshake: echo hub.challenge if hub.verify_token matches
        Route::get('leads', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');

        // a lead notification: verify, queue, 200
        Route::post('leads', [MetaWebhookController::class, 'handle'])->name('webhooks.meta.handle');
    });

/*
 | 11za's Customer Webhook for WhatsApp chatbot contacts. Authenticated by the
 | `x-api-key` header rather than a signature; the controller 404s until the
 | provider is marked built in config/integrations.php.
 */
Route::post('webhooks/whatsapp/customers', [ElevenZaWebhookController::class, 'handle'])
    ->middleware('throttle:webhooks')
    ->name('webhooks.whatsapp.customers');
