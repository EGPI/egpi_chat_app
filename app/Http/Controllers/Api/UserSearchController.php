<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = trim($validated['q']);
        $perPage = $validated['per_page'] ?? 20;

        $users = User::query()
            ->where('id', '!=', $request->user()->id)
            ->where('is_active', true)
            ->where(function ($builder) use ($query) {
                $builder
                    ->where('name', 'ILIKE', '%' . $query . '%')
                    ->orWhere('email', 'ILIKE', '%' . $query . '%');
            })
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }
}