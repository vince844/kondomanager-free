<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Mail\SendQueuedMailable;
use App\Models\MailLog;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log; 
use Throwable;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Queue::failing(function (JobFailed $event) {
            try {
                // 1. SAFETY CHECK
                if (!Schema::hasTable('mail_logs')) {
                    return;
                }

                $data = $event->job->payload();
                $commandObject = null;
                $recipient = 'Sconosciuto';
                $subject = '(Oggetto sconosciuto)';
                
                // 2. DESERIALIZZAZIONE ISOLATA CON GESTIONE DELL'ECCEZIONE (IL FIX È QUI)
                try {
                    $commandObject = isset($data['data']['command']) 
                        ? unserialize($data['data']['command']) 
                        : null;
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                    // Catturiamo esplicitamente l'errore del modello mancante (es. se lo hai cancellato)
                    $subject = 'Email annullata: Modello (es. Fattura) eliminato prima dell\'invio';
                    Log::info('Tentativo di elaborare un job in coda per un modello eliminato: ' . $e->getModel());
                } catch (Throwable $e) {
                    $subject = 'Impossibile deserializzare l\'email';
                }

                // 3. ESTRAZIONE DATI (solo se la deserializzazione ha avuto successo)
                if ($commandObject instanceof SendQueuedMailable) {
                    $mailable = $commandObject->mailable;
                    $subject = $mailable->subject ?? '(Nessun oggetto)';

                    // 4. ESTRAZIONE DESTINATARIO
                    if (property_exists($mailable, 'to')) {
                         $to = $mailable->to;
                         if (is_array($to) && count($to) > 0) {
                             $first = $to[0];
                             if (is_object($first) && method_exists($first, 'getAddress')) {
                                 $recipient = $first->getAddress(); 
                             } elseif (is_array($first) && isset($first['address'])) {
                                 $recipient = $first['address'];
                             } elseif (is_string($first)) {
                                 $recipient = $first;
                             }
                         }
                    }
                }

                // 5. SCRITTURA NEL LOG (DB) - Ora avverrà SEMPRE!
                MailLog::create([
                    'recipient'     => $recipient,
                    'subject'       => $subject,
                    'mailer'        => config('mail.default'),
                    'status'        => 'failed',
                    'error_message' => substr($event->exception->getMessage(), 0, 1000),
                    'sent_at'       => now(),
                ]);

            } catch (Throwable $e) {
                // FAIL SAFELY EXTREME: Se persino il log fallisce
                Log::warning('Fallimento critico nell\'AuditServiceProvider: ' . $e->getMessage());
            }
        });
    }
}