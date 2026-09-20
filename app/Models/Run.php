<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scenario_keys' => 'array',
        'conditions' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function probes(): HasMany
    {
        return $this->hasMany(Probe::class);
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(Outcome::class);
    }
}
