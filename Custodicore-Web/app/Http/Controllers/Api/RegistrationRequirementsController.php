<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RelationshipRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * GET /api/registration/requirements — public, read-only registration
 * guidance: relationship types and the documents each one needs, straight
 * from App\Services\RelationshipRequirements. Rules only: it never reads
 * visitors, PDLs or relationships, so it can't reveal whether anyone is in
 * custody.
 *
 * Optional ?relationshipType=spouse narrows relationshipTypes to one entry;
 * an unknown type is a 422 with the usual { message, errors } shape.
 */
class RegistrationRequirementsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'relationshipType' => ['nullable', 'string', Rule::in(array_keys(RelationshipRequirements::RELATIONSHIP_RULES))],
        ], [
            'relationshipType.in' => 'Unknown relationship type.',
        ]);

        // Answer in JSON even if the client forgot Accept: application/json.
        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $guide = RelationshipRequirements::registrationGuide();

        $type = $validator->validated()['relationshipType'] ?? null;
        if ($type !== null) {
            $guide['relationshipTypes'] = array_values(array_filter(
                $guide['relationshipTypes'],
                fn (array $entry) => $entry['key'] === $type
            ));
        }

        return response()->json($guide);
    }
}
