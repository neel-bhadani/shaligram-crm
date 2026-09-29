<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The one call this integration makes to Meta.
 *
 * A `leadgen` webhook carries no answers — only a `leadgen_id` and the page it
 * belongs to. The form data has to be fetched, authenticated as the page, which
 * is what the page access token is for and why the webhook alone is not enough
 * to import a lead.
 *
 * Its own class so the job can be tested without the network: every test in
 * the suite drives this through Http::fake().
 */
class MetaGraphClient
{
    /**
     * The raw `field_data` array for one lead.
     *
     * Meta answers with a list of {name, values} pairs whose names are whatever
     * the client called the questions on their form — normalising that is
     * MetaLeadNormaliser's job, not this one. This method's contract is
     * narrower: return what Meta said, or throw.
     *
     * @return list<array{name: string, values: list<string>}>
     *
     * @throws RuntimeException when Meta refuses or answers with no field data
     */
    public function fieldData(string $leadgenId, string $pageAccessToken): array
    {
        $base = rtrim(config('integrations.meta.graph_base'), '/');
        $version = config('integrations.meta.graph_version');

        $response = Http::timeout(config('integrations.meta.timeout'))
            ->retry(2, 200, throw: false)
            ->get("{$base}/{$version}/{$leadgenId}", [
                'access_token' => $pageAccessToken,
                'fields' => 'id,created_time,field_data',
            ]);

        if ($response->failed()) {
            /*
             | Meta's own message, not a generic one. "Error validating access
             | token: Session has expired" is the difference between an admin
             | who knows to paste a new token and an admin who files a bug —
             | and an expired page token is the commonest way this integration
             | dies months after it was set up.
             */
            $error = $response->json('error.message') ?? $response->body();

            throw new RuntimeException("Graph API refused the lead fetch ({$response->status()}): {$error}");
        }

        $fields = $response->json('field_data');

        if (! is_array($fields) || $fields === []) {
            throw new RuntimeException('Graph API returned no field_data for this lead.');
        }

        return $fields;
    }

    /**
     * A lead form's name as Ads Manager shows it, or null when Meta has none.
     *
     * @throws RuntimeException when Meta refuses
     */
    public function formName(string $formId, string $pageAccessToken): ?string
    {
        $response = $this->get($formId, $pageAccessToken, ['fields' => 'name']);

        if ($response->failed()) {
            throw new RuntimeException("Graph API refused the form lookup ({$response->status()}): ".$this->errorOf($response));
        }

        $name = trim((string) $response->json('name'));

        return $name === '' ? null : $name;
    }

    /**
     * Every lead form on the page, following Meta's paging.
     *
     * @return list<array{id: string, name: ?string}>
     *
     * @throws RuntimeException when Meta refuses
     */
    public function leadForms(string $pageId, string $pageAccessToken): array
    {
        $forms = [];
        $after = null;

        // a page with more than a thousand forms is not one this is built for;
        // the cap stops a paging bug on Meta's side becoming an endless loop
        for ($page = 0; $page < 10; $page++) {
            $response = $this->get("{$pageId}/leadgen_forms", $pageAccessToken, array_filter([
                'fields' => 'id,name',
                'limit' => 100,
                'after' => $after,
            ]));

            if ($response->failed()) {
                throw new RuntimeException("Graph API refused the form list ({$response->status()}): ".$this->errorOf($response));
            }

            foreach ((array) $response->json('data', []) as $form) {
                if (filled($form['id'] ?? null)) {
                    $forms[] = ['id' => (string) $form['id'], 'name' => filled($form['name'] ?? null) ? (string) $form['name'] : null];
                }
            }

            $after = $response->json('paging.cursors.after');

            if (! $response->json('paging.next') || ! $after) {
                break;
            }
        }

        return $forms;
    }

    private function get(string $path, string $pageAccessToken, array $query): Response
    {
        $base = rtrim(config('integrations.meta.graph_base'), '/');
        $version = config('integrations.meta.graph_version');

        return Http::timeout(config('integrations.meta.timeout'))
            ->retry(2, 200, throw: false)
            ->get("{$base}/{$version}/{$path}", ['access_token' => $pageAccessToken] + $query);
    }

    /** Meta's own error message — see fieldData() for why it is passed on. */
    private function errorOf(Response $response): string
    {
        return $response->json('error.message') ?? $response->body();
    }
}
