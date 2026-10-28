<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ConfermaPasswordRecente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password page.
     */
    public function show(): Response
    {
        return Inertia::render('auth/ConfirmPassword');
    }

    /**
     * Confirm the user's password.
     *
     * La pagina `password.confirm` la manda come modulo Inertia e riceve il rimando di sempre. La finestra
     * sulla stessa pagina (Coda 404) la manda in JSON e riceve 200 `{"confermata": true}`: rifà da sé la
     * richiesta che aveva ricevuto il 423, quindi non c'è un indirizzo a cui rimandare. La password sbagliata
     * è la stessa `ValidationException`, un 422 con `errors.password` per chi chiede JSON.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        if ($request->expectsJson()) {
            return response()->json(['confermata' => true]);
        }

        return redirect()->intended(RedirectHelper::userHomeRoute());
    }

    /**
     * Lo stato della conferma di questa sessione: la password è stata ridigitata da non più di quindici
     * minuti? Lo chiede la pagina prima di seguire il collegamento di uno scarico, che non è una richiesta
     * Inertia e non può ricevere il 423 senza scaricare il file. Non scrive niente e dice solo della
     * sessione di chi chiede.
     */
    public function stato(Request $request): JsonResponse
    {
        return response()->json(['confermata' => ! ConfermaPasswordRecente::scaduta($request)]);
    }
}
