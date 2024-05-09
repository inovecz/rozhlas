<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contact = Contact::where('has_info_email_allowed', true)->orWhere('has_info_sms_allowed', true)->inRandomOrder()->first();

        $state = $this->faker->randomElement([
            'SENT', 'SENT', 'SENT', 'SENT', 'SENT', 'SENT', 'SENT', 'SENT', // 48%
            'RECEIVED', 'RECEIVED', 'RECEIVED', 'RECEIVED', 'RECEIVED', 'RECEIVED', 'RECEIVED', 'RECEIVED', // 48%
            'FAILED', // 4%
        ]);

        if ($contact->hasInfoEmailAllowed() && $contact->hasInfoSmsAllowed()) {
            $type = $this->faker->randomElement(['SMS', 'EMAIL']);
        } elseif ($contact->hasInfoEmailAllowed()) {
            $type = 'EMAIL';
        } else {
            $type = 'SMS';
        }

        return [
            'contact_id' => $contact->id,
            'type' => $type,
            'state' => $state,
            'content' => $this->faker->text,
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
