<?php

namespace OrisIntel\MigrationSnapshot\Commands;

use Symfony\Component\Console\Output\OutputInterface;

final class MigrateLoadCommand extends \Illuminate\Console\Command
{
    protected $signature = 'migrate:load
        {--database= : The database connection to use}';

    protected $description = 'Load current database schema/structure from plain-text SQL file.';

    public function handle()
    {
        $exit_code = null;

        if (
            'production' === app()->environment()
            && ! $this->confirm('Are you sure you want to load DB structure?')
        ) {
            return;
        }

        $path = database_path() . MigrateDumpCommand::SCHEMA_MIGRATIONS_PATH;
        if (! file_exists($path)) {
            throw new \InvalidArgumentException(
                'Schema-migrations path not found, run `migrate:dump` first.'
            );
        }

        $database = $this->option('database') ?: \DB::getDefaultConnection();
        \DB::setDefaultConnection($database);
        $db_config = \DB::getConfig();

        // Delegate to driver-specific restore/load CLI command.
        // ASSUMES: Restore utilities for DBMS installed and in path.
        // CONSIDER: Accepting options for underlying restore utilities from CLI.
        // CONSIDER: Option to restore to console Stdout instead.
        // CONSIDER: Dropping whole DB, recreating; or at least dropping
        // existing tables with `$db_config['prefix']`; to avoid residuals.
        switch($db_config['driver']) {
        case 'mysql':
            $exit_code = self::mysqlLoad($path, $db_config, $this->getOutput()->getVerbosity());
            break;
        default:
            throw new \InvalidArgumentException(
                'Unsupported DB driver ' . var_export($db_config['driver'], 1)
            );
        }

        if (0 !== $exit_code) {
            exit($exit_code);
        }
    }

    private static function mysqlLoad(string $path, array $db_config, int $verbosity = null) : int
    {
        // CONSIDER: Supporting unix_socket.
        // CONSIDER: Directly sending queries via Eloquent (requires parsing SQL
        // or intermediate format).
        // CONSIDER: Capturing Stderr and outputting with `$this->error()`.

        // CONSIDER: Making input file an option which can override default.
        // CONSIDER: Avoiding shell specifics like `cat` and piping using
        // `file_get_contents` or similar.
        $command = 'cat ' . escapeshellarg($path)
            . ' | mysql --no-beep'
            . ' --host=' . escapeshellarg($db_config['host'])
            . ' --port=' . escapeshellarg($db_config['port'])
            . ' --user=' . escapeshellarg($db_config['username'])
            . ' --password=' . escapeshellarg($db_config['password'])
            . ' --database=' . escapeshellarg($db_config['database']);
        switch($verbosity) {
        case OutputInterface::VERBOSITY_QUIET:
            $command .= ' -q';
            break;
        case OutputInterface::VERBOSITY_NORMAL:
            $command .= ' -v';
            break;
        case OutputInterface::VERBOSITY_VERBOSE:
            $command .= ' -v -v';
            break;
        case OutputInterface::VERBOSITY_VERY_VERBOSE:
            // Fall through:
        case OutputInterface::VERBOSITY_DEBUG:
            $command .= ' -v -v -v';
            break;
        }

        passthru($command, $exit_code);

        return $exit_code;
    }
}