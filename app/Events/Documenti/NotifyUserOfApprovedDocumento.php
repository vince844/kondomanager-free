<?php

namespace App\Events\Documenti;

use App\Models\Documento;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyUserOfApprovedDocumento
{
    use Dispatchable, SerializesModels;

    public Documento $documento;
    public $user;

    /**
     * Create a new event instance.
     */
    public function __construct(Documento $documento, $user)
    {
        $this->documento = $documento;
        $this->user = $user;
    }

}
