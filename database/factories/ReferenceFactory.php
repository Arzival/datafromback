<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reference>
 */
class ReferenceFactory extends Factory
{
    protected $model = Reference::class;

    public function definition(): array
    {
        $hash = hash('sha256', $this->faker->unique()->uuid());

        return [
            'filename' => $this->faker->word().'.png',
            'path' => "images/{$hash}.png",
            'thumb_path' => "thumbs/{$hash}.webp",
            'hash' => $hash,
            'mime' => 'image/png',
            'bytes' => $this->faker->numberBetween(50_000, 2_000_000),
            'width' => 1440,
            'height' => 900,
            'status' => Reference::STATUS_PENDING,
            'source_url' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => Reference::STATUS_READY,
            'title' => $this->faker->sentence(4),
            'summary' => $this->faker->paragraph(),
            'provider_id' => 'claude-cli',
            'model' => 'sonnet',
        ]);
    }
}
