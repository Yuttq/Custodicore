<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\VisitorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The visitor's own photo, taken in the mobile app when they create their
 * account (and shown on their profile).
 *
 *   POST /api/me/photo  multipart "photo" — take or retake the photo
 *   GET  /api/me/photo  the visitor's own photo (for the profile screen)
 *
 * The photo is required with registration (AuthController::register and
 * registerWithGoogle, field "photo"). It is reviewed on its own like a
 * government ID (photo_status): a retake is allowed only when there is none
 * yet or staff rejected it or asked for a new one (canChangePhoto). A photo
 * waiting for review or approved stays locked.
 */
class VisitorPhotoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $visitor = $this->visitor($request);

        if (! $visitor->canChangePhoto()) {
            return response()->json([
                'message' => $visitor->photo_status === VisitorProfile::PHOTO_VERIFIED
                    ? 'Your photo is already approved, so it can no longer be changed here. Please ask the facility staff.'
                    : 'Your photo is waiting for review. You can retake it only if facility staff reject it.',
            ], 409);
        }

        $request->validate(['photo' => ['required', ...VisitorProfile::PHOTO_RULES]], [
            'photo.required' => 'Please take a photo of yourself.',
            'photo.image' => 'The photo must be a JPG, PNG or WEBP picture.',
            'photo.mimes' => 'The photo must be a JPG, PNG or WEBP picture.',
            'photo.max' => 'The photo is too large. Maximum size is 5 MB.',
        ]);

        self::storeFor($visitor, $request->file('photo'));

        // A returned account goes back to Account Review with the new photo;
        // otherwise only the photo goes back to review (IDs and documents untouched).
        $visitor->resubmitIfReturned('new photo');

        return response()->json([
            'message' => 'Photo saved.',
            'hasPhoto' => true,
            'photoUrl' => url('/api/me/photo'),
            'photoUploadedAt' => $visitor->fresh()->photo_uploaded_at?->toIso8601String(),
            'photoStatus' => VisitorProfile::PHOTO_PENDING,
        ], 201);
    }

    public function show(Request $request)
    {
        $visitor = $this->visitor($request);
        abort_unless($visitor->photo_path && Storage::disk('local')->exists($visitor->photo_path), 404);

        return Storage::disk('local')->response($visitor->photo_path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    /** Save (or replace) a visitor's photo on the private disk. */
    public static function storeFor(VisitorProfile $visitor, UploadedFile $file): void
    {
        self::assign($visitor, self::storeFile($file));
    }

    /**
     * Writes the photo file only. Registration calls this before its
     * transaction, so a failed registration can delete exactly this file.
     * A failed write (the local disk does not throw) is reported on `photo`
     * before anything is saved, instead of returning an empty path.
     */
    public static function storeFile(UploadedFile $file): string
    {
        $name = $file->hashName();
        $path = $file->storeAs('visitor-photos', $name, 'local');

        if (! is_string($path) || $path === '') {
            // A failed write can leave a partial file under the intended name.
            Storage::disk('local')->delete('visitor-photos/'.$name);

            throw ValidationException::withMessages([
                'photo' => 'Your photo could not be saved. Please try again.',
            ]);
        }

        return $path;
    }

    /** Records an already stored photo on the profile (replacing any older one). */
    public static function assign(VisitorProfile $visitor, string $path): void
    {
        $old = $visitor->photo_path;

        // Every new photo waits for review. A previous rejection reason is kept
        // so staff see what was asked for; verifying the photo clears it.
        $visitor->update([
            'photo_path' => $path,
            'photo_uploaded_at' => now(),
            'photo_status' => VisitorProfile::PHOTO_PENDING,
            'photo_reviewed_by' => null,
            'photo_reviewed_at' => null,
        ]);

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        try {
            AuditLog::record($old ? 'update' : 'create', 'visitor_profiles', $visitor->visitor_id,
                ($old ? 'Visitor retook their profile photo' : 'Visitor added their profile photo') . ' via mobile app',
                Module::CODE_VISITOR_MANAGEMENT);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Audit log write failed: ' . $e->getMessage());
        }
    }

    private function visitor(Request $request): VisitorProfile
    {
        // Always read the current row: the approval lock must not depend on a cached copy.
        $visitor = $request->user()?->visitorProfile()->first();
        abort_unless($visitor, 403, 'This account has no visitor profile.');

        return $visitor;
    }
}
