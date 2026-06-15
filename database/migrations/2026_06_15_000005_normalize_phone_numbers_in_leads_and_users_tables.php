<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeTable('leads');
        $this->normalizeTable('users');
    }

    public function down(): void
    {
        // Original formatting (e.g. "+" prefixes, dashes, spaces) cannot be
        // reliably restored once stripped, so this migration is irreversible.
    }

    private function normalizeTable(string $table): void
    {
        DB::table($table)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $digitsOnly = preg_replace('/\D/', '', $row->phone);

                    if ($digitsOnly !== $row->phone) {
                        DB::table($table)->where('id', $row->id)->update(['phone' => $digitsOnly]);
                    }
                }
            });
    }
};
