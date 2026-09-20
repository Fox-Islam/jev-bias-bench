<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Outcome extends Model
{
    protected $guarded = [];

    protected $casts = ['raw' => 'array', 'value' => 'float', 'confidence' => 'float'];

    public function probe(): BelongsTo
    {
        return $this->belongsTo(Probe::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'persona_id');
    }
}
