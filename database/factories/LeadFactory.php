<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Funnel;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // funnel_id/stage_id are NOT NULL — build a minimal funnel for
            // the lead's company when the test doesn't provide one.
            'funnel_id' => function (array $attributes) {
                $user = User::factory()->create(['company_id' => $attributes['company_id']]);

                return Funnel::create([
                    'company_id' => $attributes['company_id'],
                    'name' => 'Factory Funnel',
                    'is_active' => true,
                    'created_by' => $user->id,
                    'type' => 'sale',
                ])->id;
            },
            'stage_id' => function (array $attributes) {
                return Stage::create([
                    'funnel_id' => $attributes['funnel_id'],
                    'name' => 'Entry',
                    'order' => 1,
                    'type' => 'entry',
                ])->id;
            },
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '55119' . fake()->numerify('########'),
            'is_active' => true,
        ];
    }
}
