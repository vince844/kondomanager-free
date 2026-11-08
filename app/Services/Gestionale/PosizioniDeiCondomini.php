<?php

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Gestionale\Debiti\OriginePregresso;
use App\Services\Gestionale\Debiti\Scadenza;
use App\Services\Gestionale\Debiti\SeiMesi;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Services\Riparto\CompetenzaDelPiano;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Le posizioni dei condòmini (§ 9 del rendiconto) e i crediti per anzianità (§ 10), alla data del rendiconto: la fine dell'esercizio se è finito,
 * oggi se è aperto. Un array ciascuno, per la pagina e per la stampa: nessuna somma nella vista.
 *
 * LA FONTE. La lettura comune dei debiti ({@see LetturaDebiti}) alla data, la stessa di «Rate scadute» con la data dell'elenco: per gruppi di persone
 * (`perCondominioABlocchi`), così si tiene in memoria solo ciò che si mostra. Lo scaduto del § 10 è lo scaduto di «Rate scadute» alla stessa data.
 *
 * IL § 9. Una riga per persona e unità con una posizione diversa da zero: il residuo delle rate emesse per l'esercizio della rata (quello del suo piano,
 * {@see CompetenzaDelPiano::esercizioDelPiano()}, con il dedotto) e per la natura della gestione (`gestioni.tipo`): le quote dell'esercizio, ordinarie e
 * straordinarie; quelle degli esercizi precedenti, con il pregresso; quelle di un esercizio dopo; le righe su rate non ancora emesse; i crediti sulle
 * rate, con il segno meno. Il totale **non ha gli anticipi** («Anticipi ricevuti dai condòmini», un passivo): stanno in una colonna loro, e una riga li
 * lega al conto. Un'altra riga lega le posizioni ai crediti verso i condòmini della situazione patrimoniale: crediti = posizioni + spese anticipate per
 * singole unità (le righe del conto senza una persona).
 *
 * IL § 10. Una riga per rata emessa e non pagata alla data: l'importo, la scadenza, i giorni di ritardo e la fascia ({@see Scadenza}); le due annate
 * della rata, l'esercizio della scadenza e quello di competenza del piano, con le date dei sei mesi ({@see SeiMesi::perRiga()}): la legge non sceglie e
 * il programma nemmeno; il pregresso con le sue parole ({@see OriginePregresso}) dove non ha un numero di giorni. Per un esercizio finito, quanto è
 * stato versato dopo la data, fino a oggi: una somma nel database sulle sole rate dell'elenco, non una seconda lettura. L'ultima lettera partita o
 * spedita di ogni persona, fino a oggi: la regola dei filtri di «Rate scadute» ({@see RegistroLettere::ultimePerPersone()}), non quella della sua
 * colonna (che mostra anche una lettera solo stampata).
 */
final class PosizioniDeiCondomini
{
    /** Le persone di un gruppo della lettura. */
    private const PERSONE_PER_GRUPPO = 250;

    /** I tipi delle lettere, come si leggono. */
    private const TIPI_LETTERA = ['promemoria' => 'promemoria', 'sollecito' => 'sollecito', 'diffida' => 'diffida', 'messa_in_mora' => 'messa in mora'];

    /** Le colonne del § 9 che si sommano nel totale, nell'ordine della pagina. */
    public const COLONNE_POSIZIONI = ['ordinarie', 'straordinarie', 'precedenti', 'successivi', 'non_emesse', 'crediti'];

    public function __construct(
        private readonly LetturaDebiti $lettura,
        private readonly CompetenzaDelPiano $competenza,
        private readonly SeiMesi $seiMesi,
        private readonly RegistroLettere $lettere,
        private readonly StatoPatrimonialeService $statoPatrimoniale,
    ) {}

    /** Una rata con una parte di pregresso spalmato: le stesse parole di «Rate scadute» (`NOTA_PREGRESSO_NELLA_RATA` in `rateScadute.ts`). */
    public const NOTA_PREGRESSO_NELLA_RATA = 'Questa rata contiene anche una parte di pregresso: la sua età è quella della rata.';

    /** Le stampe e le pagine con i dati di tutti i condòmini lo dicono: le posizioni, i crediti per anzianità, il registro di contabilità, il fascicolo. */
    public const RISERVATO = 'Contiene i dati personali dei condòmini: è per i condòmini e per l\'assemblea. Non va esposto in bacheca né girato ad altri, nemmeno in una chat.';

    /** La data del rendiconto, e se l'esercizio è finito: «finito» è per data (la fine prima di oggi). */
    private static function data(Esercizio $esercizio, string $oggi): array
    {
        $fine = $esercizio->data_fine->format('Y-m-d');

        return [$fine < $oggi ? $fine : $oggi, $fine < $oggi];
    }

    /** @return array<string,mixed> */
    public function posizioni(Condominio $condominio, Esercizio $esercizio, ?string $oggi = null): array
    {
        $oggi = $oggi ?? DateHelper::oggiUtente();
        [$alla, $finito] = self::data($esercizio, $oggi);
        $inizio = $esercizio->data_inizio->format('Y-m-d');
        $esercizioDelPiano = [];
        $tipoGestione = DB::table('gestioni')->where('condominio_id', $condominio->id)->pluck('tipo', 'id')->map(fn ($t) => (string) $t)->all();

        $righe = [];
        // Le rate con un residuo: sono le righe dei crediti per anzianità alla stessa data (lo stesso `residuo !== 0`), contate qui senza una seconda lettura.
        $rateNonPagate = 0;
        foreach ($this->lettura->perCondominioABlocchi((int) $condominio->id, $alla, self::PERSONE_PER_GRUPPO) as $gruppo) {
            foreach ($gruppo as $persona => $l) {
                $riga = function (?int $immobile) use (&$righe, $persona) {
                    $k = $persona.'|'.($immobile ?? '');
                    $righe[$k] ??= ['anagrafica_id' => (int) $persona, 'immobile_id' => $immobile] + array_fill_keys([...self::COLONNE_POSIZIONI, 'anticipi'], 0) + ['pregresso_nella_rata' => false];

                    return $k;
                };
                foreach ($l['rate'] as $r) {
                    $k = $riga($r['immobile_id']);
                    $righe[$k]['crediti'] -= $r['credito'];
                    if ($r['residuo'] === 0) {
                        continue;
                    }
                    $rateNonPagate++;
                    $piano = (int) $r['piano_rate_id'];
                    $esercizioDelPiano[$piano] ??= $this->esercizioDelPiano($piano);
                    $e = $esercizioDelPiano[$piano];
                    $colonna = match (true) {
                        $r['pregresso'] !== null => 'precedenti',
                        $e !== null && $e['id'] !== (int) $esercizio->id && $e['data_inizio'] < $inizio => 'precedenti',
                        $e !== null && $e['id'] !== (int) $esercizio->id => 'successivi',
                        ($tipoGestione[(int) $r['gestione_id']] ?? 'ordinaria') === 'straordinaria' => 'straordinarie',
                        default => 'ordinarie',
                    };
                    $righe[$k][$colonna] += $r['residuo'];
                    // Il pregresso spalmato nelle rate (nella prima o in tutte) sta dentro una quota dell'esercizio: la riga lo segna, l'importo non si divide.
                    $righe[$k]['pregresso_nella_rata'] = $righe[$k]['pregresso_nella_rata'] || $r['pregresso_nella_rata'];
                }
                foreach ($l['non_emesse'] as $r) {
                    $righe[$riga($r['immobile_id'])]['non_emesse'] += $r['saldo'];
                }
                if ($l['anticipi'] !== 0) {
                    $righe[$riga(null)]['anticipi'] += $l['anticipi'];
                }
            }
        }

        // Una riga nasce solo da una chiave della lettura, che ha sempre un saldo diverso da zero: le posizioni a zero non ci sono.
        foreach ($righe as $k => $r) {
            // Il totale prima degli anticipi, che non ne fanno parte.
            $righe[$k] = array_diff_key($r, ['anticipi' => 1, 'pregresso_nella_rata' => 1])
                + ['totale' => (int) array_sum(array_intersect_key($r, array_flip(self::COLONNE_POSIZIONI))), 'anticipi' => $r['anticipi'], 'pregresso_nella_rata' => $r['pregresso_nella_rata']];
        }
        $righe = $this->conNomi(array_values($righe));
        usort($righe, fn ($a, $b) => [mb_strtolower($a['nome']), $a['unita'] === null ? 1 : 0, (string) $a['unita'], $a['anagrafica_id']]
            <=> [mb_strtolower($b['nome']), $b['unita'] === null ? 1 : 0, (string) $b['unita'], $b['anagrafica_id']]);

        $totali = array_fill_keys([...self::COLONNE_POSIZIONI, 'totale', 'anticipi'], 0);
        foreach ($righe as $r) {
            foreach ($totali as $c => $v) {
                $totali[$c] += $r[$c];
            }
        }
        $foto = $this->statoPatrimoniale->calcola($condominio, allaData: $alla);
        $saldo = fn (string $ruolo) => (int) collect([...$foto['attivo']['voci'], ...$foto['passivo']['voci']])->where('ruolo', $ruolo)->sum('saldo');
        $crediti = $saldo('crediti_condomini');
        $anticipate = $this->lettura->senzaPersona((int) $condominio->id, $alla)['totale'];
        $contoAnticipi = $saldo('anticipi_condomini');

        return [
            'esercizio' => ['id' => (int) $esercizio->id, 'nome' => $esercizio->nome, 'dal' => $inizio, 'al' => $esercizio->data_fine->format('Y-m-d')],
            'alla' => $alla,
            'finito' => $finito,
            'righe' => $righe,
            'rate_non_pagate' => $rateNonPagate,
            'totali' => $totali,
            'mostra' => ['successivi' => $totali['successivi'] !== 0, 'non_emesse' => $totali['non_emesse'] !== 0, 'anticipi' => $totali['anticipi'] !== 0],
            'riga_crediti' => ['crediti' => $crediti, 'posizioni' => $totali['totale'], 'spese_anticipate' => $anticipate, 'differenza' => $crediti - $totali['totale'] - $anticipate],
            'riga_anticipi' => ['anticipi' => -$totali['anticipi'], 'conto' => $contoAnticipi, 'differenza' => $contoAnticipi + $totali['anticipi']],
            'avvertenze' => [
                'data' => self::fraseData($alla, $finito),
                'segno' => 'Gli importi dei condòmini: positivo vuol dire che devono, negativo che hanno un credito.',
                'ordine' => 'In ordine di persona; per ogni persona le sue unità. Sono elencate le righe con almeno un importo diverso da zero.',
                'esercizio_della_rata' => 'L\'esercizio di una rata è quello del suo piano: le quote dell\'esercizio sono le rate dei piani di questo esercizio; il pregresso in una rata sua e le rate dei piani di un esercizio prima stanno negli esercizi precedenti. Con il pregresso spalmato nelle rate (nella prima o in tutte), il debito o il credito di prima sta nelle quote dell\'esercizio (le aumenta o le riduce), e la riga dice che contiene anche una parte di pregresso.',
                'anticipi' => 'Il totale è senza gli anticipi, che sono un debito del condominio verso la persona.',
                'riservato' => self::RISERVATO,
            ],
        ];
    }

    /**
     * Con `$massimo`, la lettura si ferma appena le righe lo superano (alla fine di un gruppo di persone) e l'esito è soltanto `['troppe' => N]`, con N
     * le righe lette fin lì: chi mostra o stampa l'elenco dice che sono troppe invece di finire la memoria.
     *
     * @param  'scaduto'|'persona'  $ordine
     * @return array<string,mixed>
     */
    public function crediti(Condominio $condominio, Esercizio $esercizio, string $ordine = 'scaduto', ?string $oggi = null, ?int $massimo = null): array
    {
        $oggi = $oggi ?? DateHelper::oggiUtente();
        [$alla, $finito] = self::data($esercizio, $oggi);
        $ordine = $ordine === 'persona' ? 'persona' : 'scaduto';
        $calcolo = $this->seiMesi->perCondominio((int) $condominio->id, $alla);
        $piani = [];
        $gestioni = DB::table('gestioni')->where('condominio_id', $condominio->id)->pluck('nome', 'id')->all();

        $righe = [];
        foreach ($this->lettura->perCondominioABlocchi((int) $condominio->id, $alla, self::PERSONE_PER_GRUPPO) as $gruppo) {
            $delGruppo = [];
            foreach ($gruppo as $persona => $l) {
                foreach ($l['rate'] as $r) {
                    if ($r['residuo'] === 0) {
                        continue;
                    }
                    $sei = $calcolo->perRiga($r, (int) $persona);
                    $piani[(int) $r['piano_rate_id']] ??= (string) DB::table('piani_rate')->where('id', $r['piano_rate_id'])->value('nome');
                    $delGruppo[] = [
                        'anagrafica_id' => (int) $persona, 'immobile_id' => $r['immobile_id'], 'rata_id' => $r['rata_id'], 'numero_rata' => $r['numero_rata'],
                        'piano' => $piani[(int) $r['piano_rate_id']], 'gestione' => (string) ($gestioni[(int) $r['gestione_id']] ?? ''),
                        'importo' => $r['residuo'], 'scadenza' => $r['scadenza'], 'scaduta' => $r['scaduta'], 'giorni' => $r['giorni_ritardo'],
                        // Il pregresso senza un numero di giorni sta oltre i 90, come nei totali della lettura: la cifra che non sottostima.
                        'fascia' => ! $r['scaduta'] ? null : ($r['giorni_ritardo'] === null ? 'oltre_90' : Scadenza::fascia($r['giorni_ritardo'])),
                        'origine' => $r['pregresso'] === null ? null : OriginePregresso::etichetta($r['pregresso']),
                        'pregresso_nella_rata' => $r['pregresso_nella_rata'],
                        'annata_scadenza' => self::annata($sei['dalla_scadenza']),
                        'annata_competenza' => self::annata($sei['dalla_competenza']),
                        'versato_dopo' => null, 'resta_oggi' => null, 'lettera' => null,
                    ];
                }
            }
            if ($finito && $delGruppo !== []) {
                $dopo = $this->versatoDopo((int) $condominio->id, $alla, $oggi, $delGruppo);
                foreach ($delGruppo as $i => $r) {
                    $v = $dopo[$r['anagrafica_id'].'|'.($r['immobile_id'] ?? '').'|'.$r['rata_id']] ?? 0;
                    $delGruppo[$i]['versato_dopo'] = $v;
                    $delGruppo[$i]['resta_oggi'] = max(0, $r['importo'] - $v);
                }
            }
            array_push($righe, ...$delGruppo);
            if ($massimo !== null && count($righe) > $massimo) {
                return ['troppe' => count($righe)];
            }
        }

        $persone = array_values(array_unique(array_column($righe, 'anagrafica_id')));
        $lettere = $this->lettere->ultimePerPersone((int) $condominio->id, $persone, $oggi);
        $nomi = $persone === [] ? [] : Anagrafica::whereIn('id', $persone)->pluck('nome', 'id')->all();
        $unita = Immobile::whereIn('id', array_values(array_unique(array_filter(array_column($righe, 'immobile_id')))) ?: [0])->get()->keyBy('id');
        $perPersona = [];
        foreach ($righe as $i => $r) {
            $conta = $lettere[$r['anagrafica_id']]['conta'] ?? null;
            $righe[$i]['nome'] = (string) ($nomi[$r['anagrafica_id']] ?? '');
            $righe[$i]['unita'] = $r['immobile_id'] !== null && isset($unita[$r['immobile_id']]) ? $unita[$r['immobile_id']]->etichetta : null;
            $righe[$i]['lettera'] = $conta === null ? null : ['tipo' => self::TIPI_LETTERA[$conta['tipo']] ?? (string) $conta['tipo'], 'data' => $conta['data_dei_filtri'] ?? $conta['data']];
            $perPersona[$r['anagrafica_id']] = ($perPersona[$r['anagrafica_id']] ?? 0) + ($r['scaduta'] ? $r['importo'] : 0);
        }
        $chiave = fn (array $r) => [$ordine === 'scaduto' ? -$perPersona[$r['anagrafica_id']] : 0, mb_strtolower($r['nome']), $r['anagrafica_id'], $r['scadenza'], $r['numero_rata'], (string) $r['unita']];
        usort($righe, fn ($a, $b) => $chiave($a) <=> $chiave($b));

        $fasce = Scadenza::FASCE;
        $totali = ['righe' => count($righe), 'persone' => count($persone), 'importo' => 0, 'scaduto' => 0, 'fasce' => $fasce, 'versato_dopo' => $finito ? 0 : null, 'resta_oggi' => $finito ? 0 : null];
        foreach ($righe as $r) {
            $totali['importo'] += $r['importo'];
            if ($r['scaduta']) {
                $totali['scaduto'] += $r['importo'];
                $totali['fasce'][$r['fascia']] += $r['importo'];
            }
            if ($finito) {
                $totali['versato_dopo'] += $r['versato_dopo'];
                $totali['resta_oggi'] += $r['resta_oggi'];
            }
        }

        return [
            'esercizio' => ['id' => (int) $esercizio->id, 'nome' => $esercizio->nome, 'dal' => $esercizio->data_inizio->format('Y-m-d'), 'al' => $esercizio->data_fine->format('Y-m-d')],
            'alla' => $alla,
            'oggi' => $oggi,
            'finito' => $finito,
            'ordine' => $ordine,
            'righe' => $righe,
            'totali' => $totali,
            'avvertenze' => [
                'data' => self::fraseData($alla, $finito),
                'segno' => 'Gli importi dei condòmini: positivo vuol dire che devono, negativo che hanno un credito. Il credito di una persona non si toglie dalle sue rate: è nelle posizioni dei condòmini.',
                'ordine' => $ordine === 'scaduto' ? 'In ordine di scaduto: prima chi deve di più; per ogni persona le rate per scadenza.' : 'In ordine di persona; per ogni persona le rate per scadenza.',
                'annate' => 'Due annate per rata: l\'esercizio che contiene la scadenza e quello di competenza del piano. L\'art. 1129 c. 9 c.c. parla dell\'esercizio «nel quale il credito esigibile è compreso», e si legge nei due modi: qui ci sono tutte e due.',
                'versato_dopo' => $finito ? 'Versato dopo: quanto è entrato sulla rata dopo il '.CarbonImmutable::parse($alla)->format('d/m/Y').' e fino a oggi, al netto degli storni: un numero negativo è un versamento di prima tornato indietro dopo la data.' : null,
                'lettera' => 'L\'ultima lettera partita o spedita a ogni persona, fino a oggi: una lettera solo stampata, senza la spedizione registrata, non c\'è.',
                'riservato' => self::RISERVATO,
            ],
        ];
    }

    /**
     * Il versato dopo la data, fino a oggi, per (persona, unità, rata) delle sole rate date: avere meno dare delle righe di «Crediti verso condomini»
     * con la data dopo D, di scritture non cancellate. Una somma nel database, non una seconda lettura dei debiti.
     *
     * @param  list<array<string,mixed>>  $righe
     * @return array<string,int>
     */
    private function versatoDopo(int $condominioId, string $alla, string $oggi, array $righe): array
    {
        $dal = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');
        $finoA = CarbonImmutable::parse($oggi, 'UTC')->addDay()->format('Y-m-d');
        $esito = [];
        foreach (DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereNull('sc.deleted_at')
            ->where('sc.condominio_id', $condominioId)
            ->where('sc.data_competenza', '>=', $dal)
            ->where('sc.data_competenza', '<', $finoA)
            ->where('cc.ruolo', 'crediti_condomini')
            ->whereIn('rs.anagrafica_id', array_values(array_unique(array_column($righe, 'anagrafica_id'))))
            ->whereIn('rs.rata_id', array_values(array_unique(array_column($righe, 'rata_id'))))
            ->groupBy('rs.anagrafica_id', 'rs.immobile_id', 'rs.rata_id')
            ->select('rs.anagrafica_id', 'rs.immobile_id', 'rs.rata_id')
            ->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'avere' THEN rs.importo ELSE -rs.importo END) as versato")
            ->get() as $r) {
            $esito[((int) $r->anagrafica_id).'|'.($r->immobile_id === null ? '' : (int) $r->immobile_id).'|'.((int) $r->rata_id)] = (int) $r->versato;
        }

        return $esito;
    }

    /** L'esercizio del piano (il salvato o il dedotto), con la data d'inizio; null se non c'è. */
    private function esercizioDelPiano(int $pianoId): ?array
    {
        $piano = PianoRate::find($pianoId);
        $e = $piano === null ? null : $this->competenza->esercizioDelPiano($piano);

        return $e === null ? null : ['id' => (int) $e->id, 'data_inizio' => $e->data_inizio->format('Y-m-d')];
    }

    /** Una delle due annate della rata, in parole: il nome dell'esercizio senza «Esercizio», o perché manca. */
    private static function annata(array $voce): string
    {
        if (($voce['esercizio']['nome'] ?? null) !== null) {
            return OriginePregresso::nomeEsercizio((string) $voce['esercizio']['nome']);
        }

        return match ($voce['stato']) {
            'origine_non_dichiarata' => 'origine non dichiarata',
            'prima_del_primo', 'prima_degli_esercizi' => 'prima del primo esercizio',
            'manca_esercizio' => 'nessun esercizio la contiene',
            'senza_delibera' => 'senza la data della delibera',
            default => 'non nota',
        };
    }

    private static function fraseData(string $alla, bool $finito): string
    {
        $giorno = CarbonImmutable::parse($alla)->format('d/m/Y');

        return $finito ? 'alla data del '.$giorno : 'a oggi, '.$giorno.': cambia a ogni lettura, finché l\'esercizio è aperto';
    }

    /**
     * Il nome della persona e l'etichetta dell'unità, per righe che hanno i due id.
     *
     * @param  list<array<string,mixed>>  $righe
     * @return list<array<string,mixed>>
     */
    private function conNomi(array $righe): array
    {
        $nomi = $righe === [] ? [] : Anagrafica::whereIn('id', array_values(array_unique(array_column($righe, 'anagrafica_id'))))->pluck('nome', 'id')->all();
        $unita = Immobile::whereIn('id', array_values(array_unique(array_filter(array_column($righe, 'immobile_id')))) ?: [0])->get()->keyBy('id');

        return array_map(fn ($r) => ['anagrafica_id' => $r['anagrafica_id'], 'nome' => (string) ($nomi[$r['anagrafica_id']] ?? ''), 'immobile_id' => $r['immobile_id'],
            'unita' => $r['immobile_id'] !== null && isset($unita[$r['immobile_id']]) ? $unita[$r['immobile_id']]->etichetta : null] + array_diff_key($r, ['anagrafica_id' => 1, 'immobile_id' => 1]), $righe);
    }
}
