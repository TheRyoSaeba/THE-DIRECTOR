<?php

namespace App\Console\Commands;

use App\Services\CronJobService;
use Illuminate\Console\Command;

class SyncCronJobs extends Command
{
    
    protected $signature = 'cron:sync';

    
    protected $description = 'Synchronize game cron jobs with pg_cron in PostgreSQL';

    
    public function handle(CronJobService $cronService)
    {
        $this->info('Starting cron job synchronization...');

        if (!$cronService->isInstalled()) {
            $this->error('pg_cron extension is not installed in the database.');
            return 1;
        }

        if ($cronService->resetGameJobs()) {
            $this->info('Successfully synchronized  cron jobs.');

            $jobs = $cronService->getAllJobs();
            $this->table(
            ['ID', 'Name', 'Schedule', 'Active'],
                collect($jobs)->map(fn($j) => [$j->jobid, $j->jobname, $j->schedule, $j->active])
            );

            return 0;
        }

        $this->error('Failed to synchronize some cron jobs. Check logs for details.');
        return 1;
    }
}
