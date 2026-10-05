<?php

declare(strict_types=1);

namespace HosmelQ\TemporaryUploads\Commands;

use HosmelQ\TemporaryUploads\TemporaryUploads;
use Illuminate\Console\Command;

class PruneTemporaryUploads extends Command
{
    /**
     * @var string
     */
    protected $description = 'Delete expired files from the temporary uploads prefix';

    /**
     * @var string
     */
    protected $signature = 'temporary-uploads:prune';

    public function handle(TemporaryUploads $uploads): int
    {
        $count = $uploads->prune();

        $this->components->info("Pruned {$count} temporary upload(s).");

        return self::SUCCESS;
    }
}
