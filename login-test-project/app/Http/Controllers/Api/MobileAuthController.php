<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        $state = (string) ($user->account_state ?? 'Active');
        if ($state !== 'Active') {
            return response()->json([
                'message' => 'Account is not available for mobile sign-in.',
                'account_state' => $state,
            ], 403);
        }

        $plainToken = Str::random(80);

        MobileApiToken::query()->create([
            'user_id' => $user->id,
            'name' => trim((string) ($data['device_name'] ?? 'mobile-client')) ?: 'mobile-client',
            'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now(),
        ]);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $plainToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('mobile_api_token');
        if ($token instanceof MobileApiToken) {
            $token->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'account_state' => (string) ($user->account_state ?? 'Active'),
        ];
    }
}
