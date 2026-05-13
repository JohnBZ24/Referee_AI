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

    public function store(CreateSessionRequest $request): JsonResponse
    {
        $defaultPanelists = (array) config('referee_ai.default_panelists', []);
        $defaultReferee = (string) config('referee_ai.default_referee', '');

        $panelists = $request->input('model_set.panelists', $defaultPanelists);
        if (! is_array($panelists) || $panelists === []) {
            $panelists = $defaultPanelists;
        }

        $panelists = array_values(array_unique(array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $panelists), fn ($x) => is_string($x) && $x !== ''))));

        $referee = $request->input('referee_model');
        if (! is_string($referee) || trim($referee) === '') {
            $referee = $request->input('model_set.referee', $defaultReferee);
        }
        $referee = is_string($referee) ? trim($referee) : '';
        if ($referee === '') {
            $referee = $defaultReferee;
        }

        if ($referee !== '') {
            $panelists = array_values(array_filter($panelists, fn ($x) => $x !== $referee));
        }

        $session = AiSession::create([
            'user_id' => $request->user()?->id,
            'title' => $request->input('title', 'New Session'),
            'model_set' => [
                'panelists' => $panelists,
            ],
            'referee_model' => $referee,
        ]);

        return (new AiSessionResource($session->load('messages')))
            ->response()
            ->setStatusCode(201);
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
                $panelists = is_array($newPanelists) ? $newPanelists : [];
                $panelists = array_values(array_unique(array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $panelists), fn ($x) => is_string($x) && $x !== ''))));
                $ref = $request->has('referee_model') ? trim((string) $request->input('referee_model')) : trim((string) ($session->referee_model ?? ''));
                if ($ref !== '') {
                    $panelists = array_values(array_filter($panelists, fn ($x) => $x !== $ref));
                }
                $modelSet['panelists'] = $panelists;
            }
            $data['model_set'] = $modelSet;
        }

        if ($request->has('referee_model')) {
            $ref = trim((string) $request->input('referee_model'));
            $data['referee_model'] = $ref;

            if (isset($data['model_set']) && is_array($data['model_set'])) {
                $ms = $data['model_set'];
                $p = $ms['panelists'] ?? null;
                if (is_array($p) && $ref !== '') {
                    $ms['panelists'] = array_values(array_filter($p, fn ($x) => $x !== $ref));
                    $data['model_set'] = $ms;
                }
            }
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
