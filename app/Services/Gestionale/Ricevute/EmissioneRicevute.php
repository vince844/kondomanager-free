<?php

namespace App\Services\Gestionale\Ricevute;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\LimiteAltroIndirizzoSuperatoException;
use App\Exceptions\Gestionale\RicevutaNonEmettibileException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Notifications\Gestionale\RicevutaIncassoNotification;
use App\Services\Gestionale\Invii\InviiPerPersona;
use App\Services\PDF\PdfService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * La stampa e l'invio per email delle ricevute degli incassi, una o tante (decisioni 197, 200, 201 e 202), e il registro di ogni emissione.
 *
 * - **Le scelte sono dell'amministratore**: lo stato dell'imposta di bollo (assolta, non dovuta, da assolvere: il programma non la assolve e non
 *   decide se è dovuta; mostra la soglia di € 77,47) e come è stato assolto, la nota, «Stampa la causale». In blocco il bollo si sceglie una
 *   volta per le ricevute sopra la soglia e una per le altre. Senza la scelta non si emette niente. L'ultima scelta di chi emette, in quel
 *   condominio, si ripropone ({@see sceltaRicordata()}).
 * - **L'originale e le copie**: la prima emissione uscita (stampata o partita; un invio in coda o non partito non è uscito) è l'originale e
 *   salva nella sua riga il contenuto della ricevuta; ogni emissione dopo (una ristampa, un invio, «invia di nuovo») è una copia e lo
 *   riproduce ({@see originali()}). L'anteprima del PDF e la prova non sono emissioni: non scrivono niente nel registro degli invii e non
 *   bruciano l'originale.
 * - **La stampa** fa un PDF in cui ogni ricevuta comincia su una pagina nuova; in blocco tiene insieme le ricevute della stessa persona, nell'ordine dei nomi, con
 *   l'indirizzo per la busta a finestra sulla prima. Oltre {@see TETTO_PDF} ricevute chiede di restringere i filtri.
 * - **L'invio** manda un messaggio per indirizzo spuntato con un PDF che porta tutte le ricevute di quella persona. La persona della posizione
 *   è proposta (primaria e secondaria; la PEC non è proposta, ma si può spuntare); chi ha versato per lei, se è un'altra persona, no: la ricevuta porta le rate e il residuo
 *   della posizione, e mandargliela è una scelta. Il server rifiuta un destinatario che non ha proposto. Un incasso annullato non si invia; un
 *   incasso senza denaro non ha ricevuta. Senza server di posta non si accoda niente; senza il servizio della coda partono al massimo
 *   {@see TETTO_MESSAGGI_SENZA_CODA} messaggi per volta, a persone intere (una persona sola parte intera anche se ne ha di più).
 * - **Il registro**: una riga per ricevuta e per indirizzo (email) o per ricevuta (stampa) a ogni emissione (`RegistroInvio`), con le scelte,
 *   il contenuto e l'impronta del PDF. «Non serve comunicarla» ({@see nonServeComunicarla()}) vi scrive una riga che non è un'emissione.
 * - **Gli indirizzi scritti a mano** contano nel limite di dieci al minuto per utente ({@see LimiteAltroIndirizzo}), anche in «Invia di nuovo»,
 *   «Riprova le non partite» e nella ricevuta di un incasso annullato rimandata all'indirizzo scritto a mano a cui era partita.
 * - **Un PDF per persona** ({@see archivioPerPersona()}): per chi manda le ricevute dal suo programma di posta, un archivio .zip con le ricevute
 *   di ogni persona in un PDF e l'elenco degli indirizzi; ogni ricevuta è una stampa nel registro.
 */
class EmissioneRicevute
{
    /** Le ricevute al massimo in un PDF: sopra, si restringono i filtri. */
    public const TETTO_PDF = 200;

    public const STATI_BOLLO = ['assolta', 'non_dovuta', 'da_assolvere'];

    /** Come è stato assolto il bollo: con il contrassegno (la marca, un numero per ricevuta) o in modo virtuale (un'autorizzazione per tutte). */
    public const MODI_BOLLO = ['contrassegno', 'virtuale'];

    public const NOTA_MASSIMA = 500;

    /** Le ricevute al massimo in un messaggio: sopra, la stessa persona riceve più messaggi. */
    public const RICEVUTE_PER_MESSAGGIO = 50;

    /**
     * Senza il servizio della coda (coda `sync`) le email partono dentro la richiesta, mentre la pagina aspetta: oltre questo numero di
     * messaggi per volta il server può interrompere la richiesta a metà. Al massimo questi, a persone intere (la prima parte sempre intera, anche
     * se da sola ha più messaggi del tetto), e la risposta dice chi resta (decisione 202).
     * Con il servizio della coda nessun tetto.
     */
    public const TETTO_MESSAGGI_SENZA_CODA = 50;

    /** I tipi di indirizzo di una persona, con il nome che l'amministratore legge. */
    public const TIPI_INDIRIZZO = ['email' => 'primaria', 'email_secondaria' => 'secondaria', 'pec' => 'PEC'];

    /**
     * Senza server di posta (il trasporto `log`) il messaggio non parte, e il trasporto ne scriverebbe il testo intero, PDF compreso, nel file di
     * log: le ricevute non si accodano, e il programma lo dice prima.
     */
    public const SENZA_SERVER_POSTA = 'Nessun server di posta configurato: le ricevute non partono. Configuralo nelle impostazioni della posta; intanto puoi stamparle.';

    /** Quello che dice in testa il PDF che non è una ricevuta: l'anteprima della stampa e la prova «Mandane una a me». */
    public const ETICHETTA_ANTEPRIMA = 'ANTEPRIMA';
    public const ETICHETTA_PROVA = 'PROVA';

    /** Quello che dice la riga di un messaggio già in coda che parte quando il server di posta non c'è più: non è partito. */
    public const NON_PARTITO_SENZA_SERVER = 'Nessun server di posta configurato: il messaggio non è partito. Configuralo nelle impostazioni della posta, poi invia di nuovo.';

    /** I tipi proposti per la persona della posizione: la PEC no, il programma la manda come email semplice. */
    public const TIPI_PROPOSTI = ['email', 'email_secondaria'];

    public function __construct(
        private RicevutaIncasso $ricevuta,
        private ElencoRicevute $elenco,
        private PdfService $pdf,
    ) {}

    // ── La selezione ─────────────────────────────────────────────────────────

    /**
     * Gli incassi chiesti, nell'ordine in cui arrivano: tutti di questo condominio e tutti incassi di rate, altrimenti 404 per tutta la
     * richiesta, come la pagina dell'incasso.
     *
     * @param array<int, mixed> $ids
     * @return Collection<int, ScritturaContabile>
     */
    public function incassi(Condominio $condominio, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        if ($ids === []) {
            throw new RicevutaNonEmettibileException('Nessuna ricevuta scelta.');
        }

        $trovati = ScritturaContabile::whereIn('id', $ids)
            ->where('condominio_id', $condominio->id)
            ->where('tipo_movimento', TipoMovimentoContabile::INCASSO_RATA->value)
            ->get()
            ->keyBy('id');

        abort_if($trovati->count() !== count($ids), 404);

        return collect($ids)->map(fn (int $id) => $trovati[$id])->values();
    }

    /** L'incasso di una rotta (`{scrittura}`): un incasso di rate di questo condominio, altrimenti 404. */
    public function incassiOppure404(Condominio $condominio, string|int $id): ScritturaContabile
    {
        abort_unless(is_numeric($id), 404);

        return $this->incassi($condominio, [(int) $id])->first();
    }

    /**
     * Gli identificativi della selezione della pagina delle ricevute: la lista spuntata, o «tutte le N dei filtri» (`tutte` con i `filtri`).
     *
     * @param array<string, mixed> $input
     * @return array<int, int>
     */
    public function idsDellaSelezione(Condominio $condominio, array $input): array
    {
        if (! empty($input['tutte'])) {
            return $this->elenco->righe($condominio, (array) ($input['filtri'] ?? []))->pluck('id')->all();
        }

        return array_values(array_map('intval', array_filter((array) ($input['incassi'] ?? []), 'is_numeric')));
    }

    /**
     * Le scelte dell'emissione, come arrivano dalla finestra: `bollo` (una ricevuta sola) vale per la soglia in cui sta; in blocco `bollo_sopra`
     * e `bollo_sotto`. Il modo del bollo assolto (contrassegno o modo virtuale) con i suoi estremi, la nota e «Stampa la causale» (decisione
     * 202) sono facoltativi; gli estremi senza il modo no, perché la ricevuta non saprebbe come scriverli.
     *
     * @param array<string, mixed> $input
     * @return array{bollo_sopra: ?string, bollo_sotto: ?string, modo_bollo: ?string, estremi_bollo: ?string, nota: ?string, stampa_causale: bool}
     */
    public function scelte(array $input): array
    {
        $stato = fn ($v) => is_string($v) && $v !== '' ? $v : null;
        $nota = trim(self::comeTesto($input['nota'] ?? null, 'La nota'));
        if (mb_strlen($nota) > self::NOTA_MASSIMA) {
            throw new RicevutaNonEmettibileException('La nota è troppo lunga: al massimo ' . self::NOTA_MASSIMA . ' caratteri.');
        }
        $estremi = trim(self::comeTesto($input['estremi_bollo'] ?? null, 'Gli estremi del bollo'));
        if (mb_strlen($estremi) > 200) {
            throw new RicevutaNonEmettibileException('Gli estremi del bollo sono troppo lunghi: al massimo 200 caratteri.');
        }

        $modo = $stato($input['modo_bollo'] ?? null);
        if ($modo !== null && ! in_array($modo, self::MODI_BOLLO, true)) {
            throw new RicevutaNonEmettibileException('Il bollo si assolve con il contrassegno o in modo virtuale.');
        }
        if ($modo === 'contrassegno') {
            // «n. 0117…» o «0117…»: la ricevuta scrive «contrassegno n. …» da sé.
            $estremi = trim((string) preg_replace('/^n\.?\s*/iu', '', $estremi));
        }
        if ($estremi !== '' && $modo === null) {
            throw new RicevutaNonEmettibileException('Scegli come è stato assolto il bollo, con il contrassegno o in modo virtuale, poi scrivi gli estremi.');
        }

        $scelte = [
            'bollo_sopra'    => $stato($input['bollo_sopra'] ?? null) ?? $stato($input['bollo'] ?? null),
            'bollo_sotto'    => $stato($input['bollo_sotto'] ?? null) ?? $stato($input['bollo'] ?? null),
            'modo_bollo'     => $modo,
            'estremi_bollo'  => $estremi !== '' ? $estremi : null,
            'nota'           => $nota !== '' ? $nota : null,
            'stampa_causale' => filter_var($input['stampa_causale'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        foreach (['bollo_sopra', 'bollo_sotto'] as $campo) {
            if ($scelte[$campo] !== null && ! in_array($scelte[$campo], self::STATI_BOLLO, true)) {
                throw new RicevutaNonEmettibileException('Lo stato dell\'imposta di bollo non esiste: si sceglie fra assolta, non dovuta e da assolvere.');
            }
        }

        return $scelte;
    }

    // ── La stampa ────────────────────────────────────────────────────────────

    /**
     * Le ricevute in un PDF, e una riga del registro per ognuna, con le scelte e l'impronta del PDF. Un incasso annullato si stampa e la
     * ricevuta lo dice in testa. Una ricevuta già emessa prima esce come «Copia», con la data dell'originale.
     *
     * Con `$anteprima` il PDF è lo stesso ma non è una ricevuta: porta «ANTEPRIMA» in testa, non scrive niente nel registro (quindi non
     * brucia l'originale) e non chiede il bollo, che se manca si legge «Da scegliere».
     *
     * @param Collection<int, ScritturaContabile> $incassi
     * @param array{bollo_sopra: ?string, bollo_sotto: ?string, estremi_bollo: ?string, nota: ?string} $scelte
     * Con `$perEmail` il PDF è per l'email che l'amministratore manda dal suo programma di posta («Prepara l'email»): come quello che il
     * programma allega ai suoi messaggi, senza il riquadro con l'indirizzo di casa della posizione per la busta a finestra. L'email può
     * andare a chi ha versato o a un altro indirizzo, e l'indirizzo di casa di una persona non deve seguirla. Il registro è lo stesso: una
     * stampa per ricevuta.
     *
     * @param string $ordine persona, unita o data: l'ordine delle persone; le ricevute della stessa persona restano insieme
     * @return array{pdf: string, nome: string}
     */
    public function stampa(Condominio $condominio, Collection $incassi, array $scelte, ?User $utente, string $ordine = 'persona', bool $anteprima = false, bool $perEmail = false): array
    {
        if ($incassi->count() > self::TETTO_PDF) {
            throw new RicevutaNonEmettibileException('Le ricevute scelte sono ' . $incassi->count() . ': in un PDF ne stanno al massimo '
                . self::TETTO_PDF . '. Restringi i filtri, per esempio il periodo, e stampa in più volte.');
        }

        $emetti = function () use ($condominio, $incassi, $scelte, $utente, $ordine, $anteprima, $perEmail) {
            $adesso = now();
            $originali = $this->originali($incassi->pluck('id')->all());
            $contenuti = $incassi->map(fn (ScritturaContabile $i) => $this->contenutoDaEmettere($i, $originali[$i->id] ?? null))->values();
            foreach ($contenuti as $c) {
                self::emettibile($c);
                if (! $anteprima) {
                    $this->bolloPer($c['sopra_soglia'], $scelte);
                }
            }
            if (! $anteprima) {
                $this->unContrassegnoPerRicevuta($contenuti->pluck('sopra_soglia')->all(), $scelte);
            }
            $voci = $contenuti->count() === 1
                ? [['contenuto' => $contenuti[0], 'indirizzo_per_posta' => true]]
                : $this->perBusta($contenuti, $ordine);
            if ($perEmail) {
                foreach ($voci as $k => $voce) {
                    $voci[$k]['indirizzo_per_posta'] = false;
                }
            }

            $ids = [];
            foreach ($voci as $k => $voce) {
                $c = $voce['contenuto'];
                if ($anteprima) {
                    $voci[$k] += $this->sceltePerLaVista($this->datiDellAnteprima($c['sopra_soglia'], $scelte, $originali[$c['incasso_id']] ?? null))
                        + ['emessa_il' => self::giorno($adesso), 'etichetta' => self::ETICHETTA_ANTEPRIMA];

                    continue;
                }
                $dati = $this->dati($c['sopra_soglia'], $scelte) + $this->dellOriginale($originali[$c['incasso_id']] ?? null, $c);
                $ids[] = RegistroInvio::create([
                    'condominio_id'      => $condominio->id,
                    'anagrafica_id'      => $c['posizione']['id'] ?? null,
                    'tipo'               => RegistroInvio::TIPO_RICEVUTA,
                    'canale'             => RegistroInvio::CANALE_STAMPA,
                    'documentabile_type' => ScritturaContabile::class,
                    'documentabile_id'   => $c['incasso_id'],
                    'numero'             => $c['numero'],
                    'dati'               => self::senzaVuoti($dati),
                    'stato'              => RegistroInvio::STATO_STAMPATA,
                    'emesso_da'          => $utente?->id,
                    'emesso_il'          => $adesso,
                ])->id;
                $voci[$k] += $this->sceltePerLaVista($dati) + ['emessa_il' => self::giorno($adesso)];
            }

            $pdf = $this->rendi($condominio, $voci, $adesso);
            if (! $anteprima) {
                RegistroInvio::whereIn('id', $ids)->update(['impronta' => hash('sha256', $pdf)]);
            }

            return ['pdf' => $pdf, 'nome' => self::nomeFile($condominio, array_column($voci, 'contenuto'), $anteprima)];
        };

        return $anteprima ? $emetti() : DB::transaction($emetti);
    }

    /** Il file dell'archivio con gli indirizzi email delle persone. */
    public const FILE_DEGLI_INDIRIZZI = InviiPerPersona::FILE_DEGLI_INDIRIZZI;

    /**
     * «Scarica un PDF per persona» (decisione 207.2): per chi manda le ricevute dal suo programma di posta (il server di posta non c'è, o non
     * funziona), un archivio .zip con un PDF per persona, con le sue ricevute fra le scelte e il suo nome per nome del file, e un file di testo
     * con l'elenco degli indirizzi email (persona, indirizzi, quante ricevute, il file). Ogni ricevuta è un'emissione del registro di canale
     * `stampa`, come la stampa, con le stesse scelte di bollo, nota e causale, l'originale e le copie, e lo stesso tetto; l'impronta è quella del
     * PDF della sua persona. Il PDF non ha l'indirizzo per la busta: va per email.
     *
     * @param Collection<int, ScritturaContabile> $incassi
     * @param array<string, mixed> $scelte
     * @return array{zip: string, nome: string}
     */
    public function archivioPerPersona(Condominio $condominio, Collection $incassi, array $scelte, ?User $utente): array
    {
        if ($incassi->count() > self::TETTO_PDF) {
            throw new RicevutaNonEmettibileException('Le ricevute scelte sono ' . $incassi->count() . ': in un archivio ne stanno al massimo '
                . self::TETTO_PDF . '. Restringi i filtri, per esempio il periodo, e scaricale in più volte.');
        }
        if (! class_exists(\ZipArchive::class)) {
            throw new RicevutaNonEmettibileException('Su questo server PHP non ha l\'estensione zip: l\'archivio non si può fare. Stampa le ricevute, '
                . 'o chiedi a chi gestisce l\'installazione di attivare l\'estensione.');
        }

        return DB::transaction(function () use ($condominio, $incassi, $scelte, $utente) {
            $adesso = now();
            $originali = $this->originali($incassi->pluck('id')->all());
            $contenuti = $incassi->map(fn (ScritturaContabile $i) => $this->contenutoDaEmettere($i, $originali[$i->id] ?? null))->values();
            foreach ($contenuti as $c) {
                self::emettibile($c);
                $this->bolloPer($c['sopra_soglia'], $scelte);
            }
            $this->unContrassegnoPerRicevuta($contenuti->pluck('sopra_soglia')->all(), $scelte);

            $file = [];
            $usati = [mb_strtolower(self::FILE_DEGLI_INDIRIZZI)];
            $elenco = [];
            foreach (self::gruppiPerBusta($contenuti, 'persona') as $gruppo) {
                $voci = [];
                $ids = [];
                foreach ($gruppo->values() as $c) {
                    $dati = $this->dati($c['sopra_soglia'], $scelte) + $this->dellOriginale($originali[$c['incasso_id']] ?? null, $c);
                    $ids[] = RegistroInvio::create([
                        'condominio_id'      => $condominio->id,
                        'anagrafica_id'      => $c['posizione']['id'] ?? null,
                        'tipo'               => RegistroInvio::TIPO_RICEVUTA,
                        'canale'             => RegistroInvio::CANALE_STAMPA,
                        'documentabile_type' => ScritturaContabile::class,
                        'documentabile_id'   => $c['incasso_id'],
                        'numero'             => $c['numero'],
                        'dati'               => self::senzaVuoti($dati),
                        'stato'              => RegistroInvio::STATO_STAMPATA,
                        'emesso_da'          => $utente?->id,
                        'emesso_il'          => $adesso,
                    ])->id;
                    $voci[] = ['contenuto' => $c, 'indirizzo_per_posta' => false] + $this->sceltePerLaVista($dati) + ['emessa_il' => self::giorno($adesso)];
                }
                $pdf = $this->rendi($condominio, $voci, $adesso);
                RegistroInvio::whereIn('id', $ids)->update(['impronta' => hash('sha256', $pdf)]);

                $posizione = $gruppo->first()['posizione'] ?? null;
                $nome = InviiPerPersona::nomeLibero(self::nomeFileDellaPersona($posizione['nome'] ?? 'Senza persona'), $usati) . '.pdf';
                $file[$nome] = $pdf;
                $elenco[] = [
                    'nome'      => $posizione['nome'] ?? 'Senza persona',
                    'indirizzi' => self::indirizzi(isset($posizione['id']) ? Anagrafica::find($posizione['id']) : null),
                    'documenti' => count($ids),
                    'file'      => $nome,
                ];
            }
            $file[self::FILE_DEGLI_INDIRIZZI] = InviiPerPersona::elencoDegliIndirizzi('Ricevute di pagamento', (string) $condominio->nome, $elenco, $adesso,
                fn (int $n) => $n === 1 ? '1 ricevuta' : $n . ' ricevute', self::TIPI_INDIRIZZO);

            return [
                'zip'  => InviiPerPersona::archivio($file, RicevutaNonEmettibileException::class),
                'nome' => Str::replaceLast('.pdf', '.zip', PdfService::nomeFile('ricevute-per-persona', $condominio->nome,
                    $adesso->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'))),
            ];
        });
    }

    /**
     * Il nome del file di una persona: il suo nome senza i caratteri che i sistemi rifiutano in un nome di file (`/ \ : * ? " < > |` e quelli di
     * controllo), senza accenti (dentro un .zip un nome con gli accenti si legge male in alcuni programmi), senza punti e spazi in fondo, al
     * massimo 80 caratteri, e mai un nome riservato di Windows (CON, PRN, AUX, NUL, COM1…, LPT1…).
     */
    public static function nomeFileDellaPersona(?string $nome): string
    {
        $testo = Str::ascii((string) $nome);
        $testo = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/', ' ', $testo);
        $testo = trim((string) preg_replace('/\s+/', ' ', $testo), ' .');
        $testo = trim(mb_substr($testo, 0, 80), ' .');
        if ($testo === '') {
            return 'Senza nome';
        }

        return preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $testo) ? $testo . '_' : $testo;
    }

    /**
     * Le scelte come le mostra l'anteprima: lo stato del bollo se è stato scelto (altrimenti niente), il modo e gli estremi solo con «assolta»,
     * la nota, la causale e la data dell'originale se la ricevuta è già uscita.
     *
     * @param array<string, mixed> $scelte
     * @param array{giorno: string, contenuto: ?array<string, mixed>}|null $originale
     * @return array<string, mixed>
     */
    private function datiDellAnteprima(bool $sopraSoglia, array $scelte, ?array $originale): array
    {
        $bollo = $sopraSoglia ? ($scelte['bollo_sopra'] ?? null) : ($scelte['bollo_sotto'] ?? null);
        $bollo = in_array($bollo, self::STATI_BOLLO, true) ? $bollo : null;

        return [
            'bollo'          => $bollo,
            'modo_bollo'     => $bollo === 'assolta' ? ($scelte['modo_bollo'] ?? null) : null,
            'estremi_bollo'  => $bollo === 'assolta' ? ($scelte['estremi_bollo'] ?? null) : null,
            'nota'           => $scelte['nota'] ?? null,
            'stampa_causale' => (bool) ($scelte['stampa_causale'] ?? false),
            'copia_del'      => $originale['giorno'] ?? null,
        ];
    }

    /**
     * L'ordine della busta: le ricevute della stessa persona insieme, in ordine di versamento, con l'indirizzo per la busta a finestra sulla
     * prima; le persone in ordine di nome (`persona`), dell'unità della prima ricevuta (`unita`) o del primo versamento (`data`).
     *
     * @param Collection<int, array<string, mixed>> $contenuti
     * @return array<int, array{contenuto: array<string, mixed>, indirizzo_per_posta: bool}>
     */
    public function perBusta(Collection $contenuti, string $ordine = 'persona'): array
    {
        $voci = [];
        foreach (self::gruppiPerBusta($contenuti, $ordine) as $g) {
            foreach ($g->values() as $i => $c) {
                $voci[] = ['contenuto' => $c, 'indirizzo_per_posta' => $i === 0];
            }
        }

        return $voci;
    }

    /**
     * Le persone nell'ordine scelto, ognuna con le sue ricevute in ordine di versamento. È l'ordine della stampa e anche quello delle parti del
     * PDF ({@see partiDelPdf()}): per questo legge solo `incasso_id`, `data_versamento`, `posizione` e l'unità della prima quota, che l'elenco
     * dà senza costruire le ricevute.
     *
     * @param Collection<int, array<string, mixed>> $contenuti
     * @return array<int, Collection<int, array<string, mixed>>>
     */
    private static function gruppiPerBusta(Collection $contenuti, string $ordine): array
    {
        $gruppi = $contenuti
            ->sortBy(fn (array $c) => [(string) $c['data_versamento'], (int) $c['incasso_id']])
            ->groupBy(fn (array $c) => (string) ($c['posizione']['id'] ?? 'nessuno'))
            ->values()
            ->all();

        $chiave = fn ($g) => match ($ordine) {
            'unita' => (string) ($g->first()['quote'][0]['unita'] ?? "\u{10FFFF}"),
            'data'  => (string) $g->first()['data_versamento'],
            default => '',
        };
        usort($gruppi, function ($a, $b) use ($chiave) {
            $senzaPersona = (int) ($a->first()['posizione'] === null) <=> (int) ($b->first()['posizione'] === null);

            return $senzaPersona
                ?: strnatcasecmp($chiave($a), $chiave($b))
                ?: strcasecmp((string) ($a->first()['posizione']['nome'] ?? ''), (string) ($b->first()['posizione']['nome'] ?? ''))
                ?: ((int) ($a->first()['posizione']['id'] ?? 0) <=> (int) ($b->first()['posizione']['id'] ?? 0));
        });

        return $gruppi;
    }

    /**
     * Il PDF di un messaggio: le ricevute delle sue righe del registro, con le scelte e la data di emissione di ognuna, senza l'indirizzo per la
     * busta. Lo usa la notifica nel momento in cui parte; restituisce anche il contenuto di ogni ricevuta per il testo del messaggio. Con
     * `$etichetta` (la prova) il PDF dice in testa che non è una ricevuta.
     *
     * @param array<int, array{incasso: ScritturaContabile, dati: array<string, mixed>, emesso_il: CarbonInterface}> $invii
     * @return array{pdf: string, nome: string, contenuti: array<int, array<string, mixed>>}
     */
    public function pdfDelMessaggio(Condominio $condominio, array $invii, ?string $persona, ?string $etichetta = null): array
    {
        usort($invii, fn ($a, $b) => [$a['incasso']->data_competenza?->format('Y-m-d'), $a['incasso']->id] <=> [$b['incasso']->data_competenza?->format('Y-m-d'), $b['incasso']->id]);
        $voci = array_map(fn ($i) => [
            'contenuto'           => isset($i['dati']['contenuto']) && is_array($i['dati']['contenuto'])
                ? $this->ricevuta->daIstantanea($i['dati']['contenuto'], $i['incasso'])
                : $this->ricevuta->per($i['incasso']),
            'emessa_il'           => self::giorno($i['emesso_il']),
            'indirizzo_per_posta' => false,
            'etichetta'           => $etichetta,
        ] + $this->sceltePerLaVista($i['dati']), $invii);
        $contenuti = array_column($voci, 'contenuto');

        return [
            'pdf'       => $this->rendi($condominio, $voci, $invii[0]['emesso_il'] ?? now()),
            'nome'      => count($contenuti) === 1 ? self::nomeFile($condominio, $contenuti) : PdfService::nomeFile('ricevute', $condominio->nome, null, Str::slug((string) $persona)),
            'contenuti' => $contenuti,
        ];
    }

    /**
     * Le ricevute già uscite (stampate o inviate): la prima emissione è l'originale, ogni emissione dopo è una copia. Un invio ancora in coda o
     * non partito non è uscito, e non è l'originale. Per ognuna il giorno dell'originale e il contenuto che l'originale ha portato (`dati.contenuto`). Si legge prima di scrivere le righe di una nuova
     * emissione, e la risposta va nella riga (`dati.copia_del`, `dati.contenuto`): così il PDF di un messaggio che parte dopo dice lo
     * stesso, due emissioni nello stesso secondo non si confondono, e una copia riproduce l'originale anche se dopo la situazione delle quote
     * è cambiata (per esempio lo storno di un incasso registrato prima).
     *
     * @param array<int, int> $incassiIds
     * @return array<int, array{giorno: string, contenuto: ?array<string, mixed>}> incasso => originale; il giorno è Y-m-d nel calendario dell'amministratore
     */
    public function originali(array $incassiIds): array
    {
        $esito = [];
        foreach (array_chunk(array_values(array_unique($incassiIds)) ?: [0], 500) as $blocco) {
            $prime = RegistroInvio::query()
                ->where('documentabile_type', ScritturaContabile::class)
                ->whereIn('documentabile_id', $blocco)
                ->where('tipo', RegistroInvio::TIPO_RICEVUTA)
                ->whereIn('stato', RegistroInvio::STATI_USCITI)
                ->groupBy('documentabile_id')
                ->selectRaw('MIN(id) as id')
                ->pluck('id');
            foreach (RegistroInvio::whereIn('id', $prime->all() ?: [0])->get(['id', 'documentabile_id', 'emesso_il', 'dati']) as $riga) {
                $esito[$riga->documentabile_id] = [
                    'giorno'    => $riga->emesso_il->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'),
                    'contenuto' => is_array($riga->dati['contenuto'] ?? null) ? $riga->dati['contenuto'] : null,
                ];
            }
        }

        return $esito;
    }

    /**
     * Il contenuto di una nuova emissione: quello dell'originale, se la ricevuta è già stata emessa (con l'annullamento di oggi, se c'è);
     * altrimenti la ricevuta letta adesso, che diventa l'originale.
     *
     * @param array{giorno: string, contenuto: ?array<string, mixed>}|null $originale
     * @return array<string, mixed>
     */
    public function contenutoDaEmettere(ScritturaContabile $incasso, ?array $originale): array
    {
        return $originale !== null && $originale['contenuto'] !== null
            ? $this->ricevuta->daIstantanea($originale['contenuto'], $incasso)
            : $this->ricevuta->per($incasso);
    }

    /**
     * Quello che la riga di una nuova emissione porta dell'originale: il giorno della prima emissione (se è una copia) e il contenuto, che
     * per la prima emissione è il suo.
     *
     * @param array{giorno: string, contenuto: ?array<string, mixed>}|null $originale
     * @param array<string, mixed> $contenuto
     * @return array{copia_del: ?string, contenuto: array<string, mixed>}
     */
    private function dellOriginale(?array $originale, array $contenuto): array
    {
        return ['copia_del' => $originale['giorno'] ?? null, 'contenuto' => $contenuto];
    }

    /** @return array{bollo: ?string, modo_bollo: ?string, estremi_bollo: ?string, nota: ?string, stampa_causale: bool, copia_del: ?string} */
    private function sceltePerLaVista(array $dati): array
    {
        return [
            'bollo'          => $dati['bollo'] ?? null,
            'modo_bollo'     => $dati['modo_bollo'] ?? null,
            'estremi_bollo'  => $dati['estremi_bollo'] ?? null,
            'nota'           => $dati['nota'] ?? null,
            'stampa_causale' => ! empty($dati['stampa_causale']),
            'copia_del'     => isset($dati['copia_del']) ? \Carbon\Carbon::parse($dati['copia_del'])->format('d/m/Y') : null,
        ];
    }

    /** Il registro non scrive le chiavi vuote dentro `dati`: un null in JSON non si cerca allo stesso modo su MySQL e su SQLite. */
    private static function senzaVuoti(array $dati): array
    {
        return array_filter($dati, fn ($v) => $v !== null);
    }

    // ── L'invio ──────────────────────────────────────────────────────────────

    /**
     * A chi può andare la ricevuta di un incasso: la persona della posizione e chi ha versato per lei, se è un'altra persona; per ognuna i suoi
     * indirizzi (primaria, secondaria, PEC) con la casella. Della posizione sono proposte la primaria e la secondaria; la PEC non è proposta (si può spuntare), perché il
     * programma la manda come email semplice; di chi ha versato niente (la ricevuta porta le rate e il residuo della posizione).
     *
     * Della ricevuta di un incasso annullato si propongono solo gli indirizzi a cui era già andata per email, com'erano allora (decisione 204).
     * Ogni indirizzo ha la sua `chiave`, con cui lo si sceglie: il tipo (primaria, secondaria, PEC) per gli indirizzi di oggi; il tipo e l'indirizzo
     * per quelli di un annullato, dove la primaria di prima e quella di dopo una correzione sono due indirizzi dello stesso tipo.
     *
     * @param array<string, mixed> $contenuto
     * @return array<int, array{anagrafica_id: int, nome: string, ruolo: string, indirizzi: array<int, array{tipo: string, indirizzo: string, proposto: bool, chiave: string}>}>
     */
    public function destinatari(array $contenuto, ?ScritturaContabile $incasso = null): array
    {
        if (! empty($contenuto['annullato']) && $incasso !== null) {
            return $this->destinatariDellAnnullata($incasso, $contenuto);
        }

        $ids = array_filter([$contenuto['posizione']['id'] ?? null, $contenuto['versato_da']['id'] ?? null]);
        $persone = Anagrafica::whereIn('id', $ids)->get()->keyBy('id');

        $lista = [];
        foreach (['posizione' => self::TIPI_PROPOSTI, 'versato_da' => []] as $ruolo => $proposti) {
            $id = $contenuto[$ruolo]['id'] ?? null;
            if (! $id || ! isset($persone[$id])) {
                continue;
            }
            $lista[] = [
                'anagrafica_id' => (int) $id,
                'nome'          => $persone[$id]->nome,
                'ruolo'         => $ruolo,
                'indirizzi'     => collect(self::indirizzi($persone[$id]))
                    ->map(fn (string $indirizzo, string $tipo) => ['tipo' => $tipo, 'indirizzo' => $indirizzo, 'proposto' => in_array($tipo, $proposti, true), 'chiave' => $tipo])
                    ->values()->all(),
            ];
        }

        return $lista;
    }

    /**
     * Gli indirizzi a cui la ricevuta di un incasso era già partita per email («inviata»: il server l'ha accettata), come l'originale li ha
     * avuti: lo stesso indirizzo anche se nell'anagrafica è cambiato, e tutti proposti. Una ricevuta stampata, in coda o non partita non ne dà.
     *
     * @param array<string, mixed> $contenuto
     * @return array<int, array{anagrafica_id: int, nome: string, ruolo: string, indirizzi: array<int, array{tipo: string, indirizzo: string, proposto: bool, chiave: string}>}>
     */
    private function destinatariDellAnnullata(ScritturaContabile $incasso, array $contenuto): array
    {
        $partite = RegistroInvio::where('documentabile_type', ScritturaContabile::class)
            ->where('documentabile_id', $incasso->id)
            ->where('tipo', RegistroInvio::TIPO_RICEVUTA)
            ->where('canale', RegistroInvio::CANALE_EMAIL)
            ->where('stato', RegistroInvio::STATO_INVIATA)
            ->whereNotNull('anagrafica_id')
            ->orderBy('id')
            ->get(['anagrafica_id', 'destinatario', 'dati']);
        $persone = Anagrafica::whereIn('id', $partite->pluck('anagrafica_id')->unique()->all() ?: [0])->get()->keyBy('id');

        $lista = [];
        foreach ($partite as $riga) {
            $persona = $persone->get($riga->anagrafica_id);
            if ($persona === null || ! filled($riga->destinatario)) {
                continue;
            }
            $lista[$persona->id] ??= [
                'anagrafica_id' => (int) $persona->id,
                'nome'          => $persona->nome,
                'ruolo'         => (int) ($contenuto['versato_da']['id'] ?? 0) === (int) $persona->id ? 'versato_da' : 'posizione',
                'indirizzi'     => [],
            ];
            $tipo = (string) ($riga->dati['tipo_indirizzo'] ?? 'altro');
            $chiave = $tipo . '|' . $riga->destinatario;
            $lista[$persona->id]['indirizzi'][$chiave] = ['tipo' => $tipo, 'indirizzo' => (string) $riga->destinatario, 'proposto' => true, 'chiave' => $chiave];
        }

        return array_values(array_map(fn (array $d) => ['indirizzi' => array_values($d['indirizzi'])] + $d, $lista));
    }

    /**
     * L'invio della ricevuta di un incasso agli indirizzi spuntati delle persone proposte, e, se l'amministratore lo scrive, a un altro
     * indirizzo. `$scelti` è una lista di `{anagrafica_id, tipi}`; un identificativo da solo vuol dire la primaria e la secondaria che ha.
     *
     * @param array<int, mixed> $scelti
     * @param array{bollo_sopra: ?string, bollo_sotto: ?string, nota: ?string, estremi_bollo: ?string} $scelte
     * @return array<string, mixed> il riepilogo
     */
    public function inviaUna(Condominio $condominio, ScritturaContabile $incasso, array $scelti, mixed $altroIndirizzo, array $scelte, ?User $utente): array
    {
        self::conServerPosta();
        $originale = $this->originali([$incasso->id])[$incasso->id] ?? null;
        $contenuto = $this->contenutoDaEmettere($incasso, $originale);
        self::emettibile($contenuto);
        $annullato = (bool) $contenuto['annullato'];
        if ($annullato && $this->destinatariDellAnnullata($incasso, $contenuto) === []) {
            throw new RicevutaNonEmettibileException('L\'incasso è annullato: la ricevuta si può stampare. Si manda per email solo se era già partita per email.');
        }
        $dati = $this->dati($contenuto['sopra_soglia'], $scelte);

        $proposti = collect($this->destinatari($contenuto, $incasso))->keyBy('anagrafica_id');
        $persone = Anagrafica::whereIn('id', $proposti->keys())->get()->keyBy('id');
        $destinazioni = [];
        foreach ($scelti as $scelta) {
            $id = $this->identificativo(is_array($scelta) ? ($scelta['anagrafica_id'] ?? 0) : $scelta);
            if (! $proposti->has($id)) {
                throw new RicevutaNonEmettibileException('Questa persona non è fra i destinatari della ricevuta di questo incasso.');
            }
            // Gli indirizzi fra cui scegliere, per chiave. Di un incasso annullato sono quelli a cui la ricevuta era già andata, com'erano allora;
            // altrimenti quelli di oggi dell'anagrafica, uno per tipo.
            $suoi = $annullato
                ? collect($proposti[$id]['indirizzi'])->mapWithKeys(fn (array $i) => [$i['chiave'] => $i])->all()
                : collect(self::indirizzi($persone[$id]))->mapWithKeys(fn (string $indirizzo, string $tipo) => [$tipo => ['tipo' => $tipo, 'indirizzo' => $indirizzo, 'chiave' => $tipo]])->all();
            if ($suoi === []) {
                throw new RicevutaNonEmettibileException($proposti[$id]['nome'] . ' non ha un indirizzo email: stampala per la posta.');
            }
            if (! is_array($scelta)) {
                // Un identificativo da solo: la primaria e la secondaria che ha; di un annullato, tutti gli indirizzi a cui era andata.
                $chiavi = $annullato ? array_keys($suoi) : array_values(array_intersect(self::TIPI_PROPOSTI, array_keys($suoi)));
            } else {
                $chiavi = [];
                foreach (self::tipiComeTesti($scelta['tipi'] ?? []) as $chiesto) {
                    // Si sceglie per chiave; il tipo da solo, per un annullato, vale per tutti gli indirizzi di quel tipo.
                    $trovate = isset($suoi[$chiesto]) ? [$chiesto] : array_keys(array_filter($suoi, fn (array $i) => $i['tipo'] === $chiesto));
                    if ($trovate === []) {
                        $tipoChiesto = explode('|', $chiesto)[0];
                        throw new RicevutaNonEmettibileException($proposti[$id]['nome'] . ' non ha l\'indirizzo ' . (self::TIPI_INDIRIZZO[$tipoChiesto] ?? $tipoChiesto) . ($annullato ? ' fra quelli a cui la ricevuta era già andata.' : '.'));
                    }
                    $chiavi = array_merge($chiavi, $trovate);
                }
                $chiavi = array_values(array_intersect(array_keys($suoi), $chiavi));
            }
            if ($chiavi === []) {
                throw new RicevutaNonEmettibileException('Scegli almeno un indirizzo di ' . $proposti[$id]['nome'] . '.');
            }
            foreach ($chiavi as $chiave) {
                $destinazioni[] = [$persone[$id], $suoi[$chiave]['indirizzo'], $suoi[$chiave]['tipo']];
            }
        }

        $altro = trim(self::comeTesto($altroIndirizzo, 'L\'indirizzo email'));
        if ($altro !== '' && $annullato) {
            throw new RicevutaNonEmettibileException('La ricevuta di un incasso annullato va solo agli indirizzi a cui era già andata.');
        }
        if ($altro !== '' && filter_var($altro, FILTER_VALIDATE_EMAIL) === false) {
            throw new RicevutaNonEmettibileException('«' . $altro . '» non è un indirizzo email.');
        }
        if ($altro !== '') {
            $destinazioni[] = [Anagrafica::find($contenuto['posizione']['id'] ?? 0), $altro, 'altro'];
        }
        if ($destinazioni === []) {
            throw new RicevutaNonEmettibileException('Scegli a chi mandare la ricevuta.');
        }
        // Di un incasso annullato la ricevuta torna anche all'indirizzo scritto a mano a cui era partita: conta nel limite, un posto per
        // indirizzo, prima di scrivere e mandare qualcosa. (L'altro indirizzo di un incasso non annullato lo conta il controller.)
        if ($annullato) {
            foreach ($destinazioni as [, , $tipo]) {
                if ($tipo === 'altro' && ! LimiteAltroIndirizzo::consuma($utente)) {
                    throw new LimiteAltroIndirizzoSuperatoException(LimiteAltroIndirizzo::FRASE);
                }
            }
        }

        $voce = ['id' => $incasso->id, 'numero' => $incasso->numero_protocollo];
        $dati += $this->dellOriginale($originale, $contenuto);
        $adesso = now();
        $messaggi = [];
        foreach ($destinazioni as [$persona, $indirizzo, $tipo]) {
            $messaggi = array_merge($messaggi, $this->spedisci($condominio, [$voce], $persona, $indirizzo, $tipo, [$incasso->id => $dati], $utente, $adesso));
        }

        return $this->riepilogo($messaggi, count(array_unique(array_map(fn ($d) => $d[0]?->id ?? 0, $destinazioni))));
    }

    /**
     * L'invio in blocco delle ricevute selezionate agli indirizzi dei tipi scelti, una scelta sola per tutte: un messaggio per indirizzo, con
     * tutte le ricevute di quella persona in un PDF (al più {@see RICEVUTE_PER_MESSAGGIO}, sopra si divide). Gli incassi annullati si saltano e
     * si contano; chi non ha nessun indirizzo fra quelli scelti va fra le ricevute da stampare per la posta.
     *
     * @param array<int, int> $ids
     * @param array<int, string> $tipiScelti
     * @param array{bollo_sopra: ?string, bollo_sotto: ?string, nota: ?string, estremi_bollo: ?string} $scelte
     * @return array<string, mixed> il riepilogo
     */
    public function inviaInBlocco(Condominio $condominio, array $ids, bool $ancheChiHaVersato, array $tipiScelti, array $scelte, ?User $utente, ?array $soloPersone = null): array
    {
        self::conServerPosta();
        $this->incassi($condominio, $ids);
        $tipiScelti = $this->tipiScelti($tipiScelti);
        $righeElenco = $this->elenco->righe($condominio, ['ricevuta' => 'tutte'], $ids);
        foreach ($righeElenco as $r) {
            $this->bolloPer($r['sopra_soglia'], $scelte);
        }
        $this->unContrassegnoPerRicevuta($righeElenco->pluck('sopra_soglia')->all(), $scelte);

        $piano = $this->pianoDelBlocco($righeElenco, $ancheChiHaVersato, $tipiScelti);
        if ($soloPersone !== null) {
            // «Invia le altre»: dopo il tetto dei messaggi, le persone che erano rimaste, e solo loro.
            $soloPersone = array_map('intval', $soloPersone);
            $piano['gruppi'] = array_values(array_filter($piano['gruppi'], fn ($g) => in_array((int) $g['persona']->id, $soloPersone, true)));
        }
        [$piano['gruppi'], $restanti] = self::dentroIlTetto($piano['gruppi']);
        $originali = $this->originali($righeElenco->pluck('id')->all());
        $incassi = ScritturaContabile::whereIn('id', $righeElenco->pluck('id')->all() ?: [0])->get()->keyBy('id');
        $contenuti = [];
        $adesso = now();
        $messaggi = [];
        foreach ($piano['gruppi'] as $g) {
            $dati = [];
            foreach ($g['voci'] as $v) {
                $contenuti[$v['id']] ??= $this->contenutoDaEmettere($incassi[$v['id']], $originali[$v['id']] ?? null);
                $dati[$v['id']] = $this->dati($v['sopra_soglia'], $scelte) + ['tipi_scelti' => $tipiScelti]
                    + $this->dellOriginale($originali[$v['id']] ?? null, $contenuti[$v['id']]);
            }
            foreach ($g['indirizzi'] as $tipo => $indirizzo) {
                $messaggi = array_merge($messaggi, $this->spedisci($condominio, $g['voci'], $g['persona'], $indirizzo, $tipo, $dati, $utente, $adesso));
            }
        }

        return ['restanti' => $restanti]
            + $this->riepilogo($messaggi, count($piano['gruppi']), $piano['senza_incassi'], $piano['senza_persone'], $this->elenco->esclusi($condominio, $ids));
    }

    /**
     * «Mandane una a me»: il messaggio della prima persona del blocco, con il suo PDF, all'indirizzo di chi sta lavorando. Non scrive righe nel
     * registro degli invii, perché non è una ricevuta mandata alla persona; nel registro delle email la riga c'è, come per ogni messaggio, con
     * l'oggetto «Prova — …».
     *
     * @param array<int, int> $ids
     * @param array<int, string> $tipiScelti
     * @param array<int, int>|null $soloPersone le persone rimaste nell'invio, se l'amministratore ne ha tolta qualcuna
     * @return array{indirizzo: string, persona: string, ricevute: int}
     */
    public function provaAMe(Condominio $condominio, array $ids, bool $ancheChiHaVersato, array $tipiScelti, array $scelte, User $utente, ?array $soloPersone = null): array
    {
        self::conServerPosta();
        $this->incassi($condominio, $ids);
        $righeElenco = $this->elenco->righe($condominio, ['ricevuta' => 'tutte'], $ids);
        $piano = $this->pianoDelBlocco($righeElenco, $ancheChiHaVersato, $this->tipiScelti($tipiScelti));
        if ($soloPersone !== null) {
            // Le persone che l'amministratore ha tolto dall'invio non sono la prima: la prova è il messaggio di chi riceve davvero.
            $piano['gruppi'] = array_values(array_filter($piano['gruppi'], fn ($g) => in_array((int) $g['persona']->id, array_map('intval', $soloPersone), true)));
        }
        $primo = $piano['gruppi'][0] ?? null;
        if ($primo === null) {
            throw new RicevutaNonEmettibileException('Fra le selezionate nessuna persona ha un indirizzo fra quelli scelti: non c\'è un messaggio da provare.');
        }
        if (! filled($utente->email)) {
            throw new RicevutaNonEmettibileException('Il tuo utente non ha un indirizzo email.');
        }

        $voci = array_slice($primo['voci'], 0, self::RICEVUTE_PER_MESSAGGIO);
        $originali = $this->originali(array_column($voci, 'id'));
        $incassi = ScritturaContabile::whereIn('id', array_column($voci, 'id') ?: [0])->get()->keyBy('id');
        $prova = [
            'condominio_id' => $condominio->id,
            'anagrafica_id' => $primo['persona']->id,
            'ricevute'      => array_map(fn ($v) => ['incasso_id' => $v['id'], 'dati' => $this->dati($v['sopra_soglia'], $scelte)
                + $this->dellOriginale($originali[$v['id']] ?? null, $this->contenutoDaEmettere($incassi[$v['id']], $originali[$v['id']] ?? null))], $voci),
        ];
        try {
            Notification::route('mail', $utente->email)->notifyNow(RicevutaIncassoNotification::prova($prova));
        } catch (\Throwable $e) {
            $errore = ErroreDiPosta::daEccezione($e);
            $completo = ErroreDiPosta::completoPerChiGuarda($errore);
            throw new RicevutaNonEmettibileException('La prova non è partita: ' . lcfirst((string) ErroreDiPosta::frase($errore)) . ($completo !== null ? ' ' . mb_substr($completo, 0, 300) : ''));
        }

        return ['indirizzo' => $utente->email, 'persona' => $primo['persona']->nome, 'ricevute' => count($voci)];
    }

    /**
     * «Invia di nuovo»: la stessa ricevuta, con le stesse scelte, alla stessa persona, all'indirizzo dello stesso tipo com'è oggi nella sua
     * anagrafica, o allo stesso indirizzo scritto a mano. Una riga nuova del registro; quella di prima resta com'è.
     *
     * @return array<string, mixed> il riepilogo
     */
    public function inviaDiNuovo(Condominio $condominio, RegistroInvio $riga, ?User $utente): array
    {
        self::conServerPosta();
        if ($riga->canale !== RegistroInvio::CANALE_EMAIL || $riga->tipo !== RegistroInvio::TIPO_RICEVUTA) {
            throw new RicevutaNonEmettibileException('Si rimanda solo una ricevuta già inviata per email.');
        }
        $incasso = $this->incassi($condominio, [$riga->documentabile_id])->first();
        $annullato = $incasso->stato === 'annullata';
        // Di un incasso annullato si rimanda solo una ricevuta che era già partita, allo stesso indirizzo.
        if ($annullato && $riga->stato !== RegistroInvio::STATO_INVIATA) {
            throw new RicevutaNonEmettibileException('L\'incasso è annullato: si rimanda per email solo una ricevuta che era già partita; altrimenti si stampa.');
        }

        $dati = (array) $riga->dati;
        unset($dati['tipo_indirizzo'], $dati['gettone']);
        $originale = $this->originali([$incasso->id])[$incasso->id] ?? null;
        $dati = array_merge($dati, $this->dellOriginale($originale, $this->contenutoDaEmettere($incasso, $originale)));
        $tipo = (string) ($riga->dati['tipo_indirizzo'] ?? 'altro');
        $persona = $riga->anagrafica;
        $indirizzo = self::indirizzoDiNuovo($tipo, $riga->destinatario, $persona, $annullato);
        if (! filled($indirizzo)) {
            throw new RicevutaNonEmettibileException(($persona?->nome ?? 'La persona') . ' non ha più l\'indirizzo ' . (self::TIPI_INDIRIZZO[$tipo] ?? '') . ': scegli un altro indirizzo o stampala per la posta.');
        }

        $voce = ['id' => $incasso->id, 'numero' => $incasso->numero_protocollo];

        return $this->riepilogo($this->spedisci($condominio, [$voce], $persona, $indirizzo, $tipo, [$incasso->id => $dati], $utente, now()), 1);
    }

    /**
     * L'indirizzo a cui «Invia di nuovo» manda una riga: quello scritto a mano com'era, quello di un incasso annullato com'era allora, altrimenti
     * l'indirizzo dello stesso tipo com'è oggi nell'anagrafica della persona (nullo se non ce l'ha più). Lo legge anche la conferma, perché dica
     * dove la ricevuta va davvero.
     */
    public static function indirizzoDiNuovo(string $tipo, ?string $destinatario, ?Anagrafica $persona, bool $annullato): ?string
    {
        return $tipo === 'altro' || $annullato ? $destinatario : (self::indirizzi($persona)[$tipo] ?? null);
    }

    /**
     * «Non serve comunicarla» (decisione 205): di una ricevuta già uscita (stampata o partita) il cui incasso è stato annullato, l'amministratore
     * dice che alla persona non serve dirlo. Scrive una riga nel registro, di canale `nessuno`, con chi e quando; la ricevuta esce dall'avviso
     * delle annullate da comunicare e la riga resta fra le emissioni dell'incasso. Una seconda volta non scrive un'altra riga. Lo stesso per un
     * incasso di cui era uscito solo l'avviso di compensazione: la riga è del tipo dell'avviso, e lo decide l'amministratore caso per caso.
     *
     * @return array{segnata: bool}
     */
    public function nonServeComunicarla(ScritturaContabile $incasso, ?User $utente): array
    {
        if ($incasso->stato !== 'annullata') {
            throw new RicevutaNonEmettibileException('L\'incasso non è annullato: non c\'è niente da comunicare.');
        }
        $sue = fn () => RegistroInvio::where('documentabile_type', ScritturaContabile::class)
            ->where('documentabile_id', $incasso->id)
            ->whereIn('tipo', [RegistroInvio::TIPO_RICEVUTA, RegistroInvio::TIPO_AVVISO_COMPENSAZIONE]);
        // La ricevuta uscita; senza, l'avviso di compensazione uscito: anche quello diceva un fatto che con l'annullamento non è più vero.
        $uscita = $sue()->where('tipo', RegistroInvio::TIPO_RICEVUTA)->whereIn('stato', RegistroInvio::STATI_USCITI)->orderBy('id')->first()
            ?? $sue()->where('tipo', RegistroInvio::TIPO_AVVISO_COMPENSAZIONE)->whereIn('stato', RegistroInvio::STATI_USCITI)->orderBy('id')->first();
        if ($uscita === null) {
            throw new RicevutaNonEmettibileException('Per questo incasso non è uscita né una ricevuta né un avviso di compensazione: non c\'è niente da comunicare.');
        }
        if (! $sue()->where('stato', RegistroInvio::STATO_NON_DA_COMUNICARE)->exists()) {
            RegistroInvio::create([
                'condominio_id'      => $incasso->condominio_id,
                'anagrafica_id'      => $uscita->anagrafica_id,
                'tipo'               => $uscita->tipo,
                'canale'             => RegistroInvio::CANALE_NESSUNO,
                'documentabile_type' => ScritturaContabile::class,
                'documentabile_id'   => $incasso->id,
                'numero'             => $incasso->numero_protocollo,
                'stato'              => RegistroInvio::STATO_NON_DA_COMUNICARE,
                'emesso_da'          => $utente?->id,
                'emesso_il'          => now(),
            ]);
        }

        return ['segnata' => true];
    }

    /**
     * «Riprova le non partite»: rimanda, con le stesse scelte, gli invii che {@see daRiprovare()} trova. Solo gli incassi dati, o tutti quelli
     * del condominio.
     *
     * @param array<int, int>|null $ids
     * @return array<string, mixed> il riepilogo
     */
    public function riprovaLeNonPartite(Condominio $condominio, ?array $ids, ?User $utente): array
    {
        self::conServerPosta();
        $tutte = $this->daRiprovare($condominio, $ids);
        $tetto = self::tettoMessaggi();
        $messaggi = [];
        $persone = [];
        $trattenute = 0;
        foreach ($tetto === null ? $tutte : $tutte->take($tetto) as $riga) {
            // Un indirizzo scritto a mano conta nel limite di dieci al minuto: gli invii oltre il limite restano da riprovare.
            if (LimiteAltroIndirizzo::aMano($riga) && ! LimiteAltroIndirizzo::consuma($utente)) {
                $trattenute++;

                continue;
            }
            try {
                $esito = $this->inviaDiNuovo($condominio, $riga, $utente);
                $messaggi = array_merge($messaggi, $esito['messaggi_righe']);
                $persone[$riga->anagrafica_id ?? 0] = true;
            } catch (RicevutaNonEmettibileException) {
                continue;
            }
        }

        return ['restanti' => $tetto !== null && $tutte->count() > $tetto ? ['messaggi' => $tutte->count() - $tetto] : null,
            // Gli invii a indirizzi scritti a mano che il limite di dieci al minuto ha fermato: restano non partiti, e si riprovano fra poco.
            'limite_altro_indirizzo' => $trattenute]
            + $this->riepilogo($messaggi, count($persone));
    }

    /**
     * Gli invii per email da riprovare. Per ogni ricevuta, persona e indirizzo l'ultima emissione per email, se non è partita e si può ancora
     * rimandare: l'incasso non è annullato, e la persona ha ancora un indirizzo di quel tipo. L'indirizzo è il tipo (primaria, secondaria,
     * PEC), non il testo: se l'amministratore ha corretto l'email e la ricevuta è ripartita, l'ultima emissione di quel tipo è partita, e
     * non c'è più niente da riprovare. Un indirizzo scritto a mano è quell'indirizzo. Se dopo l'invio non partito la ricevuta è uscita in un
     * PDF (stampata, o preparata per l'email che l'amministratore manda dal suo programma di posta, o nell'archivio per persona), la persona
     * l'ha avuta: l'invio non partito non si riprova, perché non le arrivi due volte. La persona è quella della riga del PDF, la posizione, e
     * anche la riga di un invio a un indirizzo scritto a mano la porta (`inviaUna` la scrive con la persona della posizione): quel PDF spegne
     * l'invio non partito della posizione e quello al suo indirizzo scritto a mano; chi ha versato per lei, con quel PDF, non ha avuto niente, e il
     * suo invio non partito resta da riprovare. Per rimandare un indirizzo scritto a mano dopo un PDF c'è «Invia di nuovo» sulla sua riga.
     * La stessa regola conta l'avviso della pagina delle ricevute e il pulsante della pagina dell'incasso.
     *
     * @param array<int, int>|null $ids
     * @return Collection<int, RegistroInvio>
     */
    public function daRiprovare(Condominio $condominio, ?array $ids = null): Collection
    {
        $ultime = RegistroInvio::query()
            ->where('condominio_id', $condominio->id)
            ->where('tipo', RegistroInvio::TIPO_RICEVUTA)
            ->where('canale', RegistroInvio::CANALE_EMAIL)
            ->when($ids !== null, fn ($q) => $q->whereIn('documentabile_id', $ids === [] ? [0] : $ids))
            ->orderBy('id')
            ->get(['id', 'documentabile_id', 'anagrafica_id', 'destinatario', 'stato', 'dati->tipo_indirizzo as tipo_indirizzo'])
            ->groupBy(fn (RegistroInvio $r) => $r->documentabile_id . '|' . $r->anagrafica_id . '|' . $r->tipo_indirizzo
                . '|' . (in_array($r->tipo_indirizzo, array_keys(self::TIPI_INDIRIZZO), true) ? '' : $r->destinatario))
            ->map(fn (Collection $righe) => $righe->last())
            ->filter(fn (RegistroInvio $r) => $r->stato === RegistroInvio::STATO_NON_PARTITA)
            ->pluck('id');
        if ($ultime->isEmpty()) {
            return collect();
        }

        $righe = RegistroInvio::with(['documentabile', 'anagrafica'])->whereIn('id', $ultime->all())->orderBy('id')->get();
        // L'ultimo PDF uscito di ogni ricevuta e di ogni persona: un invio non partito prima di quel PDF, alla stessa persona, non si riprova.
        // La riga di un PDF ha la persona della posizione, e con lei la riga di un invio a un indirizzo scritto a mano: il PDF le spegne
        // tutte e due. Chi ha versato per lei il PDF non lo ha avuto, e il suo invio non partito resta.
        $ultimoPdf = RegistroInvio::query()
            ->where('condominio_id', $condominio->id)
            ->where('tipo', RegistroInvio::TIPO_RICEVUTA)
            ->where('canale', RegistroInvio::CANALE_STAMPA)
            ->where('stato', RegistroInvio::STATO_STAMPATA)
            ->where('documentabile_type', ScritturaContabile::class)
            ->whereIn('documentabile_id', $righe->pluck('documentabile_id')->unique()->all())
            ->groupBy('documentabile_id', 'anagrafica_id')
            ->selectRaw('documentabile_id, anagrafica_id, max(id) as ultimo')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->documentabile_id . '|' . $r->anagrafica_id => (int) $r->ultimo]);

        return $righe
            ->filter(function (RegistroInvio $r) use ($ultimoPdf) {
                if ($r->documentabile === null || $r->documentabile->stato === 'annullata'
                    || $r->id < ($ultimoPdf[$r->documentabile_id . '|' . $r->anagrafica_id] ?? 0)) {
                    return false;
                }
                $tipo = (string) ($r->dati['tipo_indirizzo'] ?? 'altro');

                return ! isset(self::TIPI_INDIRIZZO[$tipo]) || isset(self::indirizzi($r->anagrafica)[$tipo]);
            })
            ->values();
    }

    /**
     * Prima dell'invio o della stampa in blocco: quante ricevute, quante sopra e sotto la soglia del bollo, a quante persone e a quanti indirizzi
     * partirebbero con i tipi scelti, chi resta senza indirizzo (e chi ha solo la PEC non scelta), quante sono già state inviate o sono in
     * coda, quante sono di versamenti più vecchi di 60 giorni, chi non ha un indirizzo postale, se si supera il tetto di un PDF. Con
     * `$escludiGiaInviate` i conteggi sono quelli dopo l'esclusione, come l'invio la farà; `gia_inviate` le conta prima, per la casella. Con più
     * ricevute del tetto di un PDF le parti seguono l'ordine scelto per la stampa (`$ordine`: persona, unita o data), dalla prima all'ultima.
     *
     * @param array<int, int> $ids
     * @param array<int, string> $tipiScelti
     * @return array<string, mixed>
     */
    public function anteprima(Condominio $condominio, array $ids, bool $ancheChiHaVersato, array $tipiScelti, bool $escludiGiaInviate = false, string $ordine = 'persona'): array
    {
        $this->incassi($condominio, $ids);
        // Nell'anteprima nessun tipo scelto non è un errore: non parte niente, e i conteggi lo dicono.
        $tipiScelti = array_values(array_intersect(array_keys(self::TIPI_INDIRIZZO), self::tipiComeTesti($tipiScelti)));
        $tutte = $this->elenco->righe($condominio, ['ricevuta' => 'tutte'], $ids);
        $gia = $tutte->where('inviata', true);
        $righeElenco = $escludiGiaInviate ? $tutte->where('inviata', false)->values() : $tutte;
        $piano = $this->pianoDelBlocco($righeElenco, $ancheChiHaVersato, $tipiScelti);

        $chiHaVersato = $righeElenco->filter(fn ($r) => $r['versato_da'] !== null)->pluck('versato_da.id')->unique()->values();
        $conIndirizzo = Anagrafica::whereIn('id', $chiHaVersato)->get()->filter(fn (Anagrafica $a) => self::indirizzi($a) !== [])->pluck('id')->all();
        $posizioni = Anagrafica::whereIn('id', $righeElenco->pluck('posizione.id')->filter()->unique())->get(['id', 'nome', 'indirizzo']);
        $limite = now()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->subDays(60)->format('Y-m-d');

        $esclusi = $this->elenco->esclusi($condominio, $ids);
        // Le causali delle selezionate (un testo libero, che «Stampa la causale» scriverebbe sulla ricevuta): quante e quali.
        $causali = [];
        foreach (array_chunk($righeElenco->pluck('id')->all(), 500) as $blocco) {
            foreach (ScritturaContabile::whereIn('id', $blocco)->whereNotNull('causale')->where('causale', '!=', '')
                ->groupBy('causale')->selectRaw('causale, COUNT(*) as quante')->pluck('quante', 'causale') as $testo => $quante) {
                $causali[(string) $testo] = ($causali[(string) $testo] ?? 0) + (int) $quante;
            }
        }

        return [
            'senza_server_posta' => self::senzaServerPosta(),
            'ricevute'          => $righeElenco->count(),
            'annullati'         => $esclusi['annullati'],
            'senza_versamento'  => $esclusi['senza_versamento'],
            'piu_persone'       => $esclusi['piu_persone'],
            'sopra_soglia'      => $righeElenco->where('sopra_soglia', true)->count(),
            'sotto_soglia'      => $righeElenco->where('sopra_soglia', false)->count(),
            'email'             => [
                'persone'   => count($piano['gruppi']),
                'ricevute'  => array_sum(array_map(fn ($g) => count($g['voci']), $piano['gruppi'])),
                'indirizzi' => array_sum(array_map(fn ($g) => count($g['indirizzi']), $piano['gruppi'])),
                'messaggi'  => array_sum(array_map(fn ($g) => self::messaggiDi($g), $piano['gruppi'])),
                // Quanti partono adesso: con il tetto, le persone intere che ci stanno (l'invio taglia con la stessa regola).
                'messaggi_adesso' => array_sum(array_map(fn ($g) => self::messaggiDi($g), self::dentroIlTetto($piano['gruppi'])[0])),
            ],
            // Chi riceve che cosa: per persona, gli indirizzi, i numeri delle ricevute e i messaggi.
            'persone'           => array_map(fn ($g) => [
                'id'        => (int) $g['persona']->id,
                'nome'      => $g['persona']->nome,
                'indirizzi' => collect($g['indirizzi'])->map(fn (string $indirizzo, string $tipo) => ['tipo' => $tipo, 'indirizzo' => $indirizzo])->values()->all(),
                'ricevute'  => array_map(fn ($v) => [
                    'numero'       => $v['numero'],
                    'per_conto_di' => ($v['posizione']['id'] ?? null) !== (int) $g['persona']->id ? ($v['posizione']['nome'] ?? null) : null,
                ], $g['voci']),
                'messaggi'  => self::messaggiDi($g),
            ], $piano['gruppi']),
            'con_causale'       => array_sum($causali),
            'causali'           => collect($causali)->map(fn (int $quante, string $testo) => ['testo' => $testo, 'quante' => $quante])->sortByDesc('quante')->take(10)->values()->all(),
            'parti_pdf'         => $righeElenco->count() > self::TETTO_PDF ? $this->partiDelPdf($righeElenco, $ordine) : [],
            'tetto_messaggi'    => self::tettoMessaggi(),
            'senza_indirizzo'   => ['ricevute' => count($piano['senza_incassi']), 'incassi' => $piano['senza_incassi'], 'persone' => $piano['senza_persone']],
            'solo_pec'          => $piano['altri_indirizzi']['pec'],
            'altri_indirizzi'   => $piano['altri_indirizzi'],
            'gia_inviate'       => ['ricevute' => $gia->count(), 'incassi' => $gia->pluck('id')->values()->all()],
            // Le già emesse escono come «Copia», con le cifre dell'originale.
            'copie'             => count($this->originali($righeElenco->pluck('id')->all())),
            'piu_vecchie'       => $righeElenco->filter(fn ($r) => $r['data'] !== null && $r['data'] < $limite)->count(),
            'senza_indirizzo_postale' => $posizioni->filter(fn ($a) => ! filled($a->indirizzo))->pluck('nome')->values()->all(),
            'chi_ha_versato'    => $righeElenco->filter(fn ($r) => $r['versato_da'] !== null && in_array($r['versato_da']['id'], $conIndirizzo, true))->count(),
            'oltre_tetto'       => $righeElenco->count() > self::TETTO_PDF,
            'tetto'             => self::TETTO_PDF,
        ];
    }

    /**
     * Le scelte fatte l'ultima volta da questo utente in questo condominio, per le cose che si ripetono: il bollo per le ricevute sopra la soglia
     * e per le altre; «assolta» solo se il bollo è assolto in modo virtuale, perché un'autorizzazione vale per tutte le ricevute, mentre la marca
     * di un contrassegno è su un foglio solo e dopo un contrassegno non si propone niente; gli estremi solo del modo virtuale; «Stampa la
     * causale» (decisione 202); i tipi di indirizzo dell'ultimo invio in blocco. La nota non si ricorda: è della ricevuta su cui si scrive
     * (decisione 204). La prima volta nessuna scelta: il bollo non è scelto, la causale non si stampa e in blocco la pagina spunta la primaria e
     * la secondaria.
     *
     * @return array{bollo_sopra: ?string, bollo_sotto: ?string, modo_bollo: ?string, estremi_bollo: ?string, stampa_causale: ?bool, indirizzi: ?array<int, string>}
     */
    public function sceltaRicordata(Condominio $condominio, ?User $utente): array
    {
        $vuota = ['bollo_sopra' => null, 'bollo_sotto' => null, 'modo_bollo' => null, 'estremi_bollo' => null, 'stampa_causale' => null, 'indirizzi' => null];
        if ($utente === null) {
            return $vuota;
        }

        $sue = fn () => RegistroInvio::where('condominio_id', $condominio->id)
            ->where('tipo', RegistroInvio::TIPO_RICEVUTA)
            ->where('emesso_da', $utente->id)
            ->orderByDesc('id');
        $stato = function (string $soglia) use ($sue): ?string {
            $dati = $sue()->where('dati->soglia', $soglia)->first()?->dati;
            $bollo = $dati['bollo'] ?? null;

            return $bollo === 'assolta' ? (($dati['modo_bollo'] ?? null) === 'virtuale' ? 'assolta' : null) : $bollo;
        };
        $sopra = $stato('sopra');
        $sotto = $stato('sotto');
        $virtuale = $sopra === 'assolta' || $sotto === 'assolta';
        $causale = $sue()->whereNotNull('dati->stampa_causale')->first();

        return [
            'bollo_sopra'    => $sopra,
            'bollo_sotto'    => $sotto,
            'modo_bollo'     => $virtuale ? 'virtuale' : null,
            'estremi_bollo'  => $virtuale ? ($sue()->where('dati->modo_bollo', 'virtuale')->first()?->dati['estremi_bollo'] ?? null) : null,
            'stampa_causale' => $causale !== null ? (bool) $causale->dati['stampa_causale'] : null,
            'indirizzi'      => $sue()->whereNotNull('dati->tipi_scelti')->first()?->dati['tipi_scelti'] ?? null,
        ];
    }

    /**
     * Gli indirizzi di una persona, per tipo: la primaria, la secondaria, la PEC, quelli che ci sono. La PEC il programma la manda come email
     * semplice dal server configurato, non come PEC.
     *
     * @return array<string, string>
     */
    public static function indirizzi(?Anagrafica $persona): array
    {
        if ($persona === null) {
            return [];
        }

        $lista = [];
        foreach (array_keys(self::TIPI_INDIRIZZO) as $tipo) {
            $valore = trim((string) $persona->{$tipo});
            if ($valore !== '' && ! in_array($valore, $lista, true)) {
                $lista[$tipo] = $valore;
            }
        }

        return $lista;
    }

    // ── Interni ──────────────────────────────────────────────────────────────

    /**
     * I messaggi di un invio a un indirizzo: le righe del registro, in coda, una per ricevuta, e la notifica che le porta (al più
     * {@see RICEVUTE_PER_MESSAGGIO} ricevute per messaggio; sopra se ne fanno più d'uno). Il messaggio va all'indirizzo scelto, non agli
     * indirizzi che la persona ha per le altre notifiche. Se l'accodamento fallisce (con la coda `sync` è l'invio stesso) le righe restano
     * `non_partita` con l'errore, e il blocco continua.
     *
     * @param array<int, array{id: int, numero: ?string}> $voci
     * @param array<int, array<string, mixed>> $datiPerIncasso
     * @return array<int, array<int, int>> un elenco di righe per messaggio
     */
    private function spedisci(Condominio $condominio, array $voci, ?Anagrafica $persona, string $indirizzo, string $tipo, array $datiPerIncasso, ?User $utente, CarbonInterface $adesso): array
    {
        $messaggi = [];
        foreach (array_chunk($voci, self::RICEVUTE_PER_MESSAGGIO) as $blocco) {
            $ids = [];
            // Il gettone lega le righe al loro messaggio: è l'identificativo della notifica, che il registro delle email rilegge quando il server
            // accetta il messaggio. Non porta gli identificativi del registro a chi riceve.
            $gettone = (string) Str::uuid();
            foreach ($blocco as $v) {
                $ids[] = RegistroInvio::create([
                    'condominio_id'      => $condominio->id,
                    'anagrafica_id'      => $persona?->id,
                    'tipo'               => RegistroInvio::TIPO_RICEVUTA,
                    'canale'             => RegistroInvio::CANALE_EMAIL,
                    'documentabile_type' => ScritturaContabile::class,
                    'documentabile_id'   => $v['id'],
                    'numero'             => $v['numero'],
                    'destinatario'       => $indirizzo,
                    'dati'               => self::senzaVuoti(array_merge($datiPerIncasso[$v['id']], ['tipo_indirizzo' => $tipo, 'gettone' => $gettone])),
                    'stato'              => RegistroInvio::STATO_IN_CODA,
                    'emesso_da'          => $utente?->id,
                    'emesso_il'          => $adesso,
                ])->id;
            }

            try {
                $notifica = new RicevutaIncassoNotification($ids);
                $notifica->id = $gettone;
                Notification::route('mail', $indirizzo)->notify($notifica);
            } catch (\Throwable $e) {
                Log::warning('Ricevuta non partita: ' . $e->getMessage(), ['invii' => $ids]);
                RegistroInvio::whereIn('id', $ids)->where('stato', RegistroInvio::STATO_IN_CODA)->update([
                    'stato'  => RegistroInvio::STATO_NON_PARTITA,
                    'errore' => ErroreDiPosta::daEccezione($e),
                ]);
            }
            $messaggi[] = $ids;
        }

        return $messaggi;
    }

    /**
     * Il piano di un invio in blocco: le persone in ordine di nome, ognuna con le sue ricevute (quelle di cui è la posizione e, se
     * l'amministratore lo sceglie, quelle che ha versato per conto di altri) e i suoi indirizzi dei tipi scelti. Chi non ne ha nessuno fra
     * quelli scelti, per le ricevute di cui è la posizione, va fra quelle da stampare; `altri_indirizzi` conta, per tipo, chi resta fuori ma
     * ha un indirizzo di un tipo non scelto (la secondaria, la PEC): spuntandolo lo si raggiunge.
     *
     * @param array<int, string> $tipiScelti
     * @return array{gruppi: array<int, array{persona: Anagrafica, voci: array<int, array<string, mixed>>, indirizzi: array<string, string>}>, senza_incassi: array<int, int>, senza_persone: array<int, string>, altri_indirizzi: array<string, int>}
     */
    private function pianoDelBlocco(Collection $righeElenco, bool $ancheChiHaVersato, array $tipiScelti): array
    {
        $perPersona = [];
        foreach ($righeElenco as $r) {
            if ($r['posizione']) {
                $perPersona[$r['posizione']['id']][$r['id']] = $r;
            }
            if ($ancheChiHaVersato && $r['versato_da']) {
                $perPersona[$r['versato_da']['id']][$r['id']] = $r;
            }
        }

        $gruppi = [];
        $senza = [];
        $nomiSenza = [];
        $altri = array_fill_keys(array_keys(self::TIPI_INDIRIZZO), 0);
        $persone = Anagrafica::whereIn('id', array_keys($perPersona))->get()->sortBy(fn (Anagrafica $a) => [mb_strtolower($a->nome), $a->id]);
        foreach ($persone as $persona) {
            $voci = collect($perPersona[$persona->id])->sortBy(fn ($r) => [$r['data'], $r['id']])->values()->all();
            $tutti = self::indirizzi($persona);
            $suoi = array_intersect_key($tutti, array_flip($tipiScelti));
            if ($suoi !== []) {
                $gruppi[] = ['persona' => $persona, 'voci' => $voci, 'indirizzi' => $suoi];

                continue;
            }
            $posizioni = array_values(array_filter($voci, fn ($v) => ($v['posizione']['id'] ?? null) === $persona->id));
            if ($posizioni !== []) {
                $senza = array_merge($senza, array_column($posizioni, 'id'));
                $nomiSenza[] = $persona->nome;
                foreach (array_keys($tutti) as $tipo) {
                    $altri[$tipo]++;
                }
            }
        }

        return ['gruppi' => $gruppi, 'senza_incassi' => array_values(array_unique($senza)), 'senza_persone' => $nomiSenza, 'altri_indirizzi' => $altri];
    }

    /**
     * @param array<int, mixed> $tipi
     * @return array<int, string>
     */
    private function tipiScelti(array $tipi): array
    {
        $scelti = array_values(array_intersect(array_keys(self::TIPI_INDIRIZZO), self::tipiComeTesti($tipi)));
        if ($scelti === []) {
            throw new RicevutaNonEmettibileException('Scegli a quali indirizzi mandare le ricevute: primaria, secondaria, PEC.');
        }

        return $scelti;
    }

    /**
     * Il riepilogo di un invio, per messaggio: quanti sono partiti (il server li ha accettati), quanti sono in coda, quanti non sono partiti e
     * perché; le persone raggiunte, le ricevute, chi resta da stampare per la posta.
     *
     * @param array<int, array<int, int>> $messaggi
     * @param array<int, int> $senzaIndirizzo gli incassi da stampare per la posta
     * @param array<int, string> $nomiSenza
     * @return array<string, mixed>
     */
    private function riepilogo(array $messaggi, int $persone, array $senzaIndirizzo = [], array $nomiSenza = [], array $esclusi = ['annullati' => 0, 'senza_versamento' => 0, 'piu_persone' => 0]): array
    {
        $ids = array_merge([], ...$messaggi);
        $scritte = RegistroInvio::whereIn('id', $ids === [] ? [0] : $ids)->get(['id', 'stato', 'errore', 'documentabile_id'])->keyBy('id');
        $statoDi = fn (array $righe) => $scritte[$righe[0]]->stato ?? null;
        $errori = $scritte->pluck('errore')->filter()->unique()->take(3)->values();
        $completi = $errori->map(fn (string $e) => ErroreDiPosta::completoPerChiGuarda($e))->filter()->values();

        return [
            'persone'                 => $persone,
            'ricevute'                => $scritte->pluck('documentabile_id')->unique()->count(),
            'messaggi'                => count($messaggi),
            'inviate'                 => count(array_filter($messaggi, fn ($m) => $statoDi($m) === RegistroInvio::STATO_INVIATA)),
            'in_coda'                 => count(array_filter($messaggi, fn ($m) => $statoDi($m) === RegistroInvio::STATO_IN_CODA)),
            'non_partite'             => count(array_filter($messaggi, fn ($m) => $statoDi($m) === RegistroInvio::STATO_NON_PARTITA)),
            // La frase che leggono tutti; l'errore intero del server, solo per chi gestisce le impostazioni.
            'errori'                  => $errori->map(fn (string $e) => ErroreDiPosta::frase($e))->unique()->values()->all(),
            'senza_indirizzo'         => count($senzaIndirizzo),
            'senza_indirizzo_incassi' => $senzaIndirizzo,
            'senza_indirizzo_persone' => $nomiSenza,
            'annullati'               => $esclusi['annullati'],
            'senza_versamento'        => $esclusi['senza_versamento'],
            'piu_persone'             => $esclusi['piu_persone'],
            'messaggi_righe'          => $messaggi,
            'tetto_messaggi'          => self::tettoMessaggi(),
            'restanti'                => null,
        ] + ($completi->isNotEmpty() ? ['errori_completi' => $completi->all()] : []);
    }

    /** Il tetto dei messaggi di un invio: con la coda `sync` (le email partono dentro la richiesta) {@see TETTO_MESSAGGI_SENZA_CODA}, altrimenti nessuno. */
    public static function tettoMessaggi(): ?int
    {
        return config('queue.connections.' . config('queue.default') . '.driver') === 'sync' ? self::TETTO_MESSAGGI_SENZA_CODA : null;
    }

    /**
     * Le ricevute in più PDF quando sono più del tetto: le persone nell'ordine scelto per la stampa (lo stesso che vale dentro ogni parte, {@see
     * gruppiPerBusta()}), ognuna intera in una parte (una sola persona con più ricevute del tetto si divide da sé). Ogni parte sta dentro
     * {@see TETTO_PDF}: si stampa una per volta, e la prima parte porta le prime persone dell'ordine, l'ultima le ultime.
     *
     * @param Collection<int, array<string, mixed>> $righe
     * @return array<int, array<int, int>>
     */
    private function partiDelPdf(Collection $righe, string $ordine): array
    {
        $unita = $ordine === 'unita' ? $this->unitaDelleRicevute($righe) : [];
        $leggere = $righe->map(fn (array $r) => [
            'incasso_id'      => $r['id'],
            'data_versamento' => $r['data'],
            'posizione'       => $r['posizione'],
            'quote'           => isset($unita[$r['id']]) ? [['unita' => $unita[$r['id']]]] : [],
        ]);

        return InviiPerPersona::partiPerPersone(
            array_map(fn ($gruppo) => $gruppo->pluck('incasso_id')->all(), self::gruppiPerBusta($leggere, $ordine)),
            self::TETTO_PDF,
        );
    }

    /**
     * L'unità della prima quota di ogni ricevuta, come la ricevuta la scrive (la prima per scadenza e numero di rata, con il denaro dell'incasso,
     * senza la parte di chi ha versato): la chiave dell'ordine per unità, letta con una query per blocco e senza costruire le ricevute.
     *
     * @param Collection<int, array<string, mixed>> $righe
     * @return array<int, string> incasso => l'etichetta dell'unità
     */
    private function unitaDelleRicevute(Collection $righe): array
    {
        $versatoDa = $righe->mapWithKeys(fn (array $r) => [$r['id'] => $r['versato_da']['id'] ?? null]);
        $prime = [];
        foreach (array_chunk($righe->pluck('id')->all(), 500) as $blocco) {
            $quote = DB::table('quota_scrittura as qs')
                ->join('rate_quote as q', 'q.id', '=', 'qs.rate_quota_id')
                ->join('rate as r', 'r.id', '=', 'q.rata_id')
                ->whereIn('qs.scrittura_contabile_id', $blocco)
                ->where('qs.importo_pagato', '>', 0)
                ->where('q.importo', '>', 0)
                ->orderBy('r.data_scadenza')->orderBy('r.numero_rata')->orderBy('q.id')
                ->get(['qs.scrittura_contabile_id as incasso', 'q.immobile_id', 'q.anagrafica_id']);
            foreach ($quote as $q) {
                $incasso = (int) $q->incasso;
                if (! isset($prime[$incasso]) && $q->immobile_id !== null && (int) $q->anagrafica_id !== (int) ($versatoDa[$incasso] ?? 0)) {
                    $prime[$incasso] = (int) $q->immobile_id;
                }
            }
        }
        $etichette = \App\Models\Immobile::whereIn('id', array_values(array_unique($prime)) ?: [0])->get()->mapWithKeys(fn ($i) => [$i->id => $i->etichetta]);

        return array_map(fn (int $immobile) => (string) ($etichette[$immobile] ?? ''), array_filter($prime, fn (int $immobile) => isset($etichette[$immobile])));
    }

    /** I messaggi di una persona del blocco: uno per indirizzo, e per ogni {@see RICEVUTE_PER_MESSAGGIO} ricevute. */
    private static function messaggiDi(array $gruppo): int
    {
        return count($gruppo['indirizzi']) * (int) ceil(count($gruppo['voci']) / self::RICEVUTE_PER_MESSAGGIO);
    }

    /**
     * Le persone del blocco che stanno nel tetto dei messaggi, nell'ordine dei nomi, e quelle che restano: una persona intera o niente, così
     * nessuno riceve la ricevuta a un indirizzo e non all'altro. La prima persona c'è sempre.
     *
     * @param array<int, array<string, mixed>> $gruppi
     * @return array{0: array<int, array<string, mixed>>, 1: ?array{persone: array<int, int>, incassi: array<int, int>, ricevute: int, messaggi: int}}
     */
    private static function dentroIlTetto(array $gruppi): array
    {
        [$dentro, $fuori] = InviiPerPersona::dentroIlTetto($gruppi, self::tettoMessaggi(), fn (array $g) => self::messaggiDi($g));
        if ($fuori === null) {
            return [$dentro, null];
        }
        $incassi = array_values(array_unique(array_merge(...array_map(fn ($r) => array_column($r['voci'], 'id'), $fuori))));

        return [$dentro, [
            'persone'  => array_map(fn ($r) => (int) $r['persona']->id, $fuori),
            'incassi'  => $incassi,
            'ricevute' => count($incassi),
            'messaggi' => array_sum(array_map(fn ($r) => self::messaggiDi($r), $fuori)),
        ]];
    }

    /** Il server di posta c'è? Con il trasporto `log` no: il messaggio non parte. È la regola di `LogSentMessage`. */
    public static function senzaServerPosta(): bool
    {
        return config('mail.default') === 'log';
    }

    /** Un campo che deve essere un testo: un elenco o un oggetto (la richiesta può mandare qualunque cosa) si rifiuta, non si converte. */
    private static function comeTesto(mixed $valore, string $campo): string
    {
        if ($valore === null) {
            return '';
        }
        if (! is_scalar($valore)) {
            throw new RicevutaNonEmettibileException($campo . ' deve essere un testo.');
        }

        return (string) $valore;
    }

    /**
     * I tipi di indirizzo scelti: una lista di testi.
     *
     * @return array<int, string>
     */
    private static function tipiComeTesti(mixed $tipi): array
    {
        $tipi = (array) $tipi;
        foreach ($tipi as $tipo) {
            if (! is_scalar($tipo)) {
                throw new RicevutaNonEmettibileException('Gli indirizzi scelti devono essere testi: primaria, secondaria, PEC.');
            }
        }

        return array_map('strval', array_values($tipi));
    }

    private function identificativo(mixed $valore): int
    {
        return is_numeric($valore) ? (int) $valore : 0;
    }

    private static function conServerPosta(): void
    {
        if (self::senzaServerPosta()) {
            throw new RicevutaNonEmettibileException(self::SENZA_SERVER_POSTA);
        }
    }

    /**
     * Una ricevuta di pagamento c'è solo se è entrato denaro (un incasso a cassa zero è una compensazione) e se l'incasso ha una posizione sola:
     * le rate di più persone senza «Versato da» non dicono per conto di chi.
     */
    private static function emettibile(array $contenuto): void
    {
        if ((int) $contenuto['importo_versato'] <= 0) {
            throw new RicevutaNonEmettibileException(ElencoRicevute::MOTIVO_SENZA_VERSAMENTO);
        }
        if (! empty($contenuto['piu_persone'])) {
            throw new RicevutaNonEmettibileException(ElencoRicevute::motivoPiuPersone(array_column($contenuto['piu_persone'], 'nome')));
        }
    }

    /**
     * Lo stato del bollo per una ricevuta sopra o sotto la soglia: quello scelto, o il rifiuto che dice quale scelta manca.
     *
     * @param array<string, ?string> $scelte
     */
    private function bolloPer(bool $sopraSoglia, array $scelte): string
    {
        $bollo = $sopraSoglia ? $scelte['bollo_sopra'] : $scelte['bollo_sotto'];
        if (! in_array($bollo, self::STATI_BOLLO, true)) {
            throw new RicevutaNonEmettibileException($sopraSoglia
                ? 'Scegli lo stato dell\'imposta di bollo per le ricevute sopra € 77,47.'
                : 'Scegli lo stato dell\'imposta di bollo per le ricevute fino a € 77,47.');
        }

        return $bollo;
    }

    /**
     * Le scelte di un'emissione, come le scrive il registro: il modo e gli estremi del bollo solo con «assolta».
     *
     * @param array<string, mixed> $scelte
     * @return array{bollo: string, modo_bollo: ?string, estremi_bollo: ?string, nota: ?string, stampa_causale: bool, soglia: string}
     */
    private function dati(bool $sopraSoglia, array $scelte): array
    {
        $bollo = $this->bolloPer($sopraSoglia, $scelte);

        return [
            'bollo'          => $bollo,
            'modo_bollo'     => $bollo === 'assolta' ? ($scelte['modo_bollo'] ?? null) : null,
            'estremi_bollo'  => $bollo === 'assolta' ? ($scelte['estremi_bollo'] ?? null) : null,
            'nota'           => $scelte['nota'] ?? null,
            'stampa_causale' => (bool) ($scelte['stampa_causale'] ?? false),
            'soglia'         => $sopraSoglia ? 'sopra' : 'sotto',
        ];
    }

    /**
     * Il numero di un contrassegno è di una ricevuta sola: in blocco, su più ricevute con il bollo assolto, la stessa marca sarebbe un
     * documento sbagliato. L'autorizzazione del modo virtuale invece vale per tutte.
     *
     * @param array<int, bool> $sopraSoglia una voce per ricevuta
     * @param array<string, mixed> $scelte
     */
    private function unContrassegnoPerRicevuta(array $sopraSoglia, array $scelte): void
    {
        if (($scelte['modo_bollo'] ?? null) !== 'contrassegno' || ($scelte['estremi_bollo'] ?? null) === null) {
            return;
        }
        $assolte = count(array_filter($sopraSoglia, fn (bool $sopra) => ($sopra ? $scelte['bollo_sopra'] : $scelte['bollo_sotto']) === 'assolta'));
        if ($assolte > 1) {
            throw new RicevutaNonEmettibileException('Il numero del contrassegno è diverso per ogni ricevuta: in blocco non si scrive. Lascialo vuoto e '
                . 'scrivilo sulle ricevute stampate, o emetti ogni ricevuta dalla sua finestra; in modo virtuale l\'autorizzazione vale per tutte.');
        }
    }

    /** @param array<int, array<string, mixed>> $voci */
    private function rendi(Condominio $condominio, array $voci, ?CarbonInterface $emissione): string
    {
        $mpdf = $this->pdf->generate('pdf.gestionale.ricevute_incasso', [
            'condominio'            => $condominio,
            'ricevute'              => $voci,
            'data_emissione_stampe' => ($emissione ?? now())->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome')),
        ], ['margin_top' => 14]); // niente intestazione ripetuta: la ricevuta porta il suo mittente, e il contenuto parte in alto
        $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

        // mPDF tiene dei riferimenti circolari: la sua memoria (qualche MB per ogni PDF, con i caratteri) torna libera solo alla raccolta. Senza,
        // l'archivio con un PDF per persona a 200 persone chiedeva oltre 120 MB più della partenza (ora circa 50), contro i 128 MB che PHP ha di solito.
        unset($mpdf);
        gc_collect_cycles();

        return $pdf;
    }

    /** @param array<int, array<string, mixed>> $contenuti */
    private static function nomeFile(Condominio $condominio, array $contenuti, bool $anteprima = false): string
    {
        $prefisso = $anteprima ? 'anteprima-' : '';

        return count($contenuti) === 1
            ? PdfService::nomeFile($prefisso . 'ricevuta', $condominio->nome, null, Str::slug((string) $contenuti[0]['numero']))
            : PdfService::nomeFile($prefisso . 'ricevute', $condominio->nome, now()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d'));
    }

    private static function giorno(?CarbonInterface $quando): string
    {
        return ($quando ?? now())->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y');
    }
}
