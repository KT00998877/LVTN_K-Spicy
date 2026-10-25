<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Services\BlogService;
use Illuminate\Console\Command;

class SanitizeBlogContent extends Command
{
    protected $signature = 'blogs:sanitize-content {--dry-run : Report changes without updating the database}';

    protected $description = 'Sanitize stored blog HTML with the current security allowlist';

    public function handle(BlogService $blogs): int
    {
        $changed = 0;
        $dryRun = (bool) $this->option('dry-run');

        Blog::query()
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($blogs, $dryRun, &$changed): void {
                foreach ($items as $blog) {
                    $sanitized = $blogs->sanitizeHtml((string) $blog->content);
                    if ($sanitized === $blog->content) {
                        continue;
                    }

                    $changed++;
                    if (!$dryRun) {
                        $blog->forceFill(['content' => $sanitized])->saveQuietly();
                    }
                }
            });

        $action = $dryRun ? 'would be updated' : 'updated';
        $this->info("{$changed} blog post(s) {$action}.");

        return self::SUCCESS;
    }
}
