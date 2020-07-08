<?php

namespace DarkGhostHunter\Larapass\Http;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use DarkGhostHunter\Larapass\Facades\WebAuthn;
use Illuminate\Validation\ValidationException;
use DarkGhostHunter\Larapass\Events\AttestationSuccessful;
use DarkGhostHunter\Larapass\Contracts\WebAuthnAuthenticatable;

trait RecoversWebAuthn
{
    use CredentialRules;

    /**
     * Display the password reset view for the given token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function showResetForm(Request $request)
    {
        if ($request->missing('token', 'email')) {
            return redirect()->route('webauthn.lost.form');
        }

        return view('larapass::recover')->with(
            ['token' => $request->query('token'), 'email' => $request->query('email')]
        );
    }

    /**
     * Returns the credential creation options to the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse|void
     */
    public function options(Request $request)
    {
        $user = WebAuthn::getUser($request->validate($this->rules()));

        // We will proceed only if the broker can find the user and the token is valid.
        // If the user doesn't exists or the token is invalid, we will bail out with a
        // HTTP 401 code because the user doing the request is not authorized for it.
        abort_unless(WebAuthn::tokenExists($user, $request->input('token')), 401);

        return response()->json(WebAuthn::generateAttestation($user));
    }

    /**
     * Get the account recovery validation rules.
     *
     * @return array
     */
    protected function rules()
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
        ];
    }

    /**
     * Recover the user account and log him in.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function recover(Request $request)
    {
        $credentials = validator([
            'email' => $request->header('email'),
            'token' => $request->header('token'),
        ], $this->rules())->validate();

        $response = WebAuthn::recover($credentials, function ($user) use ($request) {
            $this->register($request, $user);
        });

        return $response === WebAuthn::RECOVERY_ATTACHED
            ? $this->sendRecoveryResponse($request, $response)
            : $this->sendRecoveryFailedResponse($request, $response);
    }

    /**
     * Registers a device for further WebAuthn authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \DarkGhostHunter\Larapass\Contracts\WebAuthnAuthenticatable  $user
     * @return void
     */
    protected function register(Request $request, WebAuthnAuthenticatable $user)
    {
        $validCredential = WebAuthn::validateAttestation(
            $request->validate($this->attestationRules()), $user
        );

        if ($validCredential) {
            if ($request->filled('unique') || $request->header('WebAuthn-Unique')) {
                $user->disableAllCredentials();
            }

            $user->addCredential($validCredential);

            event(new AttestationSuccessful($user, $validCredential));

            $this->guard()->login($user);
        }
    }

    /**
     * Get the response for a successful account recovery.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $response
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendRecoveryResponse(Request $request, $response)
    {
        return new JsonResponse([
            'message' => trans($response),
            'redirectTo' => $this->redirectPath()
        ], 200);
    }

    /**
     * Get the response for a failed account recovery.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $response
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function sendRecoveryFailedResponse(Request $request, $response)
    {
        throw ValidationException::withMessages([
            'email' => [trans($response)],
        ]);

    }

    /**
     * Returns the Authentication guard.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard();
    }

    /**
     * Get the post recovery redirect path.
     *
     * @return string
     */
    public function redirectPath()
    {
        if (method_exists($this, 'redirectTo')) {
            return $this->redirectTo();
        }

        return property_exists($this, 'redirectTo') ? $this->redirectTo : '/home';
    }
}