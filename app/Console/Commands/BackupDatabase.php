<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'crm:backup';

    protected $description = 'Create a private SQLite or MySQL database backup';

    public function handle(): int
    {
        $connection = DB::connection();
        $config = $connection->getConfig();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'])) {
            $this->error('Backup supports SQLite and MySQL/MariaDB.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private/backups');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/telecrm-'.now()->format('Ymd-His').'-'.Str::random(6).($driver === 'sqlite' ? '.sqlite' : '.sql');
        try {
            if ($driver === 'sqlite') {
                // VACUUM INTO produces a consistent snapshot, including WAL contents.
                $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($path));
            } else {
                $args = [config('crm.mysqldump_binary'), '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--result-file='.$path];
                if (! empty($config['unix_socket'])) {
                    $args[] = '--socket='.$config['unix_socket'];
                }
                $args[] = $config['database'];
                $process = new Process($args, null, ['MYSQL_PWD' => $config['password']]);
                $process->setTimeout(300)->run();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException('Database export failed.');
                }
            }
            chmod($path, 0600);
            $this->info('Backup created: '.$path);

            return self::SUCCESS;
        } catch (\Throwable) {
            if (is_file($path)) {
                unlink($path);
            }
            $this->error('Backup failed. Verify database access, private directory permissions, and the configured dump binary.');

            return self::FAILURE;
        }
    }
}
