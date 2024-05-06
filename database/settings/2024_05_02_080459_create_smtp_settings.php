<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('smtp.host', 'smtp.seznam.cz');
        $this->migrator->add('smtp.port', 465);
        $this->migrator->add('smtp.encryption', 'TCP');
        $this->migrator->add('smtp.username', '');
        $this->migrator->add('smtp.password', '');
        $this->migrator->add('smtp.from_address', '');
        $this->migrator->add('smtp.from_name', '');
    }
};
