@extends('layouts.app')

@section('title', 'User Profile')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card-lp p-4 mb-4">
            <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold fs-3" style="width: 64px; height: 64px;">
                    {{ strtoupper(substr($user->name, 0, 1)) }}
                </div>
                <div>
                    <h4 class="mb-1 fw-bold">{{ $user->name }}</h4>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary-subtle text-primary fw-semibold">{{ strtoupper($user->role) }}</span>
                        <span class="text-muted small"><i class="bi bi-clock me-1"></i>Last login: {{ $user->last_login_at?->diffForHumans() ?? 'Current Session' }}</span>
                    </div>
                </div>
            </div>

            <form action="{{ route('profile.update') }}" method="POST">
                @csrf
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Full Name</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Email Address (Read-only)</label>
                        <input type="email" class="form-control bg-light" value="{{ $user->email }}" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Phone Number</label>
                        <input type="text" name="phone" class="form-control" value="{{ old('phone', $user->phone) }}">
                    </div>
                </div>

                <h6 class="fw-bold mb-3 pt-3 border-top">Change Password</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Current Password</label>
                        <input type="password" name="current_password" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">New Password</label>
                        <input type="password" name="new_password" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Confirm New Password</label>
                        <input type="password" name="new_password_confirmation" class="form-control">
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-primary-lp px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
