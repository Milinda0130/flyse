<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage-users');

        return UserResource::collection(User::orderBy('name')->get());
    }

    public function store(UserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['email'] = strtolower($data['email']);

        return (new UserResource(User::create($data)))->response()->setStatusCode(201);
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        $data = array_filter($request->validated(), fn ($v, $k) => $k !== 'password' || $v, ARRAY_FILTER_USE_BOTH);
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }

        $losingOwner = $user->isOwner() && (
            (isset($data['role']) && $data['role'] !== UserRole::Owner->value)
            || (array_key_exists('is_active', $data) && ! $data['is_active'])
        );
        if ($losingOwner && User::where('role', UserRole::Owner->value)->where('is_active', true)->count() <= 1) {
            abort(422, 'At least one active owner account is required.');
        }

        $user->update($data);
        if (! $user->is_active || isset($data['password'])) {
            $user->tokens()->delete();
        }

        return new UserResource($user);
    }
}
