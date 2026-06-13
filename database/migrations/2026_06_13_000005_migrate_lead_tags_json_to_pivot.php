<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $leads = DB::table('leads')->whereNotNull('tags')->get(['id', 'company_id', 'tags']);

        foreach ($leads as $lead) {
            $tags = json_decode($lead->tags, true);

            if (!is_array($tags) || empty($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                if (is_array($tag)) {
                    $name = $tag['name'] ?? null;
                } elseif (is_string($tag)) {
                    $name = $tag;
                } else {
                    $name = null;
                }

                $name = is_string($name) ? trim($name) : '';

                if ($name === '') {
                    continue;
                }

                $existingTag = DB::table('tags')
                    ->where('company_id', $lead->company_id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->first();

                $tagId = $existingTag->id ?? DB::table('tags')->insertGetId([
                    'company_id' => $lead->company_id,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('lead_tag')->insertOrIgnore([
                    'lead_id' => $lead->id,
                    'tag_id' => $tagId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data backfill only — not reversible.
    }
};
