<?php

namespace App\Events\Segnalazioni;

use App\Models\Segnalazione;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyAdminOfCreatedSegnalazione
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Segnalazione $segnalazione;

    public function __construct(Segnalazione $segnalazione)
    {
        $this->segnalazione = $segnalazione;
    }
}
