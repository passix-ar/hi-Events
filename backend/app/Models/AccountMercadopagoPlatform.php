<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountMercadopagoPlatform extends BaseModel
{
    use SoftDeletes;

    protected function getCastMap(): array
    {
        return [
            'token_expires_at'    => 'datetime',
            'setup_completed_at'  => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
