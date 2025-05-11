<?php

namespace App\Events\Segnalazioni;

use App\Models\Segnalazione;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyUserOfApprovedSegnalazione
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Segnalazione $segnalazione;
    public $user;

    public function __construct(Segnalazione $segnalazione, $user)
    {
        $this->segnalazione = $segnalazione;
        $this->user = $user;
    }

}
