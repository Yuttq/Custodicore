{{--
    "Close record – exit not recorded" for one gate record still marked
    inside after its visit ended (Overstayed). Not a check-out: no exit time
    is recorded. The page must include partials.password-confirm, which
    collects the officer's password (enforced by the `reauth` middleware).

    $checkin  App\Models\VisitCheckin (status checked_in)
--}}
<details class="mt-xs">
    <summary class="cursor-pointer text-metadata font-semibold text-primary-teal">Close record – exit not recorded</summary>
    <form method="POST"
          action="{{ route('frontdesk.checkin-checkout.close-unrecorded', $checkin->checkin_id) }}"
          class="mt-xs space-y-xs rounded-lg border border-border p-sm text-left"
          data-password-confirm="Close this record without a check-out? No exit time will be recorded. Enter your password to confirm.">
        @csrf
        <p class="text-metadata text-text-secondary">
            Use only if the visitor has already left and their check-out was not recorded.
        </p>
        <label class="block text-metadata font-semibold" for="close-reason-{{ $checkin->checkin_id }}">Explanation</label>
        <textarea id="close-reason-{{ $checkin->checkin_id }}" name="reason" required minlength="10" maxlength="150" rows="2"
                  class="w-full rounded-lg border border-border p-xs text-body"
                  placeholder="e.g. Visitor left at the end of visiting hours; check-out was not recorded."></textarea>
        <fieldset>
            <legend class="text-metadata font-semibold">Is the visitor's surrendered ID still at the desk?</legend>
            <label class="mr-md text-metadata"><input type="radio" name="id_at_desk" value="yes" required> Yes</label>
            <label class="text-metadata"><input type="radio" name="id_at_desk" value="no"> No</label>
        </fieldset>
        <button type="submit" class="cc-btn-secondary">Close record</button>
    </form>
</details>
