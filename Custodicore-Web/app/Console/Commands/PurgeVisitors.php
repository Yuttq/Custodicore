<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Role;
use App\Models\VisitorId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * php artisan cc:purge-visitors            (dry run: shows what would be deleted)
 * php artisan cc:purge-visitors --force    (deletes, after a typed confirmation)
 *
 * Permanently deletes EVERY Visitor account and everything that belongs to
 * it, so the same emails / Google accounts can register again from scratch:
 * the account, visitor profile, photo, government IDs, relationship links and
 * documents, PDL link requests, visit requests (with their QR codes,
 * check-ins, eligibility assessments and booked sessions), flags, walk-in
 * assessments, notifications, email-verification and password-reset tokens,
 * API tokens and web sessions — and the uploaded files on disk.
 *
 * Never touches PDL records, staff accounts, visitation schedules, rules or
 * settings. Audit log entries stay (the database clears their account link);
 * one summary entry is added. Needs shell access, so it cannot be triggered
 * from the website.
 */
class PurgeVisitors extends Command
{
    private const VISITOR_ROLE = 'Visitor';

    private const CONFIRM_PHRASE = 'DELETE ALL VISITORS';

    /** Notification related_record_type values that point at visitor data. */
    private const VISITOR_NOTIFICATION_TYPES = [
        'visitor_profiles' => 'visitorIds',
        'visit_requests' => 'visitRequestIds',
        'visitor_pdl_relationships' => 'relationshipIds',
        'pdl_link_requests' => 'linkRequestIds',
    ];

    protected $signature = 'cc:purge-visitors
        {--force : Actually delete (without it, this is a dry run that changes nothing)}
        {--yes : Skip the typed confirmation (only with --force)}';

    protected $description = 'Permanently delete every Visitor account and its data and files (PDLs and staff are kept)';

    public function handle(): int
    {
        $role = Role::where('role_name', self::VISITOR_ROLE)->first();

        if (! $role) {
            $this->error('The Visitor role does not exist. Nothing to do.');

            return self::FAILURE;
        }

        $ids = $this->collectIds($role->role_id);

        if ($ids['accountIds'] === []) {
            $this->info('There are no visitor accounts. Nothing to delete.');

            return self::SUCCESS;
        }

        $files = $this->collectFiles($ids);
        $this->printPlan($ids, $files);

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run only: nothing was deleted. Run again with --force to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('yes')) {
            $typed = $this->ask('This cannot be undone. Type "' . self::CONFIRM_PHRASE . '" to continue');

            if ($typed !== self::CONFIRM_PHRASE) {
                $this->warn('Cancelled: nothing was deleted.');

                return self::FAILURE;
            }
        }

        $deleted = $this->deleteRows($ids);
        [$removedFiles, $missingFiles] = $this->deleteFiles($files);

        // A console run has no signed-in user; never attribute the entry to
        // an account that was just deleted.
        auth()->forgetGuards();

        AuditLog::record(
            'delete',
            'accounts',
            0,
            Str::limit("Purged all {$deleted} visitor accounts and their data from the server console ({$removedFiles} files removed)", 250),
            Module::CODE_VISITOR_MANAGEMENT
        );

        $this->newLine();
        $this->info("Deleted {$deleted} visitor accounts and all their data.");
        $this->info("Removed {$removedFiles} files" . ($missingFiles ? " ({$missingFiles} were already missing)." : '.'));
        $this->line('PDL records, staff accounts and schedules were not touched.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<int>>
     */
    private function collectIds(int $roleId): array
    {
        $accountIds = $this->ints(DB::table('accounts')->where('role_id', $roleId)->pluck('account_id'));
        $visitorIds = $this->ints(DB::table('visitor_profiles')->whereIn('account_id', $accountIds)->pluck('visitor_id'));

        return [
            'accountIds' => $accountIds,
            'visitorIds' => $visitorIds,
            'visitRequestIds' => $this->ints(DB::table('visit_requests')->whereIn('visitor_id', $visitorIds)->pluck('visit_request_id')),
            'relationshipIds' => $this->ints(DB::table('visitor_pdl_relationships')->whereIn('visitor_id', $visitorIds)->pluck('relationship_id')),
            'linkRequestIds' => $this->ints(DB::table('pdl_link_requests')->whereIn('visitor_id', $visitorIds)->pluck('request_id')),
        ];
    }

    /**
     * Every stored file that belongs to these visitors, as [disk, path] pairs.
     *
     * @param  array<string, list<int>>  $ids
     * @return list<array{0: string, 1: string}>
     */
    private function collectFiles(array $ids): array
    {
        $files = [];

        foreach (DB::table('visitor_profiles')->whereIn('visitor_id', $ids['visitorIds'])->whereNotNull('photo_path')->pluck('photo_path') as $path) {
            $files[] = ['local', $path];
        }

        // Government IDs may still sit on the legacy public disk.
        foreach (DB::table('visitor_ids')->whereIn('visitor_id', $ids['visitorIds'])->whereNotNull('file_path')->pluck('file_path') as $path) {
            $files[] = [VisitorId::DISK, $path];
            $files[] = [VisitorId::LEGACY_DISK, $path];
        }

        foreach (DB::table('visitor_pdl_relationships')->whereIn('relationship_id', $ids['relationshipIds'])->whereNotNull('supporting_document_path')->pluck('supporting_document_path') as $path) {
            $files[] = ['local', $path];
        }

        $documentPaths = DB::table('relationship_documents')
            ->where(fn ($q) => $q->whereIn('visitor_id', $ids['visitorIds'])->orWhereIn('relationship_id', $ids['relationshipIds']))
            ->whereNotNull('file_path')
            ->pluck('file_path');

        foreach ($documentPaths as $path) {
            $files[] = ['local', $path];
        }

        return array_values(array_unique($files, SORT_REGULAR));
    }

    /**
     * @param  array<string, list<int>>  $ids
     * @param  list<array{0: string, 1: string}>  $files
     */
    private function printPlan(array $ids, array $files): void
    {
        $count = fn (string $table, string $column, array $values) => $values === []
            ? 0
            : DB::table($table)->whereIn($column, $values)->count();

        $this->warn('Visitor accounts that will be PERMANENTLY deleted:');
        $this->table(
            ['Account', 'Name', 'Email', 'Status'],
            DB::table('accounts')
                ->leftJoin('visitor_profiles', 'visitor_profiles.account_id', '=', 'accounts.account_id')
                ->whereIn('accounts.account_id', $ids['accountIds'])
                ->orderBy('accounts.account_id')
                ->get(['accounts.account_id', 'visitor_profiles.full_name', 'accounts.email', 'visitor_profiles.verification_status'])
                ->map(fn ($r) => [$r->account_id, $r->full_name ?? '—', $r->email ?? '(walk-in, no email)', $r->verification_status ?? '—'])
                ->all()
        );

        $this->table(['What', 'Rows'], [
            ['Visitor accounts', count($ids['accountIds'])],
            ['Visitor profiles', count($ids['visitorIds'])],
            ['Government IDs', $count('visitor_ids', 'visitor_id', $ids['visitorIds'])],
            ['PDL relationship links', count($ids['relationshipIds'])],
            ['Relationship documents', DB::table('relationship_documents')->where(fn ($q) => $q->whereIn('visitor_id', $ids['visitorIds'] ?: [0])->orWhereIn('relationship_id', $ids['relationshipIds'] ?: [0]))->count()],
            ['PDL link requests', count($ids['linkRequestIds'])],
            ['Visit requests', count($ids['visitRequestIds'])],
            ['  QR codes', $count('qr_codes', 'visit_request_id', $ids['visitRequestIds'])],
            ['  Check-ins', $count('visit_checkins', 'visit_request_id', $ids['visitRequestIds'])],
            ['  Booked sessions', $count('visit_sessions', 'visit_request_id', $ids['visitRequestIds'])],
            ['Visitor flags', $count('visitor_flags', 'visitor_id', $ids['visitorIds'])],
            ['Walk-in assessments', $count('walk_in_assessments', 'visitor_id', $ids['visitorIds'])],
            ['Notifications', $count('notifications', 'account_id', $ids['accountIds'])],
            ['API tokens (app sign-ins)', $this->tokenQuery($ids['accountIds'])->count()],
            ['Files on disk (photos, IDs, documents)', count(array_filter($files, fn ($f) => Storage::disk($f[0])->exists($f[1])))],
        ]);

        $this->line('Kept: PDL records, staff accounts, visitation schedules, rules, settings and audit history.');
    }

    /**
     * @param  array<string, list<int>>  $ids
     */
    private function deleteRows(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $emails = DB::table('accounts')->whereIn('account_id', $ids['accountIds'])->whereNotNull('email')->pluck('email')->all();

            // Staff-side notifications that point at a visitor record.
            foreach (self::VISITOR_NOTIFICATION_TYPES as $type => $key) {
                if ($ids[$key] !== []) {
                    DB::table('notifications')
                        ->where('related_record_type', $type)
                        ->whereIn('related_record_id', $ids[$key])
                        ->delete();
                }
            }

            // Visit requests first: their relationship_id key has no cascade,
            // so deleting a relationship that still has visits would fail.
            // Deleting them cascades to QR codes, check-ins, eligibility
            // assessments and booked sessions.
            if ($ids['visitRequestIds'] !== []) {
                DB::table('visit_requests')->whereIn('visit_request_id', $ids['visitRequestIds'])->delete();
            }

            // Not linked by foreign keys, so removed explicitly.
            $this->tokenQuery($ids['accountIds'])->delete();
            DB::table('sessions')->whereIn('user_id', $ids['accountIds'])->delete();
            if ($emails !== []) {
                DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            }

            // Children explicitly, bottom-up, instead of relying on several
            // cascade paths reaching the same rows (MySQL can refuse that).
            DB::table('relationship_documents')
                ->where(fn ($q) => $q->whereIn('visitor_id', $ids['visitorIds'] ?: [0])->orWhereIn('relationship_id', $ids['relationshipIds'] ?: [0]))
                ->delete();
            foreach (['pdl_link_requests', 'visitor_ids', 'visitor_flags', 'walk_in_assessments', 'visitor_pdl_relationships', 'visitor_profiles'] as $table) {
                DB::table($table)->whereIn('visitor_id', $ids['visitorIds'] ?: [0])->delete();
            }
            DB::table('notifications')->whereIn('account_id', $ids['accountIds'])->delete();
            DB::table('email_verification_tokens')->whereIn('account_id', $ids['accountIds'])->delete();

            return DB::table('accounts')->whereIn('account_id', $ids['accountIds'])->delete();
        });
    }

    /**
     * Runs after the database commit, so a file is never removed for a row
     * that was kept.
     *
     * @param  list<array{0: string, 1: string}>  $files
     * @return array{0: int, 1: int}
     */
    private function deleteFiles(array $files): array
    {
        // Group by path: a government ID is listed once per disk it may be on.
        $disksByPath = [];
        foreach ($files as [$disk, $path]) {
            $disksByPath[$path][] = $disk;
        }

        $removed = 0;
        $missing = 0;

        foreach ($disksByPath as $path => $disks) {
            $found = false;

            foreach ($disks as $disk) {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                    $found = true;
                }
            }

            $found ? $removed++ : $missing++;
        }

        return [$removed, $missing];
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function tokenQuery(array $accountIds)
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\Account::class)
            ->whereIn('tokenable_id', $accountIds ?: [0]);
    }

    /**
     * @return list<int>
     */
    private function ints(iterable $values): array
    {
        return array_values(array_map('intval', is_array($values) ? $values : collect($values)->all()));
    }
}
