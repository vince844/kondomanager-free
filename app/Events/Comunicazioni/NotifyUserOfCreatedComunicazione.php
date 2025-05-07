<?php

namespace App\Events\Comunicazioni;

use App\Models\Comunicazione;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyUserOfCreatedComunicazione
{
    use Dispatchable, SerializesModels;

    public array $validated;
    public Comunicazione $comunicazione;

    public function __construct(array $validated, Comunicazione $comunicazione)
    {
        $this->validated = $validated;
        $this->comunicazione = $comunicazione;
    }

}
