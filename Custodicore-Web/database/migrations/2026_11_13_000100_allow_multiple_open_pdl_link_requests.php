<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A visitor may now have several open requests to add a PDL (up to
 * PdlLinkRequestService::MAX_OPEN, enforced by the service under a lock on
 * the visitor row). The unique index on open_visitor_id allowed only one.
 *
 * open_visitor_id itself is kept and still filled while a request is open,
 * so rolling back only needs the index again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdl_link_requests', function (Blueprint $table) {
            $table->dropUnique(['open_visitor_id']);
        });
    }

    public function down(): void
    {
        // The unique index cannot come back while a visitor has two open requests.
        $crowded = DB::table('pdl_link_requests')
            ->whereNotNull('open_visitor_id')
            ->groupBy('open_visitor_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('open_visitor_id');
        if ($crowded->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot restore one open PDL request per visitor: visitor(s) #' . $crowded->implode(', #')
                . ' have several open requests. Have staff link, reject or the visitor cancel the extra ones first.'
            );
        }

        Schema::table('pdl_link_requests', function (Blueprint $table) {
            $table->unique('open_visitor_id');
        });
    }
};
