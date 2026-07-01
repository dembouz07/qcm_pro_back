<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()
            ->withCount(['submissions', 'payments'])
            ->orderByDesc('created_at');

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(50);

        return response()->json($users);
    }

    public function block(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas vous bloquer vous-même.',
            ], 422);
        }

        if ($user->role === 'superadmin') {
            return response()->json([
                'message' => 'Impossible de bloquer un super-administrateur.',
            ], 422);
        }

        $user->update(['is_blocked' => true]);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Utilisateur bloqué.',
            'user' => $user,
        ]);
    }

    public function unblock(User $user)
    {
        $user->update(['is_blocked' => false]);

        return response()->json([
            'message' => 'Utilisateur débloqué.',
            'user' => $user,
        ]);
    }
}
