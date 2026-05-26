<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MercadopagoPreference extends BaseModel
{
    protected function getTimestampsEnabled(): bool
    {
        return true;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
