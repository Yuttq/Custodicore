<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\RelationshipDocument;
use App\Models\VisitorFlag;
use App\Models\VisitorId;
use App\Models\VisitorPdlRelationship;
use App\Models\VisitorProfile;
use App\Models\VisitRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read side of audit_logs for Record Officer screens: one visitor's history
 * (Account Reviews / visitor page) and the Activity Log page. Writing stays
 * with AuditLog::record() in each controller.
 */
class ActivityLog
{
    /** Record types grouped the way the Activity Log page filters them. */
    public const CATEGORIES = [
        'accounts' => ['label' => 'Visitor accounts & IDs', 'types' => ['visitor_profiles', 'visitor_ids']],
        'relationships' => ['label' => 'Relationships & documents', 'types' => ['visitor_pdl_relationships', 'relationship_documents']],
        'visits' => ['label' => 'Visits & eligibility', 'types' => ['visit_requests', 'eligibility_assessments']],
        'flags' => ['label' => 'Visitor flags', 'types' => ['visitor_flags']],
        'pdls' => ['label' => 'PDL records', 'types' => ['pdl_profiles', 'pdl_custody_status', 'pdl_legal_records', 'pdl_disciplinary_records', 'pdl_restrictions']],
        'cells' => ['label' => 'Cell blocks', 'types' => ['cell_blocks']],
    ];

    public static function allTypes(): array
    {
        return collect(self::CATEGORIES)->pluck('types')->flatten()->all();
    }

    public static function categoryOf(string $recordType): ?string
    {
        foreach (self::CATEGORIES as $key => $category) {
            if (in_array($recordType, $category['types'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** Everything that happened to one visitor's account, IDs, relationships, documents, flags and visits. */
    public static function forVisitor(VisitorProfile $visitor, int $limit = 50): Collection
    {
        $relationshipIds = VisitorPdlRelationship::where('visitor_id', $visitor->visitor_id)->pluck('relationship_id');
        $visitRequestIds = VisitRequest::where('visitor_id', $visitor->visitor_id)->pluck('visit_request_id');

        $sets = [
            'visitor_profiles' => collect([$visitor->visitor_id]),
            'visitor_ids' => VisitorId::where('visitor_id', $visitor->visitor_id)->pluck('visitor_id_doc_id'),
            'visitor_pdl_relationships' => $relationshipIds,
            'relationship_documents' => RelationshipDocument::whereIn('relationship_id', $relationshipIds)->pluck('document_id'),
            'visitor_flags' => VisitorFlag::where('visitor_id', $visitor->visitor_id)->pluck('flag_id'),
            'visit_requests' => $visitRequestIds,
            'eligibility_assessments' => \App\Models\EligibilityAssessment::whereIn('visit_request_id', $visitRequestIds)->pluck('assessment_id'),
        ];

        return AuditLog::query()
            ->with('account.role')
            ->where(function (Builder $q) use ($sets) {
                foreach ($sets as $type => $ids) {
                    if ($ids->isNotEmpty()) {
                        $q->orWhere(fn ($w) => $w->where('record_type', $type)->whereIn('record_id', $ids->all()));
                    }
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('audit_log_id')
            ->limit($limit)
            ->get();
    }

    /** "R. Salcedo", "Ana Reyes (visitor, in the app)" or "System". */
    public static function actor(AuditLog $log): string
    {
        $account = $log->account;
        if (! $account) {
            return 'System';
        }

        $name = $account->displayName();

        return $account->role?->role_name === 'Visitor' ? "{$name} (visitor, in the app)" : $name;
    }

    /** ok (green) / bad (red) / info (blue) — for the dot next to each entry. */
    public static function tone(AuditLog $log): string
    {
        $text = mb_strtolower($log->description);

        return match (true) {
            str_contains($text, 'reject') || str_contains($text, 'flagged') || str_contains($text, 'restriction for') && ! str_contains($text, 'lifted') => 'bad',
            str_contains($text, 'approv') || str_contains($text, 'verified') || str_contains($text, 'confirmed') || str_contains($text, 'lifted') || str_contains($text, 'resolved') => 'ok',
            default => 'info',
        };
    }

    /**
     * Where each entry's record lives, for the "Open" link on the Activity
     * Log page. Resolved in a few batched queries per page.
     *
     * @return array<int, string> audit_log_id => url
     */
    public static function links(Collection $logs): array
    {
        $ids = fn (string $type) => $logs->where('record_type', $type)->pluck('record_id')->unique()->values()->all();

        $visitorBy = [
            'visitor_ids' => VisitorId::whereIn('visitor_id_doc_id', $ids('visitor_ids'))->pluck('visitor_id', 'visitor_id_doc_id'),
            'visitor_pdl_relationships' => VisitorPdlRelationship::whereIn('relationship_id', $ids('visitor_pdl_relationships'))->pluck('visitor_id', 'relationship_id'),
            'relationship_documents' => RelationshipDocument::join('visitor_pdl_relationships as r', 'r.relationship_id', '=', 'relationship_documents.relationship_id')
                ->whereIn('document_id', $ids('relationship_documents'))->pluck('r.visitor_id', 'document_id'),
            'visitor_flags' => VisitorFlag::whereIn('flag_id', $ids('visitor_flags'))->pluck('visitor_id', 'flag_id'),
            'visit_requests' => VisitRequest::whereIn('visit_request_id', $ids('visit_requests'))->pluck('visitor_id', 'visit_request_id'),
        ];
        $pdlBy = [
            'pdl_legal_records' => \App\Models\PdlLegalRecord::whereIn('legal_record_id', $ids('pdl_legal_records'))->pluck('pdl_id', 'legal_record_id'),
            'pdl_disciplinary_records' => \App\Models\PdlDisciplinaryRecord::whereIn('disciplinary_record_id', $ids('pdl_disciplinary_records'))->pluck('pdl_id', 'disciplinary_record_id'),
            'pdl_restrictions' => \App\Models\PdlRestriction::whereIn('restriction_id', $ids('pdl_restrictions'))->pluck('pdl_id', 'restriction_id'),
        ];

        $links = [];
        foreach ($logs as $log) {
            $type = $log->record_type;
            $id = $log->record_id;

            $url = match (true) {
                $type === 'visitor_profiles' => route('visitor.show', $id),
                isset($visitorBy[$type][$id]) => route('visitor.show', $visitorBy[$type][$id]),
                in_array($type, ['pdl_profiles', 'pdl_custody_status'], true) => route('pdl.show', $id),
                isset($pdlBy[$type][$id]) => route('pdl.show', $pdlBy[$type][$id]),
                $type === 'cell_blocks' => route('cell-blocks.index'),
                $type === 'eligibility_assessments' => route('eligibility.index'),
                default => null,
            };

            if ($url) {
                $links[$log->audit_log_id] = $url;
            }
        }

        return $links;
    }
}
