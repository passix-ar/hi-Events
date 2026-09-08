<?php

namespace HiEvents\Models;

use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeatingSection extends BaseModel
{
    use SoftDeletes;

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class)
            ->orderByRaw('LENGTH(row_label), row_label, seat_number');
    }

    protected function getCastMap(): array
    {
        return [
            SeatingSectionDomainObjectAbstract::AISLE_POSITIONS => 'array',
        ];
    }
}
