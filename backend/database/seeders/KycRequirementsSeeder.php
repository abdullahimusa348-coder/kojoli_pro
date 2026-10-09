<?php

namespace Database\Seeders;

use App\Models\KycRequirement;
use App\Support\Kyc\KycRequirementDefinitions;
use Illuminate\Database\Seeder;

/**
 * Creates the four KYC requirements that do not exist yet: all off, with no
 * customer types and no purposes. A requirement that already exists is never
 * touched, so staff changes survive every deploy.
 */
class KycRequirementsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (KycRequirementDefinitions::all() as $definition) {
            if (KycRequirement::where('key', $definition['key'])->exists()) {
                continue;
            }

            (new KycRequirement)->forceFill([
                'key' => $definition['key'],
                'type' => $definition['type']->value,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'is_enabled' => false,
                'user_types' => [],
                'purposes' => [],
                'position' => $definition['position'],
            ])->save();
        }
    }
}
