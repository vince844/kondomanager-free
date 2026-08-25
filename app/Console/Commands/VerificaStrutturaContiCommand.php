<?php

namespace App\Console\Commands;

use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnosi delle voci di spesa fuori struttura e dei rami finanziati a zero — **sola lettura**.
 *
 * Il piano dei conti prevede **due** livelli: il capitolo raggruppa, il sottoconto porta
 * l'importo e la tabella millesimale. Dalla beta.16 la validazione impedisce di crearne un
 * terzo. Ma fino alla 1.9.1 il menu «capitolo padre» elencava ogni voce con importo 0, e un
 * sottoconto non ancora budgetato ha importo 0: compariva quindi indistinguibile da un
 * capitolo vero, e sceglierlo creava un terzo livello con due clic. Nessuna migrazione
 * appiattisce quei dati, che arrivano intatti sulla 1.10.
 *
 * ## Perché non basta aver corretto il difetto
 *
 * La beta.51 corregge la generazione: da qui in avanti un ramo a tre livelli viene addebitato
 * per intero. Ma i piani **già generati** restano come sono, e chi li ha emessi ha chiesto ai
 * condòmini meno del dovuto senza che niente glielo dicesse — nessuna eccezione, nessuno
 * scoperto, e perfino il badge «disallineato» spento, perché confrontava lo zero della pivot
 * con lo zero addebitato. *Un avviso senza lo strumento per misurare il danno è metà lavoro.*
 *
 * ## Perché non ripara
 *
 * Rigenerare un piano significa toccare le rate, e se sono state emesse anche le scritture
 * contabili: è una decisione dell'amministratore, non di un comando. Qui si dice **se** e
 * **dove**. Stessa scelta di `kondomanager:verifica-titolarita` e di
 * `kondomanager:verifica-saldi-solidali`, per la stessa ragione.
 *
 * ## I due segnali
 *
 * 1. **Voci oltre il secondo livello** — la struttura da appiattire. Finché restano lì, ogni
 *    piano rate nuovo generato *selezionandole a mano* funziona, ma la voce non compare nei
 *    totali di pagina né nella stampa della distinta.
 * 2. **Rami finanziati a zero con budget sotto** — è il segnale che costa denaro. Una riga di
 *    `piano_rate_capitoli` a importo 0 su un conto che ha foglie con preventivo: quel ramo è
 *    stato congelato a € 0,00. Lo zero **legittimo** — capitolo davvero vuoto, sorgente
 *    svuotata da «sposta spesa» — non compare, perché sotto non ha niente da ripartire.
 */
class VerificaStrutturaContiCommand extends Command
{
    protected $signature = 'kondomanager:verifica-struttura-conti
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca le voci di spesa oltre il secondo livello e i rami di piano rate finanziati a zero. Non modifica nulla.';

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
        $this->info('Struttura del piano dei conti e rami finanziati a zero — sola lettura, niente viene modificato.');
        $this->line('');

        $totaleSegnali = 0;

        foreach ($condomini as $condominio) {
            $fuoriStruttura = $this->vociOltreIlSecondoLivello($condominio->id);
            $ramiAZero      = $this->ramiFinanziatiAZero($condominio->id);

            $segnali = $fuoriStruttura->count() + count($ramiAZero);
            if ($segnali === 0) {
                continue;
            }
            $totaleSegnali += $segnali;

            $this->line("<options=bold>#{$condominio->id} — {$condominio->nome}</>");

            if ($fuoriStruttura->isNotEmpty()) {
                $this->line('');
                // Stessa etichetta che l'amministratore legge nell'albero del piano dei conti:
                // chi lancia il comando sul server e poi apre la pagina non deve tradurre.
                $this->line("  <fg=yellow>Voci fuori struttura ({$fuoriStruttura->count()}):</> il piano dei conti prevede due livelli. Spostale sotto un capitolo di primo livello, oppure eliminale.");
                $this->table(
                    ['Voce', 'Codice', 'Sotto-conto padre', 'Capitolo', 'Preventivo'],
                    $fuoriStruttura->map(fn ($r) => [
                        $r->nome,
                        $r->codice ?? '—',
                        $r->padre,
                        $r->nonno,
                        $this->euro((int) $r->importo),
                    ])->all()
                );
            }

            if (! empty($ramiAZero)) {
                $this->line('');
                $this->line('  <fg=red>Voci finanziate a zero (' . count($ramiAZero) . '):</> hanno un preventivo sotto, ma il piano le ha congelate a € 0,00. Le rate chiedono meno del dovuto.');
                $this->table(
                    ['Piano', 'Stato', 'Voce', 'Origine', 'Non addebitato'],
                    array_map(fn ($r) => [
                        $r['piano'],
                        $r['stato'],
                        $r['voce'],
                        $r['note'] ?? '—',
                        $this->euro($r['mancante']),
                    ], $ramiAZero)
                );
            }

            $this->line('');
        }

        if ($totaleSegnali === 0) {
            $this->info('Nessun segnale: nessuna voce oltre il secondo livello, nessun ramo finanziato a zero.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn("{$totaleSegnali} segnali in totale.");
        $this->line('');
        // Il consiglio ovvio — «rigenera il piano» — sarebbe sbagliato, ed è il tipo di errore
        // che spende in una riga tutta la fiducia nel comando: il ricalcolo riusa gli importi
        // congelati in `piano_rate_capitoli` e riprodurrebbe lo stesso zero. Sui piani emessi o
        // chiusi non partirebbe nemmeno, perché ci sono già scritture e incassi.
        $this->line('<options=bold>Cosa fare, e l\'ordine conta:</>');
        $this->line('  1. <options=bold>prima</> sposta la voce fuori struttura sotto un capitolo di primo livello,');
        $this->line('     dal piano dei conti. Finché resta dov\'è, un piano rate generato includendo tutte');
        $this->line('     le voci continuerà a non addebitarla;');
        $this->line('  2. <options=bold>poi</>, per una voce finanziata a zero su un piano in <options=bold>bozza</>: elimina il');
        $this->line('     piano e ricrealo. Il ricalcolo non basta — riusa gli importi congelati e rifà lo');
        $this->line('     stesso zero. E rigenerare senza aver prima appiattito la struttura non serve a niente;');
        $this->line('  3. per un piano <options=bold>emesso</> o <options=bold>chiuso</>: le rate sono già partite e la');
        $this->line('     differenza va recuperata a conguaglio. Non tentare il ricalcolo: è bloccato,');
        $this->line('     e sbloccarlo annullando emissioni e incassi è una decisione contabile, non tecnica.');
        $this->line('');

        return self::SUCCESS;
    }

    /** Le voci con un nonno: terzo livello e oltre. */
    private function vociOltreIlSecondoLivello(int $condominioId)
    {
        return DB::table('conti as c')
            ->join('conti as p', 'p.id', '=', 'c.parent_id')
            ->join('conti as n', 'n.id', '=', 'p.parent_id')
            ->join('piani_conti as pc', 'pc.id', '=', 'c.piano_conto_id')
            ->where('pc.condominio_id', $condominioId)
            ->orderBy('n.nome')->orderBy('p.nome')->orderBy('c.nome')
            ->select([
                'c.id', 'c.nome', 'c.codice', 'c.importo',
                'p.nome as padre', 'n.nome as nonno',
            ])
            ->get();
    }

    /**
     * Le righe di pivot a zero su un conto che ha invece un preventivo sotto di sé.
     *
     * Lo zero in pivot non è di per sé un difetto: la migrazione di popolamento ne scrive uno
     * per ogni capitolo davvero vuoto («Capitolo vuoto»), e «sposta spesa» può azzerare la
     * sorgente. Il discrimine non è il valore, è **cosa c'è sotto**: zero perché non c'è
     * niente da ripartire è legittimo, zero benché ci sia un preventivo è la perdita.
     */
    private function ramiFinanziatiAZero(int $condominioId): array
    {
        $righe = DB::table('piano_rate_capitoli as prc')
            ->join('piani_rate as pr', 'pr.id', '=', 'prc.piano_rate_id')
            ->join('conti as c', 'c.id', '=', 'prc.conto_id')
            ->where('pr.condominio_id', $condominioId)
            ->where('prc.importo', 0)
            // «Sposta spesa» può azzerare deliberatamente la pivot della voce sorgente, e
            // `conti.importo` resta al valore originale: senza questa esclusione ogni
            // spostamento a saldo pieno verrebbe denunciato come denaro perduto. La tabella
            // dei movimenti è già l'audit di quella scelta — chi c'è dentro l'ha voluto.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('budget_movements as bm')
                ->whereColumn('bm.source_conto_id', 'prc.conto_id')
                ->whereColumn('bm.piano_rate_id', 'prc.piano_rate_id'))
            ->orderBy('pr.id')
            ->select(['prc.conto_id', 'prc.note', 'pr.nome as piano', 'pr.stato', 'c.nome as voce'])
            ->get();

        $trovati = [];

        foreach ($righe as $riga) {
            $conto = Conto::find($riga->conto_id);
            if (! $conto) {
                continue;
            }

            $mancante = $this->sommaDelleFoglie($conto);
            if ($mancante <= 0) {
                continue; // zero legittimo: sotto non c'è niente da ripartire
            }

            $trovati[] = [
                'piano'     => $riga->piano,
                'stato'     => $riga->stato,
                'voce'      => $riga->voce,
                'note'      => $riga->note,
                'mancante'  => $mancante,
            ];
        }

        return $trovati;
    }

    /** Quanto vale davvero il ramo: la somma delle foglie, a qualsiasi profondità. */
    private function sommaDelleFoglie(Conto $conto): int
    {
        $figli = $conto->sottoconti;

        if ($figli->isEmpty()) {
            return (int) $conto->importo;
        }

        $totale = 0;
        foreach ($figli as $figlio) {
            $totale += $this->sommaDelleFoglie($figlio);
        }

        return $totale;
    }

    private function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }
}
