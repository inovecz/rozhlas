<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->firstName();
        $surname = fake()->lastName();
        return [
            'name' => $name,
            'surname' => $surname,
            'position' => null,
            'email' => str($surname.'.'.$name)->lower()->ascii().'@'.fake()->safeEmailDomain(),
            'has_info_email_allowed' => fake()->boolean(),
            'phone' => fake()->e164PhoneNumber(),
            'has_info_sms_allowed' => fake()->boolean(),
        ];
    }
}
