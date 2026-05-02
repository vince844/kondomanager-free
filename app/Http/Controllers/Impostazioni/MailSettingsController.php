<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Models\MailLog;
use App\Settings\MailSettings;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

/**
 * MailSettingsController
 *
 * Gestisce la configurazione del sistema di invio email dal pannello
 * amministrativo. Le impostazioni vengono salvate nel database tramite
 * Spatie Laravel Settings e applicate a runtime dal MailConfigServiceProvider.
 *
 * Driver supportati: smtp, sendmail.
 */
class MailSettingsController extends Controller
{
    use HandleFlashMessages;

    /**
     * Mostra la pagina di configurazione email.
     * Passa al frontend le impostazioni correnti, l'host SMTP dal .env
     * (per mostrare lo stato del fallback) e un flag che indica se
     * la password è già salvata (per gestire il placeholder dinamico).
     */
    public function edit(MailSettings $settings)
    {
        return Inertia::render('impostazioni/impostazioniMail', [
            'settings'      => $settings,
            'mail_host_env' => config('mail.mailers.smtp.host'),
            'password_set'  => !empty($settings->mail_password),
        ]);
    }

    /**
     * Salva la configurazione email nel database.
     *
     * Logica di validazione condizionale:
     * - I campi SMTP (host, port, password) sono obbligatori solo se
     *   il driver è smtp E la configurazione è abilitata.
     * - Con sendmail non servono credenziali, solo il mittente.
     * - La password viene criptata prima del salvataggio.
     * - Se l'utente non reinserisce la password, quella esistente viene mantenuta.
     * - Quando si passa a sendmail, i campi SMTP vengono azzerati.
     */
    public function update(Request $request, MailSettings $settings)
    {
        $driver = $request->mail_driver ?? 'smtp';

        $rules = [
            'mail_enabled'      => 'boolean',
            'mail_driver'       => 'required|in:smtp,sendmail',
            'mail_from_address' => 'required|email',
            'mail_from_name'    => 'nullable|string',
        ];

        // Campi SMTP obbligatori solo se il driver è smtp e la mail è abilitata
        if ($request->boolean('mail_enabled') && $driver === 'smtp') {
            $rules['mail_host'] = 'required|string';
            $rules['mail_port'] = 'required|numeric';
            // Password obbligatoria solo al primo salvataggio (quando non esiste ancora)
            $rules['mail_password'] = empty($settings->mail_password)
                ? 'required|string'
                : 'nullable|string';
        }

        $request->validate($rules);

        try {
            $settings->mail_enabled      = $request->boolean('mail_enabled');
            $settings->mail_driver       = $driver;
            $settings->mail_from_address = $request->mail_from_address;
            $settings->mail_from_name    = $request->mail_from_name;

            if ($driver === 'smtp') {
                $settings->mail_host       = $request->mail_host;
                $settings->mail_port       = (int) $request->mail_port;
                $settings->mail_username   = $request->mail_username;
                $settings->mail_encryption = $request->mail_encryption;
                // Aggiorna la password solo se l'utente ne ha inserita una nuova
                if ($request->filled('mail_password')) {
                    $settings->mail_password = Crypt::encryptString($request->mail_password);
                }
            } else {
                // Sendmail non usa credenziali SMTP — puliamo i campi per consistenza
                $settings->mail_host       = null;
                $settings->mail_port       = 587; // valore di default
                $settings->mail_username   = null;
                $settings->mail_password   = null;
                $settings->mail_encryption = null;
            }

            $settings->save();

            return back()->with($this->flashSuccess(__('impostazioni.success_save_mail_settings')));

        } catch (\Exception $e) {
            return back()->with($this->flashError(__('impostazioni.error_save_mail_settings')));
        }
    }

    /**
     * Esegue un test di invio email con la configurazione corrente del form.
     *
     * Applica temporaneamente la configurazione a runtime (senza salvarla)
     * e tenta l'invio di una email di prova all'indirizzo specificato.
     * In caso di errore, salva il dettaglio nel MailLog per diagnostica.
     *
     * Nota: usa i valori del form, non quelli già salvati nel DB,
     * così l'utente può testare prima di salvare. Per la password,
     * se il form è vuoto usa quella già salvata nel DB (decriptata).
     */
    public function testConnection(Request $request, MailSettings $settings)
    {
        $driver = $request->mail_driver ?? 'smtp';

        try {
            if ($driver === 'smtp') {
                // Priorità alla password del form; fallback a quella salvata nel DB
                $password = $request->mail_password;
                if (empty($password) && !empty($settings->mail_password)) {
                    $password = $this->decryptOrRaw($settings->mail_password);
                }

                Config::set('mail.default', 'smtp');
                Config::set('mail.mailers.smtp.host',       $request->mail_host);
                Config::set('mail.mailers.smtp.port',       (int) $request->mail_port);
                Config::set('mail.mailers.smtp.username',   $request->mail_username);
                Config::set('mail.mailers.smtp.password',   $password);
                Config::set('mail.mailers.smtp.encryption', $request->mail_encryption);
            } else {
                // Sendmail: nessuna credenziale necessaria
                Config::set('mail.default', 'sendmail');
            }

            Config::set('mail.from.address', $request->mail_from_address);
            Config::set('mail.from.name',    $request->mail_from_name ?? 'Kondomanager');

            // Forza la ricreazione del mailer con i nuovi parametri
            if (app()->resolved('mail.manager')) {
                app('mail.manager')->forgetMailers();
            }

            Mail::raw('Test invio email riuscito!', function ($m) use ($request) {
                $m->to($request->test_email)->subject('Test Connessione');
            });

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            // Salva il fallimento nel log email per diagnostica
            MailLog::create([
                'recipient'     => $request->test_email,
                'subject'       => 'Test Connessione (Fallito)',
                'mailer'        => $driver,
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'sent_at'       => now(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Decripta una stringa cifrata con Crypt::encryptString().
     * Se la decriptazione fallisce (es. dati non cifrati o chiave cambiata),
     * restituisce la stringa grezza come fallback.
     */
    private function decryptOrRaw(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value;
        }
    }
}