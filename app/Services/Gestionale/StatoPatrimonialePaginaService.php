<?php

namespace App\Services\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\FatturaPassiva;
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
 * ciò che è «dell'esercizio» — risultato di gestione, raccordo, entrate e uscite del riepilogo —
 * taglia per `esercizio_id` senza le scritture di apertura, che è il perimetro del Libro Giornale e
 * del consuntivo. La revisione della beta.25 ha trovato che tagliare anche i flussi per data faceva
 * dire alla stessa fattura «costo 2025» qui e «costo 2026» nel giornale, e che l'apertura era
 * «iniziale» nel riepilogo ma «flusso» nel raccordo. Dove i due perimetri divergono — movimenti
 * datati fuori dal periodo del loro esercizio — è R2 a dirlo.
 *
 * ⚠️ **Questo servizio non fa somme proprie.** Ogni numero viene da uno dei tre servizi sotto; qui
 * si classifica, si etichetta e si confronta. Un'identità che non torna si espone come controllo
 * rosso, non si lancia: una pagina bianca in produzione è peggio di un numero segnalato.
 */
class StatoPatrimonialePaginaService
{
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
    ) {}

    public function costruisci(Condominio $condominio, Esercizio $esercizio, ?string $oggi = null): array
    {
        $oggi = $oggi ?? DateHelper::oggiUtente();
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $fine = $esercizio->data_fine->format('Y-m-d');
        $stato = $dal > $oggi ? 'futuro' : ($fine < $oggi ? 'chiuso' : 'aperto');
        $al = $stato === 'chiuso' ? $fine : $oggi;

        $foto = $this->statoPatrimoniale->calcola($condominio, allaData: $al);
        // I flussi dell'esercizio: per esercizio_id, senza le aperture. Stesso perimetro del riepilogo.
        $flussi = $this->statoPatrimoniale->calcola($condominio, $esercizio, escludiAperture: true);
        $riepilogo = $this->riepilogo->perEsercizio($esercizio, $oggi);
        $liquidita = $this->liquidita->allaData($condominio, $foto['attivo']['voci'], $foto['liquidita_non_contabilizzata'], $stato);

        $risultatoGestione = $this->risultatoGestione($foto, $flussi);
        $raccordo = $this->raccordo($flussi, $risultatoGestione['risultato']);
        $debiti = $this->debiti($condominio, $foto, $stato);
        $fuoriPeriodo = $this->scrittureFuoriPeriodo($esercizio);
        $controlli = $this->controlli($foto, $riepilogo, $liquidita, $raccordo, $stato, $fuoriPeriodo);

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
            ],
            'risultato_gestione' => $risultatoGestione,
            'riepilogo' => $riepilogo,
            'liquidita' => $liquidita,
            'raccordo' => $raccordo,
            'debiti' => $debiti,
            'controlli' => $controlli,
        ];
    }

    /**
     * D18 — il risultato di gestione è `quote emesse − costi`, nell'esercizio: l'avanzo o il
     * disavanzo rispetto a quanto chiesto ai condòmini, cioè ciò che andrà a conguaglio. Il
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
     * «A chi devo soldi»: le fatture ancora da pagare, con il residuo di ciascuna e il confronto col
     * saldo del conto «Debiti verso fornitori». Il residuo di una fattura è quello di OGGI, non
     * datato: per un esercizio chiuso l'elenco non può dire quali fatture erano aperte al 31/12, e
     * la pagina lo dichiara mostrando solo il totale.
     */
    private function debiti(Condominio $condominio, array $foto, string $stato): array
    {
        $saldoFornitori = $this->saldoPerRuolo($foto['passivo']['voci'], 'debiti_fornitori');
        $saldoErario = $this->saldoPerRuolo($foto['passivo']['voci'], 'debiti_erario_ritenute');

        if ($stato === 'chiuso') {
            return ['disponibile' => false, 'fatture' => [], 'totale_residui' => 0, 'saldo_fornitori' => $saldoFornitori, 'saldo_erario' => $saldoErario, 'scarto' => 0];
        }

        $fatture = FatturaPassiva::query()
            ->where('condominio_id', $condominio->id)
            ->conResiduo()
            ->with('fornitore:id,ragione_sociale')
            ->orderBy('data_scadenza')
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'fornitore' => $f->fornitore?->ragione_sociale ?? '—',
                'numero' => $f->numero_documento,
                'data' => $f->data_documento?->format('Y-m-d'),
                'scadenza' => $f->data_scadenza?->format('Y-m-d'),
                'residuo' => (int) $f->residuo,
                'nota_credito' => $f->netto_a_pagare < 0,
            ])
            ->filter(fn ($f) => $f['residuo'] !== 0)
            ->values()
            ->all();

        $totaleResidui = (int) array_sum(array_map(fn ($f) => $f['nota_credito'] ? -$f['residuo'] : $f['residuo'], $fatture));

        return [
            'disponibile' => true,
            'fatture' => $fatture,
            'totale_residui' => $totaleResidui,
            'saldo_fornitori' => $saldoFornitori,
            'saldo_erario' => $saldoErario,
            // Se il conto dice più delle fatture aperte, c'è un debito registrato senza una fattura
            // aperta (un pregresso, una fattura contestata); se dice meno, una fattura è a giornale
            // solo in parte. In entrambi i casi il numero va mostrato, non nascosto.
            'scarto' => $saldoFornitori - $totaleResidui,
        ];
    }

    /** I controlli 3c: nome, equazione con i numeri veri, esito, e — se rosso — rimedio e dove si fa. */
    /**
     * Quante scritture dell'esercizio hanno una data fuori dal suo periodo, e quante sono in tutto.
     * Serve a R2 per dire la cosa giusta: dodici scritture fuori su dodici non sono dodici errori di
     * data, sono le date dell'esercizio sbagliate («Via delle Acacie», 12/09/2026: esercizio «2025»
     * con tutte le operazioni del 2026).
     *
     * @return array{fuori:int, totali:int}
     */
    private function scrittureFuoriPeriodo(Esercizio $esercizio): array
    {
        $dal = $esercizio->data_inizio->format('Y-m-d');
        $dopo = $esercizio->data_fine->copy()->addDay()->format('Y-m-d');
        $base = DB::table('scritture_contabili')->where('esercizio_id', $esercizio->id)->whereNull('deleted_at')
            ->where('tipo_movimento', '<>', 'apertura');

        return [
            'fuori' => (clone $base)->where(fn ($q) => $q->where('data_competenza', '<', $dal)->orWhere('data_competenza', '>=', $dopo))->count(),
            'totali' => (clone $base)->count(),
        ];
    }

    private function controlli(array $foto, array $riepilogo, array $liquidita, array $raccordo, string $stato, array $fuoriPeriodo = ['fuori' => 0, 'totali' => 0]): array
    {
        $ricavi = $foto['ricavi'];
        $lnc = $foto['liquidita_non_contabilizzata'];

        // R2: la liquidità della fotografia (conti + saldi non registrati) contro i finali del
        // riepilogo, che tagliano i flussi per esercizio. Divergono se un movimento è datato fuori
        // dal periodo del suo esercizio, o se un conto di liquidità non ha una cassa.
        $liquiditaFoto = $liquidita['liquidita_totale'];
        $finali = $riepilogo['totale']['finale'];
        $contiSenzaCassa = collect($liquidita['altra_voci'])->filter(fn ($v) => str_contains($v['voce'], 'senza cassa'))->sum('saldo');

        // Più della metà delle scritture fuori dal periodo: il sospetto cade sull'esercizio, non su di loro.
        $dateEsercizioSbagliate = $fuoriPeriodo['totali'] > 0 && $fuoriPeriodo['fuori'] * 2 > $fuoriPeriodo['totali'];

        $negative = $riepilogo['casse_reali_negative'];
        $cnSenzaApertura = $negative !== [] && ($negative[0]['iniziale'] ?? 0) === 0;
        $fmt = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
        $dataIt = fn (?string $d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';

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
                    'segnalazione' => 'I vincoli dichiarati non hanno una data: questo controllo vale per l\'esercizio aperto, non per uno chiuso.',
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
            ],
            [
                'id' => 'R2',
                'nome' => 'Liquidità e riepilogo',
                'titolo' => 'Liquidità della situazione = disponibilità finale del riepilogo',
                'sinistra' => $liquiditaFoto,
                'destra' => $finali,
                'esito' => $liquiditaFoto === $finali ? 'quadra' : 'non_quadra',
                'nota' => $liquiditaFoto === $finali
                    ? 'Il confronto con l\'estratto conto della banca resta un controllo tuo: il programma non può farlo.'
                    : ($contiSenzaCassa !== 0
                        ? 'Un conto di liquidità ha movimenti ma nessuna cassa che lo rappresenti: il riepilogo non lo vede.'
                        : ($dateEsercizioSbagliate
                            ? ($fuoriPeriodo['fuori'] === $fuoriPeriodo['totali']
                                ? 'Tutte le '.$fuoriPeriodo['totali'].' scritture di questo esercizio sono datate fuori dal suo periodo: sono le date dell\'esercizio a essere sbagliate, non le scritture.'
                                : $fuoriPeriodo['fuori'].' scritture su '.$fuoriPeriodo['totali'].' sono datate fuori dal periodo dell\'esercizio: probabilmente sono le date dell\'esercizio a essere sbagliate.')
                            : ($fuoriPeriodo['fuori'] === 1
                                ? 'Una scrittura è datata fuori dal periodo del suo esercizio: la fotografia (per data) e il riepilogo (per esercizio) non contano le stesse righe.'
                                : $fuoriPeriodo['fuori'].' scritture sono datate fuori dal periodo del loro esercizio: la fotografia (per data) e il riepilogo (per esercizio) non contano le stesse righe.'))),
                'rimedio' => $liquiditaFoto === $finali ? null
                    : ($contiSenzaCassa !== 0
                        ? 'Crea o riassocia la cassa a quel conto nella pagina Casse.'
                        : ($dateEsercizioSbagliate
                            ? 'Correggi le date di inizio e fine dell\'esercizio, così che comprendano le sue scritture.'
                            : 'Nel Libro Giornale cerca le scritture con data fuori dal periodo dell\'esercizio e correggi la data o l\'esercizio.')),
                'azione' => $liquiditaFoto === $finali ? null : ($contiSenzaCassa !== 0 ? 'casse' : ($dateEsercizioSbagliate ? 'esercizio' : 'libro-giornale')),
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
                        // senza apertura e senza incassi non c'è un movimento da cercare nella Prima nota.
                        . ($cnSenzaApertura
                            ? ($negative[0]['entrate'] === 0
                                ? 'Questa cassa non ha un saldo di apertura e nessun incasso nell\'esercizio: i pagamenti sono usciti da un conto che, per il programma, era vuoto.'
                                : 'Questa cassa non ha un saldo di apertura: i pagamenti sono usciti prima che gli incassi registrati bastassero.')
                            : 'Un conto reale non può contenere meno di niente: manca un\'entrata, o un movimento è sulla cassa sbagliata.'),
                'rimedio' => $negative === [] ? null
                    : ($cnSenzaApertura
                        ? 'Se a inizio esercizio c\'erano soldi sul conto, registra il saldo iniziale della cassa: è quello che manca. Se i condòmini hanno versato, registra gli incassi delle rate. Se il conto era davvero a zero e nessuno ha versato, quel pagamento non può essere uscito da qui: nella Prima nota spostalo sulla cassa giusta.'
                        : 'Apri la Prima nota su quella cassa e cerca il movimento intorno a quella data: registra l\'entrata mancante o spostalo sulla cassa giusta.'),
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
