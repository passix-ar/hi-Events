<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'account_mercadopago_platforms';

    private const COLUMNS = ['access_token', 'refresh_token'];

    public function up(): void
    {
        DB::table(self::TABLE)
            ->select(array_merge(['id'], self::COLUMNS))
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $update = [];

                    foreach (self::COLUMNS as $column) {
                        $value = $row->{$column};

                        if ($value === null || $value === '' || $this->isEncrypted($value)) {
                            continue;
                        }

                        $update[$column] = Crypt::encryptString($value);
                    }

                    if ($update !== []) {
                        DB::table(self::TABLE)->where('id', $row->id)->update($update);
                    }
                }
            });
    }

    public function down(): void
    {
        // No-op: tokens are not reverted to plaintext. Removing the model cast is the rollback.
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
