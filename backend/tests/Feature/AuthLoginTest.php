<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCoreData;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase, CreatesCoreData;

    public function test_valid_login_returns_token_and_user(): void
    {
        $teacher = $this->makeTeacher();

        $res = $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'password']);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'teacher')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);
    }

    public function test_wrong_password_is_rejected_with_arabic_message(): void
    {
        $teacher = $this->makeTeacher();

        $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'wrong-pass'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'البريد الإلكتروني أو كلمة المرور غير صحيحة');
    }

    public function test_login_is_throttled_after_10_attempts(): void
    {
        $teacher = $this->makeTeacher();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'wrong-pass'])
                ->assertStatus(422);
        }

        // المحاولة 11 خلال نفس الدقيقة → 429 (S4)
        $this->postJson('/api/auth/login', ['email' => $teacher->email, 'password' => 'wrong-pass'])
            ->assertStatus(429);
    }

    public function test_protected_route_without_token_is_401(): void
    {
        $this->getJson('/api/auth/user')->assertStatus(401);
    }
}
