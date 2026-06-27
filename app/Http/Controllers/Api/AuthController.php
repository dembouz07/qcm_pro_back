<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'class_code' => ['required', 'string', 'max:30'],
        ]);

        $class = SchoolClass::where('code', strtoupper(trim($data['class_code'])))->first();

        if (!$class) {
            throw ValidationException::withMessages([
                'class_code' => 'Code de classe invalide. Demandez le code à votre formateur.',
            ]);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'student',
            'school_class_id' => $class->id,
        ])->load('schoolClass');

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $user->load('schoolClass'),
        ]);
    }

    public function checkEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $exists = User::where('email', $data['email'])->exists();

        if (!$exists) {
            return response()->json([
                'exists' => false,
                'message' => 'Aucun compte ne correspond à cet email.',
            ], 404);
        }

        return response()->json([
            'exists' => true,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => 'Aucun compte ne correspond à cet email.',
            ]);
        }

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        // Révoque les anciens jetons par sécurité.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès. Vous pouvez vous connecter.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('schoolClass'));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Déconnexion réussie.']);
    }
}
