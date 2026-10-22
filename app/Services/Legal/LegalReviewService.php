<?php

namespace App\Services\Legal;

class LegalReviewService
{
    public static function warning(string $code, string $severity, string $messageAr, ?string $field = null, array $legalReferences = []): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message_ar' => $messageAr,
            'field' => $field,
            'legal_references' => $legalReferences,
        ];
    }

    public static function mergeWarnings(array $warnings): array
    {
        $merged = [];
        $seen = [];

        foreach ($warnings as $warning) {
            $key = $warning['code'] ?? ($warning['field'] ?? 'warning').'-'.($warning['message_ar'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $warning;
        }

        return $merged;
    }

    public static function finalizeStatus(array $warnings, string $defaultStatus = 'valid'): string
    {
        if (empty($warnings)) {
            return $defaultStatus;
        }

        foreach ($warnings as $warning) {
            $severity = $warning['severity'] ?? $warning['level'] ?? null;
            if ($severity === 'blocked') {
                return 'blocked';
            }
        }

        foreach ($warnings as $warning) {
            $severity = $warning['severity'] ?? $warning['level'] ?? null;
            if ($severity === 'requires_legal_review') {
                return 'requires_legal_review';
            }
        }

        return 'warning';
    }
}
