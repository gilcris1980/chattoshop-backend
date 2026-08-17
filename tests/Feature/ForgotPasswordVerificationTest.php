<?php

namespace Tests\Feature;

use App\Models\Otp;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ForgotPasswordVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->enum('role', ['system_admin', 'admin', 'seller', 'customer'])->default('customer');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('avatar')->nullable();
            $table->string('seller_status')->nullable();
        });

        Schema::create('otps', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type');
            $table->string('otp');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });

        Schema::create('password_reset_tokens', function ($table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function ($table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function makeUser(bool $verified, string $suffix = 'example.com'): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => ($verified ? 'verified' : 'unverified') . rand(1000, 9999) . "@$suffix",
            'password' => Hash::make('password123'),
            'role' => 'customer',
        ]);

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    public function test_unverified_account_cannot_request_password_reset(): void
    {
        Notification::fake();

        $user = $this->makeUser(false);

        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $response->assertStatus(403)
            ->assertJsonPath('needs_verification', true)
            ->assertJsonPath('email', $user->email);

        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_verified_account_can_request_password_reset(): void
    {
        Notification::fake();

        $user = $this->makeUser(true);

        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $response->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');

        $this->assertDatabaseCount('password_reset_tokens', 1);
        Notification::assertSentTo($user, PasswordResetNotification::class);
    }

    public function test_email_verification_flow_still_works_and_then_unlocks_forgot_password(): void
    {
        $user = $this->makeUser(false);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(403);

        Otp::create([
            'user_id' => $user->id,
            'type' => 'email_verification',
            'otp' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/verify-email', ['email' => $user->email, 'otp' => '123456'])
            ->assertOk()
            ->assertJsonPath('message', 'Email verified successfully');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('otps', 0);

        Notification::fake();
        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 1);
        Notification::assertSentTo($user->fresh(), PasswordResetNotification::class);
    }

    public function test_login_flow_unaffected(): void
    {
        $unverified = $this->makeUser(false);
        $verified = $this->makeUser(true);

        $this->postJson('/api/login', ['email' => $unverified->email, 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonPath('needs_verification', true);

        $response = $this->postJson('/api/login', ['email' => $verified->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['email']]);

        $this->assertSame($verified->email, $response->json('user.email'));
    }

    public function test_reset_password_flow_unaffected_for_verified_account(): void
    {
        Notification::fake();

        $user = $this->makeUser(true);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');

        Notification::assertSentTo($user, PasswordResetNotification::class);

        $notification = Notification::sent($user, PasswordResetNotification::class)->first();

        $this->postJson('/api/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk()
            ->assertJsonPath('message', 'Password reset successfully');

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }
}