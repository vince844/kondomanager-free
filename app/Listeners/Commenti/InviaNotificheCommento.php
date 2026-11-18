<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Listeners\Commenti;

use App\Events\Commenti\CommentoCreato;
use App\Notifications\Commenti\NuovoCommentoNotification;
use App\Notifications\Commenti\CommentoInAttesaNotification;
use App\Services\CommentoNotificationService;
use App\Services\CommentoEventoService;
use Illuminate\Support\Facades\Notification;

class InviaNotificheCommento
{
    public function __construct(
        private CommentoNotificationService $service,
        private CommentoEventoService $inbox
    ) {}

    /**
     * Invia la notifica ai destinatari pertinenti quando viene creato un commento.
     * L'autore del commento non viene mai notificato.
     */
    public function handle(CommentoCreato $evento): void
    {
        $commento = $evento->commento;

        if ($commento->eInAttesa()) {
            $destinatari = $this->service->destinatariInAttesa($commento);

            if ($destinatari->isNotEmpty()) {
                Notification::send($destinatari, new CommentoInAttesaNotification($commento));
            }
        } else {
            $destinatari = $this->service->destinatariPubblicato($commento, [(int) $commento->user_id]);

            if ($destinatari->isNotEmpty()) {
                Notification::send($destinatari, new NuovoCommentoNotification($commento));
            }
        }

        // Canale 2: Admin Inbox (Evento aggregato) per l'amministratore
        $this->inbox->sincronizza($commento);
    }
}
