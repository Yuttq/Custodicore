<?php

namespace App\Console\Commands;

use App\Models\VisitorId;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * One-off move of government ID files from the old public disk
 * (storage/app/public/visitor-ids) to the private disk
 * (storage/app/private/visitor-ids). See VisitorId::DISK / LEGACY_DISK.
 *
 * Three modes, run in this order:
 *   (no option)          dry run: reports what would happen, changes nothing
 *   --copy               copies each file, verifies the SHA-256 checksum,
 *                        keeps the original
 *   --delete-originals   deletes a public original ONLY when its private
 *                        copy exists and has the same checksum
 *
 * file_path keeps the same value on both disks, so the database is only
 * read, never written. Safe to run repeatedly. Prints record ids and counts
 * only — never names or file contents.
 */
class MoveVisitorIdsToPrivate extends Command
{
    protected $signature = 'visitor-ids:move-to-private
        {--copy : Copy files to the private disk and verify them (originals are kept)}
        {--delete-originals : Delete public originals whose private copy is verified}';

    protected $description = 'Move government ID files from the public disk to the private disk (dry run unless --copy or --delete-originals)';

    private const FOLDER = 'visitor-ids/';

    public function handle(): int
    {
        if ($this->option('copy') && $this->option('delete-originals')) {
            $this->error('Use --copy first, check the result, then run --delete-originals separately.');

            return self::FAILURE;
        }

        $mode = match (true) {
            (bool) $this->option('copy') => 'copy',
            (bool) $this->option('delete-originals') => 'delete',
            default => 'dry-run',
        };

        $public = Storage::disk(VisitorId::LEGACY_DISK);
        $private = Storage::disk(VisitorId::DISK);

        $counts = array_fill_keys([
            'already private', 'to copy', 'copied', 'verified, original still public',
            'originals deleted', 'checksum mismatch', 'copy failed', 'missing on both disks', 'skipped (unexpected path)',
        ], 0);
        $problems = [];
        $referenced = [];

        VisitorId::whereNotNull('file_path')->where('file_path', '!=', '')
            ->orderBy('visitor_id_doc_id')
            ->chunk(200, function ($documents) use ($mode, $public, $private, &$counts, &$problems, &$referenced) {
                foreach ($documents as $document) {
                    $path = $document->file_path;
                    $id = $document->visitor_id_doc_id;

                    if (! str_starts_with($path, self::FOLDER) || str_contains($path, '..')) {
                        $counts['skipped (unexpected path)']++;
                        $problems[] = "visitor_ids #{$id}: unexpected file path, left alone";

                        continue;
                    }

                    $referenced[$path] = true;
                    $inPublic = $public->exists($path);
                    $inPrivate = $private->exists($path);

                    if (! $inPublic && ! $inPrivate) {
                        $counts['missing on both disks']++;
                        $problems[] = "visitor_ids #{$id}: file missing on both disks";

                        continue;
                    }

                    if ($inPrivate && ! $inPublic) {
                        $counts['already private']++;

                        continue;
                    }

                    if ($inPrivate && $inPublic) {
                        if (! $this->sameChecksum($public, $private, $path)) {
                            // Never overwrite or delete either copy: a person must look.
                            $counts['checksum mismatch']++;
                            $problems[] = "visitor_ids #{$id}: private copy differs from the public original";
                        } elseif ($mode === 'delete') {
                            $public->delete($path);
                            $counts[$public->exists($path) ? 'verified, original still public' : 'originals deleted']++;
                        } else {
                            $counts['verified, original still public']++;
                        }

                        continue;
                    }

                    // Only on the public disk.
                    if ($mode !== 'copy') {
                        $counts['to copy']++;

                        continue;
                    }

                    if ($this->copy($public, $private, $path)) {
                        $counts['copied']++;
                    } else {
                        $counts['copy failed']++;
                        $problems[] = "visitor_ids #{$id}: copy failed or did not verify; original kept";
                    }
                }
            });

        $unreferenced = collect($public->files(rtrim(self::FOLDER, '/')))
            ->reject(fn (string $file) => isset($referenced[$file]))
            ->count();

        $this->info(match ($mode) {
            'copy' => 'Copy run (originals kept):',
            'delete' => 'Delete run (only verified originals removed):',
            default => 'Dry run — nothing was changed:',
        });

        $this->table(['Result', 'Files'], collect($counts)
            ->filter(fn (int $n, string $label) => $n > 0 || in_array($label, ['already private', 'to copy'], true))
            ->map(fn (int $n, string $label) => [$label, $n])
            ->values()
            ->all());

        if ($unreferenced > 0) {
            $this->warn("{$unreferenced} file(s) in the public visitor-ids folder are not referenced by any visitor_ids row. They were left alone — review and remove them by hand.");
        }

        foreach ($problems as $problem) {
            $this->warn($problem);
        }

        return $counts['checksum mismatch'] + $counts['copy failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Copies one file and keeps the copy only when its checksum matches. */
    private function copy(FilesystemAdapter $public, FilesystemAdapter $private, string $path): bool
    {
        $stream = $public->readStream($path);

        if (! $stream) {
            return false;
        }

        try {
            $written = $private->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written && $this->sameChecksum($public, $private, $path)) {
            return true;
        }

        $private->delete($path);

        return false;
    }

    private function sameChecksum(FilesystemAdapter $public, FilesystemAdapter $private, string $path): bool
    {
        $options = ['checksum_algo' => 'sha256'];
        $original = $public->checksum($path, $options);

        return $original !== false && hash_equals($original, (string) $private->checksum($path, $options));
    }
}
