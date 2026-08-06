<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ProductEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()
            ->withCount([
                'submissions',
                'payments',
                'quizzes',
                'receivedSubmissions',
                'mindsetAssessments',
            ])
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
        DB::table('sessions')->where('user_id', $user->id)->delete();

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

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ], 422);
        }

        if ($user->isPlatformAdmin() || $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Impossible de supprimer un super-administrateur.',
            ], 422);
        }

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            try {
                ProductEvent::query()
                    ->where('actor_key', ProductEvent::actorKey($user->id))
                    ->delete();
            } catch (\Throwable) {
                // La suppression du compte reste prioritaire si la télémétrie est indisponible.
            }
            $user->delete();
        });

        return response()->json([
            'message' => 'Utilisateur supprimé définitivement.',
        ]);
    }
}
