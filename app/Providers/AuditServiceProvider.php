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
                
                // 2. DESERIALIZZAZIONE
                $commandObject = isset($data['data']['command']) 
                    ? unserialize($data['data']['command']) 
                    : null;

                // 3. FILTRO
                if ($commandObject instanceof SendQueuedMailable) {
                    $mailable = $commandObject->mailable;
                    $recipient = 'Sconosciuto';

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

                    // 5. SCRITTURA NEL LOG (DB)
                    MailLog::create([
                        'recipient'     => $recipient,
                        'subject'       => $mailable->subject ?? '(Nessun oggetto)',
                        'mailer'        => config('mail.default'),
                        'status'        => 'failed',
                        'error_message' => substr($event->exception->getMessage(), 0, 1000),
                        'sent_at'       => now(),
                    ]);
                }
            } catch (Throwable $e) {
                // FAIL SAFELY: Il worker non si ferma, ma noi abbiamo traccia del problema!
                Log::warning('Impossibile salvare il log di audit mail: ' . $e->getMessage());
            }
        });
    }
}