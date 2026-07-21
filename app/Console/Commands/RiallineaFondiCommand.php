<?php

namespace App\Console\Commands;

use App\Models\Condominio;
use App\Services\Gestionale\RiallineaFondiService;
use Illuminate\Console\Command;

/**
 * Riallineamento fondi da console — avvolge RiallineaFondiService.
 *
 * Il percorso primario per l'amministratore è la card nella pagina Giroconti
 * (visibile solo se c'è qualcosa da riallineare): questo comando serve al
 * supporto e agli aggiornamenti assistiti. Con --dry-run mostra cosa verrebbe
 * rettificato senza scrivere nulla.
 */
class RiallineaFondiCommand extends Command
{
    protected $signature = 'kondomanager:riallinea-fondi
                            {--condominio= : ID del condominio (omesso = tutti)}
                            {--dry-run : Mostra le scritture da rettificare senza crearle}';

    protected $description = 'Neutralizza le righe storiche sui fondi scritte prima della beta.19 con scritture di rettifica';

    public function handle(RiallineaFondiService $service): int
    {
        $condomini = $this->option('condominio')
            ? Condominio::where('id', $this->option('condominio'))->get()
            : Condominio::all();

        if ($condomini->isEmpty()) {
            $this->error('Nessun condominio trovato.');

            return self::FAILURE;
        }

        $totale = 0;

        foreach ($condomini as $condominio) {
            $rilevate = $service->rileva($condominio);

            if ($rilevate->isEmpty()) {
                $this->line("«{$condominio->nome}»: nessuna scrittura da riallineare.");

                continue;
            }

            $this->info("«{$condominio->nome}»: {$rilevate->count()} scritture da riallineare:");
            foreach ($rilevate as $item) {
                $this->line(sprintf(
                    '  %s del %s — %s (netto fondo: %+.2f €)',
                    $item['protocollo'] ?? 'SCR-?',
                    $item['data'] ?? '—',
                    $item['causale'] ?? '—',
                    $item['importo_netto_fondo'] / 100
                ));
            }

            if ($this->option('dry-run')) {
                continue;
            }

            $esito = $service->esegui($condominio);
            $totale += $esito['rettificate'];
            $this->info("  → create {$esito['rettificate']} rettifiche: ".implode(', ', $esito['protocolli']));
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry-run: nessuna scrittura creata.');
        } else {
            $this->info("Totale rettifiche create: {$totale}.");
        }

        return self::SUCCESS;
    }
}
