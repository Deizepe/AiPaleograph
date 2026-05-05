@extends('layouts/blankLayout')

@section('title', 'Sign in')

@section('page-style')
@vite(['resources/assets/vendor/scss/pages/page-auth.scss'])
@endsection

@section('content')
<div class="container-xxl">
    <div class="authentication-wrapper authentication-basic container-p-y">
        <div class="authentication-inner">
            <div class="card px-sm-6 px-0">
                <div class="card-body">
                    <div class="app-brand justify-content-center">
                        <a href="{{ url('/') }}" class="app-brand-link gap-2">
                            <span class="app-brand-logo demo">@include('_partials.macros')</span>
                            <span class="app-brand-text demo text-heading fw-bold">{{ config('variables.templateName') }}</span>
                        </a>
                    </div>
                    <h4 class="mb-1">Welcome back</h4>
                    <p class="mb-6">Sign in to continue to {{ config('variables.templateName') }}.</p>

                    <form class="mb-6" action="{{ route('login') }}" method="post">
                        @csrf
                        <div class="mb-6">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" placeholder="you@example.com" autofocus required />
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-6 form-password-toggle">
                            <label class="form-label" for="password">Password</label>
                            <div class="input-group input-group-merge">
                                <input type="password" id="password" class="form-control @error('password') is-invalid @enderror" name="password" placeholder="Password" aria-describedby="password" required />
                                <span class="input-group-text cursor-pointer"><i class="icon-base bx bx-hide"></i></span>
                                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="mb-8">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1" @checked(old('remember')) />
                                <label class="form-check-label" for="remember">Remember me</label>
                            </div>
                        </div>
                        <div class="mb-6">
                            <button class="btn btn-primary d-grid w-100" type="submit">Sign in</button>
                        </div>
                    </form>

                    <p class="text-center mb-0">
                        <span>New here?</span>
                        <a href="{{ route('register') }}"><span>Create an account</span></a>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <p class="text-center text-body-secondary small mt-6 mb-0 px-2">
        © {{ now()->year }}, made by <a href="https://github.com/Deizepe" target="_blank" rel="noopener" class="link-secondary">Anderson Deizepe</a>
    </p>
</div>
@endsection
