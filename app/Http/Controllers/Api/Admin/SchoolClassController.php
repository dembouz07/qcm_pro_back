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
        return SchoolClass::query()
            ->where('owner_id', $request->user()->id)
            ->withCount(['users', 'quizzes'])
            ->orderBy('name')
            ->get();
    }

    public function show(Request $request, SchoolClass $class)
    {
        $this->authorizeOwner($request, $class);

        return response()->json([
            'id' => $class->id,
            'name' => $class->name,
            'code' => $class->code,
            'students' => $class->users()
                ->where('role', 'student')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:30', 'unique:school_classes,code'],
        ]);

        $data['owner_id'] = $request->user()->id;
        $data['code'] = $data['code'] ? strtoupper($data['code']) : $this->uniqueCode();

        return response()->json(SchoolClass::create($data), 201);
    }

    public function update(Request $request, SchoolClass $class)
    {
        $this->authorizeOwner($request, $class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('school_classes', 'code')->ignore($class->id)],
        ]);

        if (!empty($data['code'])) {
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
            abort(response()->json(['message' => "Cette classe ne vous appartient pas."], 403));
        }
    }

    private function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (SchoolClass::where('code', $code)->exists());

        return $code;
    }
}
