<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('jsvv.allowAlarmSms', false);
        $this->migrator->add('jsvv.alarmSmsContacts', []);
        $this->migrator->add('jsvv.alarmSmsMessage', null);
    }
};
