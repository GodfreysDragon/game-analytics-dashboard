<?php

namespace App\Http\Requests;

use App\Analytics\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared query-string filters for every dashboard report: ?from=Y-m-d&to=Y-m-d&platform=ios
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'platform' => ['nullable', 'string', 'in:'.implode(',', config('analytics.platforms'))],
            'compare' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->range()->days() > DateRange::MAX_DAYS) {
                    $validator->errors()->add('from', 'Reports cover at most '.DateRange::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function range(int $defaultDays = 30): DateRange
    {
        return DateRange::fromStrings($this->input('from'), $this->input('to'), $defaultDays);
    }

    public function platform(): ?string
    {
        return $this->input('platform') ?: null;
    }

    /**
     * Whether to add the equally long period just before the range, for comparison.
     */
    public function wantsComparison(): bool
    {
        return $this->boolean('compare');
    }
}
