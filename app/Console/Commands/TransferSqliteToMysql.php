<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TransferSqliteToMysql extends Command
{
    protected $signature = 'db:transfer-sqlite-to-mysql
        {--truncate : Truncate MySQL tables before inserting}
        {--batch=500 : Number of rows to insert per batch}
        {--include-migrations : Also copy the migrations table (default: skipped)}';

    protected $description = 'Copy all data from the legacy SQLite database to the configured MySQL database';

    public function handle(): int
    {
        $default = config('database.default');
        if ($default !== 'mysql' && $default !== 'mariadb') {
            $this->warn("Default connection is '$default'. Set DB_CONNECTION=mysql in .env before running.");
        }

        // Ensure we can connect
        try {
            DB::connection('sqlite_old')->getPdo();
        } catch (Throwable $e) {
            $this->error('Failed to connect to sqlite_old: '.$e->getMessage());
            return self::FAILURE;
        }
        try {
            DB::connection('mysql')->getPdo();
        } catch (Throwable $e) {
            $this->error('Failed to connect to mysql: '.$e->getMessage());
            return self::FAILURE;
        }

        // Make sure schema exists in MySQL
        $this->info('Running migrations on MySQL...');
        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->line(Artisan::output());
        } catch (Throwable $e) {
            $this->error('Migration failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $sqlite = DB::connection('sqlite_old');
        $mysql = DB::connection('mysql');

        // Gather table list from SQLite (exclude sqlite internal tables)
        $rows = $sqlite->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tables = array_map(fn($r) => $r->name, $rows);

        // Optionally skip migrations
        if (!$this->option('include-migrations')) {
            $tables = array_values(array_filter($tables, fn($t) => $t !== 'migrations'));
        }

        if (empty($tables)) {
            $this->warn('No tables found to transfer.');
            return self::SUCCESS;
        }

        $batchSize = (int) $this->option('batch');
        $truncate = (bool) $this->option('truncate');

        // Disable foreign key checks during transfer
        $mysql->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                $this->info("Transferring table: {$table}");

                // Ensure table exists in MySQL
                if (!Schema::connection('mysql')->hasTable($table)) {
                    $this->warn(" - Skipped: table '{$table}' does not exist in MySQL. Make sure migrations created it.");
                    continue;
                }

                if ($truncate) {
                    // Some tables may be referenced; FK checks are disabled above
                    $mysql->table($table)->truncate();
                }

                // Get column list from SQLite PRAGMA
                $cols = $sqlite->select("PRAGMA table_info('{$table}')");
                $columns = array_map(fn($c) => $c->name, $cols);
                if (empty($columns)) {
                    $this->warn(" - No columns found, skipping");
                    continue;
                }

                // Fetch all rows from SQLite in batches
                $total = (int) ($sqlite->table($table)->count());
                $this->line(" - Rows to copy: {$total}");

                $copied = 0;
                $offset = 0;
                while ($offset < $total) {
                    $chunk = $sqlite->table($table)->select($columns)->offset($offset)->limit($batchSize)->get();
                    if ($chunk->isEmpty()) {
                        break;
                    }
                    // Convert stdClass rows to associative arrays
                    $payload = [];
                    foreach ($chunk as $row) {
                        $arr = (array) $row;
                        // Normalize data for MySQL constraints
                        if ($table === 'users') {
                            if (isset($arr['status'])) {
                                $val = strtolower((string) $arr['status']);
                                if (!in_array($val, ['active','inactive'])) {
                                    $val = 'inactive';
                                }
                                $arr['status'] = $val;
                            }
                        }
                        $payload[] = $arr;
                    }
                    // Insert batch to MySQL
                    $mysql->table($table)->insert($payload);
                    $copied += count($payload);
                    $offset += $batchSize;
                    $this->line("   - Copied {$copied}/{$total}");
                }
            }
        } finally {
            $mysql->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info('Data transfer complete.');
        return self::SUCCESS;
    }
}