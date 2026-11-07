<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\LimiteAltroIndirizzoSuperatoException;
use App\Exceptions\Gestionale\RicevutaNonEmettibileException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Notifications\Gestionale\RicevutaIncassoNotification;
use App\Services\Gestionale\Ricevute\ElencoRicevute;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\LimiteAltroIndirizzo;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use App\Traits\PaginaElenco;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le ricevute degli incassi (decisioni 197, 200 e 201): la pagina con i filtri, la finestra di un incasso, la stampa e l'invio per email, una o
 * tante. Le rotte stanno nel gruppo del gestionale, con il condominio nella rotta; ogni incasso della richiesta deve essere un incasso di rate di
 * quel condominio, altrimenti 404 (`EmissioneRicevute::incassi()`).
 */
class RicevuteIncassiController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio, PaginaElenco;

    /** Che cosa dice la pagina delle ricevute a chi ci arriva con un GET su un indirizzo di un PDF (`riapriPdf`). */
    public const FRASI_RIAPRI_PDF = [
        'stampa'          => 'Il PDF della stampa non si riapre dall\'indirizzo della scheda, e aprire quell\'indirizzo non ha stampato niente. Per averlo di nuovo ristampa la ricevuta da «Stampa o invia ricevuta», nel menu dell\'incasso, o da questa pagina fra le già emesse: esce come copia.',
        'pdf-anteprima'   => 'L\'anteprima non si riapre dall\'indirizzo della scheda: per vederla di nuovo premi «Anteprima» nella finestra della ricevuta («Stampa o invia ricevuta», nel menu dell\'incasso) o in quella della stampa in blocco.',
        'pdf-per-persona' => 'L\'archivio con un PDF per persona non si riscarica dall\'indirizzo della scheda: per averlo di nuovo premi «Scarica un PDF per persona» in questa pagina.',
    ];

    public function __construct(
        private ElencoRicevute $elenco,
        private EmissioneRicevute $emissione,
        private RicevutaIncasso $ricevuta,
    ) {}

    /**
     * La pagina delle ricevute. Alla prima apertura (nessun filtro nell'indirizzo) i filtri sono quelli del lavoro di tutti i mesi: ricevuta
     * mai emessa, versamenti dal primo del mese scorso a oggi. Dopo l'aggiornamento tutti gli incassi già registrati sono «mai emessa»: senza il
     * periodo, «seleziona tutte» manderebbe le ricevute di un anno.
     */
    public function index(Request $request, Condominio $condominio)
    {
        $grezzi = $request->only(['data_da', 'data_a', 'gestione_id', 'cassa_id', 'persone', 'email', 'ricevuta', 'soglia']);
        if (! $request->has('ricevuta')) {
            $grezzi += ElencoRicevute::periodoDiApertura();
        }
        $filtri = $this->elenco->filtriNormalizzati($grezzi);
        // I filtri scelti da chi lavora si ricordano: se un indirizzo di un PDF viene riaperto con un GET (`riapriPdf`), si torna a questi. Quelli
        // di serie no: portano le date di quel giorno, e il giorno dopo il rimando riporterebbe il periodo di ieri. L'apertura di serie, essendo
        // l'ultima pagina aperta, toglie anche quelli di prima.
        if ($request->has('ricevuta')) {
            $request->session()->put(self::chiaveFiltri($condominio), $filtri);
        } else {
            $request->session()->forget(self::chiaveFiltri($condominio));
        }
        $righe = $this->elenco->righe($condominio, $filtri);

        $perPagina = $this->righePerPagina($request);
        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $paginate = new LengthAwarePaginator($righe->forPage($pagina, $perPagina)->values(), $righe->count(), $perPagina, $pagina, [
            'path'  => $request->url(),
            'query' => $request->query(),
        ]);

        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/movimenti/incassi/RicevuteIncassi', [
            // Solo i campi che la pagina legge (l'intestazione e le rotte).
            'condominio'       => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'condomini'        => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio'        => $esercizio,
            'esercizi'         => $condominio->esercizi()->orderBy('data_inizio', 'desc')->get(['id', 'nome', 'stato']),
            'incassi'          => [
                'data' => $paginate->items(),
                'meta' => [
                    'current_page' => $paginate->currentPage(),
                    'last_page'    => $paginate->lastPage(),
                    'per_page'     => $paginate->perPage(),
                    'total'        => $paginate->total(),
                    'from'         => $paginate->firstItem(),
                    'to'           => $paginate->lastItem(),
                ],
            ],
            'totale'           => $righe->count(),
            // Mai emesse di incassi registrati da poco con un versamento più vecchio del periodo: la pagina lo dice, e si possono includere.
            'registrati_di_recente' => $this->elenco->registratiDiRecente($condominio, $filtri),
            'filters'          => $filtri,
            'persone'          => $this->elenco->personeDelFiltro($condominio),
            'gestioni'         => $condominio->gestioni()->orderBy('nome')->get(['id', 'nome']),
            // Le casse in cui un incasso può entrare (`StoreIncassoRateRequest`), la virtuale compresa.
            'casse'            => Cassa::where('condominio_id', $condominio->id)->whereIn('tipo', ['banca', 'contanti', 'virtuale'])->orderBy('nome')->get(['id', 'nome']),
            'scelta_ricordata' => $this->emissione->sceltaRicordata($condominio, $request->user()),
            'tetto'            => EmissioneRicevute::TETTO_PDF,
            'senza_server_posta' => EmissioneRicevute::senzaServerPosta(),
            // Le ricevute, non le righe: una ricevuta mandata a due indirizzi è una.
            'in_coda_da_tempo' => RegistroInvio::where('condominio_id', $condominio->id)->where('stato', RegistroInvio::STATO_IN_CODA)
                ->where('emesso_il', '<', now()->subHour())->distinct()->count('documentabile_id'),
            'non_partite'      => $this->emissione->daRiprovare($condominio)->count(),
            'annullate_da_comunicare' => $this->annullateDaComunicare($condominio),
        ]);
    }

    /**
     * Quello che serve alla finestra di un incasso: l'importo e la soglia, se è annullato, se una ricevuta c'è (un incasso senza denaro no),
     * i destinatari con gli indirizzi, le emissioni, se la prossima è una copia e se dopo l'originale la situazione è cambiata, se c'è un
     * server di posta, le scelte ricordate.
     */
    public function opzioni(Request $request, Condominio $condominio, string|int $scrittura): JsonResponse
    {
        $incasso = $this->assicuraIncasso($condominio, $scrittura);
        $originale = $this->emissione->originali([$incasso->id])[$incasso->id] ?? null;
        $contenuto = $this->emissione->contenutoDaEmettere($incasso, $originale);
        // Una copia riproduce l'originale: se dopo la situazione di quelle quote è cambiata (per esempio lo storno di un incasso registrato prima), la
        // finestra lo dice e rimanda all'estratto conto; non c'è una seconda ricevuta «aggiornata» con lo stesso numero.
        $copia = $originale === null ? null : [
            'del'      => $originale['giorno'],
            'cambiata' => $originale['contenuto'] !== null
                && RicevutaIncasso::cifre($originale['contenuto']) !== RicevutaIncasso::cifre($this->ricevuta->per($incasso)),
        ];

        $destinatari = $this->emissione->destinatari($contenuto, $incasso);
        $motivo = match (true) {
            $contenuto['importo_versato'] <= 0 => ElencoRicevute::MOTIVO_SENZA_VERSAMENTO,
            ($contenuto['piu_persone'] ?? []) !== [] => ElencoRicevute::motivoPiuPersone(array_column($contenuto['piu_persone'], 'nome')),
            default => null,
        };

        return response()->json([
            'id'               => $incasso->id,
            'numero'           => $contenuto['numero'],
            'data_versamento'  => $contenuto['data_versamento'],
            'importo'          => $contenuto['importo_versato'],
            // Il testo della causale, perché l'amministratore veda che cosa stamperebbe «Stampa la causale» (decisione 202).
            'causale'          => $contenuto['causale'] ?? null,
            // Il credito di un'altra persona usato nello stesso incasso: non è un versamento di questa ricevuta, e la finestra lo dice.
            'compensazioni_escluse' => $contenuto['compensazioni_escluse'] ?? [],
            // A quali quote sarà imputato il versamento, com'è registrato nell'incasso: la ricevuta lo scrive, e l'amministratore lo vede prima. La
            // finestra le raggruppa per piano e gestione; il nome della rata è quello breve anche per una copia emessa prima della regola.
            'quote'            => array_map(fn (array $q) => ['rata' => RicevutaIncasso::nomeBreve((string) $q['rata'], $q['piano'] ?? null), 'scadenza' => $q['scadenza'] ?? null]
                + array_intersect_key($q, array_flip(['piano', 'gestione', 'unita', 'importo', 'imputato', 'dal_credito', 'dopo'])), $contenuto['quote']),
            'parte_in_piu'     => array_map(fn (array $e) => array_intersect_key($e, array_flip(['importo', 'persona', 'rata', 'riduce', 'a_credito'])), $contenuto['eccedenze']),
            // La riga del criterio dell'imputazione che la ricevuta stampa (1.12.0-beta.3), o null: una copia emessa prima non l'ha.
            'imputazione'      => $contenuto['imputazione'] ?? null,
            // La soglia del bollo si guarda sul solo denaro versato; il credito usato la finestra lo dice a parte.
            'credito_usato'    => $contenuto['credito_usato'],
            'sopra_soglia'     => $contenuto['sopra_soglia'],
            'annullato'        => $contenuto['annullato'],
            'storno'           => $contenuto['storno'],
            'posizione'        => $contenuto['posizione'] ? ['id' => $contenuto['posizione']['id'], 'nome' => $contenuto['posizione']['nome']] : null,
            'versato_da'       => $contenuto['versato_da'],
            'persone_dell_incasso' => $contenuto['piu_persone'] ?? [],
            'destinatari'      => $destinatari,
            // Un incasso annullato si manda per email solo se la ricevuta era già partita: ha allora degli indirizzi da proporre.
            'inviabile'        => ! $contenuto['annullato'] || $destinatari !== [],
            'emissioni'        => self::emissioniDi($incasso),
            'copia'            => $copia,
            'senza_server_posta' => EmissioneRicevute::senzaServerPosta(),
            // «Prepara l'email» (decisione 207.1): l'oggetto e il testo del messaggio che il programma manderebbe, per l'email che l'amministratore
            // manda dal suo programma di posta; il saluto per ognuna delle persone a cui la ricevuta può andare.
            'bozza_email'      => self::bozzaEmail($condominio, $contenuto, $destinatari),
            'emettibile'       => $motivo === null,
            'motivo'           => $motivo,
            'scelta_ricordata' => $this->emissione->sceltaRicordata($condominio, $request->user()),
        ]);
    }

    /**
     * @param array<string, mixed> $contenuto
     * @param array<int, array{anagrafica_id: int, nome: string}> $destinatari
     * @return array{oggetto: string, saluto: string, saluti: array<int, string>, righe: array<int, string>, chiusura: string}
     */
    private static function bozzaEmail(Condominio $condominio, array $contenuto, array $destinatari): array
    {
        $testo = RicevutaIncassoNotification::testo((string) $condominio->nome, [$contenuto], null);
        $saluti = [];
        foreach ($destinatari as $d) {
            $saluti[$d['anagrafica_id']] = RicevutaIncassoNotification::testo((string) $condominio->nome, [$contenuto], $d['nome'])['saluto'];
        }

        return ['oggetto' => $testo['oggetto'], 'saluto' => $testo['saluto'], 'saluti' => $saluti, 'righe' => $testo['righe'], 'chiusura' => $testo['chiusura']];
    }

    /** L'anteprima di un'azione in blocco sulle ricevute selezionate. */
    public function anteprima(Request $request, Condominio $condominio): JsonResponse
    {
        return $this->json(fn () => $this->emissione->anteprima(
            $condominio,
            $this->selezione($request, $condominio),
            $request->boolean('anche_chi_ha_versato'),
            (array) $request->input('indirizzi', EmissioneRicevute::TIPI_PROPOSTI),
            $request->boolean('escludi_gia_inviate'),
            self::ordine($request),
        ));
    }

    /** La stampa: una ricevuta o tante, in un PDF. Gli errori tornano come testo, perché la risposta si apre in una scheda del browser. */
    public function stampa(Request $request, Condominio $condominio): Response
    {
        return $this->pdf($request, $condominio, false);
    }

    /** Lo stesso PDF della stampa, che non è una ricevuta: non scrive il registro e non chiede il bollo. */
    public function anteprimaPdf(Request $request, Condominio $condominio): Response
    {
        return $this->pdf($request, $condominio, true);
    }

    /**
     * Un GET su un indirizzo dei PDF (la stampa, l'anteprima, l'archivio per persona): la scheda del PDF ricaricata, riaperta dalla cronologia o
     * copiata. Il PDF si chiede solo con un POST, perché la stampa scrive il registro: qui non si rifà niente e non si scrive niente. Si torna
     * alla pagina delle ricevute dello stesso condominio, con la frase che dice da dove si riapre; senza questa rotta il browser mostrava un 405.
     * La pagina si riapre con i filtri che chi lavora aveva scelto nell'ultima apertura (`index` li ricorda in sessione, uno per condominio). Se
     * l'ultima apertura è stata di serie, o la pagina non è stata aperta prima, si riapre di serie: le ricevute mai emesse, dal primo del mese scorso
     * a oggi, con il periodo di quel giorno. In quel caso la ricevuta appena stampata non c'è nell'elenco, perché non è più «mai emessa»: si trova
     * scegliendo «Già stampata» o «Tutte».
     */
    public function riapriPdf(Request $request, Condominio $condominio, string $pdf): RedirectResponse
    {
        $filtri = (array) $request->session()->get(self::chiaveFiltri($condominio), []);

        return to_route('admin.gestionale.ricevute.index', ['condominio' => $condominio->id] + $filtri)
            ->with($this->flashWarning(self::FRASI_RIAPRI_PDF[$pdf] ?? self::FRASI_RIAPRI_PDF['stampa']));
    }

    /** La chiave della sessione dove la pagina ricorda i suoi ultimi filtri, uno per condominio. */
    private static function chiaveFiltri(Condominio $condominio): string
    {
        return 'ricevute.filtri.' . $condominio->id;
    }

    private function pdf(Request $request, Condominio $condominio, bool $anteprima): Response
    {
        try {
            $ids = $this->selezione($request, $condominio);
            if (count($ids) > EmissioneRicevute::TETTO_PDF) {
                throw new RicevutaNonEmettibileException('Le ricevute scelte sono ' . count($ids) . ': in un PDF ne stanno al massimo '
                    . EmissioneRicevute::TETTO_PDF . '. Restringi i filtri, per esempio il periodo, e stampa in più volte.');
            }
            $incassi = $this->emissione->incassi($condominio, $ids);
            // `per_email`: il PDF di «Prepara l'email», per il programma di posta di chi lavora: senza l'indirizzo per la busta.
            $esito = $this->emissione->stampa($condominio, $incassi, $this->emissione->scelte($request->all()), $request->user(), self::ordine($request), $anteprima, $request->boolean('per_email'));
        } catch (RicevutaNonEmettibileException $e) {
            return response(($anteprima ? 'Anteprima non fatta. ' : 'Ricevuta non stampata. ') . $e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($esito['pdf'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $esito['nome'] . '"');
    }

    /**
     * «Scarica un PDF per persona» (decisione 207.2): un archivio .zip con un PDF per persona e l'elenco degli indirizzi email. Ogni ricevuta è
     * una stampa nel registro. Gli errori tornano come testo, come la stampa.
     */
    public function pdfPerPersona(Request $request, Condominio $condominio): Response
    {
        try {
            $ids = $this->selezione($request, $condominio);
            if (count($ids) > EmissioneRicevute::TETTO_PDF) {
                throw new RicevutaNonEmettibileException('Le ricevute scelte sono ' . count($ids) . ': in un archivio ne stanno al massimo '
                    . EmissioneRicevute::TETTO_PDF . '. Restringi i filtri, per esempio il periodo, e scaricale in più volte.');
            }
            $esito = $this->emissione->archivioPerPersona($condominio, $this->emissione->incassi($condominio, $ids), $this->emissione->scelte($request->all()), $request->user());
        } catch (RicevutaNonEmettibileException $e) {
            return response('Archivio non fatto. ' . $e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($esito['zip'])
            ->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', 'attachment; filename="' . $esito['nome'] . '"');
    }

    /** L'ordine della stampa in blocco: per persona, per unità o per data del versamento; senza scelta, per persona. */
    private static function ordine(Request $request): string
    {
        return in_array($request->input('ordine'), ['persona', 'unita', 'data'], true) ? $request->input('ordine') : 'persona';
    }

    /** L'invio della ricevuta di un incasso agli indirizzi scelti nella sua finestra. Un indirizzo scritto a mano conta nel limite di dieci al minuto. */
    public function inviaUna(Request $request, Condominio $condominio, string|int $scrittura): JsonResponse
    {
        if ($request->filled('altro_indirizzo') && ! LimiteAltroIndirizzo::consuma($request->user())) {
            return self::troppiInvii($request);
        }

        try {
            return $this->json(fn () => $this->emissione->inviaUna(
                $condominio,
                $this->assicuraIncasso($condominio, $scrittura),
                (array) $request->input('destinatari', []),
                $request->input('altro_indirizzo'),
                $this->emissione->scelte($request->all()),
                $request->user(),
            ));
        } catch (LimiteAltroIndirizzoSuperatoException) {
            // La ricevuta di un incasso annullato rimandata all'indirizzo scritto a mano a cui era partita: lo stesso limite, la stessa risposta.
            return self::troppiInvii($request);
        }
    }

    /** L'invio in blocco delle ricevute selezionate. */
    public function invia(Request $request, Condominio $condominio): JsonResponse
    {
        return $this->json(function () use ($request, $condominio) {
            $ids = $this->selezione($request, $condominio);
            if ($request->boolean('escludi_gia_inviate')) {
                $this->emissione->incassi($condominio, $ids);
                $gia = $this->elenco->righe($condominio, ['ricevuta' => 'inviata'], $ids)->pluck('id')->all();
                $ids = array_values(array_diff($ids, $gia));
                if ($ids === [] && $gia !== []) {
                    throw new RicevutaNonEmettibileException('Le selezionate sono già state inviate tutte: non c\'è niente da mandare.');
                }
            }

            return $this->emissione->inviaInBlocco(
                $condominio,
                $ids,
                $request->boolean('anche_chi_ha_versato'),
                (array) $request->input('indirizzi', []),
                $this->emissione->scelte($request->all()),
                $request->user(),
                $request->filled('solo_persone') ? array_map('intval', (array) $request->input('solo_persone')) : null,
            );
        });
    }

    /** «Mandane una a me»: il messaggio della prima persona del blocco all'indirizzo di chi lavora. */
    public function provaAMe(Request $request, Condominio $condominio): JsonResponse
    {
        return $this->json(fn () => $this->emissione->provaAMe(
            $condominio,
            $this->selezione($request, $condominio),
            $request->boolean('anche_chi_ha_versato'),
            (array) $request->input('indirizzi', []),
            $this->emissione->scelte($request->all()),
            $request->user(),
            $request->filled('solo_persone') ? array_map('intval', (array) $request->input('solo_persone')) : null,
        ));
    }

    /** «Invia di nuovo» da una riga del registro: la stessa ricevuta, le stesse scelte. Se la riga era andata a un indirizzo scritto a mano conta nel limite. */
    public function inviaDiNuovo(Request $request, Condominio $condominio, string|int $invio): JsonResponse
    {
        $riga = $this->assicuraInvio($condominio, $invio);
        if ($riga->canale === RegistroInvio::CANALE_EMAIL && LimiteAltroIndirizzo::aMano($riga) && ! LimiteAltroIndirizzo::consuma($request->user())) {
            return self::troppiInvii($request);
        }

        return $this->json(fn () => $this->emissione->inviaDiNuovo($condominio, $riga, $request->user()));
    }

    /** «Non serve comunicarla»: dell'incasso annullato la cui ricevuta era uscita, l'amministratore dice che alla persona non serve dirlo (decisione 205). */
    public function nonServeComunicarla(Request $request, Condominio $condominio, string|int $scrittura): JsonResponse
    {
        $incasso = $this->assicuraIncasso($condominio, $scrittura);

        return $this->json(fn () => $this->emissione->nonServeComunicarla($incasso, $request->user()));
    }

    /** «Riprova le non partite»: di un incasso, o di tutto il condominio. */
    public function riprova(Request $request, Condominio $condominio): JsonResponse
    {
        $ids = $request->filled('incassi') ? array_map('intval', (array) $request->input('incassi')) : null;
        if ($ids !== null) {
            $this->emissione->incassi($condominio, $ids);
        }

        return $this->json(fn () => $this->emissione->riprovaLeNonPartite($condominio, $ids, $request->user()));
    }

    /**
     * Le emissioni della ricevuta di un incasso, dalla più recente: le legge la pagina dell'incasso e la sua finestra. La prima emissione è
     * l'originale.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function emissioniDi(ScritturaContabile $incasso): array
    {
        $annullato = $incasso->stato === 'annullata';

        return RegistroInvio::with('anagrafica')
            ->where('documentabile_type', ScritturaContabile::class)
            ->where('documentabile_id', $incasso->id)
            ->where('tipo', RegistroInvio::TIPO_RICEVUTA)
            ->orderByDesc('id')
            ->get()
            ->map(fn (RegistroInvio $e) => ElencoRicevute::emissione($e) + [
                'tipo_indirizzo' => $e->dati['tipo_indirizzo'] ?? null,
                'in_coda_da_tempo' => $e->stato === RegistroInvio::STATO_IN_CODA && $e->emesso_il?->lt(now()->subHour()),
                // Dove va una email se la si rimanda adesso (l'indirizzo di oggi della persona, o quello scritto a mano): la conferma lo dice.
                'manda_a' => $e->canale === RegistroInvio::CANALE_EMAIL
                    ? EmissioneRicevute::indirizzoDiNuovo((string) ($e->dati['tipo_indirizzo'] ?? 'altro'), $e->destinatario, $e->anagrafica, $annullato)
                    : null,
            ])
            ->values()
            ->all();
    }

    /** L'incasso della rotta: un incasso di rate di questo condominio, altrimenti 404. */
    private function assicuraIncasso(Condominio $condominio, string|int $scrittura): ScritturaContabile
    {
        $incasso = $this->emissione->incassiOppure404($condominio, $scrittura);
        abort_unless((int) $incasso->condominio_id === (int) $condominio->id, 404);

        return $incasso;
    }

    /** La riga del registro della rotta: di questo condominio, altrimenti 404. */
    private function assicuraInvio(Condominio $condominio, string|int $invio): RegistroInvio
    {
        abort_unless(is_numeric($invio), 404);
        $riga = RegistroInvio::findOrFail((int) $invio);
        abort_unless((int) $riga->condominio_id === (int) $condominio->id, 404);

        return $riga;
    }

    /** @return array<int, int> */
    private function selezione(Request $request, Condominio $condominio): array
    {
        return $this->emissione->idsDellaSelezione($condominio, $request->all());
    }

    /**
     * Gli incassi annullati la cui ricevuta era già stata stampata o inviata, e che dopo l'annullamento non è uscita di nuovo, per stampa o per
     * email: la persona ha in mano la ricevuta di un versamento che non risulta più. Non ci sono quelli per cui l'amministratore ha detto che non
     * serve comunicarlo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function annullateDaComunicare(Condominio $condominio): array
    {
        $annullati = ScritturaContabile::where('condominio_id', $condominio->id)
            ->where('tipo_movimento', TipoMovimentoContabile::INCASSO_RATA->value)
            ->where('stato', 'annullata')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('registro_invii')
                ->whereColumn('registro_invii.documentabile_id', 'scritture_contabili.id')
                ->where('registro_invii.documentabile_type', ScritturaContabile::class)
                ->whereIn('registro_invii.stato', [RegistroInvio::STATO_STAMPATA, RegistroInvio::STATO_INVIATA]))
            ->get(['id', 'numero_protocollo', 'data_competenza', 'updated_at']);

        // Dopo l'annullamento la ricevuta è uscita di nuovo (stampata, o mandata per email: in coda o partita), o l'amministratore ha detto che non
        // serve comunicarlo (in ogni momento: la riga resta): l'avviso si spegne.
        return $annullati->filter(fn (ScritturaContabile $i) => ! RegistroInvio::where('documentabile_type', ScritturaContabile::class)
                ->where('documentabile_id', $i->id)
                ->where(fn ($q) => $q->where(fn ($uscita) => $uscita->whereIn('stato', RegistroInvio::STATI_EMESSI)->where('emesso_il', '>=', $i->updated_at))
                    ->orWhere('stato', RegistroInvio::STATO_NON_DA_COMUNICARE))
                ->exists())
            ->map(fn (ScritturaContabile $i) => [
                'id'     => $i->id,
                'numero' => $i->numero_protocollo,
                'data'   => $i->data_competenza?->format('Y-m-d'),
            ])
            ->values()->all();
    }

    /** Il limite di dieci al minuto sugli indirizzi scritti a mano è scattato: 429 con la frase in italiano e i secondi da aspettare. */
    private static function troppiInvii(Request $request): JsonResponse
    {
        return response()->json(['message' => LimiteAltroIndirizzo::FRASE], 429, ['Retry-After' => LimiteAltroIndirizzo::secondiAllaProssima($request->user())]);
    }

    private function json(callable $azione): JsonResponse
    {
        try {
            $esito = $azione();
            unset($esito['messaggi_righe']);

            return response()->json($esito);
        } catch (RicevutaNonEmettibileException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
