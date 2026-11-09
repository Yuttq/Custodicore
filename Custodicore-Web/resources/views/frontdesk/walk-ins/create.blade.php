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

    <form method="POST" action="{{ route('frontdesk.walk-ins.store') }}" enctype="multipart/form-data" id="walkInForm" class="space-y-lg">
        @csrf

        @if ($errors->any())
            <div class="cc-card border-l-4 border-l-warning text-body">
                Photos and files are not kept after an error. Please capture or upload them again.
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

        {{-- 1. Visitor details --}}
        <div class="cc-card">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <p class="text-card-title">1 · Visitor information</p>
                <span class="text-metadata text-text-secondary">Visitor type: <span class="cc-chip cc-chip-info">Walk-In</span></span>
            </div>
            <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-3">
                <div>
                    <label for="first_name" class="cc-label">First name *</label>
                    <input id="first_name" name="first_name" value="{{ old('first_name') }}" required maxlength="100" class="cc-input" />
                    @error('first_name') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="last_name" class="cc-label">Last name *</label>
                    <input id="last_name" name="last_name" value="{{ old('last_name') }}" required maxlength="100" class="cc-input" />
                    @error('last_name') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="gender" class="cc-label">Sex *</label>
                    <select id="gender" name="gender" required class="cc-input">
                        <option value="">Select…</option>
                        <option value="male" @selected(old('gender') === 'male')>Male</option>
                        <option value="female" @selected(old('gender') === 'female')>Female</option>
                    </select>
                </div>
                <div class="grid grid-cols-[1fr_88px] gap-sm">
                    <div>
                        <label for="date_of_birth" class="cc-label">Date of birth *</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth') }}" max="{{ now()->subDay()->format('Y-m-d') }}" required class="cc-input" />
                    </div>
                    <div>
                        <label for="age" class="cc-label">Age</label>
                        {{-- Worked out from the birthdate; not sent. --}}
                        <input id="age" readonly tabindex="-1" placeholder="—" class="cc-input bg-background" />
                    </div>
                </div>
                <div>
                    <label for="contact_number" class="cc-label">Contact number</label>
                    <input id="contact_number" name="contact_number" value="{{ old('contact_number') }}" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" title="11 digits, starting with 09" data-digits class="cc-input" />
                    @error('contact_number') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="cc-label">Email (optional)</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="100" class="cc-input" />
                </div>
                <div class="md:col-span-3">
                    <label for="address" class="cc-label">Address</label>
                    <input id="address" name="address" value="{{ old('address') }}" maxlength="255" placeholder="Residential address" class="cc-input" />
                </div>
            </div>

            <div id="guardianSection" class="mt-md hidden rounded-lg bg-background p-md">
                <p class="text-body font-semibold">Minor visitor: accompanying parent or guardian *</p>
                <p class="text-metadata text-text-secondary">Visitors under 18 must come with a parent or guardian, and must be at least 3 years old.</p>
                <div class="mt-sm grid grid-cols-1 gap-md md:grid-cols-3">
                    <div>
                        <label for="guardian_full_name" class="cc-label">Guardian's full name</label>
                        <input id="guardian_full_name" name="guardian_full_name" value="{{ old('guardian_full_name') }}" maxlength="150" class="cc-input" />
                    </div>
                    <div>
                        <label for="guardian_relationship" class="cc-label">Relationship to visitor</label>
                        <input id="guardian_relationship" name="guardian_relationship" value="{{ old('guardian_relationship') }}" maxlength="100" placeholder="e.g. Mother" class="cc-input" />
                    </div>
                    <div>
                        <label for="guardian_contact_number" class="cc-label">Guardian's contact number</label>
                        <input id="guardian_contact_number" name="guardian_contact_number" value="{{ old('guardian_contact_number') }}" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" title="11 digits, starting with 09" data-digits class="cc-input" />
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Photo --}}
        <div class="cc-card">
            <p class="text-card-title">2 · Photo of the visitor *</p>
            <p class="text-metadata text-text-secondary">Face the camera, no cap or sunglasses.</p>
            <div class="mt-md">@include('frontdesk.walk-ins.partials.capture-field', ['name' => 'photo', 'id' => 'photo', 'pdf' => false])</div>
        </div>

        {{-- 3. Valid ID --}}
        <div class="cc-card">
            <p class="text-card-title">3 · Valid ID <span id="idRequiredMark">*</span></p>
            <p id="idMinorNote" class="hidden text-metadata text-text-secondary">Optional for minors: their birth certificate (below) stands in for an ID.</p>
            <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                <div>
                    <label for="id_type" class="cc-label">ID type</label>
                    <select id="id_type" name="id_type" class="cc-input">
                        <option value="">Select…</option>
                        @foreach ($idTypes as $key => $label)
                            <option value="{{ $key }}" @selected(old('id_type') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="id_number" class="cc-label">ID number</label>
                    <input id="id_number" name="id_number" value="{{ old('id_number') }}" maxlength="50" class="cc-input" />
                </div>
            </div>
            <div class="mt-md">@include('frontdesk.walk-ins.partials.capture-field', ['name' => 'government_id', 'id' => 'government_id', 'pdf' => true])</div>
        </div>

        {{-- 4. Who they are visiting --}}
        <div class="cc-card">
            <p class="text-card-title">4 · Who they are visiting</p>
            <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                <div class="relative md:col-span-2">
                    <label for="pdl_search" class="cc-label">PDL *</label>
                    <input id="pdl_search" type="text" autocomplete="off" maxlength="100" role="combobox" aria-expanded="false" aria-controls="pdlResults"
                           value="{{ $selectedPdl ? $selectedPdl->full_name.' ('.$selectedPdl->pdl_number.')' : '' }}"
                           placeholder="Type part of the name, alias or PDL number" data-url="{{ route('frontdesk.walk-ins.pdl-search') }}" class="cc-input" />
                    <input type="hidden" id="pdl_id" name="pdl_id" value="{{ $selectedPdl?->pdl_id }}" />
                    <ul id="pdlResults" role="listbox" hidden
                        class="absolute z-10 mt-xs max-h-64 w-full overflow-y-auto rounded-lg border border-border bg-card shadow-lg"></ul>
                    <p id="pdlChosen" class="mt-xs text-metadata {{ $selectedPdl ? 'font-semibold text-success' : 'text-text-secondary' }}" aria-live="polite">
                        {{ $selectedPdl ? "Selected: {$selectedPdl->full_name} · {$selectedPdl->pdl_number}" : 'Search, then select the correct PDL from the list.' }}
                    </p>
                    @error('pdl_id') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="relationship_type" class="cc-label">Relationship to the PDL *</label>
                    <select id="relationship_type" name="relationship_type" required class="cc-input">
                        <option value="">Select…</option>
                        @foreach ($relationshipTypes as $key => $type)
                            <option value="{{ $key }}" @selected(old('relationship_type') === $key)>{{ $type['label'] }}</option>
                        @endforeach
                    </select>
                    <p id="relationshipDescription" class="mt-xs text-metadata text-text-secondary"></p>
                    <p class="mt-xs text-metadata text-text-secondary">Friends and other non-relatives are not allowed to visit.</p>
                </div>
                <div>
                    <label for="visit_purpose_type" class="cc-label">Purpose of visit *</label>
                    @php $oldPurpose = old('visit_purpose'); $isOther = $oldPurpose && ! in_array($oldPurpose, $purposes, true); @endphp
                    <select id="visit_purpose_type" required class="cc-input">
                        <option value="">Select…</option>
                        @foreach ($purposes as $purpose)
                            <option value="{{ $purpose }}" @selected($oldPurpose === $purpose)>{{ $purpose }}</option>
                        @endforeach
                        <option value="other" @selected($isOther)>Other</option>
                    </select>
                    {{-- The value sent: the chosen type, or what is typed for Other. --}}
                    <input type="hidden" id="visit_purpose" name="visit_purpose" value="{{ $oldPurpose }}" />
                    <input id="visit_purpose_other" value="{{ $isOther ? $oldPurpose : '' }}" maxlength="150" placeholder="Describe the purpose" class="cc-input {{ $isOther ? '' : 'hidden' }}" />
                    @error('visit_purpose') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <fieldset id="childrenTogether" class="mt-md hidden">
                <legend class="cc-label">Do the visitor and the PDL have children together? *</legend>
                <div class="mt-xs flex gap-md text-body">
                    <label class="flex items-center gap-xs"><input type="radio" name="has_children_together" value="1" @checked(old('has_children_together') === '1') /> Yes</label>
                    <label class="flex items-center gap-xs"><input type="radio" name="has_children_together" value="0" @checked(old('has_children_together') === '0') /> No</label>
                </div>
            </fieldset>

            <div class="mt-md space-y-md">
                <p id="documentsHeading" class="hidden text-body font-semibold">Required documents</p>
                @foreach ($documents as $key => [$label, $description])
                    <div data-document="{{ $key }}" class="hidden rounded-lg border border-border p-md">
                        <p class="text-body font-semibold">{{ $label }} *</p>
                        <p class="text-metadata text-text-secondary">{{ $description }}</p>
                        <div class="mt-sm">@include('frontdesk.walk-ins.partials.capture-field', ['name' => "documents[{$key}]", 'id' => "document_{$key}", 'pdf' => true])</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Phase 2: a session Records opened; Records' approval books it. --}}
        <div class="cc-card">
            <p class="text-card-title">5 · Visit date and session *</p>
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
            </div>
        </div>

        <div class="cc-card">
            <label class="flex items-start gap-sm text-body">
                <input type="checkbox" name="consent" value="1" class="mt-1" @checked(old('consent')) required />
                <span>The visitor was told about, and agreed to, the Terms and Conditions and the Privacy Policy (their details, photo and documents are kept by the facility).</span>
            </label>
            <div class="mt-md flex flex-wrap gap-sm">
                <button type="submit" class="cc-btn-primary">Submit for approval</button>
                <a href="{{ route('frontdesk.walk-ins.index') }}" class="cc-btn-secondary">Cancel</a>
            </div>
        </div>
    </form>

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
            ['guardian_full_name', 'guardian_relationship', 'guardian_contact_number'].forEach(id => { $(id).required = minor; });
            $('idRequiredMark').classList.toggle('hidden', minor);
            $('idMinorNote').classList.toggle('hidden', !minor);
            ['id_type', 'id_number'].forEach(id => { $(id).required = !minor; });

            const rule = types[type.value];
            $('relationshipDescription').textContent = rule ? rule.description : '';
            $('childrenTogether').classList.toggle('hidden', !rule?.childrenTogether);

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

        // Contact numbers: digits only, 11 at most.
        document.querySelectorAll('[data-digits]').forEach(input => input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(0, 11);
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
            pdlSearch.value = `${pdl.name} (${pdl.number})`;
            pdlChosen.textContent = `Selected: ${pdl.name} · ${pdl.number}` + (pdl.visitable ? '' : ' · not available for visits');
            pdlChosen.className = 'mt-xs text-metadata font-semibold ' + (pdl.visitable ? 'text-success' : 'text-danger');
            pdlSearch.setCustomValidity('');
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
                pdlChosen.textContent = 'Search, then select the correct PDL from the list.';
                pdlChosen.className = 'mt-xs text-metadata text-text-secondary';
                pdlId.dispatchEvent(new Event('change'));
            }
            clearTimeout(searchTimer);
            searchTimer = setTimeout(searchPdls, 250);
        });
        pdlSearch.addEventListener('keydown', e => { if (e.key === 'Escape') closeResults(); });
        document.addEventListener('click', e => { if (!pdlResults.contains(e.target) && e.target !== pdlSearch) closeResults(); });
        $('walkInForm').addEventListener('submit', e => {
            if (!pdlId.value) {
                e.preventDefault();
                pdlSearch.setCustomValidity('Select the PDL from the search results.');
                pdlSearch.reportValidity();
                return;
            }
            if (!document.querySelector('#registrationSlots [data-slot-date]').value) {
                e.preventDefault();
                window.ccToast ? window.ccToast('Choose a visit date and session.', 'warning') : alert('Choose a visit date and session.');
                document.getElementById('registrationSlots').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Purpose: a type from the list, or Other with the purpose typed in.
        const purposeType = $('visit_purpose_type');
        const purposeOther = $('visit_purpose_other');
        const syncPurpose = () => {
            const other = purposeType.value === 'other';
            purposeOther.classList.toggle('hidden', !other);
            purposeOther.required = other;
            $('visit_purpose').value = other ? purposeOther.value.trim() : purposeType.value;
        };
        purposeType.addEventListener('change', syncPurpose);
        purposeOther.addEventListener('input', syncPurpose);
        syncPurpose();
        document.querySelectorAll('input[name="has_children_together"]').forEach(el => el.addEventListener('change', refresh));
        refresh();

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
