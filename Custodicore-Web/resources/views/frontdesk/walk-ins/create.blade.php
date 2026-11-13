@extends('layouts.frontdesk')

@section('title', 'New Walk-in')

@section('content')
    {{-- Phase 1: look for the visitor first, so a returning walk-in is not registered twice. --}}
    <div class="cc-card mb-lg">
        <p class="text-card-title">Already registered?</p>
        <p class="text-metadata text-text-secondary">Search by name before registering. A returning walk-in gets a new arrival on their record instead.</p>
        <form method="GET" action="{{ route('frontdesk.walk-ins.create') }}" class="mt-md flex flex-wrap items-end gap-sm" role="search">
            <div class="min-w-[260px] flex-1">
                <label for="visitor_search" class="cc-label">Visitor name</label>
                <input id="visitor_search" name="q" value="{{ $search }}" maxlength="100" placeholder="e.g. Lourdes Reyes" class="cc-input" />
            </div>
            <button type="submit" class="cc-btn-secondary">Search</button>
            @if ($searched)
                <a href="{{ route('frontdesk.walk-ins.create') }}" class="cc-btn-secondary">Clear</a>
            @endif
        </form>

        @if ($searched)
            @if ($existing->isEmpty())
                <p class="mt-md text-body">No visitor named "{{ $search }}" is registered. Register them below.</p>
            @else
                <ul class="mt-md divide-y divide-border rounded-lg border border-border">
                    @foreach ($existing as $row)
                        @php
                            $found = $row['visitor'];
                            $foundStatus = $row['status'];
                        @endphp
                        <li class="flex flex-wrap items-center justify-between gap-sm p-sm">
                            <div>
                                <p class="text-body font-semibold">{{ $found->full_name }}</p>
                                <p class="text-metadata text-text-secondary">
                                    Born {{ $found->date_of_birth?->format('M j, Y') ?? '—' }} ·
                                    {{ $found->isWalkIn() ? 'Walk-in' : 'App user' }}
                                    @if ($foundStatus) · {{ $foundStatus['label'] }} @endif
                                </p>
                            </div>
                            @if ($found->isWalkIn())
                                <a href="{{ route('frontdesk.walk-ins.show', $found) }}" class="cc-btn-primary">Open record</a>
                            @else
                                <a href="{{ route('frontdesk.visitor-lookup.show', $found) }}" class="cc-btn-secondary">View visitor</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </div>

    <form method="POST" action="{{ route('frontdesk.walk-ins.store') }}" enctype="multipart/form-data" id="walkInForm" class="space-y-lg" novalidate>
        @csrf

        @if ($errors->any())
            <div class="cc-card border-l-4 border-l-warning text-body">
                Some details need fixing: see the messages in red below. Photos and files are not kept after an error. Please capture or upload them again.
            </div>
        @endif

        @if ($errors->has('duplicate'))
            <div class="cc-card border-l-4 border-l-danger text-body">
                <p class="font-semibold">{{ $errors->first('duplicate') }}</p>
                @if (session('duplicate_visitor_id'))
                    <p class="mt-xs">
                        <a href="{{ route('frontdesk.walk-ins.show', session('duplicate_visitor_id')) }}" class="font-semibold text-primary-teal hover:underline">Open the existing visitor</a>
                        — if it is the same person, use that record instead of registering again.
                    </p>
                @endif
                <label class="mt-sm flex items-center gap-xs">
                    <input type="checkbox" name="not_duplicate" value="1" />
                    I checked: this is a different person with the same name and birthdate.
                </label>
            </div>
        @endif

        {{-- 1. Visitor Registration --}}
        <div class="cc-card">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <div>
                    <p class="text-card-title">1 · Visitor Registration</p>
                    <p class="text-metadata text-text-secondary">Record the visitor’s personal and contact details.</p>
                </div>
                <span class="text-metadata text-text-secondary">Visitor type: <span class="cc-chip cc-chip-info">Walk-In</span></span>
            </div>
            <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-3">
                <div>
                    <label for="first_name" class="cc-label">First name *</label>
                    <input id="first_name" name="first_name" value="{{ old('first_name') }}" maxlength="50" autocomplete="off" @class(['cc-input', 'border-danger' => $errors->has('first_name')]) />
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'first_name'])
                </div>
                <div>
                    <label for="last_name" class="cc-label">Last name *</label>
                    <input id="last_name" name="last_name" value="{{ old('last_name') }}" maxlength="50" autocomplete="off" @class(['cc-input', 'border-danger' => $errors->has('last_name')]) />
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'last_name'])
                </div>
                <div>
                    <label for="gender" class="cc-label">Sex *</label>
                    <select id="gender" name="gender" @class(['cc-input', 'border-danger' => $errors->has('gender')])>
                        <option value="">Select…</option>
                        <option value="male" @selected(old('gender') === 'male')>Male</option>
                        <option value="female" @selected(old('gender') === 'female')>Female</option>
                    </select>
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'gender'])
                </div>
                <div>
                    <div class="grid grid-cols-[1fr_88px] gap-sm">
                        <div>
                            <label for="date_of_birth" class="cc-label">Date of birth *</label>
                            <input type="date" id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth') }}" max="{{ now()->format('Y-m-d') }}" @class(['cc-input', 'border-danger' => $errors->has('date_of_birth')]) />
                        </div>
                        <div>
                            <label for="age" class="cc-label">Age</label>
                            {{-- Worked out from the birthdate; not sent. --}}
                            <input id="age" readonly tabindex="-1" placeholder="—" class="cc-input bg-background" />
                        </div>
                    </div>
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'date_of_birth'])
                </div>
                <div>
                    <label for="contact_number" class="cc-label">Contact number *</label>
                    <input id="contact_number" name="contact_number" value="{{ old('contact_number') }}" inputmode="tel" maxlength="16" placeholder="09171234567" data-phone @class(['cc-input', 'border-danger' => $errors->has('contact_number')]) />
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'contact_number'])
                </div>
                <div>
                    <label for="email" class="cc-label">Email (optional)</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="100" @class(['cc-input', 'border-danger' => $errors->has('email')]) />
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'email'])
                </div>
                <div class="md:col-span-3">
                    <label for="address" class="cc-label">Address *</label>
                    <input id="address" name="address" value="{{ old('address') }}" maxlength="200" placeholder="House no., street, barangay, city or municipality, province" @class(['cc-input', 'border-danger' => $errors->has('address')]) />
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'address'])
                </div>
            </div>

            <div id="guardianSection" class="mt-md hidden rounded-lg bg-background p-md">
                <p class="text-body font-semibold">Minor visitor: accompanying parent or guardian *</p>
                <p class="text-metadata text-text-secondary">Visitors under 18 must be accompanied by an adult, and must be at least 3 years old.</p>
                <div class="mt-sm grid grid-cols-1 gap-md md:grid-cols-3">
                    <div>
                        <label for="guardian_full_name" class="cc-label">Guardian's full name</label>
                        <input id="guardian_full_name" name="guardian_full_name" value="{{ old('guardian_full_name') }}" maxlength="150" @class(['cc-input', 'border-danger' => $errors->has('guardian_full_name')]) />
                        @include('frontdesk.walk-ins.partials.field-error', ['name' => 'guardian_full_name'])
                    </div>
                    <div>
                        <label for="guardian_relationship" class="cc-label">Relationship to visitor</label>
                        <input id="guardian_relationship" name="guardian_relationship" value="{{ old('guardian_relationship') }}" maxlength="100" placeholder="e.g. Mother" @class(['cc-input', 'border-danger' => $errors->has('guardian_relationship')]) />
                        @include('frontdesk.walk-ins.partials.field-error', ['name' => 'guardian_relationship'])
                    </div>
                    <div>
                        <label for="guardian_contact_number" class="cc-label">Guardian's contact number</label>
                        <input id="guardian_contact_number" name="guardian_contact_number" value="{{ old('guardian_contact_number') }}" inputmode="tel" maxlength="16" placeholder="09171234567" data-phone @class(['cc-input', 'border-danger' => $errors->has('guardian_contact_number')]) />
                        @include('frontdesk.walk-ins.partials.field-error', ['name' => 'guardian_contact_number'])
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Photo & Documents --}}
        <div class="cc-card">
            <p class="text-card-title">2 · Photo &amp; Documents</p>
            <p class="text-metadata text-text-secondary">Capture the visitor’s photo and upload any required documents.</p>

            <div class="mt-md">
                <p class="cc-label">Photo of the visitor *</p>
                <p class="text-metadata text-text-secondary">Face the camera, no cap or sunglasses. JPG or PNG, up to 5 MB.</p>
                <div class="mt-sm">@include('frontdesk.walk-ins.partials.capture-field', ['name' => 'photo', 'id' => 'photo', 'pdf' => false])</div>
            </div>

            <div class="mt-lg border-t border-border pt-md">
                <p class="cc-label">Valid ID <span id="idRequiredMark">*</span></p>
                <p id="idMinorNote" class="hidden text-metadata text-text-secondary">Optional for minors: their birth certificate (under Visit Details) stands in for an ID.</p>
                <div class="mt-sm grid grid-cols-1 gap-md md:grid-cols-2">
                    <div>
                        <label for="id_type" class="cc-label">ID type</label>
                        <select id="id_type" name="id_type" @class(['cc-input', 'border-danger' => $errors->has('id_type')])>
                            <option value="">Select…</option>
                            @foreach ($idTypes as $key => $label)
                                <option value="{{ $key }}" @selected(old('id_type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @include('frontdesk.walk-ins.partials.field-error', ['name' => 'id_type'])
                    </div>
                </div>
                <p class="mt-sm text-metadata text-text-secondary">Copy of the ID: JPG, PNG or PDF, up to 5 MB.</p>
                <div class="mt-xs">@include('frontdesk.walk-ins.partials.capture-field', ['name' => 'government_id', 'id' => 'government_id', 'pdf' => true])</div>
            </div>
        </div>

        {{-- 3. Visit Details --}}
        <div class="cc-card">
            <p class="text-card-title">3 · Visit Details</p>
            <p class="text-metadata text-text-secondary">Select the PDL to be visited and the purpose of the visit.</p>
            <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                <div class="relative md:col-span-2">
                    <label for="pdl_search" class="cc-label">PDL to visit *</label>
                    <input id="pdl_search" type="text" autocomplete="off" maxlength="100" role="combobox" aria-expanded="false" aria-controls="pdlResults"
                           value="{{ $selectedPdl ? $selectedPdl->full_name.' ('.$selectedPdl->pdl_number.')' : '' }}"
                           placeholder="Type part of the name, alias or PDL number" data-url="{{ route('frontdesk.walk-ins.pdl-search') }}"
                           @class(['cc-input', 'border-danger' => $errors->has('pdl_id')]) />
                    <input type="hidden" id="pdl_id" name="pdl_id" value="{{ $selectedPdl?->pdl_id }}" data-status="{{ $selectedPdl?->custody_status }}" />
                    <ul id="pdlResults" role="listbox" hidden
                        class="absolute z-10 mt-xs max-h-64 w-full overflow-y-auto rounded-lg border border-border bg-card shadow-lg"></ul>
                    <p id="pdlChosen" class="mt-xs text-metadata {{ $selectedPdl ? 'font-semibold text-success' : 'text-text-secondary' }}" aria-live="polite">
                        {{ $selectedPdl ? "Selected: {$selectedPdl->full_name} · {$selectedPdl->pdl_number}" : 'Search, then select the correct PDL from the list.' }}
                    </p>
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'pdl_id'])
                </div>
                <div>
                    <label for="relationship_type" class="cc-label">Relationship to the PDL *</label>
                    <select id="relationship_type" name="relationship_type" @class(['cc-input', 'border-danger' => $errors->has('relationship_type')])>
                        <option value="">Select…</option>
                        @foreach ($relationshipTypes as $key => $type)
                            <option value="{{ $key }}" @selected(old('relationship_type') === $key)>{{ $type['label'] }}</option>
                        @endforeach
                    </select>
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'relationship_type'])
                    <p id="relationshipDescription" class="mt-xs text-metadata text-text-secondary"></p>
                    <p class="mt-xs text-metadata text-text-secondary">Friends and other non-relatives are not allowed to visit.</p>
                </div>
                <div>
                    <label for="visit_purpose_type" class="cc-label">Purpose of visit *</label>
                    @php $oldPurpose = old('visit_purpose'); $isOther = old('visit_purpose_type') === 'other' || ($oldPurpose && ! in_array($oldPurpose, $purposes, true)); @endphp
                    <select id="visit_purpose_type" name="visit_purpose_type" @class(['cc-input', 'border-danger' => $errors->has('visit_purpose_type')])>
                        <option value="">Select…</option>
                        @foreach ($purposes as $purpose)
                            <option value="{{ $purpose }}" @selected(! $isOther && $oldPurpose === $purpose)>{{ $purpose }}</option>
                        @endforeach
                        <option value="other" @selected($isOther)>Other</option>
                    </select>
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'visit_purpose_type'])
                    {{-- The value sent: the chosen type, or what is typed for Other. --}}
                    <input type="hidden" id="visit_purpose" name="visit_purpose" value="{{ $oldPurpose }}" />
                    <input id="visit_purpose_other" value="{{ $isOther ? $oldPurpose : '' }}" maxlength="150" placeholder="Please specify the purpose" aria-label="Specify the purpose of the visit"
                           @class(['cc-input mt-xs', 'hidden' => ! $isOther, 'border-danger' => $errors->has('visit_purpose')]) />
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'visit_purpose'])
                </div>
            </div>

            <fieldset id="childrenTogether" class="mt-md hidden">
                <legend class="cc-label">Do the visitor and the PDL have children together? *</legend>
                <div class="mt-xs flex gap-md text-body">
                    <label class="flex items-center gap-xs"><input type="radio" name="has_children_together" value="1" @checked(old('has_children_together') === '1') /> Yes</label>
                    <label class="flex items-center gap-xs"><input type="radio" name="has_children_together" value="0" @checked(old('has_children_together') === '0') /> No</label>
                </div>
                @include('frontdesk.walk-ins.partials.field-error', ['name' => 'has_children_together'])
            </fieldset>

            <div class="mt-md space-y-md">
                <div id="documentsHeading" class="hidden">
                    <p class="text-body font-semibold">Required documents</p>
                    <p class="text-metadata text-text-secondary">JPG, PNG or PDF, up to 5 MB each, {{ \App\Http\Requests\FrontDesk\WalkInRegistrationRequest::MAX_FILES }} files at most (the valid ID counts).</p>
                    @include('frontdesk.walk-ins.partials.field-error', ['name' => 'documents'])
                </div>
                @foreach ($documents as $key => [$label, $description])
                    <div data-document="{{ $key }}" class="hidden rounded-lg border border-border p-md">
                        <p class="text-body font-semibold">{{ $label }} *</p>
                        <p class="text-metadata text-text-secondary">{{ $description }}</p>
                        <div class="mt-sm">@include('frontdesk.walk-ins.partials.capture-field', ['name' => "documents[{$key}]", 'id' => "document_{$key}", 'pdf' => true])</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 4. Visit date and session: one Records opened; approving the walk-in books it. --}}
        <div class="cc-card">
            <p class="text-card-title">4 · Visit Date and Session *</p>
            <p class="text-metadata text-text-secondary">Only the dates and sessions Records opened are shown. Approving the walk-in books the session chosen here.</p>
            <div class="mt-md">
                @include('frontdesk.walk-ins.partials.slot-picker', [
                    'id' => 'registrationSlots',
                    'pdlId' => null,
                    'pdlSource' => '#pdl_id',
                    'typeSource' => '#relationship_type',
                    'oldDate' => old('requested_date'),
                    'oldTimes' => old('requested_start_times', []),
                ])
                {{-- Messages from the page's own checks (the picker shows the server's). --}}
                <p class="mt-xs text-metadata font-semibold text-danger" data-error-for="requested_date" role="alert" hidden></p>
            </div>
        </div>

        {{-- 5. Review & Confirm --}}
        <div class="cc-card">
            <p class="text-card-title">5 · Review &amp; Confirm</p>
            <p class="text-metadata text-text-secondary">Double-check all details before saving the visit.</p>

            <label @class(['mt-md flex items-start gap-sm rounded-lg border-2 p-md text-body', 'border-danger' => $errors->has('consent'), 'border-border' => ! $errors->has('consent')]) data-field-box="consent">
                <input type="checkbox" id="consent" name="consent" value="1" class="mt-1 h-4 w-4 shrink-0" />
                <span>
                    I confirm that the visitor has been informed of and agrees to the
                    <button type="button" class="font-semibold text-primary-teal underline" data-legal-open="termsDialog">Terms and Conditions</button>
                    and the
                    <button type="button" class="font-semibold text-primary-teal underline" data-legal-open="privacyDialog">Privacy Policy</button>,
                    including the collection and storage of their personal information, photo, and documents by the facility in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173).
                </span>
            </label>
            @include('frontdesk.walk-ins.partials.field-error', ['name' => 'consent'])

            {{-- Ticked: the desk approves and books at once (WalkInService::approveAtDesk). Unticked: Records reviews it. --}}
            <label class="mt-md flex items-start gap-sm rounded-lg border-2 border-border p-md text-body">
                <input type="checkbox" id="desk_verified" name="desk_verified" value="1" class="mt-1 h-4 w-4 shrink-0" @checked(old('desk_verified', $errors->any() ? null : '1')) />
                <span>
                    I checked the visitor's <strong>original</strong> ID and documents in person, and they match the copies uploaded here.
                    <span class="block text-metadata text-text-secondary">The walk-in is approved and the visit booked right away. Untick this to send it to the Records Officer for review instead.</span>
                </span>
            </label>
            <label id="noImmediateFamilyBox" class="mt-sm hidden flex items-start gap-sm rounded-lg border-2 border-border p-md text-body">
                <input type="checkbox" id="no_immediate_family" name="no_immediate_family" value="1" class="mt-1 h-4 w-4 shrink-0" @checked(old('no_immediate_family')) />
                <span>
                    I confirmed the PDL has <strong>no immediate family</strong> available to visit (required for a legal guardian).
                    <span class="block text-metadata text-text-secondary">Without this, the legal guardian goes to the Records Officer for review.</span>
                </span>
            </label>

            <div class="mt-md flex flex-wrap gap-sm">
                <button type="submit" id="walkInSubmit" class="cc-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled>Approve and book visit</button>
                <a href="{{ route('frontdesk.walk-ins.index') }}" class="cc-btn-secondary">Cancel</a>
            </div>
            <p id="walkInSubmitHint" class="mt-xs text-metadata text-text-secondary">Tick the consent box to submit.</p>
        </div>
    </form>

    {{-- Terms and Conditions / Privacy Policy, from config/legal.php (the same text as /terms and /privacy). --}}
    @foreach (['termsDialog' => config('legal.terms'), 'privacyDialog' => config('legal.privacy')] as $dialogId => $legalDoc)
        <dialog id="{{ $dialogId }}" class="cc-modal max-w-2xl" aria-labelledby="{{ $dialogId }}Title">
            <div class="flex items-center justify-between gap-sm">
                <p id="{{ $dialogId }}Title" class="text-card-title">{{ $legalDoc['title'] }}</p>
                <button type="button" class="cc-modal-close" data-legal-close>Close</button>
            </div>
            <div class="mt-md max-h-[60vh] space-y-sm overflow-y-auto pr-xs text-body">
                <p>{{ $legalDoc['intro'] }}</p>
                @foreach ($legalDoc['sections'] as $section)
                    <p class="pt-xs font-semibold">{{ $section['heading'] }}</p>
                    @foreach ($section['body'] as $paragraph)
                        <p class="text-text-secondary">{{ $paragraph }}</p>
                    @endforeach
                @endforeach
            </div>
            <div class="mt-md flex justify-end">
                <button type="button" class="cc-btn-primary" data-legal-close>Done</button>
            </div>
        </dialog>
    @endforeach

    {{-- Shared desk camera: fills whichever field asked for it. --}}
    <dialog id="cameraDialog" class="cc-modal max-w-xl">
        <div class="flex items-center justify-between">
            <p class="text-card-title">Take photo</p>
            <button type="button" class="cc-modal-close" data-camera-close>Close</button>
        </div>
        <video id="cameraVideo" autoplay playsinline class="mt-md w-full rounded-lg bg-black"></video>
        <p id="cameraError" class="mt-sm hidden text-metadata text-danger"></p>
        <div class="mt-md flex gap-sm">
            <button type="button" id="cameraSnap" class="cc-btn-primary">Capture</button>
            <button type="button" class="cc-btn-secondary" data-camera-close>Cancel</button>
        </div>
    </dialog>
@endsection

@push('scripts')
<script>
    (() => {
        const types = @json($relationshipTypes);
        const minorDocument = @json($minorDocument);

        const $ = id => document.getElementById(id);
        const birth = $('date_of_birth');
        const type = $('relationship_type');

        const ageOf = () => {
            if (!birth.value) return null;
            const dob = new Date(birth.value), now = new Date();
            let age = now.getFullYear() - dob.getFullYear();
            if (now.getMonth() < dob.getMonth() || (now.getMonth() === dob.getMonth() && now.getDate() < dob.getDate())) age--;
            return age;
        };

        const isMinor = () => ageOf() !== null && ageOf() < 18;

        const childrenAnswer = () => document.querySelector('input[name="has_children_together"]:checked')?.value ?? null;

        // Mirrors RelationshipRequirements::documentKeysFor (+ the minor's birth certificate).
        const requiredDocuments = () => {
            const rule = types[type.value];
            if (!rule) return [];
            let keys = [...rule.documents];
            if (rule.childrenTogether && childrenAnswer() !== null) {
                keys = keys.concat(rule.childrenTogether[childrenAnswer() === '1' ? 'with_children' : 'without_children']);
            }
            if (isMinor()) keys.push(minorDocument);
            return [...new Set(keys)];
        };

        const refresh = () => {
            const minor = isMinor();
            const age = ageOf();
            $('age').value = age === null ? '' : age;
            $('guardianSection').classList.toggle('hidden', !minor);
            $('idRequiredMark').classList.toggle('hidden', minor);
            $('idMinorNote').classList.toggle('hidden', !minor);

            const rule = types[type.value];
            $('relationshipDescription').textContent = rule ? rule.description : '';
            $('childrenTogether').classList.toggle('hidden', !rule?.childrenTogether);
            $('noImmediateFamilyBox').classList.toggle('hidden', type.value !== 'legal_guardian' || !$('desk_verified').checked);

            const needed = requiredDocuments();
            $('documentsHeading').classList.toggle('hidden', needed.length === 0);
            document.querySelectorAll('[data-document]').forEach(block => {
                const show = needed.includes(block.dataset.document);
                block.classList.toggle('hidden', !show);
                // Hidden documents are not sent (the server checks the required ones).
                block.querySelectorAll('input[type="file"]').forEach(input => { input.disabled = !show; });
            });
        };

        [birth, type].forEach(el => el.addEventListener('change', refresh));
        birth.addEventListener('input', refresh);

        // Contact numbers: digits, with an optional leading + (09XXXXXXXXX or +639XXXXXXXXX).
        document.querySelectorAll('[data-phone]').forEach(input => input.addEventListener('input', () => {
            input.value = input.value.replace(/[^\d+ -]/g, '').replace(/(?!^)\+/g, '').slice(0, 16);
        }));

        // PDL search: partial name, alias or PDL number; the desk selects the right PDL.
        const pdlSearch = $('pdl_search');
        const pdlId = $('pdl_id');
        const pdlResults = $('pdlResults');
        const pdlChosen = $('pdlChosen');
        const custody = { active: 'Active', released: 'Released', transferred: 'Transferred', deceased: 'Deceased' };
        let searchTimer = null;
        let searchRun = 0;

        const closeResults = () => { pdlResults.hidden = true; pdlSearch.setAttribute('aria-expanded', 'false'); };
        const choosePdl = pdl => {
            pdlId.value = pdl.id;
            pdlId.dataset.status = pdl.status || '';
            pdlSearch.value = `${pdl.name} (${pdl.number})`;
            pdlChosen.textContent = `Selected: ${pdl.name} · ${pdl.number}` + (pdl.visitable ? '' : ' · not available for visits');
            pdlChosen.className = 'mt-xs text-metadata font-semibold ' + (pdl.visitable ? 'text-success' : 'text-danger');
            closeResults();
            pdlId.dispatchEvent(new Event('change')); // loads the PDL's sessions
        };
        const searchPdls = () => {
            const q = pdlSearch.value.trim();
            if (q.length < 2) { pdlResults.innerHTML = ''; closeResults(); return; }
            const current = ++searchRun;
            fetch(`${pdlSearch.dataset.url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data || current !== searchRun) return;
                    pdlResults.innerHTML = '';
                    if (!data.results.length) {
                        const none = document.createElement('li');
                        none.className = 'p-sm text-metadata text-text-secondary';
                        none.textContent = `No PDL found for "${q}".`;
                        pdlResults.appendChild(none);
                    }
                    data.results.forEach(pdl => {
                        const li = document.createElement('li');
                        li.setAttribute('role', 'option');
                        li.tabIndex = 0;
                        li.className = 'cursor-pointer p-sm text-body hover:bg-background';
                        const name = document.createElement('span');
                        name.className = 'font-semibold';
                        name.textContent = pdl.name + (pdl.alias ? ` "${pdl.alias}"` : '');
                        const meta = document.createElement('span');
                        meta.className = 'block text-metadata text-text-secondary';
                        meta.textContent = `${pdl.number} · ${custody[pdl.status] || pdl.status}` + (pdl.visitable ? '' : ' · not available for visits');
                        li.append(name, meta);
                        li.addEventListener('click', () => choosePdl(pdl));
                        li.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); choosePdl(pdl); } });
                        pdlResults.appendChild(li);
                    });
                    pdlResults.hidden = false;
                    pdlSearch.setAttribute('aria-expanded', 'true');
                })
                .catch(() => {});
        };
        pdlSearch.addEventListener('input', () => {
            // Typing again drops the earlier selection until a PDL is picked.
            if (pdlId.value) {
                pdlId.value = '';
                pdlId.dataset.status = '';
                pdlChosen.textContent = 'Search, then select the correct PDL from the list.';
                pdlChosen.className = 'mt-xs text-metadata text-text-secondary';
                pdlId.dispatchEvent(new Event('change'));
            }
            clearTimeout(searchTimer);
            searchTimer = setTimeout(searchPdls, 250);
        });
        pdlSearch.addEventListener('keydown', e => { if (e.key === 'Escape') closeResults(); });
        document.addEventListener('click', e => { if (!pdlResults.contains(e.target) && e.target !== pdlSearch) closeResults(); });
        // Purpose: a type from the list, or Other with the purpose typed in.
        const purposeType = $('visit_purpose_type');
        const purposeOther = $('visit_purpose_other');
        const syncPurpose = () => {
            const other = purposeType.value === 'other';
            purposeOther.classList.toggle('hidden', !other);
            $('visit_purpose').value = other ? purposeOther.value.trim() : purposeType.value;
        };
        purposeType.addEventListener('change', syncPurpose);
        purposeOther.addEventListener('input', syncPurpose);
        syncPurpose();
        document.querySelectorAll('input[name="has_children_together"]').forEach(el => el.addEventListener('change', refresh));
        refresh();

        // ---- Validation: the same rules and messages as WalkInRegistrationRequest ----
        const form = $('walkInForm');
        const NAME = /^[\p{L}\p{M} .'’-]+$/u;
        const MOBILE = /^(09\d{9}|\+639\d{9})$/;
        const MAX_FILES = @json(\App\Http\Requests\FrontDesk\WalkInRegistrationRequest::MAX_FILES);
        const MAX_BYTES = 5 * 1024 * 1024;
        const IMAGE_TYPES = ['image/jpeg', 'image/png'];
        const DOCUMENT_TYPES = [...IMAGE_TYPES, 'application/pdf'];

        // Trimmed, tags removed, runs of spaces collapsed: only spaces counts as empty.
        const clean = id => ($(id)?.value || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
        const today = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; };
        const parseDate = value => { const d = value ? new Date(`${value}T00:00:00`) : null; return d && !isNaN(d) ? d : null; };
        const fileInput = key => form.querySelector(`input[type="file"][data-error-key="${key}"]`);

        const nameProblem = (id, label) => {
            const value = clean(id);
            if (!value) return `Please enter the visitor’s ${label.toLowerCase()}.`;
            if (!NAME.test(value)) return `${label} can only contain letters, spaces, periods, hyphens, and apostrophes.`;
            if (value.length < 2 || value.length > 50) return `${label} must be 2 to 50 characters.`;
            return null;
        };
        const mobileProblem = id => {
            const value = clean(id).replace(/[\s-]/g, '');
            if (!value) return 'Please enter a contact number.';
            return MOBILE.test(value) ? null : 'Enter a valid mobile number (e.g. 09171234567).';
        };
        const fileProblem = (key, { required, types, typeMessage, sizeMessage }) => {
            const input = fileInput(key);
            const file = input && !input.disabled ? input.files[0] : null;
            if (!file) return required ? (key === 'photo' ? 'Please capture or upload the visitor’s photo.' : 'Please upload the required document.') : null;
            if (!types.includes(file.type)) return typeMessage;
            if (file.size > MAX_BYTES) return sizeMessage;
            return null;
        };
        const documentFile = key => fileProblem(key, {
            required: true, types: DOCUMENT_TYPES,
            typeMessage: 'Only JPG, PNG, or PDF files are allowed.', sizeMessage: 'Each file must not exceed 5 MB.',
        });

        // Error key -> [field to highlight and focus, check]. Order = order on the page.
        const checks = () => {
            const minor = isMinor();
            const list = [
                ['first_name', () => nameProblem('first_name', 'First name')],
                ['last_name', () => nameProblem('last_name', 'Last name')],
                ['gender', () => $('gender').value ? null : 'Please select the visitor’s sex.'],
                ['date_of_birth', () => {
                    const dob = parseDate(birth.value);
                    if (!dob) return 'Please enter the visitor’s date of birth.';
                    return dob > today() ? 'Date of birth cannot be in the future.' : null;
                }],
                ['contact_number', () => mobileProblem('contact_number')],
                ['email', () => {
                    const value = clean('email');
                    return !value || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? null : 'Enter a valid email address.';
                }],
                ['address', () => {
                    const value = clean('address');
                    if (!value) return 'Please enter the visitor’s complete address.';
                    return value.length < 5 || value.length > 200 ? 'Address must be 5 to 200 characters.' : null;
                }],
            ];
            if (minor) {
                list.push(
                    ['guardian_full_name', () => clean('guardian_full_name') ? null : 'Visitors under 18 must be accompanied by an adult.'],
                    ['guardian_relationship', () => clean('guardian_relationship') ? null : 'Enter the guardian’s relationship to the visitor.'],
                    ['guardian_contact_number', () => mobileProblem('guardian_contact_number')],
                );
            }
            list.push(['photo', () => fileProblem('photo', {
                required: true, types: IMAGE_TYPES,
                typeMessage: 'Photo must be a JPG or PNG file.', sizeMessage: 'Photo must not exceed 5 MB.',
            })]);
            if (!minor) {
                list.push(
                    ['id_type', () => $('id_type').value ? null : 'Select the type of valid ID the visitor showed.'],
                    ['government_id', () => documentFile('government_id')],
                );
            }
            list.push(
                ['pdl_id', () => {
                    if (!pdlId.value) return 'Please select the PDL to be visited.';
                    return pdlId.dataset.status && pdlId.dataset.status !== 'active' ? 'The selected PDL was not found or is no longer detained.' : null;
                }],
                ['relationship_type', () => type.value ? null : 'Please select the visitor’s relationship to the PDL.'],
                ['visit_purpose_type', () => purposeType.value ? null : 'Please select the purpose of the visit.'],
            );
            if (purposeType.value === 'other') {
                list.push(['visit_purpose', () => clean('visit_purpose_other') ? null : 'Please specify the purpose of the visit.']);
            }
            if (types[type.value]?.childrenTogether) {
                list.push(['has_children_together', () => childrenAnswer() !== null ? null : 'Say whether the visitor and the PDL have children together.']);
            }
            requiredDocuments().forEach(key => list.push([`documents.${key}`, () => documentFile(`documents.${key}`)]));
            list.push(
                ['documents', () => {
                    const count = [...form.querySelectorAll('input[type="file"]')]
                        .filter(input => !input.disabled && input.files.length && input.dataset.errorKey !== 'photo').length;
                    return count > MAX_FILES ? `You can upload up to ${MAX_FILES} files only.` : null;
                }],
                ['requested_date', () => {
                    const date = parseDate(document.querySelector('#registrationSlots [data-slot-date]').value);
                    if (!date) return 'Please select a visit date.';
                    return date < today() ? 'Visit date cannot be in the past.' : null;
                }],
                ['consent', () => $('consent').checked ? null : 'The visitor must agree to the Terms and Conditions and the Privacy Policy before proceeding.'],
            );
            return list;
        };

        // The element that turns red and takes focus for an error key.
        const fieldFor = key => ({
            pdl_id: pdlSearch,
            visit_purpose: purposeOther,
            requested_date: $('registrationSlots'),
            has_children_together: form.querySelector('input[name="has_children_together"]'),
            documents: $('documentsHeading'),
        }[key] || form.querySelector(`[data-field-box="${key}"]`) || $(key));

        const showError = (key, message) => {
            const note = form.querySelector(`[data-error-for="${CSS.escape(key)}"]`);
            if (note) { note.textContent = message || ''; note.hidden = !message; }
            const field = fieldFor(key);
            if (field?.classList.contains('cc-input') || field?.dataset.fieldBox) {
                field.classList.toggle('border-danger', !!message);
                if (field.dataset.fieldBox) field.classList.toggle(field.dataset.fieldBox === 'consent' ? 'border-border' : 'border-transparent', !message);
            }
            field?.setAttribute?.('aria-invalid', message ? 'true' : 'false');
        };

        const validate = (onlyKey = null) => {
            let first = null;
            checks().forEach(([key, check]) => {
                if (onlyKey && key !== onlyKey) return;
                const message = check();
                showError(key, message);
                if (message && !first) first = key;
            });
            return first;
        };

        form.addEventListener('submit', e => {
            syncPurpose();
            const first = validate();
            if (!first) return;
            e.preventDefault();
            const field = fieldFor(first);
            const target = field?.dataset?.fieldBox ? (field.querySelector('input, button') || field) : field;
            field?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target?.focus?.({ preventScroll: true });
        });

        // Clear (or update) a field's message as soon as it is corrected.
        const keyOf = el => el.dataset.errorKey
            || ({ pdl_search: 'pdl_id', visit_purpose_other: 'visit_purpose' }[el.id])
            || el.name || el.id;
        ['input', 'change'].forEach(event => form.addEventListener(event, e => {
            const key = keyOf(e.target);
            const note = key && form.querySelector(`[data-error-for="${CSS.escape(key)}"]`);
            if (note && !note.hidden) validate(key);
            if (e.target.type === 'file') validate('documents');
        }));
        pdlId.addEventListener('change', () => { if (pdlId.value) validate('pdl_id'); });
        form.addEventListener('change', e => {
            if (e.target.matches('[data-slot-picker] *') || e.target.matches('[data-slot-date]')) validate('requested_date');
        });
        $('registrationSlots').addEventListener('click', () => setTimeout(() => {
            const note = form.querySelector('[data-error-for="requested_date"]');
            if (!note.hidden) validate('requested_date');
        }, 0));

        // ---- Consent: Submit stays disabled until the box is ticked ----
        const consent = $('consent');
        const submit = $('walkInSubmit');
        const syncConsent = () => {
            submit.disabled = !consent.checked;
            $('walkInSubmitHint').hidden = consent.checked;
        };
        consent.addEventListener('change', syncConsent);
        syncConsent();

        // The button says what will happen: approve now, or send to Records.
        const deskVerified = $('desk_verified');
        const syncDeskVerified = () => {
            submit.textContent = deskVerified.checked ? 'Approve and book visit' : 'Submit for Records approval';
            refresh();
        };
        deskVerified.addEventListener('change', syncDeskVerified);
        syncDeskVerified();

        // Terms and Conditions / Privacy Policy open in a modal (not ticking the box).
        document.querySelectorAll('[data-legal-open]').forEach(link => link.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            $(link.dataset.legalOpen).showModal();
        }));
        document.querySelectorAll('[data-legal-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));

        // ---- Desk camera ----
        const dialog = $('cameraDialog');
        const video = $('cameraVideo');
        let stream = null;
        let target = null;

        const stop = () => {
            stream?.getTracks().forEach(track => track.stop());
            stream = null;
            if (dialog.open) dialog.close();
        };

        document.querySelectorAll('[data-camera-for]').forEach(button => {
            button.addEventListener('click', async () => {
                target = document.querySelector(`[data-file-for="${button.dataset.cameraFor}"]`);
                $('cameraError').classList.add('hidden');
                dialog.showModal();
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 } }, audio: false });
                    video.srcObject = stream;
                } catch {
                    $('cameraError').textContent = 'The camera could not be opened. Allow camera access in the browser, or use Upload instead.';
                    $('cameraError').classList.remove('hidden');
                }
            });
        });

        $('cameraSnap').addEventListener('click', () => {
            if (!stream || !target) return;
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            canvas.toBlob(blob => {
                const file = new File([blob], `${target.dataset.fileFor}-${Date.now()}.jpg`, { type: 'image/jpeg' });
                const files = new DataTransfer();
                files.items.add(file);
                target.files = files.files;
                target.dispatchEvent(new Event('change'));
                stop();
            }, 'image/jpeg', 0.9);
        });

        document.querySelectorAll('[data-camera-close]').forEach(button => button.addEventListener('click', stop));
        dialog.addEventListener('close', stop);

        // Preview of whatever is in each field.
        document.querySelectorAll('[data-file-for]').forEach(input => {
            input.addEventListener('change', () => {
                const preview = document.querySelector(`[data-preview-for="${input.dataset.fileFor}"]`);
                const file = input.files[0];
                if (!preview) return;
                if (!file) { preview.classList.add('hidden'); return; }
                preview.classList.remove('hidden');
                preview.innerHTML = '';
                if (file.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.className = 'h-28 rounded-lg border border-border object-cover';
                    preview.appendChild(img);
                } else {
                    preview.textContent = `${file.name} attached`;
                }
            });
        });
    })();
</script>
@endpush
