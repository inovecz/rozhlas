<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InstallApplication extends Command
{
    protected $signature = 'app:install {--force : Run without confirmation prompt}';

    protected $description = 'Run initial migrations, seeders and create an administrator account';

    public function handle(): int
    {
        if (!file_exists(base_path('.env'))) {
            $this->components->info('Creating .env from .env.example');
            copy(base_path('.env.example'), base_path('.env'));
        }

        if (!$this->option('force')) {
            $this->warn('This command will run database migrations and seeders.');
            if (!$this->confirm('Do you want to continue?')) {
                $this->info('Installation aborted.');
                return self::SUCCESS;
            }
        }

        $this->components->info('Clearing configuration cache');
        $this->call('config:clear');

        $this->components->info('Clearing application cache');
        $this->call('cache:clear');

        $this->components->info('Running database migrations...');
        $this->call('migrate', ['--force' => true]);

        $this->components->info('Seeding baseline data...');
        $this->call('db:seed', ['--force' => true]);

        $modbusPort = $this->promptForModbusPort();
        $modbusUnitId = $this->promptForModbusUnitId();

        $this->components->info('Caching configuration...');
        $this->call('config:cache');

        $this->components->info('Restarting queue workers');
        $this->call('queue:restart');

        $email = $this->promptForEmail();
        $password = $this->promptForPassword();

        $user = User::updateOrCreate(
            ['username' => $email],
            ['password' => $password],
        );

        $this->components->info('Administrator account ready:');
        $this->components->twoColumnDetail('Login (e-mail)', $email);
        $this->components->twoColumnDetail('Password', $password);
        $this->components->twoColumnDetail('Modbus port', $modbusPort);
        $this->components->twoColumnDetail('Modbus unit id', (string) $modbusUnitId);
        $this->components->twoColumnDetail('Start command', './run.sh (development)');

        $this->line('');
        $this->warn('Store these credentials securely. You can change the password after logging in.');

        return self::SUCCESS;
    }

    private function promptForEmail(): string
    {
        do {
            $email = (string) $this->ask('Administrator e-mail (used as login)');
            $validator = Validator::make(['email' => $email], [
                'email' => ['required', 'email'],
            ]);
            if ($validator->fails()) {
                $this->error('Please provide a valid e-mail address.');
                continue;
            }

            return strtolower($email);
        } while (true);
    }

    private function promptForPassword(): string
    {
        $password = (string) $this->secret('Administrator password (leave blank to generate a secure one)');
        if ($password === '') {
            $password = Str::random(16);
            $this->components->info('Generated random password for the administrator user.');
        }

        return $password;
    }

    private function promptForModbusPort(): string
    {
        $default = env('MODBUS_PORT', '/dev/tty.usbserial-AV0K3CPZ');
        $input = (string) $this->ask("Modbus port [{$default}]", $default);

        $value = $input !== '' ? $input : $default;
        $this->setEnvValue('MODBUS_PORT', $value);

        return $value;
    }

    private function promptForModbusUnitId(): int
    {
        $default = (int) env('MODBUS_UNIT_ID', 1);
        $input = (string) $this->ask("Modbus unit ID [{$default}]", (string) $default);
        $trimmed = trim($input);
        $value = $trimmed === '' ? $default : (int) $trimmed;
        if ($value <= 0) {
            $value = $default;
        }

        $this->setEnvValue('MODBUS_UNIT_ID', (string) $value);

        return $value;
    }

    private function setEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            return;
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            return;
        }

        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, $key . '=' . $value, $contents);
        } else {
            $contents = rtrim($contents, "\n") . "\n" . $key . '=' . $value . "\n";
        }

        file_put_contents($envPath, $contents);
    }
}
