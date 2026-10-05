@extends('layouts.app')
@section('title', 'Register New PDL — CustodiCore')

@section('content')
<div class="topbar">
  <h1>Register New PDL</h1>
  <div class="right">
    <a class="btn btn-outline-neutral" href="{{ route('pdl.index') }}">Back to PDL Management</a>
  </div>
</div>

<div class="panel">
  <form method="POST" action="{{ route('pdl.store') }}">
    @csrf


    <p class="section-title"><span class="icon">👤</span> Personal Details</p>
    <div class="row cols-2">
      <div class="field-m">
        <label>Full Name</label>
        <input type="text" name="full_name" value="{{ old('full_name') }}" required>
      </div>
      <div class="field-m">
        <label>Alias (optional)</label>
        <input type="text" name="alias" value="{{ old('alias') }}">
      </div>
    </div>

    <div class="row cols-3">
      <div class="field-m">
        <label>Date of Birth</label>
        <input type="date" name="date_of_birth" value="{{ old('date_of_birth') }}" required>
      </div>
      <div class="field-m">
        <label>Gender</label>
        <select name="gender">
          <option value="male" {{ old('gender') === 'male' ? 'selected' : '' }}>Male</option>
          <option value="female" {{ old('gender') === 'female' ? 'selected' : '' }}>Female</option>
          <option value="other" {{ old('gender') === 'other' ? 'selected' : '' }}>Other</option>
        </select>
      </div>
      <div class="field-m">
        {{-- Drives the visiting-day rule (drug_related -> Thu/Sat, non_drug_related -> Fri/Sun) --}}
        <label>Classification</label>
        <select name="classification" required>
          <option value="drug_related" {{ old('classification') === 'drug_related' ? 'selected' : '' }}>Drug-related</option>
          <option value="non_drug_related" {{ old('classification') === 'non_drug_related' ? 'selected' : '' }}>Non-drug-related</option>
        </select>
      </div>
    </div>

    <p class="section-title" style="margin-top:26px;"><span class="icon">🏢</span> Custody Details</p>
    <div class="row cols-2">
      <div class="field-m">
        <label>Admission Date</label>
        <input type="date" name="admission_date" value="{{ old('admission_date', now()->toDateString()) }}" required>
      </div>
      <div class="field-m">
        <label>Cell/Block (optional)</label>
        <input type="text" name="cell_block" value="{{ old('cell_block') }}" placeholder="e.g. Block A - 102">
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:8px;">
      <button class="btn btn-blue" type="submit">Register PDL</button>
      <a class="btn btn-outline-neutral" href="{{ route('pdl.index') }}">Cancel</a>
    </div>
  </form>
</div>
@endsection
