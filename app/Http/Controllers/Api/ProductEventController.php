<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyEmployee;
use App\Models\ProductEvent;
use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductEventController extends Controller
{
    public function publicEvent(Request $request)
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(['demo_completed', 'demo_booking_clicked', 'pilot_interest_clicked', 'contact_clicked'])],
            'source' => ['nullable', Rule::in(['public', 'header', 'footer', 'landing', 'pricing', 'resources', 'solution', 'demo', 'pilot'])],
            'visitor_id' => ['required', 'uuid'],
        ]);

        $idempotencyKey = ProductEvent::idempotencyKey($data['event'], [$data['visitor_id']]);

        ProductEvent::record(
            $data['event'],
            metadata: ['source' => $data['source'] ?? 'public'],
            idempotencyKey: $idempotencyKey,
        );

        return response()->json(null, 204);
    }

    public function authenticatedEvent(Request $request)
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(['report_exported', 'assessment_started'])],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ]);

        if ($data['event'] === 'assessment_started' && empty($data['subject_id'])) {
            return response()->json([
                'message' => 'Le collaborateur concerné est requis.',
            ], 422);
        }

        if ($data['event'] === 'assessment_started') {
            abort_unless($request->user()->role === 'enterprise', 403);

            $companyId = $request->user()->company?->id;
            $ownsEmployee = $companyId && CompanyEmployee::query()
                ->whereKey($data['subject_id'])
                ->where('company_id', $companyId)
                ->exists();
            abort_unless($ownsEmployee, 422, 'Ce collaborateur est inconnu.');
        }

        if ($data['event'] === 'report_exported') {
            abort_unless($request->user()->role === 'admin', 403);

            if (!empty($data['subject_id'])) {
                $ownsQuiz = Quiz::query()
                    ->whereKey($data['subject_id'])
                    ->where('created_by', $request->user()->id)
                    ->exists();
                abort_unless($ownsQuiz, 422, 'Ce QCM est inconnu.');
            }
        }

        $subjectType = $data['event'] === 'assessment_started'
            ? 'employee'
            : (isset($data['subject_id']) ? 'quiz' : null);

        $idempotencyParts = [
            $request->user()->id,
            $subjectType ?: 'none',
            $data['subject_id'] ?? 0,
            now()->toDateString(),
            $data['format'] ?? 'none',
        ];

        ProductEvent::record(
            $data['event'],
            $request->user(),
            $subjectType,
            $data['subject_id'] ?? null,
            isset($data['format']) ? ['format' => $data['format']] : [],
            ProductEvent::idempotencyKey($data['event'], $idempotencyParts),
        );

        return response()->json(null, 204);
    }
}
