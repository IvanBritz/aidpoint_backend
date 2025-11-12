<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        // SQLite does not support MODIFY/ALTER COLUMN and does not enforce decimal precision.
        // Make this a no-op on SQLite so migrations pass in local/dev environments.
        if ($driver === 'sqlite') {
            return;
        }

        // Use driver-specific SQL for reliable precision change.
        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                DB::statement('ALTER TABLE `financial_aid` MODIFY `longitude` DECIMAL(11,8) NULL');
                break;
            case 'pgsql':
                DB::statement('ALTER TABLE financial_aid ALTER COLUMN longitude TYPE numeric(11,8)');
                break;
            case 'sqlsrv':
                DB::statement('ALTER TABLE financial_aid ALTER COLUMN longitude decimal(11,8) NULL');
                break;
            default:
                // Fallback to Schema change for other drivers
                Schema::table('financial_aid', function (Blueprint $table) {
                    $table->decimal('longitude', 11, 8)->nullable()->change();
                });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                DB::statement('ALTER TABLE `financial_aid` MODIFY `longitude` DECIMAL(10,8) NULL');
                break;
            case 'pgsql':
                DB::statement('ALTER TABLE financial_aid ALTER COLUMN longitude TYPE numeric(10,8)');
                break;
            case 'sqlsrv':
                DB::statement('ALTER TABLE financial_aid ALTER COLUMN longitude decimal(10,8) NULL');
                break;
            default:
                Schema::table('financial_aid', function (Blueprint $table) {
                    $table->decimal('longitude', 10, 8)->nullable()->change();
                });
        }
    }
};
