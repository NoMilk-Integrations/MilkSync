<?php

namespace RootAccessPlease\MilkSync\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SyncDatabaseCommand extends Command
{
    protected $signature = 'milk:sync 
                            {--include-only= : Comma-separated list of tables to include only}
                            {--force : Skip confirmation prompts}
                            {--dry-run : Show what would be done without executing}';

    protected $description = 'Sync remote database to local development environment';

    private array $includeTables = [];
    private array $remoteConfig = [];
    private string $backupPath;
    private string $connectionName;

    public function handle(): int
    {
        $this->info('🔄 MilkSync Tool');
        $this->line('');

        try {
            $this->loadConfiguration();
            $this->validateEnvironment();

            if ($this->option('dry-run')) {
                $this->showDryRun();

                return 0;
            }

            $this->confirmSync();
            $this->createBackup();
            $this->syncDatabase();
            $this->cleanupOldBackups();

            $this->info('✅ Database sync completed successfully!');

        } catch (\Exception $e) {
            $this->error('❌ Sync failed: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function loadConfiguration(): void
    {
        $this->connectionName = $this->option('config');

        $connections = config('milksync.connections', []);

        if (! isset($connections[$this->connectionName])) {
            throw new \Exception("Connection '{$this->connectionName}' not found in config");
        }

        $this->remoteConfig = $connections[$this->connectionName];

        $required = ['host', 'database', 'username', 'password'];

        foreach ($required as $key) {
            if (empty($this->remoteConfig[$key])) {
                throw new \Exception("Missing remote database configuration: {$key}");
            }
        }

        if ($this->option('include-only')) {
            $this->includeTables = array_map('trim', explode(',', $this->option('include-only')));
        }

        $this->backupPath = config('milksync.backup.path', storage_path('app/db-backups'));

        if (! is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    private function validateEnvironment(): void
    {
        if (app()->environment('production')) {
            throw new \Exception('This command cannot be run in production environment!');
        }

        $result = Process::run('which mysqldump');

        if (! $result->successful()) {
            throw new \Exception('mysqldump is not available. Please install MySQL client tools.');
        }

        $this->info("🔍 Testing {$this->connectionName} database connection...");

        if (! $this->testDatabaseConnection($this->remoteConfig)) {
            throw new \Exception("Cannot connect to {$this->connectionName} database");
        }

        $this->info("✅ {$this->connectionName} database connection successful");
    }

    private function testDatabaseConnection(array $config): bool
    {
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

            $pdo = new \PDO($dsn, $config['username'], $config['password'], [
                \PDO::ATTR_TIMEOUT => 10,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            return true;
        } catch (\PDOException $e) {
            $this->error("Connection failed: " . $e->getMessage());
            return false;
        }
    }

    private function showDryRun(): void
    {
        $this->info('🔍 Dry Run Mode - Showing what would be executed:');
        $this->line('');

        $localDb = config('database.connections.mysql.database');
        $remoteDb = $this->remoteConfig['database'];

        $this->table(['Action', 'Details'], [
            ['Source Database', "{$this->connectionName}: {$remoteDb}"],
            ['Target Database', "local: {$localDb}"],
            ['Include Only', implode(', ', $this->includeTables) ?: 'All tables'],
        ]);
    }

    private function confirmSync(): void
    {
        if ($this->option('force')) {
            return;
        }

        $localDb = config('database.connections.mysql.database');
        $remoteDb = $this->remoteConfig['database'];

        $this->warn("⚠️  This will replace your local database '{$localDb}' with data from {$this->connectionName} '{$remoteDb}'");

        if (!$this->confirm('Do you want to continue?')) {
            $this->info('Sync cancelled.');

            exit(0);
        }
    }

    private function createBackup(): void
    {
        $this->info('📦 Creating backup of local database...');

        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $localConfig = config('database.connections.mysql');
        $backupFile = "{$this->backupPath}/local_backup_{$timestamp}.sql";

        $command = $this->buildMysqlDumpCommand($localConfig, $backupFile);
        $result = Process::run($command);

        if ($result->successful()) {
            $this->info("✅ Backup created: " . basename($backupFile));
        } else {
            $this->warn("⚠️  Backup failed: " . $result->errorOutput());
            if (! $this->confirm('Continue without backup?')) {
                throw new \Exception('Sync cancelled due to backup failure');
            }
        }
    }

    private function syncDatabase(): void
    {
        $this->info('🔄 Starting database sync...');

        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $dumpFile = "{$this->backupPath}/{$this->connectionName}_dump_{$timestamp}.sql";

        try {
            $this->line("📥 Dumping {$this->connectionName} database...");
            $this->dumpRemoteDatabase($dumpFile);

            $this->line('📤 Importing to local database...');
            $this->importToLocalDatabase($dumpFile);

        } finally {
            if (file_exists($dumpFile)) {
                unlink($dumpFile);
            }
        }
    }

    private function dumpRemoteDatabase(string $dumpFile): void
    {
        $command = $this->buildMysqlDumpCommand($this->remoteConfig, $dumpFile, true);
        $result = Process::run($command);

        if (! $result->successful()) {
            throw new \Exception("Failed to dump {$this->connectionName} database: " . $result->errorOutput());
        }
    }

    private function buildMysqlDumpCommand(array $config, string $outputFile, bool $includeTableFilter = false): string
    {
        $options = config('milksync.mysql.dump_options', []);
        $optionsStr = implode(' ', $options);

        return sprintf(
            'mysqldump -h%s -P%s -u%s -p%s %s %s %s > %s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['username'],
            $config['password'],
            $optionsStr,
            $includeTableFilter ? $this->buildTableFilter($config['database']) : '',
            $config['database'],
            $outputFile
        );
    }

    private function buildTableFilter(string $database): string
    {
        $filter = '';

        if (! empty($this->includeTables)) {
            return $filter;
        }

        $excludedTables = config('milksync.default_excludes', []);

        foreach ($excludedTables as $table) {
            $filter .= " --ignore-table={$database}.{$table}";
        }

        return $filter;
    }

    private function importToLocalDatabase(string $dumpFile): void
    {
        $localConfig = config('database.connections.mysql');
        $importOptions = config('milksync.mysql.import_options', []);
        $optionsStr = implode(' ', $importOptions);

        $command = sprintf(
            'mysql -h%s -P%s -u%s -p%s %s %s < %s',
            $localConfig['host'],
            $localConfig['port'],
            $localConfig['username'],
            $localConfig['password'],
            $optionsStr,
            $localConfig['database'],
            $dumpFile
        );

        $result = Process::run($command);

        if (!$result->successful()) {
            throw new \Exception('Failed to import database: ' . $result->errorOutput());
        }
    }

    private function cleanupOldBackups(): void
    {
        $keepBackups = config('milksync.backup.keep_backups', 3);

        if (! $keepBackups <= 0) {
            return;
        }

        $backupFiles = glob($this->backupPath . '/local_backup_*.sql');

        if (count($backupFiles) > $keepBackups) {
            usort($backupFiles, fn($a, $b) => filemtime($a) - filemtime($b));

            $filesToDelete = array_slice($backupFiles, 0, count($backupFiles) - $keepBackups);

            foreach ($filesToDelete as $file) {
                unlink($file);
            }

            $this->line("🧹 Cleaned up " . count($filesToDelete) . " old backup(s)");
        }
    }
}