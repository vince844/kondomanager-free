<?php

namespace App\Console\Commands;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnosi delle date di competenza — **sola lettura**.
 *
 * Nata nella 1.10 quando `anagrafica_immobile.data_inizio/data_fine` erano scritte e mai lette da
 * nessun calcolo (la beta.50 aveva corretto i testi che promettevano il contrario; questo comando
 * misurava il danno a chi ci si era fidato: *un avviso senza lo strumento per misurare il danno è
 * metà lavoro*).
 *
 * ➕ **Dalla 1.11.0-beta.31 (B2 del progetto `docs/subentro_e_competenza_temporale.md`) il motore le
 * legge**: `data_fine` filtra sempre, `data_inizio` con un predecessore chiuso (D7), e la spesa si
 * divide per giorni fra chi entra e chi esce (D8). Il segnale cambia verso: una `data_fine` compilata
 * non è più «ignorata», è la data da cui quel soggetto **non paga più** — va controllata perché è
 * voluta, non perché è inerte. Il piano B2 chiede di lanciare questo comando **prima di rigenerare un
 * piano con il motore nuovo**: le righe elencate sono quelle che cambieranno destinatario.
 *
 * ## Perché non ripara
 *
 * Riparare significherebbe rigenerare quote di piani già emessi, cioè toccare scritture
 * contabili — una decisione dell'amministratore, non di un comando. Qui si dice **se** e
 * **dove**. Stessa scelta di `kondomanager:verifica-saldi-solidali`, e per la stessa ragione.
 *
 * ## I tre segnali, e cosa significano
 *
 * 1. **`data_fine` valorizzata** — dalla beta.31 interrompe davvero l'addebito da quel giorno: è
 *    l'elenco delle righe che il motore temporale tratta diversamente da prima.
 * 2. **`attivo = false`** — righe che non partecipano già oggi. Vanno guardate perché
 *    **nessuna interfaccia le può riaccendere**: chi le ha spente l'ha fatto da database o da
 *    un percorso che non esiste più.
 * 3. **Somma delle quote ≠ 100 su (immobile, tipologia)** fra le righe **in corso** — è il segnale
 *    più importante ed è quello che costa denaro: sono i subentri rappresentati come comproprietà,
 *    senza data di fine. Due titolari al 100 % si normalizzano a 200 e prendono il 50 % ciascuno.
 *    Dalla beta.31 una riga chiusa non entra nella somma: venditore chiuso e acquirente in corso
 *    non sono più un falso allarme, sono un passaggio registrato bene.
 */
class VerificaTitolaritaCommand extends Command
{
    protected $signature = 'kondomanager:verifica-titolarita
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca le associazioni con date di competenza che cambiano il riparto (chiuse, spente, quote che non fanno 100). Non modifica nulla.';

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
        $this->info('Date di competenza che il riparto legge dalla 1.11.0-beta.31 — sola lettura, niente viene modificato.');
        $this->line('');

        $totaleSegnali = 0;

        foreach ($condomini as $condominio) {
            $conFine   = $this->righeConDataFine($condominio->id);
            $spente    = $this->righeSpente($condominio->id);
            $sospette  = $this->quoteNonAlCento($condominio->id);
            $stessoGiorno = $this->chiusuraEAperturaLoStessoGiorno($condominio->id);

            $segnali = $conFine->count() + $spente->count() + $sospette->count() + $stessoGiorno->count();
            if ($segnali === 0) {
                continue;
            }
            $totaleSegnali += $segnali;

            $this->line("<options=bold>#{$condominio->id} — {$condominio->nome}</>");

            if ($conFine->isNotEmpty()) {
                $this->line('');
                $this->line("  <fg=yellow>Data fine compilata ({$conFine->count()}):</> da quel giorno il soggetto non paga più; alla prossima generazione paga solo chi è titolare nel periodo di competenza — per giorni sull'ordinario, alla data della delibera sullo straordinario.");
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

            if ($stessoGiorno->isNotEmpty()) {
                $this->line('');
                // D7 stretto (decisione 23, 1.11.0-beta.31): il predecessore è la riga chiusa il GIORNO PRIMA. Una coppia
                // scritta a mano prima della beta.31 con chiusura e apertura lo stesso giorno («venduto il 30/6»: 30/6 e
                // 30/6) non ha più un predecessore — l'acquirente è «aperto da sempre» e paga l'anno intero.
                $this->line("  <fg=red>Chiusura e apertura lo stesso giorno ({$stessoGiorno->count()}):</> per il motore la seconda riga non ha un predecessore e conta dall'inizio del periodo.");
                $this->line('  <fg=gray>Sposta la chiusura al giorno prima, o la decorrenza al giorno dopo: il passaggio è di un giorno solo.</>');
                $this->table(
                    ['Unità', 'Ruolo', 'Chi esce', 'Chi entra', 'Giorno', 'Somma quel giorno'],
                    $stessoGiorno->map(fn ($r) => [$r->unita ?? '—', $r->tipologia, $r->uscente ?? '—', $r->entrante ?? '—', $r->giorno, $r->somma . ' %'])->all()
                );
            }

            $this->line('');
        }

        if ($totaleSegnali === 0) {
            $this->info('Nessun segnale: nessuna data di fine compilata, nessuna associazione spenta, tutte le quote in corso fanno 100, nessuna chiusura e apertura lo stesso giorno.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn("{$totaleSegnali} segnali in totale.");
        $this->line('Il riparto legge le date: prima di rigenerare un piano controlla che ogni data di fine elencata sia voluta.');
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
    /**
     * Le coppie (stessa unità, stesso ruolo) con una riga chiusa il giorno in cui l'altra decorre, e la somma delle
     * quote quel giorno sopra 100: per D7 stretto (decisione 23) la seconda non ha un predecessore. Scritte a mano
     * prima della guardia per giorno della 1.11.0-beta.31 (verifica S8-bis, L2-5).
     */
    private function chiusuraEAperturaLoStessoGiorno(int $condominioId)
    {
        return DB::table('anagrafica_immobile as a')
            ->join('anagrafica_immobile as b', function ($j) {
                $j->on('b.immobile_id', '=', 'a.immobile_id')
                    ->on('b.tipologia', '=', 'a.tipologia')
                    ->on('b.data_inizio', '=', 'a.data_fine')
                    ->on('b.id', '!=', 'a.id');
            })
            ->join('immobili as i', 'i.id', '=', 'a.immobile_id')
            ->leftJoin('anagrafiche as au', 'au.id', '=', 'a.anagrafica_id')
            ->leftJoin('anagrafiche as ab', 'ab.id', '=', 'b.anagrafica_id')
            ->where('i.condominio_id', $condominioId)
            ->where('a.attivo', true)->where('b.attivo', true)
            ->whereNotNull('a.data_fine')
            ->whereRaw('a.quota + b.quota > 100')
            ->orderBy('i.interno')
            ->get([
                'i.interno as unita', 'a.tipologia', 'au.nome as uscente', 'ab.nome as entrante', 'a.data_fine as giorno',
                DB::raw('a.quota + b.quota as somma'),
            ]);
    }

    private function quoteNonAlCento(int $condominioId)
    {
        $oggi = DateHelper::oggiUtente();

        return DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->where('i.condominio_id', $condominioId)
            ->where('ai.attivo', true)
            // B2: una riga chiusa a oggi, o che decorre dopo oggi, non entra nella somma — è un passaggio, non una
            // comproprietà (S8-13: il venditore chiuso al 14/10 e l'acquirente dal 15/10 facevano 200 fino a quel giorno).
            ->where(fn ($q) => $q->whereNull('ai.data_fine')->orWhereDate('ai.data_fine', '>=', $oggi))
            ->where(fn ($q) => $q->whereNull('ai.data_inizio')->orWhereDate('ai.data_inizio', '<=', $oggi))
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
