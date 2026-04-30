<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'round_id' => $this->round_id,
            'role' => $this->role,
            'model_name' => $this->model_name,
            'panel_position' => $this->panel_position,
            'content' => $this->content,
            'status' => $this->status,
            'tokens_used' => $this->tokens_used,
            'created_at' => $this->created_at,
        ];
    }
}
