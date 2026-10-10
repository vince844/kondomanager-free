<?php

namespace App\Models\Gestionale;

use App\Traits\RisolveIFigliDelleRotte;
use App\Enums\StatoPianoRate;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Database\Factories\Gestionale\PianoRateFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Modello PianoRate
 * * Rappresenta un piano di ripartizione e incasso delle spese (Rate) 
 * per una specifica Gestione (ordinaria o straordinaria) del Condominio.
 * Gestisce il flusso di approvazione legale e l'audit trail delle delibere.
 */
class PianoRate extends Model
{
    use RisolveIFigliDelleRotte;

    use HasFactory;

    protected $table = 'piani_rate';

    /**
     * I passaggi che hanno preso il piano, calcolati una volta sola dentro `conIPassaggiCalcolati()`; null fuori. Non è una cache
     * dell'istanza: chi emette rilegge lo stato dello stesso piano prima e dopo le scritture, e deve vederlo cambiare.
     *
     * @var list<Subentro>|null
     */
    private ?array $passaggiDelConguaglio = null;

    protected $fillable = [
        'gestione_id',
        'esercizio_id',
        'condominio_id',
        'nome',
        'descrizione',
        'metodo_distribuzione',
        'applica_saldi',
        'saldi_config',
        'numero_rate',
        'giorno_scadenza',
        'data_prima_scadenza',
        'attivo',
        'note',
        'nota_scoperti',
        'stato',
        // --- CAMPI DELIBERA E AUDIT ---
        'data_delibera_assemblea',
        'numero_verbale',
        'nota_approvazione',
        'approvato_da_user_id',
        'approvato_il',
        // --- FEATURE 2: PIANI STRAORDINARI ---
        'tipo',
        'tipo_autorizzazione',
        'motivazione_autorizzazione',
    ];

    protected $casts = [
        'stato'                   => StatoPianoRate::class,
        // NULL = «parte dall'inizio della gestione», che è il comportamento di sempre.
        'data_prima_scadenza'     => 'date',
        'data_delibera_assemblea' => 'date',      // Cast automatico a Carbon per formattazione agevole
        'approvato_il'            => 'datetime',  // Cast automatico a Carbon con orario
        'attivo'                  => 'boolean',
        // NULL = piani antecedenti alla beta.32, dove la scelta non veniva persistita.
        'applica_saldi'           => 'boolean',
        // Riparto manuale dei saldi solidali (Art. 63) deciso alla creazione.
        'saldi_config'            => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | SOGLIA DI IMMUTABILITÀ
    |--------------------------------------------------------------------------
    */

    /**
     * Il piano ha movimenti di denaro su almeno una quota: incassi, ma anche crediti già usati in
     * compensazione o rimborsati.
     *
     * Fino alla 1.11.0-beta.31 il filtro era `importo_pagato > 0`: una quota a credito consumata da uno
     * `storno_credito` o da un rimborso ha `importo_pagato` **negativo** e passava — il piano risultava
     * ricalcolabile, «Ricalcola» cancellava le quote (e la pivot in cascata) e il credito rinasceva intero
     * con il denaro già uscito o già compensato (B2, S6). La pivot `quota_scrittura` è la sola verità:
     * qualunque importo diverso da zero è un fatto contabile che il piano non può più riscrivere.
     */
    public function haIncassiRegistrati(): bool
    {
        return $this->rate()
            ->whereHas('rateQuote', fn ($q) => $q->where('importo_pagato', '!=', 0))
            ->exists();
    }

    /**
     * Almeno una quota è stata emessa in contabilità, cioè ha una scrittura collegata.
     */
    public function haRateEmesse(): bool
    {
        return $this->rate()
            ->whereHas('rateQuote', fn ($q) => $q->whereNotNull('scrittura_contabile_id'))
            ->exists();
    }

    /**
     * La soglia oltre la quale il piano — e i dati che lo alimentano — non si
     * correggono più, si stornano.
     *
     * Finché è false il sistema è già disposto a distruggere e riscrivere tutte
     * le quote (è quello che fa «Ricalcola»): non ha senso vietare la correzione
     * del saldo che le alimenta. Quando diventa true il numero è uscito dallo
     * studio — è stato emesso a giornale o incassato — e va rettificato, non riscritto.
     *
     * Tre ragioni dalla 1.11.0-beta.42: una quota a giornale, un movimento su una quota (decisione 34.1) e un conguaglio di
     * un passaggio non annullato (decisione 38). Il conguaglio di un passaggio prende questi piani e solo questi, il ricalcolo
     * li rifiuta: ogni quota la sistema uno solo dei due (decisione 21).
     */
    public function eImmutabile(): bool
    {
        return $this->haRateEmesse() || $this->haIncassiRegistrati() || $this->conguagliato();
    }

    /**
     * Perché il piano non si riscrive più, una ragione per volta (punto 8 della ripresa del giro di verifica della .42): le frasi
     * dicono la ragione vera, non le tre insieme, e così sono anche una verifica dello stato del piano. `incasso` è un movimento su
     * una quota da pagare (un incasso, anche con la compensazione); `credito` un movimento su una quota a credito (un credito usato
     * o rimborsato).
     *
     * @return list<'scrittura'|'incasso'|'credito'|'conguaglio'>
     */
    public function ragioniDelFermo(): array
    {
        $movimenti = \Illuminate\Support\Facades\DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate.piano_rate_id', $this->id)->where('rate_quote.importo_pagato', '!=', 0);

        return array_values(array_filter([
            $this->haRateEmesse() ? 'scrittura' : null,
            (clone $movimenti)->where('rate_quote.importo', '>=', 0)->exists() ? 'incasso' : null,
            (clone $movimenti)->where('rate_quote.importo', '<', 0)->exists() ? 'credito' : null,
            $this->conguagliato() ? 'conguaglio' : null,
        ]));
    }

    /**
     * «ha già quote a giornale», «ha un incasso su una sua quota», …: le ragioni di `ragioniDelFermo()` in parole, una per voce.
     * `$passaggiAParte` (verifica a video della .42): la ragione del conguaglio dice solo «di questo passaggio» / «di questi
     * passaggi», e i passaggi vanno in elenco subito dopo (`vociDeiPassaggi($this->passaggiDaAnnullare())`); in fila nella stessa
     * frase, con più passaggi, era un blocco. Il conguaglio, se c'è, è sempre l'ultima ragione.
     *
     * @return list<string>
     */
    public function ragioniInParole(bool $passaggiAParte = false): array
    {
        // Rilievo R11 della Fase 1-bis della .48: una successione con il conguaglio non scritto non ha preso il piano in nessun conguaglio,
        // lo tiene fermo (le bozze passate all'erede di riferimento). Con un passaggio così fra quelli da annullare, la frase vale per tutti.
        $nonScritti = $this->passaggiNonScritti();

        return array_map(fn (string $r) => match ($r) {
            'scrittura' => 'ha già quote a giornale',
            'incasso' => 'ha un incasso su una sua quota',
            'credito' => 'ha un credito usato o rimborsato su una sua quota',
            'conguaglio' => match (true) {
                $nonScritti && $passaggiAParte => count($this->passaggiDaAnnullare()) === 1 ? 'è tenuto fermo da questo passaggio' : 'è tenuto fermo da questi passaggi',
                $nonScritti => 'è tenuto fermo ' . (count($c = $this->passaggiDaAnnullare()) === 1 ? ($c[0]->successione() ? 'dalla ' : 'dal passaggio ') : 'dai passaggi ') . self::elencoPassaggi($c),
                default => $passaggiAParte
                ? (count($this->passaggiDaAnnullare()) === 1 ? 'è stato preso nel conguaglio di questo passaggio' : 'è stato preso nel conguaglio di questi passaggi')
                // Rilievo T7 del quarto giro: «nel conguaglio dell'estinzione dell'usufrutto di …», non «del passaggio estinzione»; lo
                // stesso per la successione (1.11.0-beta.44).
                : 'è stato preso nel conguaglio ' . (count($c = $this->passaggiDaAnnullare()) === 1
                    ? ($c[0]->tipo_passaggio === 'usufrutto' && $c[0]->tipologia === 'proprietario' ? 'dell\'' : ($c[0]->successione() ? 'della ' : 'del passaggio '))
                    : 'dei passaggi ') . self::elencoPassaggi($c),
            },
        }, $this->ragioniDelFermo());
    }

    /** Decisione 72: fra i passaggi che tengono fermo il piano c'è una successione con il conguaglio non scritto (rilievo R11). */
    private function passaggiNonScritti(): bool
    {
        return collect($this->passaggiDaAnnullare())->contains(fn (Subentro $p) => $p->conguaglioNonScritto());
    }

    /** «ha già quote a giornale» / «ha un incasso su una sua quota» / … : le ragioni di `ragioniDelFermo()` in una frase. Null se non è fermo. */
    public function fraseDelFermo(bool $passaggiAParte = false): ?string
    {
        $parti = $this->ragioniInParole($passaggiAParte);
        if ($parti === []) {
            return null;
        }
        $ultima = array_pop($parti);

        // «ed è stato preso…», non «e è» (testo T1 del giro di verifica).
        return $parti === [] ? $ultima : implode(', ', $parti) . (str_starts_with($ultima, 'è') ? ' ed ' : ' e ') . $ultima;
    }

    /**
     * Rilievo V9 del giro di verifica: il rimedio di ogni ragione del fermo, in un posto solo per l'emissione, il Wallet e l'incasso.
     * `$inElenco`: per i passi numerati, senza il «poi» che serve solo quando i rimedi stanno in fila nella stessa frase.
     * `$finoAlRicalcolo` (con `$inElenco`): i passi arrivano fino al ricalcolo, e il movimento annullato si registra di nuovo in
     * un passo suo, l'ultimo — in un elenco numerato chi segue i passi si ferma all'ultimo, e il «lo registri di nuovo dopo» fra
     * parentesi nel primo restava indietro (revisione della verifica a video della .42).
     *
     * @return list<string>
     */
    public function rimediDelFermo(bool $inElenco = false, bool $finoAlRicalcolo = false): array
    {
        $ragioni = $this->ragioniDelFermo();
        $poi = $inElenco ? '' : 'poi ';
        $movimento = array_intersect($ragioni, ['incasso', 'credito']) !== [];

        // Rilievi T6 e W9 del giro sulle correzioni: nell'ordine che funziona — prima i movimenti (un'emissione con incassi non
        // si annulla), poi le emissioni, poi i passaggi, e la nuova registrazione dei passaggi quando non resta nessun'altra
        // ragione; con più passaggi si annullano tutti prima di registrarne di nuovo uno. Rilievo T-B del terzo giro: con un
        // passaggio registrato prima della .42 la guardia dell'annullamento dell'emissione vuole prima il passaggio (decisione
        // 49), e «dall'ultimo» è l'ordine dello storico (per data, poi per registrazione).
        $passaggi = in_array('conguaglio', $ragioni, true) ? $this->passaggiDaAnnullare() : [];
        $uno = count($passaggi) === 1;
        $diPrima = $passaggi !== [] && in_array('scrittura', $ragioni, true) && collect($passaggi)->contains(fn (Subentro $s) => ! self::haIPianiPresi($s));
        $annullaPassaggi = $uno
            ? 'annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo)'
            : 'annulla tutti quei passaggi, ognuno dallo storico della sua unità («Passaggi registrati», dall\'ultimo)';
        $registraDiNuovo = $uno ? 'registralo di nuovo' : 'registrali di nuovo, quando sono annullati tutti';
        $fuori = $passaggi !== [] && ($f = $this->fraseRegolatoFuori()) !== null ? ' — ' . lcfirst(rtrim($f, '.')) : '';
        $emissioni = in_array('scrittura', $ragioni, true) ? 'annulla le emissioni dalla pagina del piano, se non hanno incassi' : null;

        // Rilievo T1 del quarto giro: con un passaggio di prima l'ordine che passa è uno solo — le emissioni venute dopo il passaggio
        // (l'annullamento del passaggio le vuole annullate), poi il passaggio, poi le altre emissioni (la guardia della 49 le
        // rifiuta finché il passaggio c'è), poi la nuova registrazione.
        return array_values(array_filter([
            $movimento ? match (true) {
                $finoAlRicalcolo => 'annulla quel movimento',
                $inElenco => 'annulla quel movimento (lo registri di nuovo dopo il ricalcolo)',
                default => 'annulla quel movimento (lo registri di nuovo dopo)',
            } : null,
            ...($diPrima
                ? [$uno ? 'annulla le emissioni venute dopo quel passaggio, se ce ne sono' : 'annulla le emissioni venute dopo quei passaggi, se ce ne sono',
                    $poi . $annullaPassaggi, $poi . 'annulla le altre emissioni dalla pagina del piano, se non hanno incassi', $registraDiNuovo . $fuori]
                : [$emissioni, $passaggi === [] ? null : $annullaPassaggi . ' e ' . $registraDiNuovo . $fuori]),
            $finoAlRicalcolo ? 'ricalcola il piano' : null,
            $finoAlRicalcolo && $movimento ? 'registra di nuovo quel movimento' : null,
        ]));
    }

    /**
     * Gli id dei piani con una quota a giornale o un movimento — le prime due ragioni di `eImmutabile()` —, come sottoquery
     * per le query che non passano dal Model. La terza ragione (decisione 38) non si scrive in SQL: chi deve sapere quali
     * piani non si riscrivono più usa `immutabiliFra()`. Lo stato «emessa» della rata non conta: fino alla beta.41
     * l'emissione lo scriveva anche su una rata senza scritture.
     */
    public static function idImmutabili(): \Illuminate\Database\Query\Builder
    {
        return \Illuminate\Support\Facades\DB::table('rate as r_imm')
            ->join('rate_quote as q_imm', 'q_imm.rata_id', '=', 'r_imm.id')
            ->where(fn ($q) => $q->whereNotNull('q_imm.scrittura_contabile_id')->orWhere('q_imm.importo_pagato', '!=', 0))
            ->select('r_imm.piano_rate_id');
    }

    /**
     * Fra i piani dati, quelli per cui `eImmutabile()` è vera, con tutte e tre le ragioni (decisioni 34.1 e 38).
     *
     * @param iterable<int|string> $pianoIds
     * @return list<int>
     */
    public static function immutabiliFra(iterable $pianoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($pianoIds) ? $pianoIds : iterator_to_array($pianoIds, false))));
        if ($ids === []) {
            return [];
        }
        $giaFermi = self::idImmutabili()->whereIn('r_imm.piano_rate_id', $ids)->distinct()->pluck('piano_rate_id')->map(fn ($id) => (int) $id)->all();
        $resto = array_values(array_diff($ids, $giaFermi));
        $conguagliati = $resto === [] ? [] : self::whereIn('id', $resto)->get()->filter(fn (self $p) => $p->conguagliato())->map(fn (self $p) => (int) $p->id)->values()->all();

        return array_values(array_merge($giaFermi, $conguagliati));
    }

    /**
     * Decisioni 38, 42 e 43 (1.11.0-beta.42): un passaggio non annullato ha preso questo piano nel conguaglio. Il piano resta fermo
     * finché il passaggio non si annulla, anche con la rinuncia o con il conguaglio annullato («le parti hanno regolato fra loro»,
     * 43): ricalcolarlo rifarebbe per giorni ciò che quel conguaglio ha già regolato (rilievo R2: un incasso su una bozza, la
     * vendita, poi lo storno dell'incasso).
     */
    public function conguagliato(): bool
    {
        return $this->passaggiCheLoHannoConguagliato() !== [];
    }

    /** @return list<Subentro> i passaggi della decisione 38, nell'ordine in cui sono stati registrati */
    public function passaggiCheLoHannoConguagliato(): array
    {
        return $this->passaggiDelConguaglio
            ?? $this->passaggiSulleSueUnita()->filter(fn (Subentro $s) => $this->presoNelConguaglioDa($s))->values()->all();
    }

    /**
     * Esegue `$fa` con i passaggi del conguaglio calcolati una volta sola (revisione della verifica a video della .42: la prop
     * della pagina li rivalutava sette volte, ognuna con le query di ogni passaggio di prima). Fuori da `$fa` il piano torna a
     * calcolarli ogni volta.
     *
     * @template T
     * @param callable(self): T $fa
     * @return T
     */
    public function conIPassaggiCalcolati(callable $fa): mixed
    {
        if ($this->passaggiDelConguaglio !== null) {
            return $fa($this);
        }
        $this->passaggiDelConguaglio = $this->passaggiCheLoHannoConguagliato();
        try {
            return $fa($this);
        } finally {
            $this->passaggiDelConguaglio = null;
        }
    }

    /**
     * Perché il piano non si riscrive più e come si riapre, per l'avviso del pulsante «Ricalcola» della pagina del piano: le
     * ragioni (il conguaglio, se c'è, è l'ultima), i passaggi del conguaglio da mettere sotto l'ultima ragione e i rimedi in passi.
     * Null se il piano si riscrive.
     *
     * @return array{ragioni: list<string>, passaggi: list<string>, rimedi: list<string>}|null
     */
    public function fermoPerLaPagina(): ?array
    {
        return $this->conIPassaggiCalcolati(function (self $piano) {
            $ragioni = $piano->ragioniInParole(passaggiAParte: true);

            return $ragioni === [] ? null : [
                'ragioni' => $ragioni,
                'passaggi' => in_array('conguaglio', $piano->ragioniDelFermo(), true) ? self::vociDeiPassaggi($piano->passaggiDaAnnullare()) : [],
                'rimedi' => $piano->rimediDelFermo(inElenco: true),
            ];
        });
    }

    /**
     * Decisioni 38 e 43: il rifiuto di riscrivere un piano preso dal conguaglio di un passaggio — ricalcolo, eliminazione, rimozione
     * di una voce —, con i passaggi e la sola strada che lo riapre: annullare il passaggio, registrarlo di nuovo e poi `$passo`.
     * `$azione` è il soggetto della frase («Ricalcolarlo»). Null se nessun passaggio lo ha preso.
     */
    public function fraseConguagliato(string $azione, string $passo = 'ricalcola il piano'): ?string
    {
        $passaggi = $this->passaggiDaAnnullare();
        if ($passaggi === []) {
            return null;
        }
        // Rilievo W9 del giro sulle correzioni: con più passaggi si annullano tutti prima di registrarne di nuovo uno, perché finché
        // uno resta il piano è preso e la nuova registrazione lo riprende. T2: niente «chi è entrato pagherebbe due volte», che non
        // è vero quando il conguaglio a chi entra non ha dato niente.
        $fuori = ($f = $this->fraseRegolatoFuori()) !== null ? ' ' . $f : '';
        // Decisione 72 (1.11.0-beta.48): una successione con il conguaglio non scritto non ha regolato niente: ha lasciato la
        // posizione com'era, e il ricalcolo la rifarebbe per giorni.
        $nonScritti = count(array_filter($passaggi, fn (Subentro $p) => $p->conguaglioNonScritto()));
        // Rilievo R11 della Fase 1-bis della .48: il passaggio «non scritto» non ha preso il piano in un conguaglio, lo tiene fermo.
        if (count($passaggi) === 1) {
            return sprintf(($nonScritti === 1 ? 'Un passaggio di titolarità tiene fermo questo piano' : 'Un passaggio di titolarità ha preso questo piano nel conguaglio') . ': %s. %s rifarebbe per giorni %s. Per farlo, annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo), registralo di nuovo e poi %s.%s',
                self::elencoPassaggi($passaggi), $azione, $nonScritti === 1 ? 'la posizione che quel passaggio ha lasciato com\'era' : 'ciò che quel conguaglio ha già regolato', $passo, $fuori);
        }

        return sprintf(($nonScritti === 0 ? 'Più passaggi di titolarità hanno preso questo piano nel conguaglio' : 'Più passaggi di titolarità tengono fermo questo piano') . ': %s. %s rifarebbe per giorni %s. Per farlo, annulla tutti quei passaggi, ognuno dallo storico della sua unità («Passaggi registrati», dall\'ultimo); quando sono annullati tutti, registrali di nuovo e poi %s.%s',
            self::elencoPassaggi($passaggi), $azione, match (true) {
                $nonScritti === 0 => 'ciò che quei conguagli hanno già regolato',
                $nonScritti === count($passaggi) => 'le posizioni che quei passaggi hanno lasciato com\'erano',
                default => 'ciò che quei conguagli hanno già regolato e le posizioni lasciate com\'erano',
            }, $passo, $fuori);
    }

    /**
     * Decisione 46 (rilievo W2): un passaggio che ha preso il piano con la rinuncia, o con il conguaglio poi annullato — le parti
     * hanno regolato fra loro. La strada della 43 rifà per giorni quella parte: il programma non la impedisce e non la compensa, lo
     * dice, con la cifra della gestione del piano quando il registro la conserva. Null se non c'è niente da dire.
     */
    public function fraseRegolatoFuori(): ?string
    {
        $parti = [];
        $nonScritti = [];
        foreach ($this->passaggiDaAnnullare() as $p) {
            if (! $p->conguaglioRinunciato() && ! $p->conguaglioAnnullato()) {
                continue;
            }
            if (is_array($p->registro['regolato_fuori'] ?? null)) {
                $cifra = $p->regolatoFuoriInParole((int) $this->gestione_id);
            } else {
                $cifra = 'il conguaglio del passaggio ' . self::elencoPassaggi([$p]);
            }
            if ($cifra === null) {
                continue;
            }
            // Decisione 72 (1.11.0-beta.48): il conguaglio non scritto della successione non è un accordo fra le parti.
            if ($p->conguaglioNonScritto() && ! $p->conguaglioAnnullato()) {
                $nonScritti[] = $cifra;
            } else {
                $parti[] = $cifra;
            }
        }

        $frasi = [];
        if ($parti !== []) {
            $frasi[] = sprintf('Le parti hanno già regolato fra loro %s: ricalcolando, il condominio addebita a chi entra i suoi giorni, e quell\'accordo va rifatto fra le parti.', implode('; ', $parti));
        }
        if ($nonScritti !== []) {
            $frasi[] = sprintf('Il conguaglio della successione non è stato scritto (%s): ricalcolando, il condominio addebita a ciascuno i suoi giorni, e la posizione lasciata com\'era cambia.', implode('; ', $nonScritti));
        }

        return $frasi === [] ? null : implode(' ', $frasi);
    }

    /**
     * Decisione 42: il passaggio ha preso questo piano nel conguaglio. Dalla .42 lo dice il registro (`piani_presi`, scritto alla
     * registrazione, anche con la rinuncia). Per i passaggi di prima la regola di allora (39): le quote c'erano già, e il piano aveva
     * una scrittura entro l'ora del passaggio — oppure una rata «emessa» senza scrittura e la coppia del passaggio sulla sua gestione,
     * il criterio della .41, che prendeva solo quelle rate (`presoSoloInParteDa()`).
     */
    public function presoNelConguaglioDa(Subentro $s): bool
    {
        // Decisione 47: un passaggio senza conguaglio (inizio locazione, fine locazione senza un nuovo inquilino) non prende piani,
        // anche quando è stato registrato prima della .42 e la sua ora cade dopo una scrittura.
        if (! $s->haUnConguaglio()) {
            return false;
        }
        if (self::haIPianiPresi($s)) {
            return in_array((int) $this->id, array_map('intval', $s->registro['piani_presi']), true);
        }

        return $s->created_at !== null && $this->quoteCeranoAl($s) && ($this->scritturaEntro($s->created_at) || $this->presoSoloInParteDa($s));
    }

    /**
     * Rilievo V3 e decisione 42: un passaggio della .41 che ha preso solo le rate «emesse» senza scrittura (la forma di DC5) e ha
     * lasciato le bozze al ricalcolo. Il piano è insieme fermo — ricalcolarlo sommerebbe la coppia al riparto per giorni — e da
     * seguire — emesso così, le bozze resterebbero a chi è uscito. Lo riapre solo l'annullamento del passaggio (43). Senza la coppia
     * (annullata) non c'è niente da sommare, e il passaggio non conta.
     */
    public function presoSoloInParteDa(Subentro $s): bool
    {
        if (self::haIPianiPresi($s) || $s->created_at === null || $this->scritturaEntro($s->created_at) || ! $this->quoteCeranoAl($s)) {
            return false;
        }

        return $this->rate()->where('stato', 'emessa')->whereNotExists(Rata::aGiornale('rate.id'))->exists()
            && $s->saldi()->where('gestione_id', $this->gestione_id)->when($this->esercizio_id !== null, fn ($q) => $q->where('esercizio_id', $this->esercizio_id))->exists();
    }

    /** Decisione 42: dalla 1.11.0-beta.42 il registro del passaggio dice quali piani il suo conguaglio ha preso. */
    public static function haIPianiPresi(Subentro $s): bool
    {
        return is_array($s->registro['piani_presi'] ?? null);
    }

    /**
     * Le quote del piano c'erano già quando il passaggio è stato registrato: `registro.quota_max_id` (dalla beta.37), come la
     * fermata «ricostruzione» del conguaglio; per i passaggi di prima, l'ora. Un piano ricalcolato dopo il passaggio ha quote
     * nuove, e il passaggio non l'ha visto.
     */
    public function quoteCeranoAl(Subentro $s, ?int $primaQuota = null): bool
    {
        $primaQuota ??= (int) \Illuminate\Support\Facades\DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate.piano_rate_id', $this->id)->min('rate_quote.id');
        if ($primaQuota === 0) {
            return false;
        }
        if (isset($s->registro['quota_max_id'])) {
            return $primaQuota <= (int) $s->registro['quota_max_id'];
        }
        $creata = \Illuminate\Support\Facades\DB::table('rate_quote')->where('id', $primaQuota)->value('created_at');

        return $creata !== null && $s->created_at !== null && $s->created_at->greaterThanOrEqualTo(\Carbon\CarbonImmutable::parse($creata));
    }

    /**
     * Decisione 39, la regola della .41 per i passaggi senza `piani_presi`: una scrittura di emissione entro l'ora del passaggio. A
     * parità di secondo vale come prima: due richieste dell'amministratore non cadono nello stesso secondo, i test sì.
     */
    public function scritturaEntro(\DateTimeInterface $ora): bool
    {
        return \Illuminate\Support\Facades\DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $this->id)
            ->join('scritture_contabili as sc_al', 'sc_al.id', '=', 'rate_quote.scrittura_contabile_id')
            ->where('sc_al.created_at', '<=', \Carbon\CarbonImmutable::instance($ora)->toDateTimeString())->exists();
    }

    /** Decisione 42: i piani che alla registrazione di un passaggio su quest'unità non si riscrivevano più (`registro.piani_presi`). */
    public static function pianiPresiSullUnita(int $immobileId): array
    {
        return self::immutabiliFra(\Illuminate\Support\Facades\DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate_quote.immobile_id', $immobileId)->distinct()->pluck('rate.piano_rate_id'));
    }

    /** I passaggi non annullati sulle unità del piano, nell'ordine in cui sono stati registrati. */
    private function passaggiSulleSueUnita(): \Illuminate\Support\Collection
    {
        $unita = \Illuminate\Support\Facades\DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate.piano_rate_id', $this->id)->distinct()->pluck('rate_quote.immobile_id')->filter()->all();

        return $unita === [] ? collect() : Subentro::with(['uscente', 'entrante', 'immobile', 'padre.uscente', 'padre.entrante', 'padre.immobile'])->whereIn('immobile_id', $unita)->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * «Ugo → Elsa, Interno 1, dal 1 maggio 2026; …». Rilievi T4, T5 e W9 del giro sulle correzioni: l'unità, perché lo storico
     * da cui si annulla è quello dell'unità del passaggio; niente parentesi, che finivano annidate nel cancello; il nome dal
     * registro quando l'anagrafica non c'è più, e l'estinzione detta per quello che è (non ha chi entra).
     *
     * @param list<Subentro> $passaggi
     */
    public static function elencoPassaggi(array $passaggi): string
    {
        return implode('; ', self::vociDeiPassaggi($passaggi));
    }

    /**
     * Le voci di `elencoPassaggi()` una per una, per i messaggi che le mettono in elenco (verifica a video della .42: in fila
     * nella stessa frase erano un blocco).
     *
     * @param list<Subentro> $passaggi
     * @return list<string>
     */
    public static function vociDeiPassaggi(array $passaggi): array
    {
        return collect($passaggi)->map(function (Subentro $s) {
            $esce = $s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? null);
            $entra = $s->entrante?->nome ?? ($s->registro['nomi']['entrante'] ?? null);
            // Rilievo T-E del terzo giro: l'estinzione ha un `anagrafica_entrante_id` (il primo dei nudi), e la freccia nominava
            // uno solo di loro; si dice per quello che è, come lo storico.
            $chi = match (true) {
                $s->tipo_passaggio === 'usufrutto' && $s->tipologia === 'proprietario' => 'estinzione dell\'usufrutto di ' . ($esce ?? 'chi non è più titolare'),
                // La successione nomina come chi entra un erede solo (decisione 65): si dice per quello che è.
                $s->successione() => 'successione di ' . ($esce ?? 'chi non è più titolare'),
                $entra !== null => ($esce ?? 'chi non è più titolare') . ' → ' . $entra,
                default => $esce ?? 'chi non è più titolare',
            };

            return sprintf('%s, %s, dal %s', $chi, $s->immobile?->nome ?? 'un\'unità', \Carbon\CarbonImmutable::parse($s->decorrenza)->locale('it')->translatedFormat('j F Y'));
        })->values()->all();
    }

    /**
     * I passaggi da nominare nelle frasi e da annullare: quelli che hanno preso il piano, con ogni pertinenza portata al passaggio
     * della sua unità e senza doppioni (rilievo T4: la stessa vendita compariva due volte, per l'unità e per il box).
     *
     * @return list<Subentro>
     */
    public function passaggiDaAnnullare(): array
    {
        return self::senzaPertinenze($this->passaggiCheLoHannoConguagliato());
    }

    /**
     * Rilievi T4 e T-D: ogni pertinenza portata al passaggio della sua unità, senza doppioni, nell'ordine dato. Una regola sola
     * per tutte le frasi che contano o nominano passaggi.
     *
     * @param iterable<Subentro> $passaggi
     * @return list<Subentro>
     */
    public static function senzaPertinenze(iterable $passaggi): array
    {
        return collect($passaggi)->map(fn (Subentro $s) => $s->subentro_padre_id !== null ? ($s->padre ?? $s) : $s)->unique('id')->values()->all();
    }

    /**
     * «del passaggio di Ugo su Interno 1, dal 1 febbraio 2026» / «dei passaggi …»: i passaggi da seguire nelle frasi di rifiuto
     * dell'emissione, del ricalcolo e dell'incasso (decisioni 35 e 41).
     *
     * @param list<Subentro> $passaggi
     */
    public static function descriviPassaggi(array $passaggi): string
    {
        // Rilievo T-D del terzo giro: una vendita con il box sono due passaggi, padre e figlio; si nomina il padre, una volta sola.
        $passaggi = self::senzaPertinenze($passaggi);
        $elenco = collect($passaggi)->map(fn (Subentro $p) => sprintf('di %s su %s, dal %s', $p->uscente?->nome ?? 'chi non è più titolare',
            $p->immobile?->nome ?? 'un\'unità', \Carbon\CarbonImmutable::parse($p->decorrenza)->locale('it')->translatedFormat('j F Y')))->unique()->values();

        return $elenco->count() === 1 ? 'del passaggio ' . $elenco[0] : 'dei passaggi ' . $elenco->join('; ', '; e ');
    }

    /**
     * Decisione 41: il rifiuto di un incasso, di una compensazione o di un rimborso su una quota di questo piano, se il piano deve
     * ancora seguire un passaggio. Null se non ne ha. `$cosa` = [«un incasso», «l'incasso»].
     *
     * @param array{0: string, 1: string} $cosa
     */
    public function fraseRicalcolaPrima(array $cosa = ['un incasso', 'l\'incasso']): ?string
    {
        $passaggi = $this->passaggiDaSeguire();
        if ($passaggi === []) {
            return null;
        }

        return sprintf('Ricalcola prima il piano «%s»: le sue quote sono state calcolate prima %s, e %s su una sua quota lo fermerebbe prima che ne tenga conto. Dopo il ricalcolo registra %s.',
            $this->nome, self::descriviPassaggi($passaggi), $cosa[0], $cosa[1]);
    }

    /**
     * Decisioni 35, 41 e 42 (1.11.0-beta.42): i passaggi che il piano deve ancora seguire. Registrati quando le quote c'erano già, non
     * lo hanno preso nel conguaglio (`piani_presi`; per quelli di prima la regola di allora) e lo hanno lasciato al ricalcolo
     * (decisione 21). Vendita, usufrutto o successione, di chi ha ancora quote del piano sull'unità, con la decorrenza entro la
     * fine del suo esercizio; la locazione no (decisione 32: emettere senza ricalcolare vuol dire che paga l'inquilino uscito).
     * Vuoto se il piano ha già quote a giornale: i dati di prima della .42, dove le bozze rimaste si emettono come prima e il
     * conguaglio del passaggio dopo si ferma (decisioni 35 e 41).
     *
     * @return list<Subentro>
     */
    public function passaggiDaSeguire(): array
    {
        if ($this->haRateEmesse()) {
            return [];
        }
        $gruppi = \Illuminate\Support\Facades\DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $this->id)
            ->whereNotNull('rate_quote.anagrafica_id')
            ->groupBy('rate_quote.immobile_id', 'rate_quote.anagrafica_id')
            ->get(['rate_quote.immobile_id', 'rate_quote.anagrafica_id', \Illuminate\Support\Facades\DB::raw('MIN(rate_quote.id) as prima')]);
        if ($gruppi->isEmpty()) {
            return [];
        }
        $fine = $this->esercizio?->data_fine ?? $this->gestione?->data_fine;

        return Subentro::with(['uscente', 'immobile'])
            ->whereIn('immobile_id', $gruppi->pluck('immobile_id')->unique()->all())
            ->whereIn('anagrafica_uscente_id', $gruppi->pluck('anagrafica_id')->unique()->all())
            ->whereIn('tipo_passaggio', ['vendita', 'usufrutto', 'successione'])
            ->when($fine !== null, fn ($q) => $q->whereDate('decorrenza', '<=', \Carbon\CarbonImmutable::parse($fine)->toDateString()))
            ->orderBy('decorrenza')->orderBy('id')
            ->get()
            ->filter(function (Subentro $p) use ($gruppi) {
                $g = $gruppi->first(fn ($x) => (int) $x->immobile_id === (int) $p->immobile_id && (int) $x->anagrafica_id === (int) $p->anagrafica_uscente_id);

                // Decisione 42: non lo ha preso (o, per un passaggio della .41, lo ha preso solo in parte: rilievo V3).
                return $g !== null && $this->quoteCeranoAl($p, (int) $g->prima) && $p->created_at !== null
                    && (! $this->presoNelConguaglioDa($p) || $this->presoSoloInParteDa($p));
            })
            ->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | RELAZIONI
    |--------------------------------------------------------------------------
    */

    /**
     * Ottiene la Gestione (ordinaria/straordinaria) a cui appartiene questo piano.
     * Un piano rate non può esistere al di fuori di una gestione attiva.
     *
     * @return BelongsTo
     */
    /** L'esercizio con cui il piano è stato generato (B2, migrazione 9); nullo sui piani vecchi non travasati. */
    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Esercizio::class, 'esercizio_id');
    }

    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class);
    }

    /**
     * Ottiene il Condominio proprietario di questo piano rate.
     *
     * @return BelongsTo
     */
    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    /**
     * Ottiene la configurazione di ricorrenza (es. mensile, bimestrale) 
     * associata alla generazione automatica delle scadenze di questo piano.
     *
     * @return HasOne
     */
    public function ricorrenza(): HasOne
    {
        return $this->hasOne(RicorrenzaRata::class);
    }

    /**
     * Il dettaglio del riparto di questo piano (1.11.0-beta.29): una riga per componente, scritta
     * alla generazione insieme alle quote. Vuoto per i piani generati prima della beta.29 — la
     * stampa allora ricalcola e lo dichiara.
     */
    public function righeRiparto(): HasMany
    {
        return $this->hasMany(RigaRiparto::class);
    }

    /**
     * Ottiene tutte le Rate fisiche (scadenze) generate da questo piano.
     * Ogni rata conterrà a sua volta le singole quote addebitate ai condòmini.
     *
     * @return HasMany
     */
    public function rate(): HasMany
    {
        return $this->hasMany(Rata::class);
    }

    /**
     * Ottiene lo storico dei movimenti di budget associati a questo piano.
     * Cruciale per la funzione "Sposta Spesa" e per tracciare i travasi
     * di fondi tra un capitolo e l'altro (Audit Log contabile).
     *
     * @return HasMany
     */
    public function budgetMovements(): HasMany
    {
        return $this->hasMany(BudgetMovement::class);
    }

    /**
     * Ottiene i capitoli di spesa (Conti) coperti da questo piano rate.
     * * CRUCIALE: Il metodo withPivot() carica i campi 'importo' e 'note' dalla 
     * tabella di collegamento. Questo permette al piano rate di coprire un 
     * capitolo anche solo parzialmente (es. finanzio solo 1.000€ su 5.000€ totali).
     *
     * @return BelongsToMany
     */
    public function capitoli(): BelongsToMany
    {
        // `id` nel pivot dalla 1.11.0-beta.31: la riga di `piano_rate_capitoli` è la chiave dei tratti di
        // competenza del capitolo (`competenze_capitolo.piano_rate_capitolo_id`, decisione 20).
        return $this->belongsToMany(Conto::class, 'piano_rate_capitoli', 'piano_rate_id', 'conto_id')
                    ->withPivot(['id', 'importo', 'note'])
                    ->withTimestamps();
    }

    /**
     * Ottiene l'amministratore (User) che ha verificato il verbale 
     * e approvato legalmente questo piano rate (Audit Trail).
     *
     * @return BelongsTo
     */
    public function approvatoDa(): BelongsTo
    { 
        return $this->belongsTo(User::class, 'approvato_da_user_id'); 
    }

    /**
     * Le fatture collegate a questo piano rate (solo per piani straordinari).
     */
    public function fatture(): BelongsToMany
    {
        return $this->belongsToMany(FatturaPassiva::class, 'piano_rate_fatture');
    }

    /**
     * Le fatture impreviste o ad personam associate a questo piano rate straordinario.
     */
    public function fattureStraordinarie(): BelongsToMany
    {
        return $this->belongsToMany(FatturaPassiva::class, 'piano_rate_fatture')
                    ->withPivot('importo_collegato')
                    ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | FACTORIES
    |--------------------------------------------------------------------------
    */

    /**
     * Collega esplicitamente la Factory corretta per i test automatizzati.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return PianoRateFactory::new();
    }

    /**
     * La data da cui parte il calendario delle rate.
     *
     * È `data_prima_scadenza` se l'amministratore l'ha scelta, altrimenti l'inizio della
     * gestione — che è il comportamento di sempre, e resta il default proprio perché nella
     * grande maggioranza dei casi è quello giusto.
     *
     * `NULL` in colonna non è un dato mancante: **è la scelta di seguire la gestione**. Un
     * piano che non ha una data propria si sposta se l'inizio della gestione si sposta, e chi
     * lo vuole fermo mette una data. È la ragione per cui questa colonna non è stata
     * riempita all'indietro sui piani esistenti.
     *
     * ⚠️ La controparte TypeScript è `partenzaCalendario()` in
     * `resources/js/lib/gestionale/pianiRate/calendario.ts`, che l'interfaccia usa per dire
     * all'amministratore da dove partirà. Le due devono rispondere allo stesso modo: è lo
     * schema che nella beta.35 è costato un centesimo di divergenza sul netto da pagare,
     * perché nessuna delle due copie era sbagliata da sola.
     */
    public function dataPartenzaCalendario(): ?\Carbon\CarbonInterface
    {
        if ($this->data_prima_scadenza) {
            return \Carbon\CarbonImmutable::parse($this->data_prima_scadenza);
        }

        $inizioGestione = $this->gestione?->data_inizio;

        return $inizioGestione ? \Carbon\CarbonImmutable::parse($inizioGestione) : null;
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
            'capitolo' => 'capitoli',
            'rata' => 'rate',
        ];
    }

}