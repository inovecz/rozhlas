<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('jsvv.locationGroupId', null);
        $this->migrator->add('jsvv.allowSms', false);
        $this->migrator->add('jsvv.smsContacts', []);
        $this->migrator->add('jsvv.smsMessage', null);
        $this->migrator->add('jsvv.allowEmail', false);
        $this->migrator->add('jsvv.emailContacts', []);
        $this->migrator->add('jsvv.emailSubject', null);
        $this->migrator->add('jsvv.emailMessage', null);
    }
};
