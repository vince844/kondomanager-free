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
            return self::segnoDelTipo($tipo) > 0
                ? ['spese' => abs($cents), 'entrate' => 0]
                : ['spese' => 0, 'entrate' => abs($cents)];
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