<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
     * @throws MetaGraphException when Meta refuses or cannot be reached
     * @throws RuntimeException when Meta answers with no field data
     */
    public function fieldData(string $leadgenId, string $pageAccessToken): array
    {
        $response = $this->get('lead fetch', $leadgenId, $pageAccessToken, ['fields' => 'id,created_time,field_data']);

        if ($response->failed()) {
            throw MetaGraphException::fromResponse('lead fetch', $response);
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
     * @throws MetaGraphException when Meta refuses or cannot be reached
     */
    public function formName(string $formId, string $pageAccessToken): ?string
    {
        $response = $this->get('form lookup', $formId, $pageAccessToken, ['fields' => 'name']);

        if ($response->failed()) {
            throw MetaGraphException::fromResponse('form lookup', $response);
        }

        $name = trim((string) $response->json('name'));

        return $name === '' ? null : $name;
    }

    /**
     * Every lead form on the page, following Meta's paging.
     *
     * @return list<array{id: string, name: ?string}>
     *
     * @throws MetaGraphException when Meta refuses or cannot be reached
     */
    public function leadForms(string $pageId, string $pageAccessToken): array
    {
        $forms = [];
        $after = null;

        // a page with more than a thousand forms is not one this is built for;
        // the cap stops a paging bug on Meta's side becoming an endless loop
        for ($page = 0; $page < 10; $page++) {
            $response = $this->get('form list', "{$pageId}/leadgen_forms", $pageAccessToken, array_filter([
                'fields' => 'id,name',
                'limit' => 100,
                'after' => $after,
            ]));

            if ($response->failed()) {
                throw MetaGraphException::fromResponse('form list', $response);
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

    /**
     * One GET against Graph, retried in place only when the failure is
     * transient — a timeout, a 5xx or a rate limit. Anything else comes back
     * on the first answer for the caller to turn into a MetaGraphException.
     *
     * @throws MetaGraphException when Meta cannot be reached at all
     */
    private function get(string $what, string $path, string $pageAccessToken, array $query): Response
    {
        $base = rtrim(config('integrations.meta.graph_base'), '/');
        $version = config('integrations.meta.graph_version');

        try {
            return Http::timeout(config('integrations.meta.timeout'))
                ->retry(2, 200, fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && MetaGraphException::isTransient($e->response)), throw: false)
                ->get("{$base}/{$version}/{$path}", ['access_token' => $pageAccessToken] + $query);
        } catch (ConnectionException $e) {
            throw MetaGraphException::unreachable($what, $e->getMessage());
        }
    }
}
