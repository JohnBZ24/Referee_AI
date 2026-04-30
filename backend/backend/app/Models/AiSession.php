<?php

namespace App\Models;

use Database\Factories\AiSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'title', 'model_set', 'referee_model'])]
class AiSession extends Model
{
    /** @use HasFactory<AiSessionFactory> */
    use HasFactory;

    protected $table = 'ai_sessions';

    protected function casts(): array
    {
        return [
            'model_set' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'session_id');
    }
}
