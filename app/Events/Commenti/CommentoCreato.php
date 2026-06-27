<?php

namespace App\Events\Commenti;

use App\Models\Commento;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentoCreato
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Commento $commento) {}
}
