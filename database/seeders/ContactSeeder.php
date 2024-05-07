<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\ContactGroup;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(ContactGroupSeeder::class);

        $contactGroups = ContactGroup::all();

        Contact::factory(50)->create()->each(function (Contact $contact) use ($contactGroups) {
            $randomGroups = $contactGroups->random(random_int(1, 3));

            // Attach the selected groups to the contact
            $contact->contactGroups()->attach($randomGroups->pluck('id')->toArray());
        });
    }
}
