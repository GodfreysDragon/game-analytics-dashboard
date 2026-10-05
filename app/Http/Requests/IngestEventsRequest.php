<?php

namespace App\Http\Requests;

use App\Models\Game;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class IngestEventsRequest extends FormRequest
{
    public const MAX_BATCH = 500;

    public const MAX_PROPERTIES_BYTES = 4096;

    public function authorize(): bool
    {
        return $this->attributes->get('game') instanceof Game;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $oldest = now()->subDays((int) config('analytics.max_event_age_days'))->toIso8601String();
        $newest = now()->addMinutes((int) config('analytics.max_clock_skew_minutes'))->toIso8601String();

        return [
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'events.*' => ['required', 'array:uuid,name,player_id,session_id,timestamp,value,properties,platform,country,app_version'],
            'events.*.uuid' => ['required', 'uuid'],
            'events.*.name' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'events.*.player_id' => ['required', 'string', 'max:128'],
            'events.*.session_id' => ['nullable', 'string', 'max:128'],
            'events.*.timestamp' => ['required', 'date', 'after_or_equal:'.$oldest, 'before_or_equal:'.$newest],
            'events.*.value' => ['nullable', 'numeric', 'between:-1000000,1000000'],
            'events.*.properties' => ['nullable', 'array', 'max:50', $this->propertiesFit(...)],
            'events.*.platform' => ['nullable', 'string', 'in:'.implode(',', config('analytics.platforms'))],
            'events.*.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'events.*.app_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.*.name.regex' => 'Event names must be snake_case, start with a letter and be at most 64 characters.',
            'events.*.timestamp.after_or_equal' => 'Events older than :date are outside the ingestion window.',
            'events.*.timestamp.before_or_equal' => 'The event timestamp is too far in the future; check the client clock.',
        ];
    }

    private function propertiesFit(string $attribute, mixed $value, Closure $fail): void
    {
        if (strlen((string) json_encode($value)) > self::MAX_PROPERTIES_BYTES) {
            $fail('Event properties must encode to at most '.self::MAX_PROPERTIES_BYTES.' bytes of JSON.');
        }
    }

    public function game(): Game
    {
        return $this->attributes->get('game');
    }
}
