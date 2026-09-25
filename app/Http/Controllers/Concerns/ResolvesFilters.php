<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Page filters live in the session, not the address bar.
 *
 * The request still carries them — every filter control is still a GET visit —
 * but the front end wipes the query string once the visit lands, so the state
 * has to survive somewhere. Each page owns a key of its own, `filters.leads`
 * and so on, which is what keeps the three pages from overwriting each other.
 *
 * Three shapes of request, in order of precedence:
 *
 *   reset=1        forget the stored state, then apply whatever else the
 *                  request names. The To-do page uses that second half: Clear
 *                  wipes the filters but resends the tab, which is the tab the
 *                  user is looking at and not something they asked to lose.
 *
 *   any owned key  the request is the instruction. It is merged over the
 *                  stored state, so a page only has to name the keys it owns.
 *                  An empty value ("search=") is how a page says "drop this
 *                  one" — absence means "leave it alone", which is why the
 *                  filter visits send every key rather than only the set ones.
 *
 *   no owned key   a plain visit — a nav link, a page link, a redirect back
 *                  from a modal. Read the stored state and change nothing.
 */
trait ResolvesFilters
{
    /**
     * @param  array<string, array>  $rules  one entry per key the page owns
     * @param  array<string, mixed>  $defaults  filled in where the state is silent
     * @param  ?callable  $normalise  last word on the resolved state,
     *                                for rules a validator cannot express
     */
    protected function resolveFilters(
        Request $request,
        string $page,
        array $rules,
        array $defaults = [],
        ?callable $normalise = null,
    ): array {
        $sessionKey = "filters.$page";
        $reset = $request->boolean('reset');

        $stored = $reset ? [] : (array) $request->session()->get($sessionKey, []);

        $incoming = [];

        foreach (array_keys($rules) as $key) {
            if ($request->has($key)) {
                $incoming[$key] = $request->input($key);
            }
        }

        $state = array_merge($this->withoutEmpty($stored), $incoming);

        if (isset($state['search']) && is_string($state['search'])) {
            $state['search'] = trim($state['search']);
        }

        $state = $this->withoutEmpty($state);

        /*
         | The session is user-controlled — it was filled from a query string
         | the user could type — so nothing goes near a query builder until it
         | has passed the page's own rules. A key that fails is dropped on its
         | own rather than taking the rest of the filters down with it.
         */
        $failed = array_keys(Validator::make($state, $rules)->errors()->toArray());

        foreach ($failed as $key) {
            unset($state[$key]);
        }

        if ($normalise) {
            $state = $normalise($state);
        }

        // store the validated state, so a bad value never gets a second chance
        $request->session()->put($sessionKey, $state);

        return array_merge($defaults, $state);
    }

    /**
     * '' and null are absence, not values. 0 and '0' are values — no filter
     * uses them today, but dropping them would be a trap for one that did.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $state): array
    {
        return array_filter($state, fn ($v) => $v !== '' && $v !== null && $v !== []);
    }
}
