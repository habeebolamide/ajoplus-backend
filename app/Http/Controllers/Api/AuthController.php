<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return response()->json(['user' => $user, ...$this->issueTokens($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The supplied credentials are incorrect.']);
        }

        return response()->json(['user' => $user, ...$this->issueTokens($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => ['nullable', 'string']]);
        $refreshToken = PersonalAccessToken::findToken($request->input('refresh_token', ''));
        if ($refreshToken && $refreshToken->tokenable_id === $request->user()->id && $refreshToken->can('refresh')) {
            $refreshToken->delete();
        }
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => ['required', 'string']]);
        $tokens = DB::transaction(function () use ($request): ?array {
            $candidate = PersonalAccessToken::findToken($request->input('refresh_token'));
            if (! $candidate) {
                return null;
            }
            $token = PersonalAccessToken::whereKey($candidate->id)->lockForUpdate()->first();
            if (! $token || ! $token->can('refresh') || $token->expires_at?->isPast()) {
                return null;
            }
            $user = User::find($token->tokenable_id);
            if (! $user) {
                return null;
            }
            $token->delete();

            return $this->issueTokens($user);
        });
        abort_unless($tokens, 401);

        return response()->json($tokens);
    }

    private function issueTokens(User $user): array
    {
        return [
            'token' => $user->createToken('mobile-access', ['api'], now()->addMinutes(15))->plainTextToken,
            'refresh_token' => $user->createToken('mobile-refresh', ['refresh'], now()->addDays(30))->plainTextToken,
        ];
    }
}
