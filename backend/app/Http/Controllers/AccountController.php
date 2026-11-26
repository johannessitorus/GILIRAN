<?php

namespace App\Http\Controllers;

use App\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class AccountController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:160',
            'business_name' => 'required|string|max:120',
        ]);
        $email = strtolower($data['email']);
        if (! DB::table('users')->where('email', $email)->exists()) {
            $this->send('verify-owner', $email, ['name' => $data['name'], 'business_name' => $data['business_name']]);
        }

        return response()->noContent();
    }

    public function invite(Request $request)
    {
        $owner = $request->user();
        abort_unless($owner?->active && $owner->role === 'ADMIN', 403);
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'department_id' => 'required|integer|min:1',
            'role' => 'required|in:SUPERVISOR,EMPLOYEE',
        ]);
        $department = DB::table('departments')->where('business_id', $owner->business_id)
            ->where('active', true)->find($data['department_id']);
        abort_unless($department, 404);
        $business = DB::table('businesses')->find($owner->business_id);
        $email = strtolower($data['email']);
        $this->send('accept-invite', $email, [
            'business_id' => $owner->business_id, 'department_id' => $department->id,
            'role' => $data['role'],
        ], $business->name);

        return response()->noContent();
    }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $email = strtolower($data['email']);
        $account = DB::table('users')->where('email', $email)->where('active', true)->first();
        if ($account) {
            $this->send('reset-password', $email, ['user_id' => $account->id]);
        }

        return response()->noContent();
    }

    public function complete(Request $request, string $purpose)
    {
        $data = $request->validate(['token' => 'required|regex:/^[a-f0-9]{64}$/D']);
        $hash = hash('sha256', $data['token']);
        try {
            $resetAccount = DB::transaction(function () use ($request, $purpose, $hash) {
                $links = DB::table('account_links')->where('purpose', $purpose);
                $email = (clone $links)->where('token_hash', $hash)->value('email');
                abort_unless($email, 404);
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
                $link = (clone $links)->where('token_hash', $hash)->where('expires_at', '>', now())->lockForUpdate()->first();
                abort_unless($link, 404);
                $payload = json_decode($link->payload, true, flags: JSON_THROW_ON_ERROR);
                $account = DB::table('users')->where('email', $email)->lockForUpdate()->first();
                if ($purpose === 'reset-password') {
                    abort_unless($account?->active && $account->id === $payload['user_id'], 404);
                    DB::table('users')->where('id', $account->id)->update([
                        'password_hash' => $this->password($request), 'remember_token' => null, 'updated_at' => now(),
                    ]);
                    DB::table('sessions')->where('user_id', $account->id)->delete();
                } elseif ($purpose === 'verify-owner') {
                    if ($account) {
                        throw new ApiProblem(409, 'ACCOUNT_EXISTS');
                    }
                    $password = $this->password($request);
                    $business = DB::table('businesses')->insertGetId(['name' => $payload['business_name']]);
                    $department = DB::table('departments')->insertGetId(['name' => 'Utama', 'business_id' => $business]);
                    DB::table('shift_types')->insert([
                        'department_id' => $department, 'name' => 'Pagi',
                        'start_local_time' => '08:00', 'end_local_time' => '16:00',
                    ]);
                    $this->createAccount($email, $payload['name'], $password, $business, 'ADMIN', null);
                } else {
                    $this->acceptInvite($request, $email, $payload, $account);
                }
                $links->where('email', $email)->delete();

                return $purpose === 'reset-password' ? $account->id : null;
            }, 3);
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) !== '23505') {
                throw $error;
            }
            throw new ApiProblem(409, 'ACCOUNT_EXISTS');
        }

        if ($resetAccount !== null && $request->hasSession() && $request->user()?->id === $resetAccount) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function acceptInvite(Request $request, string $email, array $payload, ?object $account): void
    {
        $department = DB::table('departments')->where('business_id', $payload['business_id'])
            ->where('active', true)->find($payload['department_id']);
        abort_unless($department, 404);
        if ($account) {
            if (! $account->active || $account->business_id !== $department->business_id ||
                $account->department_id !== $department->id || $account->role !== $payload['role']) {
                throw new ApiProblem(409, 'ACCOUNT_EXISTS');
            }
            abort_unless($request->user()?->id === $account->id, 401);

            return;
        }
        $data = $request->validate(['name' => 'required|string|max:160']);
        $this->createAccount($email, $data['name'], $this->password($request),
            $department->business_id, $payload['role'], $department->id);
    }

    private function createAccount(string $email, string $name, string $password, int $business, string $role, ?int $department): void
    {
        DB::table('users')->insert([
            'email' => $email, 'name' => $name, 'password_hash' => $password, 'business_id' => $business,
            'role' => $role, 'department_id' => $department, 'email_verified_at' => now(),
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function password(Request $request): string
    {
        $data = $request->validate(['password' => 'required|string|min:8|confirmed']);
        if (strlen($data['password']) > 72 || str_contains($data['password'], "\0")) {
            throw ValidationException::withMessages(['password' => 'Gunakan kata sandi hingga 72 byte tanpa karakter null.']);
        }

        return Hash::make($data['password']);
    }

    private function send(string $purpose, string $email, array $payload, ?string $businessName = null): void
    {
        [$subject, $context, $action, $hours] = match ($purpose) {
            'verify-owner' => [
                'Aktifkan akun Owner GILIRAN',
                'Kami menerima pendaftaran usaha '.$payload['business_name'].' di GILIRAN.',
                'Buka tautan ini untuk membuat kata sandi dan mengaktifkan akun Owner:',
                24,
            ],
            'accept-invite' => [
                'Terima undangan bergabung di GILIRAN',
                'Anda diundang bergabung dengan '.$businessName.'.',
                'Buka tautan ini untuk menerima undangan:',
                72,
            ],
            'reset-password' => [
                'Atur ulang kata sandi GILIRAN',
                'Kami menerima permintaan untuk mengatur ulang kata sandi akun Anda.',
                'Buka tautan ini untuk menetapkan kata sandi baru:',
                1,
            ],
        };
        $token = bin2hex(random_bytes(32));
        $link = rtrim(config('app.frontend_url'), '/').'/#'.$purpose.'?token='.$token;
        DB::transaction(function () use ($purpose, $email, $payload, $token, $hours) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
            DB::table('account_links')->where('email', $email)->where('expires_at', '<=', now())->delete();
            DB::table('account_links')->insert([
                'token_hash' => hash('sha256', $token), 'purpose' => $purpose, 'email' => $email,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'expires_at' => now()->addHours($hours),
            ]);
        });
        $body = implode("\n\n", [
            'Halo,',
            $context,
            $action."\n".$link,
            "Tautan ini berlaku {$hours} jam dan hanya bisa dipakai sekali.",
            'Jika Anda tidak mengharapkan email ini, abaikan pesan ini.',
            'GILIRAN',
        ]);
        Mail::raw($body,
            fn ($message) => $message->to($email)->subject($subject));
    }
}
