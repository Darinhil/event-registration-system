<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_with_email_or_phone_returns_a_token(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'Sokha', 'identifier' => 'sokha@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertCreated()->assertJsonStructure(['data' => ['id', 'email'], 'token'])->assertJsonPath('data.role', 'attendee');

        $this->postJson('/api/auth/register', ['name' => 'Dara', 'identifier' => '+855 12 345 678', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertCreated()->assertJsonPath('data.phone', '+855 12 345 678');
    }

    public function test_signup_rejects_mismatched_passwords_and_duplicate_accounts(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'Sokha', 'identifier' => 'sokha@example.com', 'password' => 'secret123', 'password_confirmation' => 'different'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        User::factory()->create(['email' => 'taken@example.com']);
        $this->postJson('/api/auth/register', ['name' => 'Copy', 'identifier' => 'taken@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertStatus(422)->assertJsonValidationErrors('identifier');
    }

    public function test_signup_cannot_assign_itself_a_staff_role(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'Eve', 'identifier' => 'eve@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'role' => 'admin'])
            ->assertCreated();

        $this->assertSame('attendee', User::where('email', 'eve@example.com')->value('role'));
    }

    public function test_login_accepts_email_or_phone_and_rejects_wrong_passwords(): void
    {
        User::factory()->create(['email' => 'sokha@example.com', 'phone' => '012345678', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', ['identifier' => 'sokha@example.com', 'password' => 'secret123'])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/auth/login', ['identifier' => '012345678', 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/auth/login', ['identifier' => 'sokha@example.com', 'password' => 'wrong-pass'])->assertStatus(422);
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/auth/login', ['identifier' => 'nobody@example.com', 'password' => 'wrong-pass'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['identifier' => 'nobody@example.com', 'password' => 'wrong-pass'])->assertStatus(429);
    }

    public function test_protected_routes_return_json_401_without_a_token(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }
}
