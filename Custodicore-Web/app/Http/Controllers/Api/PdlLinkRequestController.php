<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PdlLinkRequest;
use App\Models\VisitorProfile;
use App\Services\PdlLinkRequestService;
use App\Services\PdlRequestNotOpen;
use App\Services\PdlRequestRefused;
use App\Services\RelationshipRequirements;
use App\Support\Sanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5B: an approved visitor asks to add another PDL.
 *
 *   POST /api/pdl-requests                       — submit (multipart; visitor.approved)
 *   POST /api/pdl-requests/{pdlLinkRequest}/cancel — cancel the own open request
 *                                                   (any signed-in visitor, also one
 *                                                   whose account is no longer approved)
 *
 * The request stays separate from any relationship until facility staff
 * identify the PDL (later stage). A visitor may have up to
 * PdlLinkRequestService::MAX_OPEN open requests. GET /api/documents returns
 * them as `pdlRequests` (and the newest as `pdlRequest` for older builds).
 */
class PdlLinkRequestController extends Controller
{
    public function __construct(private PdlLinkRequestService $requests)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $visitor = $this->currentVisitor($request);

        $request->merge([
            'pdlName' => is_string($name = $request->input('pdlName', $request->input('pdl_name'))) ? Sanitizer::name($name) : $name,
            'relationshipType' => $request->input('relationshipType', $request->input('relationship_type')),
            'hasChildrenTogether' => self::booleanInput($request->input('hasChildrenTogether', $request->input('has_children_together'))),
        ]);

        // Same file rules as every other requirement upload (VisitorApiController).
        $fileRules = array_values(array_diff(VisitorApiController::DOCUMENT_FILE_RULES, ['required']));
        $validated = $request->validate([
            // Same rules and wording as the registration's intendedPdlName.
            'pdlName' => ['required', 'string', 'min:2', 'max:150', 'regex:' . Sanitizer::NAME_REGEX],
            'relationshipType' => ['required', 'string', Rule::in(RelationshipRequirements::registrationTypes())],
            'hasChildrenTogether' => ['nullable', 'boolean', 'required_if:relationshipType,live_in_partner'],
            'documents' => ['required', 'array'],
            'documents.*' => $fileRules,
        ], [
            'pdlName.required' => 'Please enter the name of the PDL you want to visit.',
            'pdlName.min' => 'Enter the full name of the PDL you want to visit.',
            'pdlName.max' => 'The PDL name is too long (150 characters at most).',
            'pdlName.regex' => 'The PDL name can only contain letters, spaces, periods, apostrophes and hyphens.',
            'relationshipType.required' => 'Select your relationship to the PDL.',
            'relationshipType.in' => 'Select your relationship to the PDL.',
            'hasChildrenTogether.required_if' => 'Tell us whether you and the PDL have children together.',
            'documents.required' => 'Upload the documents required for this relationship.',
            'documents.*.mimes' => 'Upload a JPG, PNG, WEBP, or PDF file.',
            'documents.*.max' => 'The file is too large. Maximum size is 10 MB.',
        ]);

        $hasChildren = $validated['relationshipType'] === 'live_in_partner' ? (bool) $validated['hasChildrenTogether'] : null;
        $isMinor = $visitor->date_of_birth?->age !== null && $visitor->date_of_birth->age < RelationshipRequirements::ADULT_AGE;
        $required = PdlLinkRequestService::requiredDocumentKeys($validated['relationshipType'], $hasChildren, $isMinor);
        $files = (array) $request->file('documents', []);

        // Exactly the documents this relationship asks for: none missing, none extra.
        $errors = [];
        if (array_diff(array_keys($files), $required) !== []) {
            $errors['documents'] = 'One of the uploaded documents is not required for the selected relationship.';
        }
        foreach ($required as $key) {
            if (! isset($files[$key])) {
                $errors["documents.{$key}"] = 'Upload ' . (RelationshipRequirements::DOCUMENTS[$key][0] ?? $key) . '.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $pdlRequest = $this->requests->submit($visitor, [
                'pdlName' => $validated['pdlName'],
                'relationshipType' => $validated['relationshipType'],
                'hasChildrenTogether' => $hasChildren,
            ], $files);
        } catch (PdlRequestRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->refusal], 409);
        } catch (\Throwable $e) {
            Log::error("PDL request could not be saved for visitor #{$visitor->visitor_id} (" . $e::class . '): ' . $e->getMessage());

            return response()->json(['message' => 'Your PDL request could not be sent. Please try again.'], 500);
        }

        return response()->json(['pdlRequest' => self::payload($pdlRequest->fresh('documents'))], 201);
    }

    public function cancel(Request $request, PdlLinkRequest $pdlLinkRequest): JsonResponse
    {
        $visitor = $this->currentVisitor($request);
        // Someone else's request is simply "not found".
        abort_unless((int) $pdlLinkRequest->visitor_id === (int) $visitor->visitor_id, 404);

        try {
            $cancelled = $this->requests->cancel($visitor, $pdlLinkRequest);
        } catch (PdlRequestNotOpen $e) {
            return response()->json(['message' => $e->getMessage(), 'status' => $e->status], 409);
        }

        return response()->json(['pdlRequest' => self::payload($cancelled->load('documents'))]);
    }

    /**
     * What the app may see of a request: no file paths, no staff details.
     * Documents are listed by requirement only (a cancelled or rejected
     * request has none left).
     */
    public static function payload(PdlLinkRequest $request): array
    {
        return [
            'id' => (string) $request->request_id,
            'status' => $request->status,
            'pdlName' => $request->pdl_name,
            'relationshipType' => $request->relationship_type,
            'relationshipLabel' => $request->relationshipLabel(),
            'hasChildrenTogether' => $request->has_children_together,
            'rejectionReason' => $request->status === PdlLinkRequest::STATUS_REJECTED ? $request->rejection_reason : null,
            'createdAt' => $request->created_at?->toIso8601String(),
            'updatedAt' => $request->updated_at?->toIso8601String(),
            'documents' => $request->documents->map(fn ($d) => [
                'key' => $d->requirement_key,
                'label' => $d->label(),
                'uploadedAt' => $d->uploaded_at ? \Illuminate\Support\Carbon::parse($d->uploaded_at)->toIso8601String() : null,
            ])->values(),
        ];
    }

    /** Multipart sends booleans as text: "1"/"0", "true"/"false" → true/false; anything else unchanged. */
    private static function booleanInput(mixed $value): mixed
    {
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed ?? ($value === '' ? null : $value);
        }

        return $value;
    }

    private function currentVisitor(Request $request): VisitorProfile
    {
        $profile = $request->user()?->visitorProfile;
        abort_unless($profile, 403, 'This account has no visitor profile.');

        return $profile;
    }
}
