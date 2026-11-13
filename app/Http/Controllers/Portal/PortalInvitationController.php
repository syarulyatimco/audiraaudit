<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PortalInvitationController extends Controller
{
    public function show(string $token, PortalService $portal): View
    {
        $inv = $portal->findPending($token);
        abort_unless($inv, 404);

        return view('portal.invitation', ['invitation' => $inv, 'token' => $token]);
    }

    public function accept(Request $request, string $token, PortalService $portal): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);
        $user = $portal->accept($token, $data['password']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('portal.dashboard')->with('status', 'Welcome to the client portal.');
    }
}
