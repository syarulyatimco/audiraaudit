<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Users\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InvitationAcceptController extends Controller
{
    public function show(string $token, InvitationService $service): View
    {
        $invitation = $service->findPending($token);
        abort_unless($invitation, 404, 'This invitation is invalid or has expired.');

        return view('auth.invitation', ['invitation' => $invitation, 'token' => $token]);
    }

    public function store(string $token, Request $request, InvitationService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $service->accept($token, $data['name'], $data['password']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Welcome aboard, '.$user->name.'.');
    }
}
