<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Probe extends Model
{
    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'persona_id');
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(Outcome::class);
    }
}
