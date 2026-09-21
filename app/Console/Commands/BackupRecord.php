<?php
// MARKER-BACKUP-RECORD

namespace App\Console\Commands;

use App\Models\SystemHealth;
use App\Support\JobFailureReporter;
use Illuminate\Console\Command;

/**
 * Called by /usr/local/bin/intake-backup.sh (source: deploy/intake-backup.sh)
 * at the end of every run, as www-data. Uses the app's own DB connection, so
 * the backup script needs no MySQL credentials to report.
 *
 *   php artisan backup:record ok --file=intake-db-….sql.gz --bytes=185187738 --started=1758420001
 *   php artisan backup:record failed --reason="mysqldump failed" --started=1758420001
 *
 * A failure also goes to Issues (and the alert email) via JobFailureReporter.
 */
class BackupRecord extends Command
{
    protected $signature = 'backup:record
        {status : ok or failed}
        {--file= : name of the uploaded dump}
        {--bytes= : size of the uploaded dump in bytes}
        {--started= : epoch seconds when the backup script started}
        {--reason= : what failed}';

    protected $description = 'Record the result of the nightly backup for the master admin dashboard';

    public function handle(): int
    {
        $status   = strtolower(trim((string) $this->argument('status')));
        $started  = (int) $this->option('started');
        $duration = $started > 0 ? max(0, now()->timestamp - $started) : null;

        if ($status === 'ok') {
            SystemHealth::write('last_backup', [
                'at'           => now()->toIso8601String(),
                'bytes'        => (int) $this->option('bytes'),
                'duration_sec' => $duration,
                'file'         => (string) $this->option('file'),
            ]);
            $this->line('recorded: backup ok');
            return self::SUCCESS;
        }

        if ($status === 'failed') {
            $reason = trim((string) $this->option('reason')) ?: 'intake-backup.sh reported a failure without a reason';
            SystemHealth::write('last_backup_failure', [
                'at'           => now()->toIso8601String(),
                'reason'       => $reason,
                'duration_sec' => $duration,
            ]);
            JobFailureReporter::report(
                static::class,
                'Nightly backup failed',
                new \RuntimeException($reason),
                ['script' => '/usr/local/bin/intake-backup.sh']
            );
            $this->line('recorded: backup failed');
            return self::SUCCESS;
        }

        $this->error('status must be "ok" or "failed"');
        return self::INVALID;
    }
}
