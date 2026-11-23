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

        $result = Process::env(['MYSQL_PWD' => $db['password']])->timeout(600)->run(sprintf(
            'mysqldump --single-transaction --quick -h %s -u %s %s | gzip > %s',
            escapeshellarg($db['host']), escapeshellarg($db['username']), escapeshellarg($db['database']), escapeshellarg($file),
        ));

        if ($result->failed()) {
            $this->error($result->errorOutput());

            return self::FAILURE;
        }

        Storage::disk('backups')->putFileAs('mysql', $file, basename($file));
        unlink($file);

        return self::SUCCESS;
    }
}
