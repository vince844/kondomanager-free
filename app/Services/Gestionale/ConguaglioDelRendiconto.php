<?php

namespace App\Services\Gestionale;

use App\Enums\NaturaGestione;
use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\PeriodoCompetenza;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La proposta di conguaglio dell'esercizio come la leggono la scheda «Conguaglio», il riquadro della Sintesi, le due stampe del fascicolo e il
 * foglio della persona: le righe per persona e unità di ogni gestione, con i giorni, i subtotali di chi ha più unità, la riga del condominio, le
 * uscite che nessuno paga con il motivo e la riga «da altre gestioni e da esercizi prima». Le cifre sono quelle di {@see ConsuntivoPerPersona}:
 * qui si danno i nomi, si ordinano le righe e si sommano i totali da mostrare, e nient'altro.
 *
 * UNA LETTURA PER RICHIESTA. La lettura costa (il motore del riparto su ogni voce, le quote, le posizioni): chi la fa la passa a chi la usa nella
 * stessa richiesta, mai rifatta. Il riquadro della Sintesi la fa solo sotto una soglia ({@see SOGLIA_RIQUADRO}): sopra dice di aprire la scheda,
 * che la fa sempre e dice quanto ci ha messo.
 *
 * LE PAROLE. È una proposta da approvare dall'assemblea: mai «dovuto», «esigibile», «pronto». Positivo vuol dire da versare, negativo a credito.
 */
final class ConguaglioDelRendiconto
{
    /**
     * Il peso sopra il quale il riquadro della Sintesi non fa la lettura e rimanda alla scheda. Il peso è unità × gruppi di pesi (una tabella di
     * un'ordinaria, o una tabella per ogni competenza diversa di una straordinaria): è il lavoro del motore del riparto, che costruisce i pesi di
     * ogni unità una volta per gruppo. Misurato il 13/11/2026 su un Apple M1 Pro, 3.000 unità e 30 voci: con un peso di 3.000 la Sintesi con la lettura
     * chiede 1,6 s e 51 MB in più; con 90.000 (una tabella per voce) la lettura sola chiede 14–15 s. Il tempo cresce di circa 0,17 ms per unità di peso:
     * a 15.000, circa 2,5 s in più sulla Sintesi. Il peso conta ogni competenza diversa di una straordinaria anche quando il motore riusa i pesi fra i
     * giorni senza un passaggio in mezzo: per la Sintesi è prudente (100 fatture su 3.000 unità: la scheda le legge in 4,2 s). Le misure sono nei test.
     */
    public const SOGLIA_RIQUADRO = 15000;

    /** Le righe di una pagina della scheda: anche le persone di un archivio dei fogli, al massimo {@see FOGLI_PER_RICHIESTA}. */
    public const RIGHE_PER_PAGINA = 200;

    /** I fogli della persona in un archivio, per richiesta: come le ricevute. */
    public const FOGLI_PER_RICHIESTA = 200;

    public const FRASE_PROPOSTA = 'Proposta di conguaglio da approvare dall\'assemblea: diventa una richiesta solo con l\'approvazione.';

    /** Accanto al saldo finale, quando un piano ha rate non ancora emesse: il saldo non le comprende ancora. */
    public const FRASE_NON_EMESSE = 'Quando un piano dell\'esercizio ha rate non ancora emesse, il saldo finale non le comprende: accanto c\'è quanto si aggiunge quando il piano le emette.';

    public const FRASE_SEGNO = 'Il segno più vuol dire da versare, il segno meno vuol dire a credito.';

    public const FRASE_COMPETENZA = 'Per competenza: ogni spesa dell\'esercizio è divisa fra chi era titolare nei giorni a cui si riferisce, con le tabelle e i millesimi della voce.';

    public const FRASE_GIORNI = 'Accanto a chi ha avuto l\'unità per una parte dell\'anno ci sono i suoi giorni: la sua parte è per quei giorni.';

    public const FRASE_CENTESIMI = 'Ogni voce è divisa al centesimo: il resto va a chi ha la parte decimale più grande, e per questo non c\'è una riga di arrotondamenti.';

    public const FRASE_COLONNE = 'Conguaglio = spese dell\'esercizio + spese personali non ancora chieste − quote dei piani. Saldo finale = posizione sulle rate + saldi fuori dalle rate + conguaglio.';

    public const FRASE_RIMANDA = 'Si calcola nella scheda Conguaglio: con questo condominio la lettura chiede troppo tempo per la Sintesi.';

    public const NOME_DA_ALTRE = 'Da altre gestioni e da esercizi prima';

    /**
     * I motivi delle uscite che nessuno paga, a parole, con che cosa fare: ogni codice dello scoperto del motore del riparto ha la sua frase (un test
     * lo controlla sul codice del motore), e un codice nuovo ha la frase di ripiego, mai il codice.
     */
    public const MOTIVI = [
        'conto_senza_tabella' => 'La voce non ha una tabella millesimale. Collega una tabella con i millesimi alla voce.',
        'tabella_senza_millesimi' => 'La tabella della voce non ha i millesimi delle unità. Scrivi i millesimi nella tabella, o collega alla voce una tabella con i millesimi.',
        'coefficienti_sotto_il_cento' => 'I coefficienti delle tabelle della voce non arrivano al cento. Correggili nella voce.',
        'ripartizioni_sotto_il_cento' => 'Le parti di proprietario, inquilino e usufruttuario della voce non arrivano al cento. Correggile nella voce.',
        'giorni_senza_titolare' => 'In alcuni giorni l\'unità non aveva un titolare. Controlla le date dei titolari dell\'unità.',
        'titolari_fuori_competenza' => 'Nei giorni della spesa l\'unità non aveva un titolare. Controlla le date dei titolari dell\'unità.',
        'senza_titolare' => 'Un\'unità della tabella non ha un titolare. Aggiungi il titolare all\'unità.',
        'ad_personam_senza_titolare' => 'La spesa di una sola unità non ha un titolare quel giorno. Aggiungi il titolare all\'unità.',
        'pregressa_senza_periodo' => 'La spesa pregressa non ha un periodo. Scrivi la competenza sulla fattura.',
        'voce_non_trovata' => 'La voce non c\'è più nel piano dei conti. Ripristinala, o sposta la spesa su un\'altra voce.',
        'periodi_diversi' => 'I piani dell\'esercizio dividono la voce con periodi diversi, e un\'unità della voce ha cambiato titolare: il programma non sceglie quale vale. '
            .'Scrivi la competenza sulle fatture, o correggi i periodi del piano.',
    ];

    /** Il motivo di ripiego, per un codice che non ha la sua frase. */
    public const MOTIVO_DI_RIPIEGO = 'Il programma non ha potuto dividerla fra le persone. Guarda la voce nel piano dei conti.';

    /** Le uscite fuori dai capitoli: il nome e il perché, una frase sola per pezzo. */
    public const FUORI_DAI_CAPITOLI = [
        'commissioni_senza_voce' => ['Commissioni bancarie senza voce', 'Non stanno in nessun capitolo. Assegna la voce nell\'elenco delle commissioni.'],
        'contestate' => ['Fatture contestate', 'Stanno fuori dai capitoli finché sono contestate.'],
        'non_classificati' => ['Costi non classificati', 'Sono righe di costo senza voce. Apri le scritture nel Libro Giornale: la voce si dà stornando la registrazione e rifacendola con la voce.'],
    ];

    /** La soglia che vale; nulla, quella scritta. Una prova la fissa. */
    private static ?int $soglia = null;

    public function __construct(private readonly ConsuntivoPerPersona $consuntivo) {}

    public static function soglia(): int
    {
        return self::$soglia ?? self::SOGLIA_RIQUADRO;
    }

    /**
     * Il peso della lettura dell'esercizio, prima di farla: per ogni gestione, le unità delle tabelle delle sue voci per i gruppi di pesi (le
     * tabelle; in una straordinaria, ogni tabella per ogni competenza diversa delle sue spese).
     */
    public function peso(Esercizio $esercizio): int
    {
        $peso = 0;
        $spese = new SpesaPerVoceService();
        foreach (DB::table('gestioni')->join('esercizio_gestione as eg', 'eg.gestione_id', '=', 'gestioni.id')->where('eg.esercizio_id', $esercizio->id)
            ->get(['gestioni.id', 'gestioni.tipo']) as $g) {
            $voci = DB::table('conti')->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')->where('pc.gestione_id', $g->id)->pluck('conti.id')->all();
            if ($voci === []) {
                continue;
            }
            $tabelle = DB::table('conto_tabella_millesimale')->whereIn('conto_id', $voci)->distinct()->pluck('tabella_id')->all();
            if ($tabelle === []) {
                continue;
            }
            $unita = (int) DB::table('quote_tabella')->whereIn('tabella_id', $tabelle)->distinct()->count('immobile_id');
            $competenze = 1;
            if (NaturaGestione::daStringa($g->tipo) === NaturaGestione::Straordinaria) {
                $competenze = max(1, count(array_unique(array_map(fn ($r) => ($r['competenza_dal'] ?? '') . '|' . ($r['competenza_al'] ?? ''),
                    $spese->perEsercizioEFattura($esercizio, $voci)))));
            } else {
                // Nell'ordinaria ogni competenza scritta su una fattura ha i suoi pesi, oltre a quelli del capitolo.
                $competenze = 1 + count(array_unique(array_map(fn ($r) => $r['competenza_dal'] . '|' . $r['competenza_al'],
                    array_filter($spese->perEsercizioEFattura($esercizio, $voci), fn ($r) => $r['competenza_dal'] !== null && $r['competenza_al'] !== null))));
            }
            $peso += $unita * count($tabelle) * $competenze;
        }

        return $peso;
    }

    /**
     * La lettura, con il giorno e l'ora del calcolo e i secondi che ha chiesto.
     *
     * @param  list<int>  $righePer  le persone di cui tenere le righe di spiegazione (il foglio)
     */
    public function leggi(Esercizio $esercizio, array $righePer = [], bool $perTabella = false): array
    {
        $inizio = hrtime(true);
        $letto = $this->consuntivo->perEsercizio($esercizio, $righePer, null, $perTabella);
        $letto['secondi'] = round((hrtime(true) - $inizio) / 1e9, 1);
        $letto['calcolato_il'] = CarbonImmutable::now()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y \a\l\l\e H:i');
        $letto['esercizio_id'] = (int) $esercizio->id;

        return $letto;
    }

    /** La data della lettura a parole: alla fine dell'esercizio, o a oggi finché è aperto. */
    public static function data(Esercizio $esercizio, array $letto): array
    {
        $fine = substr((string) $esercizio->data_fine, 0, 10);
        $finito = $fine !== '' && $fine < DateHelper::oggiUtente();
        $giorno = CarbonImmutable::parse($letto['alla'])->format('d/m/Y');

        return ['alla' => $letto['alla'], 'finito' => $finito, 'calcolato_il' => $letto['calcolato_il'] ?? null,
            'frase' => $finito ? 'Alla fine dell\'esercizio, il '.$giorno.'; calcolato il '.($letto['calcolato_il'] ?? $giorno).'.'
                : 'Esercizio aperto: a oggi, '.$giorno.', e cambia a ogni registrazione; calcolato il '.($letto['calcolato_il'] ?? $giorno).'.'];
    }

    /** Il riquadro della Sintesi: le cifre del condominio dalla lettura, o il rimando alla scheda sopra la soglia. */
    public function riquadro(Esercizio $esercizio, ?array $letto, ?int $peso = null): array
    {
        if ($letto === null) {
            return ['cifre' => null, 'frase' => self::FRASE_RIMANDA, 'peso' => $peso];
        }
        $t = self::totaliDelCondominio($letto);

        // Le uscite che nessuno paga si dicono anche qui: costi = spese divise fra le persone + non attribuite + fuori dai capitoli.
        return ['cifre' => $t, 'peso' => $peso, 'data' => self::data($esercizio, $letto), 'frase' => self::FRASE_PROPOSTA,
            'non_attribuite' => $t['non_attribuite'] === 0 ? null
                : '€ '.number_format($t['non_attribuite'] / 100, 2, ',', '.').' di spese non attribuite a nessuno: le dice la scheda Conguaglio, con il perché.',
            'non_disponibili' => self::fraseNonDisponibili($t, self::nonSiCalcolano($letto))];
    }

    /** La somma delle gestioni e della riga da altre gestioni: la riga del condominio. */
    public static function totaliDelCondominio(array $letto): array
    {
        $t = ['a' => 0, 'personali' => 0, 'b' => 0, 'conguaglio' => 0, 'posizione' => 0, 'fuori_dalle_rate' => 0, 'saldo' => 0, 'non_disponibili' => 0, 'a_non_disponibili' => 0,
            'non_attribuite' => 0];
        foreach ($letto['gestioni'] as $g) {
            foreach (array_keys($t) as $k) {
                if ($k !== 'non_attribuite') {
                    $t[$k] += (int) ($g['totali'][$k] ?? 0);
                }
            }
            $t['non_attribuite'] += (int) array_sum($g['non_attribuite'] ?? []);
        }
        $altre = $letto['da_altre_gestioni']['totali'] ?? ['posizione' => 0, 'personali' => 0, 'conguaglio' => 0, 'saldo' => 0];
        $t['personali'] += $altre['personali'];
        $t['conguaglio'] += $altre['conguaglio'];
        $t['posizione'] += $altre['posizione'];
        $t['saldo'] += $altre['saldo'];

        return $t;
    }

    /**
     * Le righe non disponibili con la loro spesa dell'esercizio, che i totali non contano: la frase, o null se non ce ne sono. Le righe di una gestione
     * che non si calcola non hanno una spesa divisa: la frase dice la gestione e le sue spese dell'esercizio, non divise (`$nonSiCalcolano`).
     *
     * @param  array{non_disponibili?:int, a_non_disponibili?:int}  $totali
     * @param  list<array{nome:string, speso:int}>  $nonSiCalcolano
     */
    public static function fraseNonDisponibili(array $totali, array $nonSiCalcolano = []): ?string
    {
        $n = (int) ($totali['non_disponibili'] ?? 0);
        if ($n === 0) {
            return null;
        }
        $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
        if ($nonSiCalcolano === []) {
            return 'Le righe non disponibili ('.$n.', spese dell\'esercizio '.$euro((int) ($totali['a_non_disponibili'] ?? 0)).') non sono nei totali.';
        }
        $a = (int) ($totali['a_non_disponibili'] ?? 0);

        return 'Le righe non disponibili ('.$n.($a === 0 ? '' : ', spese dell\'esercizio divise '.$euro($a)).') non sono nei totali. '
            .implode(' ', array_map(fn (array $g) => '«'.$g['nome'].'» non si calcola: le sue spese dell\'esercizio, '.$euro($g['speso']).', non sono divise.', $nonSiCalcolano));
    }

    /**
     * Le gestioni che non si calcolano, con le loro spese dell'esercizio: le loro righe sono non disponibili, e la frase lo dice.
     *
     * @return list<array{nome:string, speso:int}>
     */
    public static function nonSiCalcolano(array $letto): array
    {
        $elenco = [];
        foreach ($letto['gestioni'] ?? [] as $g) {
            if (($g['non_calcolabile'] ?? null) !== null && (int) ($g['totali']['non_disponibili'] ?? 0) !== 0) {
                $elenco[] = ['nome' => (string) $g['nome'], 'speso' => (int) ($g['speso'] ?? 0)];
            }
        }

        return $elenco;
    }

    /**
     * Le righe da mostrare o stampare: per gestione, una per persona e unità (anche chi è uscito, l'inquilino con la sua parte), in ordine di nome
     * e di unità, con il subtotale di chi ha più unità (le righe restano: mai un netto fra unità); poi la riga da altre gestioni; in fondo le uscite
     * che nessuno paga e la riga del condominio. Per la stampa (`$perStampa`) niente note dei passaggi né avvisi: quelli sono per l'amministratore.
     *
     * @param  array<string,mixed>|null  $fuori  i costi fuori dai capitoli del riepilogo (`fuori_capitoli`), se chi chiama li ha letti
     */
    public function righe(Condominio $condominio, Esercizio $esercizio, array $letto, ?array $fuori = null, bool $perStampa = false): array
    {
        $aids = [];
        $iids = [];
        foreach ($letto['persone'] as $perPersona) {
            foreach ($perPersona as $aid => $perUnita) {
                $aids[$aid] = true;
                foreach ($perUnita as $iid => $_) {
                    $iids[$iid] = true;
                }
            }
        }
        foreach ($letto['da_altre_gestioni']['persone'] ?? [] as $aid => $perUnita) {
            $aids[$aid] = true;
            foreach ($perUnita as $iid => $_) {
                $iids[$iid] = true;
            }
        }
        // Le unità del già versato non usato senza un titolare alla data: il loro nome, nella riga della gestione.
        foreach ($letto['gestioni'] as $g) {
            foreach (array_keys($g['gia_versato_non_usato_senza_titolare'] ?? []) as $iid) {
                $iids[$iid] = true;
            }
        }
        $nomi = [];
        foreach (array_chunk(array_keys($aids), 1000) as $ids) {
            $nomi += Anagrafica::whereIn('id', $ids)->pluck('nome', 'id')->all();
        }
        $unita = [];
        foreach (array_chunk(array_keys($iids), 1000) as $ids) {
            foreach (Immobile::whereIn('id', $ids)->get() as $i) {
                $unita[(int) $i->id] = (string) $i->etichetta;
            }
        }
        $giorni = $this->giorniDiTitolarita($esercizio, array_keys($iids));
        $conti = [];
        foreach ($letto['gestioni'] as $g) {
            foreach (array_keys($g['non_attribuite'] ?? []) as $cid) {
                $conti[$cid] = true;
            }
        }
        $nomiConti = $conti === [] ? [] : DB::table('conti')->whereIn('id', array_keys($conti))->get(['id', 'nome', 'piano_conto_id'])->keyBy('id')->all();
        // I nomi delle voci delle spese con la competenza scritta sulla fattura, per le note della scheda.
        $scritte = [];
        if (! $perStampa) {
            foreach ($letto['persone'] as $perPersona) {
                foreach ($perPersona as $perUnita) {
                    foreach ($perUnita as $r) {
                        foreach ($r['competenze_scritte'] ?? [] as $c) {
                            $scritte[(int) $c['conto_id']] = true;
                        }
                    }
                }
            }
        }
        $nomiScritte = $scritte === [] ? [] : DB::table('conti')->whereIn('id', array_keys($scritte))->pluck('nome', 'id')->all();
        // Chi passa i costi fuori dai capitoli vuole la scheda o la stampa intera: le coperture degli sfori e il secondo momento.
        $coperture = $fuori !== null && ! $perStampa ? $this->copertureDaiFondi($esercizio) : [];

        $gestioni = [];
        foreach ($letto['gestioni'] as $gid => $g) {
            $come = [];
            foreach ($g['passaggi'] ?? [] as $p) {
                $come[$p['come']] = true;
            }
            $righe = [];
            // Le rate non ancora emesse delle righe che hanno il saldo: accanto al saldo dei totali, come accanto a quello di ogni riga.
            $nonEmesse = 0;
            foreach ($letto['persone'][$gid] ?? [] as $aid => $perUnita) {
                foreach ($perUnita as $iid => $r) {
                    $riga = $this->riga($aid, $iid, $r, $nomi, $unita, $giorni, $perStampa, $nomiScritte);
                    $nonEmesse += $riga['saldo'] === null ? 0 : (int) $riga['non_emesse'];
                    $righe[] = $riga;
                }
            }
            $gestioni[] = [
                'id' => (int) $gid, 'nome' => $g['nome'], 'tipo' => $g['tipo'], 'non_calcolabile' => $g['non_calcolabile'],
                'righe' => self::conSubtotali($righe), 'totali' => $g['totali'] + ['non_emesse' => $nonEmesse],
                'non_attribuite' => array_map(fn ($cid, $importo) => ['conto_id' => (int) $cid, 'voce' => (string) ($nomiConti[$cid]->nome ?? ''),
                    'piano_conto_id' => isset($nomiConti[$cid]) ? (int) $nomiConti[$cid]->piano_conto_id : null, 'importo' => (int) $importo,
                    'motivo' => isset($g['periodi_diversi'][$cid]) ? self::frasePeriodiDiversi($g['periodi_diversi'][$cid]) : self::motivo($g['motivi'][$cid] ?? [])],
                    array_keys($g['non_attribuite'] ?? []), array_values($g['non_attribuite'] ?? [])),
                'in_corso' => self::operaInCorso($g, (int) $gid, $perStampa),
                'opera_finita' => self::operaFinita($g),
                'coperture' => $coperture[(int) $gid] ?? [],
                'gia_versato_senza_titolare' => self::fraseSenzaTitolare($g['gia_versato_non_usato_senza_titolare'] ?? [], $unita),
                // Gli avvisi per l'amministratore sotto il nome della gestione: le spese personali di giorni senza titolare, i piani con l'esercizio dedotto.
                'avvisi' => $perStampa ? [] : self::avvisi($g, $unita),
            ];
        }

        $altre = [];
        foreach ($letto['da_altre_gestioni']['persone'] ?? [] as $aid => $perUnita) {
            foreach ($perUnita as $iid => $r) {
                $altre[] = ['anagrafica_id' => (int) $aid, 'nome' => (string) ($nomi[$aid] ?? ''), 'immobile_id' => (int) $iid, 'unita' => $unita[$iid] ?? null,
                    'giorni' => null, 'a' => 0, 'personali' => $r['personali']['non_chieste'], 'b' => 0, 'b_non_emesse' => 0, 'non_emesse' => 0, 'conguaglio' => $r['conguaglio'],
                    'posizione' => $r['posizione'], 'fuori' => 0, 'saldo' => $r['saldo'], 'non_disponibile' => null, 'note' => [], 'subtotale' => false];
            }
        }
        usort($altre, fn ($a, $b) => [mb_strtolower($a['nome']), (string) $a['unita']] <=> [mb_strtolower($b['nome']), (string) $b['unita']]);

        $usciteSenza = [];
        if ($fuori !== null) {
            foreach (self::FUORI_DAI_CAPITOLI as $chiave => [$nome, $motivo]) {
                if ((int) ($fuori[$chiave]['consuntivo'] ?? 0) !== 0) {
                    $usciteSenza[] = ['nome' => $nome, 'importo' => (int) $fuori[$chiave]['consuntivo'], 'motivo' => $motivo];
                }
            }
        }

        return [
            'gestioni' => $gestioni,
            'da_altre_gestioni' => ['nome' => self::NOME_DA_ALTRE, 'righe' => self::conSubtotali($altre), 'totali' => $letto['da_altre_gestioni']['totali'] ?? null],
            'fuori_dai_capitoli' => $usciteSenza,
            'condominio' => $totali = self::totaliDelCondominio($letto) + ['non_emesse' => (int) array_sum(array_map(fn ($g) => $g['totali']['non_emesse'], $gestioni))],
            'non_disponibili' => self::fraseNonDisponibili($totali, self::nonSiCalcolano($letto)),
            'data' => self::data($esercizio, $letto),
            'secondi' => $letto['secondi'] ?? null,
            'secondo_momento' => $fuori === null ? null : $this->secondoMomento($condominio, $letto, $gestioni, $altre),
        ];
    }

    /**
     * Il secondo momento, come righe e non come una situazione patrimoniale: dopo l'approvazione e la scrittura del conguaglio, i crediti verso i
     * condòmini sono quelli di oggi (letti da dove li legge la situazione patrimoniale), meno le spese personali che il conguaglio chiede (oggi sono già
     * fra i crediti, come spese anticipate per singole unità), più i conguagli a debito; i debiti verso i condòmini per il conguaglio sono i conguagli a
     * credito. Nessun netto fra persone né fra unità; le righe non disponibili non contano.
     *
     * @param  list<array<string,mixed>>  $gestioni  le gestioni di {@see righe()}, con le loro righe
     * @param  list<array<string,mixed>>  $altre  le righe da altre gestioni e da esercizi prima
     */
    public function secondoMomento(Condominio $condominio, array $letto, array $gestioni, array $altre): array
    {
        $aDebito = 0;
        $aCredito = 0;
        $personali = 0;
        $fuori = 0;
        $nonDisponibili = 0;
        $righe = $altre;
        foreach ($gestioni as $g) {
            array_push($righe, ...$g['righe']);
        }
        foreach ($righe as $r) {
            if ($r['subtotale'] || $r['conguaglio'] === null) {
                $nonDisponibili += ! $r['subtotale'] ? 1 : 0;

                continue;
            }
            $aDebito += max(0, (int) $r['conguaglio']);
            $aCredito += max(0, -(int) $r['conguaglio']);
            $personali += (int) $r['personali'];
            $fuori += (int) ($r['fuori'] ?? 0);
        }
        $oggi = app(StatoPatrimonialePaginaService::class)->creditiVersoICondomini($condominio, (string) $letto['alla']);
        // I saldi non ancora a giornale (un saldo scritto a mano non in un piano, la rata 0 o il riporto non emessi) sono nel saldo della persona e non nei
        // crediti di oggi: con loro crediti dopo − debiti = la somma dei saldi.
        $dopo = $oggi - $personali + $aDebito + $fuori;
        $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');

        return ['crediti_oggi' => $oggi, 'personali' => $personali, 'a_debito' => $aDebito, 'fuori' => $fuori, 'crediti_dopo' => $dopo, 'a_credito' => $aCredito,
            'frase' => 'Dopo l\'approvazione e la scrittura del conguaglio: crediti verso i condòmini '.$euro($dopo).' (oggi '.$euro($oggi)
                .', meno le spese personali che il conguaglio chiede '.$euro($personali).', più i conguagli a debito '.$euro($aDebito)
                .($fuori === 0 ? '' : ', più i saldi non ancora a giornale '.$euro($fuori)).'); debiti verso i condòmini per i conguagli a credito '
                .$euro($aCredito).'.'.($nonDisponibili === 0 ? '' : ' Le righe non disponibili ('.$nonDisponibili.') non sono contate.')];
    }

    /**
     * Il già versato non usato di unità che alla data non hanno un titolare di diritto reale: non va a nessuna persona, resta della gestione. Nella
     * riga della gestione, con le unità e la cifra; null se non ce n'è.
     *
     * @param  array<int,int>  $perUnita
     * @param  array<int,string>  $nomiUnita
     */
    public static function fraseSenzaTitolare(array $perUnita, array $nomiUnita): ?string
    {
        $perUnita = array_filter($perUnita, fn ($c) => (int) $c !== 0);
        if ($perUnita === []) {
            return null;
        }
        $nomi = implode(', ', array_map(fn ($iid) => $nomiUnita[$iid] ?? '—', array_keys($perUnita)));

        return 'Già versato registrato e non usato di unità senza un titolare di diritto reale alla data ('.$nomi.'): € '
            .number_format(array_sum($perUnita) / 100, 2, ',', '.').'. Resta della gestione: non è nel conguaglio di nessuno.';
    }

    /**
     * Gli avvisi della gestione per l'amministratore, con la strada: le spese per singole unità di giorni in cui l'unità non aveva un titolare (non sono nel
     * conguaglio di nessuno), i piani dell'esercizio senza l'esercizio scritto (il programma lo deduce).
     *
     * @param  array<int,string>  $nomiUnita
     * @return list<string>
     */
    public static function avvisi(array $g, array $nomiUnita): array
    {
        $avvisi = [];
        $senza = array_filter($g['personali_senza_titolare'] ?? [], fn ($c) => (int) $c !== 0);
        if ($senza !== []) {
            $avvisi[] = 'Spese per singole unità di giorni in cui l\'unità non aveva un titolare ('.implode(', ', array_map(fn ($iid) => $nomiUnita[$iid] ?? '—', array_keys($senza)))
                .'): € '.number_format(array_sum($senza) / 100, 2, ',', '.').'. Non sono nel conguaglio di nessuno: controlla le date dei titolari, o scrivi la competenza sulla fattura.';
        }
        foreach ($g['piani_dedotti'] ?? [] as $d) {
            $avvisi[] = 'Il piano «'.$d['piano'].'» non ha l\'esercizio scritto: il programma lo legge come piano di «'.$d['esercizio'].'» dalla sua data. '
                .'Se non è il suo, scrivi l\'esercizio nel piano.';
        }

        return $avvisi;
    }

    /** La voce ferma per i periodi diversi di due piani: quali piani, e che cosa fare. */
    public static function frasePeriodiDiversi(array $piani): string
    {
        $nomi = array_map(fn (string $n) => '«'.$n.'»', $piani);
        $elenco = count($nomi) === 1 ? $nomi[0] : implode(', ', array_slice($nomi, 0, -1)).' e '.end($nomi);

        return 'I piani '.$elenco.' dividono la voce con periodi diversi, e un\'unità della voce ha cambiato titolare: il programma non sceglie quale vale. '
            .'Scrivi la competenza sulle fatture, o correggi i periodi del piano.';
    }

    /** Le spese personali di un'unità che non tornano con le sue anticipazioni aperte: le cifre, e dove guardare. */
    public static function frasePersonaliNonTornano(array $u): string
    {
        $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
        $dette = (int) $u['non_chieste'] + (int) $u['senza_titolare'] + (int) $u['non_emesse'];

        return 'Le spese per questa unità ancora da chiedere o chieste con rate non emesse ('.$euro($dette).') non sono le anticipazioni aperte dell\'unità ('
            .$euro((int) $u['aperte']).'): '.($dette < (int) $u['aperte'] ? 'mancano ' : 'in più ').$euro(abs((int) $u['aperte'] - $dette))
            .'. Guarda le fatture dell\'unità e i piani che le chiedono.';
    }

    /**
     * L'opera straordinaria in corso, a parole: lo speso e il chiesto, senza proporre; alla scheda anche che cosa fare quando la data di fine manca. L'opera
     * finita in un esercizio prima: dove è il suo conguaglio, e le spese scritte qui dopo la fine, con l'avviso alla scheda.
     */
    public static function operaInCorso(array $g, int $gestioneId, bool $perStampa): ?array
    {
        $f = $g['finita_prima'] ?? null;
        if ($f !== null) {
            // L'opera finita in un esercizio prima: qui non si propone di nuovo. Le spese scritte qui, dopo la fine, si conguagliano qui, e la frase le dice;
            // alla scheda anche l'avviso: se l'opera non era finita, la data di fine va spostata (lo decide l'amministratore).
            $quando = 'Opera finita il '.CarbonImmutable::parse($f['data_fine'])->format('d/m/Y');
            $dove = $f['esercizio'] === null ? 'quell\'esercizio' : '«'.$f['esercizio'].'»';
            $dopo = (int) ($f['speso_esercizio'] ?? 0);
            $frase = $dopo === 0 ? $quando.': il conguaglio è nel rendiconto di '.$dove.'.'
                : $quando.': il conguaglio dell\'opera è nel rendiconto di '.$dove.'; qui le spese scritte dopo la fine, € '.number_format($dopo / 100, 2, ',', '.').'.'
                    .($perStampa ? '' : ' Se l\'opera non era finita, sposta la data di fine della gestione.');

            return ['frase' => $frase, 'gestione_id' => $gestioneId, 'senza_data_fine' => false, 'sposta_data_fine' => $dopo !== 0 && ! $perStampa, 'spesi' => null, 'chiesti' => null];
        }
        $i = $g['in_corso'] ?? null;
        if ($i === null) {
            return null;
        }
        $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
        $cifre = 'spesi '.$euro((int) $i['spesi']).', chiesti '.$euro((int) $i['chiesti']).'. Il conguaglio si propone quando l\'opera è finita';
        $frase = $i['data_fine'] === null
            ? 'Opera in corso: '.$cifre.($perStampa ? '.' : ': scrivi la data di fine della gestione.')
            : 'Opera in corso fino al '.CarbonImmutable::parse($i['data_fine'])->format('d/m/Y').': '.$cifre.'.'
                // Una gestione non legata all'esercizio in cui finisce lì non c'è: il conguaglio non si proporrebbe mai.
                .(! $perStampa && ($i['legata_alla_fine'] ?? true) === false
                    ? ' La gestione non è legata all\'esercizio in cui finisce: salvala dalla sua pagina quando quell\'esercizio è aperto, e il conguaglio si propone nel suo rendiconto.' : '');

        return ['frase' => $frase, 'gestione_id' => $gestioneId, 'senza_data_fine' => $i['data_fine'] === null, 'sposta_data_fine' => false, 'spesi' => (int) $i['spesi'], 'chiesti' => (int) $i['chiesti']];
    }

    /** L'opera finita in questo esercizio su più esercizi: il conguaglio è sull'intera gestione, e la frase dice con quali esercizi. */
    public static function operaFinita(array $g): ?string
    {
        $f = $g['opera_finita'] ?? null;
        if ($f === null) {
            return null;
        }
        $nomi = array_map(fn (string $n) => '«'.$n.'»', $f['esercizi_prima']);

        return 'Opera finita il '.CarbonImmutable::parse($f['data_fine'])->format('d/m/Y').': il conguaglio è sull\'intera gestione, con le spese e le quote di '
            .implode(', ', $nomi).' e di questo esercizio (le spese degli esercizi prima: € '.number_format(((int) $f['spese_esercizi_prima']) / 100, 2, ',', '.').').';
    }

    /**
     * Gli sfori coperti da un fondo, per gestione: la copertura indicata sulla fattura dell'esercizio (`fattura_coperture`, il fondo di riserva) e, se è
     * già eseguita con un giroconto dal fondo, che il fondo ha già pagato e che i condòmini li rimettono con il conguaglio lordo (al fondo tornano con un
     * giroconto, che il programma non fa da solo); e la cifra al netto della copertura. Il conguaglio resta lordo: se usare il fondo lo decide l'assemblea.
     *
     * @return array<int, list<string>>
     */
    public function copertureDaiFondi(Esercizio $esercizio): array
    {
        $righe = DB::table('fattura_coperture as fc')->join('fatture_passive as fp', 'fp.id', '=', 'fc.fattura_passiva_id')
            ->leftJoin('conti_contabili as cc', 'cc.id', '=', 'fc.fondo_id')
            ->where('fp.condominio_id', $esercizio->condominio_id)->where('fc.tipo_copertura', 'fondo_riserva')
            ->groupBy('fp.id', 'fp.numero_documento', 'cc.nome')
            ->orderBy('fp.id')
            ->get(['fp.id', 'fp.numero_documento', 'cc.nome as fondo', DB::raw('SUM(fc.importo) as importo'),
                DB::raw("SUM(CASE WHEN fc.stato = 'confermata' AND fc.scrittura_giroconto_id IS NOT NULL THEN fc.importo ELSE 0 END) as pagato")]);
        if ($righe->isEmpty()) {
            return [];
        }
        // La voce e la gestione dalle scritture di competenza della fattura in questo esercizio.
        $dove = DB::table('fattura_scrittura as fs')->join('scritture_contabili as sc', 'sc.id', '=', 'fs.scrittura_contabile_id')
            ->join('righe_scritture as rs', 'rs.scrittura_id', '=', 'sc.id')->join('conti as v', 'v.id', '=', 'rs.voce_spesa_id')
            ->whereIn('fs.fattura_passiva_id', $righe->pluck('id')->all())->where('fs.tipo', 'competenza')->whereNull('sc.deleted_at')->where('sc.esercizio_id', $esercizio->id)
            ->distinct()->get(['fs.fattura_passiva_id', 'sc.gestione_id', 'v.nome'])->groupBy('fattura_passiva_id');
        $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
        $esito = [];
        foreach ($righe as $r) {
            if ((int) $r->importo === 0 || ! isset($dove[$r->id])) {
                continue;
            }
            $fondo = '«'.((string) ($r->fondo ?? 'fondo')).'»';
            $voci = $dove[$r->id]->pluck('nome')->unique()->implode(', ');
            $frase = $voci.': la fattura n. '.$r->numero_documento.' indica la copertura dello sforo con il fondo '.$fondo.', '.$euro((int) $r->importo).'. '
                .((int) $r->pagato !== 0
                    ? 'Il fondo ha già pagato '.$euro((int) $r->pagato).'. Se l\'assemblea approva il conguaglio lordo, i condòmini li rimettono e tornano al fondo con un '
                        .'giroconto dalla banca; se lo approva al netto, il fondo resta com\'è.'
                    : 'Il conguaglio è lordo: se usare il fondo lo decide l\'assemblea quando approva.')
                // L'altra cifra, per l'assemblea che sceglie: il conguaglio del condominio al netto della copertura.
                .' Al netto della copertura il conguaglio del condominio sarebbe '.$euro((int) $r->importo).' più basso.';
            $esito[(int) $dove[$r->id]->first()->gestione_id][] = $frase;
        }

        return $esito;
    }

    /** Una riga per persona e unità di una gestione, con le note per l'amministratore (non in stampa). */
    private function riga(int $aid, int $iid, array $r, array $nomi, array $unita, array $giorni, bool $perStampa, array $nomiScritte = []): array
    {
        $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
        $note = [];
        if (! $perStampa) {
            if (($r['b']['regolato'] ?? 0) !== 0) {
                $note[] = ($r['passaggio'] ?? null) === 'non_scritto'
                    ? 'Nelle quote '.$euro($r['b']['regolato']).' per il passaggio non scritto: la sua parte per giorni, letta dal registro; la posizione resta com\'è.'
                    : 'Nelle quote '.$euro($r['b']['regolato']).' della cifra del passaggio regolata fra le parti, letta dal registro del passaggio.';
            }
            if (($r['b']['coppie'] ?? 0) !== 0) {
                $note[] = 'Nelle quote '.$euro($r['b']['coppie']).' della coppia del passaggio.';
            }
            if (($r['avviso_anno_prima'] ?? null) !== null) {
                $note[] = $r['avviso_anno_prima']['frase'];
            }
            if ((int) ($r['gia_versato_non_usato']['questo_esercizio'] ?? 0) !== 0) {
                $note[] = 'Già versato registrato per questo esercizio e non usato dai suoi piani: '.$euro((int) $r['gia_versato_non_usato']['questo_esercizio'])
                    .'. Non entra nel conguaglio, e il programma non lo sposta da solo: che cosa farne lo decidi tu (per esempio con un piano che lo usa).';
            }
            if ((int) ($r['gia_versato_non_usato']['tutti_gli_esercizi'] ?? 0) !== 0) {
                $note[] = 'Già versato registrato per tutti gli esercizi e non usato dai piani di questo: '.$euro((int) $r['gia_versato_non_usato']['tutti_gli_esercizi'])
                    .'. Un piano di un altro esercizio può ancora usarlo; non entra nel conguaglio.';
            }
            if (($r['personali']['chieste_non_emesse'] ?? 0) !== 0) {
                $note[] = 'Spese personali chieste con rate non ancora emesse: '.$euro($r['personali']['chieste_non_emesse']).'.';
            }
            if ((int) ($r['gia_versato_da_decidere'] ?? 0) !== 0) {
                $note[] = 'Già versato registrato per questa unità e non usato dai piani: '.$euro((int) $r['gia_versato_da_decidere']).'. L\'unità ha cambiato titolare: '
                    .'non è sul foglio di nessuno, a chi va lo decidi tu.';
            }
            foreach ($r['passaggio_non_registrato'] ?? [] as $frase) {
                $note[] = $frase.' Registra il passaggio dalla pagina dei titolari dell\'unità.';
            }
            if (($r['personali_non_tornano'] ?? null) !== null) {
                $note[] = self::frasePersonaliNonTornano($r['personali_non_tornano']);
            }
            foreach ($r['competenze_scritte'] ?? [] as $c) {
                $note[] = ($nomiScritte[$c['conto_id']] ?? 'Una voce').': '.self::giorni($c['dal'], $c['al'], (int) ($c['spese'] ?? 1)).', '.$euro((int) $c['unita']).' su questa unità, '
                    .$euro((int) $c['importo']).' a questa persona: '.self::comeScritto($c).'.';
            }
        }

        return [
            'anagrafica_id' => $aid, 'nome' => (string) ($nomi[$aid] ?? ''), 'immobile_id' => $iid, 'unita' => $unita[$iid] ?? null,
            'giorni' => $giorni[$aid][$iid] ?? null,
            'a' => (int) $r['a'], 'personali' => (int) $r['personali']['non_chieste'], 'b' => $r['b']['totale'], 'b_non_emesse' => (int) $r['b']['di_cui_non_emesse'],
            // Le rate non ancora emesse dei piani dell'esercizio (la spesa delle quote, anche personale): il saldo non le comprende ancora.
            'non_emesse' => (int) $r['b']['di_cui_non_emesse'] + (int) ($r['personali']['chieste_non_emesse'] ?? 0),
            'conguaglio' => $r['conguaglio'], 'posizione' => (int) $r['posizione'], 'fuori' => (int) $r['fuori_dalle_rate'], 'saldo' => $r['saldo'],
            'non_disponibile' => $r['non_disponibile'], 'note' => $note, 'subtotale' => false, 'in_corso' => (bool) ($r['in_corso'] ?? false),
        ];
    }

    /** «spesa del 15/03/2026», «spesa dal 01/03/2026 al 31/03/2026», o con più spese «2 spese dal 15/03/2026 al 10/09/2026». */
    public static function giorni(string $dal, string $al, int $spese = 1): string
    {
        $f = fn (string $d) => CarbonImmutable::parse($d)->format('d/m/Y');

        return $spese > 1 ? $spese.' spese dal '.$f($dal).' al '.$f($al) : ($dal === $al ? 'spesa del '.$f($dal) : 'spesa dal '.$f($dal).' al '.$f($al));
    }

    /** «a chi era titolare quel giorno, come scritto sulla fattura», o con più giorni e più fatture il plurale. */
    public static function comeScritto(array $c): string
    {
        return 'a chi era titolare '.($c['dal'] === $c['al'] ? 'quel giorno' : 'in quei giorni').', come scritto '.((int) ($c['spese'] ?? 1) > 1 ? 'sulle fatture' : 'sulla fattura');
    }

    /**
     * Le righe in ordine di nome e di unità, con il subtotale dopo le righe di chi ne ha più d'una: la somma delle sue righe, che restano. Una
     * riga non disponibile fa il subtotale non disponibile nelle colonne che non ha.
     */
    public static function conSubtotali(array $righe): array
    {
        usort($righe, fn ($a, $b) => [mb_strtolower($a['nome']), $a['anagrafica_id'], (string) $a['unita']] <=> [mb_strtolower($b['nome']), $b['anagrafica_id'], (string) $b['unita']]);
        $fuori = [];
        $perPersona = [];
        foreach ($righe as $r) {
            $perPersona[$r['anagrafica_id']][] = $r;
        }
        foreach ($perPersona as $aid => $sue) {
            array_push($fuori, ...$sue);
            if (count($sue) < 2) {
                continue;
            }
            $s = ['anagrafica_id' => $aid, 'nome' => 'Totale di '.$sue[0]['nome'], 'immobile_id' => null, 'unita' => count($sue).' unità', 'giorni' => null, 'non_disponibile' => null,
                'note' => [], 'subtotale' => true];
            foreach (['a', 'personali', 'b', 'b_non_emesse', 'non_emesse', 'conguaglio', 'posizione', 'fuori', 'saldo'] as $k) {
                $valori = array_column($sue, $k);
                $s[$k] = in_array(null, $valori, true) ? null : (int) array_sum($valori);
            }
            $fuori[] = $s;
        }

        return $fuori;
    }

    /**
     * I giorni di titolarità nell'esercizio di ogni persona su ogni unità (`RisolutoreTitolari::giorniDiTitolarita`, la regola del motore), solo per
     * chi l'ha avuta per una parte dell'anno.
     *
     * @param  list<int>  $iids
     * @return array<int, array<int,int>>
     */
    public function giorniDiTitolarita(Esercizio $esercizio, array $iids): array
    {
        $periodo = PeriodoCompetenza::daEsercizio($esercizio);
        $tutti = $periodo->giorni();
        $risolutore = app(RisolutoreTitolari::class);
        $giorni = [];
        foreach (array_chunk($iids, 1000) as $ids) {
            // Le sole righe attive, come il motore (una riga spenta non partecipa); i giorni di più righe della stessa persona si sommano.
            $righe = DB::table('anagrafica_immobile')->whereIn('immobile_id', $ids)->where('attivo', true)
                ->get(['id', 'anagrafica_id', 'immobile_id', 'tipologia', 'quota', 'attivo', 'data_inizio', 'data_fine']);
            foreach ($righe->groupBy(fn ($r) => $r->immobile_id.'|'.$r->tipologia) as $coppia) {
                foreach ($coppia as $r) {
                    $n = $risolutore->giorniDiTitolarita($r, $periodo, $coppia);
                    $giorni[(int) $r->anagrafica_id][(int) $r->immobile_id] = min($tutti, ($giorni[(int) $r->anagrafica_id][(int) $r->immobile_id] ?? 0) + $n);
                }
            }
        }
        foreach ($giorni as $aid => $perUnita) {
            foreach ($perUnita as $iid => $n) {
                if ($n >= $tutti || $n <= 0) {
                    unset($giorni[$aid][$iid]);
                }
            }
        }

        return $giorni;
    }

    /**
     * Il consuntivo per tabella, dalla lettura con `per_tabella`: per gestione, le tabelle con i loro millesimi, e una riga per unità e persona con la
     * spesa su ogni tabella, il già versato dell'unità tolto (una colonna sua, come nel preventivo) e il totale, che è A. In ordine di unità; i
     * totali per tabella e il totale della gestione.
     */
    public function perTabella(Condominio $condominio, Esercizio $esercizio, array $letto): array
    {
        $aids = [];
        $iids = [];
        foreach ($letto['persone'] as $perPersona) {
            foreach ($perPersona as $aid => $perUnita) {
                $aids[(int) $aid] = true;
                foreach (array_keys($perUnita) as $iid) {
                    $iids[(int) $iid] = true;
                }
            }
        }
        // Le unità del già versato non usato senza un titolare alla data: il loro nome, nella riga della gestione.
        foreach ($letto['gestioni'] as $g) {
            foreach (array_keys($g['gia_versato_non_usato_senza_titolare'] ?? []) as $iid) {
                $iids[$iid] = true;
            }
        }
        $nomi = [];
        foreach (array_chunk(array_keys($aids), 1000) as $ids) {
            $nomi += Anagrafica::whereIn('id', $ids)->pluck('nome', 'id')->all();
        }
        $unita = [];
        foreach (array_chunk(array_keys($iids), 1000) as $ids) {
            foreach (Immobile::whereIn('id', $ids)->get() as $i) {
                $unita[(int) $i->id] = ['etichetta' => (string) $i->etichetta, 'interno' => (string) ($i->interno ?? ''), 'nome' => (string) ($i->nome ?? '')];
            }
        }
        $giorni = $this->giorniDiTitolarita($esercizio, array_keys($iids));
        $sigle = ['proprietario' => 'P', 'nuda_proprietario' => 'NP', 'usufruttuario' => 'U', 'inquilino' => 'I'];

        $gestioni = [];
        foreach ($letto['gestioni'] as $gid => $g) {
            $pt = $letto['per_tabella'][$gid] ?? ['righe' => [], 'tabelle' => [], 'millesimi' => [], 'ruoli' => []];
            $tabelle = $pt['tabelle'];
            uasort($tabelle, fn ($a, $b) => strnatcasecmp($a['nome'], $b['nome']));
            $righe = [];
            $totali = array_fill_keys(array_keys($tabelle), 0);
            $totaleVersato = 0;
            $gran = 0;
            foreach ($letto['persone'][$gid] ?? [] as $aid => $perUnita) {
                foreach ($perUnita as $iid => $r) {
                    $celle = [];
                    foreach (array_keys($tabelle) as $tid) {
                        $importo = (int) ($pt['righe'][$aid][$iid][$tid] ?? 0);
                        $celle[$tid] = ['quota' => $pt['millesimi'][$tid][$iid] ?? null, 'importo' => $importo];
                        $totali[$tid] += $importo;
                    }
                    if ((int) $r['a'] === 0 && array_sum(array_column($celle, 'importo')) === 0) {
                        continue;
                    }
                    $ruolo = (string) ($pt['ruoli'][$aid][$iid] ?? 'proprietario');
                    $righe[] = ['immobile_id' => (int) $iid, 'unita' => $unita[$iid]['etichetta'] ?? '', 'anagrafica_id' => (int) $aid, 'nome' => (string) ($nomi[$aid] ?? ''),
                        'ruolo' => $sigle[$ruolo] ?? strtoupper(substr($ruolo, 0, 1)), 'giorni' => $giorni[$aid][$iid] ?? null, 'tabelle' => $celle,
                        'gia_versato' => -(int) $r['gia_versato_unita'], 'totale' => (int) $r['a'], '_ordine' => [$unita[$iid]['interno'] ?? '', $unita[$iid]['nome'] ?? '', $ruolo]];
                    $totaleVersato -= (int) $r['gia_versato_unita'];
                    $gran += (int) $r['a'];
                }
            }
            $ordineRuoli = ['proprietario' => 0, 'nuda_proprietario' => 1, 'usufruttuario' => 2, 'inquilino' => 3];
            usort($righe, fn ($a, $b) => ((($a['_ordine'][0] === '') <=> ($b['_ordine'][0] === '')) ?: strnatcasecmp($a['_ordine'][0], $b['_ordine'][0]))
                ?: strnatcasecmp($a['_ordine'][1], $b['_ordine'][1]) ?: (($ordineRuoli[$a['_ordine'][2]] ?? 9) <=> ($ordineRuoli[$b['_ordine'][2]] ?? 9)) ?: strcmp($a['nome'], $b['nome']));
            foreach ($righe as $i => $r) {
                unset($righe[$i]['_ordine']);
            }
            $gestioni[] = [
                'id' => (int) $gid, 'nome' => (string) $g['nome'], 'tipo' => (string) $g['tipo'], 'non_calcolabile' => $g['non_calcolabile'],
                'tabelle' => array_map(fn ($t) => ['nome' => $t['nome'], 'quota' => $t['quota'], 'somma_valori' => $t['somma_valori']], $tabelle),
                'righe' => $righe, 'totali' => $totali, 'gia_versato' => $totaleVersato, 'totale' => $gran,
                'non_attribuite' => (int) array_sum($g['non_attribuite'] ?? []),
                // Le spese delle righe che il conguaglio per persona non conta (non disponibili): qui ci sono, e la stampa lo dice.
                'a_non_disponibili' => (int) ($g['totali']['a_non_disponibili'] ?? 0),
            ];
        }

        return ['gestioni' => $gestioni, 'data' => self::data($esercizio, $letto)];
    }

    /** Il motivo di una cifra non attribuita, a parole: il più grande, se sono più d'uno; mai un codice. */
    public static function motivo(array $motivi): string
    {
        if ($motivi === []) {
            return self::MOTIVO_DI_RIPIEGO;
        }
        arsort($motivi);

        return self::MOTIVI[(string) array_key_first($motivi)] ?? self::MOTIVO_DI_RIPIEGO;
    }
}
