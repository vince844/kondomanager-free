<?php

namespace App\Console\Commands;

use App\Exceptions\Gestionale\ContoPassateGestioniMancanteException;
use App\Models\Condominio;
use App\Services\Gestionale\RiallineaCreditiService;
use Illuminate\Console\Command;

/**
 * Riallineamento dei crediti da console (decisioni 75.1 e 76.1, 1.11.0-beta.49) — avvolge RiallineaCreditiService.
 *
 * Il percorso primario per l'amministratore è la card «Crediti da riallineare» nella pagina «Incassi rate» (visibile solo se c'è
 * qualcosa da fare): questo comando serve al supporto e agli aggiornamenti assistiti. Scrive solo le rettifiche delle
 * compensazioni di un credito riportato prelevate da «Crediti verso condomini» (DC1) e le quote a credito rimaste fuori dalle
 * rate miste (DC2-b); le rate di soli crediti segnate «emesse» senza righe le elenca, e le emette l'amministratore dalla pagina
 * del piano. Dice che cosa resta com'è e perché. Con --dry-run mostra che cosa scriverebbe, senza scrivere niente.
 */
class RiallineaCreditiCommand extends Command
{
    protected $signature = 'kondomanager:riallinea-crediti
                            {--condominio= : ID del condominio (omesso = tutti)}
                            {--dry-run : Mostra che cosa verrebbe scritto senza scrivere niente}';

    protected $description = 'Riallinea i crediti riportati scritti sul conto sbagliato o lasciati fuori dal giornale (lo facevano le versioni fino alla 1.10)';

    public function handle(RiallineaCreditiService $service): int
    {
        $condomini = $this->option('condominio')
            ? Condominio::where('id', $this->option('condominio'))->get()
            : Condominio::orderBy('id')->get();

        if ($condomini->isEmpty()) {
            $this->error('Nessun condominio trovato.');

            return self::FAILURE;
        }

        $errori = 0;
        foreach ($condomini as $condominio) {
            $rilevato = $service->rileva($condominio);
            $daScrivere = $service->daScrivere($rilevato);

            if ($daScrivere === 0 && $rilevato['da_emettere'] === [] && $rilevato['restano'] === []) {
                $this->line("«{$condominio->nome}»: niente da riallineare.");

                continue;
            }

            if ($daScrivere > 0) {
                $this->info("«{$condominio->nome}»: da scrivere:");
                foreach ($rilevato['compensazioni'] as $c) {
                    $this->line(sprintf('  rettifica della compensazione prot. %s del %s: %s di %s sulla rata %d, da «Crediti verso condomini» a «Passate gestioni»',
                        $c['protocollo'] ?? '?', $c['data'] ?? '—', $c['importo_formattato'], $c['persona'], $c['numero_rata']));
                }
                foreach (collect($rilevato['rate'])->where('azione', 'scrivi') as $r) {
                    $this->line(sprintf('  emissione delle quote a credito rimaste fuori dalla rata %d (%s): %s', $r['numero_rata'], $r['piano'],
                        collect($r['persone'])->pluck('nome')->join(', ')));
                }
            } else {
                $this->line("«{$condominio->nome}»: niente da scrivere.");
            }

            if ($rilevato['da_emettere'] !== []) {
                $this->line('  Da emettere dalla pagina del piano (le emette l\'amministratore, con la data e l\'avviso che sceglie):');
                foreach ($rilevato['da_emettere'] as $r) {
                    $this->line(sprintf('    rata %d (%s): %s', $r['numero_rata'], $r['piano'], $r['url']));
                }
            }

            if ($rilevato['restano'] !== []) {
                $this->line('  Restano come sono:');
                foreach ($rilevato['restano'] as $r) {
                    $this->line('    ' . $r['motivo'] . '.');
                }
            }

            if ($daScrivere === 0 || $this->option('dry-run')) {
                continue;
            }

            try {
                $fatto = $service->esegui($condominio);
                $this->info('  → ' . self::riepilogo($fatto));
            } catch (ContoPassateGestioniMancanteException $e) {
                $errori++;
                $this->error("  «{$condominio->nome}»: " . ContoPassateGestioniMancanteException::MESSAGGIO_COMANDO . ' Niente è stato scritto.');
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Prova: nessuna scrittura creata.');
        }

        return $errori === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Che cosa ha scritto, con il singolare e il plurale giusti e solo i tipi scritti (rilievo LDA-6 della Fase 1-bis): «scritta 1
     * rettifica (…)», «scritte 2 emissioni di quote a credito (…)», le due insieme, o niente.
     *
     * @param array{rettifiche: list<string>, emissioni: list<string>} $fatto
     */
    private static function riepilogo(array $fatto): string
    {
        $parti = [];
        foreach ([['rettifiche', 'rettifica', 'rettifiche', ''], ['emissioni', 'emissione', 'emissioni', ' di quote a credito']] as [$chiave, $una, $piu, $coda]) {
            $n = count($fatto[$chiave]);
            if ($n > 0) {
                $parti[] = ($n === 1 ? "1 {$una}" : "{$n} {$piu}") . $coda . ' (prot. ' . implode(', ', $fatto[$chiave]) . ')';
            }
        }
        if ($parti === []) {
            return 'niente da scrivere: le voci sono già state riallineate.';
        }
        $totale = count($fatto['rettifiche']) + count($fatto['emissioni']);

        return ($totale === 1 ? 'scritta ' : 'scritte ') . implode(' e ', $parti) . '.';
    }
}
