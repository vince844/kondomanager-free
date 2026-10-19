<?php

namespace App\Models\Gestionale;

use App\Traits\RisolveIFigliDelleRotte;
use App\Models\Fornitore;
use App\Models\Tabella;
use Database\Factories\Gestionale\ContoFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class Conto extends Model
{
    use RisolveIFigliDelleRotte;

    use HasFactory;

    protected $table = 'conti';

    /**
     * `importo` è il preventivo deliberato della voce, in centesimi interi e **IVA INCLUSA**.
     *
     * Non è una convenzione facoltativa: è il lordo che ci si confronta, perché lo speso di una
     * voce somma imponibile più imposta, e perché quando la spesa supera il preventivo è proprio
     * quel lordo che finisce scritto qui (`FatturaPassivaService`). Un preventivo caricato al
     * netto risulta quindi sforato appena si registra la prima fattura.
     *
     * ⚠️ Attenzione a leggere `importo` da una voce che arriva dal Piano dei conti: il suo
     * controller lo porta in memoria al maggiore fra preventivo e speso — il *fabbisogno* — e in
     * quel contesto il preventivo deliberato è esposto a parte come `budget_originale_raw`.
     */
    protected $fillable = [
        'piano_conto_id',
        'conto_contabile_id',
        'default_fornitore_id',
        'parent_id',
        'is_capitolo', // fatto esplicito, mai indovinato da importo/parent_id — vedi migrazione add_is_capitolo
        'richiede_gia_versato', // filtra l'elenco "Già versato" (beta.27) — stesso principio di is_capitolo
        'codice',
        'nome',
        'descrizione',
        'tipo',                 
        'tipo_spesa',           
        'tipo_ripartizione',   // NUOVO: millesimale, custom, ad_personam
        'origine_decisionale', // NUOVO: gestione_corrente, delibera_assembleare
        'is_tecnico', // true per conti generati on-the-fly (sopravvenienze + padre). Invisibili al preventivo ordinario.
        'importo',
        'destinazione_id',
        'destinazione_type',
        'note',
        'is_visibile',
    ];

    protected $casts = [
        'is_tecnico' => 'boolean',
        'is_capitolo' => 'boolean',
        'richiede_gia_versato' => 'boolean',
    ];
    
    /** * =========================================================================
     * I BINARI DI ROUTING (La Matrice Decisionale Ordinario vs Straordinario)
     * =========================================================================
     */

    /**
     * SCOPE: Filtra le voci che DEVONO finire nel Piano Rate Ordinario
     * Regola: Deve essere una spesa collettiva (millesimale) di gestione corrente.
     */
    public function scopeForPianoOrdinario(Builder $query): Builder
    {
        return $query->where('tipo_ripartizione', 'millesimale')
                     ->where('origine_decisionale', 'gestione_corrente');
    }

    /**
     * SCOPE: Filtra le voci che DEVONO finire nel Piano Rate Straordinario (Wallet)
     * Regola: Se c'è una delibera specifica OPPURE se la ripartizione è privata/non standard.
     */
    public function scopeForPianoStraordinario(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('origine_decisionale', 'delibera_assembleare')
              ->orWhereIn('tipo_ripartizione', ['custom', 'ad_personam']);
        });
    }

    /**
     * SCOPE: Esclude i conti tecnici (sopravvenienze generate on-the-fly).
     * Usato dal wizard Piano Ordinario e dal PDF preventivo deliberato.
     */
    public function scopeVisibili(Builder $query): Builder
    {
        return $query->where('is_tecnico', false);
    }

    /** * RELAZIONI 
     */
    public function pianoConto()
    {
        return $this->belongsTo(PianoConto::class, 'piano_conto_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function sottoconti()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Quanto le unità hanno già versato per questa voce (beta.26/27). */
    public function contributiVersati()
    {
        return $this->morphMany(ContributoVersato::class, 'target');
    }

    public function contoContabile()
    {
        return $this->belongsTo(ContoContabile::class, 'conto_contabile_id');
    }

    public function destinazione()
    {
        return $this->morphTo();
    }

    public function tabelleMillesimali()
    {
        return $this->hasMany(ContoTabellaMillesimale::class);
    }

    public function tabelle()
    {
        return $this->belongsToMany(Tabella::class, 'conto_tabella_millesimale')
            ->withPivot('coefficiente')
            ->withTimestamps();
    }

    public function ripartizioni()
    {
        return $this->hasManyThrough(
            ContoTabellaRipartizione::class,
            ContoTabellaMillesimale::class,
            'conto_id',
            'conto_tabella_millesimale_id'
        );
    }

    public function pianiRate(): BelongsToMany
    {
        return $this->belongsToMany(PianoRate::class, 'piano_rate_capitoli', 'conto_id', 'piano_rate_id');
    }

    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'default_fornitore_id');
    }

    /**
     * METODI HELPER 
     */
    public function getAllChildrenIds(): array
    {
        $ids = [];
        foreach ($this->sottoconti as $sottoconto) {
            $ids[] = $sottoconto->id;
            $ids = array_merge($ids, $sottoconto->getAllChildrenIds());
        }
        return $ids;
    }

    public function getAllAncestorsIds(): array
    {
        $ids = [];
        $parent = $this->parent;
        while ($parent) {
            $ids[] = $parent->id;
            $parent = $parent->parent;
        }
        return $ids;
    }

    /**
     * I tipi di voce che si addebitano alle persone (segno +1). Ogni altro tipo si accredita (segno −1).
     *
     * È l'unica lista fuori dal motore di ripartizione: il motore applica la stessa clausola con tre copie letterali sue
     * (`CalcoloQuoteService`), e chi conta le voci del piano (atteso, totali delle pagine, copertura del cruscotto) legge il
     * segno da qui. Un test (`T1.12`) fa sì che nessun'altra copia nasca altrove.
     */
    public const TIPI_DI_SPESA = ['spesa', 'uscita'];

    public static function segnoDelTipo(string $tipo): int
    {
        return in_array($tipo, self::TIPI_DI_SPESA, true) ? 1 : -1;
    }

    /**
     * Come il motore divide l'importo di una voce senza sottoconti: il valore assoluto, dal lato del suo tipo
     * (`CalcoloQuoteService::processaConti`, il ramo con l'importo del piano e quello con l'importo della voce).
     *
     * È la regola di ogni totale che conta le voci del piano dei conti: una spesa scritta con il meno (dato delle versioni fino alla
     * 1.11.0-beta.51) il motore la addebita in positivo, e un totale che la sommasse col segno direbbe un preventivo diverso da quello
     * che le quote chiedono. Il ramo senza sottoconti di {@see ripartoPerSegno()} la chiama.
     *
     * @return array{spese: int, entrate: int} in centesimi, non negativi
     */
    public static function partiDelTipo(string $tipo, int $cents): array
    {
        return self::segnoDelTipo($tipo) > 0
            ? ['spese' => abs($cents), 'entrate' => 0]
            : ['spese' => 0, 'entrate' => abs($cents)];
    }

    /** Una voce di spesa scritta con il meno: il motore la addebita in positivo, e un piano nuovo non la deve addebitare. */
    public static function eSpesaNegativa(string $tipo, int $cents): bool
    {
        return self::segnoDelTipo($tipo) > 0 && $cents < 0;
    }

    /** Gli stati di un piano rate in cui le voci che tiene, con i loro sottoconti, non cambiano importo, padre, tipo, tabella né percentuali ({@see pianiCheBloccanoLaVoce()}). */
    public const STATI_CHE_BLOCCANO_L_IMPORTO = ['approvato', 'emesso', 'chiuso'];

    /**
     * I piani che tengono ciascuna voce data in uno stato che blocca: quelli con una riga per la voce o per un capitolo sopra di lei, a qualunque livello, e i
     * piani straordinari che finanziano una fattura con una riga di spesa fuori preventivo, o una copertura di sopravvenienza, sulla voce o su un capitolo sopra
     * di lei.
     * È l'unica regola del blocco dei piani approvati. Finché un piano così la tiene, la voce non cambia importo, padre, tipo, tabella né percentuali e non si
     * elimina («Modifica inibita», `ContoController`): il motore la raggiunge da una di quelle righe, e la modifica cambierebbe ciò che il piano addebita. Una
     * voce non si sposta neppure sotto un capitolo che un piano così tiene: la regola si chiede per il capitolo. La scheda della voce, il pannello delle tabelle,
     * l'elenco dei capitoli padre e le frasi che propongono di correggere una voce la leggono da qui, per non proporre una modifica che il salvataggio rifiuta.
     *
     * La regola legge le righe, come le ha sempre lette per l'importo: un piano che tiene il capitolo con le sue tabelle e una copia non ripartisce il
     * sottoconto, ma lo tiene lo stesso. Una riga sotto la voce non la tiene: il motore parte dalla riga più in alto della catena. Un piano straordinario
     * ripartisce sulle tabelle della voce le righe di spesa fuori preventivo (`is_sopravvenienza`, senza unità) e le coperture di sopravvenienza delle
     * pregresse (`CalcoloQuoteService::calcolaDaFattureStraordinarie`): lo tiene la fattura collegata al piano (`piano_rate_fatture`) con una di quelle sulla
     * voce (`righeDeiPianiStraordinari`). La riga ordinaria di una fattura mista e quella ad personam non la tengono: il piano straordinario non le
     * ripartisce sulla voce. Con il capitolo vale la catena, come per i piani ordinari, anche se il motore straordinario non legge i sottoconti; e una
     * riga di sopravvenienza a zero tiene la voce anche se il motore la salta: il programma non crea né l'una né l'altra.
     *
     * `movimenti` dice che cosa c'è sulle quote del piano che l'annullamento di un'emissione rifiuta (`EmissioneRateController::destroy`): «incasso»
     * per un incasso registrato, «credito» per un credito usato o rimborsato, come in `PianoRate::ragioniDelFermo()`, «passaggio» per un passaggio di
     * titolarità registrato prima della 1.11.0-beta.42 dopo l'emissione, che ha preso il piano nel conguaglio, e «passaggio_senza_registro» al posto di
     * «passaggio» se uno di quei passaggi è di prima della 1.11.0-beta.37, che dal programma non si annulla (`AnnullaPassaggioAction::motivoBlocco`). Le
     * frasi lo leggono per dire che cosa annullare prima delle emissioni (`BloccoDellaVoce`).
     *
     * @param  list<int>  $contiIds
     * @return array<int, list<array{id: int, nome: string, capitolo: ?string, movimenti: list<string>}>> voce => piani in ordine di id, una riga per voce
     *         o capitolo che il piano tiene; `capitolo` è il nome del capitolo sopra la voce se la riga è sua, nullo se è della voce. Una voce che nessun
     *         piano blocca non c'è.
     */
    public static function pianiCheBloccanoLaVoce(array $contiIds): array
    {
        $contiIds = array_values(array_unique(array_map('intval', $contiIds)));
        if ($contiIds === []) {
            return [];
        }

        $catene = self::catene($contiIds);
        $tutti = array_values(array_unique(array_merge(...array_values($catene))));
        $nomi = DB::table('conti')->whereIn('id', $tutti)->pluck('nome', 'id')->all();
        $righe = DB::table('piano_rate_capitoli')
            ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
            ->whereIn('piano_rate_capitoli.conto_id', $tutti)
            ->whereIn('piani_rate.stato', self::STATI_CHE_BLOCCANO_L_IMPORTO)
            ->orderBy('piani_rate.id')
            ->orderBy('piano_rate_capitoli.id')
            ->get(['piano_rate_capitoli.conto_id', 'piani_rate.id', 'piani_rate.nome'])
            ->concat(self::righeDeiPianiStraordinari($tutti))
            ->sortBy('id')
            ->values();

        // I movimenti sulle quote dei piani trovati: un incasso su una quota da pagare, un credito usato o rimborsato su una quota a credito.
        $movimenti = [];
        if ($righe->isNotEmpty()) {
            $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
                ->whereIn('rate.piano_rate_id', $righe->pluck('id')->unique()->values()->all())
                ->where('rate_quote.importo_pagato', '!=', 0)
                ->selectRaw('rate.piano_rate_id as piano, rate_quote.importo >= 0 as da_pagare')
                ->distinct()
                ->get();
            foreach ($quote as $q) {
                $movimenti[(int) $q->piano][(bool) $q->da_pagare ? 'incasso' : 'credito'] = true;
            }

            // Un passaggio di titolarità registrato prima della 1.11.0-beta.42 (senza `piani_presi`) dopo l'emissione di una rata, che ha preso il
            // piano nel conguaglio: l'annullamento di quell'emissione lo rifiuta finché il passaggio c'è, con lo stesso filtro di
            // `EmissioneRateController::destroy`. L'ora dell'emissione è quella della prima scrittura delle sue quote; per una rata segnata «emessa»
            // senza scritture (dati fino alla 1.11.0-beta.41), la data dell'emissione o, se manca, quella della rata.
            $pianiIds = $righe->pluck('id')->unique()->values()->all();
            $emessiDal = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
                ->join('scritture_contabili', 'scritture_contabili.id', '=', 'rate_quote.scrittura_contabile_id')
                ->whereIn('rate.piano_rate_id', $pianiIds)
                ->groupBy('rate.piano_rate_id')
                ->selectRaw('rate.piano_rate_id as piano, min(scritture_contabili.created_at) as dal')
                ->pluck('dal', 'piano')
                ->map(fn ($dal) => \Illuminate\Support\Carbon::parse($dal))
                ->all();
            foreach (Rata::query()->whereIn('piano_rate_id', $pianiIds)->where('stato', 'emessa')->whereNotExists(Rata::aGiornale('rate.id'))->get(['id', 'piano_rate_id', 'data_emissione', 'created_at']) as $rata) {
                $il = $rata->data_emissione ?? $rata->created_at;
                if ($il !== null && (! isset($emessiDal[(int) $rata->piano_rate_id]) || $il->lessThan($emessiDal[(int) $rata->piano_rate_id]))) {
                    $emessiDal[(int) $rata->piano_rate_id] = $il;
                }
            }
            foreach (PianoRate::query()->whereIn('id', array_keys($emessiDal))->get() as $piano) {
                $dal = $emessiDal[(int) $piano->id];
                $dopo = collect($piano->passaggiCheLoHannoConguagliato())
                    ->filter(fn (Subentro $s) => ! PianoRate::haIPianiPresi($s) && $s->created_at !== null && $s->created_at->greaterThanOrEqualTo($dal));
                if ($dopo->isNotEmpty()) {
                    $movimenti[(int) $piano->id][self::senzaRegistro($dopo) ? 'passaggio_senza_registro' : 'passaggio'] = true;
                }
            }
        }
        $diCheTipo = fn (int $piano) => array_values(array_filter(['incasso', 'credito', 'passaggio', 'passaggio_senza_registro'], fn (string $m) => isset($movimenti[$piano][$m])));

        $piani = [];
        foreach ($contiIds as $id) {
            foreach ($righe as $riga) {
                $conto = (int) $riga->conto_id;
                if (in_array($conto, $catene[$id], true)) {
                    $piani[$id][] = ['id' => (int) $riga->id, 'nome' => (string) $riga->nome, 'capitolo' => $conto === $id ? null : (string) ($nomi[$conto] ?? ''),
                        'movimenti' => $diCheTipo((int) $riga->id)];
                }
            }
        }

        return $piani;
    }

    /**
     * Le voci su cui un piano straordinario in uno stato che blocca ha le fatture che finanzia: una riga per voce e per piano, con la riga di sopravvenienza o con
     * la copertura di sopravvenienza della fattura pregressa, che sono le sole su cui il motore straordinario legge la voce (`CalcoloQuoteService::calcolaDaFattureStraordinarie`).
     * Una riga ordinaria della stessa fattura, a preventivo, il piano straordinario non la addebita; una riga ad personam va all'unità senza le tabelle della voce.
     * La leggono anche le voci che un passaggio d'usufrutto sposta (`VociDaSpostare`): il blocco è uno solo.
     *
     * @param  list<int>  $contiIds
     * @return \Illuminate\Support\Collection<int, object{conto_id: int, id: int, nome: string}>
     */
    public static function righeDeiPianiStraordinari(array $contiIds): \Illuminate\Support\Collection
    {
        $suiConti = DB::table('righe_fattura')->whereIn('conto_id', $contiIds)->where('is_sopravvenienza', true)->whereNull('immobile_id')->select('conto_id', 'fattura_passiva_id')
            ->union(DB::table('fattura_coperture')->where('tipo_copertura', 'sopravvenienza')->whereIn('conto_id', $contiIds)->select('conto_id', 'fattura_passiva_id'));

        return DB::query()->fromSub($suiConti, 'sui_conti')
            ->join('piano_rate_fatture', 'piano_rate_fatture.fattura_passiva_id', '=', 'sui_conti.fattura_passiva_id')
            ->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_fatture.piano_rate_id')
            ->where('piani_rate.tipo', 'straordinario')
            ->whereIn('piani_rate.stato', self::STATI_CHE_BLOCCANO_L_IMPORTO)
            ->distinct()
            ->get(['sui_conti.conto_id', 'piani_rate.id', 'piani_rate.nome']);
    }

    /**
     * La catena di ogni voce, dalla radice alla voce: la voce e i capitoli sopra di lei, a qualunque livello.
     *
     * @param  list<int>  $contiIds
     * @return array<int, list<int>>
     */
    public static function catene(array $contiIds): array
    {
        $padri = [];
        $daLeggere = $contiIds;
        while ($daLeggere !== []) {
            $letti = DB::table('conti')->whereIn('id', $daLeggere)->pluck('parent_id', 'id');
            $daLeggere = [];
            foreach ($letti as $id => $padre) {
                $padri[(int) $id] = $padre === null ? null : (int) $padre;
                if ($padre !== null && ! array_key_exists((int) $padre, $padri)) {
                    $daLeggere[] = (int) $padre;
                }
            }
            $daLeggere = array_values(array_unique($daLeggere));
        }

        $catene = [];
        foreach ($contiIds as $id) {
            $catena = [];
            for ($nodo = $id; $nodo !== null && ! in_array($nodo, $catena, true); $nodo = $padri[$nodo] ?? null) {
                $catena[] = $nodo;
            }
            $catene[$id] = array_reverse($catena);
        }

        return $catene;
    }

    /**
     * Uno dei passaggi dati, o della loro famiglia (il passaggio e quelli delle pertinenze), è di prima della 1.11.0-beta.37: senza registro, e dal
     * programma non si annulla (`AnnullaPassaggioAction::motivoBlocco`).
     *
     * @param  \Illuminate\Support\Collection<int, Subentro>  $passaggi
     */
    private static function senzaRegistro(\Illuminate\Support\Collection $passaggi): bool
    {
        $padri = $passaggi->map(fn (Subentro $s) => (int) ($s->subentro_padre_id ?? $s->id))->unique()->values()->all();

        return Subentro::conAnnullati()->where(fn ($q) => $q->whereIn('id', $padri)->orWhereIn('subentro_padre_id', $padri))->get(['id', 'registro'])
            ->contains(fn (Subentro $s) => ! is_array($s->registro));
    }

    /**
     * Il segno con cui il motore distribuisce questa voce: +1 se la addebita, −1 se la accredita.
     *
     * Segue l'ordine del motore. Una voce con tabelle millesimali proprie, o senza sottoconti, ha il segno del proprio
     * tipo. Un capitolo senza tabelle ha il segno dei suoi sottoconti, ciascuno con la stessa regola: contano quelli nati
     * fino alla data del piano (`$snapshotAl`) e, se nessuno è nato fino ad allora, tutti quelli di adesso. Se i sottoconti
     * hanno segni diversi vale il tipo del capitolo: per dividere un importo fra spese ed entrate, come fa il motore, serve
     * {@see ripartoPerSegno()}.
     *
     * Il tipo deve essere stato caricato: una lettura parziale della voce non si completa a caso, perché un segno uguale
     * per tutte le voci farebbe risultare sbagliato ogni piano.
     *
     * @throws \LogicException se il tipo della voce non è caricato
     */
    public function segno(?\Carbon\CarbonInterface $snapshotAl = null): int
    {
        $tipo = $this->tipoCaricato();

        $this->loadMissing('tabelleMillesimali');

        if ($this->tabelleMillesimali->isNotEmpty()) {
            return self::segnoDelTipo($tipo);
        }

        $figli = $this->sottocontiDelPiano($snapshotAl);

        if ($figli->isEmpty()) {
            return self::segnoDelTipo($tipo);
        }

        $segni = $figli->map(fn (self $s) => $s->segno($snapshotAl))->unique();

        return $segni->count() === 1 ? (int) $segni->first() : self::segnoDelTipo($tipo);
    }

    /**
     * Se questa voce è un capitolo che il motore divide fra spese ed entrate: senza tabelle proprie, con sottoconti (gli stessi che sceglie
     * {@see segno()}) che non hanno tutti lo stesso segno, o dei quali uno è a sua volta misto.
     *
     * Per un capitolo così {@see segno()} dà il tipo del capitolo e non dice da che parte stanno i suoi sottoconti: chi sposta un importo
     * da o verso il capitolo ne cambia il totale in un modo che il segno da solo non mostra.
     *
     * @throws \LogicException se il tipo di una voce non è caricato
     */
    public function eMisto(?\Carbon\CarbonInterface $snapshotAl = null): bool
    {
        $this->tipoCaricato();

        $this->loadMissing('tabelleMillesimali');

        if ($this->tabelleMillesimali->isNotEmpty()) {
            return false;
        }

        $figli = $this->sottocontiDelPiano($snapshotAl);

        if ($figli->isEmpty()) {
            return false;
        }

        return $figli->contains(fn (self $s) => $s->eMisto($snapshotAl))
            || $figli->map(fn (self $s) => $s->segno($snapshotAl))->unique()->count() > 1;
    }

    /**
     * Come il motore divide `$cents` di questa voce fra spese ed entrate, in valore assoluto e in centesimi.
     *
     * Una voce con tabelle millesimali proprie, o senza sottoconti, porta tutto l'importo nel proprio tipo. Un capitolo senza
     * tabelle passa l'importo ai sottoconti (gli stessi che sceglie {@see segno()}) in proporzione al loro importo, e l'ultimo
     * prende il resto; se la somma dei loro importi non è maggiore di zero, in parti uguali. È la divisione di
     * `CalcoloQuoteService::processaConti`, riga per riga, con lo stesso arrotondamento; ogni sottoconto ripete la regola. Un
     * capitolo con sottoconti di tipo diverso dà quindi una parte alle spese e una alle entrate, come il motore le distribuisce.
     *
     * @return array{spese: int, entrate: int}
     *
     * @throws \LogicException se il tipo di una voce non è caricato
     */
    public function ripartoPerSegno(int $cents, ?\Carbon\CarbonInterface $snapshotAl = null): array
    {
        $tipo = $this->tipoCaricato();

        $this->loadMissing('tabelleMillesimali');

        $figli = $this->tabelleMillesimali->isNotEmpty() ? collect() : $this->sottocontiDelPiano($snapshotAl);

        if ($figli->isEmpty()) {
            return self::partiDelTipo($tipo, $cents);
        }

        $totaleFigli = (int) $figli->sum('importo');
        $numero = $figli->count();
        $assegnato = 0;
        $spese = 0;
        $entrate = 0;

        foreach ($figli->values() as $indice => $figlio) {
            if ($indice === $numero - 1) {
                $quota = $cents - $assegnato;
            } elseif ($totaleFigli > 0) {
                $quota = (int) round($cents * ($figlio->importo / $totaleFigli));
            } else {
                $quota = (int) round($cents / $numero);
            }
            $assegnato += $quota;

            $parti = $figlio->ripartoPerSegno($quota, $snapshotAl);
            $spese += $parti['spese'];
            $entrate += $parti['entrate'];
        }

        return ['spese' => $spese, 'entrate' => $entrate];
    }

    /** L'importo con il segno con cui il motore lo distribuisce: le spese meno le entrate in cui lo divide ({@see ripartoPerSegno()}). */
    public function importoConSegno(int $cents, ?\Carbon\CarbonInterface $snapshotAl = null): int
    {
        $parti = $this->ripartoPerSegno($cents, $snapshotAl);

        return $parti['spese'] - $parti['entrate'];
    }

    /** Il tipo della voce, se è stato caricato: una `select` parziale non si completa a caso. */
    private function tipoCaricato(): string
    {
        $tipo = $this->getAttributes()['tipo'] ?? null;

        if ($tipo === null || $tipo === '') {
            throw new \LogicException("Conto::segno(): il tipo della voce {$this->getKey()} non è caricato, e il segno non si indovina.");
        }

        return (string) $tipo;
    }

    /**
     * I sottoconti che il motore guarda per questa voce: quelli nati fino alla data del piano e, se nessuno è nato fino ad allora,
     * tutti quelli di adesso. Senza data, tutti.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public function sottocontiDelPiano(?\Carbon\CarbonInterface $snapshotAl)
    {
        $this->loadMissing('sottoconti');

        $figli = $snapshotAl === null
            ? $this->sottoconti
            : $this->sottoconti->filter(fn (self $s) => $s->created_at === null || $s->created_at->lte($snapshotAl));

        return $figli->isEmpty() ? $this->sottoconti : $figli;
    }

    public function getHasRateEmesseAttribute(): bool
    {
        // Check 1: piano rate direttamente collegato a questo conto
        $vincoloDiretto = $this->pianiRate()
            ->whereIn('stato', ['approvato', 'emesso', 'chiuso'])
            ->exists();

        if ($vincoloDiretto) return true;

        // Check 2: padre ha un piano approvato E questo conto
        // è esplicitamente incluso in quel piano via piano_rate_capitoli
        if ($this->parent_id && $this->parent) {
            return $this->parent->pianiRate()
                ->whereIn('stato', ['approvato', 'emesso', 'chiuso'])
                ->where('piani_rate.created_at', '>=', $this->created_at)
                ->exists();
        }

        return false;
    }

    protected static function newFactory()
    {
        return ContoFactory::new();
    }

    /**
     * Le rotte annidate sotto questo modello, e la relazione che porta a ciascun figlio.
     *
     * Vedi il blocco in testa a `App\Traits\RisolveIFigliDelleRotte` per il perché serve: Laravel
     * deriverebbe il nome con una pluralizzazione inglese, e su nomi italiani sbaglia sempre.
     *
     * @return array<string, string>
     */
    protected function relazioniDeiFigliNelleRotte(): array
    {
        return [
            'tabella' => 'tabelle',
        ];
    }

}