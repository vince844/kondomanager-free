<?php

namespace App\Events\Documenti;

use App\Models\Documento;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyAdminOfCreatedDocumento
{
    use Dispatchable, SerializesModels;

    public array $validated;
    public Documento $documento;

    /**
     * Create a new event instance.
     */
    public function __construct(array $validated, Documento $documento)
    {
        $this->validated = $validated;
        $this->documento = $documento;
    }

}
