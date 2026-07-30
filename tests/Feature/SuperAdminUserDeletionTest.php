<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_delete_a_regular_user_and_revoke_their_tokens(): void
    {
        $superAdmin = User::factory()->create(['role' => 'superadmin']);
        $user = User::factory()->create(['role' => 'student']);
        $token = $user->createToken('mobile')->accessToken;

        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/superadmin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Utilisateur supprimé définitivement.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_super_admin_cannot_delete_their_own_account(): void
    {
        $superAdmin = User::factory()->create(['role' => 'superadmin']);

        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/superadmin/users/{$superAdmin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Vous ne pouvez pas supprimer votre propre compte.');

        $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
    }

    public function test_super_admin_cannot_delete_another_super_admin(): void
    {
        $superAdmin = User::factory()->create(['role' => 'superadmin']);
        $otherSuperAdmin = User::factory()->create(['role' => 'superadmin']);

        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/superadmin/users/{$otherSuperAdmin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Impossible de supprimer un super-administrateur.');

        $this->assertDatabaseHas('users', ['id' => $otherSuperAdmin->id]);
    }

    public function test_regular_user_cannot_delete_an_account(): void
    {
        $regularUser = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'student']);

        Sanctum::actingAs($regularUser);

        $this->deleteJson("/api/superadmin/users/{$target->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }
}
