<?php

namespace App\Actions\Subentro;

use App\Enums\EventoTipo;
use App\Services\Documenti\ArchivioDocumenti;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\Gestionale\EventiRataCondomino;
use App\Services\Gestionale\InboxService;
use App\Services\Subentro\AnteprimaPassaggio;
use App\Services\Subentro\GuardieTitolarita;
use App\Traits\HasEsercizio;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Registra passaggio»: la scrittura (B2, 1.11.0-beta.31, S5). Chiude il periodo di chi esce a
 * decorrenza − 1, apre quello di chi entra, scrive `subentri` e — se il pannello ha proposto un
 * conguaglio e l'amministratore non vi ha rinunciato — la coppia in `saldi` a somma zero (D9).
 *
 * **Anteprima = scrittura.** L'action ricalcola `AnteprimaPassaggio::calcola()` **dentro la transazione**,
 * dopo il lock sulla riga di chi esce, e scrive esattamente quei numeri: un solo calcolo
 * (`ConguaglioPassaggio` → `ProRataTemporis`), nessuna seconda aritmetica. Nella stessa lettura ricalcola
 * il cancello (1) sul server: se scatta, `ho_letto` e una nota di almeno dieci caratteri sono
 * obbligatori qui, non solo nel browser (decisione 14).
 *
 * Che cosa tocca, per tipo (§6 di `docs/pertinenze_vendita_locazione.md`, decisioni A e B del 19/09):
 * - **vendita**: chiude il proprietario uscente; apre l'entrante con la quota del modulo. Se l'entrante è
 *   **già** titolare con lo stesso ruolo (il comproprietario che compra l'altra metà), la sua riga si
 *   chiude e se ne apre una alla quota somma — una riga per persona e ruolo in ogni giorno (inv. 12).
 * - **inizio locazione**: nessun uscente; apre l'inquilino. La scadenza del contratto **non** va in
 *   `data_fine` (per il motore chiuderebbe, D7): resta in `subentri.data_fine_locazione` e nel promemoria.
 * - **fine locazione**: chiude l'inquilino; se c'è un nuovo inquilino lo apre.
 * - **usufrutto, costituzione**: chiude il proprietario, lo riapre come nudo proprietario alla stessa
 *   quota, apre l'usufruttuario. **Estinzione**: chiude l'usufruttuario e riapre come proprietari pieni
 *   **tutti** i nudi proprietari in corso, ciascuno alla sua quota.
 * - **pertinenze spuntate**: la stessa operazione su ogni pertinenza, con la quota che chi esce ha lì;
 *   una riga `subentri` per pertinenza, legata alla principale (`subentro_padre_id`). Se chi esce non è
 *   titolare della pertinenza alla decorrenza, l'action si ferma e lo dice: non si tira a indovinare.
 *
 * Ogni riga nuova attraversa `GuardieTitolarita` (inv. 11 e 12) sullo stato **dopo** le chiusure, e
 * `attivo` non si tocca mai (D10). Due registrazioni concorrenti sulla stessa riga: `lockForUpdate` e
 * il rifiuto se la riga risulta già chiusa da un passaggio.
 *
 * Il PDF del titolo è un `Documento` dell'unità **solo dell'amministratore** (decisione B: nessun aggancio
 * al condominio né alle anagrafiche; `is_published` falso), salvato prima del commit e cancellato dal
 * disco se la transazione fallisce. Il promemoria in agenda (`InboxService`, opt-in) si crea **dopo** il
 * commit e non annulla mai il passaggio se fallisce.
 */
final class RegistraSubentroAction
{
    use HasEsercizio;

    public function __construct(private readonly AnteprimaPassaggio $anteprima = new AnteprimaPassaggio())
    {
    }

    /**
     * @param array<string,mixed> $dati `AnteprimaPassaggioRequest::datiPassaggio()`
     * @return array{subentro: Subentro, anteprima: array<string,mixed>, coppie: int, documento: ?Documento, promemoria: ?Evento, avvisi: list<string>}
     */
    public function execute(Condominio $condominio, Immobile $immobile, array $dati, User $utente): array
    {
        $path = null;
        $avvisi = [];

        try {
            $esito = DB::transaction(function () use ($condominio, $immobile, $dati, $utente, &$path, &$avvisi) {
                $decorrenza = $dati['decorrenza'];
                $giornoPrima = $decorrenza->subDay();
                $tipo = (string) $dati['tipo'];
                $sottotipo = $dati['sottotipo'] ?? null;

                // 0. L'unità e le pertinenze spuntate, bloccate prima di ogni lettura: `AnnullaPassaggioAction` blocca le
                //    stesse righe, e un passaggio registrato mentre un altro si annulla non deve sfuggire ai suoi controlli
                //    (Fase 1-bis della beta.37, A2: su MySQL l'istantanea nasce alla prima lettura senza lock).
                Immobile::bloccaPerScrivere(collect([$immobile->id])->merge($dati['pertinenze'] ?? []));

                // 1. La riga di chi esce, bloccata e riletta: due clic non chiudono due volte.
                $uscente = null;
                if (! empty($dati['riga_uscente'])) {
                    $uscente = TitolaritaImmobile::whereKey($dati['riga_uscente']->id)->lockForUpdate()->with('anagrafica')->first();
                    if ($uscente === null || (int) $uscente->immobile_id !== (int) $immobile->id) {
                        throw ValidationException::withMessages(['riga_uscente_id' => 'Il periodo di chi esce non esiste più: ricarica la pagina.']);
                    }
                    if ($uscente->subentriComeUscente()->exists()) {
                        throw ValidationException::withMessages(['riga_uscente_id' => 'Questo periodo è già stato chiuso da un passaggio registrato: lo trovi nello storico.']);
                    }
                    if ($uscente->data_fine !== null && $uscente->data_fine->lt($giornoPrima)) {
                        throw ValidationException::withMessages(['decorrenza' => sprintf('Questo periodo si è chiuso il %s: la decorrenza non può essere successiva di più di un giorno.', $uscente->data_fine->locale('it')->translatedFormat('j F Y'))]);
                    }
                    $dati['riga_uscente'] = $uscente;
                }

                // 2. Il pannello, ricalcolato qui: è ciò che si scrive. E il cancello (1) sul server.
                $anteprima = $this->anteprima->calcola($condominio, $immobile, $dati);
                if ($anteprima['cancello']['richiesto'] && (! $dati['ho_letto'] || mb_strlen(trim((string) ($dati['nota_cancello'] ?? ''))) < 10)) {
                    throw ValidationException::withMessages(['nota_cancello' => 'Questo passaggio tocca rate già emesse o cambia un destinatario: spunta «Ho letto cosa cambierà» e scrivi perché lo registri così (almeno dieci caratteri).']);
                }

                // La rinuncia al conguaglio vale solo se il pannello ha proposto una coppia (verifica S5, R13).
                $coppieProposte = ! empty($anteprima['rate']['conguaglio']['coppie']);
                $rinuncia = $coppieProposte && ! empty($dati['rinuncia_conguaglio']);

                // 3. Le righe della pivot, unità principale.
                $entrante = $dati['entrante'] ?? null;
                $this->registroRighe = [];
                $esitoRighe = $this->applicaRighe($immobile, $tipo, $sottotipo, $uscente, $entrante, (float) $dati['quota'], (string) $dati['tipologia'], $decorrenza, $giornoPrima, 'quota');

                // 4. Il passaggio.
                $subentro = Subentro::create([
                    'condominio_id'          => $condominio->id,
                    'immobile_id'            => $immobile->id,
                    'anagrafica_uscente_id'  => $uscente?->anagrafica_id,
                    'anagrafica_entrante_id' => $esitoRighe['anagrafica_entrante_id'] ?? $entrante?->id,
                    'riga_uscente_id'        => $uscente?->id,
                    'riga_entrante_id'       => $esitoRighe['riga_entrante_id'],
                    'tipologia'              => (string) $dati['tipologia'],
                    'tipo_passaggio'         => $tipo,
                    'decorrenza'             => $decorrenza->toDateString(),
                    'data_fine_locazione'    => $dati['data_fine_locazione']?->toDateString(),
                    'regime_contratto'       => $dati['regime_contratto'] ?? null,
                    'estremi_titolo'         => $dati['estremi_titolo'] ?? null,
                    'copia_autentica_il'     => $dati['copia_autentica'] ? $dati['copia_autentica_il']?->toDateString() : null,
                    'nota'                   => $dati['nota'] ?? null,
                    'nota_cancello'          => $anteprima['cancello']['richiesto'] ? trim((string) $dati['nota_cancello']) : null,
                    'nota_conguaglio'        => $rinuncia ? ($dati['nota_conguaglio'] ?? null) : null,
                    'utente_id'              => $utente->id,
                    'registro'               => $this->registro($uscente, $entrante),
                ]);

                // 5. Le pertinenze spuntate: la stessa operazione, una riga `subentri` ciascuna.
                foreach ($dati['pertinenze'] ?? [] as $pertinenzaId) {
                    $this->applicaAllaPertinenza($condominio, $immobile, (int) $pertinenzaId, $subentro, $tipo, $sottotipo, $uscente, $entrante, $decorrenza, $giornoPrima, $utente);
                }

                // 6. La coppia in saldi (D9), salvo rinuncia motivata.
                $coppie = 0;
                $conguaglio = $anteprima['rate']['conguaglio'];
                if ($conguaglio !== null && ! $rinuncia) {
                    $coppie = $this->scriviCoppie($condominio, $subentro, $conguaglio, $decorrenza, $avvisi);
                }

                // 6-bis. Le bozze che passano a chi entra (decisione 25, B3a): cambia il nome, non l'importo. Anche con la
                // rinuncia: le parti hanno regolato fra loro la coppia, non chi paga le rate che devono ancora scadere.
                $riassegnate = 0;
                if ($conguaglio !== null && ! empty($conguaglio['bozze_riassegnate'])) {
                    $riassegnate = $this->riassegnaBozze($subentro, $conguaglio, $decorrenza, $utente);
                }

                // 7. Il PDF del titolo: documento dell'unità, dell'amministratore. Il file si scrive qui, così
                //    `$path` è noto al catch anche se `create()` fallisce subito dopo (verifica S5, R5).
                $documento = null;
                if (($dati['allegato_titolo'] ?? null) instanceof UploadedFile) {
                    $path = app(ArchivioDocumenti::class)->salva($dati['allegato_titolo']);
                    $documento = $this->salvaDocumento($immobile, $dati['allegato_titolo'], $path, $tipo, $decorrenza, $uscente?->anagrafica?->nome, $entrante?->nome, $utente);
                    $subentro->update(['documento_id' => $documento->id]);
                }

                return ['subentro' => $subentro->fresh(), 'anteprima' => $anteprima, 'coppie' => $coppie, 'riassegnate' => $riassegnate, 'documento' => $documento];
            });
        } catch (\Throwable $e) {
            if ($path !== null && app(ArchivioDocumenti::class)->esiste($path)) {
                app(ArchivioDocumenti::class)->elimina($path);
            }
            throw $e;
        }

        // 8. Il promemoria in agenda, opt-in, dopo il commit: se fallisce non annulla il passaggio.
        $esito['promemoria'] = $this->creaPromemoria($condominio, $immobile, $esito['subentro'], $dati, $utente, $avvisi);
        $esito['avvisi'] = $avvisi;

        return $esito;
    }

    /**
     * Il registro delle righe toccate dall'unità in corso (1.11.0-beta.37, decisione 27 punto 8): ogni chiusura,
     * apertura o modifica annota i valori di prima e di dopo, e l'annullamento li rilegge al contrario. Si azzera prima
     * di ogni `applicaRighe()`: l'unità principale e ogni pertinenza hanno il loro, sulla loro riga di `subentri`.
     *
     * @var list<array<string, mixed>>
     */
    private array $registroRighe = [];

    /** @return array<string, mixed> il registro di un'unità, nella forma che legge `AnnullaPassaggioAction` */
    private function registro(?TitolaritaImmobile $uscente, ?Anagrafica $entrante): array
    {
        return [
            'versione' => 1,
            'righe'    => $this->registroRighe,
            'quote'    => [],
            // I nomi restano anche se la persona sparisce: la chiave esterna è `nullOnDelete`, e un passaggio annullato
            // non impedisce di cancellare l'anagrafica creata per sbaglio (è spesso la ragione dell'annullamento).
            'nomi'     => ['uscente' => $uscente?->anagrafica?->nome, 'entrante' => $entrante?->nome],
            // L'ultima quota esistente al passaggio: le quote con un id più alto sono nate dopo (un piano ricalcolato con
            // la titolarità nuova). Per id e non per ora: un piano generato e un passaggio nello stesso secondo non si
            // distinguono dal `created_at`.
            'quota_max_id' => (int) DB::table('rate_quote')->max('id'),
            // Lo stesso per le righe di titolarità: una riga con un id più alto è stata associata dopo il passaggio, e se
            // blocca l'annullamento la via è diversa da quella di una riga che c'era già (giro di verifica, G-3).
            'riga_max_id' => (int) DB::table('anagrafica_immobile')->max('id'),
        ];
    }

    // --- Le righe ---------------------------------------------------------------------------------

    /**
     * Chiude e apre le righe di **un'unità** secondo il tipo. `$quotaEntrante` è la quota del modulo per
     * l'unità principale; per una pertinenza è la quota che chi esce ha lì (`$fonteQuota = 'uscente'`).
     *
     * @return array{riga_entrante_id: ?int, anagrafica_entrante_id: ?int}
     */
    private function applicaRighe(Immobile $unita, string $tipo, ?string $sottotipo, ?TitolaritaImmobile $uscente, ?Anagrafica $entrante, float $quotaEntrante, string $tipologiaEntrante, CarbonImmutable $decorrenza, CarbonImmutable $giornoPrima, string $fonteQuota): array
    {
        $rigaEntranteId = null;
        $anagraficaEntranteId = $entrante?->id;

        if ($fonteQuota === 'uscente' && $uscente !== null) {
            $quotaEntrante = (float) $uscente->quota;
        }

        switch ($tipo) {
            case 'vendita':
                $this->chiudi($uscente, $giornoPrima);
                $rigaEntranteId = $this->apriSommando($unita, $entrante, $tipologiaEntrante, $quotaEntrante, $decorrenza, $giornoPrima);
                break;

            case 'inizio_locazione':
                $rigaEntranteId = $this->apri($unita, $entrante, 'inquilino', $quotaEntrante, $decorrenza);
                break;

            case 'fine_locazione':
                $this->chiudi($uscente, $giornoPrima);
                if ($entrante !== null) {
                    $rigaEntranteId = $this->apri($unita, $entrante, 'inquilino', $quotaEntrante, $decorrenza);
                }
                break;

            case 'usufrutto':
                if ($sottotipo === 'estinzione') {
                    $this->chiudi($uscente, $giornoPrima);
                    // Tutti i nudi proprietari **in corso alla decorrenza** tornano proprietari pieni, ciascuno alla
                    // sua quota. Un nudo chiuso il giorno prima (uscito con un altro passaggio) non torna: era il
                    // difetto R6 della verifica S5. Un nudo nato lo stesso giorno (nuda venduta e usufrutto estinto
                    // nello stesso atto) non si chiude a decorrenza − 1: la sua riga diventa «proprietario».
                    $nudi = $unita->titolarita()->with('anagrafica')->where('tipologia', 'nuda_proprietario')->get()
                        ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza))->values();
                    foreach ($nudi as $nudo) {
                        if ($nudo->data_inizio !== null && $nudo->data_inizio->equalTo($decorrenza)) {
                            $this->registroRighe[] = ['operazione' => 'modificata', 'id' => (int) $nudo->id,
                                'prima' => ['tipologia' => 'nuda_proprietario'], 'dopo' => ['tipologia' => 'proprietario']];
                            DB::table('anagrafica_immobile')->where('id', $nudo->id)->update(['tipologia' => 'proprietario', 'updated_at' => now()]);
                            $id = (int) $nudo->id;
                        } else {
                            $this->chiudi($nudo, $giornoPrima);
                            $id = $this->apri($unita, $nudo->anagrafica, 'proprietario', (float) $nudo->quota, $decorrenza);
                        }
                        if ($rigaEntranteId === null) {
                            $rigaEntranteId = $id;
                            $anagraficaEntranteId = $nudo->anagrafica_id;
                        }
                    }
                } else {
                    // Costituzione: il proprietario resta come nudo proprietario, alla stessa quota; entra l'usufruttuario.
                    $this->chiudi($uscente, $giornoPrima);
                    $this->apri($unita, $uscente->anagrafica, 'nuda_proprietario', (float) $uscente->quota, $decorrenza);
                    $rigaEntranteId = $this->apri($unita, $entrante, 'usufruttuario', $quotaEntrante, $decorrenza);
                }
                break;

            default:
                throw ValidationException::withMessages(['tipo' => 'Tipo di passaggio sconosciuto.']);
        }

        return ['riga_entrante_id' => $rigaEntranteId, 'anagrafica_entrante_id' => $anagraficaEntranteId];
    }

    private function chiudi(?TitolaritaImmobile $riga, CarbonImmutable $giornoPrima): void
    {
        if ($riga === null) {
            return;
        }
        // Mai `attivo` (D10): una riga chiusa resta attiva con la sua `data_fine`. Per id, non per persona.
        $this->registroRighe[] = ['operazione' => 'chiusa', 'id' => (int) $riga->id,
            'prima' => ['data_fine' => $riga->data_fine?->toDateString()], 'dopo' => ['data_fine' => $giornoPrima->toDateString()]];
        DB::table('anagrafica_immobile')->where('id', $riga->id)->update(['data_fine' => $giornoPrima->toDateString(), 'updated_at' => now()]);
        $riga->data_fine = $giornoPrima;
    }

    /** Apre una riga dopo le guardie (inv. 11 e 12) sullo stato attuale dell'unità. Restituisce l'id. */
    private function apri(Immobile $unita, ?Anagrafica $persona, string $tipologia, float $quota, CarbonImmutable $decorrenza): int
    {
        if ($persona === null) {
            throw ValidationException::withMessages(['anagrafica_entrante_id' => 'Manca chi entra.']);
        }
        $righe = $unita->titolarita()->get();
        $nuova = ['anagrafica_id' => (int) $persona->id, 'tipologia' => $tipologia, 'quota' => $quota, 'data_inizio' => $decorrenza->toDateString(), 'data_fine' => null];

        if ($sovrapposta = GuardieTitolarita::sovrapposizioneStessaPersona($righe, $nuova)) {
            throw ValidationException::withMessages(['anagrafica_entrante_id' => $unita->nome . ' — ' . mb_lcfirst(GuardieTitolarita::messaggioSovrapposizione($sovrapposta))]);
        }
        if ($sforo = GuardieTitolarita::sforoQuotePerGiorno($righe, $nuova)) {
            throw ValidationException::withMessages(['quota' => $unita->nome . ' — ' . mb_lcfirst(GuardieTitolarita::messaggioSforo($tipologia, $sforo))]);
        }

        $riga = $unita->titolarita()->create([
            'anagrafica_id' => $persona->id, 'tipologia' => $tipologia, 'quota' => $quota,
            'data_inizio' => $decorrenza->toDateString(), 'data_fine' => null, 'attivo' => true,
        ]);
        $this->registroRighe[] = ['operazione' => 'aperta', 'id' => (int) $riga->id,
            'dopo' => ['anagrafica_id' => (int) $persona->id, 'tipologia' => $tipologia, 'quota' => round($quota, 2), 'data_inizio' => $decorrenza->toDateString(), 'data_fine' => null]];
        // La persona è del condominio (già così per «Associa»).
        $persona->condomini()->syncWithoutDetaching([$unita->condominio_id]);

        return (int) $riga->id;
    }

    /**
     * Decisione A: se chi entra è già titolare con lo stesso ruolo alla decorrenza (il comproprietario che
     * compra l'altra metà), la sua riga si chiude a decorrenza − 1 e se ne apre una alla quota somma.
     *
     * Se la sua riga è nata **lo stesso giorno** — due comproprietari che vendono insieme allo stesso
     * acquirente, registrati come due passaggi con la stessa decorrenza — non c'è niente da chiudere
     * (una riga 01/05 → 30/04 sarebbe rovesciata): la quota si **somma su quella riga**, dopo le stesse
     * guardie (verifica indipendente S5, R2).
     */
    private function apriSommando(Immobile $unita, ?Anagrafica $entrante, string $tipologia, float $quota, CarbonImmutable $decorrenza, CarbonImmutable $giornoPrima): int
    {
        if ($entrante === null) {
            throw ValidationException::withMessages(['anagrafica_entrante_id' => 'Manca chi entra.']);
        }
        $righeEntrante = $unita->titolarita()->where('anagrafica_id', $entrante->id)->where('tipologia', $tipologia)->get()
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza) && $t->data_inizio !== null);

        $stessoGiorno = $righeEntrante->first(fn (TitolaritaImmobile $t) => $t->data_inizio->equalTo($decorrenza));
        if ($stessoGiorno !== null) {
            $nuovaQuota = round((float) $stessoGiorno->quota + $quota, 2);
            $righe = $unita->titolarita()->get();
            $nuova = ['anagrafica_id' => (int) $entrante->id, 'tipologia' => $tipologia, 'quota' => $nuovaQuota, 'data_inizio' => $decorrenza->toDateString(), 'data_fine' => null];
            if ($sforo = GuardieTitolarita::sforoQuotePerGiorno($righe, $nuova, (int) $stessoGiorno->id)) {
                throw ValidationException::withMessages(['quota' => $unita->nome . ' — ' . mb_lcfirst(GuardieTitolarita::messaggioSforo($tipologia, $sforo))]);
            }
            $this->registroRighe[] = ['operazione' => 'modificata', 'id' => (int) $stessoGiorno->id,
                'prima' => ['quota' => round((float) $stessoGiorno->quota, 2)], 'dopo' => ['quota' => $nuovaQuota]];
            DB::table('anagrafica_immobile')->where('id', $stessoGiorno->id)->update(['quota' => $nuovaQuota, 'updated_at' => now()]);

            return (int) $stessoGiorno->id;
        }

        $giaTitolare = $righeEntrante->first(fn (TitolaritaImmobile $t) => $t->data_inizio->lte($giornoPrima));
        if ($giaTitolare !== null) {
            $quota = round((float) $giaTitolare->quota + $quota, 2);
            $this->chiudi($giaTitolare, $giornoPrima);
        }

        return $this->apri($unita, $entrante, $tipologia, $quota, $decorrenza);
    }

    private function applicaAllaPertinenza(Condominio $condominio, Immobile $principale, int $pertinenzaId, Subentro $padre, string $tipo, ?string $sottotipo, ?TitolaritaImmobile $uscente, ?Anagrafica $entrante, CarbonImmutable $decorrenza, CarbonImmutable $giornoPrima, User $utente): void
    {
        $pertinenza = $principale->pertinenze()->whereKey($pertinenzaId)->first();
        if ($pertinenza === null) {
            throw ValidationException::withMessages(['pertinenze' => 'Una delle pertinenze spuntate non appartiene a questa unità.']);
        }

        $uscenteLi = null;
        if ($uscente !== null) {
            $uscenteLi = $pertinenza->titolarita()->with('anagrafica')->where('anagrafica_id', $uscente->anagrafica_id)->where('tipologia', $uscente->tipologia)
                ->lockForUpdate()->get()
                ->first(fn (TitolaritaImmobile $t) => $t->inCorsoIl($giornoPrima) && ! $t->subentriComeUscente()->exists());
            if ($uscenteLi === null) {
                $ruolo = RuoloAnagraficaImmobile::tryFrom((string) $uscente->tipologia)?->label() ?? $uscente->tipologia;
                throw ValidationException::withMessages(['pertinenze' => sprintf('%s: %s non risulta %s alla data del passaggio. Togli la spunta, o registra il passaggio dalla pertinenza.', $pertinenza->nome, $uscente->anagrafica?->nome, mb_strtolower($ruolo))]);
            }
        }

        $this->registroRighe = [];
        $esito = $this->applicaRighe($pertinenza, $tipo, $sottotipo, $uscenteLi, $entrante, 100.0, (string) $padre->tipologia, $decorrenza, $giornoPrima, 'uscente');

        Subentro::create([
            'condominio_id'          => $condominio->id,
            'immobile_id'            => $pertinenza->id,
            'subentro_padre_id'      => $padre->id,
            'anagrafica_uscente_id'  => $uscenteLi?->anagrafica_id,
            'anagrafica_entrante_id' => $esito['anagrafica_entrante_id'],
            'riga_uscente_id'        => $uscenteLi?->id,
            'riga_entrante_id'       => $esito['riga_entrante_id'],
            'tipologia'              => $padre->tipologia,
            'tipo_passaggio'         => $tipo,
            'decorrenza'             => $decorrenza->toDateString(),
            'estremi_titolo'         => $padre->estremi_titolo,
            'copia_autentica_il'     => $padre->copia_autentica_il?->toDateString(),
            'nota'                   => $padre->nota,
            'nota_cancello'          => $padre->nota_cancello,
            'utente_id'              => $utente->id,
            'registro'               => $this->registro($uscenteLi, $entrante),
        ]);
    }

    // --- La coppia in saldi (D9) --------------------------------------------------------------------

    /** @param array<string,mixed> $conguaglio `AnteprimaPassaggio::calcola()['rate']['conguaglio']` */
    private function scriviCoppie(Condominio $condominio, Subentro $subentro, array $conguaglio, CarbonImmutable $decorrenza, array &$avvisi): int
    {
        $uscenteId = (int) $conguaglio['anagrafica_uscente_id'];
        // Chi entra sta dentro la coppia (S8-30: con più nudi proprietari all'estinzione dell'usufrutto ogni
        // coppia ha il suo); il campo del conguaglio resta il ripiego.
        $entrantiIds = array_values(array_unique(array_map(fn ($c) => (int) ($c['anagrafica_entrante_id'] ?? $conguaglio['anagrafica_entrante_id']), $conguaglio['coppie'])));
        $nomi = Anagrafica::whereKey([$uscenteId, ...$entrantiIds])->pluck('nome', 'id');
        $testa = sprintf('Conguaglio passaggio del %s', $decorrenza->locale('it')->translatedFormat('j F Y'));
        $scritte = 0;

        foreach ($conguaglio['coppie'] as $c) {
            if ((int) $c['importo'] === 0) {
                continue;
            }
            $entranteId = (int) ($c['anagrafica_entrante_id'] ?? $conguaglio['anagrafica_entrante_id']);
            // `saldi.descrizione` è varchar(255): due nomi lunghi non devono far fallire la registrazione (S8-29).
            $descrizione = $this->testaConParti($testa, ($nomi[$uscenteId] ?? '?') . ' → ' . ($nomi[$entranteId] ?? '?'));
            $esercizioId = $c['esercizio_id'] ?? $this->getEsercizioCorrente($condominio)?->id;
            if ($esercizioId === null) {
                $avvisi[] = sprintf('Nessun esercizio su cui scrivere il conguaglio della gestione «%s»: la coppia non è stata scritta.', $c['gestione'] ?? '?');
                continue;
            }
            foreach ([[$uscenteId, -(int) $c['importo']], [$entranteId, (int) $c['importo']]] as [$anagraficaId, $importo]) {
                Saldo::create([
                    'esercizio_id'   => $esercizioId,
                    'condominio_id'  => $condominio->id,
                    'gestione_id'    => $c['gestione_id'],
                    'immobile_id'    => $c['immobile_id'],
                    'anagrafica_id'  => $anagraficaId,
                    'saldo_iniziale' => $importo,
                    'origine'        => 'automatico',
                    'is_applicato'   => false,
                    'subentro_id'    => $subentro->id,
                    'descrizione'    => $descrizione,
                ]);
            }
            $scritte++;
        }

        return $scritte;
    }

    // --- Le bozze che passano (decisione 25, B3a) ----------------------------------------------------

    /**
     * Cambia l'intestatario delle bozze che il pannello ha detto che passano, **quota per quota** e con gli stessi
     * numeri (anteprima = scrittura): l'importo della rata non cambia, il riparto non si rifà.
     *
     * - Ogni quota si rilegge con il lock e con le stesse condizioni del calcolo — ancora di chi esce, rata in bozza,
     *   non emessa a giornale, non pagata: se nel frattempo una è stata emessa o incassata, non si scrive niente e si
     *   chiede di ricaricare, invece di spostare una quota che non è più una bozza.
     * - Il **saldo pregresso** dentro la quota (metodo «spalmati», o «prima rata» quando la prima è ancora in bozza)
     *   non passa: la quota di chi entra porta il solo preventivo, e a chi esce resta una quota sua sulla stessa rata
     *   con il pregresso, nella forma che il generatore dà alle quote di soli saldi (`tipo = saldo_iniziale`,
     *   `quota_pura_gestione = 0`) — a debito l'emissione la chiude sul Fondo passate gestioni come la rata 0; a credito
     *   la quota non si emette (come la rata 0 a credito) e il credito esce da lì con il rimborso o la compensazione.
     * - `regole_calcolo.riassegnazione` ricorda da chi viene la quota, con quale passaggio e di chi è il riparto
     *   (`righe_di`): le `righe_riparto` restano del soggetto per cui il piano è stato generato, e un passaggio
     *   successivo le rilegge da lì.
     * - I promemoria delle scadenze nel portale seguono le quote ({@see EventiRataCondomino}).
     *
     * @param array<string,mixed> $conguaglio `AnteprimaPassaggio::calcola()['rate']['conguaglio']`
     */
    private function riassegnaBozze(Subentro $subentro, array $conguaglio, CarbonImmutable $decorrenza, User $utente): int
    {
        $uscenteId = (int) $conguaglio['anagrafica_uscente_id'];
        $entranteId = (int) $conguaglio['anagrafica_entrante_id'];
        $adesso = now();
        $rateToccate = [];
        $registroQuote = [];

        foreach ($conguaglio['bozze_riassegnate'] as $b) {
            $quota = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
                ->where('rate_quote.id', $b['rata_quote_id'])
                ->where('rate_quote.anagrafica_id', $uscenteId)
                ->where('rate.stato', 'bozza')
                ->whereNull('rate_quote.scrittura_contabile_id')
                ->where('rate_quote.importo_pagato', 0)
                ->lockForUpdate()
                ->first(['rate_quote.*']);
            if ($quota === null) {
                throw ValidationException::withMessages(['passaggio' => sprintf('La rata %d del piano «%s» non è più una bozza da passare a chi entra (è stata emessa o incassata mentre registravi): ricarica il pannello e riprova.', $b['rata'], $b['piano'])]);
            }

            $regole = is_string($quota->regole_calcolo) ? (json_decode($quota->regole_calcolo, true) ?: []) : [];
            $quotaPura = (int) $b['quota_pura'];
            $pregresso = (int) $quota->importo - $quotaPura;
            $traccia = [
                'da_anagrafica_id' => $uscenteId,
                'subentro_id'      => $subentro->id,
                'decorrenza'       => $decorrenza->toDateString(),
                // Di chi è il riparto: di chi aveva la quota, o di chi l'aveva prima di lui (catena di passaggi).
                'righe_di'         => (int) ($regole['riassegnazione']['righe_di'] ?? $uscenteId),
                'il'               => $adesso->toIso8601String(),
                'da'               => 'user_' . $utente->id,
            ];

            $regoleEntrante = $regole;
            $regoleEntrante['riassegnazione'] = $traccia;
            if ($pregresso !== 0) {
                $regoleEntrante['importi'] = ['quota_pura_gestione' => $quotaPura, 'saldo_usato' => 0, 'totale_calcolato' => $quotaPura];
                unset($regoleEntrante['dettagli_saldo'], $regoleEntrante['note_saldo']);
            }
            $voce = ['id' => (int) $quota->id, 'rata_id' => (int) $quota->rata_id,
                'prima' => ['anagrafica_id' => $uscenteId, 'importo' => (int) $quota->importo, 'stato' => $quota->stato, 'regole_calcolo' => $quota->regole_calcolo],
                'dopo' => ['anagrafica_id' => $entranteId, 'importo' => $quotaPura, 'stato' => $quotaPura <= 0 ? 'credito' : 'da_pagare'],
                'gemella_id' => null];
            DB::table('rate_quote')->where('id', $quota->id)->update([
                'anagrafica_id'  => $entranteId,
                'importo'        => $quotaPura,
                'stato'          => $quotaPura <= 0 ? 'credito' : 'da_pagare',
                'regole_calcolo' => json_encode($regoleEntrante),
                'updated_at'     => $adesso,
            ]);

            if ($pregresso !== 0) {
                // A chi esce resta il suo pregresso, nella forma delle quote di soli saldi del generatore.
                $diChiEsce = (array) $quota;
                unset($diChiEsce['id']);
                $voce['gemella_id'] = (int) DB::table('rate_quote')->insertGetId(array_replace($diChiEsce, [
                    'anagrafica_id'  => $uscenteId,
                    'importo'        => $pregresso,
                    'importo_pagato' => 0,
                    'stato'          => $pregresso <= 0 ? 'credito' : 'da_pagare',
                    'tipo'           => 'saldo_iniziale',
                    'regole_calcolo' => json_encode(array_filter([
                        'origine'        => $regole['origine'] ?? null,
                        'importi'        => ['quota_pura_gestione' => 0, 'saldo_usato' => $pregresso, 'totale_calcolato' => $pregresso],
                        'parametri'      => $regole['parametri'] ?? null,
                        'dettagli_saldo' => $regole['dettagli_saldo'] ?? null,
                        'note_saldo'     => $regole['note_saldo'] ?? null,
                        'divisa_da'      => ['rata_quote_id' => (int) $quota->id, 'subentro_id' => $subentro->id, 'decorrenza' => $decorrenza->toDateString()],
                        'audit'          => $regole['audit'] ?? null,
                    ], fn ($v) => $v !== null)),
                    'created_at'     => $adesso,
                    'updated_at'     => $adesso,
                ]));
            }
            $rateToccate[(int) $quota->rata_id] = true;
            $registroQuote[] = $voce;
        }

        $registro = $subentro->registro ?? [];
        $registro['quote'] = $registroQuote;
        $subentro->forceFill(['registro' => $registro])->save();

        app(EventiRataCondomino::class)->seguonoLeQuote(array_keys($rateToccate), [$uscenteId, $entranteId], $utente);

        return count($conguaglio['bozze_riassegnate']);
    }

    // --- Documento e promemoria --------------------------------------------------------------------

    /** «Testa (parti)» dentro i 255 caratteri di un varchar: si troncano le parti con «…», la testa resta intera. */
    private function testaConParti(string $testa, string $parti): string
    {
        $spazio = 255 - mb_strlen($testa) - 3; // « (» e «)»
        if (mb_strlen($parti) > $spazio) {
            $parti = mb_substr($parti, 0, max(0, $spazio - 1)) . '…';
        }

        return "{$testa} ({$parti})";
    }

    /** Il record del documento per un file già su disco (`$path`); `documenti.name` è varchar(255): le parti si troncano. */
    private function salvaDocumento(Immobile $immobile, UploadedFile $file, string $path, string $tipo, CarbonImmutable $decorrenza, ?string $uscente, ?string $entrante, User $utente): Documento
    {
        $cosa = in_array($tipo, ['inizio_locazione', 'fine_locazione'], true) ? 'Contratto di locazione' : 'Titolo di provenienza';
        $testa = sprintf('%s — passaggio del %s', $cosa, $decorrenza->locale('it')->translatedFormat('j F Y'));
        $parti = implode(' → ', array_filter([$uscente, $entrante]));
        $nome = $parti !== '' ? $this->testaConParti($testa, $parti) : $testa;

        // Passa dalla relazione: `Documento::create()` scarterebbe `documentable_*` (non sono nel fillable).
        // Nessun `condomini()->attach()`, nessuna anagrafica: solo l'amministratore (decisione B).
        $documento = $immobile->documenti()->create([
            'name'         => $nome,
            'description'  => 'Allegato a «Registra passaggio». Riservato all\'amministratore.',
            'path'         => $path,
            'mime_type'    => $file->getClientMimeType(),
            'file_size'    => $file->getSize(),
            'created_by'   => $utente->id,
            'is_published' => false,
            'is_approved'  => true,
        ]);

        return $documento;
    }

    private function creaPromemoria(Condominio $condominio, Immobile $immobile, Subentro $subentro, array $dati, User $utente, array &$avvisi): ?Evento
    {
        if (empty($dati['promemoria_scadenza']) || empty($dati['data_fine_locazione']) || empty($dati['promemoria_giorni'])) {
            return null;
        }
        $inquilino = $subentro->entrante?->nome ?? $dati['entrante']?->nome ?? 'l\'inquilino';
        $scadenza = $dati['data_fine_locazione'];
        $giorni = (int) $dati['promemoria_giorni'];

        try {
            return InboxService::createTask(
                tipo: EventoTipo::SCADENZA,
                title: sprintf('Scade la locazione di %s — %s', $inquilino, $immobile->nome),
                description: sprintf('Il contratto di locazione di %s su %s scade il %s. Il promemoria è stato chiesto con %d giorni di anticipo registrando il passaggio.', $inquilino, $immobile->nome, $scadenza->locale('it')->translatedFormat('j F Y'), $giorni),
                // `Carbon`, non `CarbonImmutable`: la firma di `createTask` lo pretende.
                scadenza: Carbon::instance($scadenza)->subDays($giorni)->setTime(9, 0),
                createdByUserId: $utente->id,
                condominioId: $condominio->id,
                context: ['subentro_id' => $subentro->id, 'immobile_id' => $immobile->id, 'scadenza_contratto' => $scadenza->toDateString()],
                actionUrl: route('admin.gestionale.immobili.anagrafiche.index', ['condominio' => $condominio->id, 'immobile' => $immobile->id]),
                eventableType: Subentro::class,
                eventableId: $subentro->id,
            );
        } catch (\Throwable $e) {
            report($e);
            $avvisi[] = 'Il promemoria in agenda non è stato creato: il passaggio è registrato, il promemoria puoi aggiungerlo dall\'agenda.';

            return null;
        }
    }
}
