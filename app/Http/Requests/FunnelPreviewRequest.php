<?php

namespace App\Http\Requests;

/**
 * An unsaved funnel definition evaluated over the usual report filters.
 */
class FunnelPreviewRequest extends ReportRequest
{
    public const MAX_STEPS = 8;

    public const MAX_WINDOW_MINUTES = 60 * 24 * 30;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), ...self::definitionRules()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function definitionRules(): array
    {
        return [
            'steps' => ['required', 'array', 'list', 'min:2', 'max:'.self::MAX_STEPS],
            'steps.*' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'window_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_WINDOW_MINUTES],
        ];
    }
}
