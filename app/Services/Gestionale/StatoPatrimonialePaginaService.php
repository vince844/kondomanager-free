<?php

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La pagina Stato patrimoniale — punto 3 della sequenza di docs/registri_contabili.md — assemblata
 * in un posto solo, così schermo e stampa leggono la stessa struttura e non possono divergere.
 *
 * Tre sezioni, come il fac-simile e come D16–D20:
 *   3a  la SITUAZIONE PATRIMONIALE alla data — lo stock, voce per voce (StatoPatrimonialeService)
 *   3b  il RIEPILOGO FINANZIARIO dell'esercizio — i flussi per cassa (RiepilogoFinanziarioService)
 *   3c  i CONTROLLI, come equazioni con i numeri veri (D14, D20), ciascuno con un nome, un esito,
 *       e — quando è rosso — un rimedio e il posto dove si fa
 * più la vista «di cui» sulla liquidità (D11) e il risultato di gestione (D18).
 *
 * DUE PERIMETRI, UNO PER LO STOCK E UNO PER I FLUSSI. La fotografia taglia per data (D16); tutto
 * ciò che è «dell'esercizio» — risultato di gestione, raccordo, entrate e uscite per cassa —
 * taglia per `esercizio_id` senza le scritture di apertura, che è il perimetro del Libro Giornale e
 * del consuntivo. La revisione della beta.25 ha trovato che tagliare anche i flussi per data faceva
 * dire alla stessa fattura «costo 2025» qui e «costo 2026» nel giornale, e che l'apertura era
 * «iniziale» nel riepilogo ma «flusso» nel raccordo. Dove i due perimetri divergono — movimenti
 * datati fuori dal periodo del loro esercizio — lo dice il controllo delle registrazioni oltre il
 * confine ({@see AnnoACavallo}); R2 toglie dal suo confronto il denaro che attraversa la data di fine
 * e lo nomina, e resta rosso per tutto il resto (anche per le registrazioni fuori da ogni esercizio,
 * quando prevalgono su quelle nel periodo: lì il programma non sa quale confine sia quello giusto).
 *
 * ⚠️ **Questo servizio non fa somme proprie.** Ogni numero viene da uno dei tre servizi sotto; qui
 * si classifica, si etichetta e si confronta. Un'identità che non torna si espone come controllo
 * rosso, non si lancia: una pagina bianca in produzione è peggio di un numero segnalato.
 */
class StatoPatrimonialePaginaService
{
    /** Quando la liquidità torna con la cassa: il confronto con la banca resta da fare, e il programma non lo fa. */
    public const FRASE_BANCA = 'Il confronto con l\'estratto conto della banca resta un controllo tuo: il programma non può farlo.';

    /** Vincoli e casse fondo, per un esercizio finito: i vincoli dichiarati si leggono a oggi, e il confronto alla data di fine non si fa. */
    public const NOTA_VINCOLI_A_OGGI = 'I vincoli dichiarati si leggono a oggi: questo confronto vale per l\'esercizio aperto, non alla data di un esercizio finito; il residuo vincolato dei fondi si legge con il consuntivo dei fondi (in arrivo).';

    /**
     * Le etichette si prendono dal RUOLO del conto, non dalla categoria (D18): «Gestione Rate» ha
     * categoria `fondi` a database e non è un fondo. Chi non ha un ruolo noto cade nel gruppo della
     * sua natura, col suo nome.
     */
    private const GRUPPI_PER_RUOLO = [
        'crediti_condomini'      => ['gruppo' => 'Crediti verso i condòmini', 'ordine' => 20],
        'iva_acquisti'           => ['gruppo' => 'Altri crediti', 'ordine' => 30],
        'debiti_fornitori'       => ['gruppo' => 'Debiti verso fornitori', 'ordine' => 10],
        'debiti_erario_ritenute' => ['gruppo' => "Debiti verso l'Erario", 'ordine' => 20],
        'anticipi_condomini'     => ['gruppo' => 'Anticipi ricevuti dai condòmini', 'ordine' => 30],
        'gestione_rate'          => ['gruppo' => 'Quote emesse ai condòmini', 'ordine' => 40],
        'passate_gestioni'       => ['gruppo' => 'Riporti da esercizi precedenti', 'ordine' => 50],
    ];

    public function __construct(
        private readonly StatoPatrimonialeService $statoPatrimoniale,
        private readonly RiepilogoFinanziarioService $riepilogo,
        private readonly LiquiditaVincolataService $liquidita,
        private readonly PagatoAllaData $pagato,
        private readonly LetturaDebiti $letturaDebiti,
        private readonly SpesaPerVoceService $spese,
        private readonly AnnoACavallo $annoACavallo,
    ) {}

    public function costruisci(Condominio $condominio, Esercizio $esercizio, ?string $oggi = null): array
    {
        $oggi = $oggi ?? DateHelper::oggiUtente();
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $fine = $esercizio->data_fine->format('Y-m-d');
        $stato = $dal > $oggi ? 'futuro' : ($fine < $oggi ? 'chiuso' : 'aperto');
        $al = $stato === 'chiuso' ? $fine : $oggi;

        $foto = $this->statoPatrimoniale->calcola($condominio, allaData: $al);
        $flussi = $this->flussi($condominio, $esercizio);
        $riepilogo = $this->riepilogo->perEsercizio($esercizio, $oggi);
        $liquidita = $this->liquidita->allaData($condominio, $foto['attivo']['voci'], $foto['liquidita_non_contabilizzata'], $stato);

        $risultatoGestione = $this->risultatoGestione($foto, $flussi);
        $raccordo = $this->raccordo($flussi, $risultatoGestione['risultato']);
        $debiti = $this->debiti($condominio, $foto, $al);
        $oltreIlConfine = $this->annoACavallo->perLaCassa($condominio, $esercizio, $al);
        $controlli = $this->controlli($foto, $riepilogo, $liquidita, $raccordo, $stato, $oltreIlConfine);

        $vociPerRuolo = fn (array $voci, string $ruolo) => (int) collect($voci)->where('ruolo', $ruolo)->sum('saldo');

        return [
            'data' => $al,
            'dal' => $dal,
            'stato_esercizio' => $stato,
            'situazione' => [
                'attivo' => ['gruppi' => $this->raggruppa($foto['attivo']['voci'], 'attivo'), 'totale' => $foto['attivo']['totale']],
                'passivo' => ['gruppi' => $this->raggruppa($foto['passivo']['voci'], 'passivo'), 'totale' => $foto['passivo']['totale']],
                'costi_voci' => $foto['costi_voci'],
                'ricavi_voci' => $foto['ricavi_voci'],
                'liquidita_non_contabilizzata' => $foto['liquidita_non_contabilizzata'],
                'costi' => $foto['costi'],
                'ricavi' => $foto['ricavi'],
                'risultato_motore' => $foto['risultato_esercizio'],
                'sbilancio' => $foto['sbilancio'],
                'quadra' => $foto['quadra'],
            ],
            'sintesi' => [
                'liquidita' => $liquidita['liquidita_totale'],
                'in_fondi' => $liquidita['in_fondi'],
                'crediti_condomini' => $vociPerRuolo($foto['attivo']['voci'], 'crediti_condomini'),
                'debiti_fornitori' => $vociPerRuolo($foto['passivo']['voci'], 'debiti_fornitori'),
                'debiti_erario' => $vociPerRuolo($foto['passivo']['voci'], 'debiti_erario_ritenute'),
                // Dentro i crediti verso i condòmini: le righe del conto senza una persona, cioè le spese anticipate per singole unità e non ancora
                // chieste a nessuno (art. 63), il complemento delle righe che la lettura dei debiti per persona legge.
                'spese_anticipate' => $this->letturaDebiti->senzaPersona((int) $condominio->id, $al)['totale'],
            ],
            'risultato_gestione' => $risultatoGestione,
            'riepilogo' => $riepilogo,
            'liquidita' => $liquidita,
            'raccordo' => $raccordo,
            'debiti' => $debiti,
            'per_gestione' => $this->perGestione($condominio, $esercizio, $al, $risultatoGestione, $foto, $debiti, $vociPerRuolo($foto['attivo']['voci'], 'crediti_condomini')),
            'controlli' => $controlli,
        ];
    }

    /**
     * Il risultato di gestione dell'esercizio, con i suoi due termini letti dal giornale: lo stesso calcolo della pagina (`risultatoGestione`, sulla
     * fotografia e sui flussi dell'esercizio senza le aperture), per chi lo mostra fuori dalla pagina, come il riepilogo per competenza.
     *
     * @return array{quote_emesse:int, costi:int, risultato:int, cumulato:int}
     */
    public function risultatoDi(Condominio $condominio, Esercizio $esercizio, ?string $oggi = null): array
    {
        $oggi = $oggi ?? DateHelper::oggiUtente();
        $fine = $esercizio->data_fine->format('Y-m-d');
        $al = $fine < $oggi ? $fine : $oggi;

        return $this->risultatoGestione($this->statoPatrimoniale->calcola($condominio, allaData: $al), $this->flussi($condominio, $esercizio));
    }

    /** I flussi dell'esercizio: per esercizio_id, senza le aperture. Stesso perimetro del prospetto per cassa e del risultato, da un posto solo. */
    private function flussi(Condominio $condominio, Esercizio $esercizio): array
    {
        return $this->statoPatrimoniale->calcola($condominio, $esercizio, escludiAperture: true);
    }

    /**
     * D18 — il risultato di gestione è `quote emesse − costi`, nell'esercizio: l'avanzo o il
     * disavanzo rispetto a quanto chiesto ai condòmini; che cosa farne lo decide l'assemblea. Il
     * risultato del motore (`ricavi − costi`, con ricavi sempre zero) resta quello che è e serve a R6.
     */
    private function risultatoGestione(array $foto, array $flussi): array
    {
        $quote = $this->saldoPerRuolo($flussi['passivo']['voci'], 'gestione_rate');
        $costi = $flussi['costi'];

        return [
            'quote_emesse' => $quote,
            'costi' => $costi,
            'risultato' => $quote - $costi,
            // Senza chiusura, quote e costi si accumulano dal primo esercizio: il cumulato è ciò che,
            // con la chiusura (v1.17), diventerà una posta. Per il primo esercizio coincide.
            'cumulato' => $this->saldoPerRuolo($foto['passivo']['voci'], 'gestione_rate') - $foto['costi'],
        ];
    }

    /**
     * D20/R7 — il raccordo cassa ↔ competenza, derivato dall'identità dare = avere delle scritture
     * dell'esercizio (stesso perimetro del riepilogo):
     *
     *   Δ liquidità = risultato di gestione − Δ crediti + Δ debiti + Δ riporti ± altre poste
     *
     * Dice perché la cassa si è mossa diversamente dal risultato: quote non ancora incassate,
     * spese non ancora pagate. Ogni riga ha il nome della sua posta, non un nome generico.
     */
    private function raccordo(array $flussi, int $risultatoGestione): array
    {
        $att = $flussi['attivo']['voci'];
        $pas = $flussi['passivo']['voci'];
        $ruolo = fn (array $voci, string $r) => $this->saldoPerRuolo($voci, $r);

        $liquidita = $this->sommaSe($att, fn ($v) => $v['categoria'] === 'liquidita');
        $creditiCondomini = $ruolo($att, 'crediti_condomini');
        $altriAttivi = $this->sommaSe($att, fn ($v) => $v['categoria'] !== 'liquidita' && $v['ruolo'] !== 'crediti_condomini');
        $debitiFornitori = $ruolo($pas, 'debiti_fornitori');
        $debitiErario = $ruolo($pas, 'debiti_erario_ritenute');
        $anticipi = $ruolo($pas, 'anticipi_condomini');
        $riporti = $ruolo($pas, 'passate_gestioni');
        $altriPassivi = $this->sommaSe($pas, fn ($v) => ! in_array($v['ruolo'], ['gestione_rate', 'passate_gestioni', 'debiti_fornitori', 'debiti_erario_ritenute', 'anticipi_condomini'], true));

        $righe = [
            ['voce' => 'Risultato di gestione dell\'esercizio', 'natura' => 'quote emesse − costi', 'effetto' => $risultatoGestione],
            ['voce' => 'Quote emesse e non ancora incassate', 'natura' => 'aumento dei crediti verso i condòmini', 'effetto' => -$creditiCondomini],
            ['voce' => 'Fatture registrate e non ancora pagate', 'natura' => 'aumento dei debiti verso fornitori', 'effetto' => $debitiFornitori],
        ];
        $facoltative = [
            ['voce' => 'Ritenute maturate e non ancora versate', 'natura' => "aumento dei debiti verso l'Erario", 'effetto' => $debitiErario],
            ['voce' => 'Anticipi ricevuti dai condòmini', 'natura' => 'incassati prima dell\'emissione', 'effetto' => $anticipi],
            ['voce' => 'Riporti da esercizi precedenti', 'natura' => 'pregresso portato a giornale', 'effetto' => $riporti],
            ['voce' => 'Altri crediti', 'natura' => 'IVA e crediti verso terzi', 'effetto' => -$altriAttivi],
            ['voce' => 'Altre passività', 'natura' => 'poste senza un ruolo dichiarato', 'effetto' => $altriPassivi],
            ['voce' => 'Ricavi contabilizzati', 'natura' => 'conti di tipo ricavo', 'effetto' => $flussi['ricavi']],
        ];
        foreach ($facoltative as $r) {
            if ($r['effetto'] !== 0) {
                $righe[] = $r;
            }
        }

        $somma = array_sum(array_column($righe, 'effetto'));

        return [
            'righe' => $righe,
            'somma' => $somma,
            'variazione_liquidita' => $liquidita,
            'scarto' => $liquidita - $somma,
            'quadra' => $liquidita === $somma,
        ];
    }

    /**
     * «A chi devo soldi» alla data della pagina: le fatture aperte alla data, per l'esercizio aperto (oggi) e per quello finito (la data di fine), di
     * qualunque esercizio. È la funzione del pagato alla data del riepilogo ({@see PagatoAllaData}) con il perimetro di tutte le fatture aperte: il
     * residuo verso il fornitore è il netto a pagare (al netto della ritenuta, che sta sull'Erario dalla registrazione) meno i pagamenti e le
     * compensazioni datati entro la data. Una fattura stornata entro la data e la sua nota si annullano e non si elencano.
     *
     * La riga «fatture aperte + differenza = debiti verso fornitori a giornale», con i nomi della differenza: un pagamento oltre il netto di una
     * fattura (il «pagato in più»), una nota di credito usata oltre il suo importo, e le registrazioni sui debiti verso fornitori che non sono di una
     * fattura aperta. Sotto l'Erario, le ritenute delle fatture non ancora pagate (la ritenuta della parte ancora aperta di ogni documento, con il
     * segno: una nota di credito aperta con la sua ritenuta la toglie): fino al pagamento sono un debito verso il fornitore. I debiti per gestione
     * seguono la scrittura di competenza della fattura (la testata).
     */
    private function debiti(Condominio $condominio, array $foto, string $al): array
    {
        $saldoFornitori = $this->saldoPerRuolo($foto['passivo']['voci'], 'debiti_fornitori');
        $saldoErario = $this->saldoPerRuolo($foto['passivo']['voci'], 'debiti_erario_ritenute');
        $tutte = $this->pagato->perFatture((int) $condominio->id, $al, null, true);

        // Una nota nata da uno storno annulla la sua fattura: la coppia, entro la data, non è un debito né un credito da elencare. Fino alla
        // beta.20 la nota restava fra le aperte e la fattura no, con una «differenza» col conto che non esiste («Via delle Acacie», 12/09/2026).
        $fuori = [];
        $note = array_keys(array_filter($tutte, fn ($f) => $f['nota_credito']));
        if ($note !== []) {
            $nateDaStorno = FatturaPassiva::whereIn('id', $note)->get()->filter(fn ($f) => $f->eNataDaStorno())->pluck('id')->map(fn ($id) => (int) $id)->all();
            $fuori = array_fill_keys($nateDaStorno, true);
            $stornate = array_keys(array_filter($tutte, fn ($f) => $f['stato_pagamento'] === 'stornata'));
            foreach ($stornate === [] ? [] : FatturaPassiva::whereIn('id', $stornate)->get(['id', 'dati_extra']) as $f) {
                if (isset($fuori[(int) ($f->dati_extra['stornata_da_id'] ?? 0)])) {
                    $fuori[(int) $f->id] = true;
                }
            }
        }

        $fatture = [];
        $pagatoInPiu = [];
        $noteOltre = [];
        $suNonPagate = 0;
        $perGestione = [];
        foreach ($tutte as $id => $f) {
            if (isset($fuori[$id])) {
                continue;
            }
            $suNonPagate += $f['ritenuta_del_residuo'];
            if ($f['residuo'] < 0) {
                $f['nota_credito'] ? $noteOltre[] = $f : $pagatoInPiu[] = $f;

                continue;
            }
            $fatture[] = [
                'id' => $id,
                'fornitore' => $f['fornitore'] ?? '—',
                'numero' => $f['numero'],
                'data' => $f['documento'],
                'scadenza' => $f['scadenza'],
                'residuo' => $f['residuo'],
                'nota_credito' => $f['nota_credito'],
                'contestata' => $f['contestata'],
                'pregressa' => $f['pregressa'],
                'esercizio_id' => $f['esercizio_id'],
                'gestione_id' => $f['gestione_id'],
            ];
            $perGestione[(int) $f['gestione_id']] = ($perGestione[(int) $f['gestione_id']] ?? 0) + ($f['nota_credito'] ? -$f['residuo'] : $f['residuo']);
        }
        usort($fatture, fn ($a, $b) => [$a['scadenza'] ?? '9999-12-31', $a['numero'], $a['id']] <=> [$b['scadenza'] ?? '9999-12-31', $b['numero'], $b['id']]);

        $totaleResidui = (int) array_sum(array_map(fn ($f) => $f['nota_credito'] ? -$f['residuo'] : $f['residuo'], $fatture));
        $scarto = $saldoFornitori - $totaleResidui;
        $voci = [];
        if ($pagatoInPiu !== []) {
            $voci[] = ['voce' => 'pagato in più a un fornitore', 'importo' => (int) array_sum(array_column($pagatoInPiu, 'residuo')), 'fatture' => array_column($pagatoInPiu, 'numero')];
        }
        if ($noteOltre !== []) {
            $voci[] = ['voce' => 'note di credito usate oltre il loro importo', 'importo' => -(int) array_sum(array_column($noteOltre, 'residuo')), 'fatture' => array_column($noteOltre, 'numero')];
        }
        $resto = $scarto - (int) array_sum(array_column($voci, 'importo'));
        if ($resto !== 0) {
            $voci[] = ['voce' => 'registrazioni sui debiti verso fornitori che non sono di una fattura aperta alla data', 'importo' => $resto, 'fatture' => []];
        }

        return [
            'alla' => $al,
            'fatture' => $fatture,
            'totale_residui' => $totaleResidui,
            'saldo_fornitori' => $saldoFornitori,
            'saldo_erario' => $saldoErario,
            'erario_su_fatture_non_pagate' => $suNonPagate,
            'scarto' => $scarto,
            'scarto_voci' => $voci,
            'per_gestione' => $perGestione,
        ];
    }

    /**
     * Un blocco per gestione, con il totale del condominio in fondo: i costi dell'esercizio per la gestione della voce, le quote emesse
     * nell'esercizio, i crediti verso i condòmini e i debiti verso fornitori alla data. Per una gestione straordinaria il cumulato dell'opera dal suo
     * inizio, accanto alla parte dell'esercizio. Una fattura con voci di un'altra gestione ha il costo nel blocco della voce e il debito nel blocco
     * della testata: le fatture così si dicono per numero. La liquidità e i fondi non si dividono.
     *
     * @param  array{quote_emesse:int, costi:int}  $risultato
     */
    private function perGestione(Condominio $condominio, Esercizio $esercizio, string $al, array $risultato, array $foto, array $debiti, int $crediti): array
    {
        $somme = $this->statoPatrimoniale->perGestione($condominio, $esercizio, $al);
        $collegate = DB::table('esercizio_gestione')->where('esercizio_id', $esercizio->id)->pluck('gestione_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique(array_filter([...$collegate, ...array_keys($somme['costi']), ...array_keys($somme['quote_emesse']),
            ...array_keys($somme['crediti']), ...array_keys($debiti['per_gestione'])])));
        sort($ids);
        $gestioni = DB::table('gestioni')->whereIn('id', $ids ?: [0])->get(['id', 'nome', 'tipo'])->keyBy('id');
        $dal = $esercizio->data_inizio->format('Y-m-d');

        $righe = [];
        foreach ($ids as $id) {
            $g = $gestioni->get($id);
            if ($g === null) {
                continue;
            }
            $righe[] = [
                'gestione' => ['id' => $id, 'nome' => $g->nome, 'tipo' => $g->tipo],
                'costi' => $somme['costi'][$id] ?? 0,
                'quote_emesse' => $somme['quote_emesse'][$id] ?? 0,
                'crediti' => $somme['crediti'][$id] ?? 0,
                'debiti' => $debiti['per_gestione'][$id] ?? 0,
                'cumulato_opera' => $g->tipo === 'straordinaria' ? $this->spese->cumulatoDellaGestione($id, $dal) : null,
            ];
        }

        // Le fatture dell'esercizio con una voce di un'altra gestione rispetto alla testata della loro scrittura di competenza.
        $altraGestione = DB::table('fattura_scrittura as fs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('sc.id', '=', 'fs.scrittura_contabile_id')->whereNull('sc.deleted_at'))
            ->join('fatture_passive as fp', 'fp.id', '=', 'fs.fattura_passiva_id')
            ->join('righe_scritture as rs', 'rs.scrittura_id', '=', 'sc.id')
            ->join('conti as v', 'v.id', '=', 'rs.voce_spesa_id')
            ->join('piani_conti as pc', 'pc.id', '=', 'v.piano_conto_id')
            ->where('fs.tipo', 'competenza')->where('sc.esercizio_id', $esercizio->id)
            ->whereColumn('pc.gestione_id', '<>', 'sc.gestione_id')
            ->distinct()->orderBy('fp.numero_documento')->pluck('fp.numero_documento')->map(fn ($n) => (string) $n)->all();

        return [
            'righe' => $righe,
            'senza_voce' => $somme['costi'][0] ?? 0,
            'senza_gestione' => ['quote_emesse' => $somme['quote_emesse'][0] ?? 0, 'crediti' => $somme['crediti'][0] ?? 0, 'debiti' => $debiti['per_gestione'][0] ?? 0],
            'differenza_debiti' => $debiti['scarto'],
            'totale' => ['costi' => $risultato['costi'], 'quote_emesse' => $risultato['quote_emesse'], 'crediti' => $crediti, 'debiti' => $debiti['saldo_fornitori']],
            'fatture_altra_gestione' => $altraGestione,
            'nota_altra_gestione' => $altraGestione === [] ? null
                : (count($altraGestione) === 1 ? 'Una fattura ha' : count($altraGestione).' fatture hanno').' voci di un\'altra gestione ('.implode(', ', $altraGestione).'): il costo sta nel blocco della voce, il debito e il pagato nel blocco della gestione della fattura.',
            'nota_liquidita' => 'La liquidità e i fondi non si dividono per gestione: la liquidità è del condominio.',
        ];
    }

    /**
     * I controlli 3c: nome, equazione con i numeri veri, esito, e — se rosso — rimedio e dove si fa.
     *
     * @param  array{dopo_la_fine: array{netto:int, lordo:int, numero:int}, di_un_esercizio_dopo: array{netto:int, lordo:int, numero:int}, fuori_da_ogni_esercizio: array{fuori:int, nel_periodo:int, prevale:bool}}  $oltre
     */
    private function controlli(array $foto, array $riepilogo, array $liquidita, array $raccordo, string $stato, array $oltre): array
    {
        $ricavi = $foto['ricavi'];
        $lnc = $foto['liquidita_non_contabilizzata'];

        // R2: la liquidità della fotografia (conti + saldi non registrati, per data) contro i finali del
        // prospetto per cassa (per esercizio). Le righe di cassa che attraversano la data di fine stanno in
        // una sola delle due letture: si tolgono dal confronto e si nominano, perché il controllo delle
        // registrazioni oltre il confine le elenca. Resta rosso per tutto il resto: un conto di liquidità
        // senza una cassa, una scrittura di cassa che non torna, le registrazioni fuori da ogni esercizio quando
        // prevalgono su quelle nel periodo (le date dell'esercizio sbagliate, o un esercizio che non c'è).
        $liquiditaFoto = $liquidita['liquidita_totale'];
        $finali = $riepilogo['totale']['finale'];
        $finaliAlGiorno = $finali - $oltre['dopo_la_fine']['netto'] + $oltre['di_un_esercizio_dopo']['netto'];
        $r2Torna = $liquiditaFoto === $finaliAlGiorno;
        $contiSenzaCassa = collect($liquidita['altra_voci'])->filter(fn ($v) => str_contains($v['voce'], 'senza cassa'))->sum('saldo');
        $fuoriDaOgniEsercizio = $oltre['fuori_da_ogni_esercizio']['prevale'];
        $lordiOltre = array_values(array_filter([$oltre['dopo_la_fine']['lordo'], $oltre['di_un_esercizio_dopo']['lordo']]));

        $negative = $riepilogo['casse_reali_negative'];
        $cnSenzaApertura = $negative !== [] && ($negative[0]['iniziale'] ?? 0) === 0;
        $fmt = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
        $dataIt = fn (?string $d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';
        // Il denaro oltre il confine, detto per quello che è: le scritture di questo esercizio con la data dopo la fine sono nella disponibilità finale, quelle
        // di un esercizio dopo con la data di questo no. Gli importi sono lordi, entrate e uscite insieme.
        $oltreNelProspetto = array_values(array_filter([
            $oltre['dopo_la_fine']['lordo'] !== 0 ? 'comprende '.$fmt($oltre['dopo_la_fine']['lordo']).' di registrazioni con la data dopo la fine' : null,
            $oltre['di_un_esercizio_dopo']['lordo'] !== 0 ? 'non comprende '.$fmt($oltre['di_un_esercizio_dopo']['lordo']).' di registrazioni scritte in un esercizio dopo con la data di questo' : null,
        ]));

        // PD: la partita doppia in sé — Σ dare = Σ avere su tutte le scritture dentro la fotografia.
        // È il numero che l'amministratore riconosce dal Libro Giornale («se lì torna, perché qui
        // non lo vedo?» — Vincenzo al test reale). Non è ridondante con R6: R6 somma anche i saldi
        // in colonna non ancora a giornale, PD no. PD rosso = una scrittura rotta; PD verde e R6
        // rosso = un'apertura non registrata. Le voci del motore portano dare e avere per conto.
        $tutteLeVoci = array_merge($foto['attivo']['voci'], $foto['passivo']['voci'], $foto['costi_voci'], $foto['ricavi_voci']);
        $dare = (int) array_sum(array_column($tutteLeVoci, 'dare'));
        $avere = (int) array_sum(array_column($tutteLeVoci, 'avere'));

        return [
            [
                'id' => 'PD',
                'nome' => 'Partita doppia',
                'titolo' => 'Totale dare = totale avere, su tutte le scritture fino alla data',
                'sinistra' => $dare,
                'destra' => $avere,
                'esito' => $dare === $avere ? 'quadra' : 'non_quadra',
                'nota' => $dare === $avere
                    ? 'Ogni scrittura ha dare uguale ad avere: è la base di tutto il resto, ed è lo stesso totale del Libro Giornale. È la somma dei movimenti, non dei saldi: per questo è più grande della quadratura patrimoniale qui sotto, che somma i saldi dei conti.'
                    : 'Almeno una scrittura non è bilanciata al suo interno: differenza '.$fmt(abs($dare - $avere)).'.',
                'rimedio' => $dare === $avere ? null : 'Nel Libro Giornale la diagnosi dello sbilancio elenca le scritture non bilanciate, con il link a ciascuna.',
                'azione' => $dare === $avere ? null : 'libro-giornale',
            ],
            [
                'id' => 'R6',
                'nome' => 'Quadratura patrimoniale',
                'titolo' => $ricavi !== 0 ? 'Attività + Costi = Passività + Ricavi' : 'Attività + Costi = Passività',
                'sinistra' => $foto['attivo']['totale'] + $foto['costi'],
                'destra' => $foto['passivo']['totale'] + $ricavi,
                'esito' => $foto['quadra'] ? 'quadra' : 'non_quadra',
                'nota' => $foto['quadra']
                    ? 'Ogni valore in questi conti ha una contropartita.'
                    : ($lnc !== 0 && $lnc === $foto['sbilancio']
                        ? 'Lo sbilancio è esattamente la somma dei saldi di apertura non ancora registrati a giornale.'
                        : ($lnc !== 0
                            ? 'Una parte dello sbilancio sono saldi di apertura non registrati ('.$fmt($lnc).'); il resto è valore senza contropartita.'
                            : 'C\'è valore senza contropartita: una scrittura non quadra, o un saldo è entrato senza la sua contropartita.')),
                'rimedio' => $foto['quadra'] ? null
                    : ($lnc !== 0 && $lnc === $foto['sbilancio']
                        ? 'Registra a giornale i saldi di apertura delle casse: il Libro Giornale ha il pulsante che lo fa.'
                        : 'Apri la diagnosi dello sbilancio nel Libro Giornale: dice quale scrittura o quale saldo non torna.'),
                'azione' => $foto['quadra'] ? null : 'libro-giornale',
            ],
            [
                'id' => 'R7',
                'nome' => 'Raccordo fra cassa e competenza',
                'titolo' => 'Variazione della liquidità = risultato di gestione ± partite del raccordo',
                'sinistra' => $raccordo['variazione_liquidita'],
                'destra' => $raccordo['somma'],
                'esito' => $raccordo['quadra'] ? 'quadra' : 'non_quadra',
                'nota' => $raccordo['quadra']
                    ? 'La cassa si è mossa esattamente di quanto spiegano risultato, crediti e debiti.'
                    : 'Una scrittura dell\'esercizio non quadra (dare diverso da avere), oppure un conto ha una natura diversa da quella dichiarata.',
                'rimedio' => $raccordo['quadra'] ? null : 'Apri la diagnosi dello sbilancio nel Libro Giornale: trova la scrittura che non quadra.',
                'azione' => $raccordo['quadra'] ? null : 'libro-giornale',
            ],
            [
                'id' => 'R8',
                'nome' => 'Vincoli e casse fondo',
                // La forma è quella del fac-simile: «residuo vincolato ≤ liquidità disponibile». Non è
                // un'uguaglianza, e un fondo con più di quanto gli è vincolato non è un'anomalia: il
                // segno fra i due numeri è quello vero (`segno`), l'esito invece è per fondo — un
                // totale che copre non assolve il singolo fondo scoperto, e la nota lo dice.
                'titolo' => 'Vincoli dichiarati ≤ liquidità accantonata nei fondi',
                'sinistra' => $liquidita['vincolo_registrato'],
                'destra' => $liquidita['in_fondi'],
                'segno' => $liquidita['vincolo_registrato'] <= $liquidita['in_fondi'] ? '≤' : '>',
                'esito' => match ($liquidita['esito_r8']) { 'quadra' => 'quadra', 'scoperto' => 'non_quadra', default => 'segnalazione' },
                'nota' => match ($liquidita['esito_r8']) {
                    'scoperto' => 'Un fondo ha meno di quanto gli è stato assegnato come vincolo: mancano '.$fmt($liquidita['scoperto_fondi']).'.',
                    'non_accantonato' => $fmt($liquidita['vincolo_non_accantonato']).' dichiarati vincolati stanno nella liquidità libera, non in un fondo: il vincolo esiste, l\'accantonamento no.',
                    'segnalazione' => self::NOTA_VINCOLI_A_OGGI,
                    default => $liquidita['vincolo_registrato'] === 0
                        ? 'Nessun contributo è dichiarato vincolato: i fondi tengono '.$fmt($liquidita['in_fondi']).' senza vincoli da coprire.'
                        : 'Ogni euro dichiarato vincolato ha una cassa fondo che lo tiene.',
                },
                'rimedio' => match ($liquidita['esito_r8']) {
                    'scoperto' => 'Accantona nel fondo con un giroconto dalla banca, o correggi il vincolo dichiarato nei contributi versati.',
                    'non_accantonato' => 'Se vuoi che il vincolo sia anche nei conti, accantona in una cassa fondo con un giroconto.',
                    default => null,
                },
                'azione' => in_array($liquidita['esito_r8'], ['scoperto', 'non_accantonato'], true) ? 'giroconti' : null,
                // Il perché in poche parole, accanto all'esito nel frontespizio del fascicolo.
                'breve' => $liquidita['esito_r8'] === 'segnalazione' ? 'per un esercizio finito il confronto non si fa' : null,
            ],
            [
                'id' => 'R2',
                'nome' => 'Liquidità e cassa',
                'titolo' => $lordiOltre === []
                    ? 'Liquidità della situazione = disponibilità finale delle entrate e uscite per cassa'
                    : 'Liquidità della situazione = disponibilità finale delle entrate e uscite per cassa, con le registrazioni oltre il confine al giorno della loro data',
                'sinistra' => $liquiditaFoto,
                'destra' => $finaliAlGiorno,
                'finale_riepilogo' => $finali,
                'esito' => $r2Torna ? 'quadra' : 'non_quadra',
                'nota' => trim(($oltreNelProspetto === [] ? '' : 'La disponibilità finale delle entrate e uscite per cassa è '.$fmt($finali).'; '.implode(' e ', $oltreNelProspetto)
                        .', fra entrate e uscite: vedi il controllo «Registrazioni oltre il confine dell\'esercizio». ')
                    .($r2Torna
                        ? self::FRASE_BANCA
                        : ($contiSenzaCassa !== 0
                            ? 'Un conto di liquidità ha movimenti ma nessuna cassa che lo rappresenti: le entrate e uscite per cassa non lo vedono.'
                            : ($fuoriDaOgniEsercizio
                                ? 'La liquidità della situazione (per data) e la disponibilità finale delle entrate e uscite per cassa (per esercizio) non contano le stesse righe di '
                                    .'cassa: vedi il controllo «Registrazioni oltre il confine dell\'esercizio». '.ControlliDelRendiconto::fraseFuoriDaOgniEsercizio($oltre['fuori_da_ogni_esercizio'])
                                : 'La liquidità della situazione (per data) e la disponibilità finale delle entrate e uscite per cassa (per esercizio) non contano le stesse righe di cassa, e la differenza non viene dalle registrazioni oltre il confine: una scrittura di cassa non torna, o una registrazione di un esercizio successivo ha una data che non è dopo la fine di questo (un anno scritto male).')))),
                'rimedio' => $r2Torna ? null
                    : ($contiSenzaCassa !== 0
                        ? 'Crea o riassocia la cassa a quel conto nella pagina Casse.'
                        : ($fuoriDaOgniEsercizio
                            ? AnnoACavallo::RIMEDIO_FUORI
                            : 'Apri il Libro Giornale: la diagnosi dello sbilancio trova una scrittura che non quadra, e il registro di contabilità una riga di cassa senza la sua cassa. Una registrazione di un esercizio successivo con la data sbagliata la trovi nel Libro Giornale di quell\'esercizio, cercandola per data.')),
                'azione' => $r2Torna ? null : ($contiSenzaCassa !== 0 ? 'casse' : ($fuoriDaOgniEsercizio ? 'esercizio' : 'libro-giornale')),
            ],
            [
                'id' => 'CN',
                'nome' => 'Casse reali sotto zero',
                'titolo' => 'Nessuna banca o cassa contanti sotto zero, in nessun giorno dell\'esercizio',
                'sinistra' => count($negative),
                'destra' => 0,
                'esito' => $negative === [] ? 'quadra' : 'non_quadra',
                'nota' => $negative === []
                    ? 'Banca e contanti non sono mai scesi sotto zero, giorno per giorno.'
                    : implode('; ', array_map(fn ($n) => $n['cassa'].' a '.$fmt($n['minimo']).($n['data'] ? ' il '.$dataIt($n['data']) : ''), $negative)).'. '
                        // La diagnosi più precisa che i dati permettono («Via delle Acacie», 12/09/2026):
                        // senza apertura e senza incassi non c'è un movimento da cercare nel registro di contabilità.
                        . ($cnSenzaApertura
                            ? ($negative[0]['entrate'] === 0
                                ? 'Questa cassa non ha un saldo di apertura e nessun incasso nell\'esercizio: i pagamenti sono usciti da un conto che, per il programma, era vuoto.'
                                : 'Questa cassa non ha un saldo di apertura: i pagamenti sono usciti prima che gli incassi registrati bastassero.')
                            : 'Un conto reale non può contenere meno di niente: manca un\'entrata, o un movimento è sulla cassa sbagliata.'),
                'rimedio' => $negative === [] ? null
                    : ($cnSenzaApertura
                        ? 'Se a inizio esercizio c\'erano soldi sul conto, registra il saldo iniziale della cassa: è quello che manca. Se i condòmini hanno versato, registra gli incassi delle rate. Se il conto era davvero a zero e nessuno ha versato, quel pagamento non può essere uscito da qui: nel registro di contabilità spostalo sulla cassa giusta.'
                        : 'Apri il registro di contabilità su quella cassa e cerca il movimento intorno a quella data: registra l\'entrata mancante o spostalo sulla cassa giusta.'),
                'azione' => $negative === [] ? null : ($cnSenzaApertura ? 'cassa' : 'prima-nota'),
                'azione_parametro' => $negative === [] ? null : ($cnSenzaApertura ? (string) $negative[0]['cassa_id'] : $negative[0]['cassa']),
            ],
        ];
    }

    private function raggruppa(array $voci, string $lato): array
    {
        $gruppi = [];
        foreach ($voci as $v) {
            if ($lato === 'attivo' && $v['categoria'] === 'liquidita') {
                $chiave = 'Liquidità'; $ordine = 10;
            } elseif (isset(self::GRUPPI_PER_RUOLO[$v['ruolo']])) {
                $chiave = self::GRUPPI_PER_RUOLO[$v['ruolo']]['gruppo']; $ordine = self::GRUPPI_PER_RUOLO[$v['ruolo']]['ordine'];
            } else {
                $chiave = $lato === 'attivo' ? 'Altre attività' : 'Altre passività'; $ordine = 90;
            }
            $gruppi[$chiave] ??= ['gruppo' => $chiave, 'ordine' => $ordine, 'voci' => [], 'totale' => 0];
            $gruppi[$chiave]['voci'][] = $v;
            $gruppi[$chiave]['totale'] += $v['saldo'];
        }
        usort($gruppi, fn ($a, $b) => $a['ordine'] <=> $b['ordine']);

        return array_values($gruppi);
    }

    private function saldoPerRuolo(array $voci, string $ruolo): int
    {
        return $this->sommaSe($voci, fn ($v) => $v['ruolo'] === $ruolo);
    }

    private function sommaSe(array $voci, callable $cond): int
    {
        $s = 0;
        foreach ($voci as $v) {
            if ($cond($v)) {
                $s += (int) $v['saldo'];
            }
        }

        return $s;
    }
}
