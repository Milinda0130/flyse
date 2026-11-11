<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentDirection;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', strtolower($credentials['email']))->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($credentials['device_name'] ?? 'web', ['*'], now()->addHours((int) config('sanctum.expiration_hours', 12)));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user),
            'abilities' => $this->abilities($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
            'abilities' => $this->abilities($request->user()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password']]);
        // Sign out other sessions after a password change.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    /** Ability flags the React app uses to show or hide controls (the API enforces them anyway). */
    private function abilities(User $user): array
    {
        $names = ['edit-records', 'view-passport', 'view-costs', 'view-reports', 'manage-payments',
            'delete-documents', 'manage-expenses', 'manage-users', 'manage-settings', 'view-audit', 'email-tickets', 'verify-documents', 'manage-airlines', 'delete-airlines'];
        $abilities = [];
        foreach ($names as $name) {
            $abilities[$name] = $user->can($name);
        }
        $abilities['record-supplier-payment'] = $user->can('record-payment', PaymentDirection::SupplierPayment);
        $abilities['record-receipt'] = $user->can('record-payment', PaymentDirection::CustomerReceipt);

        return $abilities;
    }
}
