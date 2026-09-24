<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    public function test_guests_can_read_the_privacy_policy_without_logging_in(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertViewIs('privacy-policy')
            ->assertSee('<title>Privacy Policy</title>', false)
            ->assertSee('https://shaligramgroup.co.in/privacy-policy')
            ->assertSeeInOrder([
                'Information We Collect',
                'How We Use Information',
                'How We Share Information',
                'Data Security',
                'Data Retention',
                'User Rights',
                'Third-Party Platforms',
                'Contact',
            ])
            ->assertSee('For privacy-related questions or requests, please contact us through our official website.');

        $this->assertGuest();
    }
}
