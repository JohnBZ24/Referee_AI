<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSessionRequest;
use App\Http\Requests\UpdateSessionRequest;
use App\Http\Resources\AiSessionResource;
use App\Models\AiSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = AiSession::query()
            ->withCount('messages')
            ->latest()
            ->paginate(20);

        return AiSessionResource::collection($sessions);
    }

    public function store(CreateSessionRequest $request): AiSessionResource
    {
        $session = AiSession::create([
            'title' => $request->input('title', 'New Session'),
            'model_set' => $request->input('model_set', [
                'panelists' => config('ai.default_panelists'),
            ]),
            'referee_model' => $request->input('model_set.referee', config('ai.default_referee')),
        ]);

        return new AiSessionResource($session->load('messages'));
    }

    public function show(AiSession $session): AiSessionResource
    {
        return new AiSessionResource($session->load('messages'));
    }

    public function update(UpdateSessionRequest $request, AiSession $session): AiSessionResource
    {
        $data = [];

        if ($request->has('title')) {
            $data['title'] = $request->input('title');
        }

        if ($request->has('model_set')) {
            $modelSet = $session->model_set ?? [];
            $newPanelists = $request->input('model_set.panelists');
            if ($newPanelists !== null) {
                $modelSet['panelists'] = $newPanelists;
            }
            $data['model_set'] = $modelSet;
        }

        if ($request->has('referee_model')) {
            $data['referee_model'] = $request->input('referee_model');
        }

        if (! empty($data)) {
            $session->update($data);
        }

        return new AiSessionResource($session->fresh('messages'));
    }

    public function destroy(AiSession $session): JsonResponse
    {
        $session->delete();

        return response()->json(null, 204);
    }
}
