<?php

namespace App\Http\Controllers\Newsletter;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class NewsletterController extends Controller
{
    public function subscribe(Request $request)
    {
        $email = $request->user()->email;

        // L'URL DEL FILE CHE HAI APPENA CARICATO SUL TUO SITO
        $proxyUrl = 'https://www.dev.karibusana.org/newsletter-proxy.php';
        
        try {
            $response = Http::post($proxyUrl, [
                'email' => $email
            ]);

            if ($response->successful()) {
                return back()->with('success', 'Iscrizione attivata con successo! Riceverai i prossimi aggiornamenti tecnici.');
            }
        } catch (\Exception $e) {
            return back()->withErrors(['newsletter' => 'Errore di comunicazione. Riprova più tardi.']);
        }

        return back()->withErrors(['newsletter' => 'Errore di comunicazione. Riprova più tardi.']);
    }
}