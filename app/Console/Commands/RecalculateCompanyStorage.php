<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Storage\CompanyStorage;
use Illuminate\Console\Command;

/**
 * Rebuild every company's storage counter from the file ledger.
 *
 * `companies.storage_used_bytes` is denormalised so the plan-usage endpoint
 * does not have to sum a table of every file on each dashboard load. That
 * makes it capable of drifting — a new upload path added without a
 * `CompanyStorage::record()` call, or a process killed mid-transaction.
 *
 * This is the way back. It is safe to run at any time and changes nothing but
 * the counter; the ledger is the source of truth.
 */
class RecalculateCompanyStorage extends Command
{
    protected $signature = 'storage:recalculate {--company= : Only this company id}';

    protected $description = "Rebuild companies' storage usage counters from the file ledger";

    public function handle(): int
    {
        $query = Company::withTrashed()->orderBy('id');

        if ($id = $this->option('company')) {
            $query->whereKey($id);
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            $this->warn('No companies matched.');
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($companies as $company) {
            $before = (int) ($company->storage_used_bytes ?? 0);
            $after  = CompanyStorage::recalculate($company);

            $rows[] = [
                $company->id,
                $company->name,
                $this->human($before),
                $this->human($after),
                $before === $after ? '—' : $this->human($after - $before),
            ];
        }

        $this->table(['ID', 'Company', 'Before', 'After', 'Drift'], $rows);
        $this->info('Recalculated ' . count($rows) . ' compan' . (count($rows) === 1 ? 'y' : 'ies') . '.');

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        $sign  = $bytes < 0 ? '-' : '';
        $bytes = abs($bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $power = $bytes > 0 ? (int) min(floor(log($bytes, 1024)), count($units) - 1) : 0;

        return $sign . round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }
}
