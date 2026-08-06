<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'idempotency_key',
        'event',
        'actor_key',
        'account_role',
        'subject_type',
        'subject_key',
        'environment',
        'is_internal',
        'schema_version',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'is_internal' => 'boolean',
            'schema_version' => 'integer',
        ];
    }

    public static function record(
        string $event,
        ?User $user = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): void {
        try {
            self::firstOrCreate([
                'idempotency_key' => $idempotencyKey ?: (string) Str::uuid(),
            ], [
                'event' => $event,
                'actor_key' => $user ? self::actorKey($user->id) : null,
                'account_role' => $user?->role,
                'subject_type' => $subjectType,
                'subject_key' => ($subjectType && $subjectId) ? self::subjectKey($subjectType, $subjectId) : null,
                'environment' => (string) config('analytics.metric_environment', app()->environment()),
                'is_internal' => $user ? self::isInternalUser($user) : false,
                'schema_version' => (int) config('analytics.schema_version', 1),
                'metadata' => $metadata ?: null,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // La télémétrie ne doit jamais bloquer une action métier.
            Log::warning('Événement produit non enregistré.', [
                'event' => $event,
                'exception' => $exception::class,
            ]);
        }
    }

    public static function actorKey(int $userId): string
    {
        return hash_hmac('sha256', "user:{$userId}", self::hashKey());
    }

    public static function subjectKey(string $type, int $id): string
    {
        return hash_hmac('sha256', "{$type}:{$id}", self::hashKey());
    }

    public static function idempotencyKey(string $event, array $parts): string
    {
        $key = (string) config('analytics.hash_key');
        $payload = implode('|', array_map('strval', $parts));
        $digest = $key === ''
            ? hash('sha256', $payload)
            : hash_hmac('sha256', $payload, $key);

        return mb_substr($event, 0, 50) . ':' . $digest;
    }

    private static function hashKey(): string
    {
        $key = (string) config('analytics.hash_key');

        if ($key === '') {
            throw new \RuntimeException('ANALYTICS_HASH_KEY ou APP_KEY doit être configuré.');
        }

        return $key;
    }

    public static function isInternalUser(User $user): bool
    {
        return in_array(strtolower($user->email), config('analytics.internal_emails', []), true);
    }
}
