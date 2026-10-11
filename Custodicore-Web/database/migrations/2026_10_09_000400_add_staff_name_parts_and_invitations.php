<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff accounts created by the Warden.
 *
 * staff_profiles
 *   - first_name / middle_name / last_name / suffix: separate name parts so
 *     staff can be searched and sorted by last name. full_name is still
 *     stored (the StaffProfile model rebuilds it on save) because the
 *     sidebar, audit trail and every list keep reading it.
 *   - contact_number (already existed) becomes unique when no two existing
 *     rows share one, so one mobile number cannot belong to two officers.
 *
 * accounts
 *   - invitation_token_hash / invitation_sent_at / invitation_expires_at:
 *     the one-time "set up your account" link. Only the SHA-256 hash of the
 *     token is stored. All three are NULL once the officer has set up.
 *   - password_set_at: when the officer chose their own password.
 *
 * Existing staff are backfilled by splitting full_name (see splitName()).
 * It is a best guess; the Warden can correct any row with Edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('full_name');
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('last_name', 100)->nullable()->after('middle_name');
            $table->string('suffix', 20)->nullable()->after('last_name');

            $table->index(['last_name', 'first_name'], 'staff_profiles_name_index');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('invitation_token_hash', 64)->nullable()->unique()->after('status');
            $table->timestamp('invitation_sent_at')->nullable()->after('invitation_token_hash');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_sent_at');
            $table->timestamp('password_set_at')->nullable()->after('invitation_expires_at');
        });

        // Backfill name parts for staff that only have full_name.
        DB::table('staff_profiles')->whereNull('first_name')->orderBy('staff_id')->get()->each(function ($row) {
            [$first, $middle, $last, $suffix] = $this->splitName((string) $row->full_name);

            DB::table('staff_profiles')->where('staff_id', $row->staff_id)->update([
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'suffix' => $suffix,
            ]);
        });

        // One mobile number, one officer — but only if the data allows it
        // (a duplicate would make the index fail and block the whole migration).
        $hasDuplicates = DB::table('staff_profiles')
            ->whereNotNull('contact_number')
            ->select('contact_number')
            ->groupBy('contact_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasDuplicates) {
            Schema::table('staff_profiles', function (Blueprint $table) {
                $table->unique('contact_number', 'staff_profiles_contact_number_unique');
            });
        }
    }

    public function down(): void
    {
        // Only exists when no duplicate numbers were present at migrate time.
        if (Schema::hasIndex('staff_profiles', 'staff_profiles_contact_number_unique')) {
            Schema::table('staff_profiles', function (Blueprint $table) {
                $table->dropUnique('staff_profiles_contact_number_unique');
            });
        }

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropIndex('staff_profiles_name_index');
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'suffix']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['invitation_token_hash', 'invitation_sent_at', 'invitation_expires_at', 'password_set_at']);
        });
    }

    /**
     * "Juan D. Dela Cruz Jr." -> [Juan, D., Dela Cruz, Jr.]
     * "Dela Cruz, Juan D."    -> [Juan, D., Dela Cruz, null]
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function splitName(string $full): array
    {
        $full = trim((string) preg_replace('/\s+/u', ' ', $full));
        if ($full === '') {
            return [null, null, null, null];
        }

        $suffixes = ['jr' => 'Jr.', 'sr' => 'Sr.', 'ii' => 'II', 'iii' => 'III', 'iv' => 'IV', 'v' => 'V'];
        $particles = ['de', 'dela', 'del', 'delos', 'de la', 'san', 'santa', 'van', 'von', 'da', 'di', 'la', 'le', 'los', 'las'];
        $suffix = null;

        // "Last, First Middle" (or "First Last, Jr.")
        if (str_contains($full, ',')) {
            [$before, $after] = array_map('trim', explode(',', $full, 2));
            $afterKey = strtolower(rtrim($after, '.'));

            if (isset($suffixes[$afterKey])) {
                $suffix = $suffixes[$afterKey];
                $full = $before;
            } else {
                $words = preg_split('/ /', $after, -1, PREG_SPLIT_NO_EMPTY);
                $first = array_shift($words);

                return [$first, $words ? implode(' ', $words) : null, $before, null];
            }
        }

        $words = preg_split('/ /', $full, -1, PREG_SPLIT_NO_EMPTY);

        $lastKey = strtolower(rtrim((string) end($words), '.'));
        if (count($words) > 2 && isset($suffixes[$lastKey])) {
            $suffix = $suffixes[$lastKey];
            array_pop($words);
        }

        if (count($words) === 1) {
            return [$words[0], null, null, $suffix];
        }

        $last = array_pop($words);
        while (count($words) > 1 && in_array(strtolower((string) end($words)), $particles, true)) {
            $last = array_pop($words) . ' ' . $last;
        }
        $first = array_shift($words);

        return [$first, $words ? implode(' ', $words) : null, $last, $suffix];
    }
};
