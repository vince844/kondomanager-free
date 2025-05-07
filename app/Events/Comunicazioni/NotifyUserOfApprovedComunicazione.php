<?php

namespace App\Events\Comunicazioni;

use App\Models\Comunicazione;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyUserOfApprovedComunicazione
{
    use Dispatchable, SerializesModels;

    public Comunicazione $comunicazione;
    public $user;

    public function __construct(Comunicazione $comunicazione, $user)
    {
        $this->comunicazione = $comunicazione;
        $this->user = $user;
    }

}
