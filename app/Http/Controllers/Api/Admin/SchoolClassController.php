<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SchoolClassController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'academic_year' => $this->academicYearRules(required: false),
        ]);

        return SchoolClass::query()
            ->where('owner_id', $request->user()->id)
            ->when(
                isset($data['academic_year']),
                fn ($query) => $query->where('academic_year', $data['academic_year'])
            )
            ->withCount(['users', 'quizzes'])
            ->orderByDesc('academic_year')
            ->orderBy('name')
            ->get();
    }

    public function show(Request $request, SchoolClass $class)
    {
        $this->authorizeOwner($request, $class);

        return response()->json([
            'id' => $class->id,
            'name' => $class->name,
            'academic_year' => $class->academic_year,
            'code' => $class->code,
            'students' => $class->users()
                ->where('role', 'student')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request)
    {
        $academicYear = $request->filled('academic_year')
            ? trim((string) $request->input('academic_year'))
            : SchoolClass::currentAcademicYear();
        $request->merge(['academic_year' => $academicYear]);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('school_classes', 'name')->where(
                    fn ($query) => $query
                        ->where('owner_id', $request->user()->id)
                        ->where('academic_year', $academicYear)
                ),
            ],
            'academic_year' => $this->academicYearRules(),
            'code' => ['nullable', 'string', 'max:30', 'unique:school_classes,code'],
        ]);

        $data['owner_id'] = $request->user()->id;
        $data['code'] = $data['code'] ? strtoupper($data['code']) : $this->uniqueCode();

        return response()->json(SchoolClass::create($data), 201);
    }

    public function update(Request $request, SchoolClass $class)
    {
        $this->authorizeOwner($request, $class);

        $academicYear = $request->filled('academic_year')
            ? trim((string) $request->input('academic_year'))
            : $class->academic_year;
        $request->merge(['academic_year' => $academicYear]);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('school_classes', 'name')
                    ->where(
                        fn ($query) => $query
                            ->where('owner_id', $request->user()->id)
                            ->where('academic_year', $academicYear)
                    )
                    ->ignore($class->id),
            ],
            'academic_year' => $this->academicYearRules(),
            'code' => ['nullable', 'string', 'max:30', Rule::unique('school_classes', 'code')->ignore($class->id)],
        ]);

        if (! empty($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $class->update($data);

        return response()->json($class->fresh()->loadCount(['users', 'quizzes']));
    }

    public function destroy(Request $request, SchoolClass $class)
    {
        $this->authorizeOwner($request, $class);

        $class->delete();

        return response()->json(['message' => 'Classe supprimée.']);
    }

    private function authorizeOwner(Request $request, SchoolClass $class): void
    {
        if ((int) $class->owner_id !== (int) $request->user()->id) {
            abort(response()->json(['message' => 'Cette classe ne vous appartient pas.'], 403));
        }
    }

    private function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (SchoolClass::where('code', $code)->exists());

        return $code;
    }

    private function academicYearRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'string',
            'regex:/^\d{4}-\d{4}$/',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{4})$/', $value, $matches)) {
                    return;
                }

                if ((int) $matches[2] !== (int) $matches[1] + 1) {
                    $fail("L'année scolaire doit contenir deux années consécutives (ex. 2026-2027).");
                }
            },
        ];
    }
}
