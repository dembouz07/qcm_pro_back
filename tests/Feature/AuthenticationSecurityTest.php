<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_party_spa_login_uses_a_session_without_exposing_a_plain_text_token(): void
    {
        $user = User::factory()->create([
            'email' => 'trainer@example.test',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->withHeader('Referer', 'http://localhost:5173/login')
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('token');

        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_non_browser_client_receives_a_named_mobile_token(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile@example.test',
            'password' => Hash::make('Password123!'),
        ]);

        $this->postJson('/api/auth/token', [
            'email' => $user->email,
            'password' => 'Password123!',
            'device_name' => 'Test Android',
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Test Android',
        ]);
    }

    public function test_password_reset_requires_a_valid_one_time_token_and_revokes_access_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.test',
            'password' => Hash::make('OldPassword123!'),
        ]);
        $user->createToken('mobile');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'AnotherPassword123!',
            'password_confirmation' => 'AnotherPassword123!',
        ])->assertUnprocessable();
    }

    public function test_password_reset_request_does_not_reveal_whether_an_email_exists(): void
    {
        $existing = User::factory()->create(['email' => 'known@example.test']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => $existing->email]);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_changing_email_requires_the_current_password(): void
    {
        $user = User::factory()->create([
            'email' => 'before@example.test',
            'password' => Hash::make('Password123!'),
        ]);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'name' => $user->name,
                'email' => 'after@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame('before@example.test', $user->fresh()->email);
    }

    public function test_data_export_requires_reauthentication_and_disables_caching(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
        ]);

        $this->actingAs($user)
            ->postJson('/api/auth/export', ['current_password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->actingAs($user)
            ->postJson('/api/auth/export', ['current_password' => 'Password123!'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('account.password');
    }
}
