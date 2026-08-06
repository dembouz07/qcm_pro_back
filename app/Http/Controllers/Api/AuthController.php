<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ProductEvent;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $this->requireBrowserSession($request, allowMobileRegistration: true);
        $this->normalizeEmail($request);
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

        $this->recordRegistration($user);

        return $this->authenticatedResponse($request, $user, 201);
    }

    /** Inscription d'un formateur sur la formule gratuite. */
    public function registerAdmin(Request $request)
    {
        $this->requireCommercialLaunch();
        $this->requireBrowserSession($request, allowMobileRegistration: true);
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        // Le premier mois donne accès à toutes les fonctionnalités formateur.
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
            'subscription_plan' => User::PLAN_PREMIUM,
            'subscription_status' => 'active',
            'subscribed_until' => now()->addMonth(),
        ]);

        $this->recordRegistration($user);

        return $this->authenticatedResponse($request, $user, 201);
    }

    public function registerEnterprise(Request $request)
    {
        $this->requireCommercialLaunch();
        $this->requireBrowserSession($request, allowMobileRegistration: true);
        $this->normalizeEmail($request);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'industry' => ['nullable', 'string', 'max:190'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => 'enterprise',
                'subscription_plan' => User::PLAN_ENTERPRISE,
                'subscription_status' => 'inactive',
                'subscribed_until' => null,
            ]);

            Company::create([
                'owner_id' => $user->id,
                'name' => $data['company_name'],
                'industry' => $data['industry'] ?? null,
            ]);

            return $user->load('company');
        });

        $this->recordRegistration($user);

        return $this->authenticatedResponse($request, $user, 201);
    }

    public function login(Request $request)
    {
        $this->requireBrowserSession($request);
        $this->normalizeEmail($request);
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

        if ($user->is_blocked) {
            throw ValidationException::withMessages([
                'email' => 'Votre compte a été bloqué. Contactez l\'administrateur.',
            ]);
        }

        return $this->authenticatedResponse($request, $user);
    }

    public function mobileToken(Request $request)
    {
        $this->normalizeEmail($request);
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password) || $user->is_blocked) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        return response()->json([
            'user' => $user->loadMissing(['schoolClass', 'company']),
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Réponse volontairement identique, que le compte existe ou non.
        try {
            PasswordBroker::sendResetLink(['email' => strtolower(trim($data['email']))]);
        } catch (\Throwable $exception) {
            Log::error('Échec d’envoi du lien de réinitialisation.', [
                'exception' => $exception::class,
            ]);
        }

        return response()->json([
            'message' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d’être envoyé.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $status = PasswordBroker::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(str()->random(60));
                $user->save();

                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'Ce lien est invalide ou expiré. Demandez un nouveau lien de réinitialisation.',
            ]);
        }

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès. Vous pouvez vous connecter.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load(['schoolClass', 'company']));
    }

    /**
     * Met à jour le nom et l'email du compte connecté.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $this->normalizeEmail($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['nullable', 'string'],
        ]);

        $emailChanged = $data['email'] !== $user->email;

        if ($emailChanged && (!filled($data['current_password'] ?? null) || !Hash::check($data['current_password'], $user->password))) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est requis pour modifier l’adresse email.',
            ]);
        }

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        if ($emailChanged) {
            $user->tokens()->delete();
            $this->deleteOtherSessions($request, $user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        return response()->json([
            'message' => 'Profil mis à jour.',
            'user' => $user->fresh()->load(['schoolClass', 'company']),
        ]);
    }

    /**
     * Change le mot de passe du compte connecté.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        // Révoque les appareils et les autres sessions sans interrompre la session courante.
        $user->tokens()->delete();
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($currentSessionId, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->delete();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }

    public function exportData(Request $request)
    {
        $credentials = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user()->load(['schoolClass', 'company']);

        if (!Hash::check($credentials['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        $roleData = match ($user->role) {
            'student' => $this->studentExport($user),
            'admin' => $this->trainerExport($user),
            'enterprise' => $this->enterpriseExport($user),
            default => [],
        };

        Log::notice('Export de données demandé.', [
            'user_id' => $user->id,
            'role' => $user->role,
        ]);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'subscription_plan' => $user->subscription_plan,
                'subscription_status' => $user->subscription_status,
                'subscribed_until' => $user->subscribed_until,
                'class' => $user->schoolClass ? [
                    'id' => $user->schoolClass->id,
                    'name' => $user->schoolClass->name,
                    'academic_year' => $user->schoolClass->academic_year,
                ] : null,
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'name' => $user->company->name,
                    'industry' => $user->company->industry,
                ] : null,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'payments' => $user->payments()
                ->get(['id', 'provider', 'amount', 'currency', 'status', 'created_at']),
            'usage_events' => $this->usageEventsExport($user),
            'data' => $roleData,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function logout(Request $request)
    {
        if ($request->bearerToken()) {
            $accessToken = $request->user()?->currentAccessToken();
            if ($accessToken && method_exists($accessToken, 'delete')) {
                $accessToken->delete();
            }
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    private function authenticatedResponse(Request $request, User $user, int $status = 200)
    {
        $user->loadMissing(['schoolClass', 'company']);
        $payload = ['user' => $user];

        if (!$request->hasSession()) {
            if ($request->header('X-Client-Type') === 'mobile') {
                $deviceName = trim((string) $request->header('X-Device-Name', 'Check Performance mobile'));
                $payload['token'] = $user->createToken(mb_substr($deviceName ?: 'Check Performance mobile', 0, 120))->plainTextToken;

                return response()->json($payload, $status);
            }

            return response()->json([
                'message' => 'La connexion web exige une session de même marque. Utilisez la route mobile dédiée pour un jeton d’appareil.',
            ], 409);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json($payload, $status);
    }

    private function normalizeEmail(Request $request): void
    {
        if ($request->has('email')) {
            $request->merge([
                'email' => strtolower(trim((string) $request->input('email'))),
            ]);
        }
    }

    private function requireBrowserSession(Request $request, bool $allowMobileRegistration = false): void
    {
        if ($allowMobileRegistration && $request->header('X-Client-Type') === 'mobile') {
            return;
        }

        abort_unless(
            $request->hasSession(),
            409,
            'La connexion web exige une session sur des sous-domaines de la même marque.',
        );
    }

    private function requireCommercialLaunch(): void
    {
        abort_unless(
            config('commercial.launch_enabled'),
            503,
            'Les inscriptions commerciales sont fermées pendant la phase pilote. Demandez un accès accompagné.',
        );
    }

    private function deleteOtherSessions(Request $request, User $user): void
    {
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($currentSessionId, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->delete();
    }

    private function recordRegistration(User $user): void
    {
        ProductEvent::record(
            'account_registered',
            $user,
            idempotencyKey: ProductEvent::idempotencyKey('account_registered', [$user->id]),
        );
    }

    private function usageEventsExport(User $user): array
    {
        try {
            return ProductEvent::query()
                ->where('actor_key', ProductEvent::actorKey($user->id))
                ->orderBy('occurred_at')
                ->get(['event', 'account_role', 'occurred_at'])
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function studentExport(User $user): array
    {
        return [
            'results' => $user->submissions()
                ->with('quiz:id,title')
                ->get(['id', 'user_id', 'quiz_id', 'score', 'total_points', 'percentage', 'note_sur_20', 'submitted_at'])
                ->map(fn ($submission) => [
                    'id' => $submission->id,
                    'quiz' => $submission->quiz ? [
                        'id' => $submission->quiz->id,
                        'title' => $submission->quiz->title,
                    ] : null,
                    'score' => $submission->score,
                    'total_points' => $submission->total_points,
                    'percentage' => $submission->percentage,
                    'note_sur_20' => $submission->note_sur_20,
                    'submitted_at' => $submission->submitted_at,
                ]),
        ];
    }

    private function trainerExport(User $user): array
    {
        $classes = SchoolClass::query()
            ->where('owner_id', $user->id)
            ->with(['quizzes' => fn ($query) => $query->select([
                'id', 'school_class_id', 'title', 'description', 'type', 'starts_at', 'ends_at', 'is_published', 'created_at',
            ])])
            ->get(['id', 'owner_id', 'name', 'academic_year', 'created_at']);

        return [
            'classes' => $classes->map(fn ($class) => [
                'id' => $class->id,
                'name' => $class->name,
                'academic_year' => $class->academic_year,
                'created_at' => $class->created_at,
                'quizzes' => $class->quizzes->map(fn ($quiz) => [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'description' => $quiz->description,
                    'type' => $quiz->type,
                    'starts_at' => $quiz->starts_at,
                    'ends_at' => $quiz->ends_at,
                    'is_published' => $quiz->is_published,
                    'created_at' => $quiz->created_at,
                ]),
            ]),
        ];
    }

    private function enterpriseExport(User $user): array
    {
        $company = $user->company()->with('employees.assessments.responses')->first();

        if (!$company) {
            return ['company' => null];
        }

        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'industry' => $company->industry,
                'employees' => $company->employees->map(fn ($employee) => [
                    'id' => $employee->id,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'email' => $employee->email,
                    'job_title' => $employee->job_title,
                    'department' => $employee->department,
                    'seniority_months' => $employee->seniority_months,
                    'assessments' => $employee->assessments->map(fn ($assessment) => [
                        'id' => $assessment->id,
                        'type' => $assessment->type,
                        'methodology_version' => $assessment->methodology_version,
                        'assessed_at' => $assessment->assessed_at,
                        'total_score' => $assessment->total_score,
                        'level' => $assessment->level,
                        'action_items' => $assessment->action_items,
                        'support_needs' => $assessment->support_needs,
                        'next_review_at' => $assessment->next_review_at,
                        'responses' => $assessment->responses->map(fn ($response) => [
                            'question_key' => $response->question_key,
                            'pillar' => $response->pillar,
                            'score' => $response->score,
                            'observation' => $response->observation,
                        ]),
                    ]),
                ]),
            ],
        ];
    }
}
