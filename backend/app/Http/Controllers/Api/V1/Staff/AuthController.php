<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $email = strtolower(trim($credentials['email']));

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->first();

        if (
            $user === null ||
            ! Hash::check($credentials['password'], $user->password)
        ) {
            return ApiResponse::error(
                $request,
                'INVALID_CREDENTIALS',
                'Invalid email or password.',
                401,
            );
        }

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        $token = $user->createToken(
            $credentials['device_name'] ?? 'staff-device',
            ['staff'],
            now()->addDays(30),
        );

        return ApiResponse::success($request, [
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken
                ->expires_at
                ?->toIso8601String(),
            'user' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant_id' => (string) $user->tenant_id,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        return ApiResponse::success($request, [
            'user' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant_id' => (string) $user->tenant_id,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('sanctum')
            ->currentAccessToken()
            ?->delete();

        return ApiResponse::success($request, [
            'logged_out' => true,
        ]);
    }
}
