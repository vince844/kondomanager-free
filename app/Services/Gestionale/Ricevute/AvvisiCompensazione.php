<?php

namespace App\Services\Gestionale\Ricevute;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\RicevutaNonEmettibileException;
use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Immobile;
use App\Models\User;
use App\Notifications\Gestionale\AvvisoCompensazioneNotification;
use App\Services\PDF\PdfService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * L'avviso di compensazione: la persona le cui rate il credito ha coperto, senza un versamento nuovo, e che non riceve la ricevuta di
 * quell'incasso. Sta nella pagina delle ricevute, nella sezione «Compensazioni senza avviso».
 *
 * **Chi c'è** ({@see righe()}): per ogni incasso del periodo (non annullato), le persone le cui quote una compensazione dell'incasso (le figlie
 * `storno_credito`, con la pivot in positivo su una quota a debito) ha coperto, e che la ricevuta non contiene. Due casi:
 * - l'incasso fatto solo di credito (`senza_denaro`): la pagina delle ricevute non lo ha, perché non c'è un versamento di cui dare ricevuta;
 *   ci sono tutte le persone coperte;
 * - l'incasso con denaro la cui ricevuta va a un'altra persona (`fuori_dalla_ricevuta`): le compensazioni che coprono solo le rate di persone
 *   diverse dalla posizione, la stessa regola con cui la ricevuta le nomina in `compensazioni_escluse` ({@see RicevutaIncasso}).
 * Non ci sono gli incassi con il denaro di più persone e senza chi ha versato: non sono compensazioni, e la ricevuta non saprebbe
 * per conto di chi.
 *
 * **Il registro**: una riga per avviso e per persona (per email una per indirizzo, con lo stesso `dati.avviso`), tipo `avviso_compensazione`,
 * l'incasso come «documentabile», l'istantanea nei `dati`. Il PDF non si conserva: basta l'istantanea, come per la ricevuta e il promemoria.
 * Una compensazione è «avvisata» quando ha un avviso stampato, in coda, partito o spedito; uno non partito no, e torna fra quelle da fare.
 *
 * **Il bollo**: la riga come la ricevuta, con lo stato che sceglie l'amministratore (o nessuno); il programma non dice se è dovuto. La sezione
 * propone l'ultima scelta di chi lavora in questo condominio ({@see bolloRicordato()}), come la ricevuta.
 */
final class AvvisiCompensazione
{
    public const TIPO = RegistroInvio::TIPO_AVVISO_COMPENSAZIONE;

    public const TITOLO = 'Avviso di compensazione';

    /** Gli avvisi al massimo in un PDF. */
    public const TETTO_PDF = 200;

    public const NESSUNO = 'Scegli le compensazioni da avvisare.';

    public const FRASE_BOLLO = 'Se l\'avviso sconta il bollo lo decidi tu, con il tuo commercialista: scegli lo stato. Il programma non lo calcola e non lo assolve.';

    /** Gli stati di un avviso che lo rendono fatto: stampato, in coda, partito, spedito. */
    private const STATI_AVVISATA = [RegistroInvio::STATO_STAMPATA, RegistroInvio::STATO_IN_CODA, RegistroInvio::STATO_INVIATA, RegistroInvio::STATO_SPEDITA];

    public function __construct(private readonly PersoneDegliIncassi $persone, private readonly PdfService $pdf) {}

    /**
     * Le compensazioni del periodo (la data del versamento), una riga per incasso e persona coperta, dalla più recente.
     *
     * @param array{data_da?: ?string, data_a?: ?string, gestione_id?: ?int} $filtri
     * @return Collection<int, array<string, mixed>>
     */
    public function righe(Condominio $condominio, array $filtri = [], ?array $soloIncassi = null): Collection
    {
        $compensazione = TipoMovimentoContabile::STORNO_CREDITO->value;
        $pivot = DB::table('scritture_contabili as i')
            ->join('scritture_contabili as f', 'f.scrittura_padre_id', '=', 'i.id')
            ->join('quota_scrittura as qs', 'qs.scrittura_contabile_id', '=', 'f.id')
            ->join('rate_quote as q', 'q.id', '=', 'qs.rate_quota_id')
            ->where('i.condominio_id', $condominio->id)
            ->where('i.tipo_movimento', TipoMovimentoContabile::INCASSO_RATA->value)
            ->where('i.stato', '!=', 'annullata')
            ->whereNull('i.deleted_at')
            ->where('f.tipo_movimento', $compensazione)
            ->whereNull('f.deleted_at')
            ->when(! empty($filtri['data_da']), fn ($w) => $w->whereDate('i.data_competenza', '>=', $filtri['data_da']))
            ->when(! empty($filtri['data_a']), fn ($w) => $w->whereDate('i.data_competenza', '<=', $filtri['data_a']))
            ->when(! empty($filtri['gestione_id']), fn ($w) => $w->where('i.gestione_id', (int) $filtri['gestione_id']))
            ->when($soloIncassi !== null, fn ($w) => $w->whereIn('i.id', $soloIncassi === [] ? [0] : $soloIncassi))
            ->get(['i.id as incasso', 'f.id as figlia', 'q.id as quota', 'q.anagrafica_id as persona', 'q.importo as importo_quota', 'qs.importo_pagato as importo']);
        if ($pivot->isEmpty()) {
            return collect();
        }

        $incassi = $pivot->pluck('incasso')->unique()->values()->all();
        $denaro = DB::table('righe_scritture')->whereIn('scrittura_id', $incassi)->where('tipo_riga', 'dare')->whereNotNull('cassa_id')
            ->groupBy('scrittura_id')->selectRaw('scrittura_id, SUM(importo) as importo')->pluck('importo', 'scrittura_id');
        $persone = $this->persone->di($incassi);

        // Per ogni incasso, per ogni compensazione: il netto per quota; le quote coperte sono quelle a debito con il netto positivo; il credito
        // usato viene dalle quote con il netto negativo.
        $coperte = [];
        $fonti = [];
        foreach ($pivot->groupBy('incasso') as $incasso => $righe) {
            $conDenaro = (int) ($denaro[$incasso] ?? 0) > 0;
            if ($conDenaro && $persone[$incasso]['piu_persone'] !== []) {
                continue;
            }
            $posizione = $persone[$incasso]['posizione'];
            foreach ($righe->groupBy('figlia') as $figlia) {
                $netto = [];
                foreach ($figlia as $r) {
                    $netto[(int) $r->quota] = ($netto[(int) $r->quota] ?? 0) + (int) $r->importo;
                }
                $quote = $figlia->keyBy('quota');
                $positive = array_filter($netto, fn ($v, $q) => $v > 0 && (int) $quote[$q]->importo_quota > 0 && $quote[$q]->persona !== null, ARRAY_FILTER_USE_BOTH);
                $diChi = array_values(array_unique(array_map(fn ($q) => (int) $quote[$q]->persona, array_keys($positive))));
                // Con il denaro: solo le compensazioni che non coprono rate della posizione (la ricevuta contiene le altre).
                if ($conDenaro && ($posizione === null || $diChi === [] || in_array((int) $posizione, $diChi, true))) {
                    continue;
                }
                $negative = array_keys(array_filter($netto, fn ($v) => $v < 0));
                foreach ($positive as $q => $v) {
                    $p = (int) $quote[$q]->persona;
                    $coperte[$incasso][$p][$q] = ($coperte[$incasso][$p][$q] ?? 0) + $v;
                    foreach ($negative as $n) {
                        if ($quote[$n]->persona !== null) {
                            $fonti[$incasso][$p][(int) $quote[$n]->persona] = true;
                        }
                    }
                }
            }
        }
        if ($coperte === []) {
            return collect();
        }

        $tutteLeQuote = collect($coperte)->flatMap(fn ($perPersona) => collect($perPersona)->flatMap(fn ($q) => array_keys($q)))->unique()->values()->all();
        $dettagli = DB::table('rate_quote as q')->join('rate as r', 'r.id', '=', 'q.rata_id')->join('piani_rate as p', 'p.id', '=', 'r.piano_rate_id')
            ->whereIn('q.id', $tutteLeQuote)->get(['q.id', 'q.immobile_id', 'r.numero_rata', 'r.data_scadenza', 'p.nome as piano'])->keyBy('id');
        $unita = Immobile::whereIn('id', $dettagli->pluck('immobile_id')->filter()->unique()->values()->all() ?: [0])->get()->keyBy('id');
        $idPersone = collect($coperte)->flatMap(fn ($perPersona) => array_keys($perPersona))
            ->merge(collect($fonti)->flatMap(fn ($perPersona) => collect($perPersona)->flatMap(fn ($f) => array_keys($f))))
            ->merge(collect($persone)->pluck('posizione')->filter())->unique()->values()->all();
        $anagrafiche = Anagrafica::whereIn('id', $idPersone ?: [0])->get(['id', 'nome', 'email', 'email_secondaria', 'pec'])->keyBy('id');
        $scritture = ScritturaContabile::whereIn('id', array_keys($coperte))->get(['id', 'numero_protocollo', 'data_competenza', 'gestione_id'])->keyBy('id');
        $ultimi = RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', self::TIPO)->where('documentabile_type', ScritturaContabile::class)
            ->whereIn('documentabile_id', array_keys($coperte))->orderBy('id')->get(['id', 'documentabile_id', 'anagrafica_id', 'canale', 'stato', 'destinatario', 'emesso_il', 'errore'])
            ->groupBy(fn ($r) => $r->documentabile_id . ':' . $r->anagrafica_id);

        $esito = collect();
        foreach ($coperte as $incasso => $perPersona) {
            $i = $scritture->get($incasso);
            foreach ($perPersona as $persona => $quote) {
                $a = $anagrafiche->get($persona);
                $chiave = $incasso . ':' . $persona;
                $sue = $ultimi->get($chiave, collect());
                $ultimo = $sue->last();
                $esito->push([
                    'chiave'      => $chiave,
                    'incasso_id'  => (int) $incasso,
                    'numero'      => $i?->numero_protocollo,
                    'data'        => $i?->data_competenza?->format('Y-m-d'),
                    'persona'     => ['id' => (int) $persona, 'nome' => (string) ($a?->nome ?? '')],
                    'importo'     => (int) array_sum($quote),
                    'quote'       => collect($quote)->map(fn ($v, $q) => [
                        'rata'     => ((int) $dettagli[$q]->numero_rata) === 0 ? 'Rata 0 (pregresso)' : 'Rata ' . (int) $dettagli[$q]->numero_rata,
                        'piano'    => (string) $dettagli[$q]->piano,
                        'unita'    => $dettagli[$q]->immobile_id !== null && isset($unita[$dettagli[$q]->immobile_id]) ? $unita[$dettagli[$q]->immobile_id]->etichetta : null,
                        'scadenza' => substr((string) $dettagli[$q]->data_scadenza, 0, 10),
                        'importo'  => (int) $v,
                    ])->sortBy('scadenza')->values()->all(),
                    'credito_di'  => collect(array_keys($fonti[$incasso][$persona] ?? []))->map(fn ($id) => (string) ($anagrafiche->get($id)?->nome ?? ''))->filter()->sort()->values()->all(),
                    'caso'        => (int) ($denaro[$incasso] ?? 0) > 0 ? 'fuori_dalla_ricevuta' : 'senza_denaro',
                    'ricevuta_di' => (int) ($denaro[$incasso] ?? 0) > 0 ? $anagrafiche->get((int) $persone[$incasso]['posizione'])?->nome : null,
                    'ha_email'    => $a !== null && (filled($a->email) || filled($a->email_secondaria)),
                    // Gli indirizzi a cui va l'avviso per email (quelli di serie, la primaria e la secondaria): la sezione li mostra prima dell'invio.
                    'indirizzi'   => $a === null ? [] : array_values(collect(EmissioneRicevute::indirizzi($a))->only(EmissioneRicevute::TIPI_PROPOSTI)->all()),
                    'avvisata'    => $sue->contains(fn ($r) => in_array($r->stato, self::STATI_AVVISATA, true)),
                    'ultimo'      => $ultimo ? ElencoRicevute::emissione($ultimo) : null,
                ]);
            }
        }

        return $esito->sortBy([['data', 'desc'], ['incasso_id', 'desc'], ['persona.nome', 'asc']])->values();
    }

    /**
     * Le compensazioni chieste ("incasso:persona"): tutte di questo condominio e ancora compensazioni da avvisare; altrimenti 404 per tutta la
     * richiesta.
     *
     * @param mixed $chiavi
     * @return Collection<int, array<string, mixed>>
     */
    public function scelte(Condominio $condominio, mixed $chiavi): Collection
    {
        $grezze = array_values((array) $chiavi);
        $chiavi = array_values(array_unique(array_filter($grezze, fn ($c) => is_string($c) && preg_match('/^\d+:\d+$/', $c) === 1)));
        // Una chiave che non è «incasso:persona» non è di nessuna compensazione: 404 per tutta la richiesta, come le altre.
        abort_if($chiavi === [] || count($chiavi) !== count(array_unique(array_map(fn ($c) => is_scalar($c) ? (string) $c : '', $grezze))), 404);
        $incassi = array_values(array_unique(array_map(fn ($c) => (int) explode(':', $c)[0], $chiavi)));
        $righe = $this->righe($condominio, [], $incassi)->keyBy('chiave');
        abort_unless(collect($chiavi)->every(fn ($c) => $righe->has($c)), 404);
        if (count($chiavi) > self::TETTO_PDF) {
            throw new RicevutaNonEmettibileException('Gli avvisi scelti sono ' . count($chiavi) . ': al massimo ' . self::TETTO_PDF . ' per volta.');
        }

        return $righe->only($chiavi)->values();
    }

    /**
     * Lo stato del bollo dell'ultimo avviso di chi lavora in questo condominio: la sua ultima scelta, come per la ricevuta, con una memoria sua
     * (i due documenti possono avere stati diversi). Null senza avvisi, o se l'ultimo non aveva una scelta.
     */
    public static function bolloRicordato(Condominio $condominio, ?User $utente): ?string
    {
        if ($utente === null) {
            return null;
        }
        $bollo = RegistroInvio::where('condominio_id', $condominio->id)->where('tipo', self::TIPO)->where('emesso_da', $utente->id)
            ->whereNotNull('dati->avviso')->orderByDesc('id')->first()?->dati['bollo'] ?? null;

        return in_array($bollo, EmissioneRicevute::STATI_BOLLO, true) ? $bollo : null;
    }

    /** Lo stato del bollo scelto: uno dei tre della ricevuta, o nessuno. */
    public static function bollo(mixed $bollo): ?string
    {
        if ($bollo === null || $bollo === '') {
            return null;
        }
        if (! is_string($bollo) || ! in_array($bollo, EmissioneRicevute::STATI_BOLLO, true)) {
            throw new RicevutaNonEmettibileException('Lo stato dell\'imposta di bollo non esiste: si sceglie fra assolta, non dovuta e da assolvere.');
        }

        return $bollo;
    }

    /**
     * La stampa: un PDF con un avviso per pagina, e una riga «stampata» per avviso, con l'impronta del PDF.
     *
     * @return array{pdf: string, nome: string}
     */
    public function stampa(Condominio $condominio, mixed $chiavi, mixed $bollo, ?User $utente): array
    {
        $scelte = $this->scelte($condominio, $chiavi);
        $bollo = self::bollo($bollo);
        $adesso = now();

        $pdf = DB::transaction(function () use ($condominio, $scelte, $bollo, $utente, $adesso) {
            $righe = [];
            foreach ($scelte as $c) {
                $righe[] = RegistroInvio::create($this->base($condominio, $c, $utente, $adesso) + ['canale' => RegistroInvio::CANALE_STAMPA, 'stato' => RegistroInvio::STATO_STAMPATA,
                    'dati' => self::istantanea($c, $bollo, $adesso)]);
            }
            $pdf = $this->rendi($condominio, array_map(fn (RegistroInvio $r) => $r->dati, $righe));
            RegistroInvio::whereIn('id', array_map(fn ($r) => $r->id, $righe))->update(['impronta' => hash('sha256', $pdf)]);

            return $pdf;
        });

        return ['pdf' => $pdf, 'nome' => PdfService::nomeFile('avvisi-di-compensazione', (string) $condominio->nome, DateHelper::oggiUtente())];
    }

    /**
     * L'invio per email: per ogni avviso un messaggio per indirizzo dei tipi scelti (di serie la primaria e la secondaria, come la ricevuta).
     * Chi non ha un indirizzo fra quelli scelti resta da stampare. Con la coda `sync` al massimo i messaggi del tetto, a persone intere.
     *
     * @return array<string, mixed>
     */
    public function invia(Condominio $condominio, mixed $chiavi, mixed $bollo, mixed $tipiIndirizzo, ?User $utente): array
    {
        if (EmissioneRicevute::senzaServerPosta()) {
            throw new RicevutaNonEmettibileException(EmissioneRicevute::SENZA_SERVER_POSTA);
        }
        $scelte = $this->scelte($condominio, $chiavi);
        $bollo = self::bollo($bollo);
        $tipi = array_values(array_intersect(array_keys(EmissioneRicevute::TIPI_INDIRIZZO),
            array_map('strval', array_filter((array) ($tipiIndirizzo ?? EmissioneRicevute::TIPI_PROPOSTI), 'is_scalar'))));
        $anagrafiche = Anagrafica::whereIn('id', $scelte->pluck('persona.id')->all() ?: [0])->get()->keyBy('id');

        $gruppi = [];
        $senza = [];
        foreach ($scelte as $c) {
            $a = $anagrafiche->get($c['persona']['id']);
            $indirizzi = $a === null ? [] : collect(EmissioneRicevute::indirizzi($a))->only($tipi)->map(fn ($i, $t) => [$i, $t])->values()->all();
            if ($indirizzi === []) {
                $senza[] = $c['persona']['nome'];

                continue;
            }
            $gruppi[] = ['avviso' => $c, 'indirizzi' => $indirizzi];
        }
        [$dentro, $fuori] = \App\Services\Gestionale\Invii\InviiPerPersona::dentroIlTetto($gruppi, EmissioneRicevute::tettoMessaggi(), fn (array $g) => count($g['indirizzi']));
        $adesso = now();
        $messaggi = 0;
        foreach ($dentro as $g) {
            $istantanea = self::istantanea($g['avviso'], $bollo, $adesso);
            $righe = DB::transaction(fn () => array_map(fn ($d) => RegistroInvio::create($this->base($condominio, $g['avviso'], $utente, $adesso) + [
                'canale' => RegistroInvio::CANALE_EMAIL, 'destinatario' => $d[0], 'stato' => RegistroInvio::STATO_IN_CODA,
                'dati' => $istantanea + ['tipo_indirizzo' => $d[1], 'gettone' => (string) Str::uuid()],
            ]), $g['indirizzi']));
            foreach ($righe as $r) {
                try {
                    $notifica = new AvvisoCompensazioneNotification([$r->id]);
                    $notifica->id = (string) $r->dati['gettone'];
                    Notification::route('mail', $r->destinatario)->notify($notifica);
                } catch (\Throwable $e) {
                    Log::warning('Avviso di compensazione non partito: ' . $e->getMessage(), ['invio' => $r->id]);
                    RegistroInvio::whereKey($r->id)->where('stato', RegistroInvio::STATO_IN_CODA)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => ErroreDiPosta::daEccezione($e)]);
                }
                $messaggi++;
            }
        }

        return ['messaggi' => $messaggi, 'avvisi' => count($dentro), 'senza_indirizzo' => $senza,
            'restanti' => $fuori === null ? null : array_map(fn ($g) => $g['avviso']['chiave'], $fuori)];
    }

    /** L'avviso come lo scrive il registro e come lo stampa la vista: quello che diceva quando è stato emesso. */
    public static function istantanea(array $c, ?string $bollo, CarbonInterface $adesso): array
    {
        return [
            'avviso'      => (string) Str::uuid(),
            'incasso'     => ['id' => $c['incasso_id'], 'numero' => $c['numero'], 'data' => $c['data']],
            'persona'     => $c['persona'],
            'quote'       => $c['quote'],
            'importo'     => $c['importo'],
            'credito_di'  => $c['credito_di'],
            'bollo'       => $bollo,
            'emesso_il'   => $adesso->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'),
        ];
    }

    /** Il PDF di uno o più avvisi, dalle loro istantanee. */
    public function rendi(Condominio $condominio, array $avvisi): string
    {
        $mpdf = $this->pdf->generate('pdf.gestionale.avviso_compensazione', ['condominio' => $condominio, 'avvisi' => $avvisi,
            'data_emissione_stampe' => DateHelper::adessoUtente()], ['margin_top' => 14]);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function base(Condominio $condominio, array $c, ?User $utente, CarbonInterface $adesso): array
    {
        return ['condominio_id' => $condominio->id, 'anagrafica_id' => $c['persona']['id'], 'tipo' => self::TIPO, 'documentabile_type' => ScritturaContabile::class,
            'documentabile_id' => $c['incasso_id'], 'emesso_da' => $utente?->id, 'emesso_il' => $adesso];
    }
}
