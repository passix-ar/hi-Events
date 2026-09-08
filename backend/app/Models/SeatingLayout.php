<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatingLayout extends BaseModel
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected function getCastMap(): array
    {
        return [];
    }
}
