<?php

namespace App\Listeners\Gestionale;

use App\Events\Gestionale\RataEmessa;
use App\Models\Evento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CompletaEventoScadenziario implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(RataEmessa $event): void
    {
        $deleted = Evento::whereJsonContains('meta->context->rata_id', $event->rata->id)
            ->whereJsonContains('meta->type', 'emissione_rata')
            ->delete();

        if ($deleted) {
            Log::info("Scadenziario: Task emissione rimosso per rata {$event->rata->id}");
        }
    }
}