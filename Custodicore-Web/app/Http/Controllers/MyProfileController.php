<?php

namespace App\Http\Controllers;

use App\Models\StaffProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "My Profile" for every staff role (Warden, Record Officer, Front Desk Officer):
 * the signed-in officer adds, replaces or removes their OWN profile picture.
 * Nobody can change another officer's picture here — every action works on
 * auth()->user()'s own staff profile.
 *
 * Pictures live on the private "local" disk (same as PDL photos) and are only
 * served to signed-in staff through photo(); they are never publicly reachable.
 */
class MyProfileController extends Controller
{
    // A real picture (checked from the file's content, not just its name),
    // JPG/PNG/WEBP, at most 2 MB, not tiny.
    private const PHOTO_RULES = ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=100,min_height=100'];

    /** GET account/profile */
    public function show(): View
    {
        $account = auth()->user();
        $account->loadMissing(['staffProfile', 'role']);

        return view('account.profile', [
            'account' => $account,
            'profile' => $account->staffProfile,
        ]);
    }

    /** POST account/profile/photo */
    public function update(Request $request): RedirectResponse
    {
        $profile = $this->ownProfile();

        $request->validate(['photo' => self::PHOTO_RULES], [
            'photo.required' => 'Choose a picture first.',
            'photo.image' => 'The picture must be a JPG, PNG or WEBP image.',
            'photo.mimes' => 'The picture must be a JPG, PNG or WEBP image.',
            'photo.max' => 'That picture is too large. The maximum size is 2 MB.',
            'photo.uploaded' => 'The picture could not be uploaded. Try a smaller one (up to 2 MB).',
            'photo.dimensions' => 'The picture is too small. Use one that is at least 100 x 100 pixels.',
        ]);

        $old = $profile->photo_path;
        $new = $request->file('photo')->store('staff-photos', 'local');

        $profile->update(['photo_path' => $new]);

        // Only after the new one is saved, so a failed upload never leaves the officer with nothing.
        if ($old && $old !== $new) {
            Storage::disk('local')->delete($old);
        }

        return redirect()->route('account.profile')->with('status', 'Your profile picture was updated.');
    }

    /** DELETE account/profile/photo */
    public function destroy(): RedirectResponse
    {
        $profile = $this->ownProfile();
        $old = $profile->photo_path;

        $profile->update(['photo_path' => null]);

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return redirect()->route('account.profile')->with('status', 'Your profile picture was removed.');
    }

    /** GET account/photo/{staff} — a staff picture, for signed-in staff only. */
    public function photo(StaffProfile $staff): Response
    {
        abort_unless($staff->photo_path && Storage::disk('local')->exists($staff->photo_path), 404);

        return Storage::disk('local')->response($staff->photo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ownProfile(): StaffProfile
    {
        $profile = auth()->user()->staffProfile;

        abort_unless($profile, 404, 'This account has no staff profile.');

        return $profile;
    }
}
