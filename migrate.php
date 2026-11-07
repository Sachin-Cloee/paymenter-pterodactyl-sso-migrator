#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Server as ExtensionServer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Output\OutputInterface;

$root = getcwd();

if (!is_file($root . '/artisan') || !is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run this script from the Paymenter root (the folder containing artisan).\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$cli = getopt('', ['help', 'mysqldump-path:']);

if (isset($cli['help'])) {
    echo <<<'TXT'
Paymenter PterodactylSSO migration wizard

Usage:
  cd /var/www/paymenter
  php migrate.php [--mysqldump-path=/usr/bin/mysqldump]

The script asks whether you have one upgraded panel or two separate panels,
which server row to update, the new URL/API keys, and the SSO shared secret.
It writes a full database dump before changing anything and then runs:

  php artisan pterodactyl-sso:migrate-v2 --server=<id> --force --apply

Requirements: Paymenter with the PterodactylSSO extension (migration command
installed), MySQL/MariaDB and mysqldump or mariadb-dump.

TXT;
    exit(0);
}

$dumpPath = $cli['mysqldump-path'] ?? null;

if (!is_string($dumpPath) || $dumpPath === '') {
    $dumpPath = null;
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Warning: run this as the Paymenter file owner (for example www-data), not root.\n");
}

exit((new MigrationWizard($root, $dumpPath))->run());

final class MigrationWizard
{
    private const REQUIRED_DUMP_TABLES = ['extensions', 'products', 'services', 'settings', 'properties'];
    private const ENCRYPTED_SETTINGS = ['api_key', 'admin_api_key', 'client_api_key', 'sso_secret'];

    private QuestionHelper $helper;
    private ArgvInput $input;
    private ConsoleOutput $output;
    private ?string $mysqldumpPath;
    private ?string $backupDir = null;

    public function __construct(private readonly string $root, ?string $mysqldumpPath)
    {
        $this->input = new ArgvInput();
        $this->output = new ConsoleOutput();
        $this->helper = new QuestionHelper();
        $this->mysqldumpPath = $mysqldumpPath;
    }

    public function run(): int
    {
        $this->line('');
        $this->line('<info>Paymenter → Pterodactyl 2.x migration</info>');
        $this->line('This updates Paymenter server rows and products. It does not upgrade the panel.');
        $this->line('A full database backup is created before any change is written.');
        $this->line('');

        if (!$this->confirm('Run the full migration / update now?', true)) {
            return 0;
        }

        if (!array_key_exists('pterodactyl-sso:migrate-v2', Artisan::all())) {
            $this->error('The pterodactyl-sso:migrate-v2 command is not registered. Install/update the PterodactylSSO Paymenter extension first.');

            return 1;
        }

        $rows = ExtensionServer::query()
            ->where('type', 'server')
            ->where('extension', 'PterodactylSSO')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->error('No PterodactylSSO server rows found. Create one in Paymenter admin → Servers first.');

            return 1;
        }

        $twoRows = false;
        $source = null;
        $target = null;

        if ($rows->count() >= 2) {
            $twoRows = $this->confirm('Do you have two separate Pterodactyl panels/servers (old 1.x and new 2.x)?', false);
        }

        if ($twoRows) {
            $source = $this->chooseRow($rows, 'Select the OLD 1.x server row (source)');
            $target = $this->chooseRow($rows->reject(fn (ExtensionServer $row) => $row->id === $source->id), 'Select the NEW 2.x server row (target)');

            $this->line('');
            $this->warn('The new panel must already contain the customer servers, matched by their external ID (the Paymenter service ID).');
            $this->warn('If a server is missing on the new panel, its service is skipped and SSO will not work for it.');

            if (!$this->confirm('Continue with the two-panel migration?', true)) {
                return 0;
            }
        } else {
            $target = $this->chooseRow($rows, 'Select the Paymenter server row to update');
        }

        $sourceVersion = (string) ($source?->settings->keyBy('key')->get('panel_version')?->value ?? 'v1');
        $targetVersion = (string) ($target->settings->keyBy('key')->get('panel_version')?->value ?? 'v1');

        if ($source !== null) {
            if ($sourceVersion === 'v2' && $targetVersion !== 'v2') {
                $this->error('The selected source row is already v2 and the target is not. Select the old 1.x row as source and the new 2.x row as target.');

                return 1;
            }

            if ($sourceVersion === 'v2') {
                $this->warn('The selected source row is already marked v2. Double-check that it is the old panel row.');
            }

            if ($targetVersion !== 'v2') {
                $this->warn('The target row is not marked v2 yet; the migration will set it to v2.');
            }
        }

        $name = $this->ask('Server name', $target->name) ?: $target->name;
        $keys = $this->askKeys($target);
        $secret = $this->askSecret($target);

        $currentSettings = $target->settings->keyBy('key');
        $effectiveHost = $keys['host'] ?? (string) ($currentSettings->get('host')?->value ?? '');
        $effectiveKey = $keys['api_key'] ?? (string) ($currentSettings->get('api_key')?->value ?? '');
        $effectiveSecret = $secret ?? (string) ($currentSettings->get('sso_secret')?->value ?? '');

        if (!$this->validHost($effectiveHost)) {
            $this->error('The Pterodactyl URL is not a valid HTTPS base URL. Nothing was changed.');

            return 1;
        }

        if (trim($effectiveKey) === '') {
            $this->error('The Application API key is missing. Nothing was changed.');

            return 1;
        }

        if (trim($effectiveSecret) === '') {
            $this->error('The SSO shared secret is missing. Nothing was changed.');

            return 1;
        }

        $moveProducts = false;
        $productsToMove = 0;

        if ($source !== null) {
            $productsToMove = Product::query()->where('server_id', $source->id)->count();

            if ($productsToMove > 0) {
                $moveProducts = $this->confirm(
                    "Move {$productsToMove} product(s) from the old row to the new row?",
                    true
                );
            } else {
                $this->warn('The old row has no products. Only the new row will be migrated.');
            }

            if (!$moveProducts && $productsToMove > 0) {
                $this->warn('Products will stay on the old row and will not be migrated.');
            }
        }

        $this->line('');
        $this->line('<info>Summary</info>');
        $this->line('  Target row:  #' . $target->id . ' ' . $name);
        $this->line('  Mode:        ' . ($source ? 'Two separate panels' : 'Single panel / row update'));

        if ($source !== null) {
            $this->line('  Source row:  #' . $source->id . ' ' . $source->name);
            $this->line('  Move products: ' . ($moveProducts ? 'yes (' . $productsToMove . ')' : 'no'));
        }

        $this->line('  Keys:        ' . ($keys === [] ? 'keep existing' : 'update ' . implode(', ', array_keys($keys))));
        $this->line('  SSO secret:  ' . ($secret === null ? 'keep existing' : 'update'));

        if (!$this->confirm('Apply this migration now? A full database backup is created first.', false)) {
            return 0;
        }

        try {
            $backup = $this->backupDatabase();
        } catch (Throwable $e) {
            $this->error('Backup failed; nothing was changed: ' . $e->getMessage());

            return 1;
        }

        $this->line('');
        $this->line('<info>Backup verified:</info> ' . $backup['dump']);

        try {
            DB::beginTransaction();

            if ($source !== null && $moveProducts) {
                Product::query()
                    ->where('server_id', $source->id)
                    ->get()
                    ->each(function (Product $product) use ($target): void {
                        $product->server_id = $target->id;
                        $product->save();
                    });
            }

            if ($name !== $target->name) {
                $target->name = $name;
                $target->save();
            }

            foreach ($keys as $key => $value) {
                $this->writeSetting($target, $key, $value);
            }

            if ($secret !== null) {
                $this->writeSetting($target, 'sso_secret', $secret);
            }

            $dryRunExit = Artisan::call('pterodactyl-sso:migrate-v2', [
                '--server' => [(string) $target->id],
                '--force' => true,
            ]);
            $dryRunOutput = Artisan::output();

            if ($dryRunExit !== 0) {
                DB::rollBack();
                $this->output->write($dryRunOutput, false, OutputInterface::OUTPUT_RAW);
                $this->error('Preflight failed; the server row changes were rolled back.');
                $this->line('Backup: ' . $backup['dump']);

                return 1;
            }

            DB::commit();
            $this->output->write($dryRunOutput, false, OutputInterface::OUTPUT_RAW);
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Could not apply the server row changes: ' . $e->getMessage());
            $this->line('Backup: ' . $backup['dump']);

            return 1;
        }

        $this->line('Running the migration command...');
        $this->line('');

        $exitCode = Artisan::call('pterodactyl-sso:migrate-v2', [
            '--server' => [(string) $target->id],
            '--force' => true,
            '--apply' => true,
        ]);

        $this->output->write(Artisan::output(), false, OutputInterface::OUTPUT_RAW);

        if ($exitCode !== 0) {
            $this->error('The migration command failed.');
            $this->line('Pre-migration database backup: ' . $backup['dump']);
            $this->line('The server row changes made by this wizard are still in place.');
            $this->line('Restore the dump above, or fix the reported issue and run the migration command again.');

            return 1;
        }

        $this->line('');
        $this->line('<info>Done.</info>');
        $this->line('Pre-migration backup: ' . $backup['dump']);
        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. php artisan optimize:clear');
        $this->line('  2. php artisan queue:restart');
        $this->line('  3. Open each product and re-select the Tag and Egg fields.');
        $this->line('  4. Test Auto Login / Open Panel for one customer.');

        return 0;
    }

    private function chooseRow($rows, string $prompt): ExtensionServer
    {
        $labels = $rows->map(function (ExtensionServer $row): string {
            $version = (string) ($row->settings->keyBy('key')->get('panel_version')?->value ?? 'v1');

            return '#' . $row->id . ' — ' . $row->name . ' (' . $version . ')';
        })->values()->all();

        $question = new ChoiceQuestion($prompt, $labels, 0);
        $answer = $this->helper->ask($this->input, $this->output, $question);
        $index = array_search($answer, $labels, true);

        return $rows->values()[(int) $index];
    }

    /**
     * @return array<string, string>
     */
    private function askKeys(ExtensionServer $row): array
    {
        $settings = $row->settings->keyBy('key');
        $currentHost = trim((string) ($settings->get('host')?->value ?? ''));

        if ($this->confirm('Are the panel URL and API keys already correct in "' . $row->name . '"?', true)) {
            return [];
        }

        $changes = [];

        do {
            $host = $this->ask('Pterodactyl URL (https://panel.example.com)', $currentHost);

            if ($this->validHost($host)) {
                break;
            }

            $this->error('Enter a valid HTTPS base URL with no path, for example https://panel.example.com.');
        } while (true);

        if ($host !== $currentHost) {
            $changes['host'] = $host;
        }

        $applicationKey = $this->secretQuestion('Application API key (leave blank to keep current)');

        if ($applicationKey !== '') {
            $changes['api_key'] = $applicationKey;
        }

        $adminKey = $this->secretQuestion('Admin API key (2.x, optional; leave blank to keep current)');

        if ($adminKey !== '') {
            $changes['admin_api_key'] = $adminKey;
        }

        $clientKey = $this->secretQuestion('Client API key (leave blank to keep current)');

        if ($clientKey !== '') {
            $changes['client_api_key'] = $clientKey;
        }

        return $changes;
    }

    private function askSecret(ExtensionServer $row): ?string
    {
        $current = trim((string) ($row->settings->keyBy('key')->get('sso_secret')?->value ?? ''));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $secret = $this->secretQuestion('Shared secret from the panel extension (leave blank to keep current)');

            if ($secret !== '' || $current !== '') {
                return $secret === '' ? null : $secret;
            }

            $this->error('The row has no stored SSO secret; enter the panel extension shared secret.');
        }

        return null;
    }

    private function writeSetting(ExtensionServer $row, string $key, string $value): void
    {
        $row->settings()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => 'string',
                'encrypted' => in_array($key, self::ENCRYPTED_SETTINGS, true),
            ]
        );
    }

    /**
     * @return array{dir: string, dump: string}
     */
    private function backupDatabase(): array
    {
        $root = $this->root . '/storage/app/backups';
        $this->ensureDirectory($root);

        $directory = $root . '/pterodactyl-sso-wizard-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));

        if (!@mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create backup directory: ' . $directory);
        }

        @chmod($directory, 0700);
        $previousUmask = umask(0077);

        try {
            $dump = $directory . '/database.sql';
            $this->dumpDatabase($dump);
        } catch (Throwable $e) {
            @unlink($directory . '/database.sql');
            @rmdir($directory);

            throw $e;
        } finally {
            umask($previousUmask);
        }

        return ['dir' => $directory, 'dump' => $dump];
    }

    private function dumpDatabase(string $destination): void
    {
        $connectionName = (string) config('database.default');
        $config = (array) config('database.connections.' . $connectionName);
        $url = trim((string) ($config['url'] ?? ''));

        if ($url !== '') {
            $parsed = parse_url($url);

            if ($parsed === false) {
                throw new RuntimeException('The database URL is invalid.');
            }

            $config['host'] = $config['host'] ?? ($parsed['host'] ?? null);
            $config['port'] = $config['port'] ?? ($parsed['port'] ?? null);
            $config['username'] = $config['username'] ?? (isset($parsed['user']) ? rawurldecode($parsed['user']) : null);
            $config['password'] = $config['password'] ?? (isset($parsed['pass']) ? rawurldecode($parsed['pass']) : null);
            $config['database'] = $config['database'] ?? (isset($parsed['path']) ? ltrim($parsed['path'], '/') : null);
        }

        $driver = (string) ($config['driver'] ?? '');

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Only MySQL/MariaDB is supported for the backup.');
        }

        $database = trim((string) ($config['database'] ?? ''));

        if ($database === '') {
            throw new RuntimeException('The database name is missing.');
        }

        $dumpBinary = $this->resolveDumpBinary();
        $cnf = tempnam(sys_get_temp_dir(), 'pterosso-cnf-');

        if ($cnf === false) {
            throw new RuntimeException('Unable to create a temporary MySQL option file.');
        }

        @chmod($cnf, 0600);

        try {
            $lines = ['[client]', 'host=' . $this->cnfValue((string) ($config['host'] ?? '127.0.0.1'))];

            if (!empty($config['port'])) {
                $lines[] = 'port=' . (int) $config['port'];
            }

            if (!empty($config['unix_socket'])) {
                $lines[] = 'socket=' . $this->cnfValue((string) $config['unix_socket']);
            }

            $lines[] = 'user=' . $this->cnfValue((string) ($config['username'] ?? ''));
            $lines[] = 'password=' . $this->cnfValue((string) ($config['password'] ?? ''));

            if (file_put_contents($cnf, implode("\n", $lines) . "\n") === false) {
                throw new RuntimeException('Unable to write the temporary MySQL option file.');
            }

            $process = proc_open([
                $dumpBinary,
                '--defaults-extra-file=' . $cnf,
                '--single-transaction',
                '--quick',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--databases',
                $database,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $destination, 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start mysqldump.');
            }

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new RuntimeException('mysqldump failed: ' . substr((string) $stderr, 0, 400));
            }
        } catch (Throwable $e) {
            @unlink($destination);

            throw $e;
        } finally {
            @unlink($cnf);
        }

        if (!is_file($destination) || filesize($destination) < 1024) {
            @unlink($destination);

            throw new RuntimeException('The database dump is missing or suspiciously small.');
        }

        @chmod($destination, 0600);
        $this->verifyDump($destination);
    }

    private function resolveDumpBinary(): string
    {
        if ($this->mysqldumpPath !== null) {
            if (!is_executable($this->mysqldumpPath)) {
                throw new RuntimeException('--mysqldump-path is not executable: ' . $this->mysqldumpPath);
            }

            return $this->mysqldumpPath;
        }

        $found = trim((string) @shell_exec('command -v mysqldump 2>/dev/null'));

        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mariadb-dump', '/usr/local/bin/mariadb-dump'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('mysqldump/mariadb-dump was not found. Use --mysqldump-path=/path/to/binary.');
    }

    private function verifyDump(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The database dump could not be opened.');
        }

        $tables = [];
        $completed = false;

        while (($line = fgets($handle)) !== false) {
            if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/', $line, $match)) {
                $tables[$match[1]] = true;
            }

            if (str_contains($line, '-- Dump completed')) {
                $completed = true;
            }
        }

        fclose($handle);

        $missing = array_values(array_filter(self::REQUIRED_DUMP_TABLES, fn (string $table) => !isset($tables[$table])));

        if ($missing !== []) {
            @unlink($path);

            throw new RuntimeException('The dump is incomplete; missing tables: ' . implode(', ', $missing) . '.');
        }

        if (!$completed) {
            @unlink($path);

            throw new RuntimeException('The dump did not finish cleanly.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0700, true)) {
                throw new RuntimeException('Unable to create directory: ' . $path);
            }

            @chmod($path, 0700);
        }

        if (!is_writable($path)) {
            throw new RuntimeException('Backup directory is not writable: ' . $path);
        }
    }

    private function cnfValue(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $value) . '"';
    }

    private function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($host);

        if ($parts === false || !isset($parts['host']) || strcasecmp((string) ($parts['scheme'] ?? ''), 'https') !== 0) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');

        return $path === '' || $path === '/';
    }

    private function ask(string $question, ?string $default = null): string
    {
        $q = new Question($question, $default);

        return trim((string) $this->helper->ask($this->input, $this->output, $q));
    }

    private function confirm(string $question, bool $default = false): bool
    {
        $q = new ConfirmationQuestion($question . ' [' . ($default ? 'Y/n' : 'y/N') . '] ', $default);

        return (bool) $this->helper->ask($this->input, $this->output, $q);
    }

    private function secretQuestion(string $question): string
    {
        $q = new Question($question);
        $q->setHidden(true);
        $q->setHiddenFallback(true);

        return trim((string) $this->helper->ask($this->input, $this->output, $q));
    }

    private function line(string $message = ''): void
    {
        $this->output->writeln($message);
    }

    private function error(string $message): void
    {
        $this->output->writeln('<error>' . $message . '</error>');
    }

    private function warn(string $message): void
    {
        $this->output->writeln('<comment>' . $message . '</comment>');
    }
}
