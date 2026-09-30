<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $guarded = [];

    public function references(): BelongsToMany
    {
        return $this->belongsToMany(Reference::class)->withPivot('confidence');
    }
}
