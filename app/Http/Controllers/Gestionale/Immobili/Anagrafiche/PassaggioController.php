<?php

namespace App\Http\Controllers\Gestionale\Immobili\Anagrafiche;

use App\Helpers\DateHelper;
use App\Actions\Subentro\AnnullaConguaglioAction;
use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Actions\Subentro\RegistraSubentroAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Anagrafica\CreateAnagraficaRequest;
use App\Http\Requests\Gestionale\Immobile\Anagrafica\AnteprimaPassaggioRequest;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use App\Services\Subentro\AnteprimaPassaggio;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Registra passaggio»: la vendita o donazione, l'inizio e la fine di una locazione, l'usufrutto.
 *
 * È il verbo che **conserva** la storia dell'unità, in contrapposizione a «Modifica associazione»
 * che corregge un dato e a «Dissocia» che cancella una riga senza storia (§6.3 di
 * `docs/pertinenze_vendita_locazione.md`, decisione 13 del progetto sul subentro). Chiude il periodo di
 * chi esce al giorno prima, apre quello di chi entra dalla decorrenza, e — se ci sono rate già emesse
 * — propone la coppia di conguaglio a somma zero (D9) **mostrando gli importi prima di scrivere**.
 *
 * Tre ingressi:
 * - `create`: la pagina, con i titolari in corso e le persone del condominio;
 * - `anteprima`: il pannello «Cosa cambierà», calcolato dal server a ogni modifica del modulo
 *   ({@see AnteprimaPassaggio}) — mai lato client;
 * - `store`: la scrittura (`RegistraSubentroAction`, S5): ricalcola il pannello dentro la transazione e
 *   scrive quei numeri; poi torna all'elenco dei titolari con l'avviso verde e le due azioni del §6.4.
 */
class PassaggioController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    public function create(Request $request, Condominio $condominio, Immobile $immobile): Response
    {
        $this->assicuraUnitaDelCondominio($condominio, $immobile);
        $tipo = (string) $request->query('tipo', 'vendita');
        abort_unless(in_array($tipo, Subentro::TIPI_PASSAGGIO, true), 404);

        $immobile->loadMissing(['palazzina', 'scala', 'tipologiaImmobile', 'pertinenzaDi'])->loadCount('pertinenze');
        $esercizio = $this->getEsercizioCorrente($condominio);
        $oggi = DateHelper::oggiUtenteImmutable();

        $titolari = $immobile->titolarita()->with('anagrafica')->orderBy('data_inizio')->get()
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($oggi))
            ->values();

        // Le pertinenze collegate, con chi ne è titolare oggi: la card «Applica lo stesso passaggio
        // anche a» le propone con le caselle **non spuntate** (D5 di pertinenze).
        $pertinenze = $immobile->pertinenze()->with('titolarita.anagrafica')->get()
            ->map(fn (Immobile $p) => [
                'id' => $p->id,
                'nome' => $p->nome,
                'etichetta' => $p->etichetta_estesa,
                'titolari' => $p->titolarita->filter(fn ($t) => $t->inCorsoIl($oggi))
                    ->map(fn ($t) => ['nome' => $t->anagrafica?->nome, 'tipologia' => $t->tipologia])->values(),
            ])->values();

        // Dal menu di una riga si arriva con `?riga=<id>`: quella riga è già selezionata in «Chi esce», se è
        // fra i candidati del tipo. Un id che non è di questa unità si ignora e basta.
        $rigaPreselezionata = (int) $request->query('riga', 0);
        if ($rigaPreselezionata > 0 && ! $titolari->contains(fn (TitolaritaImmobile $t) => $t->id === $rigaPreselezionata)) {
            $rigaPreselezionata = 0;
        }

        return Inertia::render('gestionale/immobili/anagrafiche/PassaggioNew', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'immobile' => new ImmobileResource($immobile),
            'tipo' => $tipo,
            'rigaPreselezionata' => $rigaPreselezionata ?: null,
            'titolari' => $this->titolariPerIlModulo($titolari),
            'anagrafiche' => $condominio->anagrafiche()->orderBy('nome')->get(['anagrafiche.id', 'anagrafiche.nome', 'anagrafiche.codice_fiscale', 'anagrafiche.indirizzo'])
                ->map(fn ($a) => ['id' => $a->id, 'nome' => $a->nome, 'codice_fiscale' => $a->codice_fiscale, 'indirizzo' => $a->indirizzo])->values(),
            'pertinenze' => $pertinenze,
            // Le rate emesse dell'unità sono un fatto che il pannello mostra anche prima di compilare.
            'rateEmesseCount' => $this->rateEmesseCount($immobile),
        ]);
    }

    public function anteprima(AnteprimaPassaggioRequest $request, Condominio $condominio, Immobile $immobile, AnteprimaPassaggio $anteprima): JsonResponse
    {
        $this->assicuraUnitaDelCondominio($condominio, $immobile);

        return response()->json($anteprima->calcola($condominio, $immobile, $request->datiPassaggio()));
    }

    /**
     * La scrittura del passaggio (`RegistraSubentroAction`, S5).
     *
     * Gli errori di dominio dell'action sono `ValidationException` sui campi del modulo (riga già chiusa,
     * quota che sfora in un giorno, pertinenza senza chi esce, cancello (1) senza nota): Inertia li mostra
     * sotto il campo, come quelli della Request. Il resto passa dal catch e torna con un errore leggibile.
     *
     * Dopo: l'elenco dei titolari con l'avviso verde e due azioni — «Estratto conto di chi entra» (la pagina
     * esiste e legge `saldi`: mostra il conguaglio appena scritto) e «Aggiorna l'anagrafe» — **non** «Scarica
     * l'attestazione» (stampa della v1.16) e **non** la «situazione debitoria dell'unità» del §6.4, che oggi è
     * un endpoint JSON per l'incasso e non una pagina (verifica S5, R14).
     */
    public function store(AnteprimaPassaggioRequest $request, Condominio $condominio, Immobile $immobile, RegistraSubentroAction $action): RedirectResponse
    {
        $this->assicuraUnitaDelCondominio($condominio, $immobile);
        $dati = $request->datiPassaggio();

        try {
            $esito = $action->execute($condominio, $immobile, $dati, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \App\Support\ErroriDiagnosticabili::registra($e, 'Registra passaggio', ['condominio_id' => $condominio->id, 'immobile_id' => $immobile->id]);

            return back()->withErrors(['passaggio' => 'La registrazione non è riuscita e niente è stato scritto. ' . $e->getMessage()]);
        }

        $subentro = $esito['subentro'];
        $anteprima = $esito['anteprima'];
        $entranteId = $subentro->anagrafica_entrante_id;
        // La frase del pannello è al futuro («risulterà»): dopo la scrittura si dice il fatto — con la stessa frase
        // per tipo del pannello, portata al passato/presente, non con una frase generica che per l'usufrutto e la
        // fine locazione diceva una cosa falsa (Fase 1-bis, S8-31).
        $frase = str_replace(
            ['risulterà titolare', 'risulterà inquilino', 'risulterà usufruttuario', 'risulterà proprietario pieno', 'risulterà'],
            ['è stato titolare', 'è stato inquilino', 'è stato usufruttuario', 'è stato proprietario pieno', 'è'],
            (string) $anteprima['riferimento']['frase'],
        );

        return to_route('admin.gestionale.immobili.anagrafiche.index', ['condominio' => $condominio->id, 'immobile' => $immobile->id])
            ->with($this->flashSuccess('Passaggio registrato. ' . $frase))
            ->with('passaggio_registrato', [
                'subentro_id' => $subentro->id,
                'frase'       => $frase,
                'coppie'      => $esito['coppie'],
                'conguaglio'  => $esito['coppie'] > 0 ? ($anteprima['rate']['conguaglio']['totale_entrante_assoluto_formattato'] ?? null) : null,
                // Decisione 25: con le bozze che passano la coppia può rovesciarsi, e allora il credito è di chi entra.
                'conguaglio_rovesciato' => $esito['coppie'] > 0 && (int) ($anteprima['rate']['conguaglio']['totale_entrante'] ?? 0) < 0,
                'riassegnate' => $esito['riassegnate'] ?? 0,
                'riassegnate_frase' => $this->fraseRiassegnate($anteprima['rate']['conguaglio']['riassegnazione'] ?? [], $subentro->entrante?->nome),
                'rinuncia'    => $subentro->conguaglioRinunciato(),
                'documento'   => $esito['documento']?->name,
                'promemoria'  => $esito['promemoria']?->start_time?->toDateString(),
                'avvisi'      => $esito['avvisi'],
                'entrante'    => $subentro->entrante?->nome,
                'azioni'      => [
                    'estratto_conto' => $entranteId ? route('admin.gestionale.anagrafiche.estratto-conto', ['condominio' => $condominio->id, 'anagrafica' => $entranteId]) : null,
                    'anagrafe'       => $entranteId ? route('admin.anagrafiche.edit', ['anagrafica' => $entranteId]) : null,
                ],
            ]);
    }

    /**
     * «Le 8 rate in bozza del piano «Preventivo 2026» sono passate a Bianchi Anna»: la stessa cosa che il pannello
     * diceva al futuro, detta al passato dopo la scrittura (decisione 25).
     *
     * @param list<array{piano: string, n: int}> $riassegnazione
     */
    private function fraseRiassegnate(array $riassegnazione, ?string $entrante): ?string
    {
        if ($riassegnazione === []) {
            return null;
        }
        $parti = array_map(fn ($r) => $r['n'] === 1
            ? sprintf('la rata in bozza del piano «%s» è passata', $r['piano'])
            : sprintf('le %d rate in bozza del piano «%s» sono passate', $r['n'], $r['piano']), $riassegnazione);

        return ucfirst(implode('; ', $parti)) . sprintf(' a %s: è cambiato l\'intestatario, non l\'importo.', $entrante ?? 'chi entra');
    }

    /**
     * La copia autentica del titolo, registrata **dopo** il passaggio (S6, voce 7). Da quel giorno chi ha
     * venduto è liberato per i contributi successivi (art. 63 co. 5 disp. att. c.c.), e la frase «finché non
     * la ricevi» del vademecum si spegne da sola: il testo si ricalcola dai fatti, non si riscrive.
     */
    public function copiaAutentica(Request $request, Condominio $condominio, Immobile $immobile, Subentro $subentro): RedirectResponse
    {
        abort_unless((int) $subentro->immobile_id === (int) $immobile->id && (int) $subentro->condominio_id === (int) $condominio->id, 404);
        // Solo dal passaggio padre: la data si propaga alle pertinenze, e da un figlio non deve poter divergere (verifica S6, R12).
        abort_unless($subentro->subentro_padre_id === null, 404);
        // Il binding trova anche un passaggio annullato (beta.37): una seconda scheda rimasta aperta riceve il rifiuto nel
        // messaggio della pagina, prima della validazione, perché lo storico ricaricato non mostra più il campo della data.
        if ($subentro->annullato()) {
            return back()->with($this->flashError('Questo passaggio è stato annullato: la copia autentica non si registra più.'));
        }

        $dati = $request->validate([
            // «Oggi» nel fuso dell'utente, non del server (verifica S6, R10): fra mezzanotte e le due il giorno è già cambiato solo a Roma.
            'copia_autentica_il' => ['required', 'date', 'before_or_equal:' . \App\Helpers\DateHelper::oggiUtente()],
        ], [
            'copia_autentica_il.required' => 'Indica il giorno in cui il condominio ha ricevuto la copia autentica.',
            'copia_autentica_il.before_or_equal' => 'La copia autentica non può essere stata ricevuta in un giorno futuro.',
        ]);

        if ($subentro->tipo_passaggio !== 'vendita') {
            return back()->with($this->flashError('La copia autentica del titolo riguarda una vendita o donazione: questo passaggio non la prevede.'));
        }

        // Una data sola per il passaggio e le sue pertinenze: le righe `subentri` figlie la ricevono insieme (verifica S6, R12).
        $giorno = CarbonImmutable::parse($dati['copia_autentica_il'])->toDateString();
        $subentro->update(['copia_autentica_il' => $giorno]);
        $subentro->pertinenze()->update(['copia_autentica_il' => $giorno]);

        return back()->with($this->flashSuccess(sprintf(
            'Copia autentica registrata: dal %s %s è liberato verso il condominio per i contributi successivi (art. 63 co. 5 disp. att. c.c.).',
            CarbonImmutable::parse($dati['copia_autentica_il'])->locale('it')->translatedFormat('j F Y'),
            $subentro->uscente?->nome ?? 'chi ha venduto',
        )));
    }

    /**
     * Annulla un passaggio registrato (1.11.0-beta.37, decisione 27): l'ultimo dell'unità, a rate intatte, rileggendo il
     * suo registro al contrario. Le regole e i motivi del rifiuto stanno nell'action; qui si traducono in un 422 sulla
     * chiave `passaggio`, che lo storico mostra, e nel messaggio finale con ciò che resta da fare.
     */
    public function annulla(Request $request, Condominio $condominio, Immobile $immobile, Subentro $subentro, AnnullaPassaggioAction $action): RedirectResponse
    {
        abort_unless((int) $subentro->immobile_id === (int) $immobile->id && (int) $subentro->condominio_id === (int) $condominio->id, 404);
        // Una seconda scheda rimasta aperta: lo storico ricaricato mostra l'annullato senza modulo, quindi il rifiuto va nel
        // messaggio della pagina e non sotto un campo che non c'è più.
        if ($subentro->annullato()) {
            return back()->with($this->flashError('Questo passaggio è già stato annullato.'));
        }

        $dati = $request->validate([
            'nota_annullamento' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'nota_annullamento.required' => 'Scrivi perché annulli il passaggio: la nota resta nello storico.',
            'nota_annullamento.min' => 'La nota deve avere almeno dieci caratteri.',
        ]);

        try {
            $esito = $action->execute($subentro, $dati['nota_annullamento'], $request->user());
        } catch (ValidationException $e) {
            // Due conferme quasi insieme (due schede, due utenti): la seconda ha passato il controllo qui sopra, ha aspettato
            // la prima sotto lock e l'ha trovata annullata. Stesso messaggio della richiesta in sequenza: lo storico
            // ricaricato non ha più il modulo sotto cui mostrare l'errore (giro di verifica, C-R8).
            if ($subentro->fresh()?->annullato()) {
                return back()->with($this->flashError('Questo passaggio è già stato annullato.'));
            }
            throw $e;
        }

        // Solo ciò che il passaggio aveva davvero toccato: una locazione non sposta rate, una rinuncia non scrive conguaglio.
        $effetti = implode(' ', AnnullaPassaggioAction::frasiEffetti($esito, $esito['uscente'], $esito['entrante'], true));
        if ($esito['avvisi'] === []) {
            return back()->with($this->flashSuccess('Passaggio annullato. ' . $effetti . ' Resta nello storico, con la tua nota.'));
        }

        // Un avviso è una cosa ancora da fare: va in testa, in un messaggio che non si chiude da solo. Un successo si chiude
        // dopo sei secondi, e la scheda dello storico resta aperta sopra la pagina (giro di verifica, L-R1).
        return back()->with($this->flashWarning('Passaggio annullato. ' . implode(' ', $esito['avvisi']) . ' ' . $effetti . ' Resta nello storico, con la tua nota.'));
    }

    /**
     * Annulla il conguaglio di un passaggio (S6, voce 8): toglie insieme le righe di `saldi` del passaggio e
     * delle sue pertinenze, con una nota che resta. Le regole — solo righe libere, somma zero — stanno
     * nell'action; qui si traducono in un 422 sulla chiave `conguaglio`, che lo storico mostra.
     */
    public function annullaConguaglio(Request $request, Condominio $condominio, Immobile $immobile, Subentro $subentro, AnnullaConguaglioAction $action): RedirectResponse
    {
        abort_unless((int) $subentro->immobile_id === (int) $immobile->id && (int) $subentro->condominio_id === (int) $condominio->id, 404);
        if ($subentro->annullato()) {
            return back()->with($this->flashError('Questo passaggio è stato annullato, e il suo conguaglio con lui: non c\'è più niente da annullare.'));
        }

        $dati = $request->validate([
            'nota_annullamento_conguaglio' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'nota_annullamento_conguaglio.required' => 'Scrivi come le parti hanno regolato il conguaglio: la nota resta sul passaggio.',
            'nota_annullamento_conguaglio.min' => 'La nota deve avere almeno dieci caratteri.',
        ]);

        $tolte = $action->execute($subentro, $dati['nota_annullamento_conguaglio']);

        return back()->with($this->flashSuccess(sprintf(
            'Conguaglio annullato: %d righe tolte dai saldi della gestione. La nota resta sul passaggio, e le quote già emesse non cambiano.',
            $tolte,
        )));
    }

    /**
     * «Crea nuova anagrafica» dal dialogo inline di «Registra passaggio», senza lasciare la pagina.
     *
     * Stessa richiesta di validazione di `AnagraficaController::store()` — le regole sono una volta
     * sola — ma risposta JSON e persona **già associata al condominio**: è la ragione per cui esiste
     * (`anagrafica_condominio` è dove il gestionale legge chi appartiene allo stabile, vedi la nota in
     * `ImmobileAnagraficaController::store()`).
     */
    public function creaAnagrafica(CreateAnagraficaRequest $request, Condominio $condominio, Immobile $immobile): JsonResponse
    {
        $this->assicuraUnitaDelCondominio($condominio, $immobile);
        $dati = collect($request->validated())->except('condomini')->all();

        $anagrafica = Anagrafica::create($dati);
        $anagrafica->condomini()->syncWithoutDetaching([$condominio->id]);

        return response()->json([
            'id' => $anagrafica->id,
            'nome' => $anagrafica->nome,
            'codice_fiscale' => $anagrafica->codice_fiscale,
            'indirizzo' => $anagrafica->indirizzo,
        ], 201);
    }

    /**
     * L'unità dell'indirizzo appartiene al condominio dell'indirizzo. Lo garantisce già lo
     * `scopeBindings()` del gruppo; qui si ripete perché il controller fa cose **dopo** il binding — legge
     * un `riga_uscente_id` dal corpo, associa una persona al condominio — e la difesa in profondità è
     * l'unica che resta accesa il giorno che una rotta esce dal vincolo (`RotteAnnidateSenzaGuardiaTest`).
     */
    private function assicuraUnitaDelCondominio(Condominio $condominio, Immobile $immobile): void
    {
        abort_unless((int) $immobile->condominio_id === (int) $condominio->id, 404);
    }

    /** @return list<array<string, mixed>> */
    private function titolariPerIlModulo(Collection $titolari): array
    {
        return $titolari->map(fn (TitolaritaImmobile $t) => [
            'id' => $t->id,
            'anagrafica' => [
                'id' => $t->anagrafica?->id,
                'nome' => $t->anagrafica?->nome,
                'codice_fiscale' => $t->anagrafica?->codice_fiscale,
            ],
            'tipologia' => $t->tipologia,
            'quota' => $t->quota,
            'data_inizio' => $t->data_inizio?->toDateString(),
            'data_fine' => $t->data_fine?->toDateString(),
        ])->values()->all();
    }

    private function rateEmesseCount(Immobile $immobile): int
    {
        return \DB::table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate_quote.immobile_id', $immobile->id)
            ->where('rate.stato', 'emessa')
            ->count();
    }
}
