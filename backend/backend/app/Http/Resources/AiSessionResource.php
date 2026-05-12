<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiSessionResource extends JsonResource
{
    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function modelIdMaps(): array
    {
        $models = (array) config('referee_ai.models', []);

        $keyToId = [];
        $idToName = [];

        foreach ($models as $key => $cfg) {
            if (! is_array($cfg)) {
                continue;
            }

            $id = trim((string) ($cfg['model_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $keyToId[(string) $key] = $id;
            $idToName[$id] = trim((string) ($cfg['name'] ?? '')) ?: $id;
        }

        return [$keyToId, $idToName];
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        [$keyToId] = $this->modelIdMaps();

        $modelSet = is_array($this->model_set) ? $this->model_set : [];
        $panelists = $modelSet['panelists'] ?? null;
        if (is_array($panelists)) {
            $modelSet['panelists'] = array_values(array_map(function ($x) use ($keyToId) {
                $s = is_scalar($x) ? trim((string) $x) : '';
                if ($s === '') {
                    return '';
                }

                return $keyToId[$s] ?? $s;
            }, $panelists));
            $modelSet['panelists'] = array_values(array_filter($modelSet['panelists'], fn ($x) => is_string($x) && trim($x) !== ''));
        }

        $referee = is_scalar($this->referee_model) ? trim((string) $this->referee_model) : '';
        $referee = $referee !== '' ? ($keyToId[$referee] ?? $referee) : '';

        return [
            'id' => $this->id,
            'title' => $this->title,
            'model_set' => $modelSet,
            'referee_model' => $referee !== '' ? $referee : null,
            'message_count' => $this->whenCounted('messages'),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
