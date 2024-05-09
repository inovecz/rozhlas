<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('twoWayComm.type', 'DIGITAL');
        $this->migrator->add('twoWayComm.spam', false);
        $this->migrator->add('twoWayComm.nestStatusAutoUpdate', false);
        $this->migrator->add('twoWayComm.nestFirstReadTime', '00:00');
        $this->migrator->add('twoWayComm.nestNextReadInterval', 360);
        $this->migrator->add('twoWayComm.sensorStatusAutoUpdate', false);
        $this->migrator->add('twoWayComm.sensorFirstReadTime', '00:00');
        $this->migrator->add('twoWayComm.sensorNextReadInterval', 120);
    }
};
