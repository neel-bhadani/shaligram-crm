<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The migration that adds last_delivery_at fills it from the activity log, so
 * a live integration still reads as Connected after deploy — and one that has
 * only ever seen test leads does not.
 */
class IntegrationDeliveryBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_real_delivery_in_the_log_backfills_last_delivery_at(): void
    {
        $migration = $this->migration();
        $migration->down();

        Integration::create(['provider' => 'facebook', 'is_active' => true]);
        Integration::create(['provider' => 'whatsapp', 'is_active' => true]);

        $this->event('facebook', 'created', '1234567890', '2026-11-20 10:00:00');
        $this->event('facebook', 'test', '4444', '2026-11-21 10:00:00');
        $this->event('facebook', 'created', 'test_abcdef', '2026-11-22 10:00:00');
        $this->event('whatsapp', 'created', '11za:9999912345:2026-11-23', '2026-11-23 10:00:00');
        $this->event('whatsapp', 'test', null, '2026-11-23 11:00:00');

        $migration->up();

        $this->assertEquals(Carbon::parse('2026-11-20 10:00:00'), Integration::forProvider('facebook')->last_delivery_at);
        $this->assertNull(Integration::forProvider('whatsapp')->last_delivery_at);
        $this->assertNull(Integration::forProvider('facebook')->last_rejected_at);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_24_114856_add_delivery_timestamps_to_integrations_table.php');
    }

    private function event(string $provider, string $result, ?string $externalId, string $at): void
    {
        $event = IntegrationEvent::create([
            'provider' => $provider,
            'result' => $result,
            'external_id' => $externalId,
            'message' => 'Logged.',
        ]);

        $event->forceFill(['created_at' => $at])->save();
    }
}
