<?php

declare(strict_types=1);

namespace App\Models;

use App\Bench\Personas\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated person as stored. The JSON column is called `profile` rather than
 * `attributes` because Eloquent already owns that name internally.
 */
class Person extends Model
{
    protected $table = 'personas';

    protected $guarded = [];

    protected $casts = ['profile' => 'array', 'is_anchor' => 'boolean'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function persona(): Persona
    {
        return Persona::fromArray($this->profile);
    }

    /** Everything the analysis groups by: drawn attributes plus derived bands. */
    public function factors(): array
    {
        return $this->profile['factors'] ?? [];
    }
}
