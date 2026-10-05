<?php

namespace App\Http\Controllers\Admin;

use App\Analytics\FunnelReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FunnelPreviewRequest;
use App\Http\Requests\FunnelRequest;
use App\Http\Requests\ReportRequest;
use App\Http\Resources\FunnelResource;
use App\Models\Funnel;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FunnelController extends Controller
{
    public function index(Game $game): AnonymousResourceCollection
    {
        return FunnelResource::collection($game->funnels()->orderBy('name')->orderBy('id')->get());
    }

    public function store(FunnelRequest $request, Game $game): JsonResponse
    {
        $funnel = $game->funnels()->create($request->validated());

        return (new FunnelResource($funnel))->response()->setStatusCode(201);
    }

    public function update(FunnelRequest $request, Game $game, Funnel $funnel): FunnelResource
    {
        $funnel->update($request->validated());

        return new FunnelResource($funnel);
    }

    public function destroy(Game $game, Funnel $funnel): Response
    {
        $funnel->delete();

        return response()->noContent();
    }

    public function report(ReportRequest $request, Game $game, Funnel $funnel, FunnelReport $report): JsonResponse
    {
        return response()->json([
            'funnel' => new FunnelResource($funnel),
            ...$report->build($game, $funnel->steps, $funnel->window_minutes, $request->range(), $request->platform()),
        ]);
    }

    /**
     * Evaluate an unsaved definition so the builder can show results while it is being edited.
     */
    public function preview(FunnelPreviewRequest $request, Game $game, FunnelReport $report): JsonResponse
    {
        return response()->json($report->build(
            $game,
            $request->validated('steps'),
            (int) $request->validated('window_minutes'),
            $request->range(),
            $request->platform(),
        ));
    }
}
