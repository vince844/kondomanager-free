<?php

namespace App\Console\Commands;

use App\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnosi delle date di competenza scritte e mai lette — **sola lettura**.
 *
 * `anagrafica_immobile` ha `data_inizio` e `data_fine` dalla creazione della tabella. Sono
 * scritte, sono validate, sono mostrate in elenco — e **nessun calcolo le legge**. Gli otto
 * punti che decidono chi paga filtrano su `attivo` e `tipologia` e basta, e la firma stessa del
 * motore non ha una dimensione temporale: non esiste pro-rata per giorni in nessun punto del
 * progetto.
 *
 * La beta.50 ha corretto i **testi** che promettevano il contrario. Ma un amministratore che si
 * è fidato di quei testi sta addebitando il venditore da mesi, e correggere la scritta non gli
 * dice se è successo a lui. Questo comando è quel modo: *un avviso senza lo strumento per
 * misurare il danno è metà lavoro.*
 *
 * ## Perché non ripara
 *
 * Riparare significherebbe rigenerare quote di piani già emessi, cioè toccare scritture
 * contabili — una decisione dell'amministratore, non di un comando. Qui si dice **se** e
 * **dove**. Stessa scelta di `kondomanager:verifica-saldi-solidali`, e per la stessa ragione.
 *
 * ## I tre segnali, e cosa significano
 *
 * 1. **`data_fine` valorizzata** — qualcuno ha compilato la data credendo che interrompesse
 *    l'addebito. È l'elenco di chi si è fidato.
 * 2. **`attivo = false`** — righe che non partecipano già oggi. Vanno guardate perché
 *    **nessuna interfaccia le può riaccendere**: chi le ha spente l'ha fatto da database o da
 *    un percorso che non esiste più.
 * 3. **Somma delle quote ≠ 100 su (immobile, tipologia)** — è il segnale più importante ed è
 *    quello che costa denaro: sono i subentri rappresentati come comproprietà. Due titolari al
 *    100 % si normalizzano a 200 e prendono il 50 % ciascuno, **identico per un rogito di
 *    gennaio e uno di dicembre**. Il motore addebita metà spesa a chi non è più titolare.
 */
class VerificaTitolaritaCommand extends Command
{
    protected $signature = 'kondomanager:verifica-titolarita
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca le associazioni con date di competenza che il motore di riparto non legge. Non modifica nulla.';

    public function handle(): int
    {
        $condomini = $this->option('condominio')
            ? Condominio::where('id', $this->option('condominio'))->get()
            : Condominio::orderBy('id')->get();

        if ($condomini->isEmpty()) {
            $this->warn('Nessun condominio trovato.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->info('Date di competenza registrate e non lette dal riparto — sola lettura, niente viene modificato.');
        $this->line('');

        $totaleSegnali = 0;

        foreach ($condomini as $condominio) {
            $conFine   = $this->righeConDataFine($condominio->id);
            $spente    = $this->righeSpente($condominio->id);
            $sospette  = $this->quoteNonAlCento($condominio->id);

            $segnali = $conFine->count() + $spente->count() + $sospette->count();
            if ($segnali === 0) {
                continue;
            }
            $totaleSegnali += $segnali;

            $this->line("<options=bold>#{$condominio->id} — {$condominio->nome}</>");

            if ($conFine->isNotEmpty()) {
                $this->line('');
                $this->line("  <fg=yellow>Data fine compilata ({$conFine->count()}):</> il riparto la ignora, l'addebito continua.");
                $this->table(
                    ['Unità', 'Soggetto', 'Ruolo', 'Dal', 'Al', 'Quota'],
                    $conFine->map(fn ($r) => [
                        $r->unita ?? '—', $r->soggetto ?? '—', $r->tipologia,
                        $r->data_inizio ?? '—', $r->data_fine, $r->quota . ' %',
                    ])->all()
                );
            }

            if ($spente->isNotEmpty()) {
                $this->line('');
                $this->line("  <fg=yellow>Associazioni disattivate ({$spente->count()}):</> non partecipano al riparto, e nessuna schermata le riaccende.");
                $this->table(
                    ['Unità', 'Soggetto', 'Ruolo', 'Quota'],
                    $spente->map(fn ($r) => [$r->unita ?? '—', $r->soggetto ?? '—', $r->tipologia, $r->quota . ' %'])->all()
                );
            }

            if ($sospette->isNotEmpty()) {
                $this->line('');
                // ⚠️ **Le cause sono due, e nominarne una sola manda fuori strada.** Fino alla
                // beta.52 qui si leggeva solo «probabile subentro registrato come comproprietà».
                // Poi si è scoperto che sul condominio 33 le due unità con somma 200 venivano da
                // **importazioni ripetute** — la prima aveva scritto i coniugi separati, la
                // seconda la coppia come soggetto unico — e chi leggeva il messaggio andava a
                // cercare un subentro che non c'era mai stato.
                $this->line("  <fg=red>Quote che non fanno 100 ({$sospette->count()}):</> la spesa si divide fra più soggetti di quanti ne abbia davvero l'unità.");
                $this->line('  <fg=gray>Due cause tipiche: un subentro registrato come comproprietà — chi è uscito continua a pagare —</>');
                $this->line("  <fg=gray>oppure due importazioni che hanno portato la stessa proprietà in forme diverse (la coppia come</>");
                $this->line('  <fg=gray>soggetto unico e i due coniugi separati). Guarda le date di creazione delle righe per distinguerle.</>');
                $this->table(
                    ['Unità', 'Ruolo', 'Titolari', 'Somma quote'],
                    $sospette->map(fn ($r) => [$r->unita ?? '—', $r->tipologia, $r->n, $r->somma . ' %'])->all()
                );
            }

            $this->line('');
        }

        if ($totaleSegnali === 0) {
            $this->info('Nessun segnale: nessuna data di competenza compilata, nessuna associazione spenta, tutte le quote fanno 100.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn("{$totaleSegnali} segnali in totale.");
        $this->line('Le date si registrano ma il riparto non le legge: finché è così, un subentro a metà anno va calcolato a mano.');
        $this->line('');

        return self::SUCCESS;
    }

    /** Chi ha compilato la data di fine credendo che interrompesse l'addebito. */
    private function righeConDataFine(int $condominioId)
    {
        return DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->leftJoin('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
            ->where('i.condominio_id', $condominioId)
            ->whereNotNull('ai.data_fine')
            ->orderBy('i.interno')
            ->select([
                'i.interno as unita', 'a.nome as soggetto',
                'ai.tipologia', 'ai.data_inizio', 'ai.data_fine', 'ai.quota',
            ])
            ->get();
    }

    /** Righe già escluse dal riparto, che nessuna interfaccia può riaccendere. */
    private function righeSpente(int $condominioId)
    {
        return DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->leftJoin('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
            ->where('i.condominio_id', $condominioId)
            ->where('ai.attivo', false)
            ->orderBy('i.interno')
            ->select(['i.interno as unita', 'a.nome as soggetto', 'ai.tipologia', 'ai.quota'])
            ->get();
    }

    /**
     * Coppie (immobile, tipologia) con più titolari attivi la cui somma di quote non fa 100.
     *
     * ⚠️ Si guardano solo le righe **attive**: sono quelle che il motore somma davvero. Una
     * riga spenta non entra nel denominatore, quindi non falsa il riparto — e comparirebbe
     * qui come falso allarme.
     */
    private function quoteNonAlCento(int $condominioId)
    {
        return DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->where('i.condominio_id', $condominioId)
            ->where('ai.attivo', true)
            ->groupBy('i.interno', 'ai.immobile_id', 'ai.tipologia')
            ->havingRaw('COUNT(*) > 1 AND ABS(SUM(ai.quota) - 100) > 0.01')
            ->select([
                'i.interno as unita',
                'ai.tipologia',
                DB::raw('COUNT(*) as n'),
                DB::raw('SUM(ai.quota) as somma'),
            ])
            ->get();
    }
}
