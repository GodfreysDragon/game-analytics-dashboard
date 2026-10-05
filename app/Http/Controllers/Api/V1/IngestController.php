<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IngestEventsRequest;
use App\Ingestion\EventIngestor;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class IngestController extends Controller
{
    /**
     * Accept a batch of game events. Re-sending a batch is safe: events are deduplicated by uuid.
     */
    public function __invoke(IngestEventsRequest $request, EventIngestor $ingestor): JsonResponse
    {
        $result = $ingestor->ingest($request->game(), $request->validated('events'));

        return response()->json($result->toArray(), Response::HTTP_ACCEPTED);
    }
}
