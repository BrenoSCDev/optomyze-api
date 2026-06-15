<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Record;
use App\Services\RecordTranslator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateTransactionsToRecordsCommand extends Command
{
    protected $signature = 'records:migrate-transactions {--dry-run : Preview the migration without writing any data}';

    protected $description = 'Migrate lead_transactions and sale_transactions into the unified records table';

    public function handle(): int
    {
        $leadCount = DB::table('lead_transactions')->count();
        $saleCount = DB::table('sale_transactions')->count();

        $this->info("Source: {$leadCount} lead_transactions, {$saleCount} sale_transactions.");

        if ($this->option('dry-run')) {
            $this->info('Dry run - no data will be written.');
            return self::SUCCESS;
        }

        $languages = Company::query()->pluck('language', 'id');

        DB::transaction(function () use ($languages) {
            $this->migrate('lead_transactions', Record::RECORDABLE_LEAD, 'lead_id', $languages);
            $this->migrate('sale_transactions', Record::RECORDABLE_SALE, 'sale_id', $languages);
        });

        $newCount = DB::table('records')->count();
        $this->info("Destination: {$newCount} records.");

        return self::SUCCESS;
    }

    private function migrate(string $table, string $recordableType, string $foreignKey, $languages): void
    {
        $migrated = 0;

        DB::table($table)->chunkById(500, function ($rows) use (&$migrated, $recordableType, $foreignKey, $languages, $table) {
            $records = $rows->map(function ($row) use ($recordableType, $foreignKey, $languages, $table) {
                $language = $languages[$row->company_id] ?? 'EN';
                $title = RecordTranslator::titleFor($language, $recordableType, $row->type, $row->action);

                return [
                    'company_id' => $row->company_id,
                    'recordable_type' => $recordableType,
                    'recordable_id' => $row->{$foreignKey},
                    'user_id' => $row->user_id,
                    'type' => $row->type,
                    'action' => $row->action,
                    'title' => $title,
                    'description' => $row->description,
                    'metadata' => json_encode(['migrated_from' => $table, 'legacy_id' => $row->id]),
                    'source' => $row->source,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            })->all();

            DB::table('records')->insert($records);
            $migrated += count($records);
        });

        $this->info("Migrated {$migrated} rows from {$table}.");
    }
}
