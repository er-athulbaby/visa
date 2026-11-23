<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Dump MySQL, gzip it, upload to the backups disk';

    public function handle(): int
    {
        $db = config('database.connections.mysql');
        $file = storage_path('app/backup-'.now()->format('Ymd-His').'.sql.gz');

        try {
            touch($file);
            chmod($file, 0600); // plaintext PII dump: owner-only before any data lands in it

            $result = Process::env(['MYSQL_PWD' => $db['password']])->timeout(600)->run(['bash', '-o', 'pipefail', '-c', sprintf(
                'mysqldump --single-transaction --quick -h %s -u %s %s | gzip > %s',
                escapeshellarg($db['host']), escapeshellarg($db['username']), escapeshellarg($db['database']), escapeshellarg($file),
            )]);

            if ($result->failed() || ! is_file($file) || filesize($file) <= 100) {
                $this->error($result->errorOutput() ?: 'Backup dump is missing or truncated.');

                return self::FAILURE;
            }

            Storage::disk('backups')->putFileAs('mysql', $file, basename($file));

            return self::SUCCESS;
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
