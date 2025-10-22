<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
        $this->clearApplicationCache(ignoreMissingTable: true);

        $this->ensureSqliteDatabaseExists();

        $this->components->info('Running database migrations...');
        $this->call('migrate', ['--force' => true]);

        $this->components->info('Seeding baseline data...');
        $this->call('db:seed', ['--force' => true]);

        if (config('app.env') === 'production') {
            $this->components->info('Building frontend assets (npm run build)...');
            $this->runNpmBuild();
        }

        $modbusPort = $this->promptForModbusPort();
        $modbusUnitId = $this->promptForModbusUnitId();

        $this->components->info('Caching configuration...');
        $this->cacheConfiguration();

        $this->components->info('Clearing application cache');
        $this->clearApplicationCache();

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

    private function ensureSqliteDatabaseExists(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $databasePath = config('database.connections.sqlite.database');
        if ($databasePath === null || $databasePath === '' || $databasePath === ':memory:') {
            return;
        }

        $isAbsolute = Str::startsWith($databasePath, ['/','\\']) || preg_match('/^[A-Za-z]:\\\\/', $databasePath) === 1;
        if (!$isAbsolute) {
            $databasePath = base_path($databasePath);
        }

        $directory = dirname($databasePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (!file_exists($databasePath)) {
            touch($databasePath);
            $this->components->info(sprintf('Created SQLite database at %s', $databasePath));
        }
    }

    private function clearApplicationCache(bool $ignoreMissingTable = false): void
    {
        try {
            $this->call('cache:clear');
        } catch (\Throwable $exception) {
            if ($ignoreMissingTable && $this->isMissingCacheTableError($exception)) {
                $this->components->warn('Skipping cache:clear – cache table not created yet.');
                return;
            }

            throw $exception;
        }
    }

    private function isMissingCacheTableError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'no such table') && str_contains($message, 'cache');
    }

    private function cacheConfiguration(): void
    {
        try {
            $this->call('config:cache');
        } catch (\Throwable $exception) {
            if ($exception instanceof ViteManifestNotFoundException) {
                $this->components->warn('Skipping config:cache – Vite manifest not found. Run npm run build before caching config.');
                return;
            }

            throw $exception;
        }
    }

    private function runNpmBuild(): void
    {
        if (!file_exists(base_path('package.json'))) {
            $this->components->warn('package.json not found, skipping npm build.');
            return;
        }

        $npmBinary = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';

        try {
            $process = new Process([$npmBinary, 'run', 'build'], base_path());
        } catch (\Throwable $exception) {
            $this->components->warn('Unable to create npm process: ' . $exception->getMessage());
            return;
        }

        $process->setTimeout(null);
        $process->run(function ($type, $buffer): void {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('npm run build failed: ' . $process->getErrorOutput());
        }
    }
}
