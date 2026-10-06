<?php

namespace App\Services\Subentro;

use App\Enums\NaturaGestione;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestionale\Subentro;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Services\Riparto\DettaglioRiparto;
use App\Services\Riparto\PeriodoDellaRiga;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use App\Support\ProRataTemporis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il blocco 2 del pannello «Cosa cambierà» **e** la coppia in `saldi` che `RegistraSubentroAction` scrive:
 * un solo calcolo (S5, «anteprima = scrittura»), per le quote **già emesse a chi esce** sulle unità del
 * passaggio (D9: le rate emesse non si toccano, si conguagliano).
 *
 * Per ogni quota emessa: la **quota pura del riparto** (`regole_calcolo.importi.quota_pura_gestione`), non
 * l'importo della quota — la parte che viene da un saldo pregresso è dell'uscente per definizione. Il
 * netting del già versato è suo **se la riga di `contributi_versati` porta la sua persona** (decisione 17):
 * non entra nella divisione e si toglie dalla sua parte; senza persona è dell'unità (D8) e abbassa la spesa
 * da dividere, come nel motore (S8-5). La competenza è la stessa del motore, decisa in un posto solo
 * (`CompetenzaDelPiano`, decisioni 11 e 12): ordinario → pro rata per giorni; straordinario → **per riga**
 * congelata (S8-4): la competenza dichiarata sulla fattura, altrimenti il giorno della delibera, tutto da
 * un lato. Dalla decisione 26 (1.11.0-beta.35) anche sull'ordinario la riga che porta la competenza della sua
 * fattura (gradino «dichiarata», piano rate straordinario su gestione ordinaria) si divide su quella, come il motore. Straordinario senza competenza dichiarata e senza data della delibera: quel piano **si salta e
 * si dice**, non si inventa un periodo (decisione 12); gestione senza esercizio: idem.
 *
 * Usufrutto (`soloOrdinario`): le spese straordinarie sono del nudo proprietario per legge (art. 1005 c.c.),
 * quindi non si conguagliano mai con l'usufruttuario, qualunque sia la data della delibera. Riserva d'usufrutto
 * (`soloStraordinario`, 1.11.0-beta.38, decisione 28): lo specchio — chi vende resta usufruttuario e l'ordinaria la deve
 * ancora lui (art. 1004 c.c.), quindi si conguaglia solo la straordinaria, con la regola della vendita.
 *
 * **Capitoli con tratti (S6, decisione 20).** Se il piano ha voci con una competenza propria in
 * `competenze_capitolo` (riscaldamento stagionale), la quota pura non è tutta sulla base: la si scompone
 * per conto con le `righe_riparto` di chi esce (il dettaglio congelato della generazione, beta.29), ogni
 * conto si divide per giorni sulla **sua** competenza — i tratti della pivot del conto, poi della radice,
 * come fa il motore, altrimenti la base — e la parte di chi entra così ottenuta si distribuisce sulle
 * quote emesse in proporzione alla quota pura. Le righe portano solo gli estremi dell'insieme
 * (decisione 15): i tratti veri si rileggono dalla tabella, non dalla riga.
 *
 * La coppia in `saldi` è **una per (gestione, unità)**, solo se l'importo è diverso da zero, con lo stesso
 * `subentro_id` e somma zero (invariante 19): di norma credito all'uscente e debito all'entrante, ma il verso lo dà il
 * segno — con le bozze che passano a chi entra (decisione 25) la coppia può rovesciarsi.
 */
final class ConguaglioPassaggio
{
    /**
     * Decisione 31.5: quali voci ordinarie ha fatto passare a chi entra un passaggio con «come la voce». Nella riserva
     * d'usufrutto quelle sul «Proprietario» (un piano ricalcolato le dà al nudo proprietario); nella costituzione le altre
     * (un piano ricalcolato le dà all'usufruttuario). L'addebito diretto all'unità sta dalla parte del «Proprietario»: il
     * motore lo dà al proprietario, poi al nudo proprietario (`RuoloAnagraficaImmobile::titolariDiDirittoReale()`).
     */
    private const VOCI_SUL_PROPRIETARIO = 'proprietario';
    private const VOCI_NON_SUL_PROPRIETARIO = 'non_proprietario';
    /** Le due insieme, in due passaggi di una catena: nessuna voce sta da tutte e due le parti. */
    private const VOCI_NESSUNA = 'nessuna';

    /** La riga di riparto sta dalla parte del «Proprietario»? Vedi le costanti qui sopra. */
    private static function latoProprietario(object $riga): bool
    {
        return ($riga->ruolo_richiesto ?? null) === 'proprietario' || ($riga->tipo ?? null) === RigaRiparto::TIPO_AD_PERSONAM;
    }

    /** Con `$vociPassate`, la riga passa a chi entra? */
    private static function passaPerVoce(object $riga, string $vociPassate): bool
    {
        return $vociPassate !== self::VOCI_NESSUNA && self::latoProprietario($riga) === ($vociPassate === self::VOCI_SUL_PROPRIETARIO);
    }

    /**
     * Una parte del dettaglio del riparto riportata alla quota pura emessa. Conto interamente già versato dalla persona
     * (verifica S8-bis, L1-3): quota pura 0 su totale 0 — la quota esiste (riga documentaria «coperta da versamento») ed è
     * tutta emessa, fattore 1: il versato di chi esce torna a chi esce per la parte di chi entra, non sparisce. Il totale può
     * essere negativo (nota di credito a riparto): il rapporto conserva il segno, un `> 0` azzerava il conguaglio (strada b,
     * B2-2).
     */
    private static function riportaAllaQuota(int $parte, int $quotaPura, int $totale): int
    {
        return $totale !== 0 ? (int) round($quotaPura * $parte / $totale) : ($quotaPura === 0 ? $parte : 0);
    }

    /**
     * Una quota di un predecessore passa a chi entra per le voci che erano arrivate a chi esce (`$arrivate`, dal passaggio
     * con cui le ha avute) **e** che questo passaggio fa passare (`$qui`). Null da una parte vuol dire «tutte».
     */
    private static function vociInsieme(?string $arrivate, ?string $qui): ?string
    {
        return $arrivate === null || $qui === null || $arrivate === $qui ? ($qui ?? $arrivate) : self::VOCI_NESSUNA;
    }

    public function __construct(private readonly CompetenzaDelPiano $competenza = new CompetenzaDelPiano())
    {
    }

    /**
     * @param list<int> $immobileIds l'unità principale e le pertinenze spuntate
     * @return array{
     *   stato: 'calcolato'|'nessuna_rata',
     *   quote: list<array<string,mixed>>,
     *   per_gestione: list<array<string,mixed>>,
     *   coppie: list<array{gestione_id:int, gestione:string, immobile_id:int, esercizio_id:?int, importo:int}>,
     *   totale_entrante: int, totale_entrante_formattato: string,
     *   pregressi: int, non_risolte: list<array{piano:string, motivo:string}>, frasi: list<string>
     * }
     */
    public function calcola(Anagrafica $uscente, ?Anagrafica $entrante, array $immobileIds, CarbonImmutable $decorrenza, bool $soloOrdinario = false, bool $riassegnaBozze = false, bool $nudaProprieta = false, bool $soloStraordinario = false, bool $ordinariaComeLaVoce = false, array $ruoliCheRestano = [], bool $piuNudi = false, array $entrantiPerUnita = [], ?string $passaggio = null, array $righeUscenti = [], array $nudiOra = [], bool $tutteLeBozze = false, int $eredi = 0): array
    {
        $this->eredi = $eredi;
        $predecessori = $this->predecessori((int) $uscente->id, $immobileIds);
        $righe = $this->quoteConguagliabili((int) $uscente->id, $immobileIds, $predecessori);
        if ($righe->isEmpty()) {
            return $this->vuoto();
        }
        $piani = PianoRate::with('gestione')->findMany($righe->pluck('piano_rate_id')->unique())->keyBy('id');
        $esiti = [];
        $eserciziPerPiano = [];
        $dedotti = [];
        $nonRisolte = [];
        foreach ($piani as $piano) {
            // L'esercizio del piano una volta sola: quello con cui è stato generato (migrazione 9), altrimenti
            // dedotto — e in quel caso lo si dice (verifica S5, R8).
            $esercizio = $this->competenza->esercizioDelPiano($piano);
            $eserciziPerPiano[$piano->id] = $esercizio?->id;
            if ($esercizio !== null && $this->competenza->esercizioDedotto($piano)) {
                $dedotti[] = $piano->nome;
            }
            $esito = $this->competenza->perPiano($piano, $esercizio);
            if ($esito === null) {
                $nonRisolte[] = ['piano' => $piano->nome, 'motivo' => 'la gestione non è legata a nessun esercizio: la competenza non si può determinare'];
            }
            // Straordinario senza delibera: si decide dopo la scomposizione per conto (S8-4) — se ogni riga
            // congelata porta la competenza dichiarata sulla fattura, la delibera non serve.
            $esiti[$piano->id] = $esito;
        }

        // La quota pura per (piano, unità, intestatario) — chi esce e i suoi predecessori (S8-3) — e, dove il piano ha
        // righe congelate per quell'intestatario, la sua scomposizione per conto e per tratto.
        $quotaPuraDi = fn ($r) => (int) (((is_string($r->regole_calcolo) ? json_decode($r->regole_calcolo, true) : (array) $r->regole_calcolo)['importi']['quota_pura_gestione']) ?? $r->importo);
        // Le quote passate con un passaggio precedente (B3a) fanno gruppo a sé: il loro riparto è di chi le aveva.
        $chiaveDi = fn ($r) => $r->piano_rate_id . '|' . $r->immobile_id . '|' . $r->anagrafica_id . '|' . $r->righe_di;
        $quotaPuraPer = [];
        foreach ($righe as $r) {
            $quotaPuraPer[$chiaveDi($r)] = ($quotaPuraPer[$chiaveDi($r)] ?? 0) + $quotaPuraDi($r);
        }
        // Le quote senza composizione (generate con la 1.7.x: `regole_calcolo` vuoto, il saldo pregresso dentro l'importo) su
        // un'unità con un saldo pregresso assorbito da quel piano: la spesa dell'anno non si separa dal pregresso. Il
        // conguaglio non lo indovina — trattarle come «tutta spesa» darebbe il pregresso di chi vende a chi compra — e su
        // quelle quote si ferma e lo dice. Senza un saldo assorbito dal piano la quota è tutta spesa, come prima.
        $senzaIstantanea = $this->senzaComposizione($righe, $chiaveDi, $eserciziPerPiano);
        // Decisioni 55 e 56 (1.11.0-beta.43): la genealogia della quota. Ogni riga di riparto sa da quale riga di titolarità viene
        // (o si deduce, sui piani di prima, dove è unica), e la si segue nei passaggi fino a chi esce: si sa quale parte è
        // arrivata a chi esce, quando, e — all'estinzione — a quale nudo torna. Dove la genealogia decide, prende il posto
        // delle regole del ruolo (`$fuoriQuotaDi`, la parte arrivata, le voci passate prima). Dove non arriva resta indecidibile.
        $legame = $this->legame($uscente, $immobileIds, $decorrenza, $righe, $chiaveDi, $piani, $esiti, $passaggio, $righeUscenti, $nudiOra);
        // Le catene di passaggi in cui una persona ha avuto più quote sull'unità (decisione di Vincenzo del 03/10/2026): la
        // regola delle righe di un'altra quota guarda il ruolo della riga, e lì il ruolo non dice di quale quota è. Dalla .43 il
        // conguaglio di quell'unità si ferma solo sui gruppi in cui la genealogia non arriva, e dice perché; i gruppi che la
        // genealogia decide non si fermano.
        $ambigue = $this->catenaAmbigua($uscente, $immobileIds, $decorrenza, $righe, $esiti, $predecessori, $piuNudi, $ruoliCheRestano);
        $ragioneCatena = [];
        // Le voci che un passaggio «come la voce» fa passare (decisione 31.5): dipende solo dal passaggio; serve anche qui sotto.
        $vociPassateQui = $ordinariaComeLaVoce && $soloStraordinario ? self::VOCI_SUL_PROPRIETARIO
            : ($ordinariaComeLaVoce && $soloOrdinario ? self::VOCI_NON_SUL_PROPRIETARIO : null);
        $primaDi = $righe->groupBy($chiaveDi)->map(fn ($g) => $g->first());
        foreach ($righe as $r) {
            $chiave = $chiaveDi($r);
            $ambigua = $ambigue[(int) $r->immobile_id] ?? null;
            // Rilievo Q1 del quinto giro: un gruppo che la legge del passaggio tiene fuori per intero (l'ordinaria nella riserva con la
            // legge, la straordinaria nella costituzione e nell'estinzione, l'ordinaria che una riserva ha lasciato a un
            // predecessore) resta fuori qualunque sia il pezzo da cui viene la quota: la genealogia non ha niente da decidere.
            $natura = NaturaGestione::daStringa($piani[$r->piano_rate_id]->gestione?->tipo);
            $fuoriPerLegge = ($soloOrdinario && $natura === NaturaGestione::Straordinaria)
                || ($soloStraordinario && $natura === NaturaGestione::Ordinaria && $vociPassateQui === null)
                || $primaDi[$chiave]->riservata_da !== null;
            // Rilievo H2 del terzo giro e decisione 63: la genealogia chiede di fermare il gruppo (una riga, anche di chi esce, su una
            // nuda sommata dopo la generazione e consolidata solo in parte), anche senza una catena ambigua.
            if (($legame[$chiave]['ferma'] ?? false) && ! isset($senzaIstantanea[$chiave]) && ! $fuoriPerLegge) {
                $senzaIstantanea[$chiave] = 'catena';
                $ragioneCatena[$chiave] = $legame[$chiave]['ragione'];
                continue;
            }
            // Non si ferma il gruppo che la genealogia decide, né quello senza quota pura (solo saldi pregressi): non c'è niente da
            // separare. Gli altri sì, con la ragione della genealogia, o quella della catena se il piano non ha righe di riparto.
            if ($ambigua === null || isset($senzaIstantanea[$chiave]) || ($legame[$chiave]['stato'] ?? null) === 'deciso'
                || (! isset($legame[$chiave]) && ($quotaPuraPer[$chiave] ?? 0) === 0)) {
                continue;
            }
            $senzaIstantanea[$chiave] = 'catena';
            $ragioneCatena[$chiave] = $legame[$chiave]['ragione'] ?? $ambigua;
        }
        // Decisione 35 (1.11.0-beta.42, difetto U1): le quote di un predecessore che il suo passaggio non ha fatto passare,
        // perché il piano allora si ricalcolava e non è stato ricalcolato prima di emetterlo. Non sono mai arrivate a chi esce:
        // il conguaglio si ferma su quelle quote e lo dice. Prima di ogni altra fermata: non è una parte da separare.
        $maiPassate = $this->maiPassate($righe, $predecessori, $chiaveDi);
        foreach (array_keys($maiPassate) as $chiave) {
            $senzaIstantanea[$chiave] = 'mai_passate';
        }
        // I gruppi che la genealogia decide, e che nessuna fermata tiene fermi: lì la genealogia prende il posto delle regole del
        // ruolo. Altrove (la genealogia non arriva, o il piano non ha righe di riparto) valgono le regole del ruolo, come prima.
        $deciso = fn (string $chiave): ?array => ($legame[$chiave]['stato'] ?? null) === 'deciso' && ! isset($senzaIstantanea[$chiave]) ? $legame[$chiave] : null;
        // La scomposizione per conto ogni volta che il piano ha righe congelate per l'intestatario (S8-5): è la
        // strada del motore — il netting del già versato si spacca fra parte «della persona» e parte «dell'unità»
        // (decisione 17, D8) e ogni riga si divide sulla sua competenza ∩ il suo tratto di titolarità (migrazione 11,
        // verifica S8-bis L1-1/L1-2/L1-4: la stessa persona con due tratti, il predecessore già tagliato alla sua
        // uscita, chi esce chiuso prima della generazione). Vale anche per lo straordinario (S8-4): lì la competenza
        // è **per riga** (dichiarata sulla fattura, o la delibera), non una per piano. I piani senza righe
        // (pre-beta.29) restano sulla strada di prima: quota pura netta divisa sulla base.
        $perCapitolo = [];
        $entrantePerQuota = [];
        $uscentePerQuota = [];
        $pesiNudoGruppo = [];
        $gruppi = $righe->groupBy($chiaveDi);
        // Fase 1-bis della beta.34, R4 (decisione di Vincenzo del 26/09/2026): nella vendita della NUDA proprietà le
        // ordinarie di un piano generato quando chi vende era proprietario pieno (righe risolte «proprietario») dalla
        // costituzione dell'usufrutto sono dell'usufruttuario (art. 1004 c.c.): fra le parti non passano a chi compra
        // la nuda proprietà (non «già regolate con quel passaggio», rilievo T-B5: senza niente a giornale la costituzione
        // non scrive coppie, decisione 21), come le straordinarie non passano
        // all'usufruttuario (art. 1005 c.c.) — verso il condominio i due rispondono in solido (art. 67 ult. co., decisione 28.4). Le ordinarie che il motore ha dato al nudo proprietario (righe risolte
        // «nuda_proprietario», piano generato durante l'usufrutto) e tutte le straordinarie passano come sempre.
        $ordinariaDellUsufruttuario = [];
        // Decisione 31.5: se chi vende la nuda proprietà aveva costituito l'usufrutto «come la voce», le voci sul «Proprietario»
        // sono rimaste sue da nudo proprietario, e con questa vendita passano a chi compra; le altre erano già dell'usufruttuario.
        $vociDelNudo = [];
        if ($nudaProprieta) {
            // Fase 1-ter della beta.41 (due vendite della nuda proprietà dopo una costituzione «come la voce»): anche le costituzioni dei predecessori sulla stessa unità — Rita costituisce
            // «come la voce», vende la nuda a Dora, Dora la rivende: le voci sul «Proprietario» sono rimaste alla nuda proprietà.
            $catena = [(int) $uscente->id, ...$righe->pluck('righe_di')->map(fn ($id) => (int) $id)->all()];
            $costituzioni = Subentro::whereIn('immobile_id', $immobileIds)->where('tipo_passaggio', 'usufrutto')->where('tipologia', 'usufruttuario')
                ->whereIn('anagrafica_uscente_id', array_values(array_unique($catena)))->where('decorrenza', '<=', $decorrenza->toDateString())->get();
            $costituito = $costituzioni->pluck('immobile_id')->map(fn ($id) => (int) $id)->flip()->all();
            $costituitoComeLaVoce = $costituzioni->filter(fn (Subentro $c) => $c->ordinariaComeLaVoce())->pluck('immobile_id')->map(fn ($id) => (int) $id)->flip()->all();
            foreach ($gruppi as $chiave => $gruppo) {
                $primo = $gruppo->first();
                if (NaturaGestione::daStringa($piani[$primo->piano_rate_id]->gestione?->tipo) !== NaturaGestione::Ordinaria) {
                    continue;
                }
                $ruoli = DB::table('righe_riparto')->where('piano_rate_id', $primo->piano_rate_id)->where('immobile_id', $primo->immobile_id)
                    ->where('anagrafica_id', $primo->righe_di)->where('tipo', RigaRiparto::TIPO_RIPARTO)->pluck('ruolo_risolto');
                // Senza righe (piano anteriore alla beta.29) vale il fatto registrato: un usufrutto costituito da chi vende, e la
                // regola vale per la quota intera. Con le righe vale **riga per riga** (Fase 1-ter della beta.41): restano fuori le
                // sole righe risolte «proprietario», mentre quelle che il motore ha dato al nudo proprietario passano. Prima
                // bastava una riga «proprietario» (gennaio, prima della costituzione) per tenere fuori tutto l'anno.
                $ordinariaDellUsufruttuario[$chiave] = $ruoli->isEmpty() && isset($costituito[(int) $primo->immobile_id]);
                if (($ruoli->contains('proprietario') || $ordinariaDellUsufruttuario[$chiave]) && isset($costituitoComeLaVoce[(int) $primo->immobile_id])) {
                    $vociDelNudo[$chiave] = self::VOCI_SUL_PROPRIETARIO;
                }
            }
        }
        // Decisione 31.5 (1.11.0-beta.41): alla costituzione e alla riserva d'usufrutto l'amministratore sceglie chi paga
        // l'ordinaria dal giorno dell'atto. Con la legge (art. 1004 c.c., la proposta) il conguaglio è quello di sempre; con
        // «come la voce» l'ordinaria si decide **conto per conto**, dal ruolo che la voce chiedeva (decisione 29.1): passano a
        // chi entra le voci che un piano ricalcolato gli darebbe — nella riserva quelle sul «Proprietario», che vanno al nudo
        // proprietario; nella costituzione le altre, che vanno all'usufruttuario — e le altre restano a chi esce. Le quote di
        // un predecessore portano invece ciò che **il loro** passaggio ha fatto passare (`predecessori()`, `voci_passate`).
        $vociPassateDi = function (string $chiave) use ($gruppi, $piani, $vociPassateQui, $vociDelNudo, $ordinariaDellUsufruttuario): ?string {
            $primo = $gruppi[$chiave]->first();
            if (NaturaGestione::daStringa($piani[$primo->piano_rate_id]->gestione?->tipo) !== NaturaGestione::Ordinaria) {
                return null;
            }
            if (empty($primo->ereditata_da)) {
                return $vociPassateQui ?? $vociDelNudo[$chiave] ?? null;
            }
            // Coda 218 (decisione 59, 1.11.0-beta.43): su un piano senza righe di riparto, dopo una costituzione «come dice
            // ogni voce», le quote di un predecessore portano alla nuda proprietà le voci sul «Proprietario», come le quote
            // proprie: prima il gruppo ereditato ignorava `$vociDelNudo`, `$esclusaDi` lo teneva fuori come ordinaria
            // dell'usufruttuario e chi rivendeva la nuda perdeva i giorni dopo l'atto. Con le righe vale la regola riga per riga.
            $arrivate = self::vociInsieme($primo->voci_passate ?? null, $vociPassateQui);

            return $arrivate ?? (($ordinariaDellUsufruttuario[$chiave] ?? false) ? ($vociDelNudo[$chiave] ?? null) : null);
        };
        // Le voci che di una quota di un predecessore erano arrivate a chi esce (null: tutte, o una quota di chi esce).
        $vociArrivateDi = fn (string $chiave): ?string => ! empty($gruppi[$chiave]->first()->ereditata_da) && $vociPassateDi($chiave) !== null
            ? ($gruppi[$chiave]->first()->voci_passate ?? null) : null;
        // Rilievo B3 della Fase 1-bis (beta.38): la quota ordinaria di un predecessore raggiunto attraverso una vendita con
        // riserva d'usufrutto non è mai passata a chi esce — l'ha tenuta chi vendeva, usufruttuario (art. 1004 c.c.) — e
        // resta fuori, con o senza righe di riparto: per il tipo dell'anello (`predecessori()`), non per il ruolo risolto.
        $esclusaDi = fn (string $chiave, NaturaGestione $natura) => ($soloOrdinario && $natura === NaturaGestione::Straordinaria)
            || ($soloStraordinario && $natura === NaturaGestione::Ordinaria && $vociPassateQui === null)
            // R4 non vale per l'ordinaria che un passaggio «come la voce» ha già fatto passare voce per voce (decisione 31.5):
            // le voci sul «Proprietario» passate al nudo proprietario con una riserva passano con la sua rivendita.
            || (($ordinariaDellUsufruttuario[$chiave] ?? false) && $vociPassateDi($chiave) === null)
            || $gruppi[$chiave]->first()->riservata_da !== null;
        // Fase 1-ter della beta.41, 03/10/2026: una riga di riparto passa solo se è della quota che passa. Restano fuori
        // le righe dei ruoli che chi esce — o il predecessore, il giorno del suo passaggio — tiene accanto a quella quota
        // (l'usufrutto dell'altra metà, la nuda proprietà che la costituzione gli lascia), e, nella vendita della nuda
        // proprietà, le ordinarie risolte «proprietario» (R4 riga per riga), salvo quelle sul «Proprietario» di un usufrutto
        // costituito «come la voce». Null: nessuna riga fuori, come prima. La regola dice anche perché, e le frasi lo ripetono:
        // `tiene:<ruolo>` (un ruolo che chi esce tiene), `mai_arrivata:<ruolo>` (di un predecessore, mai passato a chi esce),
        // `usufruttuario` (R4).
        $costituitoVoce = $nudaProprieta ? ($costituitoComeLaVoce ?? []) : [];
        $fuoriQuotaDi = function (string $chiave) use ($gruppi, $piani, $ruoliCheRestano, $nudaProprieta, $costituitoVoce, $vociPassateDi): ?\Closure {
            $primo = $gruppi[$chiave]->first();
            $restano = ! empty($primo->ereditata_da) ? ($primo->restano ?? []) : (! empty($primo->passata_il) ? [] : ($ruoliCheRestano[(int) $primo->immobile_id] ?? []));
            // R4 come prima: non vale per l'ordinaria che un passaggio «come la voce» ha già fatto passare voce per voce (la
            // riserva «come la voce» ha dato al nudo proprietario le voci sul «Proprietario»).
            $r4 = $nudaProprieta && NaturaGestione::daStringa($piani[$primo->piano_rate_id]->gestione?->tipo) === NaturaGestione::Ordinaria
                && $vociPassateDi($chiave) === null;
            if ($restano === [] && ! $r4) {
                return null;
            }
            $voce = isset($costituitoVoce[(int) $primo->immobile_id]);

            $predecessore = ! empty($primo->ereditata_da);

            return fn (object $r): ?string => match (true) {
                ($r->ruolo_risolto ?? null) !== null && in_array($r->ruolo_risolto, $restano, true) => ($predecessore ? 'mai_arrivata:' : 'tiene:') . $r->ruolo_risolto,
                $r4 && ($r->ruolo_risolto ?? null) === 'proprietario' && ! ($voce && self::latoProprietario($r)) => 'usufruttuario',
                default => null,
            };
        };
        // Rilievo M-R5 della revisione della Fase 1-ter: un piano senza righe di riparto (generato prima della beta.29) si legge
        // dalla ricostruzione del motore, che usa le righe di titolarità di oggi e le conta tutte, anche quelle chiuse. Su
        // un'unità mista, dopo un passaggio registrato dopo la generazione, le quote non fanno più 100 e la ricostruzione torna
        // alla regola della Coda 58: non descrive più la quota emessa. Se il conguaglio deve separare una quota (un ruolo che
        // resta, una parte arrivata), lì si ferma e lo dice. Il primo passaggio non cambia, e nemmeno un'unità non mista.
        foreach ($gruppi as $chiave => $gruppo) {
            $primo = $gruppo->first();
            if (isset($senzaIstantanea[$chiave]) || ($fuoriQuotaDi($chiave) === null && ($primo->arrivata ?? null) === null)
                || DB::table('righe_riparto')->where('piano_rate_id', $primo->piano_rate_id)->where('immobile_id', $primo->immobile_id)->where('anagrafica_id', $primo->righe_di)->exists()
                || ! $this->unitaMista((int) $primo->immobile_id, ($esiti[$primo->piano_rate_id] ?? null)?->periodi?->dal() ?? $decorrenza->startOfYear(), $decorrenza)) {
                continue;
            }
            $primaQuota = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $primo->piano_rate_id)
                ->where('rate_quote.immobile_id', $primo->immobile_id)->min('rate_quote.id');
            // Dopo la generazione: il passaggio è stato registrato quando le quote del piano c'erano già. Senza registro (prima
            // della beta.37) non si sa, e nel dubbio ci si ferma. Seconda revisione della Fase 1-ter (M2-4): contano solo i
            // passaggi che cambiano proprietà, nuda proprietà o usufrutto (non una locazione), e solo dentro la competenza del piano.
            $dalPiano = ($esiti[$primo->piano_rate_id] ?? null)?->periodi?->dal() ?? $decorrenza->startOfYear();
            if (Subentro::where('immobile_id', $primo->immobile_id)->whereIn('tipo_passaggio', ['vendita', 'usufrutto', 'successione'])
                ->whereDate('decorrenza', '>=', $dalPiano->toDateString())->get(['id', 'registro'])
                ->contains(fn ($x) => ! isset($x->registro['quota_max_id']) || (int) $x->registro['quota_max_id'] >= $primaQuota)) {
                $senzaIstantanea[$chiave] = 'ricostruzione';
            }
        }
        $dettagli = [];
        foreach ($gruppi as $chiave => $gruppo) {
            if (isset($senzaIstantanea[$chiave])) {
                continue;
            }
            $primo = $gruppo->first();
            $piano = $piani[$primo->piano_rate_id];
            $esito = $esiti[$piano->id];
            $straordinaria = NaturaGestione::daStringa($piano->gestione?->tipo) === NaturaGestione::Straordinaria;
            if ($esito === null || (! $straordinaria && ! $esito->risolto())) {
                continue;
            }
            $tratti = $straordinaria ? [] : PeriodoDellaRiga::trattiPerConto((int) $piano->id);
            $decorrenzaAcquisto = (! empty($primo->ereditata_da) || ! empty($primo->passata_il)) && ! empty($primo->decorrenza_acquisto) ? CarbonImmutable::parse($primo->decorrenza_acquisto) : null;
            $genealogia = $deciso($chiave);
            $dettaglio = $genealogia !== null
                // Decisione 56: la genealogia dice, riga per riga, quale parte è arrivata a chi esce e da quando, e quale è di un'altra
                // quota; il giorno d'arrivo conta per le quote di un predecessore, o passate a chi esce con un passaggio di prima.
                // Le voci che i passaggi di prima hanno fatto passare le dice la genealogia (la riserva e la costituzione «come la voce»
                // hanno già diviso le righe per lato): qui conta solo la scelta di questo passaggio. Le bozze seguono le regole di
                // prima (decisione 25): il segnale «per voce» della quota resta quello di `$vociPassateDi`.
                ? $this->scomponiPerConto((int) $piano->id, (int) $primo->immobile_id, (int) $primo->righe_di, $tratti, $esito->risolto() ? $esito->periodi : null, $decorrenza, $straordinaria, $decorrenzaAcquisto,
                    $straordinaria ? null : $vociPassateQui, decorrenzeLato: ! empty($primo->ereditata_da) && ! $straordinaria ? ($primo->decorrenze_lato ?? null) : null,
                    genealogia: $genealogia['parti'], arrivoPerParte: $decorrenzaAcquisto !== null)
                : $this->scomponiPerConto((int) $piano->id, (int) $primo->immobile_id, (int) $primo->righe_di, $tratti, $esito->risolto() ? $esito->periodi : null, $decorrenza, $straordinaria, $decorrenzaAcquisto, $vociPassateDi($chiave), $vociArrivateDi($chiave),
                    ! empty($primo->ereditata_da) && ! $straordinaria ? ($primo->decorrenze_lato ?? null) : null, $fuoriQuotaDi($chiave), $primo->arrivata ?? null);
            if ($dettaglio === null) {
                continue;
            }
            $dettagli[$chiave] = [$dettaglio, $decorrenzaAcquisto];
        }
        // DV1 (decisione 59, 1.11.0-beta.43): nell'ultimo anello di una catena le quote di una stessa unità hanno più intestatari
        // (chi esce e i predecessori), un gruppo ciascuno, e i gruppi leggono lo stesso dettaglio del riparto. La parte di chi
        // entra — e, per i predecessori, quella di chi esce — si arrotonda una volta sull'insieme dei gruppi che condividono il
        // dettaglio, e si divide fra loro con i resti maggiori: prima ogni gruppo arrotondava per conto suo, e la somma poteva
        // sforare di un centesimo (Carlo 35508 invece di 35507, `CateneDelConguaglioTest`).
        $parteDi = [];
        foreach (['entrante', 'uscente'] as $campo) {
            $famiglie = [];
            foreach ($dettagli as $chiave => [$dettaglio, $decorrenzaAcquisto]) {
                if ($campo === 'uscente' && $decorrenzaAcquisto === null) {
                    continue;
                }
                $primo = $gruppi[$chiave]->first();
                $famiglie[implode('|', [$primo->piano_rate_id, $primo->immobile_id, $primo->righe_di, (int) $dettaglio[$campo], (int) $dettaglio['totale']])][] = $chiave;
            }
            foreach ($famiglie as $chiavi) {
                $dettaglio = $dettagli[$chiavi[0]][0];
                $quotePure = array_map(fn ($k) => (int) ($quotaPuraPer[$k] ?? 0), array_combine($chiavi, $chiavi));
                $concordi = $dettaglio['totale'] !== 0 && (min($quotePure) > 0 || max($quotePure) < 0);
                if (count($chiavi) > 1 && $concordi) {
                    $dv1Totale = (int) round(array_sum($quotePure) * (int) $dettaglio[$campo] / $dettaglio['totale']);
                    foreach (MoneyHelper::ripartisciPerQuote($dv1Totale, array_map(fn ($q) => (float) abs($q), $quotePure)) as $k => $cents) {
                        $parteDi[$campo][$k] = (int) $cents;
                    }
                    continue;
                }
                foreach ($chiavi as $k) {
                    $parteDi[$campo][$k] = self::riportaAllaQuota((int) $dettaglio[$campo], $quotePure[$k], (int) $dettaglio['totale']);
                }
            }
        }
        foreach ($dettagli as $chiave => [$dettaglio, $decorrenzaAcquisto]) {
            $gruppo = $gruppi[$chiave];
            // La parte di chi entra (e, per un predecessore, quella di chi esce), riportata alla quota pura emessa e
            // distribuita sulle quote (resti maggiori).
            $quotaPuraTot = $quotaPuraPer[$chiave] ?? 0;
            $fattore = fn (int $parte) => self::riportaAllaQuota($parte, $quotaPuraTot, (int) $dettaglio['totale']);
            $bersaglio = $parteDi['entrante'][$chiave];
            $pesi = $gruppo->mapWithKeys(fn ($r) => [(int) $r->id => (float) abs($quotaPuraDi($r))])->all();
            foreach (MoneyHelper::ripartisciPerQuote($bersaglio, $pesi) as $rataQuoteId => $cents) {
                $entrantePerQuota[$rataQuoteId] = (int) $cents;
            }
            // Decisione 56: all'estinzione, quanto della parte di chi entra di questo gruppo va a ciascun nudo, riportato alle quote emesse.
            if (($dettaglio['pesi_nudo'] ?? []) !== [] && (int) $dettaglio['entrante'] !== 0) {
                $pesiNudoGruppo[$chiave] = array_map(fn ($w) => round((float) $w * $bersaglio / (int) $dettaglio['entrante'], 6), $dettaglio['pesi_nudo']);
            } elseif ($bersaglio === 0) {
                $pesiNudoGruppo[$chiave] = [];
            }
            if ($decorrenzaAcquisto !== null) {
                foreach (MoneyHelper::ripartisciPerQuote($parteDi['uscente'][$chiave], $pesi) as $rataQuoteId => $cents) {
                    $uscentePerQuota[$rataQuoteId] = (int) $cents;
                }
            }
            // Il dettaglio per conto riportato alla stessa grana della testa (verifica S6, R8): con l'emissione
            // parziale le righe dicevano gli importi dell'intero piano sotto un conguaglio calcolato sulle sole quote
            // emesse, e «→ a chi entra» era un numero diverso da quello scritto. Resti maggiori, così le righe
            // sommano esattamente alla testa; con l'emissione completa coincidono con l'intero piano.
            // Chiavi per posizione, non per conto: lo stesso conto può avere due competenze (S8-4) o due tratti (L1-1).
            // Ogni conto scalato col suo segno (strada b, B2-3): con una nota di credito accanto a una spesa i pesi in
            // valore assoluto davano righe tutte positive che non erano quelle emesse. Il resto dell'arrotondamento
            // va sul conto con l'importo assoluto maggiore, così le righe sommano esattamente alla testa.
            $scala = function (int $totale, string $campo) use ($fattore, $dettaglio): array {
                $valori = array_map(fn ($c) => $fattore((int) $c[$campo]), $dettaglio['conti']);
                $resto = $totale - (int) array_sum($valori);
                if ($resto !== 0 && $valori !== []) {
                    $indici = array_keys($valori);
                    usort($indici, fn ($a, $b) => abs((int) $dettaglio['conti'][$b][$campo]) <=> abs((int) $dettaglio['conti'][$a][$campo]));
                    $valori[$indici[0]] += $resto;
                }

                return $valori;
            };
            $importiEmessi = $scala($quotaPuraTot, 'importo');
            $entrantiEmessi = $scala($bersaglio, 'entrante');
            foreach ($dettaglio['conti'] as $i => $c) {
                $dettaglio['conti'][$i]['importo_emesso'] = (int) ($importiEmessi[$i] ?? 0);
                $dettaglio['conti'][$i]['importo_emesso_formattato'] = MoneyHelper::format((int) ($importiEmessi[$i] ?? 0));
                $dettaglio['conti'][$i]['entrante_emesso'] = (int) ($entrantiEmessi[$i] ?? 0);
                $dettaglio['conti'][$i]['entrante_emesso_formattato'] = MoneyHelper::format((int) ($entrantiEmessi[$i] ?? 0));
            }
            $perCapitolo[$chiave] = $dettaglio;
        }
        $dette = [];
        foreach ($senzaIstantanea as $k => $perche) {
            [$pianoId, $immobileId] = array_map('intval', array_slice(explode('|', $k), 0, 2));
            $motivo = match ($perche) {
                'catena' => sprintf('su %s le quote sono passate per più strade (%s): il conguaglio non separa con certezza la parte che passa — se serve un conguaglio, si scrive con un saldo manuale dal Wallet sulla stessa gestione', $righe->firstWhere('immobile_id', $immobileId)?->immobile_nome ?? 'questa unità', $ragioneCatena[$k] ?? $ambigue[$immobileId]),
                'mai_passate' => sprintf('le quote intestate a %s su %s non sono passate con il suo passaggio del %s: quel passaggio non le ha prese nel conguaglio e le ha lasciate al ricalcolo, che non c\'è stato — restano di %s, senza conguaglio; se serve un conguaglio, si scrive con un saldo manuale dal Wallet sulla stessa gestione',
                    $maiPassate[$k]['nome'], $righe->firstWhere('immobile_id', $immobileId)?->immobile_nome ?? 'questa unità', $this->data($maiPassate[$k]['il']), $maiPassate[$k]['nome']),
                'ricostruzione' => sprintf('il piano non ha il dettaglio del riparto, e la titolarità di %s è cambiata con un passaggio dopo la generazione, o registrato da una versione che non annotava quando: la parte della quota che passa non si separa con certezza — se serve un conguaglio, si scrive con un saldo manuale dal Wallet sulla stessa gestione', $righe->firstWhere('immobile_id', $immobileId)?->immobile_nome ?? 'questa unità'),
                default => 'le sue quote sono state generate da una versione che non salvava quanta parte di ogni rata è saldo pregresso, e su questa unità il piano ha assorbito un saldo pregresso: la spesa dell\'anno non si separa dal pregresso — se serve un conguaglio, si scrive con un saldo manuale dal Wallet sulla stessa gestione',
            };
            if (! isset($dette[$pianoId . '|' . $motivo])) {
                $dette[$pianoId . '|' . $motivo] = true;
                $nonRisolte[] = ['piano' => $piani[$pianoId]->nome, 'motivo' => $motivo];
            }
        }
        $unitaDelPiano = fn (PianoRate $piano) => $righe->where('piano_rate_id', $piano->id)->map($chiaveDi)->unique();
        foreach ($piani as $piano) {
            $esito = $esiti[$piano->id];
            if ($esito === null || ! $esito->richiedeDelibera) {
                continue;
            }
            // Risolto dalle righe se ogni (unità, intestatario) del piano ha la scomposizione e nessun conto è rimasto senza competenza.
            $risoltoDalleRighe = $unitaDelPiano($piano)->every(fn ($chiave) => isset($perCapitolo[$chiave]) && $perCapitolo[$chiave]['non_risolte'] === 0);
            if ($risoltoDalleRighe) {
                continue;
            }
            // S8-4: con un intervento urgente (art. 1135 co. 2 c.c.) non c'è una delibera da registrare e le rate
            // emesse non si ricalcolano: la strada è la competenza dichiarata sulle fatture del piano; per le quote
            // già emesse, un saldo manuale dal Wallet o la rinuncia motivata al conguaglio.
            $nonRisolte[] = ['piano' => $piano->nome, 'motivo' => $piano->tipo_autorizzazione === 'urgenza'
                ? 'intervento urgente senza delibera: la competenza si dichiara sulle fatture del piano (Costo maturato / Spesa deliberata il); le quote di questo piano non entrano nel conguaglio proposto — con rate già emesse si regola con un saldo manuale dal Wallet sulla stessa gestione'
                : 'manca la data della delibera dell\'assemblea: registrala sul piano e ricalcola'];
        }

        // Ramo base (nessuna riga congelata: piani pre-beta.29): la parte di chi entra si calcola **una volta** sulla
        // quota pura dell'intero (piano, unità, intestatario) e si distribuisce sulle quote con i resti maggiori — come
        // il ramo per conto e come il motore. Dividere quota per quota arrotondava ogni rata da sé (4 × 6.914 = 27.656
        // invece di 27.655). Per un predecessore (S8-3) la parte di chi esce è ciò che gli era passato all'acquisto
        // meno ciò che passa ora — senza righe non si sa quali giorni la quota coprisse, e si assume l'intero periodo.
        $baseDivisa = [];
        foreach ($gruppi as $chiave => $gruppo) {
            if (isset($perCapitolo[$chiave]) || isset($senzaIstantanea[$chiave])) {
                continue;
            }
            $primo = $gruppo->first();
            $piano = $piani[$primo->piano_rate_id];
            $esito = $esiti[$piano->id];
            $esclusa = $esclusaDi($chiave, NaturaGestione::daStringa($piano->gestione?->tipo));
            if ($esito === null || ! $esito->risolto() || $esclusa) {
                continue;
            }
            $quotaPuraTot = (int) $gruppo->sum($quotaPuraDi);
            // Decisione 31.5, «come la voce», su un piano senza righe (anteriore alla beta.29, cioè ogni piano di chi arriva
            // dalla 1.10.0): le voci si leggono dalla ricostruzione del motore in sola lettura, la stessa delle stampe
            // (decisione 29.1), e si divide per giorni solo la parte della quota che viene dalle voci che passano.
            $quotaDivisibile = $quotaPuraTot;
            $frazione = 1.0;
            $vociPassate = $vociPassateDi($chiave);
            // Fase 1-ter della beta.41: anche senza righe, la parte di un'altra quota (un ruolo che chi esce tiene, l'ordinaria
            // dell'usufruttuario nella vendita della nuda) si legge dalla ricostruzione del motore, e non passa.
            $fuori = $fuoriQuotaDi($chiave);
            // E del ruolo arrivato solo in parte (un'estinzione a più nudi proprietari), il resto: pesa la frazione arrivata.
            $arrivata = $primo->arrivata ?? null;
            $passaQuota = fn (object $r): float => $fuori !== null && $fuori($r) !== null ? 0.0
                : ($arrivata !== null && ($r->ruolo_risolto ?? null) === $arrivata['ruolo'] ? (float) $arrivata['frazione'] : 1.0);
            $frazioneQuota = 1.0;
            if ($fuori !== null || $arrivata !== null) {
                $frazioneQuota = $this->frazioneDelleRighe($piano, (int) $primo->immobile_id, (int) $primo->righe_di, $passaQuota);
                if ($frazioneQuota === null) {
                    $nonRisolte[] = ['piano' => $piano->nome, 'motivo' => 'il piano non ha il dettaglio del riparto e la ricostruzione non trova le righe di questa unità: la parte della quota che passa non si separa da quella di un ruolo che resta'];
                    continue;
                }
                $frazione = $frazioneQuota;
            }
            if ($vociPassate !== null) {
                $frazione = $this->frazioneDelleRighe($piano, (int) $primo->immobile_id, (int) $primo->righe_di, fn (object $r): float => self::passaPerVoce($r, $vociPassate) ? $passaQuota($r) : 0.0);
                if ($frazione === null) {
                    $nonRisolte[] = ['piano' => $piano->nome, 'motivo' => 'il piano non ha il dettaglio del riparto e la ricostruzione non trova le voci di questa unità: la parte che segue la voce non si può dividere'];
                    continue;
                }
            }
            $quotaDivisibile = (int) round($quotaPuraTot * $frazione);
            $parti = ProRataTemporis::dividi($quotaDivisibile, $esito->periodi, $decorrenza);
            $pesi = $gruppo->mapWithKeys(fn ($r) => [(int) $r->id => (float) abs($quotaPuraDi($r))])->all();
            $voce = ['giorni' => ['giorni_uscente' => $parti['giorni_uscente'], 'giorni_entrante' => $parti['giorni_entrante'], 'giorni_periodo' => $parti['giorni_periodo']], 'entrante' => MoneyHelper::ripartisciPerQuote((int) $parti['entrante'], $pesi), 'uscente' => null, 'frazione' => $frazione, 'frazione_quota' => $frazioneQuota,
                'riconcilia' => ['divisibile' => $quotaDivisibile, 'arrivata' => null, 'acquisto' => null, 'pesi' => $pesi, 'periodi' => $esito->periodi],
                'motivi_quota' => $frazioneQuota < 1.0 ? $this->motiviDelleRighe($piano, (int) $primo->immobile_id, (int) $primo->righe_di, $fuori, $arrivata) : []];
            if ((! empty($primo->ereditata_da) || ! empty($primo->passata_il)) && ! empty($primo->decorrenza_acquisto)) {
                // Decisione 31.5: a chi esce era arrivata tutta la quota, o solo le voci che un passaggio «come la voce» prima
                // di lui aveva fatto passare; quelle che ora non passano restano sue dal suo acquisto.
                $vociArrivate = $vociArrivateDi($chiave);
                $quotaArrivata = $vociArrivate === null ? (int) round($quotaPuraTot * $frazioneQuota)
                    : (int) round($quotaPuraTot * ($this->frazioneDelleRighe($piano, (int) $primo->immobile_id, (int) $primo->righe_di, fn (object $r): float => self::passaPerVoce($r, $vociArrivate) ? $passaQuota($r) : 0.0) ?? 0.0));
                $acquisto = ProRataTemporis::dividi($quotaArrivata, $esito->periodi, CarbonImmutable::parse($primo->decorrenza_acquisto));
                // Rilievo D5: i due lati arrivati in giorni diversi si contano ciascuno dal suo giorno.
                $lati = $primo->decorrenze_lato ?? null;
                if ($vociArrivate === null && is_array($lati) && ($lati['P'] ?? null) !== null && ($lati['A'] ?? null) !== null && $lati['P'] !== $lati['A']
                    && ($frazioneP = $this->frazioneDelleRighe($piano, (int) $primo->immobile_id, (int) $primo->righe_di, fn (object $r): float => self::passaPerVoce($r, self::VOCI_SUL_PROPRIETARIO) ? $passaQuota($r) : 0.0)) !== null) {
                    $quotaP = (int) round($quotaPuraTot * $frazioneP);
                    $acquistoP = ProRataTemporis::dividi($quotaP, $esito->periodi, CarbonImmutable::parse($lati['P']));
                    $acquistoA = ProRataTemporis::dividi($quotaArrivata - $quotaP, $esito->periodi, CarbonImmutable::parse($lati['A']));
                    // Le voci tutte da un lato: i giorni sono quelli del lato. Da tutti e due: il denaro si somma, e la frase
                    // dice le due date (i giorni di chi esce non sono più uno solo).
                    $voce['lati'] = $quotaP === 0 ? ['A'] : ($quotaP === $quotaArrivata ? ['P'] : ['P', 'A']);
                    $acquisto = match ($voce['lati']) {
                        ['A'] => $acquistoA,
                        ['P'] => $acquistoP,
                        default => ['entrante' => (int) $acquistoP['entrante'] + (int) $acquistoA['entrante']] + $acquisto,
                    };
                }
                $voce['giorni'] = ['giorni_uscente' => max(0, $acquisto['giorni_entrante'] - $parti['giorni_entrante']), 'giorni_entrante' => min($parti['giorni_entrante'], $acquisto['giorni_entrante']), 'giorni_periodo' => $acquisto['giorni_entrante']];
                $voce['uscente'] = MoneyHelper::ripartisciPerQuote(max(0, (int) $acquisto['entrante'] - (int) $parti['entrante']), $pesi);
                if (! isset($voce['lati'])) {
                    $voce['riconcilia']['arrivata'] = $quotaArrivata;
                    $voce['riconcilia']['acquisto'] = (string) $primo->decorrenza_acquisto;
                }
            }
            $baseDivisa[$chiave] = $voce;
        }
        // DV1 (decisione 59, 1.11.0-beta.43), anche senza righe di riparto: i gruppi della stessa unità e dello stesso piano
        // che dividono con la stessa frazione e le stesse date si arrotondano una volta sull'insieme, e la parte si divide fra
        // loro con i resti maggiori. Prima ogni gruppo arrotondava per sé: quattro quote emesse a Ugo e otto bozze passate a
        // Zeta davano € 335,34 + € 670,68 = € 1.006,02, un centesimo sotto i 306 giorni di € 1.200,00 (€ 1.006,03).
        $dv1Famiglie = [];
        foreach ($baseDivisa as $chiave => $voce) {
            $primo = $gruppi[$chiave]->first();
            $dv1Famiglie[implode('|', [$primo->piano_rate_id, $primo->immobile_id, $primo->righe_di, $voce['frazione'], $voce['frazione_quota']])][] = $chiave;
        }
        foreach ($dv1Famiglie as $dv1Chiavi) {
            if (count($dv1Chiavi) < 2) {
                continue;
            }
            $dv1Divisibili = array_map(fn ($k) => (int) $baseDivisa[$k]['riconcilia']['divisibile'], array_combine($dv1Chiavi, $dv1Chiavi));
            $dv1Periodi = $baseDivisa[$dv1Chiavi[0]]['riconcilia']['periodi'];
            $dv1EntrantePer = array_map(fn ($k) => (int) array_sum($baseDivisa[$k]['entrante']), array_combine($dv1Chiavi, $dv1Chiavi));
            if (min($dv1Divisibili) > 0) {
                $dv1Entrante = (int) ProRataTemporis::dividi(array_sum($dv1Divisibili), $dv1Periodi, $decorrenza)['entrante'];
                $dv1EntrantePer = MoneyHelper::ripartisciPerQuote($dv1Entrante, array_map('floatval', $dv1Divisibili));
                foreach ($dv1Chiavi as $k) {
                    $baseDivisa[$k]['entrante'] = MoneyHelper::ripartisciPerQuote((int) $dv1EntrantePer[$k], $baseDivisa[$k]['riconcilia']['pesi']);
                }
            }
            // La parte di chi esce, per i gruppi dei predecessori arrivati lo stesso giorno: quello che gli era arrivato dal suo
            // acquisto, meno ciò che passa ora, arrotondato una volta.
            $dv1PerAcquisto = [];
            foreach ($dv1Chiavi as $k) {
                if ($baseDivisa[$k]['riconcilia']['arrivata'] !== null) {
                    $dv1PerAcquisto[$baseDivisa[$k]['riconcilia']['acquisto']][] = $k;
                }
            }
            foreach ($dv1PerAcquisto as $dv1Giorno => $dv1Stesse) {
                $dv1Arrivate = array_map(fn ($k) => (int) $baseDivisa[$k]['riconcilia']['arrivata'], array_combine($dv1Stesse, $dv1Stesse));
                if (count($dv1Stesse) < 2 || ! (min($dv1Arrivate) > 0)) {
                    continue;
                }
                $dv1QuiEntra = array_sum(array_map(fn ($k) => (int) $dv1EntrantePer[$k], $dv1Stesse));
                $dv1Acquisto = (int) ProRataTemporis::dividi(array_sum($dv1Arrivate), $dv1Periodi, CarbonImmutable::parse((string) $dv1Giorno))['entrante'];
                $dv1UscentePer = MoneyHelper::ripartisciPerQuote(max(0, $dv1Acquisto - $dv1QuiEntra), array_map('floatval', $dv1Arrivate));
                foreach ($dv1Stesse as $k) {
                    $baseDivisa[$k]['uscente'] = MoneyHelper::ripartisciPerQuote((int) $dv1UscentePer[$k], $baseDivisa[$k]['riconcilia']['pesi']);
                }
            }
        }

        $quote = [];
        foreach ($righe as $r) {
            $piano = $piani[$r->piano_rate_id];
            $esito = $esiti[$piano->id];
            $natura = NaturaGestione::daStringa($piano->gestione?->tipo);
            $quotaPura = $quotaPuraDi($r);
            $chiave = $chiaveDi($r);
            $capitoli = $perCapitolo[$chiave] ?? null;
            $risolta = (($esito !== null && $esito->risolto()) || $capitoli !== null) && ! isset($senzaIstantanea[$chiave]);
            $esclusa = $esclusaDi($chiave, $natura);
            $ereditata = ! empty($r->ereditata_da);
            $acquistoDellaQuota = $this->acquistoDellaQuota($r, $capitoli !== null ? ($capitoli['lati'] ?? null) : ($baseDivisa[$chiave]['lati'] ?? null));

            if ($capitoli !== null && ! $esclusa) {
                $parteEntrante = $entrantePerQuota[(int) $r->id] ?? 0;
                // Per una quota del predecessore chi esce ha solo la parte fra acquisto e decorrenza; il resto era già
                // del predecessore e non riguarda questo passaggio.
                $parteUscente = $ereditata || ! empty($r->passata_il) ? ($uscentePerQuota[(int) $r->id] ?? 0) : $quotaPura - $parteEntrante;
                $parti = ['uscente' => $parteUscente, 'entrante' => $parteEntrante] + $capitoli['giorni'];
            } elseif (isset($baseDivisa[$chiave]) && $risolta && ! $esclusa) {
                $base = $baseDivisa[$chiave];
                $parteEntrante = (int) ($base['entrante'][(int) $r->id] ?? 0);
                $parteUscente = $base['uscente'] !== null ? (int) ($base['uscente'][(int) $r->id] ?? 0) : $quotaPura - $parteEntrante;
                $parti = ['uscente' => $parteUscente, 'entrante' => $parteEntrante] + $base['giorni'];
            } else {
                $parti = ['uscente' => $quotaPura, 'entrante' => 0, 'giorni_uscente' => null, 'giorni_entrante' => null, 'giorni_periodo' => null];
            }

            $quote[] = [
                'rata_quote_id'  => (int) $r->id,
                'immobile_id'    => (int) $r->immobile_id,
                'immobile_nome'  => $r->immobile_nome,
                'piano_rate_id'  => (int) $piano->id,
                'piano'          => $piano->nome,
                'esercizio_id'   => $eserciziPerPiano[$piano->id],
                'gestione_id'    => (int) $piano->gestione_id,
                'gestione'       => $piano->gestione?->nome,
                'natura'         => $natura->value,
                'rata'           => (int) $r->numero_rata,
                'scadenza'       => substr((string) $r->data_scadenza, 0, 10),
                'importo'        => (int) $r->importo,
                'importo_formattato' => MoneyHelper::format((int) $r->importo),
                'quota_pura'     => $quotaPura,
                'pregresso'      => (int) $r->importo - $quotaPura,
                'gradino'        => $risolta ? ($capitoli !== null && $capitoli['dettagliato'] ? $capitoli['gradino'] : $esito->gradino?->value) : null,
                'periodo'        => $risolta ? ($capitoli !== null && ($natura === NaturaGestione::Straordinaria || ! empty($capitoli['competenza_per_fattura'])) ? $capitoli['periodo'] : $esito->periodi?->toArray()) : null,
                // La divisione è per conto e il dettaglio sta qui (uguale per tutte le quote del piano sull'unità), quando dice qualcosa in più della testa.
                'per_capitolo'   => $capitoli !== null && $capitoli['dettagliato'] && ! $esclusa ? $capitoli['conti'] : null,
                'voce_per_voce'  => $risolta && $capitoli !== null && ! empty($capitoli['voce_per_voce']),
                'giorni_uscente' => $parti['giorni_uscente'],
                'giorni_entrante' => $parti['giorni_entrante'],
                'giorni_periodo' => $parti['giorni_periodo'],
                'uscente'        => $parti['uscente'],
                'entrante'       => $parti['entrante'],
                'entrante_formattato' => MoneyHelper::format((int) $parti['entrante']),
                // Decisione 56: il gruppo della quota, per sommare i pesi per nudo della genealogia sulla coppia.
                'gruppo'         => $chiave,
                'non_risolta'    => ! $risolta,
                // Una quota senza composizione su un'unità con un saldo pregresso assorbito dal piano (la 1.7.x).
                'senza_istantanea' => ($senzaIstantanea[$chiave] ?? null) === true,
                // Una catena di passaggi in cui il ruolo della riga non dice di quale quota è.
                'catena_ambigua' => in_array($senzaIstantanea[$chiave] ?? null, ['catena', 'ricostruzione'], true),
                // Decisione 35: una quota di un predecessore che il suo passaggio non ha fatto passare.
                'mai_passata'    => ($senzaIstantanea[$chiave] ?? null) === 'mai_passate',
                'esclusa'        => $esclusa,
                // Decisione 31.5: l'ordinaria di questa quota si decide voce per voce.
                'per_voce'       => $vociPassateDi($chiave) !== null,
                // Almeno una voce resta per la scelta, con la competenza che arriva al giorno dell'atto: le frasi lo dicono.
                'resta_per_voce' => $vociPassateDi($chiave) !== null && ! $esclusa && ($capitoli !== null
                    ? ! empty($capitoli['resta_per_voce'])
                    : isset($baseDivisa[$chiave]) && (int) ($parti['giorni_entrante'] ?? 0) > 0 && ($baseDivisa[$chiave]['frazione'] ?? 1.0) < 1.0),
                // Senza righe, la parte della quota fatta delle voci che passano: è quella che si divide per giorni.
                'quota_che_passa' => isset($baseDivisa[$chiave]) && $capitoli === null ? (int) round($quotaPura * ($baseDivisa[$chiave]['frazione'] ?? 1.0)) : $quotaPura,
                // Fase 1-ter della beta.41: la quota ha righe di un'altra quota (`fuori_quota`), o solo righe così (`tutta_fuori`).
                'fuori_quota'    => ! $esclusa && ($capitoli !== null ? ! empty($capitoli['fuori_quota']) : (($baseDivisa[$chiave]['frazione_quota'] ?? 1.0) < 1.0)),
                'tutta_fuori'    => ! $esclusa && ($capitoli !== null ? ! empty($capitoli['tutta_fuori']) : (($baseDivisa[$chiave]['frazione_quota'] ?? 1.0) <= 0.0)),
                'motivi_quota'   => $esclusa ? [] : ($capitoli !== null
                    ? array_values(array_unique(array_filter(array_column($capitoli['conti'], 'motivo_quota'))))
                    : ($baseDivisa[$chiave]['motivi_quota'] ?? [])),
                // S8-3: la quota è di un predecessore di chi esce; la competenza gli è passata alla sua decorrenza.
                'ereditata_da'   => $r->ereditata_da ?? null,
                // Decisione 67 (1): la quota del predecessore intestata a un altro erede (l'erede di riferimento), che la tiene.
                'intestato_a'    => $r->intestato_a ?? null,
                'decorrenza_acquisto' => $acquistoDellaQuota['data'],
                // Le due date, quando le voci della quota occupano tutti e due i lati arrivati in giorni diversi.
                'acquisto_per_lato' => $acquistoDellaQuota['per_lato'],
                // Rilievo B3: l'ordinaria rimasta, con una vendita con riserva d'usufrutto, a chi è qui nominato.
                'riservata_da'   => $r->riservata_da ?? null,
                // R11: passata a chi esce con un passaggio precedente (decisione 25), da quel giorno.
                'passata_il'     => $r->passata_il ?? null,
                // Decisione 21: quota ancora in bozza di un piano che non si può più ricalcolare.
                'in_bozza'       => (bool) ($r->in_bozza ?? false),
                'rata_id'        => (int) $r->rata_id,
                'stato_rata'     => $r->stato_rata,
                'importo_pagato' => (int) $r->importo_pagato,
                'stato_quota'    => $r->stato_quota,
                'intestatario_id' => (int) $r->anagrafica_id,
                // B3a: la bozza passa a chi entra (decisione 25), e se no perché resta.
                'passa'          => false,
                'motivo_bozza'   => null,
            ];
        }

        // R17: una bozza con un pagamento segnalato dal portale e non ancora verificato non passa a chi entra.
        $segnalate = \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)
            ->where('meta->status', 'reported')
            ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $uscente->id))
            ->get()->map(fn ($e) => (int) ($e->meta['context']['rata_id'] ?? 0))->filter()->flip()->all();
        $quote = $this->decidiBozze($quote, $riassegnaBozze && $entrante !== null, $decorrenza, $segnalate, $soloStraordinario, $nudaProprieta, $tutteLeBozze);

        // Una coppia per (gestione, unità, esercizio del piano), solo se ≠ 0 (verifica S5, R9: due piani della
        // stessa gestione su esercizi diversi non si fondono su un esercizio solo). Dentro il gruppo i periodi
        // possono differire (piani con competenze diverse): i giorni e le frasi sono **per periodo** (R10).
        $nomiNudi = Anagrafica::whereIn('id', collect($pesiNudoGruppo)->flatMap(fn ($p) => array_keys($p))->unique()->values()->all())->pluck('nome', 'id');
        $perGestione = collect($quote)->groupBy(fn ($q) => $q['gestione_id'] . '|' . $q['immobile_id'] . '|' . ($q['esercizio_id'] ?? ''))->map(function (Collection $g) use ($uscente, $nomiNudi, $pesiNudoGruppo) {
            $prima = $g->first();
            // Decisione 56: all'estinzione, la coppia per nudo proprietario dai pesi della genealogia, se coprono ogni gruppo che passa
            // qualcosa a chi entra; altrimenti null, e la coppia si divide per quota come prima. E chi la riceve, per le frasi.
            $pesiNudo = [];
            $coperta = true;
            foreach ($g->groupBy('gruppo') as $k => $quoteDelGruppo) {
                if ((int) $quoteDelGruppo->sum('entrante') === 0) {
                    continue;
                }
                if (! isset($pesiNudoGruppo[$k])) {
                    $coperta = false;
                    break;
                }
                foreach ($pesiNudoGruppo[$k] as $chi => $w) {
                    $pesiNudo[(int) $chi] = ($pesiNudo[(int) $chi] ?? 0.0) + (float) $w;
                }
            }
            $importoCoppia = (int) $g->sum('entrante') - (int) $g->where('passa', true)->sum('quota_pura');
            $perNudo = $coperta && $pesiNudo !== [] && array_sum($pesiNudo) > 0 && $importoCoppia !== 0 ? array_map('intval', MoneyHelper::ripartisciPerQuote($importoCoppia, $pesiNudo)) : null;
            $riceve = $perNudo === null ? [] : array_values(array_filter(array_map(fn ($chi) => $nomiNudi[$chi] ?? null, array_keys(array_filter($perNudo)))));
            $perPeriodo = $g->filter(fn ($q) => ! $q['non_risolta'] && ! $q['esclusa'])
                ->groupBy(fn ($q) => json_encode([$q['periodo'], $q['per_capitolo'] === null ? null : $q['piano_rate_id'], $q['ereditata_da'], $q['decorrenza_acquisto'], $q['acquisto_per_lato'], $q['intestato_a']]))
                ->map(fn (Collection $p) => [
                    'periodo'        => $p->first()['periodo'],
                    'gradino'        => $p->first()['gradino'],
                    'quote'          => $p->count(),
                    'quota_pura'     => (int) $p->sum('quota_pura'),
                    'giorni_uscente' => $p->first()['giorni_uscente'],
                    'giorni_entrante' => $p->first()['giorni_entrante'],
                    'giorni_periodo' => $p->first()['giorni_periodo'],
                    'uscente'        => (int) $p->sum('uscente'),
                    'entrante'       => (int) $p->sum('entrante'),
                    'entrante_formattato' => MoneyHelper::format((int) $p->sum('entrante')),
                    // B3a: il preventivo delle bozze che passano a chi entra — lo paga con quelle, non con la coppia.
                    'passate'        => (int) $p->where('passa', true)->sum('quota_pura'),
                    'per_capitolo'   => $p->first()['per_capitolo'],
                    'ereditata_da'   => $p->first()['ereditata_da'],
                    'intestato_a'    => $p->first()['intestato_a'],
                    'decorrenza_acquisto' => $p->first()['decorrenza_acquisto'],
                    'acquisto_per_lato' => $p->first()['acquisto_per_lato'],
                    'passata_il'     => $p->first()['passata_il'],
                    // La scelta è la ragione solo se la competenza attraversa il passaggio; prima, la ragione resta la competenza.
                    'per_voce'       => $p->contains(fn ($q) => ! empty($q['resta_per_voce'])),
                    'quota_che_passa' => (int) $p->sum('quota_che_passa'),
                    // Fase 1-ter della beta.41: righe di un'altra quota, e perché (le frasi lo dicono).
                    'fuori_quota'    => $p->contains(fn ($q) => ! empty($q['fuori_quota'])),
                    'tutta_fuori'    => $p->every(fn ($q) => ! empty($q['tutta_fuori'])),
                    'motivi_quota'   => $p->pluck('motivi_quota')->flatten()->unique()->values()->all(),
                ])->values();
            $unico = $perPeriodo->count() === 1 ? $perPeriodo->first() : null;
            // Decisione 25 (B3a): la parte di chi entra è sull'intero piano; la coppia è quella parte meno il preventivo
            // delle bozze che da oggi sono sue. Calcolata sulle sole quote emesse gli farebbe pagare più dei suoi giorni.
            $lordo = (int) $g->sum('entrante');
            $passate = (int) $g->where('passa', true)->sum('quota_pura');

            return [
                'gestione_id'    => $prima['gestione_id'],
                'gestione'       => $prima['gestione'],
                'immobile_id'    => $prima['immobile_id'],
                'immobile_nome'  => $prima['immobile_nome'],
                'natura'         => $prima['natura'],
                'gradino'        => $g->pluck('gradino')->filter()->unique()->values()->all(),
                // Decisione 31.5: l'ordinaria di queste quote segue la voce (la scelta di questo passaggio o di uno prima).
                'per_voce'       => $g->contains(fn ($q) => ! empty($q['resta_per_voce'])),
                'quota_che_passa' => (int) $g->sum('quota_che_passa'),
                'fuori_quota'    => $perPeriodo->contains(fn ($p) => $p['fuori_quota']),
                'tutta_fuori'    => $perPeriodo->isNotEmpty() && $perPeriodo->every(fn ($p) => $p['tutta_fuori']),
                'motivi_quota'   => $perPeriodo->pluck('motivi_quota')->flatten()->unique()->values()->all(),
                // Voce per voce anche quando a mescolare sono due piani della stessa gestione (uno dichiarato, uno sulla
                // base): il gruppo non ha un periodo solo da mostrare (verifica delle correzioni, beta.35).
                'voce_per_voce'  => $g->contains(fn ($q) => $q['voce_per_voce'] ?? false)
                    || ($prima['natura'] !== NaturaGestione::Straordinaria->value
                        && in_array('dichiarata', $g->pluck('gradino')->filter()->all(), true)
                        && ($g->pluck('gradino')->filter()->unique()->count() > 1 || $perPeriodo->count() > 1)),
                // Periodo e giorni a livello di gruppo solo quando il periodo è uno; altrimenti `per_periodo`.
                'periodo'        => $unico['periodo'] ?? null,
                'quote'          => $g->count(),
                'quota_pura'     => (int) $g->sum('quota_pura'),
                'pregressi'      => (int) $g->sum('pregresso'),
                'giorni_uscente' => $unico['giorni_uscente'] ?? null,
                'giorni_entrante' => $unico['giorni_entrante'] ?? null,
                'giorni_periodo' => $unico['giorni_periodo'] ?? null,
                'per_periodo'    => $perPeriodo->all(),
                'importo'        => $lordo - $passate,
                'importo_formattato' => MoneyHelper::format($lordo - $passate),
                'importo_lordo'  => $lordo,
                'importo_lordo_formattato' => MoneyHelper::format($lordo),
                'bozze_passate'  => $g->where('passa', true)->count(),
                'bozze_passate_importo' => $passate,
                'bozze_passate_formattato' => MoneyHelper::format($passate),
                'non_risolte'    => $g->where('non_risolta', true)->count(),
                // Fra le non risolte, quelle che il conguaglio ferma per un'altra ragione che la competenza: le quote della 1.7.x senza
                // composizione, e le catene in cui la parte che passa non si separa con certezza (l'etichetta a video lo dice).
                'non_separabili' => $g->filter(fn ($q) => ! empty($q['senza_istantanea']) || ! empty($q['catena_ambigua']))->count(),
                // Decisione 35, rilievo R8 della Fase 1-bis della .42: quote che il passaggio di prima non ha fatto passare. Non sono
                // «non separabili»: non sono mai arrivate a chi esce.
                'mai_passate'    => $g->filter(fn ($q) => ! empty($q['mai_passata']))->count(),
                'escluse'        => $g->where('esclusa', true)->count(),
                // Testi T3 (beta.38): a chi sono intestate davvero le quote escluse — chi esce o un suo predecessore — e, per
                // l'ordinaria rimasta con una riserva d'usufrutto (rilievo B3), chi l'ha tenuta.
                'escluse_di'     => $g->where('esclusa', true)->map(fn ($q) => $q['intestato_a'] ?? $q['ereditata_da'] ?? $uscente->nome)->unique()->values()->all(),
                'riservata_da'   => $g->where('esclusa', true)->pluck('riservata_da')->filter()->unique()->values()->all(),
                'esercizio_id'   => $prima['esercizio_id'],
                'per_nudo'       => $perNudo,
                // Chi riceve la parte di chi entra, quando la genealogia la divide per nudo: le frasi nominano lui, non tutti i nudi.
                'entrante_nome'  => $riceve === [] ? null : (count($riceve) === 1 ? $riceve[0] : implode(', ', array_slice($riceve, 0, -1)) . ' e ' . end($riceve)),
            ];
        })->values();

        $coppie = $perGestione->filter(fn ($g) => $g['importo'] !== 0)->map(fn ($g) => [
            'gestione_id'  => $g['gestione_id'],
            'gestione'     => $g['gestione'],
            'immobile_id'  => $g['immobile_id'],
            'esercizio_id' => $g['esercizio_id'],
            'importo'      => $g['importo'],
            'per_nudo'     => $g['per_nudo'],
        ])->values()->all();

        $totale = (int) array_sum(array_column($coppie, 'importo'));

        // Decisione 21: le quote in bozza che restano a chi le ha, comprese nel conguaglio, per piano, intestatario e
        // ragione (con la catena dei passaggi le bozze possono essere del predecessore, non di chi esce: la frase deve
        // dire a chi resteranno intestate; con la B3a dice anche perché non passano).
        $inBozza = collect($quote)->where('in_bozza', true)->where('passa', false)
            ->groupBy(fn ($q) => $q['piano'] . '|' . ($q['intestato_a'] ?? $q['ereditata_da'] ?? $uscente->nome) . '|' . $q['motivo_bozza'])
            ->map(fn (Collection $g) => ['piano' => $g->first()['piano'], 'intestatario' => $g->first()['intestato_a'] ?? $g->first()['ereditata_da'] ?? $uscente->nome, 'n' => $g->count(), 'motivo' => $g->first()['motivo_bozza']]
                // Decisione 56: la parte che non passa è una che chi esce ha già ceduto, non una che tiene (le frasi lo dicono).
                + ($g->every(fn ($q) => ($q['motivi_quota'] ?? []) !== [] && array_filter($q['motivi_quota'], fn ($m) => ! str_starts_with((string) $m, 'ceduta:')) === []) ? ['ceduta' => true] : [])
                // Rilievo GT2: una parte già ceduta da chi esce accanto ad altre ragioni; la frase non dice «mai passata».
                + ($g->contains(fn ($q) => collect($q['motivi_quota'] ?? [])->contains(fn ($m) => str_starts_with((string) $m, 'ceduta:'))) ? ['ceduta_in_parte' => true] : [])
                // Rilievo X3 della Fase 1-bis della .44: le bozze di chi esce con la spesa di una parte che a chi esce non è mai arrivata (la
                // parte degli altri eredi, sulle bozze dell'erede di riferimento): non è una parte che «tiene».
                + ($g->every(fn ($q) => ($q['motivi_quota'] ?? []) !== [] && array_filter($q['motivi_quota'], fn ($m) => ! str_starts_with((string) $m, 'mai_arrivata:')) === [])
                    // Giro sulle correzioni (GC6): con più motivi, la frase che li comprende.
                    ? ['mai_arrivata' => ($m = $g->flatMap(fn ($q) => $q['motivi_quota'])->unique()->values())->count() === 1
                        ? self::parteInParole((string) $m[0]) : self::parteInParole('mai_arrivata:altri_titolari')] : [])
                // Rilievo B3: chi resta usufruttuario — chi vende nella riserva, chi ha venduto con riserva in una catena
                // (le quote possono essere di chi gli aveva venduto prima).
                + ($g->first()['motivo_bozza'] === 'ordinaria_riservata' ? ['usufruttuario' => $g->first()['riservata_da'] ?? $uscente->nome] : []))
            ->values()->all();

        // Decisione 25 (B3a): le bozze che passano a chi entra — ciò che `RegistraSubentroAction` riscrive, quota per
        // quota — e il loro riepilogo per piano, per le frasi e per il cancello.
        $passano = collect($quote)->where('passa', true)->values();
        $bozzeRiassegnate = $passano->map(fn ($q) => [
            'rata_quote_id' => $q['rata_quote_id'], 'rata_id' => $q['rata_id'], 'piano_rate_id' => $q['piano_rate_id'], 'piano' => $q['piano'],
            'immobile_id' => $q['immobile_id'], 'rata' => $q['rata'], 'scadenza' => $q['scadenza'],
            'importo' => $q['importo'], 'quota_pura' => $q['quota_pura'], 'pregresso' => $q['pregresso'],
        ])->all();
        $riassegnazione = $passano->groupBy('piano_rate_id')->map(fn (Collection $p) => [
            'piano_rate_id' => $p->first()['piano_rate_id'],
            'piano'      => $p->first()['piano'],
            'n'          => $p->pluck('rata_id')->unique()->count(),
            'quote'      => $p->count(),
            'dal'        => $p->min('scadenza'),
            'al'         => $p->max('scadenza'),
            'preventivo' => (int) $p->sum('quota_pura'),
            'preventivo_formattato' => MoneyHelper::format((int) $p->sum('quota_pura')),
            'pregresso'  => (int) $p->sum('pregresso'),
        ])->values()->all();

        return [
            'stato'          => 'calcolato',
            'anagrafica_uscente_id'  => (int) $uscente->id,
            'anagrafica_entrante_id' => $entrante?->id,
            'quote'          => $quote,
            'quote_in_bozza' => $inBozza,
            'bozze_riassegnate' => $bozzeRiassegnate,
            'riassegnazione' => $riassegnazione,
            'per_gestione'   => $perGestione->all(),
            'coppie'         => $coppie,
            'totale_entrante' => $totale,
            'totale_entrante_formattato' => MoneyHelper::format($totale),
            // Con le bozze che passano la coppia può rovesciarsi (decisione 25): il verso lo dice il segno, la cifra è questa.
            'totale_entrante_assoluto_formattato' => MoneyHelper::format(abs($totale)),
            'pregressi'      => (int) collect($quote)->sum('pregresso'),
            'non_risolte'    => $nonRisolte,
            'esercizi_dedotti' => $dedotti,
            'frasi'          => $this->perGliEredi($this->frasi($perGestione, $nonRisolte, $uscente->nome, $entrante?->nome, $decorrenza, $soloOrdinario, $soloStraordinario, collect($quote)->where('passa', false)->groupBy(fn ($q) => $q['ereditata_da'] ?? $uscente->nome)->map(fn ($g) => (int) $g->sum('pregresso'))->filter()->all(), $dedotti, $inBozza, $riassegnazione, $entrantiPerUnita)),
        ];
    }

    /**
     * Decisione 25 (B3a, 1.11.0-beta.34): quali bozze di un piano già a giornale passano a chi entra, e perché le
     * altre restano. È la prassi degli amministratori (forum p=552): «si cambia il nome sulle rate che restano, senza
     * rifare il riparto; il passato si regola con il conguaglio». Una bozza passa se:
     *
     * - il passaggio è una **vendita** (`$riassegna`): locazione e usufrutto restano alla decisione 21, perché lì chi
     *   entra paga solo una parte delle voci e cambiare il nome sulla quota intera sposterebbe anche il resto;
     * - è **di chi esce** — quella di un predecessore resta sua (decisione 21, S8-3);
     * - **scade dalla decorrenza in poi**: una rata che scade prima del rogito era da pagare quando l'unità era sua;
     * - **nessuno l'ha pagata**, nemmeno in parte, né ha segnalato dal portale di averla pagata: un anticipo di chi esce non
     *   diventa di chi entra;
     * - ha un **preventivo** (quota pura ≠ 0): una quota di soli saldi pregressi è tutta di chi esce;
     * - il piano è **risolto** (competenza nota) e, se è **straordinario**, la spesa è **tutta di chi entra**
     *   (delibera, o competenza dichiarata, dalla decorrenza in poi): una straordinaria di chi esce resta sua, una
     *   divisa per competenza resta a chi esce e si conguaglia come prima. Dalla decisione 26 vale lo stesso
     *   sull'ordinario quando una voce porta la competenza dichiarata sulla fattura (`fattura_di_chi_esce`,
     *   `fattura_divisa`): la pregressa del 2025 non passa a chi compra per tornare indietro nella coppia;
     * - **non è esclusa per legge** (R4, Fase 1-bis): nella vendita della nuda proprietà le ordinarie di un piano generato
     *   prima dell'usufrutto sono dell'usufruttuario (art. 1004 c.c.) e restano fuori, come le straordinarie
     *   nell'usufrutto (art. 1005 c.c.); e le ordinarie che una vendita con riserva d'usufrutto ha lasciato a chi vendeva,
     *   nella riserva stessa (decisione 28) e dopo, quando si rivende la nuda proprietà (rilievo B3 della beta.38).
     *
     * Il saldo pregresso dentro una bozza che passa **non passa**: `RegistraSubentroAction` lo lascia a chi esce in una
     * quota sua sulla stessa rata (decisione 25, punto 3). Vale con i tre metodi di distribuzione dei saldi: rata zero
     * (la rata 0 è di soli pregressi e resta), prima rata, spalmati.
     *
     * Decisione 65 (1.11.0-beta.44): nella successione con l'arretrato agli eredi (`$tutteLeBozze`) passano all'erede di riferimento
     * anche le bozze del defunto che scadono prima del decesso o che sono una spesa sua (straordinaria o fattura di sua competenza,
     * anche divisa): un defunto non riceve emissioni, e l'arretrato rimette ciascun erede alla sua quota di tutto ciò che il defunto
     * ha lasciato aperto. Restano dove sono quelle che non sono del defunto (un predecessore, la legge) e quelle pagate o segnalate.
     *
     * @param list<array<string,mixed>> $quote
     * @return list<array<string,mixed>>
     */
    private function decidiBozze(array $quote, bool $riassegna, CarbonImmutable $decorrenza, array $segnalate = [], bool $riserva = false, bool $nudaProprieta = false, bool $tutteLeBozze = false): array
    {
        $gruppi = collect($quote)->groupBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['immobile_id'] . '|' . $q['intestatario_id']);
        $giorno = $decorrenza->toDateString();
        // R8: «divisa» si decide dai giorni, non dal segno di `uscente` — con il già versato della persona l'uscente di
        // ogni quota può essere negativo anche su una spesa divisa per competenza. Una voce non risolta non è «tutta di
        // chi entra».
        $divisa = fn ($x) => $x['per_capitolo'] !== null
            ? collect($x['per_capitolo'])->contains(fn ($c) => ! empty($c['non_risolta']) || (int) ($c['giorni_uscente'] ?? 0) > 0)
            : (int) ($x['giorni_uscente'] ?? 0) > 0;

        foreach ($quote as $i => $q) {
            if (! $q['in_bozza']) {
                continue;
            }
            $gruppo = $gruppi[$q['piano_rate_id'] . '|' . $q['immobile_id'] . '|' . $q['intestatario_id']];
            $straordinaria = $q['natura'] === NaturaGestione::Straordinaria->value;
            // Decisione 26 (1.11.0-beta.35): sull'ordinaria, una voce del piano da fatture con la competenza dichiarata
            // sulla fattura segue la regola della straordinaria — passa solo se la spesa è tutta di chi entra. Altrimenti
            // la bozza andrebbe a chi entra e tornerebbe indietro nella coppia (decisione 25, punto 2).
            $perFattura = ! $straordinaria && $gruppo->contains(fn ($x) => collect($x['per_capitolo'] ?? [])->contains(fn ($c) => ($c['gradino'] ?? null) === 'dichiarata'));
            // R12: l'ordine conta — una quota di soli pregressi o esclusa per legge ha la sua ragione prima di tutte le altre.
            $motivo = match (true) {
                (int) $q['quota_pura'] === 0 => 'solo_pregresso',
                // Decisione 48: le quote del predecessore mai arrivate a chi esce dicono questo, anche quando la legge lascerebbe
                // comunque quella natura a chi esce: la ragione di legge non spiega perché le quote sono ancora del predecessore.
                ! empty($q['mai_passata']) => 'mai_passata',
                // Riserva d'usufrutto (decisione 28): l'ordinaria resta a chi vende, che la deve come usufruttuario — nella
                // riserva stessa e quando la nuda proprietà di chi esce viene da una riserva (rilievo B3).
                $q['esclusa'] => $straordinaria ? 'straordinaria_del_nudo' : ($riserva || $q['riservata_da'] !== null ? 'ordinaria_riservata' : 'ordinaria_dell_usufruttuario'),
                // Una quota senza composizione con un saldo pregresso assorbito: non si sa quanta parte passerebbe.
                ! empty($q['senza_istantanea']) => 'senza_istantanea',
                ! empty($q['catena_ambigua']) => 'catena_ambigua',
                // Fase 1-ter della beta.41: una bozza con righe di un'altra quota — dell'usufrutto che chi vende tiene, o, nella
                // vendita della nuda proprietà, l'ordinaria dell'usufruttuario (R4 riga per riga) — non passa: resta a chi la ha,
                // e la parte che passa si conguaglia qui. Con le sole righe di un'altra quota non passa niente, in qualunque
                // passaggio: la bozza resta a chi la ha e il conguaglio non la tocca (non «si conguaglia qui»).
                // Rilievo T-B3 della revisione della 1-ter: il motivo dell'art. 1004 solo se la quota è fuori per l'usufrutto, non
                // perché chi vende la nuda tiene anche la piena proprietà di un'altra parte.
                ! empty($q['tutta_fuori']) => $nudaProprieta && ! $straordinaria && ($q['motivi_quota'] ?? []) === ['usufruttuario'] ? 'ordinaria_dell_usufruttuario' : 'altra_quota',
                $riassegna && ! empty($q['fuori_quota']) => 'quota_di_piu_ruoli',
                // Decisione 31.5: con «come la voce» l'ordinaria si divide voce per voce, e una bozza non si divide: resta a chi
                // la ha, e la parte delle voci che passano va nel conguaglio, come per le rate emesse.
                $riassegna && ! $straordinaria && ! empty($q['per_voce']) => 'ordinaria_per_voce',
                ! $riassegna => 'passaggio',
                ! empty($q['ereditata_da']) => 'predecessore',
                $gruppo->contains(fn ($x) => $x['non_risolta']) => 'non_risolta',
                $straordinaria && (int) $gruppo->sum('entrante') === 0 => 'straordinaria_di_chi_esce',
                $straordinaria && $gruppo->contains($divisa) => 'straordinaria_divisa',
                $perFattura && (int) $gruppo->sum('entrante') === 0 => 'fattura_di_chi_esce',
                $perFattura && $gruppo->contains($divisa) => 'fattura_divisa',
                $q['stato_rata'] !== 'bozza' => 'passaggio',
                $q['scadenza'] < $giorno => 'scade_prima',
                (int) $q['importo_pagato'] !== 0 || ! in_array($q['stato_quota'], ['da_pagare', 'credito'], true) => 'pagata',
                isset($segnalate[(int) $q['rata_id']]) => 'segnalata',
                default => null,
            };
            if ($tutteLeBozze && in_array($motivo, ['straordinaria_di_chi_esce', 'straordinaria_divisa', 'fattura_di_chi_esce', 'fattura_divisa', 'scade_prima'], true)) {
                // Rilievo X7 della Fase 1-bis: quei motivi stanno nel match prima di «pagata» e «segnalata», che si rivalutano qui. Una bozza
                // con un versamento o un pagamento segnalato dal portale resta dove è anche con l'arretrato agli eredi (R17).
                $motivo = match (true) {
                    (int) $q['importo_pagato'] !== 0 || ! in_array($q['stato_quota'], ['da_pagare', 'credito'], true) => 'pagata',
                    isset($segnalate[(int) $q['rata_id']]) => 'segnalata',
                    default => null,
                };
            }
            $quote[$i]['passa'] = $motivo === null;
            $quote[$i]['motivo_bozza'] = $motivo;
        }

        return $quote;
    }

    /**
     * Le chiavi (piano, unità, intestatario) delle quote senza composizione — `regole_calcolo` senza
     * `importi.quota_pura_gestione`, come le generava la 1.7.x — su cui il piano ha assorbito un saldo pregresso dell'unità o
     * della persona. Lì il saldo è dentro l'importo e non si separa.
     *
     * Il saldo assorbito si riconosce in due modi. Con `saldi.piano_rate_id` uguale al piano, quando c'è. Ma i saldi della
     * 1.7.x non lo hanno: la colonna nasce vuota con la beta.32, e la riparazione dei lucchetti orfani la riempie leggendo la
     * composizione delle quote, proprio ciò che a queste manca (revisione della Fase 1-ter, F3-1). Per loro vale il saldo
     * senza piano dello stesso esercizio del piano, scritto prima delle sue quote: la 1.7.0 prendeva i saldi «manuali» per
     * condominio ed esercizio. Mai le coppie dei passaggi né i debiti verso i fornitori. La persona è chi ha la quota oggi o chi
     * la aveva quando è stata generata (`righe_di`, una bozza passata di mano con un passaggio).
     *
     * @param array<int, ?int> $eserciziPerPiano
     * @return array<string, true>
     */
    private function senzaComposizione(Collection $righe, \Closure $chiaveDi, array $eserciziPerPiano): array
    {
        $senza = $righe->filter(fn ($r) => (((is_string($r->regole_calcolo) ? json_decode($r->regole_calcolo, true) : (array) $r->regole_calcolo)['importi']['quota_pura_gestione']) ?? null) === null);
        if ($senza->isEmpty()) {
            return [];
        }
        $piani = $senza->pluck('piano_rate_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $esercizi = array_values(array_filter(array_map(fn ($id) => $eserciziPerPiano[$id] ?? null, $piani)));
        $nate = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->whereIn('rate.piano_rate_id', $piani)
            ->groupBy('rate.piano_rate_id')->selectRaw('rate.piano_rate_id, MIN(rate_quote.created_at) as nate')->pluck('nate', 'piano_rate_id');
        $saldi = DB::table('saldi')->whereNull('subentro_id')->whereNull('fornitore_id')->where('saldo_iniziale', '!=', 0)
            ->where(fn ($q) => $q->whereIn('piano_rate_id', $piani)->orWhere(fn ($q2) => $q2->whereNull('piano_rate_id')->whereIn('esercizio_id', $esercizi)))
            ->get(['piano_rate_id', 'esercizio_id', 'immobile_id', 'anagrafica_id', 'created_at']);
        $chiavi = [];
        foreach ($senza as $r) {
            $piano = (int) $r->piano_rate_id;
            $persone = array_unique([(int) $r->anagrafica_id, (int) ($r->righe_di ?? $r->anagrafica_id)]);
            $conSaldo = $saldi->contains(fn ($x) => ($x->piano_rate_id !== null
                    ? (int) $x->piano_rate_id === $piano
                    : (int) $x->esercizio_id === (int) ($eserciziPerPiano[$piano] ?? 0) && ($nate[$piano] ?? null) !== null && (string) $x->created_at <= (string) $nate[$piano])
                && ($x->immobile_id !== null || $x->anagrafica_id !== null)
                && ($x->immobile_id === null || (int) $x->immobile_id === (int) $r->immobile_id)
                && ($x->anagrafica_id === null || in_array((int) $x->anagrafica_id, $persone, true)));
            if ($conSaldo) {
                $chiavi[$chiaveDi($r)] = true;
            }
        }

        return $chiavi;
    }

    /**
     * Decisioni 55 e 56 (1.11.0-beta.43): la genealogia, gruppo per gruppo (piano, unità, intestatario, righe di): `deciso`, con le
     * parti di ogni riga di riparto lorda del gruppo (`GenealogiaDellaQuota`), o `indecidibile`, con la ragione della prima riga che
     * ferma il gruppo (`ferma`, decisione 63), altrimenti della prima che non si decide. Niente per un gruppo senza righe di riparto
     * (piano anteriore alla beta.29, o ricostruito: lì le regole del ruolo e le fermate di prima), né se il passaggio non dice la
     * riga di chi esce su quell'unità.
     *
     * @param array<int, int> $righeUscenti unità → riga di chi esce
     * @param array<int, list<array{id: int, anagrafica_id: int, quota: float}>> $nudiOra unità → i nudi che tornano pieni adesso
     * @return array<string, array{stato: string, parti?: array<int, list<array<string, mixed>>>, ragione?: string, ferma?: bool}>
     */
    private function legame(Anagrafica $uscente, array $immobileIds, CarbonImmutable $decorrenza, Collection $righe, \Closure $chiaveDi, Collection $piani, array $esiti, ?string $passaggio, array $righeUscenti, array $nudiOra): array
    {
        if ($passaggio === null || $righeUscenti === []) {
            return [];
        }
        $gruppi = $righe->groupBy($chiaveDi);
        $persone = $gruppi->map(fn ($g) => (int) $g->first()->righe_di)->unique()->values()->all();
        $riparto = DB::table('righe_riparto')->whereIn('piano_rate_id', $piani->keys()->all())->whereIn('immobile_id', $immobileIds)
            ->whereIn('anagrafica_id', $persone)->whereIn('tipo', [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_AD_PERSONAM])
            ->get(['id', 'piano_rate_id', 'tipo', 'anagrafica_id', 'immobile_id', 'anagrafica_immobile_id', 'conto_id', 'tabella_id', 'ruolo_richiesto', 'ruolo_risolto', 'quota_possesso',
                'riga_fattura_id', 'competenza_dal', 'competenza_al', 'titolarita_dal', 'titolarita_al', 'created_at']);
        if ($riparto->isEmpty()) {
            return [];
        }
        // La prima quota di ogni piano: un passaggio registrato quando c'era già è venuto dopo la generazione.
        $primeQuote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->whereIn('rate.piano_rate_id', $riparto->pluck('piano_rate_id')->unique()->values()->all())
            ->groupBy('rate.piano_rate_id')->selectRaw('rate.piano_rate_id as piano, MIN(rate_quote.id) as prima')->pluck('prima', 'piano');
        $infoPiani = [];
        foreach ($piani as $piano) {
            $infoPiani[(int) $piano->id] = [
                'prima_quota' => (int) ($primeQuote[$piano->id] ?? 0),
                'dal' => (($esiti[$piano->id] ?? null)?->periodi?->dal() ?? $decorrenza->startOfYear())->toDateString(),
                'natura' => NaturaGestione::daStringa($piano->gestione?->tipo)->value,
            ];
        }
        $perRiga = (new GenealogiaDellaQuota())->calcola((int) $uscente->id, $immobileIds, $righeUscenti, $decorrenza, $passaggio, $nudiOra, $riparto, $infoPiani);

        $legame = [];
        foreach ($gruppi as $chiave => $gruppo) {
            $primo = $gruppo->first();
            $sue = $riparto->filter(fn ($r) => (int) $r->piano_rate_id === (int) $primo->piano_rate_id && (int) $r->immobile_id === (int) $primo->immobile_id
                && (int) $r->anagrafica_id === (int) $primo->righe_di);
            if ($sue->isEmpty() || ! isset($righeUscenti[(int) $primo->immobile_id])) {
                continue;
            }
            $parti = [];
            $indecidibile = null;
            foreach ($sue as $r) {
                $esito = $perRiga[(int) $r->id] ?? null;
                if ($esito === null || isset($esito['indecidibile'])) {
                    // Decisione 63: la fermata della genealogia vale per il gruppo da qualunque sua riga venga, non solo dalla prima
                    // riga indecidibile (dal quarto giro della .43 vale anche per chi esce, e lì le regole del ruolo sbaglierebbero).
                    if ($indecidibile === null || (! empty($esito['ferma']) && ! $indecidibile['ferma'])) {
                        $indecidibile = ['stato' => 'indecidibile', 'ragione' => $esito['indecidibile'] ?? 'la riga di chi esce su questa unità non si trova', 'ferma' => ! empty($esito['ferma'])];
                    }
                    continue;
                }
                $parti[(int) $r->id] = $esito['parti'];
            }
            $legame[$chiave] = $indecidibile ?? ['stato' => 'deciso', 'parti' => $parti];
        }

        return $legame;
    }

    /**
     * Fase 1-ter della beta.41 (decisione di Vincenzo del 03/10/2026): le unità su cui una persona della catena — chi esce, o un
     * predecessore — ha avuto più quote nel periodo dei piani, con la ragione in parole. La regola delle righe di un'altra
     * quota riconosce la quota di una riga di riparto dal suo ruolo risolto; nelle catene lo stesso ruolo può indicare due quote
     * diverse, e il conguaglio sbagliava in silenzio (le sette forme della Fase 1-bis mirata: un ruolo ceduto per un'altra
     * strada, la nuda proprietà avuta due volte, la metà di una vedova ricomprata da un fratello, un ritorno, la riserva su
     * un'unità mista e poi l'estinzione). Nella .41 e nella .42 lì il conguaglio si fermava e lo diceva. Dalla 1.11.0-beta.43
     * (decisione 56) è la guardia del ripiego: si ferma solo il gruppo in cui la genealogia della quota non arriva (un piano
     * senza righe di riparto, una riga che non dice da dove viene e non si deduce, una riga cambiata senza un passaggio), con la
     * ragione della genealogia. Le unità segnate sono quelle in cui la persona, nel periodo:
     * - è un predecessore raggiunto per più strade, e una porta solo una parte della sua quota (l'estinzione con più nudi
     *   proprietari, poi un nudo compra la metà dell'altro);
     * - ha lasciato l'unità con un passaggio e l'ha riavuta comprandola con un altro (l'estinzione non conta come acquisto: per
     *   il nudo proprietario è la stessa quota che torna piena);
     * - aveva più quote insieme e le ha cedute con passaggi diversi;
     * - ha avuto lo stesso ruolo in due periodi separati, e il piano ha righe di quel ruolo a suo nome;
     * - chiude un usufrutto con più nudi proprietari mentre aveva anche un'altra quota.
     * Le forme verificate giuste restano fuori: l'unità mista diretta, l'estinzione con un nudo, la vedova con due figli, il
     * ruolo ceduto una volta sola, la costituzione e poi la fine dello stesso usufrutto.
     *
     * @param array<int, mixed> $esiti
     * @param array<int, array<string, mixed>> $predecessori
     * @return array<int, string> unità → la ragione
     */
    private function catenaAmbigua(Anagrafica $uscente, array $immobileIds, CarbonImmutable $decorrenza, Collection $righe, array $esiti, array $predecessori, bool $piuNudi, array $ruoliCheRestano = []): array
    {
        $inizio = collect($esiti)->filter()->map(fn ($e) => $e->periodi?->dal())->filter()->min() ?? $decorrenza->startOfYear();
        $persone = array_values(array_unique([(int) $uscente->id, ...array_map('intval', array_keys($predecessori))]));
        $passaggi = Subentro::whereIn('immobile_id', $immobileIds)
            ->whereDate('decorrenza', '>=', $inizio->toDateString())->whereDate('decorrenza', '<', $decorrenza->toDateString())
            ->orderBy('decorrenza')->orderBy('id')->get();
        $titolarita = DB::table('anagrafica_immobile')->whereIn('immobile_id', $immobileIds)->whereIn('anagrafica_id', $persone)->where('quota', '>', 0)
            ->get(['id', 'immobile_id', 'anagrafica_id', 'tipologia', 'data_inizio', 'data_fine']);
        $congelati = DB::table('righe_riparto')->whereIn('piano_rate_id', $righe->pluck('piano_rate_id')->unique()->values())->whereIn('immobile_id', $immobileIds)
            ->whereIn('anagrafica_id', $persone)->where('tipo', RigaRiparto::TIPO_RIPARTO)->distinct()->get(['immobile_id', 'anagrafica_id', 'ruolo_risolto']);
        $nomi = Anagrafica::whereIn('id', $persone)->pluck('nome', 'id');
        // Le righe aperte dagli acquisti del periodo, e se una riga è in vigore in un giorno.
        $righeEntrate = DB::table('anagrafica_immobile')->whereIn('id', $passaggi->flatMap(fn (Subentro $s) => [$s->riga_entrante_id, ...array_column($s->destinatari(), 'riga_id')])->filter()->unique()->values()->all())
            ->get(['id', 'tipologia', 'data_inizio', 'data_fine'])->keyBy('id');
        $inVigore = fn (?object $t, string $g) => $t !== null && ($t->data_inizio === null || substr((string) $t->data_inizio, 0, 10) <= $g)
            && ($t->data_fine === null || substr((string) $t->data_fine, 0, 10) >= $g);
        $primoGiorno = $inizio->toDateString();
        $ultimoGiorno = $decorrenza->subDay()->toDateString();
        // Il tratto di una riga dentro il periodo, o null se non lo tocca.
        $tratto = function (object $t) use ($primoGiorno, $ultimoGiorno): ?array {
            $dal = max($t->data_inizio !== null ? substr((string) $t->data_inizio, 0, 10) : '0000-01-01', $primoGiorno);
            $al = min($t->data_fine !== null ? substr((string) $t->data_fine, 0, 10) : '9999-12-31', $ultimoGiorno);

            return $dal <= $al ? [$dal, $al] : null;
        };
        $ambigue = [];
        foreach ($immobileIds as $immobileId) {
            $qui = $passaggi->where('immobile_id', $immobileId);
            foreach ($persone as $x) {
                $nome = (string) ($nomi[$x] ?? 'una persona della catena');
                $uscite = $qui->filter(fn ($s) => (int) $s->anagrafica_uscente_id === $x)->values();
                // Gli acquisti di una quota d'altri (vendita, nuda proprietà, riserva, costituzione, locazione, successione: ogni
                // erede, non solo quello che il passaggio nomina). L'estinzione no: per il nudo proprietario è la stessa quota, che
                // da nuda torna piena; ma con l'accrescimento l'usufruttuario che resta riceve l'usufrutto di un altro.
                $acquisti = $qui->filter(fn (Subentro $s) => in_array($x, $s->entranti(), true)
                    && (! $s->estinzioneUsufrutto() || $s->conAccrescimento()))->values();
                $strade = $predecessori[$x]['strade'][(int) $immobileId] ?? [];
                $sue = $titolarita->where('immobile_id', $immobileId)->where('anagrafica_id', $x)->map(fn ($t) => [$t, $tratto($t)])->filter(fn ($p) => $p[1] !== null)->values();
                $insieme = $sue->contains(fn ($a) => $sue->contains(fn ($b) => $a[0]->id !== $b[0]->id && $a[1][0] <= $b[1][1] && $b[1][0] <= $a[1][1]));
                $ruoloDueVolte = $congelati->where('immobile_id', $immobileId)->where('anagrafica_id', $x)->pluck('ruolo_risolto')->filter()->unique()
                    ->contains(function ($ruolo) use ($sue, $qui, $x) {
                        $periodi = $sue->filter(fn ($p) => $p[0]->tipologia === $ruolo)->map(fn ($p) => $p[1])->sortBy(fn ($p) => $p[0])->values();
                        for ($i = 1; $i < $periodi->count(); $i++) {
                            $riaperto = CarbonImmutable::parse($periodi[$i - 1][1])->addDay()->toDateString();
                            if ($riaperto < $periodi[$i][0] && ! ($ruolo === 'proprietario' && $this->stessoUsufruttoChiuso($qui, $x, $riaperto, $periodi[$i][0]))) {
                                return true;
                            }
                        }

                        return false;
                    });
                // Seconda revisione della Fase 1-ter (M2-1): una quota arrivata con un acquisto del periodo, che la persona tiene ancora quando cede un'altra sua quota
                // (chi esce: alla decorrenza, con un ruolo che resta; un predecessore: all'uscita verso la catena), mentre il
                // conguaglio porta righe di riparto non generate a suo nome. Il ruolo della riga non dice di quale quota è.
                $altrui = $righe->contains(fn ($q) => (int) $q->immobile_id === (int) $immobileId && (int) $q->righe_di !== $x);
                $tieneLAcquisto = $altrui && $acquisti->contains(function ($e) use ($x, $uscente, $uscite, $righeEntrate, $inVigore, $ruoliCheRestano, $immobileId, $decorrenza) {
                    $r = $righeEntrate[(int) $e->rigaEntranteDi($x)] ?? null;
                    if ($r === null) {
                        return false;
                    }
                    if ($x === (int) $uscente->id && $inVigore($r, $decorrenza->toDateString()) && in_array($r->tipologia, $ruoliCheRestano[(int) $immobileId] ?? [], true)) {
                        return true;
                    }

                    return $uscite->contains(fn ($u) => $u->decorrenza->gt($e->decorrenza) && (int) $u->riga_uscente_id !== (int) $r->id && $inVigore($r, $u->decorrenza->toDateString()));
                });
                $ragione = match (true) {
                    count($strade) >= 2 && in_array(true, $strade, true) => sprintf('le quote di %s arrivano per più strade, e una ne porta solo una parte', $nome),
                    $uscite->contains(fn ($u) => $acquisti->contains(fn ($e) => $e->decorrenza->gt($u->decorrenza))) => sprintf('%s ha ceduto una quota dell\'unità con un passaggio e ne ha avuta un\'altra con un passaggio successivo', $nome),
                    $insieme && $uscite->count() >= 2 => sprintf('%s aveva più quote insieme e le ha cedute con passaggi diversi', $nome),
                    $insieme && $tieneLAcquisto => sprintf('%s ha comprato una quota nel periodo e la tiene ancora mentre ne cede un\'altra', $nome),
                    $ruoloDueVolte => sprintf('%s ha avuto lo stesso ruolo in due periodi separati, con quote diverse', $nome),
                    $x === (int) $uscente->id && $insieme && $piuNudi => sprintf('l\'usufrutto di %s non era la sua sola quota, e i nudi proprietari sono più di uno: non si sa a chi torna ogni parte', $nome),
                    default => null,
                };
                if ($ragione !== null) {
                    $ambigue[(int) $immobileId] = $ragione;
                    break;
                }
            }
        }

        return $ambigue;
    }

    /**
     * Seconda revisione della Fase 1-ter (M2-2): la piena proprietà che torna a chi ha costituito l'usufrutto quando quello
     * stesso usufrutto si estingue è la stessa quota (per il nudo proprietario la nuda torna piena), non una seconda: il
     * ruolo «Proprietario» in due periodi separati da quel tratto non è ambiguo. Prima la catena «costituzione, fine dello
     * stesso usufrutto, vendita» si fermava senza ragione.
     */
    private function stessoUsufruttoChiuso(Collection $passaggi, int $x, string $costituito, string $tornata): bool
    {
        return $passaggi->contains(fn ($c) => (int) $c->anagrafica_uscente_id === $x && $c->tipo_passaggio === 'usufrutto' && ! $c->estinzioneUsufrutto()
            && $c->decorrenza->toDateString() === $costituito
            && $passaggi->contains(fn ($e) => $e->estinzioneUsufrutto() && (int) $e->anagrafica_uscente_id === (int) $c->anagrafica_entrante_id && $e->decorrenza->toDateString() === $tornata));
    }

    /**
     * L'unità è mista in qualche giorno fra `$dal` e il giorno prima di `$decorrenza`: un proprietario pieno in vigore insieme a
     * un nudo proprietario o a un usufruttuario (la condizione 1 di `UnitaMista`). Un passaggio di consegne, in cui una riga
     * finisce quando l'altra comincia, non la rende mista.
     */
    private function unitaMista(int $immobileId, CarbonImmutable $dal, CarbonImmutable $decorrenza): bool
    {
        $da = $dal->toDateString();
        $a = $decorrenza->subDay()->toDateString();
        $righe = DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)->where('quota', '>', 0)
            ->whereIn('tipologia', ['proprietario', 'nuda_proprietario', 'usufruttuario'])->get(['tipologia', 'data_inizio', 'data_fine'])
            ->map(fn ($t) => [$t->tipologia, max($t->data_inizio !== null ? substr((string) $t->data_inizio, 0, 10) : '0000-01-01', $da), min($t->data_fine !== null ? substr((string) $t->data_fine, 0, 10) : '9999-12-31', $a)])
            ->filter(fn ($t) => $t[1] <= $t[2]);

        return $righe->where(0, 'proprietario')->contains(fn ($p) => $righe->filter(fn ($t) => $t[0] !== 'proprietario')->contains(fn ($g) => $p[1] <= $g[2] && $g[1] <= $p[2]));
    }

    /** @var array<int, list<array<string, mixed>>> le righe ricostruite per piano, una volta sola (il motore gira intero) */
    private array $righeRicostruite = [];

    /** 1.11.0-beta.44: nella successione, quanti eredi (zero negli altri passaggi). Lo leggono le frasi (rilievi L2 e L3 della Fase 1-bis). */
    private int $eredi = 0;

    /**
     * Rilievo L3 della Fase 1-bis della .44: nella successione le frasi della vendita senza sentenze e senza l'art. 63 (decisione 65: la
     * regola della straordinaria è quella della vendita, letta dalla legge), e chi riceve la nuda proprietà sono gli eredi.
     *
     * @param list<string> $frasi
     * @return list<string>
     */
    private function perGliEredi(array $frasi): array
    {
        if ($this->eredi === 0) {
            return $frasi;
        }

        return array_map(fn (string $f) => strtr($f, [
            ' (art. 63 disp. att. c.c.; Cass. civ. 30 agosto 2025 n. 24236)' => '',
            ' (art. 63 disp. att. c.c.; Cass. 24654/2010)' => '',
            '; art. 63 disp. att. c.c.)' => ')',
            'a chi compra la nuda proprietà' => 'agli eredi',
        ]), $frasi);
    }

    /**
     * La frazione della quota di (unità, intestatario) fatta delle righe che `$filtro` accetta, sulla ricostruzione del motore
     * in sola lettura (decisione 29.1). Null se la ricostruzione non trova righe con un importo.
     */
    private function frazioneDelleRighe(PianoRate $piano, int $immobileId, int $anagraficaId, \Closure $filtro): ?float
    {
        $righe = $this->righeRicostruite[(int) $piano->id] ??= DettaglioRiparto::perPiano($piano)['righe'];
        $sue = collect($righe)->filter(fn (array $r) => (int) ($r['immobile_id'] ?? 0) === $immobileId && (int) ($r['anagrafica_id'] ?? 0) === $anagraficaId
            && in_array($r['tipo'] ?? null, [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_AD_PERSONAM], true));
        $totale = (int) $sue->sum('importo');
        if ($totale === 0) {
            return null;
        }

        // Il filtro dice quanto di ogni riga conta: 1 o 0, o una frazione (il ruolo arrivato solo in parte).
        return $sue->sum(fn (array $r) => (int) ($r['importo'] ?? 0) * (float) $filtro((object) $r)) / $totale;
    }

    /**
     * Fase 1-ter della beta.41: perché le righe ricostruite di un piano senza righe restano fuori dalla quota che passa (vedi
     * `$fuoriQuotaDi`): le frasi lo dicono anche quando la divisione si fa sulla ricostruzione.
     *
     * @return list<string>
     */
    private function motiviDelleRighe(PianoRate $piano, int $immobileId, int $anagraficaId, ?\Closure $fuori, ?array $arrivata = null): array
    {
        $righe = $this->righeRicostruite[(int) $piano->id] ??= DettaglioRiparto::perPiano($piano)['righe'];

        return collect($righe)->filter(fn (array $r) => (int) ($r['immobile_id'] ?? 0) === $immobileId && (int) ($r['anagrafica_id'] ?? 0) === $anagraficaId
                && in_array($r['tipo'] ?? null, [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_AD_PERSONAM], true) && (int) ($r['importo'] ?? 0) !== 0)
            ->map(fn (array $r) => ($fuori !== null ? $fuori((object) $r) : null)
                ?? ($arrivata !== null && ($r['ruolo_risolto'] ?? null) === $arrivata['ruolo'] ? 'mai_arrivata:' . ($arrivata['come'] ?? 'altri_nudi') : null))
            ->filter()->unique()->values()->all();
    }

    /**
     * Decisioni 35, 39 e 40 (difetto U1): i gruppi di quote di un predecessore che il suo passaggio non ha fatto passare. Un
     * passaggio registrato quando il piano si poteva ancora ricalcolare non lo conguaglia e non sposta le bozze (decisione 21):
     * le quote che c'erano già restano di chi è uscito finché il piano non si ricalcola. Se il piano è andato a giornale o ha
     * ricevuto un movimento senza essere ricalcolato — dalla beta.42 l'emissione e l'incasso lo rifiutano, i dati di prima
     * possono esserlo —, quelle quote non sono mai arrivate a chi esce adesso, e un conguaglio su di esse gli darebbe un credito
     * su quote mai sue (€ 1.006,03 su € 1.200,00 nel rapporto).
     *
     * «Non le ha prese» è `PianoRate::presoNelConguaglioDa()` (decisione 42): dalla .42 il registro del passaggio dice quali piani
     * ha preso; per i passaggi di prima la regola di allora (39), e un passaggio della .41 che ha preso solo le rate «emesse» senza
     * scrittura le ha lasciate passare in parte (`presoSoloInParteDa()`): il gruppo si ferma per prudenza. «C'erano già»: `PianoRate::quoteCeranoAl()`. Vendita e usufrutto, e anche il cambio d'inquilino
     * (decisione 40): lì la guardia dell'emissione resta spenta (decisione 32), e il fermo è ciò che conserva la scelta di
     * emettere senza ricalcolare. Non un gruppo che il conguaglio lascia fuori per legge (rilievo A7: l'ordinaria che una riserva
     * ha lasciato a chi vende), dove l'esito non cambierebbe.
     *
     * @return array<string, array{nome: string, il: string}> chiave del gruppo => di chi sono le quote e da quando
     */
    private function maiPassate(Collection $righe, array $predecessori, \Closure $chiaveDi): array
    {
        $esito = [];
        $passaggi = [];
        $piani = [];
        $siRicalcolava = [];
        foreach ($righe->filter(fn ($r) => ! empty($r->ereditata_da))->groupBy($chiaveDi) as $chiave => $gruppo) {
            $primo = $gruppo->first();
            $piano = $piani[(int) $primo->piano_rate_id] ??= PianoRate::find((int) $primo->piano_rate_id);
            if ($piano === null) {
                continue;
            }
            $primaQuota = (int) $gruppo->min('id');
            $natura = null;
            foreach (array_keys($predecessori[(int) $primo->anagrafica_id]['strade'][(int) $primo->immobile_id] ?? []) as $strada) {
                $id = (int) explode('|', (string) $strada)[0];
                $p = $passaggi[$id] ??= Subentro::find($id);
                if ($p === null || ! in_array($p->tipo_passaggio, ['vendita', 'usufrutto', 'fine_locazione', 'successione'], true) || $p->created_at === null
                    || ! $piano->quoteCeranoAl($p, $primaQuota)) {
                    continue;
                }
                // Decisioni 48 e 51 (rilievi W7 e X1): l'anello mancato è un passaggio che quella natura la trasferiva. La
                // costituzione non trasferisce la straordinaria; per l'ordinaria si guarda riga per riga (`anelloTrasferivaOrdinaria`).
                // Il passaggio di adesso, invece, non conta: anche se per legge lascia quella natura a chi esce, il pannello dice
                // che le quote del predecessore non sono mai arrivate (prima lo taceva l'estensione di A7).
                $natura = $natura ?? NaturaGestione::daStringa($piano->gestione?->tipo);
                // Decisione 51 (rilievo X1 del terzo giro): per l'ordinaria si guarda riga per riga se l'anello avrebbe fatto
                // passare qualcosa — la vendita della nuda fa passare le voci sul «Proprietario» dopo una costituzione «come dice
                // ogni voce», e le righe del nudo.
                $nonTrasferiva = $natura === NaturaGestione::Straordinaria
                    ? $p->tipo_passaggio === 'usufrutto' && $p->tipologia === 'usufruttuario'
                    : ! $this->anelloTrasferivaOrdinaria($p, $primo);
                if ($nonTrasferiva) {
                    continue;
                }
                $siRicalcolava[$piano->id . '|' . $id] ??= ! $piano->presoNelConguaglioDa($p) || $piano->presoSoloInParteDa($p);
                if ($siRicalcolava[$piano->id . '|' . $id]) {
                    $esito[$chiave] = ['nome' => (string) $primo->ereditata_da, 'il' => $p->decorrenza->toDateString()];
                    break;
                }
            }
        }

        return $esito;
    }

    /**
     * Decisione 51: l'anello `$p` avrebbe fatto passare l'ordinaria di questo gruppo? Sì se almeno una sua riga di riparto sta da
     * un lato che l'anello trasferisce (`latiDellAnello`) e, nella vendita della nuda, non è dell'usufruttuario: le righe risolte
     * «proprietario» restano all'usufruttuario (R4), salvo le voci sul «Proprietario» dopo una costituzione «come dice ogni voce»
     * sull'unità. Un piano senza righe (anteriore alla beta.29): la vendita della nuda solo con quella costituzione a monte.
     */
    private function anelloTrasferivaOrdinaria(Subentro $p, object $primo): bool
    {
        $lati = self::latiDellAnello($p);
        if ($lati === []) {
            return false;
        }
        $nuda = in_array($p->tipo_passaggio, ['vendita', 'successione'], true) && $p->tipologia === 'nuda_proprietario' && ! $p->riservaUsufrutto();
        $voceAMonte = $nuda && Subentro::where('immobile_id', (int) $primo->immobile_id)->where('tipo_passaggio', 'usufrutto')->where('tipologia', 'usufruttuario')
            ->where('decorrenza', '<=', $p->decorrenza->toDateString())->get()->contains(fn (Subentro $c) => $c->ordinariaComeLaVoce());
        $righe = DB::table('righe_riparto')->where('piano_rate_id', (int) $primo->piano_rate_id)->where('immobile_id', (int) $primo->immobile_id)
            ->where('anagrafica_id', (int) ($primo->righe_di ?? $primo->anagrafica_id))->whereIn('tipo', [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_AD_PERSONAM])
            ->get(['ruolo_richiesto', 'ruolo_risolto', 'tipo']);
        if ($righe->isEmpty()) {
            return ! $nuda || $voceAMonte;
        }

        return $righe->contains(function (object $r) use ($lati, $nuda, $voceAMonte) {
            $lato = self::latoProprietario($r) ? 'P' : 'A';

            return in_array($lato, $lati, true)
                && ! ($nuda && ($r->ruolo_risolto ?? null) === 'proprietario' && ! ($lato === 'P' && $voceAMonte));
        });
    }

    /**
     * I lati dell'ordinaria che un passaggio trasferisce (P: le voci sul «Proprietario», A: le altre). La riserva con la legge
     * nessuno, la riserva «come dice ogni voce» le voci sul «Proprietario», la costituzione «come dice ogni voce» le altre; gli
     * altri passaggi tutti e due. Una regola sola per `anelloOrdinario` e per il fermo «mai passate» (decisione 51).
     *
     * @return list<'P'|'A'>
     */
    private static function latiDellAnello(Subentro $s): array
    {
        $comeLaVoce = $s->ordinariaComeLaVoce();

        return match (true) {
            $s->riservaUsufrutto() && ! $comeLaVoce => [],
            $s->riservaUsufrutto() => ['P'],
            $comeLaVoce && $s->tipo_passaggio === 'usufrutto' => ['A'],
            default => ['P', 'A'],
        };
    }

    /**
     * Chi, oltre a chi esce, ha quote emesse che il conguaglio riguarda: i suoi **predecessori** sulle stesse
     * unità, risalendo la catena dei passaggi registrati (Fase 1-bis, S8-3). Chi ha comprato a maggio con la
     * coppia di conguaglio e rivende a settembre non ha quote emesse a suo nome: quelle del venditore di maggio
     * portano la competenza che gli è passata, e passano ancora. Si include anche chi ha rinunciato o annullato
     * la coppia: il conguaglio si propone, non si impone, e l'amministratore può rinunciare di nuovo.
     *
     * **Una data per natura** (rilievo B3 della Fase 1-bis della beta.38). Ogni anello trasferisce l'ordinaria e la
     * straordinaria insieme, dalla sua decorrenza, salvo la vendita con riserva d'usufrutto: quella trasferisce **solo la
     * straordinaria**, perché l'ordinaria la tiene chi vende, che resta usufruttuario (art. 1004 c.c., decisione 28). Chi si
     * raggiunge solo attraverso una riserva ha l'ordinaria a null e `riservata_da` (chi l'ha tenuta): le sue quote ordinarie
     * non sono mai passate a chi esce, e il conguaglio le lascia fuori. I visti sono per (persona, natura): nella catena
     * riserva → estinzione → vendita la straordinaria passa con la riserva e l'ordinaria con l'estinzione, e il secondo
     * anello non si salta più perché la persona è «già vista». Senza riserve nella catena le due date coincidono.
     *
     * @return array<int, array{nome: ?string, decorrenza: array{ordinaria: ?string, straordinaria: ?string}, riservata_da: ?string}> predecessore → quando chi esce ne ha acquistato la competenza, per natura
     */
    public function predecessori(int $anagraficaId, array $immobileIds): array
    {
        $nature = [NaturaGestione::Ordinaria->value, NaturaGestione::Straordinaria->value];
        $trovati = [];
        $frontiera = [$anagraficaId];
        $visti = [$anagraficaId => array_fill_keys($nature, true)];
        while ($frontiera !== []) {
            $prossimi = [];
            $anelli = $this->anelliVerso($frontiera, $immobileIds);
            $righeUscenti = DB::table('anagrafica_immobile')->whereIn('id', array_filter(array_map(fn ($a) => $a[0]->riga_uscente_id, $anelli)))->get(['id', 'tipologia'])->keyBy('id');
            foreach ($anelli as [$s, $entrante, $frazione]) {
                $pid = (int) $s->anagrafica_uscente_id;
                if ($pid === $anagraficaId) {
                    continue;
                }
                $trovati[$pid] ??= ['nome' => $s->uscente?->nome, 'decorrenza' => array_fill_keys($nature, null), 'riservata_da' => null, 'voci_passate' => array_fill_keys($nature, null), 'lati' => ['P' => null, 'A' => null], 'restano' => [], 'arrivata' => [], 'strade' => []];
                // Le strade per cui il predecessore arriva a chi esce, per unità, e se una porta solo una parte (catenaAmbigua).
                $trovati[$pid]['strade'][(int) $s->immobile_id][$s->id . '|' . $entrante] = $frazione < 1.0;
                // Fase 1-ter della beta.41: i ruoli che il predecessore teneva accanto alla quota che è passata,
                // il giorno del suo passaggio — le altre sue righe in vigore, compresa quella che il passaggio gli ha lasciato
                // (nuda proprietà dopo una costituzione, usufrutto dopo una riserva). Le sue righe di riparto di quei ruoli non
                // sono mai arrivate a chi esce.
                $uscita = $righeUscenti[(int) $s->riga_uscente_id] ?? null;
                if ($uscita !== null) {
                    // Il ruolo che questo anello trasferisce: un ruolo tenuto in un anello (l'usufrutto alla riserva) e trasferito
                    // in un altro (l'estinzione) è arrivato a chi esce.
                    $trovati[$pid]['passati'][(int) $s->immobile_id][$uscita->tipologia] = true;
                    // Fase 1-ter della beta.41: l'estinzione di un usufrutto con più nudi proprietari dà a ciascuno la parte della
                    // sua quota, e a chi esce, attraverso questo anello, è arrivata solo quella. Conta l'anello diretto: due
                    // estinzioni parziali in fila (un usufruttuario che riceve l'usufrutto da due nudi diversi e muore) non si
                    // seguono, perché la parte che torna a ciascuno è la sua metà, non una proporzione.
                    if ($frazione < 1.0) {
                        $come = $s->successione() ? 'altri_eredi' : ($s->conAccrescimento() ? 'altri_usufruttuari' : 'altri_nudi');
                        // Decisione 68 (2): quando l'anello dopo è una successione, la parte arrivata si moltiplica lungo la catena (nonno →
                        // figli → nipoti: 0,5 × 0,5); negli altri casi resta quella dell'anello diretto, come sopra.
                        $dopo = $entrante !== $anagraficaId ? ($trovati[$entrante]['arrivata'][(int) $s->immobile_id] ?? null) : null;
                        if ($dopo !== null && ($dopo['come'] ?? null) === 'altri_eredi' && in_array($dopo['ruolo'], [$uscita->tipologia, (string) $s->tipologia], true)) {
                            $frazione *= (float) $dopo['frazione'];
                            $come = $come === 'altri_eredi' ? 'altri_eredi' : 'altri_titolari';
                        }
                        $trovati[$pid]['arrivata'][(int) $s->immobile_id] ??= ['ruolo' => $uscita->tipologia, 'frazione' => $frazione, 'come' => $come];
                    } elseif ($entrante !== $anagraficaId && ($arrivataDopo = $trovati[$entrante]['arrivata'][(int) $s->immobile_id] ?? null) !== null
                        && in_array($arrivataDopo['ruolo'], [$uscita->tipologia, (string) $s->tipologia], true)) {
                        // Giro sulle correzioni della Fase 1-bis della .44 (G26): un anello pieno prima di uno parziale (Ugo vende tutto a
                        // Mario, poi Mario lascia metà ad Anna con la successione) porta a chi esce la stessa parte dell'anello dopo: le quote
                        // di Ugo arrivano ad Anna per metà, come quelle di Mario. Anche quando l'anello pieno cambia il ruolo (ultima
                        // revisione, UD3): l'usufrutto di Ugo che con l'estinzione torna alla piena di Mario, divisa poi fra gli eredi; la
                        // parte vale per il ruolo che Ugo aveva. Due anelli parziali in fila restano non seguiti (sopra).
                        $trovati[$pid]['arrivata'][(int) $s->immobile_id] ??= ['ruolo' => $uscita->tipologia] + $arrivataDopo;
                    }
                    $giorno = $s->decorrenza->toDateString();
                    foreach (DB::table('anagrafica_immobile')->where('anagrafica_id', $pid)->where('immobile_id', $s->immobile_id)
                        ->where('id', '!=', $uscita->id)->where('tipologia', '!=', $uscita->tipologia)
                        ->where(fn ($q) => $q->whereNull('data_inizio')->orWhereDate('data_inizio', '<=', $giorno))
                        ->where(fn ($q) => $q->whereNull('data_fine')->orWhereDate('data_fine', '>=', $giorno))
                        ->pluck('tipologia') as $tipologia) {
                        $trovati[$pid]['restano'][(int) $s->immobile_id][$tipologia] = true;
                    }
                }
                $nuove = false;
                foreach ($nature as $natura) {
                    if ($natura === NaturaGestione::Ordinaria->value) {
                        $nuove = $this->anelloOrdinario($trovati, $pid, $entrante, $anagraficaId, $s) || $nuove;
                        continue;
                    }
                    if (isset($visti[$pid][$natura])) {
                        continue;
                    }
                    // La straordinaria (l'ordinaria si segue per lato, qui sopra). La data in cui la competenza di questo
                    // predecessore è passata a chi esce (verifica S8-bis, L1-6): al primo anello è la decorrenza del passaggio
                    // verso chi esce; agli anelli successivi è quella ereditata dal proprio antenato di primo anello — chi ha
                    // comprato da A il 1/3 e da B il 1/6 ha due date, una per quota. Ogni anello la trasferisce, anche la riserva.
                    $decorrenza = $entrante === $anagraficaId ? $s->decorrenza->toDateString() : $trovati[$entrante]['decorrenza'][$natura];
                    if ($decorrenza === null) {
                        $trovati[$pid]['riservata_da'] ??= $trovati[$entrante]['riservata_da'];
                        continue;
                    }
                    $visti[$pid][$natura] = true;
                    $trovati[$pid]['decorrenza'][$natura] = $decorrenza;
                    $nuove = true;
                }
                if ($nuove) {
                    $prossimi[] = $pid;
                }
            }
            $frontiera = $prossimi;
        }

        return $trovati;
    }

    /**
     * I passaggi verso le persone della frontiera, come [passaggio, chi entra, frazione arrivata]. Fase 1-ter della beta.41:
     * l'estinzione dell'usufrutto è un anello verso **ogni** nudo proprietario che torna pieno, con la parte della sua quota;
     * il passaggio registra come «chi entra» solo il primo. Prima l'usufrutto della vedova arrivava tutto al figlio scritto
     * per primo, e niente all'altro: chi comprava dal primo pagava il godimento dell'unità intera, chi comprava dal secondo
     * niente.
     *
     * @param list<int> $frontiera
     * @param list<int> $immobileIds
     * @return list<array{0: Subentro, 1: int, 2: float}>
     */
    private function anelliVerso(array $frontiera, array $immobileIds): array
    {
        $passaggi = Subentro::with('uscente:id,nome')->whereIn('immobile_id', $immobileIds)->whereNotNull('anagrafica_uscente_id')
            ->where(fn ($q) => $q->whereIn('anagrafica_entrante_id', $frontiera)->orWhere('tipo_passaggio', 'usufrutto')->orWhere('tipo_passaggio', 'successione'))
            ->orderBy('decorrenza')->orderBy('id')->get();
        $anelli = [];
        foreach ($passaggi as $s) {
            // Decisione 65 (1.11.0-beta.44): la successione è un anello verso **ogni** erede, con la quota che ha ereditato (dal registro,
            // non dalla riga, che può essere sommata). Come per l'estinzione, il passaggio registra come chi entra uno solo: senza,
            // quando un altro erede vende, le quote emesse al defunto non passavano a chi compra.
            // L'estinzione con l'accrescimento è un anello verso ogni usufruttuario che riceve, come la successione verso ogni erede.
            $nudi = $s->destinatari() !== [] ? collect($s->destinatari())->mapWithKeys(fn ($e) => [(int) $e['anagrafica_id'] => (float) $e['quota']])->all()
                : ($s->estinzioneUsufrutto() ? $this->nudiDellEstinzione($s) : []);
            if ($nudi === []) {
                if (in_array((int) $s->anagrafica_entrante_id, $frontiera, true)) {
                    $anelli[] = [$s, (int) $s->anagrafica_entrante_id, 1.0];
                }
                continue;
            }
            $totale = array_sum($nudi);
            foreach ($nudi as $anagraficaId => $quota) {
                if (in_array($anagraficaId, $frontiera, true)) {
                    $anelli[] = [$s, $anagraficaId, $totale > 0 ? $quota / $totale : 1.0];
                }
            }
        }

        return $anelli;
    }

    /**
     * I nudi proprietari che l'estinzione ha fatto tornare pieni, con la loro quota, dal registro del passaggio: le righe
     * aperte o cambiate in «proprietario». Solo le righe dell'unità del passaggio, e la quota scritta nel registro quando
     * c'è, non quella di oggi, che un passaggio dopo può aver cambiato (rilievo S-R2 della revisione della 1-ter).
     * @return array<int, float> anagrafica → quota
     */
    private function nudiDellEstinzione(Subentro $s): array
    {
        // Seconda revisione della Fase 1-ter (M2-3): un'estinzione registrata prima della beta.37 non ha le righe nel registro.
        // Si ricostruisce ciò che la registrazione ha scritto: il nudo chiuso il giorno prima e riaperto «proprietario» il
        // giorno dell'atto (e la riga di chi entra, per il nudo nato lo stesso giorno e cambiato sul posto).
        if (! isset($s->registro['righe'])) {
            $nudiPrima = DB::table('anagrafica_immobile')->where('immobile_id', $s->immobile_id)->where('tipologia', 'nuda_proprietario')
                ->whereDate('data_fine', $s->decorrenza->subDay()->toDateString())->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all();

            return DB::table('anagrafica_immobile')->where('immobile_id', $s->immobile_id)->where('tipologia', 'proprietario')
                ->whereDate('data_inizio', $s->decorrenza->toDateString())
                ->where(fn ($q) => $q->whereIn('anagrafica_id', $nudiPrima)->orWhere('id', (int) $s->riga_entrante_id))
                ->get(['anagrafica_id', 'quota'])->groupBy('anagrafica_id')->map(fn ($g) => (float) $g->sum('quota'))
                ->mapWithKeys(fn ($q, $id) => [(int) $id => $q])->all();
        }
        // Decisione 36 (1.11.0-beta.42): con la somma la riga riaperta porta anche la quota piena che il nudo aveva già (Nora piena 50
        // e nuda 50 → piena 100), e leggerla come «parte tornata piena» gonfiava la sua frazione dell'usufrutto. La parte tornata
        // è la quota della riga di nudo che l'estinzione ha chiuso, o di quella cambiata sul posto (la quota di prima, se il
        // registro la porta). Per i registri di prima della .42 è la stessa cifra: la riga aperta aveva la quota del nudo.
        $righe = collect($s->registro['righe'] ?? []);
        $chiuse = $righe->where('operazione', 'chiusa')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cambiate = $righe->filter(fn ($r) => ($r['operazione'] ?? null) === 'modificata' && ($r['dopo']['tipologia'] ?? null) === 'proprietario')->keyBy(fn ($r) => (int) $r['id']);
        $nudiChiusi = DB::table('anagrafica_immobile')->whereIn('id', $chiuse)->where('immobile_id', $s->immobile_id)->where('tipologia', 'nuda_proprietario')
            ->get(['anagrafica_id', 'quota'])->map(fn ($r) => [(int) $r->anagrafica_id, (float) $r->quota]);
        $nudiCambiati = DB::table('anagrafica_immobile')->whereIn('id', $cambiate->keys()->all())->where('immobile_id', $s->immobile_id)->get(['id', 'anagrafica_id', 'quota'])
            ->map(fn ($r) => [(int) $r->anagrafica_id, (float) ($cambiate[(int) $r->id]['prima']['quota'] ?? $r->quota)]);

        return $nudiChiusi->concat($nudiCambiati)->groupBy(0)->map(fn ($g) => (float) $g->sum(1))
            ->mapWithKeys(fn ($q, $id) => [(int) $id => $q])->all();
    }

    /**
     * Rilievi D4 e D5 della Fase 1-bis della beta.41: l'ordinaria si segue **per lato** — le voci sul «Proprietario» (P) e
     * le altre (A) — perché con «come la voce» un anello può farne passare uno solo: la riserva «voce» il lato P, la
     * costituzione «voce» e l'estinzione di un usufrutto nato «voce» il lato A; la riserva con la legge nessuno (l'ordinaria
     * resta a chi vende); ogni altro anello tutti e due. Lo stesso predecessore si può raggiungere con più anelli (riserva
     * «voce» il 1/05, estinzione il 1/09): prima il primo segnava la persona come «vista» e il secondo si saltava, e il lato A
     * non passava più. Ora ogni lato prende il suo giorno, il più vecchio fra gli anelli che lo portano. Agli anelli più
     * lontani il giorno è quello in cui il lato è arrivato a chi esce, come prima per la data unica.
     *
     * Ne derivano la data comune (la più vecchia) e `voci_passate` (null = tutti e due i lati), che il resto del calcolo
     * legge come prima. Restituisce se ha aggiunto un lato.
     */
    private function anelloOrdinario(array &$trovati, int $pid, int $entrante, int $anagraficaId, Subentro $s): bool
    {
        $ordinaria = NaturaGestione::Ordinaria->value;
        $lati = self::latiDellAnello($s);
        $aggiunti = false;
        $arrivati = false;
        foreach ($lati as $lato) {
            $giorno = $entrante === $anagraficaId ? $s->decorrenza->toDateString() : ($trovati[$entrante]['lati'][$lato] ?? null);
            if ($giorno === null) {
                continue;
            }
            $arrivati = true;
            if ($trovati[$pid]['lati'][$lato] === null || $giorno < $trovati[$pid]['lati'][$lato]) {
                $trovati[$pid]['lati'][$lato] = $giorno;
                $aggiunti = true;
            }
        }
        if (! $arrivati) {
            // Rilievo B3 della beta.38: l'ordinaria rimasta a chi vendeva con riserva, o mai arrivata attraverso questo anello.
            $trovati[$pid]['riservata_da'] ??= $lati === [] ? $s->uscente?->nome : ($trovati[$entrante]['riservata_da'] ?? null);
        }
        $p = $trovati[$pid]['lati']['P'];
        $a = $trovati[$pid]['lati']['A'];
        $trovati[$pid]['decorrenza'][$ordinaria] = $p === null ? $a : ($a === null ? $p : min($p, $a));
        $trovati[$pid]['voci_passate'][$ordinaria] = match (true) {
            $p !== null && $a === null => self::VOCI_SUL_PROPRIETARIO,
            $p === null && $a !== null => self::VOCI_NON_SUL_PROPRIETARIO,
            default => null,
        };

        return $aggiunti;
    }

    /** Gli intestatari le cui quote emesse il conguaglio riguarda: chi esce e i suoi predecessori (per l'anteprima). */
    public function intestatariConguagliabili(int $anagraficaId, array $immobileIds): array
    {
        return array_values(array_unique([$anagraficaId, ...array_keys($this->predecessori($anagraficaId, $immobileIds))]));
    }

    /**
     * Il filtro del pannello sulle quote emesse, con la stessa regola del conguaglio (DV4): per ogni unità del passaggio, chi
     * esce e i predecessori che su quell'unità gli hanno ceduto qualcosa.
     *
     * @param list<int> $immobileIds
     * @return \Closure(int, int): bool anagrafica, unità → la quota conta
     */
    public function filtroConguagliabili(int $anagraficaId, array $immobileIds): \Closure
    {
        $predecessori = $this->predecessori($anagraficaId, $immobileIds);

        return fn (int $intestatario, int $immobileId): bool => self::contaPerChiEsce($anagraficaId, $predecessori, $intestatario, $immobileId);
    }

    /** Una quota conta per chi esce se è sua, o di un predecessore che su quell'unità gli ha ceduto qualcosa (DV4). */
    private static function contaPerChiEsce(int $anagraficaId, array $predecessori, int $intestatario, int $immobileId): bool
    {
        return $intestatario === $anagraficaId || isset($predecessori[$intestatario]['strade'][$immobileId]);
    }

    /**
     * Le quote emesse a chi esce — e ai suoi predecessori (S8-3) — sulle unità del passaggio, con ciò che serve a
     * decidere la competenza. Le righe ereditate portano `ereditata_da` e `decorrenza_acquisto` — la data della natura
     * della gestione del piano (rilievo B3) — e, se quella natura non è mai passata a chi esce, `riservata_da`.
     */
    private function quoteConguagliabili(int $anagraficaId, array $immobileIds, ?array $predecessori = null): Collection
    {
        $predecessori ??= $this->predecessori($anagraficaId, $immobileIds);

        $intestatari = [$anagraficaId, ...array_keys($predecessori)];
        // Decisione 67 (1), rilievo X3 della Fase 1-bis della .44: le bozze del defunto passate all'erede di riferimento sono, per la
        // spesa, anche degli altri eredi. Quando ne vende uno, entrano nel suo conguaglio con la sua quota, come quelle rimaste al
        // defunto; restano del riferimento (non passano a chi compra). Gli altri eredi delle successioni dei predecessori.
        $coeredi = array_values(array_diff(Subentro::where('tipo_passaggio', 'successione')->whereIn('immobile_id', $immobileIds)
            ->whereIn('anagrafica_uscente_id', array_keys($predecessori))->get()
            ->flatMap(fn (Subentro $s) => array_column($s->eredi(), 'anagrafica_id'))->map(fn ($id) => (int) $id)->unique()->all(), $intestatari));
        $nomiCoeredi = $coeredi === [] ? collect() : Anagrafica::whereIn('id', $coeredi)->pluck('nome', 'id');
        $diUnCoerede = function ($r) use ($coeredi, $predecessori): ?int {
            if (! in_array((int) $r->anagrafica_id, $coeredi, true)) {
                return null;
            }
            $regole = is_string($r->regole_calcolo) ? json_decode($r->regole_calcolo, true) : (array) $r->regole_calcolo;
            // Giro sulle correzioni (G13): chi ha passato la quota al riferimento è il defunto (`da_anagrafica_id`), non chi aveva il piano
            // all'origine (`righe_di`, che resta lo stesso lungo la catena): la parte arrivata a chi esce si legge su di lui.
            $daChi = (int) ($regole['riassegnazione']['da_anagrafica_id'] ?? $regole['riassegnazione']['righe_di'] ?? 0);

            return $daChi !== (int) $r->anagrafica_id && isset($predecessori[$daChi]) ? $daChi : null;
        };
        $candidati = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->whereIn('rate_quote.immobile_id', $immobileIds)->whereIn('rate_quote.anagrafica_id', [...$intestatari, ...$coeredi])
            ->distinct()->pluck('rate.piano_rate_id')->all();

        return DB::table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->join('immobili', 'immobili.id', '=', 'rate_quote.immobile_id')
            ->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
            ->leftJoin('gestioni', 'gestioni.id', '=', 'piani_rate.gestione_id')
            ->whereIn('rate_quote.immobile_id', $immobileIds)
            ->whereIn('rate_quote.anagrafica_id', [...$intestatari, ...$coeredi])
            // Decisione 21 (S8-1), con il criterio unico delle decisioni 34, 34.1 e 38 (1.11.0-beta.42): le quote dei piani che il
            // ricalcolo non può più riscrivere — una quota a giornale, un movimento o un conguaglio di un passaggio
            // (`PianoRate::eImmutabile()`) —, emesse o ancora in bozza; le altre le sistema il ricalcolo con il cancello (2).
            // Fino alla beta.41 contava anche lo stato «emessa» di una rata senza scritture: il conguaglio e il ricalcolo
            // sistemavano la stessa quota due volte (DC5).
            ->whereIn('rate.piano_rate_id', \App\Models\Gestionale\PianoRate::immutabiliFra($candidati))
            ->where('rate_quote.stato', '!=', 'annullata')
            ->orderBy('rate.piano_rate_id')->orderBy('rate.numero_rata')
            ->get(['rate_quote.id', 'rate_quote.rata_id', 'rate_quote.immobile_id', 'rate_quote.anagrafica_id', 'immobili.nome as immobile_nome', 'rate_quote.importo', 'rate_quote.importo_pagato', 'rate_quote.stato as stato_quota', 'rate_quote.regole_calcolo', 'rate.numero_rata', 'rate.data_scadenza', 'rate.piano_rate_id', 'rate.stato as stato_rata', 'gestioni.tipo as natura_gestione',
                DB::raw('EXISTS (SELECT 1 FROM rate_quote q_gio WHERE q_gio.rata_id = rate.id AND q_gio.scrittura_contabile_id IS NOT NULL) as rata_a_giornale')])
            // DV4 (decisione 59, 1.11.0-beta.43): le quote di un predecessore contano solo sulle unità dove il predecessore ha
            // ceduto qualcosa a chi esce. Prima si prendevano su tutte le unità del passaggio: chi vendeva a chi esce la sua
            // metà dell'appartamento ma non del box portava nel conguaglio anche le quote del box, e chi comprava
            // l'appartamento con il box pagava i giorni della metà rimasta all'altro.
            ->filter(fn ($r) => self::contaPerChiEsce($anagraficaId, $predecessori, $diUnCoerede($r) ?? (int) $r->anagrafica_id, (int) $r->immobile_id))
            ->map(function ($r) use ($anagraficaId, $predecessori, $diUnCoerede, $nomiCoeredi) {
                // La quota di un altro erede si legge come quella del predecessore da cui viene: la sua decorrenza, la parte arrivata a
                // chi esce; e da predecessore non passa a chi entra. Le frasi dicono a chi è intestata.
                $coerede = $diUnCoerede($r);
                $r->intestato_a = $coerede !== null ? ($nomiCoeredi[(int) $r->anagrafica_id] ?? null) : null;
                $pred = $coerede !== null ? $predecessori[$coerede] : ((int) $r->anagrafica_id !== $anagraficaId ? ($predecessori[(int) $r->anagrafica_id] ?? null) : null);
                $r->ereditata_da = $pred['nome'] ?? null;
                $r->decorrenza_acquisto = $pred['decorrenza'][NaturaGestione::daStringa($r->natura_gestione)->value] ?? null;
                // Decisione 31.5: quali voci ordinarie il passaggio del predecessore ha fatto passare, se le ha scelte una per una.
                $r->voci_passate = $pred['voci_passate'][NaturaGestione::daStringa($r->natura_gestione)->value] ?? null;
                // Rilievo D5: per l'ordinaria, il giorno in cui ciascun lato delle voci è arrivato a chi esce.
                $r->decorrenze_lato = $pred !== null && NaturaGestione::daStringa($r->natura_gestione) === NaturaGestione::Ordinaria ? $pred['lati'] : null;
                // Fase 1-ter della beta.41: i ruoli che il predecessore teneva accanto alla quota passata, su questa unità.
                $r->restano = $pred !== null ? array_values(array_diff(array_keys($pred['restano'][(int) $r->immobile_id] ?? []), array_keys($pred['passati'][(int) $r->immobile_id] ?? []))) : null;
                // Fase 1-ter della beta.41: la parte del ruolo del predecessore arrivata a chi esce, quando non è tutta.
                $r->arrivata = $pred['arrivata'][(int) $r->immobile_id] ?? null;
                // Rilievo B3: l'ordinaria di chi ha venduto con riserva d'usufrutto non è mai passata a chi esce.
                $r->riservata_da = $pred !== null && $r->decorrenza_acquisto === null ? ($pred['riservata_da'] ?? $pred['nome'] ?? 'chi ha venduto con riserva d\'usufrutto') : null;
                // Decisione 34: in bozza è la rata che non è andata a giornale, qualunque sia il suo stato.
                $r->in_bozza = ! (bool) $r->rata_a_giornale;
                // B3a: una quota passata a questa persona da un passaggio precedente porta con sé il riparto di chi la
                // aveva (le `righe_riparto` restano del soggetto per cui il piano è stato generato): la scomposizione
                // per conto si legge da lì, riportata alla quota con il `$fattore`.
                $regole = is_string($r->regole_calcolo) ? json_decode($r->regole_calcolo, true) : (array) $r->regole_calcolo;
                $r->righe_di = (int) ($regole['riassegnazione']['righe_di'] ?? $r->anagrafica_id);
                // R11 (Fase 1-bis): la quota è di chi esce solo dal giorno in cui gli è passata; i giorni prima si sono
                // regolati con quel passaggio. Si tratta come la quota di un predecessore per i giorni, non per il nome.
                $passata = $regole['riassegnazione'] ?? null;
                $r->passata_il = null;
                if ($r->ereditata_da === null && is_array($passata) && (int) ($passata['righe_di'] ?? 0) !== (int) $r->anagrafica_id && ! empty($passata['decorrenza'])) {
                    $r->decorrenza_acquisto = $passata['decorrenza'];
                    $r->passata_il = $passata['decorrenza'];
                }

                return $r;
            });
    }


    /**
     * La quota di un intestatario (chi esce, o un suo predecessore) su un'unità, **riga per riga** dalle
     * `righe_riparto` del piano, ognuna divisa sulla sua competenza ∩ il suo tratto di titolarità. Null se il piano
     * non ha righe per quel soggetto (piano generato prima della beta.29: si torna alla base, come prima di S6).
     *
     * - **Ordinario**: la competenza della riga è il tratto del pivot (del conto → della radice), altrimenti la base;
     *   gruppo per (conto, tratto di titolarità): la stessa persona con due righe sullo stesso conto — ha cambiato
     *   quota nell'anno, o ha preso un ripiego — ha due gruppi, ciascuno sui suoi giorni (S8-bis L1-1). Eccezione
     *   (decisione 26, 1.11.0-beta.35): una riga del piano da fatture con il gradino `dichiarata` usa la competenza
     *   congelata sulla riga, come lo straordinario, e il gruppo è (conto, dal, al, tratto).
     * - **Straordinario (S8-4)**: la competenza è quella congelata **sulla riga** (`competenza_dal/al`, gradino
     *   `dichiarata` o `delibera`), gruppo per (conto, dal, al, tratto); riga senza competenza → la delibera del
     *   piano se c'è (`$base`), altrimenti il conto resta non risolto e per intero a chi esce.
     * - **Il tratto (migrazione 11)**: `titolarita_dal/al` è ciò che quei giorni coprono; la divisione è
     *   `competenza ∩ tratto` alla decorrenza — nessuna ipotesi su giorni «in coda». Righe senza tratto (scritte prima
     *   della migrazione 11, o atemporali) si dividono sull'intera competenza, come prima (S8-bis L1-2/L1-4).
     * - **Predecessore (S8-3)**: con `$decorrenzaAcquisto`, a chi entra va la parte dalla decorrenza in poi; a chi
     *   esce quella fra il suo acquisto e la decorrenza; ciò che precede l'acquisto resta del predecessore.
     * - **Netting del già versato (S8-5, decisione 17 + D8)**: il motore congela due righe `netting` per chiave —
     *   «già versato della persona» e «già versato dell'unità» (`CalcoloQuoteService::NETTING_*`) — e qui si
     *   rileggono così com'erano alla generazione, attribuite ai gruppi del conto in proporzione ai lordi. La parte
     *   dell'unità abbassa la spesa da dividere; la parte della persona **non entra nella divisione**: è un pagamento
     *   di chi esce e resta suo (`versato_uscente`), tolto dalla sua parte dopo la divisione al lordo. Così `entrante`
     *   può superare `totale`: è l'eccedenza di chi esce, come nel motore. Piani pre-B2 (una riga sola, senza
     *   descrizione): parte della persona zero. ⚠️ Con **tutte e due** le parti sullo stesso conto i centesimi non
     *   coincidono con il motore, che toglie prima la parte della persona e divide quella dell'unità sulle righe già
     *   ridotte (`CalcoloQuoteService::nettingGiaVersato`): qualche euro su € 700,00. Precedente alla beta.35, è
     *   una scelta di dominio ancora da fare (decisione 17, D8): Coda 160.
     *
     * @param array<int, InsiemePeriodi> $tratti
     * @param ?InsiemePeriodi $base la competenza del piano quando è risolta (ordinario: base; straordinario: la delibera, usata come ripiego)
     * @return ?array{totale:int, uscente:int, entrante:int, versato_uscente:int, non_risolte:int, gradino:?string, competenza_per_fattura:bool, voce_per_voce:bool, periodo:?array, giorni:array, dettagliato:bool, conti:list<array<string,mixed>>}
     */
    private function scomponiPerConto(int $pianoRateId, int $immobileId, int $anagraficaId, array $tratti, ?InsiemePeriodi $base, CarbonImmutable $decorrenza, bool $straordinaria = false, ?CarbonImmutable $decorrenzaAcquisto = null, ?string $vociPassate = null, ?string $vociArrivate = null, ?array $decorrenzeLato = null, ?\Closure $fuoriQuota = null, ?array $arrivata = null, ?array $genealogia = null, bool $arrivoPerParte = false): ?array
    {
        $righe = DB::table('righe_riparto')
            ->where('piano_rate_id', $pianoRateId)->where('immobile_id', $immobileId)->where('anagrafica_id', $anagraficaId)
            // Tutte le righe del soggetto, non solo i lordi (verifica S6, R7): il netting del già versato è una riga
            // negativa dello stesso conto e la quota pura emessa è già al netto — sommandola qui, `totale` torna a
            // essere la quota pura del piano e un conto interamente già versato pesa zero.
            ->whereIn('tipo', [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_NETTING, RigaRiparto::TIPO_AD_PERSONAM])
            ->get(['id', 'conto_id', 'conto_nome', 'conto_radice_id', 'tabella_id', 'ruolo_richiesto', 'ruolo_risolto', 'importo', 'tipo', 'giorni_titolarita', 'competenza_dal', 'competenza_al', 'gradino_competenza', 'riga_descrizione', 'riga_fattura_id', 'titolarita_dal', 'titolarita_al']);
        if ($righe->isEmpty()) {
            return null;
        }
        // Le righe di tutti sull'unità: servono a ricostruire i giorni di una riga di ripiego (Fase 1-bis della beta.34, R5).
        $righeUnita = DB::table('righe_riparto')->where('piano_rate_id', $pianoRateId)->where('immobile_id', $immobileId)->where('tipo', RigaRiparto::TIPO_RIPARTO)
            ->get(['conto_id', 'tabella_id', 'ruolo_richiesto', 'ruolo_risolto', 'giorni_titolarita', 'titolarita_dal', 'titolarita_al']);

        $giorno = fn ($d) => $d === null ? null : substr((string) $d, 0, 10);
        $lordi = $righe->whereIn('tipo', [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_AD_PERSONAM]);
        // Fase 1-ter della beta.41: del ruolo che il predecessore ha lasciato con un'estinzione a più nudi proprietari, a chi
        // esce è arrivata solo la parte della sua quota. Ogni riga di quel ruolo si divide in due: la parte arrivata, che si
        // divide per giorni come sempre, e il resto, che è di un'altra quota (`non_arrivata`).
        if ($arrivata !== null && (float) $arrivata['frazione'] < 1.0) {
            $lordi = $lordi->flatMap(function ($r) use ($arrivata) {
                if (($r->ruolo_risolto ?? null) !== $arrivata['ruolo']) {
                    return [$r];
                }
                $parte = clone $r;
                $parte->importo = (int) round((int) $r->importo * (float) $arrivata['frazione']);
                $resto = clone $r;
                $resto->importo = (int) $r->importo - $parte->importo;
                $resto->non_arrivata = $arrivata['come'] ?? 'altri_nudi';

                return [$parte, $resto];
            });
        }
        // Decisione 56 (1.11.0-beta.43): con la genealogia ogni riga si divide nelle sue parti — quella arrivata a chi esce (dal
        // giorno in cui ci è arrivata, e all'estinzione con il peso di ogni nudo) e quelle di un'altra quota, con la ragione. La
        // parte è l'importo per la frazione; il resto dell'arrotondamento all'ultima, così le parti sommano alla riga.
        if ($genealogia !== null) {
            $lordi = $lordi->flatMap(function ($r) use ($genealogia) {
                $parti = $genealogia[(int) $r->id] ?? [['frazione' => 1.0, 'arrivo' => null, 'esito' => null, 'nudi' => null]];
                $resto = (int) $r->importo;
                $pezzi = [];
                foreach (array_values($parti) as $i => $parte) {
                    $pezzo = clone $r;
                    $pezzo->importo = $i === count($parti) - 1 ? $resto : (int) round((int) $r->importo * (float) $parte['frazione']);
                    $resto -= $pezzo->importo;
                    $pezzo->legame = $parte;
                    $pezzi[] = $pezzo;
                }

                return $pezzi;
            });
        }
        $nettingRighe = $righe->where('tipo', RigaRiparto::TIPO_NETTING);
        // Decisione 26 (1.11.0-beta.35): nel piano da fatture la riga porta la competenza della sua fattura anche sulla
        // gestione ordinaria (gradino «dichiarata»), e si divide su quella, come la straordinaria — non sulla base. I
        // piani generati prima (righe «gestione»/«esercizio») restano come sono stati calcolati: anteprima = scrittura.
        $dichiarata = fn ($r) => ($r->gradino_competenza ?? null) === 'dichiarata' && $r->competenza_dal !== null && $r->competenza_al !== null;
        // Un addebito diretto all'unità non ha conto: senza la sua riga nella chiave, due addebiti con la stessa competenza
        // si fondevano in una voce sola, col nome del primo e la somma dei due (verifica delle correzioni, beta.35). Una
        // voce per riga della fattura, come la scrive il motore.
        $perRiga = fn ($r) => ($r->tipo ?? null) === RigaRiparto::TIPO_AD_PERSONAM ? '|riga:' . ($r->riga_fattura_id ?? $r->riga_descrizione ?? '') : '';
        // Rilievo D6 della Fase 1-bis della beta.41: con «come la voce» le righe dello stesso conto che stanno da parti diverse
        // (una voce divisa fra «Proprietario» e «Inquilino») sono gruppi distinti, e ciascuna passa o resta per conto suo.
        // Prima il gruppo era uno, e decideva la prima riga. In coda, così il conto resta il primo pezzo della chiave.
        // Lo stesso quando i due lati sono arrivati a chi esce in giorni diversi (una riserva «come la voce», poi l'estinzione):
        // la voce divisa ha una data d'acquisto per parte, e un gruppo solo prendeva la data della prima riga per tutte e due.
        $latiDiversi = ! $straordinaria && $decorrenzeLato !== null && ($decorrenzeLato['P'] ?? null) !== null && ($decorrenzeLato['A'] ?? null) !== null && $decorrenzeLato['P'] !== $decorrenzeLato['A'];
        $lato = fn ($r) => ($vociPassate !== null || $latiDiversi) && ! $straordinaria ? '|lato:' . (self::latoProprietario($r) ? 'P' : 'A') : '';
        // Fase 1-ter della beta.41: con la regola delle righe fuori quota, le righe dello stesso conto risolte su ruoli
        // diversi (Ugo usufruttuario di una metà e proprietario pieno dell'altra) sono gruppi distinti.
        $ruolo = fn ($r) => $fuoriQuota !== null || $genealogia !== null ? '|ruolo:' . ($r->ruolo_risolto ?? '') : '';
        // Decisione 56: le parti di una riga con la genealogia — chi le ha (la ragione), e per una quota di un predecessore il
        // giorno in cui sono arrivate a chi esce — sono gruppi distinti.
        $parte = fn ($r, bool $conArrivo = true) => isset($r->legame) ? '|leg:' . ($arrivoPerParte && $conArrivo ? ($r->legame['arrivo'] ?? '') : '') . '|' . ($r->legame['esito'] ?? '') : '';
        // Fase 1-ter della beta.41 (la riga di ripiego): una riga di ripiego (ruolo risolto diverso da quello chiesto: la parte «Inquilino» di una voce
        // che, finita la locazione, ricade sul proprietario) ha i suoi giorni, ricostruiti togliendo quelli degli altri ruoli
        // (`PeriodoDellaRiga::senzaGliAltri`). Nello stesso gruppo della parte chiesta al «Proprietario» la ricostruzione valeva
        // per tutte e due, e la quota intera si divideva sui giorni del ripiego. È un gruppo suo.
        $ripiego = fn ($r) => ($r->ruolo_risolto ?? null) !== null && ($r->ruolo_richiesto ?? null) !== null && $r->ruolo_risolto !== $r->ruolo_richiesto ? '|ripiego:' . $r->ruolo_richiesto : '';
        $chiave = fn ($r, bool $conArrivo = true) => (int) ($r->conto_id ?? 0) . '|' . ($straordinaria || $dichiarata($r) ? $giorno($r->competenza_dal) . '|' . $giorno($r->competenza_al) : '') . '|' . $giorno($r->titolarita_dal) . '|' . $giorno($r->titolarita_al) . $perRiga($r) . $lato($r) . $ruolo($r) . $ripiego($r) . (! empty($r->non_arrivata) ? '|non_arrivata' : '') . $parte($r, $conArrivo);
        // Rilievo G5 del giro sulle correzioni: `groupBy` passa anche l'indice, che finiva nel secondo parametro di `$chiave`.
        $gruppi = $lordi->groupBy(fn ($r) => $chiave($r));
        // Netting per conto (persona / unità), attribuito ai gruppi del conto in proporzione ai lordi.
        $nettingPerConto = [];
        foreach ($nettingRighe->groupBy(fn ($r) => (int) ($r->conto_id ?? 0)) as $contoId => $g) {
            $tot = (int) abs((int) $g->sum('importo'));
            $persona = min($tot, (int) abs((int) $g->where('riga_descrizione', CalcoloQuoteService::NETTING_DELLA_PERSONA)->sum('importo')));
            $gruppiDelConto = $gruppi->filter(fn ($gr, $k) => (int) explode('|', (string) $k)[0] === (int) $contoId)->keys()->all();
            if ($gruppiDelConto === []) {
                // Netting senza lordo (conto interamente versato con lordo su un'altra chiamata): un gruppo a sé, a zero.
                $gruppi[(int) $contoId . '|netting'] = $g->take(0);
                $gruppiDelConto = [(int) $contoId . '|netting'];
            }
            $pesiLordi = array_map(fn ($k) => (float) abs((int) $gruppi[$k]->sum('importo')), $gruppiDelConto);
            $distribuito = MoneyHelper::ripartisciPerQuote($tot, array_combine($gruppiDelConto, $pesiLordi));
            $distribuitoPersona = MoneyHelper::ripartisciPerQuote($persona, array_combine($gruppiDelConto, $pesiLordi));
            foreach ($gruppiDelConto as $k) {
                $nettingPerConto[$k] = ['netting' => (int) ($distribuito[$k] ?? 0), 'persona' => (int) ($distribuitoPersona[$k] ?? 0), 'conto' => $g->first()];
            }
        }

        $conti = [];
        $latiPresenti = [];
        $acquistoComune = $decorrenzaAcquisto;
        // Rilievo A7 della Fase 1-bis della .43: le parti della stessa riga arrivate a chi esce in giorni diversi (un gruppo per
        // giorno d'arrivo) passano a chi entra per gli stessi giorni. Si arrotondano una volta sola, sulla riga, e poi si dividono
        // fra le parti con i resti maggiori: come DV1 (decisione 59, punto 1) per le quote con più intestatari.
        $famiglie = [];
        foreach ($gruppi as $k => $gruppo) {
            $primo = $gruppo->first() ?? $nettingPerConto[$k]['conto'];
            if ($latiDiversi && $gruppo->isNotEmpty()) {
                $latiPresenti[self::latoProprietario($primo) ? 'P' : 'A'] = true;
            }
            // Rilievo D5: le voci sul «Proprietario» e le altre possono essere arrivate a chi esce in giorni diversi (una
            // riserva «come la voce» il 1/05, l'estinzione il 1/09): la data d'acquisto è quella del lato della riga.
            $decorrenzaAcquisto = $acquistoComune;
            if ($acquistoComune !== null && $decorrenzeLato !== null && ! $straordinaria && ($d = $decorrenzeLato[self::latoProprietario($primo) ? 'P' : 'A'] ?? null) !== null) {
                $decorrenzaAcquisto = CarbonImmutable::parse($d);
            }
            // Decisione 56: la parte è arrivata a chi esce il giorno che la genealogia dice.
            if ($arrivoPerParte && $acquistoComune !== null && ($primo->legame['arrivo'] ?? null) !== null) {
                $decorrenzaAcquisto = CarbonImmutable::parse($primo->legame['arrivo']);
            }
            $contoId = (int) ($primo->conto_id ?? 0);
            $lordo = (int) $gruppo->sum('importo');
            $netting = (int) ($nettingPerConto[$k]['netting'] ?? 0);
            $np = (int) ($nettingPerConto[$k]['persona'] ?? 0);
            $nu = $netting - $np;
            $importo = $lordo - $netting;

            if ($straordinaria) {
                $rigaConCompetenza = $primo->competenza_dal !== null && $primo->competenza_al !== null;
                $competenza = $rigaConCompetenza ? InsiemePeriodi::uno(new PeriodoCompetenza($giorno($primo->competenza_dal), $giorno($primo->competenza_al))) : $base;
                $gradino = $rigaConCompetenza ? ($primo->gradino_competenza ?: 'dichiarata') : ($base !== null ? 'delibera' : null);
            } elseif ($dichiarata($primo)) {
                $competenza = InsiemePeriodi::uno(new PeriodoCompetenza($giorno($primo->competenza_dal), $giorno($primo->competenza_al)));
                $gradino = 'dichiarata';
            } else {
                $tratto = $tratti[$contoId] ?? $tratti[(int) ($primo->conto_radice_id ?? 0)] ?? null;
                $competenza = $tratto ?? $base;
                $gradino = $tratto !== null ? 'capitolo' : 'base';
            }
            // Migrazione 11: la riga copre solo il suo tratto di titolarità — si divide su competenza ∩ tratto. Se il
            // tratto congelato non tocca la competenza di oggi (competenza del piano cambiata dopo l'emissione) la voce
            // non è risolta e lo dice: ripiegare in silenzio sull'intera competenza divideva giorni che la riga non
            // copre (strada b, B2-4). Le righe senza tratto (pre-migrazione 11) restano sulla competenza intera.
            $trattoRiga = $primo->titolarita_dal !== null && $primo->titolarita_al !== null ? new PeriodoCompetenza($giorno($primo->titolarita_dal), $giorno($primo->titolarita_al)) : null;
            $competenzaRiga = $competenza !== null && $trattoRiga !== null ? $competenza->intersezione($trattoRiga) : $competenza;
            $trattoFuoriCompetenza = $competenza !== null && $trattoRiga !== null && $competenzaRiga === null;
            // Riga di ripiego (decisione 22) con i giorni senza titolare in due buchi: il tratto congelato è l'estensione
            // (tutto l'anno) e i giorni veri sono meno. Si tolgono i tratti delle righe risolte sugli altri ruoli; se i
            // giorni ancora non tornano la voce non si divide (Fase 1-bis della beta.34, R5: era un difetto della beta.31,
            // che dava a chi entra 245/365 di una riga che copriva 120 giorni).
            $trattoNonRicostruibile = false;
            if ($competenzaRiga !== null && $trattoRiga !== null && ($primo->ruolo_risolto ?? null) !== null && $primo->ruolo_risolto !== $primo->ruolo_richiesto) {
                $ricostruito = PeriodoDellaRiga::senzaGliAltri($competenzaRiga, $primo, $righeUnita);
                $trattoNonRicostruibile = $ricostruito === null;
                $competenzaRiga = $ricostruito;
            }

            // Decisione 31.5: una voce che con «come la voce» non passa. Di chi esce, resta sua tutta. Di un predecessore, resta a
            // chi esce dal giorno in cui l'ha avuta, se gli era arrivata; se un passaggio prima l'aveva tenuta, niente.
            $nonPassa = $vociPassate !== null && ! $straordinaria && $primo !== null && ! self::passaPerVoce($primo, $vociPassate);
            // Fase 1-ter della beta.41: la riga è di un'altra quota — di un ruolo che chi esce tiene, o, per un
            // predecessore, che non è mai arrivato a chi esce. Di chi esce resta sua tutta; di un predecessore, niente.
            $motivoQuota = ! empty($primo->non_arrivata) ? 'mai_arrivata:' . (is_string($primo->non_arrivata) ? $primo->non_arrivata : 'altri_nudi')
                : (isset($primo->legame) ? ($primo->legame['esito'] ?? null) : ($fuoriQuota !== null && $primo !== null ? $fuoriQuota($primo) : null));
            $fuori = $motivoQuota !== null;

            if ($fuori) {
                $parti = ['uscente' => $decorrenzaAcquisto === null ? $lordo - $nu : 0, 'entrante' => 0, 'giorni_uscente' => null, 'giorni_entrante' => null, 'giorni_periodo' => null, 'giorni_predecessore' => null];
            } elseif ($nonPassa) {
                $arrivata = $decorrenzaAcquisto === null || $vociArrivate === null || self::passaPerVoce($primo, $vociArrivate);
                $parteUscente = match (true) {
                    $decorrenzaAcquisto === null => $lordo - $nu,
                    $arrivata && $competenzaRiga !== null => (int) ProRataTemporis::dividi($lordo - $nu, $competenzaRiga, $decorrenzaAcquisto)['entrante'],
                    default => 0,
                };
                $parti = ['uscente' => $parteUscente, 'entrante' => 0, 'giorni_uscente' => null, 'giorni_entrante' => null, 'giorni_periodo' => null, 'giorni_predecessore' => null];
            } elseif ($competenzaRiga === null) {
                $parti = ['uscente' => $lordo - $nu, 'entrante' => 0, 'giorni_uscente' => null, 'giorni_entrante' => null, 'giorni_periodo' => null, 'giorni_predecessore' => null];
            } elseif ($decorrenzaAcquisto !== null) {
                // Predecessore (S8-3): a chi entra dalla decorrenza in poi; a chi esce fra il suo acquisto e la decorrenza.
                $adesso = ProRataTemporis::dividi($lordo - $nu, $competenzaRiga, $decorrenza);
                $acquisto = ProRataTemporis::dividi($lordo - $nu, $competenzaRiga, $decorrenzaAcquisto);
                if ($arrivoPerParte && isset($primo->legame)) {
                    $famiglie[$chiave($primo, false)][] = ['indice' => count($conti), 'netto' => $lordo - $nu, 'acquisto' => (int) $acquisto['entrante'], 'competenza' => $competenzaRiga];
                }
                $parti = [
                    'uscente'         => max(0, $acquisto['entrante'] - $adesso['entrante']),
                    'entrante'        => min($adesso['entrante'], $acquisto['entrante']),
                    'giorni_uscente'  => max(0, $acquisto['giorni_entrante'] - $adesso['giorni_entrante']),
                    'giorni_entrante' => min($adesso['giorni_entrante'], $acquisto['giorni_entrante']),
                    'giorni_periodo'  => $acquisto['giorni_entrante'],
                    // I giorni prima dell'acquisto: restano al predecessore, e le frasi voce per voce li dicono (B2-1).
                    'giorni_predecessore' => $acquisto['giorni_uscente'],
                ];
            } else {
                $parti = ProRataTemporis::dividi($lordo - $nu, $competenzaRiga, $decorrenza) + ['giorni_predecessore' => null];
            }
            $conti[] = [
                'conto_id'        => $contoId,
                // Una riga addebitata direttamente all'unità non ha un conto: il nome è la sua descrizione. Senza, la frase voce
                // per voce usciva «· : € 500,00» — sulla straordinaria da sempre, sull'ordinaria dalla decisione 26 (R6 della
                // Fase 1-bis).
                'conto'           => $primo->conto_nome
                    ?? (($primo->tipo ?? null) === RigaRiparto::TIPO_AD_PERSONAM && filled($primo->riga_descrizione ?? null) ? $primo->riga_descrizione : 'Addebito diretto all\'unità'),
                'importo'         => $importo,
                'importo_formattato' => MoneyHelper::format($importo),
                'versato_uscente' => $np,
                'gradino'         => $gradino,
                'periodo'         => $competenzaRiga?->toArray(),
                'tratto'          => $trattoRiga?->toArray(),
                'non_risolta'     => $competenzaRiga === null && ! $nonPassa && ! $fuori,
                // Fase 1-ter della beta.41: la riga è di un'altra quota, e le frasi lo dicono. Come per la scelta «come la voce», l'altra
                // quota è la ragione solo se la competenza della riga arriva al giorno dell'atto: una riga di gennaio, quando chi
                // vende la nuda era ancora proprietario pieno, non passerebbe comunque, e non trattiene le bozze.
                'resta_per_quota' => $restaPerQuota = $fuori && $importo !== 0 && ($competenzaRiga === null || $competenzaRiga->al()->gte($decorrenza)),
                'motivo_quota'    => $restaPerQuota ? $motivoQuota : null,
                // Decisione 31.5: la voce resta dove la scelta «come la voce» la lascia. La scelta è la ragione solo se la
                // competenza arriva al giorno dell'atto; se finisce prima, la voce non passerebbe comunque.
                'resta_per_voce'  => $nonPassa && $competenzaRiga !== null && $competenzaRiga->al()->gte($decorrenza),
                // Rilievo D6: con «come la voce» le due parti di una voce divisa sono due righe con lo stesso nome.
                'parte'           => ($vociPassate !== null || $latiDiversi) && ! $straordinaria ? (self::latoProprietario($primo) ? 'parte sul «Proprietario»' : 'parte sugli altri ruoli') : null,
                'ruolo_risolto'   => $primo->ruolo_risolto ?? null,
                'motivo'          => $trattoFuoriCompetenza ? 'tratto_fuori_competenza' : ($trattoNonRicostruibile ? 'tratto_non_ricostruibile' : null),
                'competenza_oggi' => $trattoFuoriCompetenza ? $competenza->toArray() : null,
                'giorni_uscente'  => $parti['giorni_uscente'],
                'giorni_entrante' => $parti['giorni_entrante'],
                'giorni_periodo'  => $parti['giorni_periodo'],
                'giorni_predecessore' => $parti['giorni_predecessore'],
                'uscente'         => $parti['uscente'] - ($decorrenzaAcquisto !== null ? 0 : $np),
                'entrante'        => $parti['entrante'],
                'entrante_formattato' => MoneyHelper::format($parti['entrante']),
                // Decisione 56: il giorno in cui la parte è arrivata a chi esce (quota di un predecessore), e all'estinzione quanto
                // della parte di chi entra va a ciascun nudo proprietario.
                'arrivo'          => $arrivoPerParte ? ($primo->legame['arrivo'] ?? null) : null,
                'pesi_nudo'       => ($pesiNudo = $gruppo->reduce(function (array $pesi, $x) {
                    foreach ($x->legame['nudi'] ?? [] as $chi => $f) {
                        $pesi[(int) $chi] = ($pesi[(int) $chi] ?? 0.0) + abs((int) $x->importo) * (float) $f;
                    }

                    return $pesi;
                }, [])) === [] || array_sum($pesiNudo) <= 0 ? null : array_map(fn ($w) => (int) $parti['entrante'] * $w / array_sum($pesiNudo), $pesiNudo),
                // La competenza della riga finisce prima dell'atto e niente passa: per decidere se la quota è tutta di un'altra
                // quota questa riga non conta (rilievo T-B4 della revisione della Fase 1-ter).
                'finita_prima'    => ! $fuori && ! $nonPassa && $competenzaRiga !== null && $competenzaRiga->al()->lt($decorrenza) && (int) $parti['entrante'] === 0,
            ];
        }

        foreach ($famiglie as $famiglia) {
            $netti = array_column($famiglia, 'netto');
            if (count($famiglia) < 2 || (min($netti) < 0 && max($netti) > 0)) {
                continue;
            }
            $entrante = (int) ProRataTemporis::dividi((int) array_sum($netti), $famiglia[0]['competenza'], $decorrenza)['entrante'];
            $quote = MoneyHelper::ripartisciPerQuote($entrante, array_map(fn ($m) => (float) abs($m['netto']), $famiglia));
            foreach ($famiglia as $j => $m) {
                $c = &$conti[$m['indice']];
                $prima = (int) $c['entrante'];
                $c['entrante'] = min((int) $quote[$j], $m['acquisto']);
                $c['uscente'] = max(0, $m['acquisto'] - $c['entrante']);
                $c['entrante_formattato'] = MoneyHelper::format($c['entrante']);
                if ($c['pesi_nudo'] !== null && $prima !== 0) {
                    $c['pesi_nudo'] = array_map(fn ($w) => $w * $c['entrante'] / $prima, $c['pesi_nudo']);
                }
                unset($c);
            }
        }

        $gradini = array_values(array_unique(array_filter(array_column($conti, 'gradino'))));
        // I giorni a livello di quota, quando sono gli stessi per ogni riga (ordinario tutto sulla base, un tratto).
        // Seconda revisione della Fase 1-ter (T2-11, B9): il nome della parte serve solo a distinguere le righe della stessa voce.
        // Una voce che compare una volta sola non è divisa, e non lo dice; due righe della stessa voce che la scelta non distingue
        // (l'unità mista: la metà piena e l'usufrutto) si distinguono per la parte dell'unità.
        $quante = array_count_values(array_map(fn ($c) => (int) $c['conto_id'] . '|' . ($c['conto'] ?? ''), $conti));
        // Decisione 56: due parti della stessa voce arrivate a chi esce in giorni diversi si distinguono per il giorno.
        $arrivi = [];
        foreach ($conti as $c) {
            $arrivi[(int) $c['conto_id'] . '|' . ($c['conto'] ?? '')][(string) ($c['arrivo'] ?? '')] = true;
        }
        foreach ($conti as &$c) {
            $doppia = ($quante[(int) $c['conto_id'] . '|' . ($c['conto'] ?? '')] ?? 1) > 1;
            if (! $doppia) {
                $c['parte'] = null;
            } elseif (empty($c['parte']) && ($c['arrivo'] ?? null) !== null && count($arrivi[(int) $c['conto_id'] . '|' . ($c['conto'] ?? '')]) > 1) {
                $c['parte'] = 'la parte arrivata il ' . $this->data($c['arrivo']);
            } elseif (empty($c['parte']) && ($c['ruolo_risolto'] ?? null) !== null) {
                $c['parte'] = 'per ' . self::parteInParole(':' . $c['ruolo_risolto']);
            }
        }
        unset($c);
        $giorniDistinti = array_unique(array_map(fn ($c) => json_encode([$c['giorni_uscente'], $c['giorni_entrante'], $c['giorni_periodo']]), $conti));
        $giorni = count($giorniDistinti) === 1 ? ['giorni_uscente' => $conti[0]['giorni_uscente'], 'giorni_entrante' => $conti[0]['giorni_entrante'], 'giorni_periodo' => $conti[0]['giorni_periodo']] : ['giorni_uscente' => null, 'giorni_entrante' => null, 'giorni_periodo' => null];
        $versato = (int) array_sum(array_column($conti, 'versato_uscente'));
        $nonRisolte = count(array_filter($conti, fn ($c) => $c['non_risolta']));
        $contiDistinti = count(array_unique(array_column($conti, 'conto_id')));
        $periodi = [];
        foreach ($conti as $c) {
            foreach ($c['periodo'] ?? [] as $t) {
                $periodi[$t['dal'] . '|' . $t['al']] = $t;
            }
        }
        ksort($periodi);

        $perNudo = [];
        foreach ($conti as $c) {
            foreach ($c['pesi_nudo'] ?? [] as $chi => $w) {
                $perNudo[(int) $chi] = ($perNudo[(int) $chi] ?? 0.0) + (float) $w;
            }
        }

        return [
            'totale'   => (int) array_sum(array_column($conti, 'importo')),
            'uscente'  => (int) array_sum(array_column($conti, 'uscente')),
            'entrante' => (int) array_sum(array_column($conti, 'entrante')),
            // Decisione 56: all'estinzione, quanto della parte di chi entra va a ciascun nudo proprietario, senza arrotondare (vuoto
            // senza genealogia): la coppia si divide una volta sola, con i resti maggiori, come la divisione per quota.
            'pesi_nudo' => $perNudo,
            'versato_uscente' => $versato,
            'non_risolte' => $nonRisolte,
            // Un gradino solo quando è uno; con più gradini (dichiarata + delibera) si dice il primo e le righe dicono il resto.
            'gradino'  => $straordinaria ? ($gradini[0] ?? null) : (in_array('dichiarata', $gradini, true) ? 'dichiarata' : 'capitolo'),
            // Decisione 26: almeno una voce si divide sulla competenza della sua fattura (anche sull'ordinaria).
            'competenza_per_fattura' => $straordinaria || in_array('dichiarata', $gradini, true),
            // Sull'ordinaria, voci dichiarate accanto ad altre (o su periodi diversi): la testa non ha un periodo solo da
            // mostrare, e l'anteprima dice «voce per voce» invece di «competenza dichiarata» con un intervallo che non è
            // di nessuna voce (R5 della Fase 1-bis). Sulla straordinaria resta la regola di S8-4 (il primo gradino).
            'voce_per_voce' => ! $straordinaria && in_array('dichiarata', $gradini, true) && (count($gradini) > 1 || count($periodi) > 1),
            'periodo'  => $periodi === [] ? null : array_values($periodi),
            'giorni'   => $giorni,
            // Il dettaglio voce per voce si mostra solo quando dice qualcosa che la testa non dice: una voce con
            // competenza propria, lo straordinario per riga, un versato di chi esce, una voce non risolta, la stessa
            // voce su due tratti (L1-1). Un ordinario tutto sulla base si legge come prima («divisa in proporzione ai
            // giorni: N a X, M a Y»).
            'resta_per_voce' => in_array(true, array_column($conti, 'resta_per_voce'), true),
            'fuori_quota' => in_array(true, array_column($conti, 'resta_per_quota'), true),
            // Tutta di un'altra quota: ogni conto che sposta denaro, o potrebbe, è di un'altra quota. Contano i conti con una parte
            // a chi entra anche se l'importo è zero (tutto già versato: la divisione è al lordo, rilievo S-R1), le voci non risolte
            // e quelle con un importo la cui competenza arriva all'atto; non quelle finite prima, che non passano comunque (T-B4).
            'tutta_fuori' => ($conDenaro = array_filter($conti, fn ($c) => (int) $c['entrante'] !== 0 || ! empty($c['non_risolta']) || ! empty($c['resta_per_quota'])
                || ((int) $c['importo'] !== 0 && empty($c['finita_prima'])))) !== [] && ! in_array(false, array_map(fn ($c) => ! empty($c['resta_per_quota']), $conDenaro), true),
            // Con le date per lato diverse, i lati che le voci di questa quota occupano (la data della frase dipende da questi).
            'lati'     => $latiDiversi ? array_keys($latiPresenti) : null,
            'dettagliato' => $straordinaria || in_array('capitolo', $gradini, true) || in_array('dichiarata', $gradini, true) || $versato > 0 || $nonRisolte > 0 || count($conti) > $contiDistinti
                || in_array(true, array_column($conti, 'resta_per_quota'), true),
            'conti'    => $conti,
        ];
    }

    /**
     * Le frasi del blocco 2 dell'anteprima: una riga d'apertura, poi una per gestione (e per periodo, quando i piani
     * della stessa gestione hanno competenze diverse — verifica S5, R10), poi le non risolte e i piani con l'esercizio
     * dedotto. I testi sono quelli del §6 del progetto.
     *
     * @param list<array{piano: string, motivo: string}> $nonRisolte
     * @param list<string> $dedotti
     * @param list<array{piano: string, intestatario: string, n: int}> $inBozza
     */
    private function frasi(Collection $perGestione, array $nonRisolte, string $uscente, ?string $entrante, CarbonImmutable $decorrenza, bool $soloOrdinario, bool $soloStraordinario, array $pregressi, array $dedotti = [], array $inBozza = [], array $riassegnazione = [], array $entrantiPerUnita = []): array
    {
        $entrante ??= 'chi entra';
        $frasi = ['Le rate già emesse non si toccano. Il conguaglio è proposto come due righe di saldo che sommano a zero, per gestione:'];
        // Decisione 25 (B3a): le bozze che passano a chi entra, piano per piano — prima di tutto, perché cambiano il
        // numero che segue. Il pregresso dentro quelle bozze non passa e si dice.
        foreach ($riassegnazione as $r) {
            $frasi[] = $r['n'] === 1
                ? sprintf('La rata in bozza del piano «%s» con scadenza il %s passa a %s: cambia l\'intestatario, non l\'importo (%s di preventivo, che da ora paga a suo nome).', $r['piano'], $this->data($r['dal']), $entrante, $r['preventivo_formattato'])
                : sprintf('Le %d rate in bozza del piano «%s» con scadenza dal %s al %s passano a %s: cambia l\'intestatario, non l\'importo (%s di preventivo, che da ora paga a suo nome).', $r['n'], $r['piano'], $this->data($r['dal']), $this->data($r['al']), $entrante, $r['preventivo_formattato']);
            if ($r['pregresso'] !== 0) {
                $frasi[] = sprintf('Dentro %s c\'è %s di %s pregresso di %s: non passa, resta suo in una quota a parte sulla stessa rata.', $r['n'] === 1 ? 'questa rata' : 'queste rate', MoneyHelper::format(abs($r['pregresso'])), $r['pregresso'] > 0 ? 'debito' : 'credito', $uscente);
            }
        }
        // Decisione 21: le bozze dei piani già a giornale che restano a chi le ha sono comprese, e si dice quali e
        // perché — una riga a sé, perché l'anteprima sostituisce la riga d'apertura con la propria e tiene le altre.
        foreach ($inBozza as $b) {
            $frasi[] = $this->fraseBozzeTrattenute($b, $uscente, $decorrenza);
        }

        $entranteDelPassaggio = $entrante;
        foreach ($perGestione as $g) {
            $nome = $g['gestione'] ?? 'gestione';
            // U4 (decisione 59, 1.11.0-beta.43): chi entra può cambiare da un'unità all'altra del passaggio — nell'estinzione
            // dell'usufrutto il box ha il suo nudo proprietario —, e la frase lo nomina.
            $entrante = $g['entrante_nome'] ?? $entrantiPerUnita[(int) ($g['immobile_id'] ?? 0)] ?? $entranteDelPassaggio;
            // Decisione 25: con bozze che passano a chi entra la coppia è la sua parte dell'intero piano meno quelle.
            $cd = $this->creditoDebito((int) $g['importo_lordo'], (int) $g['bozze_passate_importo'], $uscente, $entrante);
            if ($g['non_risolte'] > 0 && $g['importo'] === 0) {
                continue; // la dice la riga delle non risolte
            }
            if ($g['escluse'] > 0 && $g['importo'] === 0) {
                // Testi T3 (beta.38): la frase nomina chi ha davvero le quote escluse — chi esce o un suo predecessore. Anche
                // la straordinaria (cantiere C6, referto C3): nelle catene con l'usufrutto le quote possono essere emesse a chi
                // aveva l'unità prima di chi esce.
                $chi = implode(' e ', $g['escluse_di'] ?: [$uscente]);
                $frasi[] = $g['natura'] === NaturaGestione::Straordinaria->value
                    ? sprintf('Sulla gestione %s (straordinaria): %s restano interamente a %s — le spese straordinarie sono del nudo proprietario (art. 1005 c.c.), il passaggio non le tocca.', $nome, MoneyHelper::format($g['quota_pura']), $chi)
                    : ($soloStraordinario
                        // Riserva d'usufrutto (decisione 28): chi vende resta usufruttuario e l'ordinaria la deve ancora. Senza
                        // pronomi (V3 della verifica a video): il programma non conosce il genere delle persone.
                        ? sprintf('Sulla gestione %s: %s restano a %s — sono spese ordinarie, e %s resta usufruttuario (art. 1004 c.c.): non passano a %s.', $nome, MoneyHelper::format($g['quota_pura']), $chi, $uscente, $entrante)
                        : ($g['riservata_da'] !== []
                            // Rilievo B3 e testi T3 (beta.38): la nuda proprietà di chi esce viene da una riserva. L'usufrutto è
                            // nato in quell'atto e non si è regolato niente: l'ordinaria non è mai passata a chi esce.
                            ? sprintf('Sulla gestione %s: %s restano a %s — sono spese ordinarie, e la vendita o donazione con riserva d\'usufrutto non le ha fatte passare a %s: %s resta usufruttuario (art. 1004 c.c.), e non passano nemmeno a %s.', $nome, MoneyHelper::format($g['quota_pura']), $chi, $uscente, implode(' e ', $g['riservata_da']), $entrante)
                            // R4: vendita della nuda proprietà, piano generato prima dell'usufrutto. «Non ne risponde» era falso
                            // verso il condominio (art. 67 ult. co.): fra le parti le paga l'usufruttuario (decisione 28.4). Non «già
                            // regolate» (rilievo T-B5): una costituzione senza niente a giornale non scrive coppie (decisione 21).
                            : sprintf('Sulla gestione %s: %s restano a %s — sono spese ordinarie, e dalla costituzione dell\'usufrutto sono dell\'usufruttuario (art. 1004 c.c.): non passano a chi compra la nuda proprietà.', $nome, MoneyHelper::format($g['quota_pura']), $chi)));
                continue;
            }
            if ($g['natura'] === NaturaGestione::Straordinaria->value) {
                // Con più delibere sulla stessa gestione, ciascuna con la sua sorte (R10).
                foreach ($g['per_periodo'] as $p) {
                    if (($p['per_capitolo'] ?? null) !== null) {
                        // S8-4: la competenza è per riga (dichiarata sulla fattura, o la delibera): si leggono le voci.
                        // Con una quota del predecessore (S8-3) la testa lo nomina, e le voci dicono di chi era l'unità (B2-1).
                        // Giro sulle correzioni (G14): le quote del predecessore intestate all'erede di riferimento dicono a chi sono intestate.
                        $ereditata = ! empty($p['ereditata_da']) ? sprintf(' (emesse a %s: la competenza è passata a %s dal %s con un passaggio precedente)', self::emessaA($p), $uscente, $this->data($p['decorrenza_acquisto'])) : '';
                        $frasi[] = $p['entrante'] === 0
                            ? ($ereditata !== ''
                                ? sprintf('Sulla gestione %s (straordinaria): %s%s non passano a chi entra — la competenza di ogni voce, dichiarata sulla fattura o alla data della delibera, cade prima del passaggio:', $nome, MoneyHelper::format($p['quota_pura']), $ereditata)
                                : sprintf('Sulla gestione %s (straordinaria): %s restano a %s — la competenza di ogni voce, dichiarata sulla fattura o alla data della delibera, cade prima del passaggio:', $nome, MoneyHelper::format($p['quota_pura']), $uscente))
                            : sprintf('Sulla gestione %s (straordinaria): %s%s — voce per voce, ognuna sulla sua competenza (dichiarata sulla fattura, o la data della delibera; art. 63 disp. att. c.c.):', $nome, $this->creditoDebito((int) $p['entrante'], (int) $p['passate'], $uscente, $entrante), $ereditata);
                        array_push($frasi, ...$this->frasiPerCapitolo($p['per_capitolo'], $uscente, $entrante, $decorrenza, true, $p['ereditata_da'] ?? null, $p['intestato_a'] ?? null));
                        continue;
                    }
                    $delibera = $p['periodo'][0]['dal'] ?? null;
                    // Cantiere C6 (referto C3): con le quote di un predecessore (S8-3) «restano a chi esce, quando l'unità era sua»
                    // era falso due volte — sono emesse al predecessore, e se la delibera viene prima del suo passaggio a chi
                    // esce l'unità era del predecessore. La frase dice a chi sono emesse e di chi era l'unità alla delibera.
                    $eraDi = ! empty($p['ereditata_da']) && $delibera !== null && ! empty($p['decorrenza_acquisto']) && $delibera < $p['decorrenza_acquisto'] ? $p['ereditata_da'] : $uscente;
                    $frasi[] = $p['entrante'] === 0
                        ? (! empty($p['ereditata_da'])
                            ? sprintf('Sulla gestione %s (straordinaria): le quote emesse a %s (%s) non passano a chi entra: l\'assemblea ha deliberato il %s, quando l\'unità era di %s (art. 63 disp. att. c.c.; Cass. civ. 30 agosto 2025 n. 24236).', $nome, self::emessaA($p), MoneyHelper::format($p['quota_pura']), $this->data($delibera), $eraDi)
                            : sprintf('Sulla gestione %s (straordinaria): %s restano interamente a %s, perché l\'assemblea ha deliberato il %s, quando l\'unità era sua (art. 63 disp. att. c.c.; Cass. civ. 30 agosto 2025 n. 24236).', $nome, MoneyHelper::format($p['quota_pura']), $uscente, $this->data($delibera)))
                        : sprintf('Sulla gestione %s (straordinaria): %s — la delibera del %s è del giorno del passaggio o successiva, la spesa è di chi entra (art. 63 disp. att. c.c.; Cass. 24654/2010).', $nome, $this->creditoDebito((int) $p['entrante'], (int) $p['passate'], $uscente, $entrante), $this->data($delibera));
                }
                continue;
            }
            $dichiarataInTesta = collect($g['per_periodo'])->contains(fn ($p) => collect($p['per_capitolo'] ?? [])->contains(fn ($c) => ($c['gradino'] ?? null) === 'dichiarata'));
            if ($dichiarataInTesta && $g['importo'] === 0 && $g['bozze_passate'] === 0 && count($g['per_periodo']) === 1 && empty($g['per_periodo'][0]['ereditata_da'])) {
                // Decisione 26: la competenza dichiarata sulla fattura cade tutta prima del passaggio (la pregressa dell'anno
                // prima): la voce è di chi esce, e le righe voce per voce dicono quale fattura e quale periodo.
                $frasi[] = sprintf('Sulla gestione %s: nessun conguaglio — %s restano a %s: la competenza dichiarata sulla fattura cade prima del passaggio.', $nome, MoneyHelper::format($g['quota_pura']), $uscente);
                array_push($frasi, ...$this->frasiPerCapitolo($g['per_periodo'][0]['per_capitolo'], $uscente, $entrante, $decorrenza, false));
                continue;
            }
            if ($g['importo'] === 0 && $g['bozze_passate'] === 0) {
                // Con giorni di chi entra > 0 la ragione non è la competenza: la quota pura è zero (versato dell'unità
                // al 100 %, D8, o solo saldi pregressi) e non c'è nulla da dividere (L1-3). Gli intestatari sono quelli
                // veri del gruppo — chi esce o un suo predecessore (L1-8).
                $intestatari = collect($g['per_periodo'])->map(fn ($p) => $p['intestato_a'] ?? $p['ereditata_da'] ?? $uscente)->unique()->values()->all();
                $chi = $intestatari === [] ? $uscente : implode(' e ', $intestatari);
                if (! empty($g['tutta_fuori']) && (int) $g['quota_pura'] !== 0) {
                    // Fase 1-ter della beta.41: le quote sono di una parte dell'unità che non passa con questo atto. Con un motivo
                    // solo la testa basta; con più motivi le righe voce per voce dicono quale.
                    $motivi = $g['motivi_quota'];
                    $frasi[] = $motivi === ['usufruttuario']
                        ? sprintf('Sulla gestione %s: nessun conguaglio — le quote emesse a %s (%s) sono spese ordinarie, e dalla costituzione dell\'usufrutto sono dell\'usufruttuario (art. 1004 c.c.): non passano a chi compra la nuda proprietà.', $nome, $chi, MoneyHelper::format($g['quota_pura']))
                        : sprintf('Sulla gestione %s: nessun conguaglio — le quote emesse a %s (%s) sono %s: non passano a %s.', $nome, $chi, MoneyHelper::format($g['quota_pura']), $this->altraParte($motivi, $uscente), $entrante);
                    if (count($motivi) > 1 && count($g['per_periodo']) === 1 && ($g['per_periodo'][0]['per_capitolo'] ?? null) !== null) {
                        array_push($frasi, ...$this->frasiPerCapitolo($g['per_periodo'][0]['per_capitolo'], $uscente, $entrante, $decorrenza, false, $g['per_periodo'][0]['ereditata_da'] ?? null, $g['per_periodo'][0]['intestato_a'] ?? null));
                    }
                    continue;
                }
                $frasi[] = match (true) {
                    // Decisione 31.5 (Fase 1-bis della beta.41): la ragione è la scelta, non la competenza, che è tutto l'anno.
                    ! empty($g['per_voce']) && (int) $g['quota_pura'] !== 0 => sprintf('Sulla gestione %s: nessun conguaglio — l\'ordinaria segue la voce, per la scelta «come dice ogni voce»: le voci delle quote emesse a %s (%s) non passano a %s.', $nome, $chi, MoneyHelper::format($g['quota_pura']), $entrante),
                    ($g['giorni_entrante'] ?? 0) > 0 || (int) $g['quota_pura'] === 0 => sprintf('Sulla gestione %s: nessun conguaglio — la quota emessa a %s è %s (interamente coperta dal già versato dell\'unità, o fatta solo di saldi pregressi): non c\'è nulla da dividere.', $nome, $chi, MoneyHelper::format($g['quota_pura'])),
                    default => sprintf('Sulla gestione %s: nessun conguaglio — la competenza delle quote emesse a %s (%s) finisce prima del %s.', $nome, $chi, MoneyHelper::format($g['quota_pura']), $this->data($decorrenza->toDateString())),
                };
                continue;
            }
            if (count($g['per_periodo']) === 1 && ($g['per_periodo'][0]['per_capitolo'] ?? null) === null) {
                $p0 = $g['per_periodo'][0];
                // Decisione 31.5 senza righe: si divide per giorni la sola parte delle voci che passano, letta dalla ricostruzione.
                // Fase 1-ter della beta.41: anche la parte di un'altra quota (un ruolo che chi esce tiene, l'ordinaria dell'usufruttuario)
                // resta fuori dalla divisione, e la frase lo dice come per le voci.
                $fuoriParte = ! empty($g['fuori_quota']) && empty($g['tutta_fuori']);
                $soloLeVoci = match (true) {
                    ! empty($g['per_voce']) && $fuoriParte => sprintf(' Per la scelta «come dice ogni voce» si divide solo la parte delle voci che passano, senza quella %s: %s su %s; il resto non passa a %s.', $this->altraParte($g['motivi_quota'], $uscente), MoneyHelper::format($g['quota_che_passa']), MoneyHelper::format($g['quota_pura']), $entrante),
                    ! empty($g['per_voce']) => sprintf(' Per la scelta «come dice ogni voce» si divide solo la parte delle voci che passano, %s su %s; il resto non passa a %s.', MoneyHelper::format($g['quota_che_passa']), MoneyHelper::format($g['quota_pura']), $entrante),
                    $fuoriParte => sprintf(' Si divide solo la parte della quota che passa, %s su %s; il resto è %s, e non passa a %s.', MoneyHelper::format($g['quota_che_passa']), MoneyHelper::format($g['quota_pura']), $this->altraParte($g['motivi_quota'], $uscente), $entrante),
                    default => '',
                };
                if (! empty($p0['ereditata_da']) && ! empty($p0['acquisto_per_lato'])) {
                    // Rilievo D5: le voci sono arrivate a chi esce in due giorni; i suoi giorni non sono uno solo, quelli di chi
                    // entra sì.
                    $frasi[] = sprintf('Sulla gestione %s: %s — la quota ordinaria (%s su %d %s) è emessa a %s: la sua competenza è passata a %s %s, con passaggi precedenti; a %s va la parte dal giorno del passaggio (%d giorni).',
                        $nome, $cd, MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote',
                        self::emessaA($p0), $uscente, $this->dalAcquisto($p0), $entrante, (int) $g['giorni_entrante']) . $soloLeVoci;
                    continue;
                }
                if (! empty($p0['ereditata_da'])) {
                    // S8-3: la quota è emessa a un predecessore; passa la competenza che era passata a chi esce (L1-7: vale
                    // per vendita, locazione, usufrutto e per catene di qualunque lunghezza).
                    $frasi[] = sprintf('Sulla gestione %s: %s — la quota ordinaria (%s su %d %s) è emessa a %s: la sua competenza è passata a %s dal %s con un passaggio precedente, e quella parte (%d giorni) è divisa in proporzione ai giorni: %d a %s, %d a %s.',
                        $nome, $cd,
                        MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote',
                        self::emessaA($p0), $uscente, $this->data($p0['decorrenza_acquisto']), (int) $p0['giorni_periodo'],
                        (int) $g['giorni_uscente'], $uscente, (int) $g['giorni_entrante'], $entrante) . $soloLeVoci;
                    continue;
                }
                if (! empty($p0['passata_il'])) {
                    // R11: la quota è di chi esce dal giorno del passaggio precedente; si dividono solo i suoi giorni.
                    $frasi[] = sprintf('Sulla gestione %s: %s — la quota ordinaria (%s su %d %s) è passata a %s il %s con il passaggio precedente, e quella parte (%d giorni) è divisa in proporzione ai giorni: %d a %s, %d a %s.',
                        $nome, $cd, MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote',
                        $uscente, $this->data($p0['passata_il']), (int) $p0['giorni_periodo'], (int) $g['giorni_uscente'], $uscente, (int) $g['giorni_entrante'], $entrante) . $soloLeVoci;
                    continue;
                }
                $frasi[] = sprintf('Sulla gestione %s: %s — la quota ordinaria (%s su %d %s) è divisa in proporzione ai giorni di competenza: %d a %s, %d a %s.',
                    $nome, $cd,
                    MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote',
                    (int) $g['giorni_uscente'], $uscente, (int) $g['giorni_entrante'], $entrante) . $soloLeVoci;
                continue;
            }
            if (count($g['per_periodo']) === 1) {
                // Voci con una competenza propria: la divisione è per conto, e si leggono i conti. Con una quota del
                // predecessore (S8-3) la testa dice a chi è emessa e da quando la competenza è di chi esce (B2-1).
                $p0 = $g['per_periodo'][0];
                $frasi[] = ! empty($p0['ereditata_da'])
                    ? sprintf('Sulla gestione %s: %s — la quota ordinaria (%s su %d %s) è emessa a %s: la sua competenza è passata a %s %s, e quella parte è divisa voce per voce, ognuna sui giorni della sua competenza:',
                        $nome, $cd, MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote', self::emessaA($p0), $uscente, $this->dalAcquisto($p0) . (! empty($p0['acquisto_per_lato']) ? ', con passaggi precedenti' : ' con un passaggio precedente'))
                    : sprintf('Sulla gestione %s: %s — la quota ordinaria (%s su %d %s) è divisa voce per voce, ognuna sui giorni della sua competenza:',
                        $nome, $cd, MoneyHelper::format($g['quota_pura']), $g['quote'], $g['quote'] === 1 ? 'quota' : 'quote');
                array_push($frasi, ...$this->frasiPerCapitolo($p0['per_capitolo'], $uscente, $entrante, $decorrenza, false, $p0['ereditata_da'] ?? null, $p0['intestato_a'] ?? null));
                continue;
            }
            // Più piani con competenze diverse sulla stessa gestione: una riga per periodo, così i giorni
            // che si leggono sono quelli che hanno prodotto l'importo (verifica S5, R10). Stesso periodo ma quote di
            // predecessori diversi o acquistate in date diverse (L1-6): non sono «piani con competenze diverse».
            $periodiDistinti = count(array_unique(array_map(fn ($p) => json_encode($p['periodo']), $g['per_periodo'])));
            $frasi[] = sprintf('Sulla gestione %s: %s, %s:', $nome, $cd, $periodiDistinti > 1 ? 'da piani con competenze diverse' : 'da quote emesse a intestatari diversi o acquistate in date diverse');
            foreach ($g['per_periodo'] as $p) {
                if (($p['per_capitolo'] ?? null) !== null) {
                    $emesseA = ! empty($p['ereditata_da']) ? sprintf(', emesse a %s; a %s %s', self::emessaA($p), $uscente, $this->dalAcquisto($p)) : '';
                    // Seconda revisione della Fase 1-ter (T2-10): un gruppo che non passa per niente non annuncia una divisione.
                    $frasi[] = (int) ($p['entrante'] ?? 0) === 0
                        ? sprintf('— %s (%d %s%s) restano a %s: nessuna parte passa a chi entra, voce per voce:', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', ! empty($p['ereditata_da']) ? ', emesse a ' . self::emessaA($p) : '', $p['intestato_a'] ?? $p['ereditata_da'] ?? $uscente)
                        : sprintf('— %s (%d %s%s) divisi voce per voce, ognuna sui giorni della sua competenza → %s a chi entra:', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $emesseA, $p['entrante_formattato']);
                    array_push($frasi, ...$this->frasiPerCapitolo($p['per_capitolo'], $uscente, $entrante, $decorrenza, false, $p['ereditata_da'] ?? null, $p['intestato_a'] ?? null));
                    continue;
                }
                $tratti = implode(' + ', array_map(fn ($t) => $this->data($t['dal']) . '–' . $this->data($t['al']), $p['periodo'] ?? []));
                if (! empty($p['ereditata_da'])) {
                    $tratti .= sprintf(', emesse a %s; a %s %s', self::emessaA($p), $uscente, $this->dalAcquisto($p));
                } elseif (! empty($p['passata_il'])) {
                    $tratti .= sprintf(', passate a %s il %s', $uscente, $this->data($p['passata_il']));
                }
                $frasi[] = $p['entrante'] === 0
                    ? (! empty($p['tutta_fuori']) && (int) $p['quota_pura'] !== 0
                        ? sprintf('— %s (%d %s, competenza %s) restano a %s: sono %s.', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $tratti, $p['intestato_a'] ?? $p['ereditata_da'] ?? $uscente, $this->altraParte($p['motivi_quota'], $uscente))
                    : (! empty($p['per_voce'])
                        ? sprintf('— %s (%d %s, competenza %s) restano a %s: l\'ordinaria segue la voce, per la scelta «come dice ogni voce».', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $tratti, $uscente)
                        : sprintf('— %s (%d %s, competenza %s) restano a %s: la competenza finisce prima del %s.', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $tratti, $uscente, $this->data($decorrenza->toDateString()))))
                    : (! empty($p['acquisto_per_lato'])
                        ? sprintf('— %s (%d %s, competenza %s): a %s la parte dal giorno del passaggio (%d giorni) → %s a chi entra.', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $tratti, $entrante, (int) $p['giorni_entrante'], $p['entrante_formattato'])
                        : sprintf('— %s (%d %s, competenza %s) divisi per giorni%s: %d a %s, %d a %s → %s a chi entra.', MoneyHelper::format($p['quota_pura']), $p['quote'], $p['quote'] === 1 ? 'quota' : 'quote', $tratti,
                        match (true) {
                            ! empty($p['per_voce']) => sprintf(' solo per la parte delle voci che passano (%s), per la scelta «come dice ogni voce»', MoneyHelper::format($p['quota_che_passa'])),
                            ! empty($p['fuori_quota']) => sprintf(' solo per la parte della quota che passa (%s; il resto è %s)', MoneyHelper::format($p['quota_che_passa']), $this->altraParte($p['motivi_quota'], $uscente)),
                            default => '',
                        },
                        (int) $p['giorni_uscente'], $uscente, (int) $p['giorni_entrante'], $entrante, $p['entrante_formattato']));
            }
        }

        foreach ($nonRisolte as $n) {
            $frasi[] = sprintf('Piano «%s»: %s. Nessun conguaglio proposto su quelle quote.', $n['piano'], $n['motivo']);
        }
        if ($dedotti !== []) {
            $frasi[] = sprintf('%s «%s» non %s in quale esercizio %s generat%s (piano di una versione precedente): l\'esercizio è stato dedotto dalla data di creazione.', count($dedotti) === 1 ? 'Il piano' : 'I piani', implode('», «', $dedotti), count($dedotti) === 1 ? 'ricorda' : 'ricordano', count($dedotti) === 1 ? 'è stato' : 'sono stati', count($dedotti) === 1 ? 'o' : 'i');
        }
        // R11: i pregressi per intestatario — quelli del predecessore non sono di chi esce.
        foreach ($pregressi as $intestatario => $importo) {
            $frasi[] = sprintf('%s delle quote intestate a %s sono saldi pregressi (conguagli di esercizi passati o di un passaggio precedente): non si dividono, restano suoi.', MoneyHelper::format($importo), $intestatario);
        }

        return $frasi;
    }

    /** A chi è emessa la quota di un predecessore: a lui, o all'erede di riferimento che la tiene (decisione 67, punto 1). */
    private static function emessaA(array $p): ?string
    {
        return ! empty($p['intestato_a']) && ! empty($p['ereditata_da']) ? sprintf('%s, erede di riferimento di %s', $p['intestato_a'], $p['ereditata_da']) : ($p['ereditata_da'] ?? null);
    }

    /** Fase 1-ter della beta.41: la parte dell'unità di un motivo `tiene:<ruolo>`, `ceduta:<ruolo>` o `mai_arrivata:<ruolo>`, in parole. */
    private static function parteInParole(string $motivo): string
    {
        return match (substr($motivo, (int) strpos($motivo, ':') + 1)) {
            'usufruttuario'     => 'l\'usufrutto',
            'nuda_proprietario' => 'la nuda proprietà',
            // Rilievo T-B11 della revisione della 1-ter: «la proprietà» si confondeva con la nuda proprietà.
            'proprietario'      => 'la piena proprietà',
            'inquilino'         => 'la locazione',
            // Rilievo T-B8: la parte del ruolo che un'estinzione con più nudi proprietari ha dato agli altri, non a chi esce.
            'altri_nudi'        => 'la parte dell\'usufrutto che l\'estinzione ha riunito alle altre quote di nuda proprietà',
            // Rilievo X3 della Fase 1-bis della .44: la parte che una successione ha dato agli altri eredi, o un accrescimento agli altri
            // usufruttuari — prima diceva dell'estinzione anche lì.
            'altri_eredi'       => 'la parte che la successione ha dato agli altri eredi',
            'altri_usufruttuari' => 'la parte dell\'usufrutto che l\'accrescimento ha dato agli altri usufruttuari',
            // Giro sulle correzioni (GC6): una parte passata per divisioni di tipo diverso (un'estinzione, poi una successione).
            'altri_titolari'    => 'la parte che i passaggi precedenti hanno dato ad altri titolari',
            default             => 'un altro ruolo',
        };
    }

    /**
     * Fase 1-ter della beta.41: che cos'è la parte di una quota che non passa, dai motivi di `$fuoriQuotaDi` — «della parte
     * dell'unità che X tiene (l'usufrutto)», «di una parte dell'unità che non è mai passata a X (la nuda proprietà)»,
     * «l'ordinaria dell'usufruttuario». Con motivi diversi, una frase che li comprende.
     *
     * @param list<string> $motivi
     */
    private function altraParte(array $motivi, string $uscente): string
    {
        $parti = fn (string $tipo) => implode(' e ', array_unique(array_map(fn ($m) => self::parteInParole($m), array_values(array_filter($motivi, fn ($m) => str_starts_with($m, $tipo . ':'))))));

        return match (true) {
            $motivi === ['usufruttuario'] => 'l\'ordinaria dell\'usufruttuario (art. 1004 c.c.)',
            $motivi !== [] && array_filter($motivi, fn ($m) => ! str_starts_with($m, 'tiene:')) === [] => sprintf('della parte dell\'unità che %s tiene (%s)', $uscente, $parti('tiene')),
            // Decisione 56: la parte che chi esce ha già ceduto con un passaggio di prima (la genealogia lo sa), non una che tiene.
            $motivi !== [] && array_filter($motivi, fn ($m) => ! str_starts_with($m, 'ceduta:')) === [] => sprintf('della parte dell\'unità che %s ha già ceduto con un passaggio precedente (%s)', $uscente, $parti('ceduta')),
            $motivi !== [] && array_filter($motivi, fn ($m) => ! str_starts_with($m, 'mai_arrivata:')) === [] => sprintf('di una parte dell\'unità che non è mai passata a %s (%s)', $uscente, $parti('mai_arrivata')),
            default => 'di parti dell\'unità che non passano con questo atto',
        };
    }

    /** Il nome di una voce nelle frasi voce per voce, con la parte quando «come la voce» divide una voce fra due ruoli. */
    private function nomeVoce(array $c): string
    {
        return ($c['conto'] ?? '') . (! empty($c['parte']) ? ' (' . $c['parte'] . ')' : '');
    }

    /**
     * Le righe «voce per voce». `$predecessore` è l'intestatario delle quote quando non è chi esce (S8-3): le righe
     * dicono i giorni che restano a chi le ha emesse, quelli di chi esce e quelli di chi entra (B2-1).
     *
     * @param list<array<string,mixed>> $conti
     */
    private function frasiPerCapitolo(array $conti, string $uscente, ?string $entrante, CarbonImmutable $decorrenza, bool $straordinaria = false, ?string $predecessore = null, ?string $intestatoA = null): array
    {
        $frasi = [];
        $versato = 0;
        // Decisione 67 (1): la quota del predecessore intestata all'erede di riferimento: i giorni sono del predecessore, la quota è sua.
        $emessaA = $intestatoA !== null && $predecessore !== null ? sprintf('%s, erede di riferimento di %s', $intestatoA, $predecessore) : $predecessore;
        $intestatario = $emessaA ?? $uscente;
        foreach ($conti as $c) {
            $versato += (int) ($c['versato_uscente'] ?? 0);
            // Gli importi sono quelli riportati alle quote emesse (R8): le righe sommano alla testa.
            $importo = $c['importo_emesso_formattato'] ?? $c['importo_formattato'];
            $parteEntrante = $c['entrante_emesso'] ?? $c['entrante'];
            if (! empty($c['non_risolta'])) {
                if (($c['motivo'] ?? null) === 'tratto_fuori_competenza') {
                    // B2-4: la riga è congelata su un tratto che la competenza di oggi non tocca (competenza del piano
                    // cambiata dopo l'emissione): non si divide per giorni che la riga non copre.
                    // `tratto` è un periodo solo ({dal, al}), `competenza_oggi` un insieme (lista di periodi).
                    $tratto = $this->data($c['tratto']['dal'] ?? null) . '–' . $this->data($c['tratto']['al'] ?? null);
                    $oggi = implode(' + ', array_map(fn ($t) => $this->data($t['dal']) . '–' . $this->data($t['al']), $c['competenza_oggi'] ?? []));
                    $frasi[] = sprintf('  · %s: %s, titolarità %s — la riga emessa copre un tratto che la competenza di oggi del piano (%s) non tocca: resta a %s, nessun conguaglio su questa voce finché il piano non è ricalcolato.', $c['conto'], $importo, $tratto, $oggi, $intestatario);
                    continue;
                }
                if (($c['motivo'] ?? null) === 'tratto_non_ricostruibile') {
                    // R5: una riga di ripiego i cui giorni non si ricostruiscono dalle altre righe della voce.
                    $frasi[] = sprintf('  · %s: %s — pagata per i giorni in cui mancava chi doveva pagarla, e quei giorni non si ricostruiscono dal riparto registrato: resta a %s, nessun conguaglio su questa voce.', $c['conto'], $importo, $intestatario);
                    continue;
                }
                $frasi[] = sprintf('  · %s: %s — senza competenza dichiarata sulla fattura e senza delibera: resta a %s, nessun conguaglio su questa voce.', $c['conto'], $importo, $intestatario);
                continue;
            }
            $tratti = implode(' + ', array_map(fn ($t) => $t['dal'] === $t['al'] ? $this->data($t['dal']) : $this->data($t['dal']) . '–' . $this->data($t['al']), $c['periodo']));
            $etichetta = match ($c['gradino'] ?? null) {
                'dichiarata' => 'competenza dichiarata ' . $tratti,
                'delibera'   => 'delibera del ' . $tratti,
                default      => 'competenza ' . $tratti,
            };
            if (! empty($c['resta_per_quota'])) {
                // Fase 1-ter della beta.41: la voce è di un'altra quota, e non passa per nessuna competenza.
                $motivo = (string) ($c['motivo_quota'] ?? '');
                $frasi[] = match (true) {
                    $motivo === 'usufruttuario' => sprintf('  · %s: %s, %s — resta a %s: è una spesa ordinaria, e dalla costituzione dell\'usufrutto è dell\'usufruttuario (art. 1004 c.c.): non passa a chi compra la nuda proprietà.', $this->nomeVoce($c), $importo, $etichetta, $intestatario),
                    // La quota di un predecessore arrivata a chi esce e già ceduta: è emessa al predecessore, non «resta» a chi esce (T6).
                    str_starts_with($motivo, 'ceduta:') && $intestatario !== $uscente => sprintf('  · %s: %s, %s — emessa a %s per una parte dell\'unità che %s ha avuto e ha già ceduto con un passaggio precedente (%s): nessun conguaglio su questa voce.', $this->nomeVoce($c), $importo, $etichetta, $intestatario, $uscente, self::parteInParole($motivo)),
                    str_starts_with($motivo, 'ceduta:') => sprintf('  · %s: %s, %s — resta a %s: è della parte dell\'unità che %s ha già ceduto con un passaggio precedente (%s), e non passa.', $this->nomeVoce($c), $importo, $etichetta, $uscente, $uscente, self::parteInParole($motivo)),
                    // Rilievo X3 della Fase 1-bis della .44: la bozza di chi esce con la parte degli altri eredi non è «emessa a X … mai passata a X».
                    str_starts_with($motivo, 'mai_arrivata:') && $intestatario === $uscente => sprintf('  · %s: %s, %s — è della parte dell\'unità che a %s non è mai passata (%s): nessun conguaglio su questa voce.', $this->nomeVoce($c), $importo, $etichetta, $uscente, self::parteInParole($motivo)),
                    str_starts_with($motivo, 'mai_arrivata:') => sprintf('  · %s: %s, %s — emessa a %s per una parte dell\'unità che non è mai passata a %s (%s): nessun conguaglio su questa voce.', $this->nomeVoce($c), $importo, $etichetta, $intestatario, $uscente, self::parteInParole($motivo)),
                    default => sprintf('  · %s: %s, %s — resta a %s: è della parte dell\'unità che %s tiene (%s), e non passa.', $this->nomeVoce($c), $importo, $etichetta, $uscente, $uscente, self::parteInParole($motivo)),
                };
                continue;
            }
            if ($predecessore !== null && ! $straordinaria) {
                // Quota del predecessore: i giorni prima dell'acquisto di chi esce restano a chi ha emesso la quota.
                $parti = array_filter([
                    ($c['giorni_predecessore'] ?? 0) > 0 ? sprintf('%d giorni a %s', (int) $c['giorni_predecessore'], $predecessore) : null,
                    ($c['giorni_uscente'] ?? 0) > 0 ? sprintf('%d a %s', (int) $c['giorni_uscente'], $uscente) : null,
                    ($c['giorni_entrante'] ?? 0) > 0 ? sprintf('%d a %s', (int) $c['giorni_entrante'], $entrante) : null,
                ]);
                $frasi[] = $parteEntrante === 0
                    ? (! empty($c['resta_per_voce'])
                        ? sprintf('  · %s: %s, %s — emessa a %s, resta per la scelta «come dice ogni voce»: nessun conguaglio su questa voce.', $this->nomeVoce($c), $importo, $etichetta, $emessaA)
                        : sprintf('  · %s: %s, %s — emessa a %s, la competenza finisce prima del %s: nessun conguaglio su questa voce.', $this->nomeVoce($c), $importo, $etichetta, $emessaA, $this->data($decorrenza->toDateString())))
                    : sprintf('  · %s: %s, %s — emessa a %s: %s → %s a chi entra.', $this->nomeVoce($c), $importo, $etichetta, $emessaA, implode(', ', $parti), $c['entrante_emesso_formattato'] ?? $c['entrante_formattato']);
            } elseif ($parteEntrante === 0) {
                // Straordinario a gradino: era di chi esce se il gradino cade fra il suo acquisto e il passaggio, altrimenti del predecessore.
                $eraDi = $predecessore !== null && ($c['giorni_uscente'] ?? 0) === 0 ? $predecessore : $uscente;
                $frasi[] = $straordinaria
                    ? sprintf('  · %s: %s, %s — %s, quando l\'unità era di %s: la spesa resta sua.', $c['conto'], $importo, $etichetta, ($c['gradino'] ?? null) === 'delibera' ? 'deliberata' : 'maturata', $eraDi)
                    : (! empty($c['resta_per_voce'])
                        ? sprintf('  · %s: %s, %s — resta a %s per la scelta «come dice ogni voce».', $this->nomeVoce($c), $importo, $etichetta, $uscente)
                        : sprintf('  · %s: %s, %s — resta a %s, la competenza finisce prima del %s.', $this->nomeVoce($c), $importo, $etichetta, $uscente, $this->data($decorrenza->toDateString())));
            } elseif (($c['giorni_uscente'] ?? 0) === 0) {
                $frasi[] = sprintf('  · %s: %s, %s — %s dal giorno del passaggio in poi: la spesa è di chi entra per intero → %s a chi entra.', $this->nomeVoce($c), $importo, $etichetta, ($c['gradino'] ?? null) === 'delibera' ? 'deliberata' : 'maturata', $c['entrante_emesso_formattato'] ?? $c['entrante_formattato']);
            } else {
                $frasi[] = sprintf('  · %s: %s, %s — %d giorni a %s, %d a %s → %s a chi entra.', $this->nomeVoce($c), $importo, $etichetta, (int) $c['giorni_uscente'], $uscente, (int) $c['giorni_entrante'], $entrante, $c['entrante_emesso_formattato'] ?? $c['entrante_formattato']);
            }
        }
        if ($versato > 0) {
            // S8-5: la parte «della persona» del già versato non entra nella divisione (decisione 17).
            $frasi[] = $straordinaria
                ? sprintf('  %s già versati da %s restano suoi: la spesa si attribuisce per competenza al lordo, e il versato torna col conguaglio.', MoneyHelper::format($versato), $intestatario)
                : sprintf('  %s già versati da %s restano suoi: la spesa è divisa per competenza al lordo, il versato si toglie dalla sua parte.', MoneyHelper::format($versato), $intestatario);
        }

        return $frasi;
    }

    /**
     * «credito X a chi esce, debito X a chi entra», e con le bozze che passano (decisione 25) anche da dove viene X:
     * la parte di chi entra sull'intero piano meno il preventivo delle bozze che da ora paga a suo nome. Può
     * rovesciarsi — con una rata che scade il giorno stesso del rogito le bozze coprono più dei suoi giorni — e
     * allora il credito è di chi entra.
     */
    private function creditoDebito(int $lordo, int $passate, string $uscente, string $entrante): string
    {
        $netto = $lordo - $passate;
        // Rilievo L2 della Fase 1-bis della .44: con più eredi la coppia non è una sola, e la frase non li tratta come una persona: dice la
        // parte degli eredi, e la divisione per erede la dicono le frasi dopo.
        // Giro sulle correzioni (GC3): con l'arretrato agli eredi le bozze che passano superano di regola i giorni degli eredi, e la stessa
        // frase serve la straordinaria, che va per delibera e non «per i giorni».
        if ($this->eredi > 1) {
            return match (true) {
                $passate === 0 => sprintf('la parte degli eredi è %s, divisa per quota più sotto', MoneyHelper::format($lordo)),
                $passate <= $lordo => sprintf('la parte degli eredi sull\'intero piano è %s; %s sono già nelle rate in bozza che passano a suo nome; la divisione per erede è più sotto', MoneyHelper::format($lordo), MoneyHelper::format($passate)),
                default => sprintf('la parte degli eredi sull\'intero piano è %s, e le rate in bozza che passano a suo nome valgono %s: coprono più dei giorni degli eredi; la divisione per erede è più sotto', MoneyHelper::format($lordo), MoneyHelper::format($passate)),
            };
        }
        if ($passate === 0) {
            return sprintf('credito %s a %s, debito %s a %s', MoneyHelper::format($netto), $uscente, MoneyHelper::format($netto), $entrante);
        }
        $parte = sprintf('la parte di %s sull\'intero piano è %s', $entrante, MoneyHelper::format($lordo));

        return match (true) {
            $netto > 0 => sprintf('credito %s a %s, debito %s a %s (%s, di cui %s con le rate in bozza che passano a suo nome)', MoneyHelper::format($netto), $uscente, MoneyHelper::format($netto), $entrante, $parte, MoneyHelper::format($passate)),
            $netto < 0 => sprintf('credito %s a %s, debito %s a %s (%s, e le rate in bozza che passano a suo nome valgono %s: coprono più dei suoi giorni)', MoneyHelper::format(-$netto), $entrante, MoneyHelper::format(-$netto), $uscente, $parte, MoneyHelper::format($passate)),
            default => sprintf('nessuna coppia (%s, esattamente quanto valgono le rate in bozza che passano a suo nome)', $parte),
        };
    }

    /**
     * Perché un gruppo di bozze resta a chi le ha (decisione 21, e dalla B3a la decisione 25 che ne fa passare le
     * altre): la frase dice a chi resteranno intestate e, quando c'è, la ragione.
     *
     * @param array{piano: string, intestatario: string, n: int, motivo: ?string, usufruttuario?: string, ceduta?: bool, ceduta_in_parte?: bool} $b
     */
    private function fraseBozzeTrattenute(array $b, string $uscente, CarbonImmutable $decorrenza): string
    {
        $n = $b['n'];
        $uno = $n === 1;
        $Quote = $uno ? 'La quota' : sprintf('Le %d quote', $n);
        $comprese = $uno ? sprintf('Compresa la quota del piano «%s» non ancora emessa', $b['piano']) : sprintf('Comprese le %d quote del piano «%s» non ancora emesse', $n, $b['piano']);
        $resteranno = $uno ? 'resterà intestata' : 'resteranno intestate';
        $siConguagliano = $uno ? 'si conguaglia' : 'si conguagliano';

        return match ($b['motivo'] ?? null) {
            'scade_prima' => sprintf('%s che %s prima del %s: %s a %s e %s qui.', $comprese, $uno ? 'scade' : 'scadono', $this->data($decorrenza->toDateString()), $resteranno, $b['intestatario'], $siConguagliano),
            'pagata' => sprintf('%s che %s già un pagamento: %s a %s e %s qui.', $comprese, $uno ? 'ha' : 'hanno', $resteranno, $b['intestatario'], $siConguagliano),
            'segnalata' => sprintf('%s che %s un pagamento segnalato dal portale e non ancora verificato: %s a %s e %s qui — verifica la segnalazione prima.', $comprese, $uno ? 'ha' : 'hanno', $resteranno, $b['intestatario'], $siConguagliano),
            'solo_pregresso' => sprintf('%s del piano «%s» non ancora %s %s solo di saldi pregressi di %s: %s sua.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $uno ? 'è fatta' : 'sono fatte', $b['intestatario'], $uno ? 'resterà' : 'resteranno'),
            'straordinaria_di_chi_esce' => sprintf('%s del piano «%s» non ancora %s %s a %s: la spesa straordinaria è sua.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario']),
            'straordinaria_divisa' => sprintf('%s: la spesa straordinaria si divide fra chi esce e chi entra per competenza, quindi %s a %s e %s qui.', $comprese, $resteranno, $b['intestatario'], $siConguagliano),
            // Decisione 26: sull'ordinaria, la voce con la competenza dichiarata sulla fattura.
            'fattura_di_chi_esce' => sprintf('%s del piano «%s» non ancora %s %s a %s: la competenza dichiarata sulla fattura cade prima del passaggio, la spesa è sua.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario']),
            'fattura_divisa' => sprintf('%s: una voce del piano ha la competenza dichiarata sulla fattura e la spesa si divide voce per voce fra chi esce e chi entra, quindi %s a %s e %s qui.', $comprese, $resteranno, $b['intestatario'], $siConguagliano),
            // R12: le quote che il conguaglio non tocca non «si conguagliano qui».
            'straordinaria_del_nudo' => sprintf('%s del piano «%s» non ancora %s %s a %s: le spese straordinarie sono del nudo proprietario (art. 1005 c.c.), il passaggio non le tocca.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario']),
            // Decisione 28.4: non «non ne risponde» — verso il condominio ne risponde in solido (art. 67 ult. co.).
            'ordinaria_dell_usufruttuario' => sprintf('%s del piano «%s» non ancora %s %s a %s: sono spese ordinarie dell\'usufruttuario (art. 1004 c.c.): fra le parti si regolano con l\'usufruttuario, e non passano a chi compra la nuda proprietà.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario']),
            // Riserva d'usufrutto (decisione 28): l'usufrutto nasce in questo atto, e chi vende lo tiene. Chi resta
            // usufruttuario non è sempre l'intestatario: le quote possono essere di chi gli aveva venduto prima (rilievo B3).
            'ordinaria_riservata' => sprintf('%s del piano «%s» non ancora %s %s a %s: sono spese ordinarie, e %s resta usufruttuario (art. 1004 c.c.) — non passano a chi compra la nuda proprietà.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'], $b['usufruttuario'] ?? $b['intestatario']),
            'senza_istantanea' => sprintf('%s del piano «%s» non ancora %s %s a %s, senza conguaglio: non %s quanta parte è saldo pregresso.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'], $uno ? 'dice' : 'dicono'),
            'catena_ambigua' => sprintf('%s del piano «%s» non ancora %s %s a %s, senza conguaglio: su questa unità la parte che passa non si separa con certezza.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario']),
            // Decisione 35: il passaggio di prima ha lasciato il piano al ricalcolo, che non c'è stato.
            'mai_passata' => sprintf('%s del piano «%s» non ancora %s %s a %s, senza conguaglio: il passaggio di prima non %s ha %s passare, e il piano non è stato ricalcolato.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'], $uno ? 'la' : 'le', $uno ? 'fatta' : 'fatte'),
            'non_risolta' => sprintf('%s del piano «%s» non ancora %s %s a %s, senza conguaglio: la competenza del piano non si può determinare.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario']),
            // Decisione 31.5: con «come la voce» la bozza non passa intera.
            // Non «hai scelto»: la scelta può venire da un passaggio prima (la riserva, la costituzione), non da questo.
            // Fase 1-ter della beta.41: le quote di un predecessore sono di una parte che non è mai passata a chi esce; quelle di chi
            // esce, di una parte che tiene.
            // Rilievo GT2: la parte di un predecessore arrivata a chi esce e già ceduta da chi esce; con ragioni miste, una frase neutra.
            'altra_quota' => ! empty($b['ceduta'])
                ? ($b['intestatario'] !== $uscente
                    ? sprintf('%s del piano «%s» non ancora %s %s a %s: sono della parte dell\'unità che %s ha avuto e ha già ceduto con un passaggio precedente, e non passano.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'], $uscente)
                    : sprintf('%s del piano «%s» non ancora %s %s a %s: sono della parte dell\'unità che %s ha già ceduto con un passaggio precedente, e non passano.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'], $b['intestatario']))
                // Rilievo HT1: con ragioni miste (una parte ceduta, una tenuta o mai passata) la frase neutra, anche per chi esce.
                : (! empty($b['ceduta_in_parte'])
                ? sprintf('%s del piano «%s» non ancora %s %s a %s: sono di parti dell\'unità che non passano con questo atto.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'])
                : ($b['intestatario'] === $uscente && ! empty($b['mai_arrivata'])
                    ? sprintf('%s del piano «%s» non ancora %s %s a %s: sono di una parte dell\'unità che a %s non è mai passata (%s), e non passano.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'], $uscente, $b['mai_arrivata'])
                    : ($b['intestatario'] === $uscente
                    ? sprintf('%s del piano «%s» non ancora %s %s a %s: sono della parte dell\'unità che %s tiene, e non passano.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'], $b['intestatario'])
                    : sprintf('%s del piano «%s» non ancora %s %s a %s: sono di una parte dell\'unità che non è mai passata a %s, e non passano.', $Quote, $b['piano'], $uno ? 'emessa' : 'emesse', $resteranno, $b['intestatario'], $uscente)))),
            'quota_di_piu_ruoli' => ! empty($b['ceduta'])
                ? sprintf('%s: ogni quota comprende anche la spesa di una parte dell\'unità che %s ha già ceduto con un passaggio precedente, e la quota non passa intera, quindi %s a %s e la parte che passa si conguaglia qui.', $comprese, $b['intestatario'] !== $uscente ? $uscente . ' ha avuto e' : $b['intestatario'], $resteranno, $b['intestatario'])
                : (! empty($b['ceduta_in_parte'])
                ? sprintf('%s: ogni quota comprende anche la spesa di parti dell\'unità che non passano con questo atto, e la quota non passa intera, quindi %s a %s e la parte che passa si conguaglia qui.', $comprese, $resteranno, $b['intestatario'])
                : ($b['intestatario'] === $uscente && ! empty($b['mai_arrivata'])
                    ? sprintf('%s: ogni quota comprende anche la spesa di una parte dell\'unità che a %s non è mai passata (%s), e la quota non passa intera, quindi %s a %s e la parte che passa si conguaglia qui.', $comprese, $uscente, $b['mai_arrivata'], $resteranno, $b['intestatario'])
                    : ($b['intestatario'] === $uscente
                    ? sprintf('%s: ogni quota comprende anche la spesa della parte dell\'unità che %s tiene, e la quota non passa intera, quindi %s a %s e la parte che passa si conguaglia qui.', $comprese, $b['intestatario'], $resteranno, $b['intestatario'])
                    : sprintf('%s: ogni quota comprende anche la spesa di una parte dell\'unità che non è mai passata a %s, e la quota non passa intera, quindi %s a %s e la parte che passa si conguaglia qui.', $comprese, $uscente, $resteranno, $b['intestatario'])))),
            'ordinaria_per_voce' => sprintf('%s: l\'ordinaria segue la voce, per la scelta «come dice ogni voce», e con questa scelta le rate in bozza non cambiano intestatario, quindi %s a %s e la parte delle voci che passano si conguaglia qui.', $comprese, $resteranno, $b['intestatario']),
            // Testo T2 del giro di verifica: la ragione vera la dice l'apertura del blocco rate e il cancello (`fraseDelFermo()`).
            default => sprintf('%s: il piano non si ricalcola più, quindi %s a %s e %s qui.', $comprese, $resteranno, $b['intestatario'], $siConguagliano),
        };
    }

    private function vuoto(): array
    {
        return [
            'stato' => 'nessuna_rata', 'anagrafica_uscente_id' => null, 'anagrafica_entrante_id' => null, 'quote' => [], 'per_gestione' => [], 'coppie' => [],
            'totale_entrante' => 0, 'totale_entrante_formattato' => MoneyHelper::format(0),
            'pregressi' => 0, 'non_risolte' => [], 'esercizi_dedotti' => [], 'frasi' => [],
        ];
    }

    /**
     * Rilievo D5: la data d'acquisto di una quota ereditata. Quando le voci sul «Proprietario» e le altre sono arrivate a chi
     * esce in giorni diversi, conta il lato che le voci della quota occupano; se le occupano tutti e due, le due date.
     *
     * @param list<string>|null $lati i lati occupati, quando le date dei lati sono diverse
     * @return array{data: ?string, per_lato: ?array{P: string, A: string}}
     */
    private function acquistoDellaQuota(object $r, ?array $lati): array
    {
        $date = $r->decorrenze_lato ?? null;
        if (! is_array($date) || $lati === null || $lati === [] || ($date['P'] ?? null) === null || ($date['A'] ?? null) === null || $date['P'] === $date['A']) {
            return ['data' => $r->decorrenza_acquisto ?? null, 'per_lato' => null];
        }

        return count($lati) === 1
            ? ['data' => $date[$lati[0]], 'per_lato' => null]
            : ['data' => $r->decorrenza_acquisto ?? null, 'per_lato' => ['P' => $date['P'], 'A' => $date['A']]];
    }

    /** «dal 1 maggio 2026», o «dal 1 maggio 2026 per le voci sul «Proprietario» e dal 1 settembre 2026 per le altre». */
    private function dalAcquisto(array $p): string
    {
        $lati = $p['acquisto_per_lato'] ?? null;

        return is_array($lati)
            ? sprintf('dal %s per le voci sul «Proprietario» e dal %s per le altre', $this->data($lati['P']), $this->data($lati['A']))
            : 'dal ' . $this->data($p['decorrenza_acquisto'] ?? null);
    }

    private function data(?string $iso): string
    {
        return $iso ? CarbonImmutable::parse($iso)->locale('it')->translatedFormat('j F Y') : '—';
    }
}
