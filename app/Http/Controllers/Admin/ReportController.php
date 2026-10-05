<?php

namespace App\Http\Controllers\Admin;

use App\Analytics\EventsReport;
use App\Analytics\OverviewReport;
use App\Analytics\RetentionReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function overview(ReportRequest $request, Game $game, OverviewReport $report): JsonResponse
    {
        return response()->json($report->build($game, $request->range()));
    }

    public function events(ReportRequest $request, Game $game, EventsReport $report): JsonResponse
    {
        $names = $request->validate([
            'names' => ['nullable', 'array', 'max:'.EventsReport::MAX_TRENDS],
            'names.*' => ['string', 'max:64'],
        ])['names'] ?? [];

        return response()->json($report->build($game, $request->range(), array_values($names)));
    }

    public function eventCatalog(Game $game, EventsReport $report): JsonResponse
    {
        return response()->json(['names' => $report->catalog($game)]);
    }

    public function retention(ReportRequest $request, Game $game, RetentionReport $report): JsonResponse
    {
        $maxDay = (int) ($request->validate([
            'max_day' => ['nullable', 'integer', Rule::in([7, 14, 30])],
        ])['max_day'] ?? 14);

        return response()->json($report->build($game, $request->range(), $maxDay, $request->platform()));
    }
}
