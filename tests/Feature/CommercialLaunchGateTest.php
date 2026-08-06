<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommercialLaunchGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_pilot_blocks_public_commercial_registration_and_checkout(): void
    {
        config()->set('commercial.launch_enabled', false);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withSession([])
            ->postJson('/api/auth/register-admin', [
                'name' => 'Formateur Test',
                'email' => 'formateur@example.test',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])->assertServiceUnavailable();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/subscription/checkout', [
            'plan' => User::PLAN_PREMIUM,
            'billing_period' => 'monthly',
        ])->assertServiceUnavailable();
    }

    public function test_commercial_registration_can_open_only_when_the_server_flag_is_enabled(): void
    {
        config()->set('commercial.launch_enabled', true);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->withSession([])
            ->postJson('/api/auth/register-admin', [
                'name' => 'Formateur Autorisé',
                'email' => 'autorise@example.test',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])->assertCreated()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonMissingPath('token');
    }
}
