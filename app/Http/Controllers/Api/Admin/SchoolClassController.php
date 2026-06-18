<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolClassController extends Controller
{
    public function publicIndex()
    {
        return SchoolClass::query()
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();
    }

    public function index()
    {
        return SchoolClass::query()
            ->withCount(['users', 'quizzes'])
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:school_classes,name'],
            'code' => ['nullable', 'string', 'max:30', 'unique:school_classes,code'],
        ]);

        return response()->json(SchoolClass::create($data), 201);
    }

    public function update(Request $request, SchoolClass $class)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('school_classes', 'name')->ignore($class->id)],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('school_classes', 'code')->ignore($class->id)],
        ]);

        $class->update($data);

        return response()->json($class->fresh()->loadCount(['users', 'quizzes']));
    }

    public function destroy(SchoolClass $class)
    {
        $class->delete();

        return response()->json(['message' => 'Classe supprimée.']);
    }
}
