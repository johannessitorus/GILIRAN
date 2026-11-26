<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

final class BusinessAccountsTest extends TestCase
{
    use RefreshDatabase;

    private array $messages = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::getDatabaseName(), 'giliran_test')) {
            throw new RuntimeException('Gunakan basis data PostgreSQL khusus uji dengan awalan giliran_test.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::shouldReceive('raw')->andReturnUsing(function (string $body, callable $configure) {
            $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email());
            $configure($message);
            $this->messages[$message->getSymfonyMessage()->getTo()[0]->getAddress()] = $body;
        });
    }

    public function test_owner_registration_invitation_and_tenant_scope(): void
    {
        $owner = $this->register('owner-a@example.test', 'Toko A');
        $other = $this->register('owner-b@example.test', 'Toko B');
        $department = DB::table('departments')->where('business_id', $owner->business_id)->first();
        $foreign = DB::table('departments')->where('business_id', $other->business_id)->first();

        $this->actingAs($owner, 'web');
        $this->getJson('/api/v1/departments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/departments/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/users/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/shift-types')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/departments', ['name' => 'Cabang Utara'])->assertCreated();
        $email = 'supervisor@example.test';
        $invite = ['email' => $email, 'role' => 'SUPERVISOR', 'department_id' => $foreign->id];
        $this->postJson('/api/v1/auth/invitations', $invite)->assertNotFound();
        $invite['department_id'] = $department->id;
        $this->postJson('/api/v1/auth/invitations', $invite)->assertNoContent();
        $token = $this->token($email, 'accept-invite');
        $payload = ['token' => $token, 'name' => 'Supervisor', 'role' => 'ADMIN',
            'department_id' => $foreign->id] + $this->password('Password-123');
        $this->postJson('/api/v1/auth/links/accept-invite', $payload)->assertNoContent();
        $this->postJson('/api/v1/auth/links/accept-invite', $payload)->assertNotFound();
        $supervisor = User::where('email', $email)->firstOrFail();
        $this->assertSame('SUPERVISOR', $supervisor->role);
        $this->assertSame($department->id, $supervisor->department_id);
        $this->assertSame($owner->business_id, $supervisor->business_id);
        $this->app['auth']->forgetGuards();
        $this->actingAs($supervisor, 'web')->postJson('/api/v1/auth/invitations', $invite)->assertForbidden();
    }

    public function test_password_reset_is_single_use_and_revokes_database_sessions(): void
    {
        $email = 'owner@example.test';
        $owner = $this->register($email, 'Toko');
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test'])->assertNoContent();
        $this->assertArrayNotHasKey('unknown@example.test', $this->messages);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertNoContent();
        $token = $this->token($email, 'reset-password');
        $this->assertDatabaseHas('account_links', ['token_hash' => hash('sha256', $token)]);
        $payload = ['token' => $token] + $this->password('New-password-123');
        $this->postJson('/api/v1/auth/links/reset-password', $payload)->assertNoContent();
        $this->postJson('/api/v1/auth/links/reset-password', $payload)->assertNotFound();
        $this->assertTrue(Hash::check('New-password-123', $owner->fresh()->password_hash));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
    }

    private function register(string $email, string $business): User
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => $email, 'name' => 'Owner', 'business_name' => $business,
        ])->assertNoContent();
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->postJson('/api/v1/auth/links/verify-owner', [
            'token' => $this->token($email, 'verify-owner'),
        ] + $this->password('Password-123'))->assertNoContent();

        return User::where('email', $email)->firstOrFail();
    }

    private function password(string $value): array
    {
        return ['password' => $value, 'password_confirmation' => $value];
    }

    private function token(string $email, string $purpose): string
    {
        $this->assertMatchesRegularExpression('/#'.$purpose.'\?token=[a-f0-9]{64}/', $this->messages[$email]);
        preg_match('/token=([a-f0-9]{64})/', $this->messages[$email], $matches);

        return $matches[1];
    }
}
