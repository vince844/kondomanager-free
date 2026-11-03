<?php

namespace App\Services\Subentro;

use App\Enums\NaturaGestione;
use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Models\TitolaritaImmobile;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Services\Riparto\PeriodoDellaRiga;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Coda 245 (1.11.0-beta.55): la regola del piano alla fine di una locazione con un coinquilino che resta (decisioni 29.1 e 79.2).
 *
 * Il piano ha congelato, voce per voce e unità per unità, la parte «Inquilino» (le righe del riparto con il ruolo richiesto «inquilino»:
 * l'anello). Quando uno dei conduttori esce e la locazione continua con un altro, quella parte si ridivide con i pesi che il motore darebbe
 * dopo l'uscita (`ChiPagaAlPostoDellInquilino::pesiDelPiano`: quota × giorni, e i giorni che nessun inquilino copre alla catena del
 * ripiego), con un arrotondamento solo sull'anello: la coppia di ognuno è la parte nuova meno quella congelata, e somma zero per voce e per
 * unità. Contro il piano generato dopo l'uscita, che arrotonda sull'intera voce e su tutte le unità, la differenza è di qualche centesimo:
 * al più 1 + voci × (1 + passaggi), la tolleranza dell'invariante del laboratorio.
 *
 * A pari resto il centesimo va a chi esce, poi alle righe d'inquilino nell'ordine, poi al ripiego: un ordine deciso, non quello di lettura
 * del database. I giorni che non copre nessuno, neanche il ripiego, restano nelle rate di chi esce, come nella fine senza coinquilini.
 *
 * Una riga in saldi l'ha solo chi ha quote del piano sull'unità o righe di coppie già scritte lì: un conduttore entrato su un piano che non
 * si ricalcolava più non diventa debitore del condominio (decisione 45, art. 9 L. 392/1978), e la sua parte si dice, con la persona e la
 * cifra. In quel caso si scrive la differenza di chi esce, divisa fra chi riceve i suoi giorni in proporzione alle loro differenze, e il
 * resto lo regolano le parti con il prospetto degli oneri accessori.
 */
final class RegolaDelPiano
{
    /** La chiave dei giorni che nessuno copre, nei pesi (`ChiPagaAlPostoDellInquilino::pesiDelPiano`): la loro parte resta a chi esce. */
    public const NESSUNO = 'nessuno';

    public function __construct(
        private readonly ChiPagaAlPostoDellInquilino $chiPaga = new ChiPagaAlPostoDellInquilino(),
        private readonly CompetenzaDelPiano $competenza = new CompetenzaDelPiano(),
    ) {
    }

    /**
     * Gli anelli dell'inquilino di un piano su un'unità, voce per voce (conto e competenza): l'importo dell'anello e la parte congelata di
     * ognuno, con il segno (un'entrata toglie). La parte «dell'unità» del già versato abbassa l'anello, come nel conguaglio
     * (`ConguaglioPassaggio::scomponiPerConto`): il motore la divide fra le righe di ciascuno in proporzione ai lordi; la parte «della
     * persona» resta di chi l'ha versata e non entra. Vuoto se sull'unità il piano non ha una parte «Inquilino».
     *
     * Un piano senza il riparto registrato (generato dalla 1.10, prima della 1.11.0-beta.29) ha un anello solo, sulla sua competenza: le quote
     * pure degli inquilini sull'unità (la 1.10 divideva la parte dell'inquilino per quota). Lì si ferma, e lo dice (`ferma`, e il solo perché in
     * `perche`, per il prospetto degli oneri accessori), quando un inquilino ha anche un altro ruolo sull'unità o una sua quota non ha la composizione
     * (la 1.7.x): la parte dell'inquilino non si separa.
     *
     * @return list<array{piano_rate_id: int, conto_id: int, conto: string, competenza: InsiemePeriodi, anello: int, prima: array<int, int>}>|array{ferma: string, perche: string}
     */
    public function anelli(PianoRate $piano, int $immobileId): array
    {
        if (! DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->exists()) {
            return $this->anelloSenzaRiparto($piano, $immobileId);
        }
        $righe = DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('immobile_id', $immobileId)
            ->whereIn('tipo', [RigaRiparto::TIPO_RIPARTO, RigaRiparto::TIPO_NETTING, RigaRiparto::TIPO_AD_PERSONAM])->orderBy('id')
            ->get(['id', 'anagrafica_id', 'conto_id', 'conto_nome', 'conto_radice_id', 'ruolo_richiesto', 'tipo', 'importo', 'competenza_dal', 'competenza_al', 'gradino_competenza', 'riga_descrizione']);
        $anello = $righe->filter(fn ($r) => $r->tipo === RigaRiparto::TIPO_RIPARTO && $r->ruolo_richiesto === RuoloAnagraficaImmobile::INQUILINO->value)->values();
        if ($anello->isEmpty()) {
            return [];
        }
        $esito = $this->competenza->perPiano($piano, $this->competenza->esercizioDelPiano($piano));
        $base = $esito !== null && $esito->risolto() ? $esito->periodi : null;
        $straordinaria = NaturaGestione::daStringa($piano->gestione?->tipo) === NaturaGestione::Straordinaria;
        $tratti = $straordinaria ? [] : PeriodoDellaRiga::trattiPerConto((int) $piano->id);
        $giorno = fn ($d) => substr((string) $d, 0, 10);

        // La parte «dell'unità» del già versato che tocca all'anello, per persona e conto: il netto del già versato meno la parte della
        // persona, diviso fra le righe dell'anello e le altre righe della persona sullo stesso conto, in proporzione ai lordi.
        $dellUnita = [];
        foreach ($righe->where('tipo', RigaRiparto::TIPO_NETTING)->groupBy(fn ($r) => (int) $r->anagrafica_id . '|' . (int) $r->conto_id) as $chiave => $g) {
            $persona = (int) $g->where('riga_descrizione', CalcoloQuoteService::NETTING_DELLA_PERSONA)->sum('importo');
            $unita = (int) $g->sum('importo') - $persona;
            [$aid, $cid] = array_map('intval', explode('|', (string) $chiave));
            $suoi = $righe->filter(fn ($r) => (int) $r->anagrafica_id === $aid && (int) $r->conto_id === $cid && $r->tipo !== RigaRiparto::TIPO_NETTING);
            $nellAnello = (float) abs((int) $suoi->filter(fn ($r) => $r->tipo === RigaRiparto::TIPO_RIPARTO && $r->ruolo_richiesto === RuoloAnagraficaImmobile::INQUILINO->value)->sum('importo'));
            if ($unita === 0 || $nellAnello <= 0.0) {
                continue;
            }
            $dellUnita[$chiave] = (int) MoneyHelper::ripartisciPerQuote($unita, ['anello' => $nellAnello, 'altro' => (float) abs((int) $suoi->sum('importo')) - $nellAnello])['anello'];
        }

        $voci = [];
        foreach ($anello as $r) {
            $propria = ($straordinaria || $r->gradino_competenza === 'dichiarata') && $r->competenza_dal !== null && $r->competenza_al !== null;
            // La competenza c'è: il conguaglio passa qui solo i piani delle quote risolte di chi esce, e un piano straordinario senza delibera
            // non si genera (`RichiedeDeliberaException`).
            $competenza = $propria ? InsiemePeriodi::uno(new PeriodoCompetenza($giorno($r->competenza_dal), $giorno($r->competenza_al)))
                : ($tratti[(int) $r->conto_id] ?? $tratti[(int) ($r->conto_radice_id ?? 0)] ?? $base);
            $chiave = (int) $r->conto_id . '|' . json_encode($competenza->toArray());
            $voci[$chiave] ??= ['piano_rate_id' => (int) $piano->id, 'conto_id' => (int) $r->conto_id, 'conto' => (string) ($r->conto_nome ?? ''), 'competenza' => $competenza, 'anello' => 0, 'prima' => []];
            $voci[$chiave]['prima'][(int) $r->anagrafica_id] = ($voci[$chiave]['prima'][(int) $r->anagrafica_id] ?? 0) + (int) $r->importo;
        }
        foreach ($dellUnita as $chiave => $netto) {
            [$aid, $cid] = array_map('intval', explode('|', (string) $chiave));
            // La persona ha righe dell'anello su quel conto (`$nellAnello`): la sua voce c'è.
            $dove = array_key_first(array_filter($voci, fn (array $v) => $v['conto_id'] === $cid && isset($v['prima'][$aid])));
            $voci[$dove]['prima'][$aid] += $netto;
        }
        foreach ($voci as &$v) {
            $v['anello'] = (int) array_sum($v['prima']);
        }
        unset($v);

        return array_values($voci);
    }

    /**
     * L'anello di un piano senza il riparto registrato: le quote pure degli inquilini sull'unità, sulla competenza del piano (vedi `anelli`).
     *
     * @return list<array<string, mixed>>|array{ferma: string, perche: string}
     */
    private function anelloSenzaRiparto(PianoRate $piano, int $immobileId): array
    {
        // La competenza del piano è risolta: il conguaglio passa qui solo i piani delle quote risolte di chi esce (`ConguaglioPassaggio::calcola`).
        $esito = $this->competenza->perPiano($piano, $this->competenza->esercizioDelPiano($piano));
        $righe = $this->chiPaga->righe($immobileId);
        $inquilini = $righe->where('tipologia', RuoloAnagraficaImmobile::INQUILINO->value)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $nomeUnita = (string) (DB::table('immobili')->where('id', $immobileId)->value('nome') ?: 'questa unità');
        $strada = ' — se serve un conguaglio, si scrive dal Wallet con saldi legati a questo passaggio, sulla stessa gestione';
        if ($righe->contains(fn ($r) => in_array((int) $r->anagrafica_id, $inquilini, true) && $r->tipologia !== RuoloAnagraficaImmobile::INQUILINO->value)) {
            $perche = sprintf('su %s un inquilino ha anche un altro ruolo: la parte dell\'inquilino non si separa dalle sue altre quote', $nomeUnita);

            return ['ferma' => 'il piano non ha il dettaglio del riparto, e ' . $perche . $strada, 'perche' => $perche];
        }
        $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
            ->where('rate_quote.immobile_id', $immobileId)->whereIn('rate_quote.anagrafica_id', $inquilini ?: [0])->where('rate_quote.stato', '!=', 'annullata')
            ->get(['rate_quote.anagrafica_id', 'rate_quote.regole_calcolo']);
        $prima = [];
        foreach ($quote as $q) {
            $pura = (is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : (array) $q->regole_calcolo)['importi']['quota_pura_gestione'] ?? null;
            if ($pura === null) {
                $perche = sprintf('le quote degli inquilini su %s sono state generate da una versione che non salvava la composizione della rata: la parte dell\'inquilino non si separa dai saldi pregressi', $nomeUnita);

                return ['ferma' => $perche . $strada, 'perche' => $perche];
            }
            $prima[(int) $q->anagrafica_id] = ($prima[(int) $q->anagrafica_id] ?? 0) + (int) $pura;
        }
        $prima = array_filter($prima, fn (int $c) => $c !== 0);

        return $prima === [] ? [] : [['piano_rate_id' => (int) $piano->id, 'conto_id' => 0, 'conto' => (string) $piano->nome, 'competenza' => $esito->periodi, 'anello' => (int) array_sum($prima), 'prima' => $prima]];
    }

    /**
     * L'anello ridiviso con i pesi dati, un arrotondamento solo (la regola di `MoneyHelper::ripartisciPerQuote`: le parti intere, il centesimo che
     * avanza a chi ha il resto più grande, a pari resto a chi viene prima). Chi esce viene prima di tutti; gli altri nell'ordine dei pesi. Il segno
     * lo dà l'anello.
     *
     * I pesi sono giorni in virgola mobile (quota × giorni normalizzati sui giorni coperti): due parti che a mano finiscono tutte e due in ,5 possono
     * uscire 13187,4999… e 22812,5000…, e il centesimo andrebbe a chi il rumore dell'aritmetica favorisce, non a chi viene prima. Le parti esatte si
     * arrotondano al milionesimo di centesimo prima del confronto dei resti: a pari resto vale l'ordine.
     *
     * @param array<int|string, float> $pesi
     * @return array<int|string, int>
     */
    public static function ridividi(int $anello, array $pesi, ?int $chiEsce): array
    {
        if ($chiEsce !== null && array_key_exists($chiEsce, $pesi)) {
            $pesi = [$chiEsce => $pesi[$chiEsce]] + $pesi;
        }
        $somma = (float) array_sum($pesi);
        if ($pesi === [] || $somma <= 0.0) {
            return array_map('intval', MoneyHelper::ripartisciPerQuote($anello, $pesi));
        }
        $assoluto = abs($anello);
        $parti = [];
        $resti = [];
        foreach ($pesi as $chiave => $peso) {
            $esatto = round($assoluto * (float) $peso / $somma, 6);
            $parti[$chiave] = (int) floor($esatto);
            $resti[$chiave] = $esatto - $parti[$chiave];
        }
        // `arsort` è stabile: a pari resto vince chi viene prima.
        arsort($resti);
        foreach (array_slice(array_keys($resti), 0, max(0, $assoluto - (int) array_sum($parti))) as $chiave) {
            $parti[$chiave]++;
        }

        return array_map(fn (int $c) => $anello < 0 ? -$c : $c, $parti);
    }

    /**
     * Voce per voce, la parte di ognuno con le righe di dopo il passaggio. Una voce in cui i pesi non cambiano resta com'è (la parte congelata):
     * il passaggio non la tocca, e non nasce un centesimo dall'arrotondamento. Dove cambiano, l'anello si ridivide (`ridividi`); la parte dei
     * giorni che non copre nessuno resta a chi esce.
     *
     * Decisione 111.3 (la differenza del piano del passaggio): in un passaggio dopo una fine con la regola del piano sullo stesso anello
     * (`$dopoUnaFine`) la parte di prima non è quella congelata, ma l'anello ridiviso con le righe di prima del passaggio: è la parte a cui
     * la fine di prima ha portato ognuno con «Scrivi», o che il piano gli dà con «Non scriverlo». A pari resto il centesimo di prima va a chi
     * era uscito con l'ultimo passaggio della regola del piano (`$primoPrima`), come nella coppia che lo ha scritto.
     *
     * @param list<array<string, mixed>> $voci gli anelli (`anelli`)
     * @param Collection<int, object> $righePrima le righe dell'unità prima del passaggio
     * @param Collection<int, object> $righeDopo le righe dell'unità come le lascia il passaggio
     * @return list<array<string, mixed>> le voci con `dopo`, `giorni` (gli inquilini dopo), `ripiego` (persone e parte) e `nessuno`
     */
    public function differenze(array $voci, Collection $righePrima, Collection $righeDopo, ?int $chiEsce, bool $dopoUnaFine = false, ?int $primoPrima = null): array
    {
        foreach ($voci as &$v) {
            $prima = $this->chiPaga->pesiDelPiano($righePrima, $v['competenza']);
            $dopo = $this->chiPaga->pesiDelPiano($righeDopo, $v['competenza']);
            if ($dopoUnaFine) {
                $v['prima'] = self::parti((int) $v['anello'], $prima['pesi'], $primoPrima)[0];
            }
            $v['giorni'] = $dopo['giorni'];
            $v['ripiego'] = [];
            $v['scoperti'] = null;
            $v['nessuno'] = 0;
            if ($prima['pesi'] === $dopo['pesi']) {
                $v['dopo'] = $v['prima'];
                continue;
            }
            [$v['dopo'], $v['nessuno']] = self::parti((int) $v['anello'], $dopo['pesi'], $chiEsce);
            $v['ripiego'] = $dopo['ripiego'];
            $v['scoperti'] = $dopo['scoperti'];
            // I giorni che il passaggio ha reso scoperti: quelli di dopo che prima avevano un conduttore (la frase dice da quando tornano a chi paga al posto dell'inquilino).
            $v['scoperti_nuovi'] = $dopo['scoperti'] === null ? null : array_reduce($prima['scoperti']?->periodi() ?? [], fn (?InsiemePeriodi $resto, $tratto) => $resto?->meno($tratto), $dopo['scoperti']);
            $v['scoperti_nessuno'] = $v['nessuno'] !== 0 ? $dopo['di_nessuno'] : null;
        }
        unset($v);

        return $voci;
    }

    /**
     * L'anello ridiviso con i pesi dati (`ridividi`), senza gli zeri: i giorni che non copre nessuno vengono subito dopo `$primo`, e la loro
     * parte è sua (chi esce, o chi era uscito). La parte e quella dei giorni di nessuno.
     *
     * @param array<int|string, float> $pesi
     * @return array{0: array<int, int>, 1: int}
     */
    private static function parti(int $anello, array $pesi, ?int $primo): array
    {
        [$parti, $nessuno] = self::partiConINessuno($anello, $pesi, $primo);
        if ($nessuno !== 0 && $primo !== null) {
            $parti[$primo] = ($parti[$primo] ?? 0) + $nessuno;
        }

        return [array_filter($parti, fn (int $c) => $c !== 0), $nessuno];
    }

    /**
     * L'anello ridiviso come in `parti` (lo stesso arrotondamento, lo stesso primo a pari resto), con la parte dei giorni che non copre nessuno tenuta a
     * parte invece che sommata a `$primo`: il prospetto degli oneri accessori la dice nei giorni senza conduttore, come rimasta nelle rate di chi è uscito.
     *
     * @param array<int|string, float> $pesi
     * @return array{0: array<int, int>, 1: int} le parti senza gli zeri, e la parte dei giorni di nessuno
     */
    public static function partiConINessuno(int $anello, array $pesi, ?int $primo): array
    {
        if (isset($pesi[self::NESSUNO]) && $primo !== null) {
            $pesi = [self::NESSUNO => $pesi[self::NESSUNO]] + $pesi;
        }
        $parti = self::ridividi($anello, $pesi, $primo);
        $nessuno = (int) ($parti[self::NESSUNO] ?? 0);
        unset($parti[self::NESSUNO]);

        return [array_filter($parti, fn (int $c) => $c !== 0), $nessuno];
    }

    /**
     * La coppia della fine sulle differenze: per persona la parte di dopo meno quella congelata, sommate sulle voci. Si scrivono le differenze
     * di tutti quando tutti hanno una riga possibile (quote del piano sull'unità, o righe di coppie già scritte); altrimenti la differenza di
     * chi esce, divisa fra chi riceve, scrivibile, in proporzione alle loro differenze di segno opposto, e il resto si dice.
     *
     * @param list<array<string, mixed>> $voci le voci di `differenze`
     * @param array<int, true> $scrivibili
     * @return array{parti: array<int, int>, resto: array<int, int>, differenze: array<int, int>}
     */
    public static function coppia(array $voci, int $chiEsce, array $scrivibili): array
    {
        $differenze = self::differenzeDi($voci);
        $fuori = array_diff_key($differenze, $scrivibili, [$chiEsce => true]);
        if ($fuori === []) {
            return ['parti' => $differenze, 'resto' => [], 'differenze' => $differenze];
        }
        $diChiEsce = (int) ($differenze[$chiEsce] ?? 0);
        $segno = $diChiEsce > 0 ? 1 : -1;
        $riceventi = [];
        foreach ($differenze as $id => $c) {
            if ($id !== $chiEsce && isset($scrivibili[$id]) && -$segno * $c > 0) {
                $riceventi[$id] = (float) (-$segno * $c);
            }
        }
        // A pari resto prima chi ha la differenza più grande; senza riceventi nessuna riga (le parti a zero si tolgono in fondo).
        arsort($riceventi);
        $parti = [];
        foreach (MoneyHelper::ripartisciPerQuote(abs($diChiEsce), $riceventi) as $id => $c) {
            $parti[(int) $id] = -$segno * (int) $c;
        }
        $parti = [$chiEsce => -(int) array_sum($parti)] + $parti;
        $resto = [];
        foreach ($differenze as $id => $c) {
            if ($c - ($parti[$id] ?? 0) !== 0) {
                $resto[$id] = $c - ($parti[$id] ?? 0);
            }
        }

        return ['parti' => array_filter($parti, fn (int $c) => $c !== 0), 'resto' => $resto, 'differenze' => $differenze];
    }

    /**
     * La differenza di ognuno sulle voci: la parte di dopo meno quella di prima, sommate, senza gli zeri.
     *
     * @param list<array<string, mixed>> $voci le voci di `differenze`
     * @return array<int, int>
     */
    private static function differenzeDi(array $voci): array
    {
        $differenze = [];
        foreach ($voci as $v) {
            foreach (array_keys($v['dopo'] + $v['prima']) as $id) {
                $differenze[(int) $id] = ($differenze[(int) $id] ?? 0) + (int) ($v['dopo'][$id] ?? 0) - (int) ($v['prima'][$id] ?? 0);
            }
        }

        return array_filter($differenze, fn (int $c) => $c !== 0);
    }

    /**
     * La regola del piano per un gruppo della fine (gestione, unità, esercizio): gli anelli dei piani del gruppo, le parti di dopo con la
     * riga di chi esce chiusa il giorno prima, la coppia e la fotografia per il registro (`conguaglio.piano`). Senza anelli (le quote di chi
     * esce non risolte: il conguaglio le dice fra le non risolte) la fotografia è vuota, e la coppia non c'è; con `ferma`, il motivo.
     *
     * @param list<int> $pianoIds
     * @return array<string, mixed>
     */
    public function dellaFine(TitolaritaImmobile $uscente, int $immobileId, array $pianoIds, int $gestioneId, CarbonImmutable $decorrenza): array
    {
        $voci = [];
        foreach (PianoRate::with('gestione')->findMany($pianoIds)->sortBy('id') as $piano) {
            $anelli = $this->anelli($piano, $immobileId);
            if (isset($anelli['ferma'])) {
                return $anelli;
            }
            array_push($voci, ...$anelli);
        }
        $chiEsce = (int) $uscente->anagrafica_id;
        $righe = $this->chiPaga->righe($immobileId);
        // Un cambio d'inquilino della .50 sull'unità e la gestione (chi entra prende la quota di chi esce, decisione 37: la coppia è quella del piano) ha già spostato
        // l'anello, con «Scrivi» e con «Non scriverlo» (la stessa coppia, fra le parti): la parte di prima è l'anello ridiviso con le righe di prima di questa fine, come
        // nei passaggi dopo una fine con la regola del piano (decisione 111.3), e non quella congelata.
        $cambi = self::cambiCheHannoSpostatoLAnello($immobileId, $gestioneId);
        $ultimoCambio = end($cambi) ?: null;
        $voci = $this->differenze($voci, $righe, app(FermataDopoScrivi::class)->righeDopoLaFine($righe, $immobileId, $uscente, $decorrenza), $chiEsce, dopoUnaFine: $ultimoCambio !== null);
        // Chi riceve i giorni di chi esce, come nei passaggi dopo: chi era già uscito (la sua riga chiusa prima della generazione, o con una fine
        // registrata da una beta) ha quote del piano, ma la sua parte non si tocca.
        [$scrivibili, $restano, $riceventi] = self::chiRiceve($voci, $chiEsce, null, $immobileId, $pianoIds, $gestioneId, $decorrenza);
        $delleBeta = self::finiConLaFermataDelCoinquilino($immobileId, $gestioneId);
        if ($delleBeta !== []) {
            // Decisione 111.2: dopo una fine delle beta con la fermata del coinquilino sull'unità e la gestione l'anello resta alla regola dei giorni, com'era registrato
            // (un modello solo sull'unità): la regola del piano si dice, e non si scrive, neanche dove ci sarebbe una coppia. Con la coppia il registro avrebbe la
            // regola del piano, e il prospetto la leggerebbe anche per chi è uscito con la fine delle beta, sopra la sua fermata.
            $differenze = self::differenzeDi($voci);
            $coppia = ['parti' => [], 'resto' => $differenze, 'differenze' => $differenze];
        } else {
            $coppia = self::coppia($voci, $chiEsce, array_intersect_key($scrivibili, $riceventi));
        }
        $fuori = self::chiRestaFuori($coppia, $scrivibili, $restano, $riceventi, $chiEsce);
        if ($delleBeta !== []) {
            // Decisione 115.2: dopo una fine delle beta con la fermata del coinquilino il resto parla degli stessi giorni della fermata: la strada del Wallet non
            // si offre, e la frase rimanda alla decisione 111.2 (annullare quella fine e registrarla di nuovo).
            $fuori['wallet'] = false;
        }

        return $this->fotografia($voci, $coppia, $chiEsce, $immobileId, $gestioneId) + $fuori + [
            // Dopo una fine delle beta con la fermata del coinquilino sull'unità e la gestione la fermata e il resto parlano degli stessi giorni, e senza una
            // coppia il resto si dice e basta (la regola del piano non va nel registro, `RegistraSubentroAction::delPiano`).
            'dopo_la_fermata_del_coinquilino' => $delleBeta !== [],
            // Quelle fini, per la frase della decisione 111.2: chi è uscito e il giorno.
            'fini_delle_beta' => array_map(fn (Subentro $f) => ['id' => (int) $f->id, 'persona' => (string) ($f->uscente?->nome ?? ($f->registro['nomi']['uscente'] ?? '')),
                'il' => $f->decorrenza->toDateString()], $delleBeta),
            // Il giorno dell'ultimo cambio che ha già spostato l'anello: le parti di prima non sono più quelle nelle rate, e la frase lo dice.
            'dopo_il_cambio' => $ultimoCambio?->decorrenza?->toDateString(),
            // Con «Non scriverlo» la coppia del cambio è rimasta fra le parti, sull'unità: la frase dice chi la rimborsa a chi.
            'cambio_fra_le_parti' => $ultimoCambio !== null && $ultimoCambio->conguaglioNonScritto()
                ? ['entra' => (int) $ultimoCambio->anagrafica_entrante_id, 'esce' => (int) $ultimoCambio->anagrafica_uscente_id,
                    'nomi' => [(int) $ultimoCambio->anagrafica_entrante_id => (string) ($ultimoCambio->entrante?->nome ?? ''), (int) $ultimoCambio->anagrafica_uscente_id => (string) ($ultimoCambio->uscente?->nome ?? '')],
                    'parti' => self::regolatiDelCambio($ultimoCambio, $immobileId, $gestioneId)]
                : null,
        ];
    }

    /**
     * I cambi d'inquilino della .50 sull'unità, prima della fine data, che hanno spostato l'anello della gestione prima della fine con la regola del piano: con la
     * coppia scritta in saldi, anche con il conguaglio annullato dopo (le parti l'hanno regolato fra loro, `regolato_fuori`), e con «Non scriverlo», che lascia la
     * stessa coppia fra le parti (`regolato_fuori`, decisione 37). La fine li segue (`dellaFine`), e il prospetto degli oneri accessori ne dice il conguaglio, o chi lo
     * rimborsa a chi (`ProspettoRegolaDelPiano::composizione`). Un cambio sullo stesso anello dopo la fine ha la sua voce di `conguaglio.piano`, e il prospetto lo legge
     * con i passaggi dopo.
     *
     * @return list<Subentro>
     */
    public static function cambiCheHannoSpostatoLAnello(int $immobileId, int $gestioneId, ?int $primaDi = null): array
    {
        return Subentro::where('immobile_id', $immobileId)->where('tipo_passaggio', 'fine_locazione')->whereNotNull('anagrafica_entrante_id')
            ->when($primaDi !== null, fn ($q) => $q->where('id', '<', $primaDi))->orderBy('id')->get()
            ->map(fn (Subentro $s) => $s->capo())->unique('id')
            ->filter(fn (Subentro $c) => $c->conguaglioAnnullato() || $c->conguaglioNonScritto()
                ? self::regolatiDelCambio($c, $immobileId, $gestioneId) !== []
                : Saldo::where('subentro_id', (int) $c->id)->where('immobile_id', $immobileId)->where('gestione_id', $gestioneId)->exists())
            ->values()->all();
    }

    /**
     * Ciò che le parti hanno regolato fra loro con un cambio d'inquilino («Annulla il conguaglio», o «Non scriverlo»), per persona, su un'unità e una gestione:
     * `regolato_fuori` è per gestione, e con le pertinenze spuntate somma l'unità e il box. Il registro lo dice unità per unità (`per_unita`); un registro scritto
     * prima, con le pertinenze, divide la cifra della gestione fra le unità come il cambio ha diviso la sua coppia, per giorni: le quote congelate di chi esce
     * sui piani che il cambio ha preso, voce per voce, sui giorni della competenza dal cambio in poi, con il segno (`quoteDiChiEsce`: un'entrata va contro le
     * spese), e la cifra divisa con quelle coppie (`perUnita`). Senza pertinenze la cifra della gestione è quella dell'unità.
     *
     * @return array<int, int> anagrafica => importo, con il segno dei saldi
     */
    public static function regolatiDelCambio(Subentro $c, int $immobileId, int $gestioneId): array
    {
        $unita = [(int) $c->immobile_id, ...$c->pertinenze()->pluck('immobile_id')->map(fn ($id) => (int) $id)->all()];
        $pesi = null;
        $out = [];
        foreach (collect($c->registro['regolato_fuori'] ?? [])->filter(fn ($g) => is_array($g) && (int) ($g['gestione_id'] ?? 0) === $gestioneId) as $g) {
            if (isset($g['per_unita'])) {
                $persone = (array) (collect($g['per_unita'])->first(fn ($u) => (int) ($u['immobile_id'] ?? 0) === $immobileId)['persone'] ?? []);
            } elseif (count($unita) === 1) {
                $persone = (int) $unita[0] === $immobileId ? (array) ($g['persone'] ?? []) : [];
            } else {
                $pesi ??= self::quoteDiChiEsce($c, $unita, $gestioneId);
                // La cifra della gestione è quella di chi entra (`importo`, con il segno dei saldi); chi esce ha la stessa, con il segno opposto.
                $parte = (int) (self::perUnita((int) ($g['importo'] ?? 0), $pesi, (int) $c->immobile_id)[$immobileId] ?? 0);
                $persone = array_map(fn (array $p) => ['anagrafica_id' => $p['anagrafica_id'],
                    'importo' => (int) $p['anagrafica_id'] === (int) $c->anagrafica_uscente_id ? -$parte : $parte], (array) ($g['persone'] ?? []));
            }
            foreach ($persone as $p) {
                $out[(int) $p['anagrafica_id']] = ($out[(int) $p['anagrafica_id']] ?? 0) + (int) $p['importo'];
            }
        }

        return array_filter($out, fn (int $x) => $x !== 0);
    }

    /**
     * La coppia del cambio, per giorni, su ognuna delle unità date, sui piani della gestione che il cambio ha preso nel conguaglio (un piano generato dopo non
     * l'ha diviso): la parte dell'inquilino congelata di chi esce, voce per voce, per i giorni della competenza dal cambio in poi sui giorni della competenza
     * (una voce con la competenza finita prima del cambio non pesa), con il segno dei saldi di chi entra: un'entrata va contro le spese, come nella coppia del
     * cambio. Sono i pesi della divisione di `regolatiDelCambio`: con una voce di una sola unità, le sole quote darebbero alle unità una cifra che il cambio non
     * ha diviso così.
     *
     * @param list<int> $unita
     * @return array<int, float>
     */
    private static function quoteDiChiEsce(Subentro $c, array $unita, int $gestioneId): array
    {
        $regola = new self();
        $dal = CarbonImmutable::parse($c->decorrenza->toDateString());
        $pesi = array_fill_keys($unita, 0.0);
        foreach (PianoRate::where('gestione_id', $gestioneId)->orderBy('id')->get()->filter(fn (PianoRate $p) => $p->presoNelConguaglioDa($c)) as $piano) {
            foreach ($unita as $u) {
                $anelli = $regola->anelli($piano, $u);
                if (isset($anelli['ferma'])) {
                    continue;
                }
                foreach ($anelli as $v) {
                    $competenza = $v['competenza'];
                    $dopo = $dal->lte($competenza->al()) ? $competenza->giorniDiSovrapposizione(new PeriodoCompetenza($dal, $competenza->al())) : 0;
                    $pesi[$u] += (float) ((int) ($v['prima'][(int) $c->anagrafica_uscente_id] ?? 0)) * $dopo / max(1, $competenza->giorni());
                }
            }
        }

        return $pesi;
    }

    /**
     * La cifra della gestione di un registro scritto prima, divisa fra le unità del cambio con le sue coppie ricostruite (`quoteDiChiEsce`). Coppie dello stesso
     * segno: in proporzione, con i resti maggiori. Coppie di segno diverso (un'entrata che su un'unità supera le spese): la proporzione non regge, perché la
     * somma è più piccola delle parti; ogni unità ha la sua coppia arrotondata al centesimo, e la differenza dalla cifra della gestione va sull'unità del passaggio.
     *
     * @param array<int, float> $pesi unità => la coppia ricostruita
     * @return array<int, int> unità => la sua parte della cifra
     */
    private static function perUnita(int $cifra, array $pesi, int $delPassaggio): array
    {
        if (! (min($pesi) < 0.0 && max($pesi) > 0.0)) {
            return MoneyHelper::ripartisciPerQuote($cifra, array_map(fn (float $w) => abs($w), $pesi));
        }
        $parti = array_map(fn (float $w) => (int) round($w), $pesi);
        $parti[$delPassaggio] = $cifra - (array_sum($parti) - ($parti[$delPassaggio] ?? 0));

        return $parti;
    }

    /**
     * Le fini delle beta (.50–.53) con la fermata del coinquilino su questa unità e gestione (`fermata_coinquilino`), prima di questa fine, nell'ordine.
     *
     * @return list<Subentro>
     */
    private static function finiConLaFermataDelCoinquilino(int $immobileId, int $gestioneId): array
    {
        $condominio = (int) DB::table('immobili')->where('id', $immobileId)->value('condominio_id');

        return Subentro::where('condominio_id', $condominio)->whereNull('subentro_padre_id')->where('tipo_passaggio', 'fine_locazione')->orderBy('id')->get()
            ->filter(fn (Subentro $s) => collect($s->registro['fermata_coinquilino'] ?? [])->contains(fn ($f) => is_array($f) && (int) ($f['immobile_id'] ?? 0) === $immobileId
                && (int) ($f['gestione_id'] ?? 0) === $gestioneId))->values()->all();
    }

    /**
     * Chi riceve i giorni di chi esce, nella prima fine e nei passaggi dopo una fine con la regola del piano: i conduttori che restano il giorno
     * del passaggio, chi entra nel cambio d'inquilino, chi sta al posto dell'inquilino nei giorni che nessun conduttore copre. Chi era già uscito
     * non riceve: la sua parte, se la regola del piano la cambia, resta fuori e si dice (decisione 95.6 b). Con chi può avere una riga in saldi:
     * chi ha quote del piano sull'unità o righe di coppie già scritte lì, chi entra, e chi sta al posto dell'inquilino anche senza quote (l'usufruttuaria
     * con le voci sul «Proprietario»), che risponde verso il condominio come nella fine senza coinquilini; non un conduttore senza quote (decisione 45).
     *
     * @param list<array<string, mixed>> $voci le voci di `differenze`
     * @param list<int> $pianoIds
     * @return array{0: array<int, true>, 1: array<int, true>, 2: array<int, true>} chi può avere una riga, chi resta conduttore, chi riceve
     */
    private static function chiRiceve(array $voci, ?int $chiEsce, ?int $entra, int $immobileId, array $pianoIds, int $gestioneId, CarbonImmutable $decorrenza): array
    {
        $ripiego = array_fill_keys(array_merge([], ...array_map(fn (array $v) => $v['ripiego'], $voci)), true);
        $entrante = $entra !== null ? [$entra => true] : [];
        $restano = ChiPagaAlPostoDellInquilino::coinquiliniCheRestano($immobileId, $chiEsce ?? $entra ?? 0, $decorrenza)
            ->mapWithKeys(fn ($t) => [(int) $t->anagrafica_id => true])->all();
        // Ricevono anche i conduttori la cui riga comincia dopo il giorno del passaggio: coprono una parte dei giorni di chi esce. È già uscito solo chi
        // ha tutte le righe d'inquilino finite prima di quel giorno.
        $dopo = TitolaritaImmobile::where('immobile_id', $immobileId)->where('tipologia', RuoloAnagraficaImmobile::INQUILINO->value)->get()
            ->filter(fn (TitolaritaImmobile $t) => (int) $t->anagrafica_id !== (int) ($chiEsce ?? 0) && (float) $t->quota > 0
                && ($t->data_fine === null || $t->data_fine->toDateString() >= $decorrenza->toDateString()))
            ->mapWithKeys(fn (TitolaritaImmobile $t) => [(int) $t->anagrafica_id => true])->all();

        return [self::scrivibili($pianoIds, $immobileId, $gestioneId) + $ripiego + $entrante, $restano, $restano + $dopo + $ripiego + $entrante];
    }

    /**
     * Chi resta fuori dalla coppia, per la fotografia: se il resto si regola dal Wallet (tutte le sue persone possono avere una riga in saldi), chi
     * era già uscito, chi resta conduttore, e chi del resto non può avere una riga in saldi.
     *
     * @param array{parti: array<int, int>, resto: array<int, int>} $coppia
     * @param array<int, true> $scrivibili
     * @param array<int, true> $restano
     * @param array<int, true> $riceventi
     * @return array{wallet: bool, gia_usciti: list<int>, restano: list<int>, senza_righe: list<int>}
     */
    private static function chiRestaFuori(array $coppia, array $scrivibili, array $restano, array $riceventi, ?int $chiEsce): array
    {
        return [
            'wallet' => $coppia['resto'] !== [] && array_diff_key($coppia['resto'], $scrivibili) === [],
            'gia_usciti' => array_values(array_map('intval', array_keys(array_diff_key($coppia['resto'], $riceventi, $chiEsce !== null ? [$chiEsce => true] : [])))),
            'restano' => array_values(array_map('intval', array_keys($restano))),
            'senza_righe' => array_values(array_map('intval', array_keys(array_diff_key($coppia['resto'], $scrivibili)))),
        ];
    }

    /**
     * Decisione 95.6 (b): la fine con la regola del piano sull'anello di un gruppo (un'unità, una gestione, i piani del gruppo), registrata
     * e non annullata, con qualunque scelta e anche con il conguaglio annullato: la sua voce di `conguaglio.piano` è sui piani dati. Con lei
     * l'ultima voce della regola del piano su quell'anello (la fine stessa, o un passaggio dopo che la segue: `segue_fine_id`), che dice chi
     * era uscito per ultimo. Null fuori da quell'anello: lì valgono le regole di prima. Le fini registrate da una beta con la fermata del
     * coinquilino non hanno `conguaglio.piano`: i passaggi dopo seguono la regola di oggi (decisione 111.2).
     *
     * L'anello è dell'unità e della gestione, non del piano: un piano della stessa gestione generato dopo la fine (un integrativo con una voce nuova)
     * non è nella voce della fine, ma in quella del passaggio dopo che l'ha preso. La fine è allora quella che la voce trovata segue (`segue_fine_id`), e
     * i piani dell'anello (`piani`) sono quelli di tutte le voci della fine e dei passaggi che la seguono. L'ultima voce è l'ultima sui piani dati.
     *
     * Un piano della gestione e dell'esercizio della fine generato dopo di lei, che non si ricalcola più, è sull'anello anche prima che una voce lo nomini
     * (`generatiDopo`): un inizio o una fine senza coppia, che una voce non la scrivono, lo dividono con la regola del piano, e il prospetto lo legge così.
     * Senza, l'inizio lo lasciava nelle rate di chi c'era, la fine dopo lo divideva lo stesso, e il prospetto lo leggeva per giorni.
     *
     * @param list<int> $pianoIds
     * @return ?array{fine: Subentro, voce: array<string, mixed>, ultimo: Subentro, ultima: array<string, mixed>, piani: list<int>}
     */
    public static function sullAnello(int $immobileId, int $gestioneId, array $pianoIds): ?array
    {
        $condominio = (int) DB::table('immobili')->where('id', $immobileId)->value('condominio_id');
        $pianoIds = array_map('intval', $pianoIds);
        $piani = fn (array $g) => array_map(fn (array $v) => (int) ($v['piano_rate_id'] ?? 0), (array) ($g['voci'] ?? []));
        $delGruppo = [];
        foreach (Subentro::where('condominio_id', $condominio)->whereNull('subentro_padre_id')->where('tipo_passaggio', 'fine_locazione')->orderBy('id')->get() as $s) {
            foreach ($s->registro['conguaglio']['piano'] ?? [] as $g) {
                if ((int) ($g['immobile_id'] ?? 0) === $immobileId && (int) ($g['gestione_id'] ?? 0) === $gestioneId) {
                    $delGruppo[] = [$s, $g];
                }
            }
        }
        // La fine (la voce senza `segue_fine_id`) e le voci dei passaggi che la seguono.
        $dellAnello = fn (int $fineId) => array_values(array_filter($delGruppo, fn (array $x) => ((int) $x[0]->id === $fineId && ($x[1]['segue_fine_id'] ?? null) === null)
            || (int) ($x[1]['segue_fine_id'] ?? 0) === $fineId));
        $suiPiani = array_values(array_filter($delGruppo, fn (array $x) => array_intersect($piani($x[1]), $pianoIds) !== []));
        if ($suiPiani === []) {
            foreach (array_filter($delGruppo, fn (array $x) => ($x[1]['segue_fine_id'] ?? null) === null) as $x) {
                $anello = $dellAnello((int) $x[0]->id);
                if (array_intersect(self::generatiDopo($anello, $gestioneId), $pianoIds) !== []) {
                    $suiPiani = $anello;
                    break;
                }
            }
            if ($suiPiani === []) {
                return null;
            }
        }
        // La fine è la voce senza `segue_fine_id`: la prima trovata, o quella che la prima trovata segue. Viene prima dei passaggi che la seguono, perché
        // un passaggio dopo sullo stesso anello è registrato dopo di lei e si annulla prima (decisione 83.1).
        $fineId = (int) ($suiPiani[0][1]['segue_fine_id'] ?? 0) ?: (int) $suiPiani[0][0]->id;
        $anello = $dellAnello($fineId);
        $ultima = end($suiPiani);

        return ['fine' => $anello[0][0], 'voce' => $anello[0][1], 'ultimo' => $ultima[0], 'ultima' => $ultima[1],
            'piani' => array_values(array_unique([...array_merge(...array_map(fn (array $x) => $piani($x[1]), $anello)), ...self::generatiDopo($anello, $gestioneId)]))];
    }

    /**
     * I piani della gestione e dell'esercizio della fine che apre l'anello generati dopo di lei (le loro prime quote vengono dopo la fine, `quoteCeranoAl`), che non
     * si ricalcolano più (`eImmutabile`: con le rate emesse, gli incassi o un conguaglio) e che nessuna voce dell'anello nomina ancora. Un piano che si ricalcola ancora
     * si ridivide con chi c'è quando si ricalcola, e non è sull'anello; uno generato prima della fine, che la fine non ha preso perché allora si ricalcolava, ed emesso
     * dopo senza ricalcolo, resta con le quote di prima, come lo dice la decisione 40.
     *
     * @param non-empty-list<array{0: Subentro, 1: array<string, mixed>}> $anello le voci dell'anello, la fine per prima
     * @return list<int>
     */
    private static function generatiDopo(array $anello, int $gestioneId): array
    {
        [$fine, $voce] = $anello[0];
        $nelle = array_merge(...array_map(fn (array $x) => array_map(fn (array $v) => (int) ($v['piano_rate_id'] ?? 0), (array) ($x[1]['voci'] ?? [])), $anello));
        $esercizio = $voce['esercizio_id'] ?? null;

        return PianoRate::where('gestione_id', $gestioneId)->when($esercizio !== null, fn ($q) => $q->where('esercizio_id', (int) $esercizio))
            ->whereNotIn('id', $nelle ?: [0])->orderBy('id')->get()
            ->filter(fn (PianoRate $p) => ! $p->quoteCeranoAl($fine) && $p->eImmutabile())
            ->map(fn (PianoRate $p) => (int) $p->id)->values()->all();
    }

    /**
     * I gruppi della regola del piano su un'unità (gestione e piani), per i passaggi che non passano dal conguaglio (l'inizio di un conduttore,
     * la fine di chi non ha quote del piano): uno per voce di `conguaglio.piano` della fine sull'anello, con la sua gestione e i piani dell'anello
     * (`sullAnello`: anche quelli generati dopo la fine e presi da un passaggio dopo).
     *
     * @return list<array{gestione_id: int, piani: list<int>, anello: array<string, mixed>}>
     */
    public static function gruppiDellUnita(int $immobileId): array
    {
        $condominio = (int) DB::table('immobili')->where('id', $immobileId)->value('condominio_id');
        $gruppi = [];
        foreach (Subentro::where('condominio_id', $condominio)->whereNull('subentro_padre_id')->where('tipo_passaggio', 'fine_locazione')->orderBy('id')->get() as $s) {
            foreach ($s->registro['conguaglio']['piano'] ?? [] as $g) {
                if ((int) ($g['immobile_id'] ?? 0) !== $immobileId || ($g['segue_fine_id'] ?? null) !== null) {
                    continue;
                }
                $piani = array_values(array_unique(array_map(fn (array $v) => (int) ($v['piano_rate_id'] ?? 0), (array) ($g['voci'] ?? []))));
                $anello = self::sullAnello($immobileId, (int) $g['gestione_id'], $piani);
                if ($anello !== null) {
                    $gruppi[] = ['gestione_id' => (int) $g['gestione_id'], 'piani' => $anello['piani'], 'anello' => $anello];
                }
            }
        }

        return $gruppi;
    }

    /**
     * Decisione 95.6 (b), con le decisioni 111.3 e 45: un passaggio sull'anello di una fine con la regola del piano (`sullAnello`). La parte di
     * ognuno con le righe di dopo il passaggio meno quella con le righe di prima: la differenza del piano del passaggio, che non dipende dalla
     * scelta della fine di prima. Si scrive solo la differenza di chi esce, divisa fra chi riceve i suoi giorni (i conduttori che restano, chi
     * entra nel cambio d'inquilino, chi sta al posto dell'inquilino nei giorni che nessun conduttore copre), se ha una riga possibile; il resto
     * (`resto`) si dice con la persona e la cifra. Se nessuno era già uscito e la cosa torna, si scrivono le differenze di tutti, come alla
     * prima fine. Senza chi esce con quote del piano (l'inizio di un conduttore, la fine di chi non ha quote) non si scrive niente: tutto è
     * resto, un'informazione.
     *
     * @param array{fine: Subentro, voce: array<string, mixed>, ultimo: Subentro, ultima: array<string, mixed>} $anello
     * @param Collection<int, object> $righeDopo le righe dell'unità come le lascia il passaggio
     * @param list<int> $pianoIds
     * @return array<string, mixed> la fotografia (`fotografia`), con `segue_fine_id`; con `ferma`, il motivo
     */
    public function dopoUnaFine(array $anello, ?int $chiEsce, ?int $entra, int $immobileId, array $pianoIds, int $gestioneId, CarbonImmutable $decorrenza, Collection $righeDopo): array
    {
        $voci = [];
        foreach (PianoRate::with('gestione')->findMany($pianoIds)->sortBy('id') as $piano) {
            $anelli = $this->anelli($piano, $immobileId);
            if (isset($anelli['ferma'])) {
                return $anelli;
            }
            array_push($voci, ...$anelli);
        }
        $righe = $this->chiPaga->righe($immobileId);
        $voci = $this->differenze($voci, $righe, $righeDopo, $chiEsce, dopoUnaFine: true, primoPrima: (int) ($anello['ultima']['uscente_id'] ?? 0) ?: null);
        // Chi riceve i giorni di chi esce (`chiRiceve`, la stessa regola della prima fine). All'inizio di un conduttore, i conduttori che ci sono il giorno
        // prima e restano: le frasi li nominano prima di chi era già uscito.
        [$scrivibili, $restano, $riceventi] = self::chiRiceve($voci, $chiEsce, $entra, $immobileId, $pianoIds, $gestioneId, $decorrenza);
        if ($chiEsce === null || ! isset($scrivibili[$chiEsce])) {
            $differenze = self::differenzeDi($voci);
            $coppia = ['parti' => [], 'resto' => $differenze, 'differenze' => $differenze];
        } else {
            $coppia = self::coppia($voci, $chiEsce, array_intersect_key($scrivibili, $riceventi));
        }

        return $this->fotografia($voci, $coppia, $chiEsce, $immobileId, $gestioneId) + [
            'segue_fine_id' => (int) $anello['fine']->id,
        ] + self::chiRestaFuori($coppia, $scrivibili, $restano, $riceventi, $chiEsce) + [
            'entra' => $entra,
            // Chi esce non ha quote del piano (un conduttore entrato su un piano che non si ricalcolava più): niente coppia, un'informazione.
            'senza_quote' => $chiEsce !== null && ! isset($scrivibili[$chiEsce]),
            // Da quando i giorni di chi esce vanno a chi sta al posto dell'inquilino, per la frase: il primo giorno che questo passaggio ha reso scoperto (non
            // quello di giorni scoperti già da un passaggio prima).
            'ripiego_dal' => collect($voci)->filter(fn (array $v) => $v['scoperti'] !== null && $v['ripiego'] !== [])
                ->map(fn (array $v) => ($v['scoperti_nuovi'] ?? $v['scoperti'])->dal()->toDateString())->min(),
        ];
    }

    /**
     * Chi può avere una riga in saldi su un'unità: chi ha quote dei piani dati sull'unità, con qualunque ruolo, e chi ha già righe di coppie
     * scritte lì sulla gestione.
     *
     * @param list<int> $pianoIds
     * @return array<int, true>
     */
    public static function scrivibili(array $pianoIds, int $immobileId, int $gestioneId): array
    {
        return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->whereIn('rate.piano_rate_id', $pianoIds ?: [0])
            ->where('rate_quote.immobile_id', $immobileId)->distinct()->pluck('rate_quote.anagrafica_id')
            ->merge(DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $immobileId)->where('gestione_id', $gestioneId)->distinct()->pluck('anagrafica_id'))
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * La fotografia della regola del piano per il registro e per le frasi: le righe scritte (`parti`, con il segno, chi esce compreso), il
     * resto detto, chi è del ripiego e la sua parte sui suoi giorni (`periodi`, la forma di `conguaglio.periodi`), i giorni che non copre
     * nessuno, e voce per voce la parte di prima e di dopo di ognuno.
     *
     * @param list<array<string, mixed>> $voci
     * @param array{parti: array<int, int>, resto: array<int, int>} $coppia
     * @return array<string, mixed>
     */
    private function fotografia(array $voci, array $coppia, ?int $chiEsce, int $immobileId, int $gestioneId): array
    {
        $ripiego = [];
        $periodi = [];
        $scoperti = null;
        $nessuno = 0;
        foreach ($voci as $v) {
            $parte = 0;
            foreach ($v['ripiego'] as $id) {
                $ripiego[$id] = true;
                $parte += (int) ($v['dopo'][$id] ?? 0) - (int) ($v['prima'][$id] ?? 0);
            }
            if ($parte !== 0 && $v['scoperti'] !== null) {
                $periodi[] = ['tratti' => $v['scoperti']->toArray(), 'importo' => $parte];
            }
            if ($v['nessuno'] !== 0 && ($v['scoperti_nessuno'] ?? null) !== null) {
                $nessuno += $v['nessuno'];
                $scoperti = $scoperti === null ? $v['scoperti_nessuno'] : $scoperti->unione($v['scoperti_nessuno']);
            }
        }
        // Decisione 111.3: in un passaggio dopo una fine con la regola del piano chi sta al posto dell'inquilino può ricevere con la coppia meno
        // della sua differenza (il resto resta fuori): i periodi portano la parte scritta, divisa sui giorni delle voci come la differenza.
        $scritto = (int) array_sum(array_intersect_key($coppia['parti'], $ripiego));
        $differenza = (int) array_sum(array_column($periodi, 'importo'));
        if ($periodi !== [] && $scritto !== $differenza && $differenza !== 0) {
            // Ogni voce in proporzione, con il suo segno (un'entrata resta un'entrata); il centesimo che avanza alla voce con il resto più grande.
            $esatti = array_map(fn (array $p) => (int) $p['importo'] * $scritto / $differenza, $periodi);
            $interi = array_map(fn (float $e) => (int) floor($e), $esatti);
            $resti = array_map(fn (float $e, int $i) => $e - $i, $esatti, $interi);
            arsort($resti);
            foreach (array_slice(array_keys($resti), 0, $scritto - (int) array_sum($interi)) as $i) {
                $interi[$i]++;
            }
            $periodi = array_values(array_filter(array_map(fn (array $p, int $i) => ['tratti' => $p['tratti'], 'importo' => $i], $periodi, $interi), fn (array $p) => $p['importo'] !== 0));
        }
        $ids = [];
        foreach ($voci as $v) {
            $ids = [...$ids, ...array_keys($v['prima']), ...array_keys($v['dopo'])];
        }
        $giorni = [];
        $competenze = array_unique(array_map(fn (array $v) => json_encode($v['competenza']->toArray()), $voci));
        if (count($competenze) === 1) {
            $giorni = $voci[0]['giorni'];
        }

        return [
            'gestione_id' => $gestioneId,
            'immobile_id' => $immobileId,
            'uscente_id' => $chiEsce,
            'parti' => $coppia['parti'],
            'resto' => $coppia['resto'],
            'ripiego' => array_values(array_map('intval', array_keys($ripiego))),
            'periodi' => $periodi,
            'nessuno' => $nessuno,
            'scoperti' => $scoperti?->toArray(),
            'giorni' => $giorni,
            'nomi' => Anagrafica::whereIn('id', array_values(array_unique($ids)) ?: [0])->pluck('nome', 'id')->map(fn ($n) => (string) $n)->all(),
            'voci' => array_map(fn (array $v) => ['piano_rate_id' => $v['piano_rate_id'], 'conto_id' => $v['conto_id'], 'conto' => $v['conto'],
                'competenza' => $v['competenza']->toArray(), 'anello' => $v['anello'], 'prima' => $v['prima'], 'dopo' => $v['dopo']], $voci),
        ];
    }

    // --- Le frasi ---------------------------------------------------------------------------------------------------------------

    /**
     * Le righe del calcolo, nel blocco «Rate» (`frasi_del_conguaglio`, che il pannello mostra prima della scelta e con «Scrivi»): la coppia
     * per persona sulla gestione e, con più voci che cambiano, voce per voce.
     *
     * @param array<string, mixed> $piano la fotografia (`dellaFine`)
     * @return list<string>
     */
    public static function frasiDelCalcolo(array $piano, string $gestione, ?string $unita = null): array
    {
        $frasi = [sprintf('Sulla %s%s, con la regola del piano: %s.', $gestione, $unita !== null && $unita !== '' ? sprintf(', nell\'unità «%s»', $unita) : '',
            self::partiInParole(self::nellOrdine($piano['parti'], (int) $piano['uscente_id']), $piano['nomi']))];
        $voci = array_values(array_filter(array_map(fn (array $v) => ['conto' => $v['conto'], 'parti' => self::differenzaDellaVoce($v)], $piano['voci']), fn (array $v) => $v['parti'] !== []));
        if (count($voci) > 1) {
            foreach ($voci as $v) {
                $frasi[] = sprintf('— «%s»: %s.', $v['conto'], self::partiInParole(self::nellOrdine($v['parti'], (int) $piano['uscente_id']), $piano['nomi']));
            }
        }

        return $frasi;
    }

    /**
     * La frase del pannello per un gruppo: che la locazione continua, la regola, le parti del piano di ognuno con i suoi giorni, quanto ha
     * nelle sue rate, le righe che «Scrivi il conguaglio» scrive e chi rimborsa chi con «Non scriverlo». Con la parte di chi non ha quote del
     * piano (il resto) dice anche quella, e che la regolano le parti.
     *
     * @param array<string, mixed> $piano la fotografia (`dellaFine`)
     * @param list<string> $coinquilini
     */
    public static function frasePannello(array $piano, string $gestione, array $coinquilini, ?string $unita = null): string
    {
        $nomi = $piano['nomi'];
        $uscente = (int) $piano['uscente_id'];
        $dopo = [];
        $prima = [];
        foreach ($piano['voci'] as $v) {
            foreach ($v['dopo'] as $id => $c) {
                $dopo[(int) $id] = ($dopo[(int) $id] ?? 0) + (int) $c;
            }
            foreach ($v['prima'] as $id => $c) {
                $prima[(int) $id] = ($prima[(int) $id] ?? 0) + (int) $c;
            }
        }
        $giorni = $piano['giorni'] ?? [];
        // La parte dei giorni che non copre nessuno è nelle rate di chi esce, ma non è dei suoi giorni: la frase la dice a parte.
        $dopo[$uscente] = ($dopo[$uscente] ?? 0) - (int) ($piano['nessuno'] ?? 0);
        $ripiego = array_flip($piano['ripiego'] ?? []);
        $delPiano = [];
        foreach (self::nellOrdine(array_filter($dopo, fn (int $c) => $c !== 0), $uscente) as $id => $c) {
            if (isset($ripiego[$id]) && ! isset($giorni[$id])) {
                continue;
            }
            $delPiano[] = isset($giorni[$id])
                ? sprintf('%s %s per i suoi %d giorni', $nomi[$id] ?? '?', self::soldi($c), (int) $giorni[$id])
                : sprintf('%s %s', $nomi[$id] ?? '?', self::soldi($c));
        }
        $delRipiego = [];
        foreach ($piano['ripiego'] ?? [] as $id) {
            if ((int) ($dopo[$id] ?? 0) !== 0 && ! isset($giorni[$id])) {
                $delRipiego[] = sprintf('%s %s', $nomi[$id] ?? '?', self::soldi((int) $dopo[$id]));
            }
        }
        $periodi = array_merge(...array_map(fn (array $p) => $p['tratti'], $piano['periodi'] ?? [])) ?: [];
        $suiGiorni = $delRipiego === [] ? '' : sprintf(', e per %s, che nessun inquilino copre, %s', self::giorniInParole($periodi), self::elenco($delRipiego));
        $nessuno = (int) ($piano['nessuno'] ?? 0) !== 0 && ! empty($piano['scoperti'])
            ? sprintf(' Per %s non copre nessuno neanche chi sta al posto dell\'inquilino: la loro parte, %s, resta nelle rate di %s.', self::giorniInParole($piano['scoperti']), self::soldi((int) $piano['nessuno']), $nomi[$uscente] ?? '?')
            : '';
        $inRata = array_keys(self::nellOrdine(array_filter($prima, fn (int $c) => $c !== 0), $uscente));
        // Dopo un cambio d'inquilino che ha già spostato l'anello le parti di prima sono quelle del cambio, non quelle nelle rate (`dellaFine`).
        $dopoIlCambio = ! empty($piano['dopo_il_cambio']);
        $nelleRate = self::elenco(array_map(fn ($i, $id) => sprintf($i === 0 && ! $dopoIlCambio ? '%s ne ha %s' : '%s %s', $nomi[$id] ?? '?', self::soldi((int) $prima[$id])), array_keys($inRata), $inRata));
        $frase = sprintf('%sa locazione continua con %s: con la regola del piano la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno, come in un piano generato dopo l\'uscita. Sulla %s %s%s; %s %s.%s',
            $unita !== null && $unita !== '' ? sprintf('Nell\'unità «%s» l', $unita) : 'L', self::elenco($coinquilini) ?: 'un coinquilino', $gestione, self::elenco($delPiano), $suiGiorni,
            $dopoIlCambio ? sprintf('con il cambio d\'inquilino del %s, %s,', self::data((string) $piano['dopo_il_cambio']), self::comeIlCambio($piano['cambio_fra_le_parti'] ?? null)) : 'nelle loro rate', $nelleRate, $nessuno);
        $parti = self::nellOrdine($piano['parti'], $uscente);
        $resto = $piano['resto'] ?? [];
        $usciti = array_values(array_filter(array_map('intval', (array) ($piano['gia_usciti'] ?? [])), fn (int $id) => isset($resto[$id])));
        if ($usciti !== []) {
            // Decisione 95.6 (b) nella prima fine: chi era già uscito non riceve i giorni di chi esce, e la sua parte non si tocca; si scrive solo la coppia
            // di chi esce, e il resto si dice come nei passaggi dopo, con la strada del Wallet o con il prospetto.
            $chiEsce = $nomi[$uscente] ?? '?';
            $frase .= $parti !== []
                ? sprintf(' Se scrivi il conguaglio, il programma scrive solo la coppia di %s: %s.', $chiEsce, self::partiInParole($parti, $nomi))
                : ' ' . self::senzaCoppia($piano, $uscente);
            // Senza una coppia non c'è la scelta, ma il registro ha la regola del piano e il prospetto degli oneri accessori la legge; non dopo una fine delle
            // beta con la fermata del coinquilino, dove il resto si dice e basta (`RegistraSubentroAction::delPiano`).
            $frase .= ' ' . self::fraseDelResto($piano, $gestione, conIlProspetto: $parti !== [] || empty($piano['dopo_la_fermata_del_coinquilino']));
            if ($parti !== []) {
                $frase .= ' Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e il prospetto degli oneri accessori dice chi rimborsa chi.';
            }

            return $frase;
        }
        if ($parti !== []) {
            $frase .= sprintf(' Se scrivi il conguaglio, le righe nei saldi sono %s. Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e %s.',
                self::partiInParole($parti, $nomi), self::chiRimborsaChi($parti, $nomi));
        }
        if ($resto !== []) {
            $senzaQuote = array_values(array_filter(array_keys($resto), fn ($id) => (int) $id !== $uscente && in_array((int) $id, (array) ($piano['senza_righe'] ?? []), true)));
            // Anche senza una coppia (e senza la scelta) il registro ha la regola del piano, e il prospetto degli oneri accessori la legge.
            $frase .= sprintf(' %s non %s quote del piano, e nei saldi non %s righe: con la regola del piano %s; la differenza la regolano le parti con il prospetto degli oneri accessori.',
                self::elenco(array_map(fn ($id) => $nomi[$id] ?? '?', $senzaQuote)) ?: 'Chi è entrato dopo', count($senzaQuote) > 1 ? 'hanno' : 'ha', count($senzaQuote) > 1 ? 'hanno' : 'ha',
                self::elenco(array_map(fn ($id, $c) => sprintf('%s pagherebbe %s in %s', $nomi[$id] ?? '?', MoneyHelper::format(abs((int) $c)), (int) $c > 0 ? 'più' : 'meno'), array_keys(self::nellOrdine($resto, $uscente)), self::nellOrdine($resto, $uscente))));
        }

        return $frase;
    }

    /**
     * Che cosa ha fatto il cambio d'inquilino che la fine segue: «che ha scritto la sua coppia», o, con «Non scriverlo», «che non ha scritto il conguaglio (Sara
     * rimborsa a Ines € 90,74 con il prospetto degli oneri accessori)».
     *
     * @param ?array{entra: int, esce: int, nomi: array<int, string>, parti: array<int, int>} $fraLeParti
     */
    private static function comeIlCambio(?array $fraLeParti): string
    {
        if ($fraLeParti === null) {
            return 'che ha scritto la sua coppia';
        }
        $entra = (int) ($fraLeParti['parti'][$fraLeParti['entra']] ?? 0);
        [$chi, $verso, $a] = $entra >= 0 ? [$fraLeParti['entra'], 'rimborsa', $fraLeParti['esce']] : [$fraLeParti['esce'], 'restituisce', $fraLeParti['entra']];

        return sprintf('che non ha scritto il conguaglio (%s %s %s %s con il prospetto degli oneri accessori)', $fraLeParti['nomi'][$chi] ?? '?', $verso,
            FrasiObbligati::a((string) ($fraLeParti['nomi'][$a] ?? '?')), self::soldi($entra));
    }

    /**
     * Perché la prima fine con chi era già uscito non scrive una coppia: chi riceve i giorni di chi esce, con la regola del piano, pagherebbe anche
     * lui di più (o di meno) come chi esce, e fra loro non c'è niente da spostare; oppure chi li riceve non ha quote del piano (decisione 45).
     *
     * @param array<string, mixed> $piano la fotografia (`dellaFine`), senza parti e con il resto
     */
    private static function senzaCoppia(array $piano, int $uscente): string
    {
        $nomi = $piano['nomi'];
        $resto = array_map('intval', (array) $piano['resto']);
        $diChiEsce = (int) ($resto[$uscente] ?? 0);
        $riceventi = array_values(array_filter(array_unique(array_map('intval', [...(array) ($piano['restano'] ?? []), ...(array) ($piano['ripiego'] ?? [])])),
            fn (int $id) => (int) ($resto[$id] ?? 0) !== 0));
        $comeLui = array_values(array_filter($riceventi, fn (int $id) => $diChiEsce !== 0 && ($resto[$id] > 0) === ($diChiEsce > 0)));
        if ($comeLui !== [] && $comeLui === $riceventi) {
            $piu = count($comeLui) > 1;

            return sprintf('Nei saldi il programma non scrive niente: con la regola del piano anche %s, che %s i giorni di %s, %s di %s, e fra loro non c\'è una coppia da scrivere.',
                self::elenco(array_map(fn (int $id) => $nomi[$id] ?? '?', $comeLui)), $piu ? 'ricevono' : 'riceve', $nomi[$uscente] ?? '?', $piu ? 'pagherebbero' : 'pagherebbe', $diChiEsce > 0 ? 'più' : 'meno');
        }
        if (! empty($piano['dopo_la_fermata_del_coinquilino'])) {
            // Decisione 111.2 (`dellaFine`): dopo una fine delle beta con la fermata del coinquilino la coppia non si scrive, e la frase del resto dice perché e la strada.
            return 'Nei saldi il programma non scrive niente: dopo una fine registrata da una versione precedente con la fermata del coinquilino la regola del piano si dice, e non si scrive.';
        }

        // Chi li riceve con una differenza di verso opposto non può avere una riga in saldi: è un conduttore senza quote del piano.
        return sprintf('Nei saldi il programma non scrive niente: chi riceve i giorni di %s, %s, non ha quote del piano.', $nomi[$uscente] ?? '?',
            self::elenco(array_map(fn (int $id) => $nomi[$id] ?? '?', array_values(array_intersect($riceventi, array_map('intval', (array) ($piano['senza_righe'] ?? [])))))));
    }

    /**
     * Decisione 95.6 (b): la frase del pannello di un passaggio dopo una fine con la regola del piano sullo stesso anello (la fine di chi
     * restava): che cosa ha fatto la fine di prima e le cifre di prima, chi riceve i giorni di chi esce e le cifre di dopo, la coppia che si
     * scrive, il resto con la persona e la cifra e la strada a mano (i saldi del Wallet legati al passaggio, o il prospetto quando una persona
     * del resto non ha quote del piano), e che cosa succede con «Non scriverlo».
     *
     * @param array<string, mixed> $piano la fotografia (`dopoUnaFine`)
     */
    public static function frasePannelloDopo(array $piano, string $gestione, string $chiEsce, ?string $unita = null): string
    {
        $nomi = $piano['nomi'];
        $nome = fn ($id) => $nomi[$id] ?? '?';
        $uscente = (int) ($piano['uscente_id'] ?? 0);
        [$prima, $dopo] = self::totali($piano);
        $fine = Subentro::conAnnullati()->find((int) ($piano['segue_fine_id'] ?? 0));
        $diPrima = (int) ($fine?->anagrafica_uscente_id ?? 0);
        $ordine = array_keys(self::nellOrdine(array_filter($prima, fn (int $c) => $c !== 0), $diPrima));
        foreach (array_keys(array_filter($dopo, fn (int $c) => $c !== 0)) as $id) {
            if (! in_array($id, $ordine, true)) {
                $ordine[] = $id;
            }
        }
        $inParole = fn (array $parti) => self::elenco(array_map(fn ($id) => sprintf('%s %s', $nome($id), self::soldi((int) $parti[$id])),
            array_values(array_filter($ordine, fn ($id) => (int) ($parti[$id] ?? 0) !== 0))));

        $frase = self::laFineDiPrima($fine, $piano, $gestione, $inParole($prima), self::comeAllora($fine, $piano, $prima)) . ' ';
        // Chi riceve i giorni di questo passaggio: chi sta al posto dell'inquilino e la cui parte cambia (non chi li aveva già con un passaggio prima).
        $ripiego = array_values(array_filter((array) ($piano['ripiego'] ?? []), fn ($id) => (int) ($dopo[$id] ?? 0) !== (int) ($prima[$id] ?? 0)));
        $restano = array_map($nome, (array) ($piano['restano'] ?? []));
        $alPosto = $ripiego === [] ? null : sprintf('i giorni dal %s%s vanno a chi paga al posto dell\'inquilino, %s', self::data((string) ($piano['ripiego_dal'] ?? '')),
            $restano !== [] ? ' che nessun conduttore copre' : '', self::elenco(array_map($nome, $ripiego)));
        $come = match (true) {
            $restano !== [] && $alPosto !== null => sprintf('la locazione continua con %s, e %s', self::elenco($restano), $alPosto),
            $restano !== [] => sprintf('la locazione continua con %s', self::elenco($restano)),
            default => $alPosto,
        };
        $voci = count(array_filter($piano['voci'], fn (array $v) => self::differenzaDellaVoce($v) !== []));
        $frase .= sprintf('Con la fine della locazione di %s%s, e la regola del piano ridivide %s: %s.', $chiEsce, $come !== null ? ' ' . $come : '', $voci > 1 ? 'le voci' : 'la voce', $inParole($dopo));
        $parti = self::nellOrdine($piano['parti'], $uscente);
        $resto = $piano['resto'] ?? [];
        $frase .= match (true) {
            $parti !== [] && $resto !== [] => sprintf(' Se scrivi il conguaglio, il programma scrive solo la coppia di %s: %s.', $chiEsce, self::partiInParole($parti, $nomi)),
            $parti !== [] => sprintf(' Se scrivi il conguaglio, le righe nei saldi sono %s.', self::partiInParole($parti, $nomi)),
            ! empty($piano['senza_quote']) => sprintf(' %s non ha quote del piano, e nei saldi non ha righe: il programma non scrive niente.', $chiEsce),
            default => sprintf(' Nei saldi il programma non scrive niente: chi riceve i giorni di %s non ha quote del piano.', $chiEsce),
        };
        if ($resto !== []) {
            $frase .= ' ' . self::fraseDelResto($piano, $gestione, $parti !== []);
        }
        if ($parti !== []) {
            $frase .= ' Se non scrivi il conguaglio, nei saldi non si scrive niente, e il prospetto degli oneri accessori dice chi rimborsa chi.';
        }

        return $unita !== null && $unita !== '' ? sprintf('Nell\'unità «%s» %s', $unita, lcfirst($frase)) : $frase;
    }

    /**
     * Decisioni 45 e 95.6 (b): l'inizio di un conduttore sull'anello di una fine con la regola del piano. Il piano non si ricalcola più, e il
     * programma non scrive niente: la frase dice le cifre della regola del piano prima e con il nuovo conduttore, e chi rimborsa chi con il prospetto
     * degli oneri accessori (se lo si dice per nome: chi entra è il solo che pagherebbe di più).
     *
     * @param array<string, mixed> $piano la fotografia (`dopoUnaFine`, senza chi esce e con chi entra)
     */
    public static function fraseDellInizio(array $piano, string $chi, string $dal, string $quota, bool $breve = false): string
    {
        $nomi = $piano['nomi'];
        $nome = fn ($id) => $nomi[$id] ?? '?';
        [$prima, $dopo] = self::totali($piano);
        $fine = Subentro::conAnnullati()->find((int) ($piano['segue_fine_id'] ?? 0));
        $diPrima = (int) ($fine?->anagrafica_uscente_id ?? 0);
        $soldi = fn (array $parti, array $ordine) => self::elenco(array_map(fn ($id) => sprintf('%s %s', $nome($id), self::soldi((int) $parti[$id])),
            array_values(array_filter($ordine, fn ($id) => (int) ($parti[$id] ?? 0) !== 0))));
        $ordinePrima = array_keys(self::nellOrdine(array_filter($prima, fn (int $c) => $c !== 0), $diPrima));
        // Con il nuovo conduttore: lui per primo, poi chi resta conduttore, poi gli altri come prima.
        $ordineDopo = array_values(array_unique([...array_filter([(int) ($piano['entra'] ?? 0)]), ...array_map('intval', (array) ($piano['restano'] ?? [])), ...$ordinePrima, ...array_keys($dopo)]));
        $chiEsce = (string) ($fine?->uscente?->nome ?? ($fine?->registro['nomi']['uscente'] ?? 'l\'inquilino'));
        $il = $fine?->decorrenza !== null ? self::data($fine->decorrenza->toDateString()) : '?';
        $cifre = $soldi($prima, $ordinePrima);
        $comeAllora = self::comeAllora($fine, $piano, $prima);
        $testa = match (true) {
            $fine !== null && $fine->conguaglioAnnullato() => sprintf('La fine locazione di %s del %s ha diviso la parte dell\'inquilino con la regola del piano%s, e il suo conguaglio è stato annullato: le parti hanno regolato fra loro la differenza di quella fine.',
                $chiEsce, $il, $comeAllora ? sprintf(' (%s)', $cifre) : ''),
            // La prima fine senza una coppia (nessuna scelta): non ha scritto righe, e ha lasciato il resto da regolare a mano.
            $fine !== null && ! isset($fine->registro['conguaglio']['scelta']) => sprintf('La fine locazione di %s del %s ha diviso la parte dell\'inquilino con la regola del piano%s, senza righe nei saldi: ha lasciato da regolare a mano %s.',
                $chiEsce, $il, $comeAllora ? sprintf(' (%s)', $cifre) : '', self::restoDellaFine($fine, $piano)),
            ($fine?->registro['conguaglio']['scelta'] ?? null) === Subentro::CONGUAGLIO_SCRITTO => sprintf('La fine locazione di %s del %s ha diviso la parte dell\'inquilino con la regola del piano%s.',
                $chiEsce, $il, $comeAllora ? sprintf(' (%s)', $cifre) : ''),
            default => sprintf('La fine locazione di %s del %s non ha scritto il conguaglio della regola del piano%s: la differenza di quella fine resta fra le parti, con il prospetto degli oneri accessori.',
                $chiEsce, $il, $comeAllora ? sprintf(' (%s)', $cifre) : ''),
        };
        if (! $comeAllora) {
            $testa .= sprintf(' Prima di questo inizio la regola del piano dava %s.', $cifre);
        }
        $resto = $piano['resto'] ?? [];
        $piu = array_keys(array_filter($resto, fn (int $c) => $c > 0));
        $meno = array_values(array_filter($ordineDopo, fn ($id) => (int) ($resto[$id] ?? 0) < 0));
        $rimborsa = $piu === [(int) ($piano['entra'] ?? 0)] && $meno !== []
            ? sprintf('%s rimborsa con il prospetto degli oneri accessori %s', $chi, self::elenco(array_map(fn ($id) => sprintf('%s %s', self::soldi((int) $resto[$id]), FrasiObbligati::a($nome($id))), $meno)))
            : 'il prospetto degli oneri accessori dice chi rimborsa chi';

        if ($breve) {
            // Per le informazioni del cancello: la sola regola del piano con il nuovo conduttore, e chi rimborsa chi.
            return sprintf('con la regola del piano, con %s dal %s, %s, e %s', $chi, $dal, $soldi($dopo, $ordineDopo), $rimborsa);
        }

        return sprintf('%s Con %s dal %s al %s %% la regola del piano darebbe %s: il programma non scrive niente, e %s.', $testa, $chi, $dal, $quota, $soldi($dopo, $ordineDopo), $rimborsa);
    }

    /**
     * Decisione 95.6 (b): il cambio d'inquilino sull'anello di una fine con la regola del piano. Chi entra riceve la differenza di chi esce;
     * la parte di chi era già uscito non cambia (o, se cambia, resta fuori e si dice). Con «Non scriverlo» chi entra rimborsa con il prospetto.
     *
     * @param array<string, mixed> $piano la fotografia (`dopoUnaFine`, con chi esce e chi entra)
     */
    public static function fraseDelCambio(array $piano, string $gestione, string $chiEsce, string $chiEntra): string
    {
        $nomi = $piano['nomi'];
        $uscente = (int) ($piano['uscente_id'] ?? 0);
        $entra = (int) ($piano['entra'] ?? 0);
        [$prima, $dopo] = self::totali($piano);
        $parti = self::nellOrdine(array_map('intval', $piano['parti']), $uscente);
        $fermi = array_values(array_filter(array_keys(array_filter($prima, fn (int $c) => $c !== 0)),
            fn ($id) => $id !== $uscente && $id !== $entra && (int) ($dopo[$id] ?? 0) === (int) $prima[$id]));
        $nonCambia = $fermi === [] ? '' : sprintf('la parte di %s non cambia, e ', self::elenco(array_map(fn ($id) => $nomi[$id] ?? '?', $fermi)));
        $soloLaCoppia = array_keys($parti) === [$uscente, $entra] || array_keys($parti) === [$entra, $uscente];
        $passa = (int) ($parti[$entra] ?? 0);
        $comeVa = match (true) {
            $soloLaCoppia && $passa > 0 => sprintf('%s della parte di %s passano %s', self::soldi($passa), $chiEsce, FrasiObbligati::a($chiEntra)),
            $soloLaCoppia => sprintf('un credito di %s della parte di %s passa %s', self::soldi($passa), $chiEsce, FrasiObbligati::a($chiEntra)),
            default => sprintf('le righe sono %s', self::partiInParole($parti, $nomi)),
        };
        $frase = sprintf('Se scrivi il conguaglio, le sue righe fra %s e %s, che entra come inquilino, entrano nei saldi e sommano a zero: con la regola del piano %s%s.', $chiEsce, $chiEntra, $nonCambia, $comeVa);
        if (($piano['resto'] ?? []) !== []) {
            $frase .= ' ' . self::fraseDelResto($piano, $gestione);
        }
        $rimborso = match (true) {
            $soloLaCoppia && $passa > 0 => sprintf('%s rimborsa %s %s con il prospetto degli oneri accessori', $chiEntra, FrasiObbligati::a($chiEsce), self::soldi($passa)),
            $soloLaCoppia => sprintf('%s restituisce %s %s con il prospetto degli oneri accessori', $chiEsce, FrasiObbligati::a($chiEntra), self::soldi($passa)),
            default => 'il prospetto degli oneri accessori dice chi rimborsa chi',
        };

        return $frase . sprintf(' Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e %s.', $rimborso);
    }

    /**
     * La fine con la regola del piano che il passaggio segue, e le cifre di prima del passaggio: con «Scrivi» le ha portate la coppia; con «Non
     * scriverlo» le dice il piano, e la differenza di quella fine resta fra le parti; con il conguaglio annullato l'hanno regolata le parti.
     *
     * @param array<string, mixed> $piano
     */
    private static function laFineDiPrima(?Subentro $fine, array $piano, string $gestione, string $cifre, bool $comeAllora = true): string
    {
        $chi = (string) ($fine?->uscente?->nome ?? ($fine?->registro['nomi']['uscente'] ?? 'l\'inquilino'));
        $il = $fine?->decorrenza !== null ? self::data($fine->decorrenza->toDateString()) : '?';
        $differenza = self::differenzaDellaFine($fine, $piano);
        // Con passaggi registrati dopo la fine (un inizio, un cambio) le cifre di prima di questo passaggio non sono più quelle della fine: si dicono a parte.
        $dopo = sprintf(' Prima di questo passaggio, con la regola del piano, sulla %s %s.', $gestione, $cifre);

        return match (true) {
            $fine !== null && $fine->conguaglioAnnullato() => sprintf('La fine locazione di %s del %s ha scritto il conguaglio con la regola del piano, e il conguaglio è stato annullato: le parti hanno regolato fra loro la differenza di quella fine, %s.%s',
                $chi, $il, $differenza, $comeAllora ? sprintf(' Con la regola del piano, sulla %s, %s.', $gestione, $cifre) : $dopo),
            // La prima fine senza una coppia (nessuna scelta): non ha scritto righe, e ha lasciato il resto da regolare a mano, con la sua strada.
            $fine !== null && ! isset($fine->registro['conguaglio']['scelta']) => sprintf('La fine locazione di %s del %s non ha scritto righe nei saldi: la regola del piano ha lasciato da regolare a mano %s.%s',
                $chi, $il, self::restoDellaFine($fine, $piano), $comeAllora ? sprintf(' Con la regola del piano, sulla %s, %s.', $gestione, $cifre) : $dopo),
            ($fine?->registro['conguaglio']['scelta'] ?? null) === Subentro::CONGUAGLIO_SCRITTO => $comeAllora
                ? sprintf('La fine locazione di %s del %s ha scritto il conguaglio con la regola del piano: sulla %s %s.', $chi, $il, $gestione, $cifre)
                : sprintf('La fine locazione di %s del %s ha scritto il conguaglio con la regola del piano.%s', $chi, $il, $dopo),
            default => $comeAllora
                ? sprintf('La fine locazione di %s del %s non ha scritto il conguaglio: con la regola del piano sulla %s %s, e la differenza di quella fine, %s, resta fra le parti, con il prospetto degli oneri accessori.',
                    $chi, $il, $gestione, $cifre, $differenza)
                : sprintf('La fine locazione di %s del %s non ha scritto il conguaglio, e la differenza di quella fine, %s, resta fra le parti, con il prospetto degli oneri accessori.%s', $chi, $il, $differenza, $dopo),
        };
    }

    /**
     * La differenza che la fine con la regola del piano ha scritto, o lasciato fra le parti, sul gruppo del passaggio: «€ 60,66 a credito di Ines e
     * € 60,66 a debito di Dora».
     *
     * @param array<string, mixed> $piano
     */
    private static function differenzaDellaFine(?Subentro $fine, array $piano): string
    {
        $voce = self::voceDellaFine($fine, $piano);

        return self::partiInParole(self::nellOrdine(array_map('intval', (array) ($voce['parti'] ?? [])), (int) ($fine?->anagrafica_uscente_id ?? 0)), (array) ($voce['nomi'] ?? []));
    }

    /**
     * Il resto che la prima fine senza coppia ha lasciato da regolare a mano, sul gruppo del passaggio: chi era già uscito per primo, come nella sua frase.
     *
     * @param array<string, mixed> $piano
     */
    private static function restoDellaFine(Subentro $fine, array $piano): string
    {
        $voce = (array) self::voceDellaFine($fine, $piano);

        return self::partiInParole(self::restoInOrdine($voce), (array) ($voce['nomi'] ?? []));
    }

    /**
     * La voce di `conguaglio.piano` della fine con la regola del piano per il gruppo del passaggio.
     *
     * @param array<string, mixed> $piano
     * @return ?array<string, mixed>
     */
    private static function voceDellaFine(?Subentro $fine, array $piano): ?array
    {
        return collect($fine?->registro['conguaglio']['piano'] ?? [])->first(fn ($g) => (int) ($g['immobile_id'] ?? 0) === (int) $piano['immobile_id']
            && (int) ($g['gestione_id'] ?? 0) === (int) $piano['gestione_id'] && ($g['segue_fine_id'] ?? null) === null);
    }

    /**
     * Le parti di prima di questo passaggio sono ancora quelle che la fine con la regola del piano ha dato (nessun inizio, cambio o fine registrati in
     * mezzo le ha cambiate)?
     *
     * @param array<string, mixed> $piano
     * @param array<int, int> $prima
     */
    private static function comeAllora(?Subentro $fine, array $piano, array $prima): bool
    {
        $voce = self::voceDellaFine($fine, $piano);
        if ($voce === null) {
            return true;
        }
        $allora = array_filter(self::totali($voce)[1], fn (int $c) => $c !== 0);
        $ora = array_filter($prima, fn (int $c) => $c !== 0);
        ksort($allora);
        ksort($ora);

        return $allora === $ora;
    }

    /**
     * Il resto di un passaggio dopo una fine con la regola del piano, con la persona e la cifra: la parte di chi era già uscito non si tocca, e
     * la strada a mano è il Wallet, con i saldi legati al passaggio (decisione 95.6 b); se una persona del resto non ha quote del piano, lo
     * regolano le parti con il prospetto (decisione 45).
     *
     * @param array<string, mixed> $piano la fotografia (`dopoUnaFine`)
     */
    private static function fraseDelResto(array $piano, string $gestione, bool $conCoppia = true, bool $conIlProspetto = true): string
    {
        $nomi = $piano['nomi'];
        $usciti = array_values(array_filter(array_map('intval', (array) ($piano['gia_usciti'] ?? [])), fn (int $id) => isset($piano['resto'][$id])));
        $resto = self::restoInOrdine($piano, $conCoppia);
        $voci = [];
        foreach ($resto as $id => $c) {
            $voci[] = sprintf($voci === [] ? '%s pagherebbe %s in %s' : '%s %s in %s', $nomi[$id] ?? '?', MoneyHelper::format(abs((int) $c)), (int) $c > 0 ? 'più' : 'meno');
        }
        $ultimo = array_pop($voci);
        $cifre = $voci === [] ? (string) $ultimo : implode(', ', $voci) . ', e ' . $ultimo;
        $frase = match (true) {
            $conCoppia && $usciti !== [] => sprintf('La parte di %s non si tocca, perché %s: con la regola del piano %s.', self::elenco(array_map(fn ($id) => $nomi[$id] ?? '?', $usciti)),
                count($usciti) > 1 ? 'le loro locazioni sono già finite' : 'la sua locazione è già finita', $cifre),
            $conCoppia => sprintf('Con la regola del piano, dopo la coppia, %s.', $cifre),
            default => sprintf('Con la regola del piano %s.', $cifre),
        };

        $leParti = $conIlProspetto ? 'la differenza la regolano le parti con il prospetto degli oneri accessori' : 'la differenza la regolano le parti';

        return $frase . match (true) {
            ! empty($piano['wallet']) => sprintf(' Se le parti la vogliono al piano, registra dal Wallet i saldi legati a questo passaggio, sulla %s: %s.', $gestione, self::partiInParole($resto, $nomi)),
            // Decisione 115.2, con la 111.2: dopo una fine delle beta con la fermata del coinquilino, per la regola del piano quella fine si annulla e si registra di nuovo.
            // Si annullano dall'ultima (decisione 83.1).
            ! empty($piano['fini_delle_beta']) => sprintf(' %s Per avere la regola del piano anche lì, annulla dallo storico dell\'unità%s %s e %s di nuovo, prima di questa; altrimenti %s.',
                self::delleBeta($piano['fini_delle_beta']), count($piano['fini_delle_beta']) > 1 ? ', dall\'ultima,' : '', self::leFiniDelleBeta(array_reverse($piano['fini_delle_beta'])),
                count($piano['fini_delle_beta']) > 1 ? 'registrale' : 'registrala', $leParti),
            default => ' ' . ucfirst($leParti) . '.',
        };
    }

    /**
     * Decisione 115.2: le fini delle beta con la fermata del coinquilino che il passaggio segue, a parole. «La fine della locazione di Ines del 1 luglio 2026 è
     * stata registrata da una versione precedente con la fermata del coinquilino: i giorni dopo la sua uscita sono rimasti nelle sue rate, e chi li paga lo
     * decidono le parti.»
     *
     * @param list<array{id: int, persona: string, il: string}> $fini
     */
    private static function delleBeta(array $fini, bool $soloLaTesta = false): string
    {
        $piu = count($fini) > 1;
        $testa = sprintf('%s %s %s da una versione precedente con la fermata del coinquilino', $piu ? 'Le fini della locazione' : 'La fine della locazione',
            self::elenco(array_map(fn (array $f) => sprintf('di %s del %s', $f['persona'], self::data((string) $f['il'])), $fini)), $piu ? 'sono state registrate' : 'è stata registrata');

        return $soloLaTesta ? $testa : sprintf('%s: i giorni dopo %s sono rimasti nelle %s rate, e chi li paga lo decidono le parti.', $testa, $piu ? 'la loro uscita' : 'la sua uscita', $piu ? 'loro' : 'sue');
    }

    /** «la fine di Ines», «le fini di Ines e di Pia». */
    private static function leFiniDelleBeta(array $fini): string
    {
        return (count($fini) > 1 ? 'le fini ' : 'la fine ') . self::elenco(array_map(fn (array $f) => 'di ' . $f['persona'], $fini));
    }

    /**
     * Il resto nell'ordine delle frasi: con una coppia scritta prima chi era già uscito, poi gli altri; senza (un'informazione), prima chi esce, poi
     * gli altri, e chi era già uscito per ultimo.
     *
     * @param array<string, mixed> $piano
     * @return array<int, int>
     */
    private static function restoInOrdine(array $piano, bool $conCoppia = true): array
    {
        $resto = array_map('intval', (array) ($piano['resto'] ?? []));
        $usciti = array_flip(array_map('intval', (array) ($piano['gia_usciti'] ?? [])));
        if (! $conCoppia) {
            return self::nellOrdine(array_diff_key($resto, $usciti), (int) ($piano['uscente_id'] ?? 0)) + array_intersect_key($resto, $usciti);
        }

        return array_intersect_key($resto, $usciti) + array_diff_key($resto, $usciti);
    }

    /**
     * Le parti di prima e di dopo di ognuno, sommate sulle voci.
     *
     * @param array<string, mixed> $piano
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private static function totali(array $piano): array
    {
        $prima = [];
        $dopo = [];
        foreach ($piano['voci'] as $v) {
            foreach ((array) $v['prima'] as $id => $c) {
                $prima[(int) $id] = ($prima[(int) $id] ?? 0) + (int) $c;
            }
            foreach ((array) $v['dopo'] as $id => $c) {
                $dopo[(int) $id] = ($dopo[(int) $id] ?? 0) + (int) $c;
            }
        }

        return [$prima, $dopo];
    }

    /**
     * Il resto di un passaggio dopo una fine con la regola del piano, nella forma del registro (`resto_del_piano`) e del pannello: la fine che
     * il passaggio segue, chi esce adesso, il gruppo, il giorno, le persone con la cifra (il segno dei saldi), chi era già uscito, e se la strada
     * è il Wallet (`wallet`, con `strada` legata) o il prospetto.
     *
     * @param array<string, mixed> $piano la fotografia (`dopoUnaFine`)
     * @return array<string, mixed>
     */
    public static function restoPerIlRegistro(array $piano, string $gestione, string $unita, ?int $esercizioId, string $chiEsce, CarbonImmutable $decorrenza): array
    {
        $fine = Subentro::conAnnullati()->find((int) ($piano['segue_fine_id'] ?? 0));
        // Nella prima fine (nessuna fine di prima da seguire, `fine_id` nullo) chi era già uscito viene prima, come nella frase del pannello.
        $resto = self::restoInOrdine($piano, $piano['parti'] !== [] || ! isset($piano['segue_fine_id']));
        $nomi = $piano['nomi'];

        return [
            'fine_id' => isset($piano['segue_fine_id']) ? (int) $piano['segue_fine_id'] : null, 'fine_il' => $fine?->decorrenza?->toDateString(),
            'inquilino' => (string) ($fine?->uscente?->nome ?? ($fine?->registro['nomi']['uscente'] ?? '')),
            'persona' => $chiEsce, 'persona_id' => (int) ($piano['uscente_id'] ?? 0) ?: null,
            'immobile_id' => (int) $piano['immobile_id'], 'unita' => $unita, 'gestione_id' => (int) $piano['gestione_id'], 'gestione' => $gestione, 'esercizio_id' => $esercizioId,
            'dal' => $decorrenza->toDateString(), 'cifra' => (int) array_sum(array_filter($resto, fn (int $c) => $c > 0)),
            'parti' => array_values(array_map(fn ($id, $c) => ['anagrafica_id' => (int) $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => (int) $c], array_keys($resto), $resto)),
            'gia_usciti' => array_values(array_map(fn ($id) => (string) ($nomi[$id] ?? '?'), array_values(array_filter(array_map('intval', (array) ($piano['gia_usciti'] ?? [])), fn ($id) => isset($resto[$id]))))),
            'coppia' => array_values(array_map(fn ($id, $c) => ['anagrafica_id' => (int) $id, 'nome' => (string) ($nomi[$id] ?? '?'), 'cifra' => (int) $c], array_keys($piano['parti']), $piano['parti'])),
            'wallet' => ! empty($piano['wallet']),
        ] + (! empty($piano['wallet']) ? ['strada' => FermataDopoScrivi::STRADA_LEGATA] : [])
            // Decisione 115.2: la prima fine dopo una fine delle beta con la fermata del coinquilino, per lo storico (la strada della decisione 111.2).
            + (! empty($piano['fini_delle_beta']) ? ['fini_delle_beta' => $piano['fini_delle_beta']] : []);
    }

    /**
     * Il motivo del cancello per il resto (con la spunta): la cifra e le persone, e che il passaggio scrive solo la coppia di chi esce. Prima della
     * scelta «se scrivi il conguaglio», come le fermate; con «Non scriverlo» non c'è (nei saldi non si scrive niente).
     *
     * @param array<string, mixed> $r la voce di `resto_del_piano`
     */
    public static function motivoDelResto(array $r, bool $seScrivi = false): string
    {
        $parti = collect($r['parti'] ?? []);
        $due = $parti->count() === 2 && (int) $parti[0]['cifra'] === -(int) $parti[1]['cifra'];
        // Senza una coppia (nella prima fine, chi riceve i giorni di chi esce pagherebbe come lui) non c'è un conguaglio da cui restare fuori.
        if (($r['coppia'] ?? []) === []) {
            return sprintf('con la regola del piano la fine della locazione di %s non scrive righe nei saldi, e restano da regolare a mano %s (%s)', $r['persona'] ?? '?',
                self::partiInParole($parti->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all(), $parti->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (string) $p['nome']])->all()),
                FrasiObbligati::gestione($r['gestione'] ?? '?'));
        }
        $motivo = $due
            ? sprintf('%s della regola del piano restano a %s di %s e a %s di %s (%s): la fine della locazione di %s scrive solo la sua coppia', self::soldi((int) $parti[0]['cifra']),
                (int) $parti[0]['cifra'] < 0 ? 'credito' : 'debito', $parti[0]['nome'], (int) $parti[1]['cifra'] < 0 ? 'credito' : 'debito', $parti[1]['nome'], FrasiObbligati::gestione($r['gestione'] ?? '?'), $r['persona'] ?? '?')
            : sprintf('con la regola del piano restano fuori dal conguaglio %s (%s): la fine della locazione di %s scrive solo la sua coppia',
                self::partiInParole($parti->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all(), $parti->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (string) $p['nome']])->all()),
                FrasiObbligati::gestione($r['gestione'] ?? '?'), $r['persona'] ?? '?');

        return $seScrivi ? 'se scrivi il conguaglio, ' . $motivo : $motivo;
    }

    /**
     * Lo storico della fine con «Non scriverlo»: ciò che la regola del piano lascia fuori dalla coppia di chi esce, dal registro. Con «Non scriverlo» il resto non entra in
     * `resto_del_piano`, ma il gruppo della regola del piano (`conguaglio.piano[]`) lo conserva, con le persone e le cifre, e il prospetto degli oneri accessori lo dice come
     * dice la coppia: lo storico le nomina, per gestione. Null se nessun gruppo ha un resto.
     *
     * @param list<array<string, mixed>> $gruppi i gruppi della regola del piano del registro
     * @param array<int, string>         $gestioni gestione_id → nome
     */
    public static function fraseDelRestoFraLeParti(array $gruppi, string $chiEsce, array $gestioni): ?string
    {
        $perGestione = [];
        foreach ($gruppi as $g) {
            $resto = self::restoInOrdine($g);
            if (array_filter($resto, fn (int $c) => $c !== 0) === []) {
                continue;
            }
            $gid = (int) ($g['gestione_id'] ?? 0);
            foreach ($resto as $id => $cifra) {
                $perGestione[$gid]['parti'][$id] = ($perGestione[$gid]['parti'][$id] ?? 0) + $cifra;
            }
            $perGestione[$gid]['nomi'] = ($perGestione[$gid]['nomi'] ?? []) + (array) ($g['nomi'] ?? []);
        }
        if ($perGestione === []) {
            return null;
        }
        $pezzi = [];
        foreach ($perGestione as $gid => $x) {
            $pezzi[] = sprintf('sulla %s la regola del piano lascia fuori dalla coppia di %s anche %s', FrasiObbligati::gestione($gestioni[$gid] ?? null), $chiEsce,
                self::partiInParole(array_filter($x['parti'], fn (int $c) => $c !== 0), $x['nomi']));
        }

        return ucfirst(implode('; ', $pezzi)) . ': li regolano le parti, e il prospetto degli oneri accessori dice chi rimborsa chi.';
    }

    /**
     * La frase dello storico del passaggio che ha lasciato il resto (`resto_del_piano`), dal registro: le cifre della regola del piano con questo
     * passaggio, la coppia scritta, di quanto è rimasto sopra o sotto il piano ognuno, e la strada indicata; con le righe del Wallet legate al
     * passaggio, i loro fatti (`FermataDopoScrivi::fattiDeiLegati`).
     *
     * Il resto è la differenza del piano di questo passaggio (decisione 111.3), e «Annulla il conguaglio» non lo tocca: annullato il conguaglio della
     * fine di prima, le parti hanno regolato fra loro la differenza di quella fine; annullato quello di questo passaggio, la sua coppia (`regolato_fuori`).
     * In tutti e due i casi ciò che la regola del piano ha lasciato fuori resta da regolare, con la sua strada, e lo storico lo dice come il prospetto.
     *
     * @param array<string, mixed> $r la voce di `resto_del_piano`
     */
    public static function fraseStoricoDelResto(array $r, Subentro $passaggio): string
    {
        $parti = collect($r['parti'] ?? []);
        $resto = $parti->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all();
        $nomi = $parti->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (string) $p['nome']])->all();
        $piano = collect($passaggio->registro['conguaglio']['piano'] ?? [])->first(fn ($g) => (int) ($g['immobile_id'] ?? 0) === (int) $r['immobile_id']
            && (int) ($g['gestione_id'] ?? 0) === (int) $r['gestione_id']);
        // Senza la regola del piano nel registro (nessuna coppia, nessuna scelta) il prospetto la legge solo per un passaggio dopo una fine con la regola del piano.
        $conIlProspetto = $piano !== null || ($r['fine_id'] ?? null) !== null;
        $diPrimaAnnullata = FermataDopoScrivi::fineSenzaConguaglio($r)
            ? sprintf('Il conguaglio della fine locazione di %s del %s è stato annullato: le parti hanno regolato fra loro la differenza di quella fine, e ciò che la regola del piano ha lasciato fuori da questo passaggio non cambia. ',
                $r['inquilino'] ?? '?', self::data((string) ($r['fine_il'] ?? '')))
            : '';
        [$prima, $dopo] = $piano === null ? [[], []] : self::totali($piano);
        $tuttiNomi = (array) ($piano['nomi'] ?? []) + $nomi;
        // Nell'ordine della frase del pannello: chi era uscito con la fine di prima (nella prima fine, chi esce), gli altri come nel piano di prima, poi
        // chi è comparso dopo.
        $diPrima = ($r['fine_id'] ?? null) === null ? (int) ($r['persona_id'] ?? 0)
            : (int) (Subentro::conAnnullati()->whereKey((int) $r['fine_id'])->value('anagrafica_uscente_id') ?? 0);
        $ordine = array_values(array_unique([...array_keys(self::nellOrdine(array_filter($prima, fn (int $c) => $c !== 0), $diPrima)), ...array_keys($dopo)]));
        $ordine = array_values(array_filter($ordine, fn (int $id) => (int) ($dopo[$id] ?? 0) !== 0));
        $cifre = self::elenco(array_map(fn (int $id) => sprintf('%s %s', $tuttiNomi[$id] ?? '?', self::soldi((int) $dopo[$id])), $ordine));
        $coppia = collect($r['coppia'] ?? [])->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all();
        $posizioni = [];
        foreach ($resto as $id => $c) {
            $posizioni[] = sprintf($posizioni === [] ? 'la parte di %s è rimasta %s %s il piano' : 'quella di %s %s %s', $nomi[$id] ?? '?', MoneyHelper::format(abs($c)), $c < 0 ? 'sopra' : 'sotto');
        }
        $scritto = $coppia === [] ? 'nei saldi non è stato scritto niente' : sprintf('il conguaglio ha scritto solo la coppia di %s, %s', $r['persona'] ?? '?', self::partiInParole(self::nellOrdine($coppia, (int) ($r['persona_id'] ?? 0)), (array) ($piano['nomi'] ?? []) + $nomi));
        // Senza la regola del piano nel registro (un passaggio dopo senza coppia, la prima fine dopo una fine delle beta con la fermata del coinquilino) la frase
        // non ha le sue cifre.
        $frase = $diPrimaAnnullata . ($cifre === ''
            ? sprintf('Con questa fine, sulla %s (%s), %s, e %s.', FrasiObbligati::gestione($r['gestione'] ?? '?'), $r['unita'] ?? '', $scritto, implode(', ', $posizioni))
            : sprintf('Con questa fine la regola del piano dava, sulla %s (%s), %s: %s, e %s.', FrasiObbligati::gestione($r['gestione'] ?? '?'), $r['unita'] ?? '', $cifre, $scritto, implode(', ', $posizioni)));
        if ($passaggio->conguaglioAnnullato()) {
            // Le parti hanno regolato fra loro la coppia (`regolato_fuori`); il resto non è nella coppia.
            $frase .= sprintf(' Il conguaglio di questo passaggio è stato annullato: la coppia di %s l\'hanno regolata fra loro le parti, e ciò che la regola del piano ha lasciato fuori resta da regolare.', $r['persona'] ?? '?');
        }
        if (empty($r['wallet'])) {
            $leParti = $conIlProspetto ? 'la differenza la regolano le parti con il prospetto degli oneri accessori' : 'la differenza la regolano le parti';
            if (! empty($r['fini_delle_beta'])) {
                // Decisione 115.2, con la 111.2: questa fine è registrata dopo quella delle beta, e si annulla prima lei (dall'ultimo).
                return $frase . sprintf(' %s: per avere la regola del piano anche lì, annulla dallo storico dell\'unità prima questa fine, poi %s, e registrale di nuovo; altrimenti %s.',
                    self::delleBeta($r['fini_delle_beta'], soloLaTesta: true), count($r['fini_delle_beta']) > 1
                        ? 'quelle ' . self::elenco(array_map(fn (array $f) => 'di ' . $f['persona'], array_reverse($r['fini_delle_beta']))) . ', dall\'ultima'
                        : 'quella di ' . $r['fini_delle_beta'][0]['persona'], $leParti);
            }

            return $frase . ' ' . ucfirst($leParti) . '.';
        }
        $frase .= sprintf(' La strada indicata erano i saldi del Wallet legati a questo passaggio: %s.', self::partiInParole($resto, $nomi));
        $righe = Saldo::where('passaggio_id', (int) $passaggio->id)->where('immobile_id', (int) $r['immobile_id'])->where('gestione_id', (int) $r['gestione_id'])->orderBy('id')->get();
        if ($righe->isEmpty()) {
            return $frase;
        }

        return $frase . ' ' . FermataDopoScrivi::fattiDeiLegati($righe, array_map(fn (int $id, int $c) => ['ids' => [$id], 'nomi' => [$nomi[$id] ?? '?'], 'cifra' => $c], array_keys($resto), $resto),
            'il resto della regola del piano');
    }

    /**
     * L'avviso dell'annullamento del passaggio che ha lasciato il resto della regola del piano: annullato, quella parte non c'è più; i saldi del
     * Wallet legati al passaggio si tolgono con lui, e un accordo fra le parti si rivede.
     *
     * @param array<string, mixed> $r la voce di `resto_del_piano`
     */
    public static function avvisoDelRestoAnnullando(array $r, bool $conLegate): string
    {
        $parti = collect($r['parti'] ?? []);
        $inParole = self::partiInParole($parti->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (int) $p['cifra']])->all(), $parti->mapWithKeys(fn (array $p) => [(int) $p['anagrafica_id'] => (string) $p['nome']])->all());
        // Senza una coppia (la prima fine in cui chi riceve i giorni pagherebbe come chi esce) il passaggio non ha un conguaglio da cui restare fuori: nei saldi non ha scritto niente.
        $daCui = ($r['coppia'] ?? []) === [] ? 'non ha scritto righe nei saldi, e ha lasciato da regolare a mano' : 'ha lasciato fuori dal conguaglio';

        return sprintf('Con questo passaggio la regola del piano %s %s: annullandolo quella parte non c\'è più%s', $daCui, $inParole,
            $conLegate ? ', e i saldi del Wallet legati a questo passaggio si tolgono con lui.' : '; se le parti l\'avevano regolata fra loro, quell\'accordo va rivisto.');
    }

    /**
     * L'avviso di «Annulla il conguaglio» della fine con la regola del piano, per un passaggio dopo sullo stesso anello che l'ha letta (la fine
     * di chi restava, il cambio d'inquilino): la sua coppia e il resto, e che cosa succede annullando. Avvisa, non rifiuta (decisione 83.2).
     *
     * @param array<string, mixed> $g la voce di `conguaglio.piano` del passaggio dopo
     */
    public static function avvisoDelConguaglio(Subentro $passaggio, array $g): string
    {
        $nomi = (array) ($g['nomi'] ?? []);
        $chi = (string) ($passaggio->uscente?->nome ?? ($passaggio->registro['nomi']['uscente'] ?? '?'));
        $parti = self::nellOrdine(array_map('intval', (array) ($g['parti'] ?? [])), (int) ($g['uscente_id'] ?? 0));
        $resto = self::restoInOrdine($g);
        $scritto = ($passaggio->registro['conguaglio']['scelta'] ?? null) === Subentro::CONGUAGLIO_SCRITTO && ! $passaggio->conguaglioAnnullato();
        $frase = sprintf('Dopo questo conguaglio, %s %s %s (%s)%s.', FermataDopoScrivi::passaggioInParole($passaggio),
            $scritto ? 'ha scritto' : 'ha diviso', $scritto ? 'la sua coppia con la regola del piano' : 'la sua parte con la regola del piano, senza scrivere il conguaglio',
            self::partiInParole($parti, $nomi), $resto !== [] && $scritto ? sprintf(', e la regola del piano ha lasciato fuori dal conguaglio %s', self::partiInParole($resto, $nomi)) : '');
        // Il resto è la differenza del piano di quel passaggio (decisione 111.3): l'annullamento di questo conguaglio non lo cambia.
        $frase .= sprintf(' Annullato questo conguaglio, %sil prospetto degli oneri accessori dice le cifre del piano di oggi.',
            $scritto ? sprintf($resto !== [] ? 'la coppia di %s e la parte lasciata fuori dal conguaglio restano come sono, e ' : 'la coppia di %s resta com\'è, e ', $chi) : '');
        $righe = Saldo::where('passaggio_id', (int) $passaggio->id)->where('immobile_id', (int) ($g['immobile_id'] ?? 0))->where('gestione_id', (int) ($g['gestione_id'] ?? 0))->orderBy('id')->get();

        return $righe->isEmpty() ? $frase : $frase . ' ' . FermataDopoScrivi::fattiDeiLegati($righe, null, 'il resto della regola del piano', chiNonLiCambia: 'l\'annullamento del conguaglio');
    }

    /**
     * Decisione 115.1: un piano senza il riparto registrato sull'anello di una fine con la regola del piano, quando la regola del piano non si ricalcola più
     * (`anelli` si ferma: un inquilino ha preso dopo la fine un altro ruolo sull'unità). Il perché, e le cifre della fine con la regola del piano che apre
     * l'anello (il primo dei passaggi sull'anello, `ProspettoRegolaDelPiano::passaggi`): «su Interno 1 un inquilino ha anche un altro ruolo: …, e la regola
     * del piano non si ricalcola; con la fine della locazione del 01/07/2026 dava Ines € 119,34 e Dora € 240,66».
     *
     * @param non-empty-list<array{s: Subentro, tipo: string, voce: array<string, mixed>}> $passaggi
     * @param \Closure(CarbonImmutable): string $data il giorno nella forma della pagina
     */
    public static function pianoFermo(string $perche, array $passaggi, \Closure $data): string
    {
        return sprintf('%s, e la regola del piano non si ricalcola; con la fine della locazione del %s dava %s', $perche,
            $data(CarbonImmutable::parse($passaggi[0]['s']->decorrenza->toDateString())), self::partiDellaFine($passaggi[0]['voce']));
    }

    /**
     * La parte di ognuno dopo l'uscita nella fotografia della fine che apre l'anello, voce per voce sommata: la regola del piano di quel giorno, «Ines € 119,34 e Dora € 240,66».
     *
     * @param array<string, mixed> $voce il gruppo della regola del piano della fine (`conguaglio.piano[]`)
     */
    public static function partiDellaFine(array $voce): string
    {
        $parti = array_filter(self::nellOrdine(self::totali($voce)[1], (int) ($voce['uscente_id'] ?? 0)));
        $nomi = (array) ($voce['nomi'] ?? []);

        return self::elenco(array_map(fn ($id, $c) => sprintf('%s %s', $nomi[$id] ?? '?', self::soldi((int) $c)), array_keys($parti), $parti));
    }

    /** «1 ottobre 2026». */
    private static function data(string $iso): string
    {
        return $iso === '' ? '?' : CarbonImmutable::parse($iso)->locale('it')->translatedFormat('j F Y');
    }

    /**
     * Chi rimborsa chi con «Non scriverlo», per la frase: chi ha una parte a debito rimborsa chi l'ha a credito. Con una sola persona da una
     * parte si dice per nome e cifra; con più persone dalle due parti il prospetto degli oneri accessori lo dice per voce.
     *
     * @param array<int, int> $parti
     * @param array<int, string> $nomi
     */
    private static function chiRimborsaChi(array $parti, array $nomi): string
    {
        $debito = array_filter($parti, fn (int $c) => $c > 0);
        $credito = array_filter($parti, fn (int $c) => $c < 0);
        $nome = fn ($id) => $nomi[$id] ?? '?';
        if (count($credito) === 1) {
            $chi = $nome(array_key_first($credito));

            return count($debito) === 1
                ? sprintf('il prospetto degli oneri accessori dice che %s rimborsa %s %s', $nome(array_key_first($debito)), FrasiObbligati::a($chi), self::soldi((int) reset($debito)))
                : sprintf('il prospetto degli oneri accessori dice che %s rimborsano %s %s', self::elenco(array_map(fn ($id, $c) => sprintf('%s (%s)', $nome($id), self::soldi((int) $c)), array_keys($debito), $debito)), FrasiObbligati::a($chi), self::soldi((int) -reset($credito)));
        }
        if (count($debito) === 1) {
            return sprintf('il prospetto degli oneri accessori dice che %s rimborsa %s', $nome(array_key_first($debito)),
                self::elenco(array_map(fn ($id, $c) => sprintf('%s %s', self::soldi((int) -$c), FrasiObbligati::a($nome($id))), array_keys($credito), $credito)));
        }

        return 'il prospetto degli oneri accessori dice chi rimborsa chi';
    }

    /** «€ 60,66 a credito di Ines e € 60,66 a debito di Dora»: il verso a parole, mai il meno. */
    public static function partiInParole(array $parti, array $nomi): string
    {
        return self::elenco(array_map(fn ($id, $c) => sprintf('%s a %s di %s', self::soldi((int) $c), (int) $c < 0 ? 'credito' : 'debito', $nomi[$id] ?? '?'), array_keys($parti), $parti));
    }

    /**
     * La differenza di una voce per persona, senza gli zeri.
     *
     * @param array<string, mixed> $v
     * @return array<int, int>
     */
    private static function differenzaDellaVoce(array $v): array
    {
        $d = [];
        foreach (array_keys((array) $v['dopo'] + (array) $v['prima']) as $id) {
            $c = (int) ($v['dopo'][$id] ?? 0) - (int) ($v['prima'][$id] ?? 0);
            if ($c !== 0) {
                $d[(int) $id] = $c;
            }
        }

        return $d;
    }

    /**
     * Chi esce per primo, poi gli altri nell'ordine in cui vengono.
     *
     * @param array<int, int> $parti
     * @return array<int, int>
     */
    private static function nellOrdine(array $parti, int $uscente): array
    {
        return array_key_exists($uscente, $parti) ? [$uscente => $parti[$uscente]] + $parti : $parti;
    }

    /** «i 92 giorni dal 1 ottobre 2026», «i 31 giorni dal 1 al 31 dicembre 2026»: i tratti di giorni, a parole. */
    private static function giorniInParole(array $tratti): string
    {
        $giorni = 0;
        foreach ($tratti as $t) {
            $giorni += CarbonImmutable::parse($t['dal'])->diffInDays(CarbonImmutable::parse($t['al'])) + 1;
        }
        $dal = CarbonImmutable::parse($tratti[0]['dal'] ?? 'today');

        return sprintf('i %d giorni dal %s', (int) $giorni, $dal->locale('it')->translatedFormat('j F Y'));
    }

    /** Una cifra senza il meno: il verso lo dice la frase. */
    private static function soldi(int $cifra): string
    {
        return MoneyHelper::format(abs($cifra));
    }

    /** «Ines, Dora e Pia». */
    private static function elenco(array $voci): string
    {
        $voci = array_values(array_filter($voci, fn ($v) => (string) $v !== ''));
        if (count($voci) <= 1) {
            return (string) ($voci[0] ?? '');
        }
        $ultimo = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultimo;
    }
}
