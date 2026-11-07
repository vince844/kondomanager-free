<?php

namespace App\Services\Gestionale\Debiti;

use App\Enums\NaturaGestione;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\Riparto\CompetenzaDelPiano;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Le due date dei sei mesi di una rata scaduta (art. 1129 c. 9 c.c.), calcolate a ogni lettura e mai scritte. La norma chiede di agire
 * «entro sei mesi dalla chiusura dell'esercizio nel quale il credito esigibile è compreso», e si legge in due modi; il programma mostra
 * tutte e due le date e mette in evidenza la più vicina:
 * - **dalla scadenza**: la fine dell'esercizio del condominio che contiene la scadenza della rata, più sei mesi;
 * - **dalla competenza**: la fine dell'esercizio di competenza del piano, più sei mesi. Per un piano ordinario è l'esercizio del piano
 *   ({@see CompetenzaDelPiano::esercizioDelPiano()}: il salvato, o il dedotto, che la cella segna); per un piano di una gestione
 *   straordinaria è l'esercizio che contiene la data della delibera, la stessa regola del motore (la natura la dice la gestione).
 *
 * «Più sei mesi» è `addMonthsNoOverflow(6)`: lo stesso giorno del sesto mese, o l'ultimo di quel mese se il giorno manca (31/12 → 30/06,
 * 30/06 → 30/12, 31/08 → 28/02). È la più presto delle somme possibili: chi agisce entro quella data non è in ritardo con nessuna. Il giorno
 * festivo non sposta la data: è una scelta prudente del programma (la data più presto), non una regola di legge che il programma abbia
 * verificato.
 *
 * Dove la data non si può calcolare il programma non indovina e lo dice: l'esercizio che contiene la scadenza non c'è ancora (ogni gennaio,
 * con il vecchio ancora aperto); due esercizi contengono la data (si mostra la più vicina delle due, con il segno); il piano straordinario
 * non ha la data della delibera; il pregresso senza un'origine dichiarata. Una data dalla competenza che viene prima della scadenza della rata
 * non si mostra in evidenza: la rata non è esigibile prima di scadere.
 *
 * Il pregresso con un esercizio d'origine ha una data sola, dalla fine di quell'esercizio (le due letture coincidono); con «dovuto con questo
 * piano» le date sono quelle della rata che lo porta.
 *
 * La dispensa dell'assemblea si mostra accanto a ogni data che copre (l'esercizio di quella data, la persona): le date non cambiano mai per
 * una dispensa.
 */
final class SeiMesi
{
    /** Il testo della norma, fra virgolette, come si legge nella Gazzetta Ufficiale. */
    public const TESTO_NORMA = '«Salvo che sia stato espressamente dispensato dall\'assemblea, l\'amministratore è tenuto ad agire per la riscossione '
        . 'forzosa delle somme dovute dagli obbligati entro sei mesi dalla chiusura dell\'esercizio nel quale il credito esigibile è compreso, '
        . 'anche ai sensi dell\'articolo 63, primo comma, delle disposizioni per l\'attuazione del presente codice.» (art. 1129, comma 9, c.c.)';

    /**
     * Il giorno festivo, a video e sulla stampa: una scelta prudente del programma (la data più presto), non una regola di legge. L'art. 2963
     * c.c. proroga al giorno dopo un termine di prescrizione che scade di festivo; che valga per questo termine non è accertato.
     */
    public const FRASE_FESTIVO = 'Se la data cade in un giorno festivo il programma non la sposta: è una scelta prudente, la data più presto. '
        . 'Che la legge la sposti al giorno dopo non è accertato.';

    /** «Più sei mesi», a video e sulla stampa: come il programma conta, e che è la data più presto fra i modi di contare (una sua scelta). */
    public const FRASE_PIU_SEI_MESI = 'Più sei mesi: lo stesso giorno del sesto mese dopo, o l\'ultimo giorno di quel mese se quel giorno manca (31/08 → 28/02, o '
        . '29/02 negli anni bisestili). È una scelta prudente del programma: fra i modi di aggiungere sei mesi a una data, quello che dà la data più presto.';

    /** @var list<array{id:int, nome:string, data_inizio:string, data_fine:string, stato:string}> */
    private array $esercizi = [];

    /** @var list<array{id:int, data_delibera:string, numero_verbale:?string, revocata_il:?string, tutte:bool, esercizi:list<int>, persone:list<int>}> */
    private array $dispense = [];

    /** @var array<int, array{straordinaria:bool, esercizio:?array, dedotto:bool, delibera:?string}> */
    private array $piani = [];

    public function __construct(private readonly CompetenzaDelPiano $competenza) {}

    /** La fine dell'esercizio più sei mesi: lo stesso giorno del sesto mese, o l'ultimo di quel mese. */
    public static function piuSeiMesi(string $fine): string
    {
        return CarbonImmutable::parse(Scadenza::giorno($fine), 'UTC')->addMonthsNoOverflow(6)->format('Y-m-d');
    }

    /**
     * Il calcolo per un condominio, con i suoi esercizi e le sue dispense letti una volta. Con la data dell'elenco, le dispense sono quelle
     * di quel giorno: una delibera presa dopo non c'è ancora, e una revoca successiva non vale ancora (un elenco al 31/12 per l'assemblea
     * non scrive una dispensa deliberata a marzo).
     */
    public function perCondominio(int $condominioId, ?string $alla = null): self
    {
        $c = new self($this->competenza);
        $c->esercizi = DB::table('esercizi')->where('condominio_id', $condominioId)->whereNotNull('data_inizio')->whereNotNull('data_fine')
            ->orderBy('data_inizio')->orderBy('id')->get(['id', 'nome', 'data_inizio', 'data_fine', 'stato'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'nome' => (string) $e->nome, 'data_inizio' => Scadenza::giorno($e->data_inizio),
                'data_fine' => Scadenza::giorno($e->data_fine), 'stato' => (string) $e->stato])->all();

        $alla = $alla === null ? null : Scadenza::giorno($alla);
        $dispense = DB::table('dispense_sei_mesi')->where('condominio_id', $condominioId)->orderBy('data_delibera')->orderBy('id')->get()
            ->filter(fn ($d) => $alla === null || Scadenza::giorno($d->data_delibera) <= $alla)->values();
        $ids = $dispense->pluck('id')->all();
        $esercizi = $ids === [] ? collect() : DB::table('dispensa_sei_mesi_esercizio')->whereIn('dispensa_id', $ids)->get()->groupBy('dispensa_id');
        $persone = $ids === [] ? collect() : DB::table('dispensa_sei_mesi_anagrafica')->whereIn('dispensa_id', $ids)->get()->groupBy('dispensa_id');
        $c->dispense = $dispense->map(fn ($d) => [
            'id' => (int) $d->id, 'data_delibera' => Scadenza::giorno($d->data_delibera), 'numero_verbale' => $d->numero_verbale,
            'revocata_il' => $d->revocata_il === null || ($alla !== null && Scadenza::giorno($d->revocata_il) > $alla) ? null : Scadenza::giorno($d->revocata_il),
            'tutte' => (bool) $d->tutte_le_persone,
            'esercizi' => $esercizi->get($d->id, collect())->pluck('esercizio_id')->map(fn ($x) => (int) $x)->all(),
            'persone' => $persone->get($d->id, collect())->pluck('anagrafica_id')->map(fn ($x) => (int) $x)->all(),
        ])->all();

        return $c;
    }

    /**
     * Le due date di una riga della lettura comune (una rata emessa), per la persona della riga.
     *
     * Ogni data: `data` (Y-m-d o null), `esercizio` (id, nome, fine), `stato` (`ok`, `da_origine`, `manca_esercizio`, `prima_degli_esercizi`,
     * `senza_delibera`, `senza_competenza`, `origine_non_dichiarata`, `prima_del_primo`), `doppio` (due esercizi contengono la data), `dedotto` (la competenza
     * dedotta), `prima_della_scadenza` (la data viene prima della scadenza della rata: non va in evidenza), `data_cercata` e
     * `esercizio_da_chiudere` per l'esercizio che manca, `dispensa`. Poi la più vicina fra quelle che ci sono (prima le date non coperte
     * da una dispensa) e la sua dispensa.
     *
     * @param  array<string,mixed>  $riga
     * @return array{dalla_scadenza:array<string,mixed>, dalla_competenza:array<string,mixed>, coincidono:bool, piu_vicina:?string, piu_vicina_dispensa:?array}
     */
    public function perRiga(array $riga, int $anagraficaId): array
    {
        $scadenza = Scadenza::giorno((string) $riga['scadenza']);
        $pregresso = $riga['pregresso'] ?? null;

        if ($pregresso !== null && $pregresso['origine'] !== OriginePregresso::CON_IL_PIANO) {
            if ($pregresso['origine'] === OriginePregresso::ESERCIZIO && ! empty($pregresso['esercizio']['data_fine'])) {
                $e = $pregresso['esercizio'];
                $voce = $this->voce('da_origine', ['id' => (int) $e['id'], 'nome' => (string) $e['nome'], 'data_fine' => (string) $e['data_fine']], $anagraficaId);

                return $this->esito($voce, $voce, true);
            }
            $vuota = $this->voce($pregresso['origine'] === OriginePregresso::PRIMA_DEL_PRIMO ? 'prima_del_primo' : 'origine_non_dichiarata', null, $anagraficaId);

            return $this->esito($vuota, $vuota, true);
        }

        $dallaScadenza = $this->dallaData($scadenza, $anagraficaId, 'manca_esercizio');
        $dallaCompetenza = $this->dallaCompetenza((int) $riga['piano_rate_id'], $anagraficaId);
        if ($dallaCompetenza['data'] !== null && $dallaCompetenza['data'] < $scadenza) {
            $dallaCompetenza['prima_della_scadenza'] = true;
        }

        return $this->esito($dallaScadenza, $dallaCompetenza, false);
    }

    /** La data dall'esercizio che contiene un giorno: nessuno (manca), uno, o più d'uno (la più vicina, con il segno). */
    private function dallaData(string $giorno, int $anagraficaId, string $statoSeManca): array
    {
        $contengono = array_values(array_filter($this->esercizi, fn ($e) => $e['data_inizio'] <= $giorno && $giorno <= $e['data_fine']));
        // Una data prima del primo esercizio che il programma conosce (una rata storica importata, una delibera vecchia) non «c'è ancora»:
        // la cella lo dice, e non manda a creare un esercizio passato.
        if ($contengono === [] && $this->esercizi !== [] && $giorno < min(array_column($this->esercizi, 'data_inizio'))) {
            $voce = $this->voce('prima_degli_esercizi', null, $anagraficaId);
            $voce['data_cercata'] = $giorno;

            return $voce;
        }
        if ($contengono === []) {
            $voce = $this->voce($statoSeManca, null, $anagraficaId);
            $voce['data_cercata'] = $giorno;
            // L'esercizio che oggi va chiuso prima di aprire quello che contiene la data: l'ultimo aperto che finisce prima.
            $prima = array_values(array_filter($this->esercizi, fn ($e) => $e['data_fine'] < $giorno && $e['stato'] === 'aperto'));
            $voce['esercizio_da_chiudere'] = $prima === [] ? null : end($prima)['nome'];

            return $voce;
        }
        usort($contengono, fn ($a, $b) => [$a['data_fine'], $a['id']] <=> [$b['data_fine'], $b['id']]);
        $e = $contengono[0];
        $voce = $this->voce('ok', ['id' => $e['id'], 'nome' => $e['nome'], 'data_fine' => $e['data_fine']], $anagraficaId);
        $voce['doppio'] = count($contengono) > 1;

        return $voce;
    }

    /** La data dalla competenza del piano: l'esercizio del piano (ordinario) o quello della delibera (gestione straordinaria). */
    private function dallaCompetenza(int $pianoId, int $anagraficaId): array
    {
        $piano = $this->piani[$pianoId] ??= $this->piano($pianoId);
        if ($piano['straordinaria']) {
            if ($piano['delibera'] === null) {
                return $this->voce('senza_delibera', null, $anagraficaId);
            }

            return $this->dallaData($piano['delibera'], $anagraficaId, 'manca_esercizio');
        }
        if ($piano['esercizio'] === null) {
            return $this->voce('senza_competenza', null, $anagraficaId);
        }
        $voce = $this->voce('ok', $piano['esercizio'], $anagraficaId);
        $voce['dedotto'] = $piano['dedotto'];

        return $voce;
    }

    /** @return array{straordinaria:bool, esercizio:?array, dedotto:bool, delibera:?string} */
    private function piano(int $pianoId): array
    {
        $piano = PianoRate::with('gestione')->find($pianoId);
        if ($piano === null) {
            return ['straordinaria' => false, 'esercizio' => null, 'dedotto' => false, 'delibera' => null];
        }
        $straordinaria = $piano->gestione !== null && NaturaGestione::daStringa($piano->gestione->tipo) === NaturaGestione::Straordinaria;
        $delibera = $piano->data_delibera_assemblea === null ? null : Scadenza::giorno($piano->data_delibera_assemblea);
        $esercizio = $straordinaria ? null : $this->competenza->esercizioDelPiano($piano);

        return [
            'straordinaria' => $straordinaria,
            'esercizio' => $esercizio instanceof Esercizio && $esercizio->data_fine !== null
                ? ['id' => (int) $esercizio->id, 'nome' => (string) $esercizio->nome, 'data_fine' => Scadenza::giorno($esercizio->data_fine)]
                : null,
            'dedotto' => ! $straordinaria && $this->competenza->esercizioDedotto($piano),
            'delibera' => $delibera,
        ];
    }

    /** @param array{id:int, nome:string, data_fine:string}|null $esercizio */
    private function voce(string $stato, ?array $esercizio, int $anagraficaId): array
    {
        return [
            'data' => $esercizio === null ? null : self::piuSeiMesi($esercizio['data_fine']),
            'esercizio' => $esercizio,
            'stato' => $stato,
            'doppio' => false,
            'dedotto' => false,
            'prima_della_scadenza' => false,
            'data_cercata' => null,
            'esercizio_da_chiudere' => null,
            'dispensa' => $esercizio === null ? null : $this->dispensaPer((int) $esercizio['id'], $anagraficaId),
        ];
    }

    /**
     * La dispensa che copre un esercizio per una persona: quella in vigore (la più recente); se ci sono solo dispense revocate, l'ultima
     * revocata, perché la cella lo dica.
     */
    private function dispensaPer(int $esercizioId, int $anagraficaId): ?array
    {
        $copre = array_values(array_filter($this->dispense, fn ($d) => in_array($esercizioId, $d['esercizi'], true)
            && ($d['tutte'] || in_array($anagraficaId, $d['persone'], true))));
        if ($copre === []) {
            return null;
        }
        $inVigore = array_values(array_filter($copre, fn ($d) => $d['revocata_il'] === null));
        $d = $inVigore !== [] ? end($inVigore) : end($copre);

        return ['id' => $d['id'], 'data_delibera' => $d['data_delibera'], 'numero_verbale' => $d['numero_verbale'], 'revocata_il' => $d['revocata_il']];
    }

    private function esito(array $dallaScadenza, array $dallaCompetenza, bool $coincidono): array
    {
        $candidate = [];
        foreach ($coincidono ? [$dallaScadenza] : [$dallaScadenza, $dallaCompetenza] as $v) {
            if ($v['data'] !== null && ! $v['prima_della_scadenza']) {
                $candidate[] = $v;
            }
        }
        $libere = array_values(array_filter($candidate, fn ($v) => $v['dispensa'] === null || $v['dispensa']['revocata_il'] !== null));
        $scelte = $libere !== [] ? $libere : $candidate;
        usort($scelte, fn ($a, $b) => $a['data'] <=> $b['data']);
        $piuVicina = $scelte[0] ?? null;

        return [
            'dalla_scadenza' => $dallaScadenza,
            'dalla_competenza' => $dallaCompetenza,
            'coincidono' => $coincidono,
            'piu_vicina' => $piuVicina['data'] ?? null,
            'piu_vicina_dispensa' => $piuVicina !== null && $piuVicina['dispensa'] !== null && $piuVicina['dispensa']['revocata_il'] === null ? $piuVicina['dispensa'] : null,
        ];
    }
}
