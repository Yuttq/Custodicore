@extends('layouts.admin')

@section('title', 'User Management')

@section('content')
    @if (session('setup_link'))
        {{-- Shown once, right after creating / re-sending: hand this link to the officer yourself if email or SMS is not set up. --}}
        <div class="cc-card border border-border">
            <p class="text-card-title">Setup link for {{ session('setup_link.name') }}</p>
            <p class="mt-xs text-metadata text-text-secondary">
                Give this link only to {{ session('setup_link.name') }}. It works once, expires in {{ session('setup_link.hours') }} hours,
                and is not shown again. Sending a new link cancels this one.
            </p>
            <div class="mt-sm flex flex-col gap-sm sm:flex-row">
                <input type="text" id="setup-link-url" readonly value="{{ session('setup_link.url') }}"
                       onfocus="this.select()"
                       class="h-[38px] w-full rounded-button border border-border bg-background px-md text-body" />
                <button type="button" id="setup-link-copy" class="cc-btn-primary shrink-0">Copy Setup Link</button>
            </div>
            <script>
                document.getElementById('setup-link-copy').addEventListener('click', function () {
                    var box = document.getElementById('setup-link-url');
                    var btn = this;
                    function done() { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy Setup Link'; }, 2000); }
                    box.select();
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(box.value).then(done, function () { document.execCommand('copy'); done(); });
                    } else {
                        document.execCommand('copy'); done();
                    }
                });
            </script>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-card-title">Staff Accounts</p>
                <p class="text-metadata text-text-secondary">
                    @if ($filterLabel)
                        <span class="font-semibold text-text-primary">{{ $filterLabel }}</span> ·
                    @endif
                    @if ($search)
                        {{ $accounts->total() }} of {{ $totalAccounts }} accounts match "{{ $search }}"
                    @else
                        {{ $accounts->total() }} accounts, sorted by last name
                    @endif
                    @if ($filter)
                        · <a href="{{ route('admin.users.index') }}" class="font-semibold text-primary-navy hover:underline">Show all</a>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-sm">
                {{-- Working search: last name, first name, middle name, employee #, email, username or mobile number. --}}
                <form method="GET" action="{{ route('admin.users.index') }}" class="flex items-center gap-sm">
                    @if ($filter)<input type="hidden" name="filter" value="{{ $filter }}" />@endif
                    <input type="search" name="q" value="{{ $search }}" maxlength="100"
                           placeholder="Search name, email, username, employee # or mobile…"
                           class="h-[38px] w-full rounded-button border border-border bg-white px-md text-body sm:w-72" />
                    <button type="submit" class="cc-btn-secondary shrink-0">Search</button>
                    @if ($search)
                        <a href="{{ route('admin.users.index', array_filter(['filter' => $filter])) }}" class="cc-btn-secondary shrink-0">Clear</a>
                    @endif
                </form>
                <button type="button" class="cc-btn-primary shrink-0" onclick="document.getElementById('new-account-modal').showModal()">
                    + Add Officer
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Employee #</th>
                        <th class="py-sm pr-md">Name</th>
                        <th class="py-sm pr-md">Role</th>
                        <th class="py-sm pr-md">Sign-in (email / username)</th>
                        <th class="py-sm pr-md">Mobile</th>
                        <th class="py-sm pr-md">Last Login</th>
                        <th class="py-sm pr-md">Status</th>
                        <th class="py-sm pr-md">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($accounts as $account)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $account['employee_number'] }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $account['full_name'], 'size' => 'h-8 w-8', 'photo' => $account['photo'] ?? null])
                                    <span class="font-semibold">{{ $account['list_name'] }}</span>
                                </div>
                            </td>
                            <td class="py-sm pr-md">{{ \App\Models\Role::label($account['role_name']) }}</td>
                            <td class="py-sm pr-md text-text-secondary">
                                {{ $account['email'] }}
                                <span class="block text-metadata">Username: {{ $account['username'] ?: '—' }}</span>
                                @if ($account['personal_email'] && strcasecmp($account['personal_email'], $account['email']) !== 0)
                                    {{-- Older accounts: the sign-in email differs from the officer's own email. --}}
                                    <span class="block text-metadata">Personal email: {{ $account['personal_email'] }}</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $account['contact_number'] ?? '—' }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $account['last_login_at'] }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $account['display_status']])</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-xs">
                                    <button type="button" class="cc-btn-ghost" onclick="document.getElementById('edit-account-modal-{{ $account['id'] }}').showModal()">
                                        Edit
                                    </button>

                                    @if ($account['invite'] !== null)
                                        {{-- Not set up yet: Activate/Deactivate makes no sense (nobody knows the password). --}}
                                        <form method="POST" action="{{ route('admin.users.resend-invite', $account['id']) }}"
                                              data-confirm="Send a new setup link to {{ $account['full_name'] }}'s email?"
                                              data-confirm-title="Resend Invite" data-confirm-ok="Yes, send" data-confirm-cancel="No">
                                            @csrf
                                            <button type="submit" class="cc-btn-ghost">Resend Invite</button>
                                        </form>
                                    @elseif (! $account['is_self'])
                                        {{-- Password popup replaces the plain confirm(): same question, plus re-entering your own password. --}}
                                        <form method="POST" action="{{ route('admin.users.toggle-status', $account['id']) }}"
                                              data-password-confirm="{{ $account['status'] === 'active' ? 'Disable' : 'Enable' }} {{ $account['full_name'] }}'s account? Enter your password to confirm.">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="cc-btn-ghost">
                                                {{ $account['status'] === 'active' ? 'Disable Account' : 'Enable Account' }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-lg text-center text-text-secondary">
                                {{ $search || $filter ? 'No staff match your search.' : 'No staff accounts yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['paginator' => $accounts, 'label' => 'accounts'])
    </div>

    {{-- Live duplicate-email check (Register + Edit Officer). Warns as soon as the Warden
         leaves the email field; the server still re-checks on save (StaffAccountRequest). --}}
    <script>
        (function () {
            function msgFor(input) {
                return input.parentElement.querySelector('.email-check-msg');
            }
            function show(input, text) {
                var el = msgFor(input);
                if (!el) return;
                el.textContent = text || '';
                el.style.display = text ? '' : 'none';
                input.setCustomValidity(text || '');
                input.style.borderColor = text ? '#DC2626' : '';
            }
            // Resolves true when the email is free (or cannot be checked right now —
            // the server check on save still applies), false when it is taken.
            window.ccCheckStaffEmail = function (input) {
                var email = (input.value || '').trim().toLowerCase();
                if (!email) return Promise.resolve(true);
                show(input, '');
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return Promise.resolve(true);
                var url = input.getAttribute('data-email-check') + '?email=' + encodeURIComponent(email);
                var ignore = input.getAttribute('data-email-ignore');
                if (ignore) url += '&ignore=' + encodeURIComponent(ignore);
                return fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : { available: null }; })
                    .then(function (data) {
                        if (data.available === false) { show(input, data.message || 'This email is already used by another account.'); return false; }
                        return true;
                    })
                    .catch(function () { return true; });
            };
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('input[data-email-check]').forEach(function (input) {
                    var timer;
                    // A server error from the last save (shown under the field) stays until edited.
                    var initial = msgFor(input);
                    if (initial && initial.textContent.trim()) { initial.style.display = ''; input.style.borderColor = '#DC2626'; }
                    input.addEventListener('input', function () {
                        show(input, '');
                        clearTimeout(timer);
                        timer = setTimeout(function () { window.ccCheckStaffEmail(input); }, 600);
                    });
                    input.addEventListener('blur', function () { clearTimeout(timer); window.ccCheckStaffEmail(input); });
                    // Edit Officer submits directly: stop it while the email is taken.
                    if (input.hasAttribute('data-email-ignore')) {
                        input.form.addEventListener('submit', function (e) {
                            var el = msgFor(input);
                            if (el && el.style.display !== 'none' && el.textContent.trim()) { e.preventDefault(); input.focus(); }
                        });
                    }
                });
            });
        })();
    </script>

    {{-- "Register Officer" — the admin's only way to create data on this dashboard. --}}
    <dialog id="new-account-modal" class="cc-modal">
        <form method="POST" action="{{ route('admin.users.store') }}" autocomplete="off">
            @csrf
            <div class="mb-md flex items-center justify-between">
                <p class="text-card-title">Register BJMP Officer</p>
                <button type="button" class="cc-modal-close" onclick="document.getElementById('new-account-modal').close()">
                    Close
                </button>
            </div>

            @if ($errors->any() && ! old('_edit_id') && ! $errors->has('current_password'))
                <div class="mb-md rounded-sm border-l-4 border-l-danger bg-danger/5 p-sm text-metadata text-danger">
                    <ul class="list-disc pl-md">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php $isNew = ! old('_edit_id'); @endphp
            <div class="space-y-sm">
                <div class="grid grid-cols-1 gap-sm sm:grid-cols-2">
                    <div>
                        <label class="cc-label" for="new-last-name">Last Name</label>
                        <input id="new-last-name" type="text" name="last_name" value="{{ $isNew ? old('last_name') : '' }}" required maxlength="100" class="cc-input" placeholder="e.g. Dela Cruz" />
                    </div>
                    <div>
                        <label class="cc-label" for="new-first-name">First Name</label>
                        <input id="new-first-name" type="text" name="first_name" value="{{ $isNew ? old('first_name') : '' }}" required maxlength="100" class="cc-input" placeholder="e.g. Juan" />
                    </div>
                    <div>
                        <label class="cc-label" for="new-middle-name">Middle Name <span class="normal-case">(optional)</span></label>
                        <input id="new-middle-name" type="text" name="middle_name" value="{{ $isNew ? old('middle_name') : '' }}" maxlength="100" class="cc-input" placeholder="e.g. Dizon" />
                    </div>
                    <div>
                        <label class="cc-label" for="new-suffix">Suffix <span class="normal-case">(optional)</span></label>
                        <select id="new-suffix" name="suffix" class="cc-input">
                            <option value="">None</option>
                            @foreach ($suffixes as $suffix)
                                <option value="{{ $suffix }}" @selected($isNew && old('suffix') === $suffix)>{{ $suffix }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="cc-label" for="new-role">Role</label>
                    <select id="new-role" name="role_name" required class="cc-input">
                        <option value="" @selected(! ($isNew && old('role_name'))) disabled>Select a role…</option>
                        <option value="System Administrator/Warden" @selected($isNew && old('role_name') === 'System Administrator/Warden')>Admin</option>
                        <option value="Record Officer" @selected($isNew && old('role_name') === 'Record Officer')>Record Officer</option>
                        <option value="Front Desk Officer" @selected($isNew && old('role_name') === 'Front Desk Officer')>Front Desk Officer</option>
                    </select>
                </div>
                <div class="grid grid-cols-1 gap-sm sm:grid-cols-2">
                    <div>
                        <label class="cc-label" for="new-personal-email">Email <span class="normal-case">(sign-in &amp; setup link)</span></label>
                        <input id="new-personal-email" type="email" name="personal_email" value="{{ $isNew ? old('personal_email') : '' }}" required maxlength="100" class="cc-input" placeholder="e.g. juan.delacruz@gmail.com"
                               data-email-check="{{ route('admin.users.check-email') }}" />
                        <p class="email-check-msg text-metadata text-danger" role="alert" style="display:none; margin-top:4px;">@if ($isNew && $errors->has('personal_email')){{ $errors->first('personal_email') }}@endif</p>
                    </div>
                    <div>
                        <label class="cc-label" for="new-contact">Mobile Number</label>
                        <input id="new-contact" type="tel" inputmode="tel" name="contact_number" value="{{ $isNew ? old('contact_number') : '' }}" required maxlength="20" class="cc-input" placeholder="0917 123 4567" />
                    </div>
                </div>
                <p class="text-metadata text-text-secondary">The officer's own email that they check. The setup link is sent there, and they sign in with this email or with the unique username they choose when they set up their account.</p>

                <div class="rounded-sm bg-background p-sm text-metadata text-text-secondary">
                    <p class="font-semibold text-primary-navy">What happens next</p>
                    <ul style="margin:4px 0 0; padding-left:18px; list-style:disc;">
                        <li>After you create the account, a one-time setup link is sent to the officer's email.</li>
                        <li>The officer uses the link to choose their own password.</li>
                        <li>The link expires in {{ config('auth.staff_invitation.expire_hours', 72) }} hours.</li>
                    </ul>
                </div>
            </div>

            {{-- Step 1: review. Step 2: the Warden confirms, and only then is the account created and the email sent. --}}
            <div id="register-actions" class="mt-lg flex justify-end gap-sm">
                <button type="button" class="cc-btn-secondary" onclick="document.getElementById('new-account-modal').close()">Cancel</button>
                <button type="button" id="register-review" class="cc-btn-primary">Continue</button>
            </div>

            <div id="register-confirm" class="mt-lg rounded-sm border border-border bg-background p-md" style="display:none;">
                <p class="text-card-title">Send the setup link now?</p>
                <p class="mt-xs text-body">
                    The account for <strong id="register-confirm-name"></strong> will be created and the one-time setup link will be
                    emailed to <strong id="register-confirm-email"></strong> right away. They will sign in with this email or with the username they choose. You do not need to send anything yourself.
                </p>
                <p class="mt-xs text-metadata text-text-secondary">Check that the email is correct and belongs to the officer.</p>
                @unless ($mailConnected)
                    <p class="mt-sm rounded-sm border-l-4 border-l-warning bg-warning/5 p-sm text-metadata">
                        Email sending is not connected yet (mail is in log mode), so nothing will actually be delivered.
                        After you confirm, you will get the link on screen to copy and give to the officer.
                    </p>
                @endunless
                <div class="mt-md flex justify-end gap-sm">
                    <button type="button" id="register-back" class="cc-btn-secondary">Back</button>
                    <button type="submit" class="cc-btn-primary">Create &amp; Send Invite</button>
                </div>
            </div>
        </form>

        <script>
            (function () {
                var dialog = document.getElementById('new-account-modal');
                var form = dialog.querySelector('form');
                var actions = document.getElementById('register-actions');
                var confirmBox = document.getElementById('register-confirm');

                function showConfirm(on) {
                    actions.style.display = on ? 'none' : '';
                    confirmBox.style.display = on ? '' : 'none';
                }

                var emailInput = document.getElementById('new-personal-email');
                var reviewBtn = document.getElementById('register-review');

                function review() {
                    if (!form.reportValidity()) return; // shows the usual "fill this in" hints
                    var v = function (n) { return (form.elements[n] ? form.elements[n].value : '').trim(); };

                    // Do not go on to "Send the setup link now?" while the email is already in use.
                    reviewBtn.disabled = true;
                    window.ccCheckStaffEmail(emailInput).then(function (ok) {
                        reviewBtn.disabled = false;
                        if (!ok) { emailInput.focus(); return; }
                        var name = [v('first_name'), v('middle_name'), v('last_name'), v('suffix')].filter(Boolean).join(' ');
                        document.getElementById('register-confirm-name').textContent = name;
                        document.getElementById('register-confirm-email').textContent = v('personal_email').toLowerCase();
                        showConfirm(true);
                    });
                }

                document.getElementById('register-review').addEventListener('click', review);
                document.getElementById('register-back').addEventListener('click', function () { showConfirm(false); });

                // Pressing Enter in a field must not skip the confirmation step.
                form.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON') {
                        e.preventDefault();
                        if (confirmBox.style.display === 'none') review();
                    }
                });

                dialog.addEventListener('close', function () { showConfirm(false); });
            })();
        </script>
    </dialog>

    {{-- "Edit" — the admin's only way to change existing data, when the
         officer whose account it is has asked for a correction. One modal
         per row, pre-filled with that account's current details. --}}
    @foreach ($accounts as $account)
        @php $mine = old('_edit_id') == $account['id']; @endphp
        <dialog id="edit-account-modal-{{ $account['id'] }}" class="cc-modal">
            {{-- Final step on save: the admin re-enters their own password (data-password-confirm → partials/password-confirm). --}}
            <form method="POST" action="{{ route('admin.users.update', $account['id']) }}" autocomplete="off"
                  data-password-confirm="Save changes to {{ $account['full_name'] }}'s account? Enter your password to confirm.">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_edit_id" value="{{ $account['id'] }}" />

                <div class="mb-md flex items-center justify-between">
                    <p class="text-card-title">Update Officer Details</p>
                    <button type="button" class="cc-modal-close" onclick="document.getElementById('edit-account-modal-{{ $account['id'] }}').close()">
                        Close
                    </button>
                </div>

                {{-- The admin's own password was rejected by the `reauth` middleware: nothing was saved.
                     The typed changes are kept (old()) so they can retry; the notice is removed when
                     the window is closed or the form is submitted again. Too-many-attempts keeps its own wording. --}}
                @if ($mine && $errors->has('current_password'))
                    @php $pwError = $errors->first('current_password'); @endphp
                    <div class="mb-md rounded-sm border-l-4 border-l-danger bg-danger/5 p-sm text-metadata text-danger"
                         role="alert" data-reauth-error>
                        {{ str_starts_with($pwError, 'Too many') ? $pwError : 'Incorrect password. No changes were saved. Please try again.' }}
                    </div>
                @endif

                @if ($mine && $errors->any() && ! $errors->has('current_password'))
                    <div class="mb-md rounded-sm border-l-4 border-l-danger bg-danger/5 p-sm text-metadata text-danger">
                        <ul class="list-disc pl-md">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="space-y-sm">
                    <div class="grid grid-cols-1 gap-sm sm:grid-cols-2">
                        <div>
                            <label class="cc-label">Last Name</label>
                            <input type="text" name="last_name" value="{{ $mine ? old('last_name') : $account['last_name'] }}" required maxlength="100" class="cc-input" />
                        </div>
                        <div>
                            <label class="cc-label">First Name</label>
                            <input type="text" name="first_name" value="{{ $mine ? old('first_name') : $account['first_name'] }}" required maxlength="100" class="cc-input" />
                        </div>
                        <div>
                            <label class="cc-label">Middle Name <span class="normal-case">(optional)</span></label>
                            <input type="text" name="middle_name" value="{{ $mine ? old('middle_name') : $account['middle_name'] }}" maxlength="100" class="cc-input" />
                        </div>
                        <div>
                            <label class="cc-label">Suffix <span class="normal-case">(optional)</span></label>
                            @php $currentSuffix = $mine ? old('suffix') : $account['suffix']; @endphp
                            <select name="suffix" class="cc-input">
                                <option value="">None</option>
                                @foreach ($suffixes as $suffix)
                                    <option value="{{ $suffix }}" @selected($currentSuffix === $suffix)>{{ $suffix }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="cc-label">Role</label>
                        @php $currentRole = $mine ? old('role_name') : $account['role_name']; @endphp
                        <select name="role_name" required class="cc-input" @if ($account['is_self']) title="You cannot change your own role" @endif>
                            <option value="System Administrator/Warden" @selected($currentRole === 'System Administrator/Warden')>Admin</option>
                            <option value="Record Officer" @selected($currentRole === 'Record Officer')>Record Officer</option>
                            <option value="Front Desk Officer" @selected($currentRole === 'Front Desk Officer')>Front Desk Officer</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 gap-sm sm:grid-cols-2">
                        <div>
                            <label class="cc-label">Email <span class="normal-case">(sign-in &amp; setup link)</span></label>
                            <input type="email" name="personal_email" value="{{ $mine ? old('personal_email') : $account['personal_email'] }}" maxlength="100" class="cc-input" placeholder="e.g. juan.delacruz@gmail.com"
                                   data-email-check="{{ route('admin.users.check-email') }}" data-email-ignore="{{ $account['id'] }}" />
                            <p class="email-check-msg text-metadata text-danger" role="alert" style="display:none; margin-top:4px;"></p>
                        </div>
                        <div>
                            <label class="cc-label">Mobile Number</label>
                            <input type="tel" inputmode="tel" name="contact_number" value="{{ $mine ? old('contact_number') : $account['contact_number'] }}" required maxlength="20" class="cc-input" placeholder="0917 123 4567" />
                        </div>
                    </div>
                    <p class="text-metadata text-text-secondary">
                        Signs in with: <strong>{{ $account['email'] }}</strong> or username <strong>{{ $account['username'] ?: '—' }}</strong>.
                        Changing the email here changes the address they sign in with. Leave it as is to keep the current sign-in.
                    </p>
                    @if ($account['invite'] !== null)
                        <p class="text-metadata text-text-secondary">This officer has not set up their account yet. If you change the personal email, a new setup link is sent to the new address.</p>
                    @endif
                </div>

                <div class="mt-lg flex justify-end gap-sm">
                    <button type="button" class="cc-btn-secondary" onclick="document.getElementById('edit-account-modal-{{ $account['id'] }}').close()">Cancel</button>
                    <button type="submit" class="cc-btn-primary">Save Changes</button>
                </div>
            </form>
        </dialog>
    @endforeach

    @if ($errors->any())
        @if (old('_edit_id'))
            <script>
                (function () {
                    var dlg = document.getElementById('edit-account-modal-{{ (int) old('_edit_id') }}');
                    if (!dlg) return;
                    // Clear the "incorrect password" notice once the admin closes the window or retries.
                    function clearReauthError() {
                        dlg.querySelectorAll('[data-reauth-error]').forEach(function (el) { el.remove(); });
                    }
                    dlg.addEventListener('close', clearReauthError);
                    // Click, not submit: partials/password-confirm stops the submit event (capture phase)
                    // to ask for the password. Enter in a field also "clicks" the submit button.
                    dlg.querySelector('button[type="submit"]').addEventListener('click', clearReauthError);
                    dlg.showModal();
                })();
            </script>
        @elseif (! $errors->has('current_password'))
            {{-- A rejected password on Activate/Deactivate has no modal to reopen — the
                 banner from partials/password-confirm explains it instead. --}}
            <script>document.getElementById('new-account-modal').showModal();</script>
        @endif
    @endif
@endsection
