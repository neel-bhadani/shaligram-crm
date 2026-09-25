<?php

namespace App\Providers;

use App\Services\AlertService;
use App\Services\Automation\LoopGuard;
use App\Services\Automation\RuleEngine;
use App\Services\LeadFollowUpService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->automation();
    }

    /**
     * The three pieces of automation that have to be ONE piece of automation.
     *
     * All three carry state that only means anything if there is a single
     * instance of it for the length of a request:
     *
     *   LoopGuard             counts how many times automation has touched a
     *                         lead in the current chain. A fresh instance per
     *                         injection would count to one for ever, and two
     *                         rules undoing each other would never be stopped.
     *
     *   LeadFollowUpService   holds the triggers waiting for the current
     *                         transaction to commit, and the "this is the
     *                         system acting, not a person" depth. Two instances
     *                         would mean a rule writing a to-do stamped with
     *                         the signed-in user's id.
     *
     *   RuleEngine            holds the suspension flag, so withoutRules()
     *                         actually reaches the code inside it.
     *
     *   AlertService          holds the same kind of suspension flag, for the
     *                         same reason — see withoutAlerts(). Bound here
     *                         too, otherwise LeadAssignmentService's copy and
     *                         a bulk importer's copy would be two different
     *                         instances and suspending one would do nothing
     *                         to the other.
     *
     * They are containers of request state rather than stateless helpers, which
     * is exactly what a singleton binding is for. Nothing here is shared
     * between requests — the container is rebuilt for each one.
     */
    private function automation(): void
    {
        $this->app->singleton(LoopGuard::class);
        $this->app->singleton(RuleEngine::class);
        $this->app->singleton(LeadFollowUpService::class);
        $this->app->singleton(AlertService::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->rateLimiters();
    }

    /**
     * The webhook limit.
     *
     * Meta batches its deliveries and its real rate for one page's lead forms
     * is a handful a minute at most, so 120 is generous for the traffic that is
     * meant to arrive and still a ceiling on traffic that is not: the URL is
     * public, and every POST costs an HMAC over an attacker-supplied body.
     *
     * Keyed by IP rather than by provider, because the thing being limited is a
     * caller, not a platform — one misbehaving source must not use up the
     * budget Meta needs.
     *
     * A 429 is safe here in a way it would not be elsewhere: Meta treats
     * anything but a 2xx as a failed delivery and redelivers, so a throttled
     * lead is delayed rather than lost.
     */
    private function rateLimiters(): void
    {
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
