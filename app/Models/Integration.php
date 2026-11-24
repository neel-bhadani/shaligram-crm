<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A connected platform and its settings.
 *
 * The settings column is `encrypted:array`, which is what keeps the page access
 * token and the app secret out of a database dump. It is also why nothing may
 * hand `$integration->settings` to a view: decryption happens on read, so the
 * array in PHP holds the tokens in the clear and shipping it to Inertia would
 * put them in the page source. IntegrationController::card() is the only thing
 * that serialises this model, and it masks both.
 */
class Integration extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'encrypted:array',
        'is_active' => 'boolean',
        'last_received_at' => 'datetime',
        'last_delivery_at' => 'datetime',
        'last_rejected_at' => 'datetime',
    ];

    /** The keys that must never be sent to the browser in the clear. */
    public const SECRET_KEYS = ['page_access_token', 'app_secret', 'api_key'];

    /** What each family of integration cannot work without. */
    public const REQUIRED_SETTINGS = [
        'meta' => ['page_access_token', 'app_secret', 'default_project_id', 'assign_to_user_id'],
        'whatsapp' => ['api_key', 'default_project_id', 'assign_to_user_id'],
    ];

    public function events()
    {
        return $this->hasMany(IntegrationEvent::class, 'provider', 'provider');
    }

    /**
     * The row for a provider, existing or not yet saved.
     *
     * Returns an unsaved model rather than null so every caller can ask a
     * consistent question — `$integration->setting('page_id')` on a platform
     * nobody has configured is null, not a crash.
     */
    public static function forProvider(string $provider): self
    {
        return static::firstOrNew(['provider' => $provider]);
    }

    /**
     * The project every WhatsApp lead lands on before somebody chooses its
     * real one with Switch project, or null when none is configured.
     */
    public static function whatsAppDefaultProjectId(): ?int
    {
        $id = (int) static::where('provider', 'whatsapp')->first()?->setting('default_project_id');

        return $id > 0 ? $id : null;
    }

    /** One setting, or the fallback. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Merge new settings over the stored ones.
     *
     * Merge, not replace, because the settings form deliberately does not send
     * the tokens back: an admin who edits the page ID must not blank the access
     * token by leaving the (empty, masked) token field alone. The controller
     * strips the empty replacement fields before calling this, so what arrives
     * here is only what the admin actually changed.
     */
    public function mergeSettings(array $changes): void
    {
        $this->settings = array_merge($this->settings ?? [], $changes);
    }

    /**
     * The token Meta echoes back during the verification handshake.
     *
     * Generated once and then stable: it is pasted into the Meta app dashboard,
     * so regenerating it on every save would silently break a live webhook the
     * next time Meta re-verified. Not a secret in the way the access token is —
     * it proves the endpoint belongs to whoever configured the app, and it has
     * to be readable on screen for the admin to paste it.
     */
    public function ensureVerifyToken(): string
    {
        if (! $this->setting('verify_token')) {
            $this->mergeSettings(['verify_token' => Str::random(32)]);
            $this->save();
        }

        return $this->setting('verify_token');
    }

    /**
     * The key 11za sends in the `x-api-key` header.
     *
     * Generated once and then stable: it is pasted into 11za's webhook
     * settings, so regenerating it on every save would break a live webhook.
     */
    public function ensureApiKey(): string
    {
        if (! $this->setting('api_key')) {
            $this->mergeSettings(['api_key' => Str::random(40)]);
            $this->save();
        }

        return $this->setting('api_key');
    }

    /** Whether the key on a request is ours. hash_equals, so timing leaks nothing. */
    public function apiKeyMatches(?string $presented): bool
    {
        $expected = (string) $this->setting('api_key');

        return $expected !== '' && hash_equals($expected, (string) $presented);
    }

    /**
     * Configured well enough to accept a lead.
     *
     * The four things the import cannot proceed without: something to
     * authenticate the Graph call, something to verify the signature with, a
     * project to file the lead against and somebody to give it to. `is_active`
     * is separate — it is the admin's switch, this is the readiness of the row.
     */
    public function isConfigured(): bool
    {
        $family = config("integrations.providers.{$this->provider}.family");
        $required = self::REQUIRED_SETTINGS[$family] ?? null;

        // a provider with no known family can never be ready
        if ($required === null) {
            return false;
        }

        foreach ($required as $key) {
            if (blank($this->setting($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * A provider request passed the key or signature check. One update query,
     * no model events: it runs on every webhook delivery.
     */
    public static function recordDelivery(string $provider): void
    {
        static::where('provider', $provider)->update(['last_delivery_at' => now()]);
    }

    /**
     * A request was refused for a wrong or missing key or signature, on a row
     * that has a secret to compare against. The endpoints are public, so this
     * writes at most once a minute however often it is called.
     */
    public static function recordRejection(string $provider): void
    {
        static::where('provider', $provider)
            ->where(fn ($q) => $q->whereNull('last_rejected_at')->orWhere('last_rejected_at', '<', now()->subMinute()))
            ->update(['last_rejected_at' => now()]);
    }

    /** Whether the last request seen was refused rather than delivered. */
    public function keyMismatch(): bool
    {
        if ($this->last_rejected_at === null) {
            return false;
        }

        return $this->last_delivery_at === null || $this->last_rejected_at->greaterThan($this->last_delivery_at);
    }

    /** Whether it is switched on AND has what it needs. */
    public function isReady(): bool
    {
        return $this->exists && $this->is_active && $this->isConfigured();
    }

    /**
     * A token as the browser is allowed to see it: the last four characters and
     * nothing else, or null when there is no token stored at all.
     *
     * Four characters is enough for an admin to tell "the token I pasted last
     * Tuesday" from "some other token", and not enough to be worth stealing.
     */
    public function maskedSetting(string $key): ?string
    {
        $value = (string) $this->setting($key);

        if ($value === '') {
            return null;
        }

        return str_repeat('•', 8).substr($value, -4);
    }
}
