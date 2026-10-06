<?php

namespace App\Http\Requests\Gestionale\Immobile\Anagrafica;

use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Helpers\DateHelper;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use App\Services\Subentro\NudiDellEstinzione;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Il corpo di «Registra passaggio», per l'anteprima (`anteprima`) e per la scrittura (`store`, S5).
 *
 * Una sola richiesta per i due usi, di proposito: l'anteprima mostra le conseguenze **di questi dati**
 * e la scrittura li scrive; se validassero cose diverse, il pannello racconterebbe un'operazione e la
 * conferma ne farebbe un'altra («anteprima = scrittura», `B2InvariantiTodoTest`).
 *
 * Regole che non stanno in `rules()` perché guardano la banca dati (`withValidator`):
 * - la riga di chi esce appartiene a **questa** unità ed è in corso;
 * - la decorrenza è **dopo** l'inizio di chi esce (un periodo che finisce prima di cominciare non è
 *   un dato brutto, è un dato che il filtro temporale non sa leggere — nota gemella in
 *   `UpdateImmobileAnagraficaRequest`);
 * - chi entra non è chi esce (per ricomprare si registra una seconda vendita, non la stessa);
 * - il tipo decide chi è obbligatorio: la vendita e l'usufrutto vogliono uscente ed entrante, l'inizio
 *   di locazione solo l'entrante, la fine di locazione solo l'uscente («Nessuno — l'unità resta sfitta»).
 *
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class AnteprimaPassaggioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tipo = (string) $this->input('tipo');

        return [
            'tipo' => ['required', Rule::in(Subentro::TIPI_PASSAGGIO)],
            // L'usufrutto ha due forme; la vendita una sola, la riserva d'usufrutto (beta.38, decisione 28), che si
            // dichiara e non si deduce; la successione il legato (1.11.0-beta.44, decisione 66). I sottotipi di un tipo non valgono
            // per l'altro, e la locazione non ne ha (prima la fine locazione accettava «costituzione» e lo scriveva nel registro).
            'sottotipo' => ['nullable', 'required_if:tipo,usufrutto', Rule::prohibitedIf(fn () => in_array($tipo, ['inizio_locazione', 'fine_locazione'], true)),
                Rule::in(match ($tipo) { 'vendita' => [Subentro::RISERVA_USUFRUTTO], 'successione' => [Subentro::LEGATO], default => ['costituzione', 'estinzione'] })],
            'riga_uscente_id' => [
                // L'inizio di una locazione non ha chi esce (S8-20): una riga qui chiuderebbe il proprietario.
                Rule::prohibitedIf(fn () => $tipo === 'inizio_locazione'),
                Rule::requiredIf(fn () => in_array($tipo, ['vendita', 'fine_locazione', 'usufrutto', 'successione'], true)),
                'nullable', 'integer',
            ],
            'anagrafica_entrante_id' => [
                Rule::requiredIf(fn () => $tipo === 'vendita' || $tipo === 'inizio_locazione' || ($tipo === 'usufrutto' && $this->input('sottotipo') !== 'estinzione')),
                // Nella successione entrano gli eredi, in `eredi`: un solo campo per una persona sola nasconderebbe gli altri.
                Rule::prohibitedIf(fn () => $tipo === 'successione'),
                'nullable', 'integer', Rule::exists('anagrafiche', 'id'),
            ],
            // Decisione 65 (1.11.0-beta.44): gli eredi, ciascuno con la quota che eredita; le quote sommano a quella del defunto.
            'eredi' => [Rule::requiredIf(fn () => $tipo === 'successione'), Rule::prohibitedIf(fn () => $tipo !== 'successione'), 'nullable', 'array', 'min:1'],
            'eredi.*.anagrafica_id' => ['required', 'integer', 'distinct', Rule::exists('anagrafiche', 'id')],
            'eredi.*.quota' => ['required', 'numeric', 'gt:0', 'max:100'],
            // Decisione 65 (2): l'arretrato del defunto agli eredi per quota, o a suo nome. Nessun valore predefinito sul server, come la
            // scelta sull'ordinaria: un modulo che non la mostra si ferma qui invece di spostare denaro senza che nessuno l'abbia vista.
            'arretrato' => [Rule::requiredIf(fn () => $tipo === 'successione'), Rule::prohibitedIf(fn () => $tipo !== 'successione'), 'nullable',
                Rule::in([Subentro::ARRETRATO_AGLI_EREDI, Subentro::ARRETRATO_AL_DEFUNTO])],
            // Decisione 65 (3): l'erede che riceve le bozze di un piano già fermo; lo chiede il pannello solo quando serve.
            'erede_di_riferimento' => ['nullable', 'integer', Rule::prohibitedIf(fn () => $tipo !== 'successione')],
            // Nessun valore predefinito: la data del passaggio la dichiara l'amministratore (decisione 12,
            // «non si tira a indovinare»).
            // La data del decesso non è nel futuro (decisione 65): un atto si registra anche prima del giorno in cui vale, un decesso no.
            'decorrenza' => ['required', 'date', ...($tipo === 'successione' ? ['before_or_equal:' . DateHelper::oggiUtente()] : [])],
            'quota' => ['required', 'numeric', 'min:0', 'max:100'],
            // Il ruolo di chi entra è legato al tipo: una vendita non fa entrare un inquilino, una
            // locazione non fa entrare un proprietario. L'elenco per tipo sta in `ruoliEntrante()`.
            'tipologia' => ['required', Rule::in(self::ruoliEntrante($tipo, (string) $this->input('sottotipo')))],
            // Decisione 65: niente copia autentica nella successione (art. 63 co. 5 disp. att. c.c. riguarda chi cede, non chi muore).
            'copia_autentica' => ['required', 'boolean', 'declined_if:tipo,successione'],
            // S8-23: non si può aver ricevuto la copia autentica in un giorno futuro (stessa regola del PATCH dallo storico).
            'copia_autentica_il' => ['nullable', 'date', 'required_if:copia_autentica,true', 'before_or_equal:' . DateHelper::oggiUtente()],
            'estremi_titolo' => ['nullable', 'string', 'max:500'],
            'nota' => ['nullable', 'string', 'max:2000'],
            'data_fine_locazione' => ['nullable', 'date', 'after:decorrenza'],
            // Sul box locato il regime decide cosa spetta all'inquilino (§6.6): si chiede, non si deduce.
            'regime_contratto' => ['nullable', Rule::in(['abitativo', 'uso_diverso', 'atipica', 'comodato'])],
            'pertinenze' => ['array'],
            'pertinenze.*' => ['integer'],
            // Il cancello (1): letti da `store` in S5, accettati già qui perché il corpo è uno.
            'ho_letto' => ['nullable', 'boolean'],
            'nota_cancello' => ['nullable', 'string', 'max:2000'],
            // Copia del titolo o del contratto: `store` (S5) la salva fra i Documenti dell'unità, agganciata
            // al passaggio. Stesse regole di `CreateImmobileDocumentoRequest` (solo PDF, tetto di casa).
            'allegato_titolo' => ['nullable', 'file', 'mimes:pdf', 'max:' . \App\Support\LimiteCaricamento::regolaMax()],
            // Promemoria in agenda prima della scadenza del contratto (S5: `InboxService`).
            'promemoria_scadenza' => ['nullable', 'boolean'],
            'promemoria_giorni' => ['nullable', 'integer', Rule::in([30, 60, 90, 180]), 'required_if:promemoria_scadenza,true'],
            // S5: la rinuncia alla coppia di conguaglio proposta, con la ragione (Cass. 11199/2021 «salvo diverso accordo»).
            'rinuncia_conguaglio' => ['nullable', 'boolean'],
            'nota_conguaglio' => ['nullable', 'string', 'min:10', 'max:2000', 'required_if:rinuncia_conguaglio,true'],
            // Decisioni 31.5 e 31.6 (1.11.0-beta.41): alla costituzione e alla riserva d'usufrutto, chi paga l'ordinaria dal
            // giorno dell'atto. È obbligatoria (rilievo A3 della Fase 1-bis): una scelta che sposta le voci della tabella
            // intera non ha un valore predefinito sul server — un modulo rimasto aperto da prima dell'aggiornamento, che
            // non la mostra, si ferma qui invece di spostarle senza che nessuno le abbia viste. Le voci da tenere sul
            // «Proprietario» sono quelle a cui l'amministratore ha tolto la spunta: si mandano quelle e non le spuntate, così
            // un elenco vuoto non sparisce in un modulo multipart.
            'ordinaria_dopo_atto' => [
                Rule::requiredIf(fn () => ($tipo === 'usufrutto' && $this->input('sottotipo') !== 'estinzione')
                    || ($tipo === 'vendita' && $this->input('sottotipo') === Subentro::RISERVA_USUFRUTTO)),
                'nullable', Rule::in([Subentro::ORDINARIA_ALL_USUFRUTTUARIO, Subentro::ORDINARIA_COME_LA_VOCE]),
            ],
            // Decisione 57 (1.11.0-beta.43, D2): all'estinzione, con un altro usufrutto in corso sull'unità, i nudi proprietari che
            // tornano pieni li sceglie l'amministratore quando i dati non lo dicono (`NudiDellEstinzione`).
            'nudi_che_tornano' => ['nullable', 'array'],
            'nudi_che_tornano.*' => ['integer'],
            // Decisione 62: «tutti i nudi, ciascuno per la sua quota» (la donazione congiunta), al posto della scelta per nudi interi.
            'nudi_per_quota' => ['nullable', 'boolean'],
            // 1.11.0-beta.44: all'estinzione, l'atto prevede l'accrescimento all'altro usufruttuario (o è un legato di usufrutto
            // congiunto, artt. 675 e 678 c.c.): l'usufrutto di chi muore va agli usufruttuari che restano, e la nuda resta nuda.
            'accrescimento' => ['nullable', 'boolean'],
            'voci_da_tenere' => ['nullable', 'array'],
            'voci_da_tenere.*' => ['integer'],
            // Rilievo S1: l'impronta dell'elenco delle voci che il pannello ha mostrato (`VociDaSpostare::impronta()`).
            'ordinaria_impronta' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * L'anteprima arriva in JSON (booleani veri), la scrittura con un allegato arriva in multipart
     * (`"1"`/`"0"`): le tre caselle si normalizzano prima, o `required_if:…,true` legge due lingue.
     */
    protected function prepareForValidation(): void
    {
        $norm = [];
        foreach (['copia_autentica', 'ho_letto', 'promemoria_scadenza', 'rinuncia_conguaglio', 'accrescimento'] as $campo) {
            if ($this->has($campo)) {
                $norm[$campo] = filter_var($this->input($campo), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
        }
        if ($norm !== []) {
            $this->merge($norm);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            /** @var Immobile $immobile */
            $immobile = $this->route('immobile');
            $uscente = $this->rigaUscente();

            if ($this->filled('riga_uscente_id') && $uscente === null) {
                $v->errors()->add('riga_uscente_id', 'La riga di chi esce non appartiene a questa unità.');

                return;
            }

            if ($uscente !== null && ! $uscente->attivo) {
                $v->errors()->add('riga_uscente_id', 'Questa riga è disattivata: il passaggio si registra da un titolare in corso.');
            }

            if ($uscente !== null && $this->filled('decorrenza')) {
                $decorrenza = CarbonImmutable::parse($this->input('decorrenza'));
                if ($uscente->data_inizio !== null && $decorrenza->lte($uscente->data_inizio)) {
                    $v->errors()->add('decorrenza', sprintf(
                        'La data del passaggio deve essere successiva al %s, giorno in cui %s è diventato titolare.',
                        $uscente->data_inizio->locale('it')->translatedFormat('j F Y'),
                        $uscente->anagrafica?->nome ?? 'chi esce',
                    ));
                }
                // Una `data_fine` **futura** è una scadenza (§6.6: «non un automatismo»), e l'inquilino che
                // recede prima della scadenza è il caso principale di «Fine locazione»: il periodo è in
                // corso alla data dell'atto e si accetta (S5 lo chiuderà al giorno prima). Si rifiuta solo
                // il periodo finito **prima** del giorno che precede la decorrenza.
                if ($uscente->data_fine !== null && $uscente->data_fine->lt($decorrenza->subDay())) {
                    $v->errors()->add('riga_uscente_id', sprintf(
                        'Questo periodo si è chiuso il %s: il passaggio si registra da un titolare in corso alla data dell\'atto.',
                        $uscente->data_fine->locale('it')->translatedFormat('j F Y'),
                    ));
                } elseif ($uscente->data_fine !== null && $uscente->passaggioCheLaChiude() !== null) {
                    // Giro sulle correzioni della Fase 1-bis della .44 (G25): la riga di chi esce che un passaggio registrato dopo ha già
                    // chiuso — come chi esce, o sommandola nella riga nuova di chi riceve. La registrazione la richiudeva al giorno prima,
                    // sotto ciò che è successo dopo. Una data di fine scritta a mano (la scadenza della locazione, l'usufrutto a termine)
                    // resta accettata.
                    $v->errors()->add('riga_uscente_id', ucfirst(self::fraseUscenteChiusa($uscente)));
                }
            }

            // Il ruolo di chi esce deve combaciare con il tipo: un inquilino non «vende», un proprietario
            // non «finisce una locazione». Il client filtra già l'elenco; il server non si fida del client.
            if ($uscente !== null) {
                $ammessi = self::ruoliUscente((string) $this->input('tipo'), (string) $this->input('sottotipo'));
                if ($ammessi !== [] && ! in_array($uscente->tipologia, $ammessi, true)) {
                    $v->errors()->add('riga_uscente_id', sprintf(
                        '%s è %s: per registrare la sua uscita usa «%s».',
                        $uscente->anagrafica?->nome ?? 'Questa persona',
                        mb_strtolower(RuoloAnagraficaImmobile::tryFrom($uscente->tipologia)?->label() ?? $uscente->tipologia),
                        self::tipoPerRuolo($uscente->tipologia),
                    ));
                }
            }

            if ($uscente !== null && $this->filled('anagrafica_entrante_id')
                && (int) $this->input('anagrafica_entrante_id') === (int) $uscente->anagrafica_id) {
                $v->errors()->add('anagrafica_entrante_id', 'Chi entra non può essere la stessa persona che esce.');
            }

            // S8-21: nella vendita chi entra ha il ruolo di chi esce. Nuda → piena farebbe entrare come proprietario
            // pieno chi ha comprato solo la nuda (la quota ordinaria andrebbe a lui, art. 1004 c.c.). Piena → nuda è la
            // vendita con riserva d'usufrutto (beta.38, decisione 28): ha una sua via, ma si dichiara — il programma non
            // deduce che chi vende resta usufruttuario, e non sceglie al posto dell'amministratore.
            $riserva = (string) $this->input('tipo') === 'vendita' && (string) $this->input('sottotipo') === Subentro::RISERVA_USUFRUTTO;
            if ($uscente !== null && ! $riserva && (string) $this->input('tipo') === 'vendita' && $this->filled('tipologia')
                && (string) $this->input('tipologia') !== (string) $uscente->tipologia
                && in_array((string) $uscente->tipologia, ['proprietario', 'nuda_proprietario'], true)) {
                $v->errors()->add('tipologia', (string) $uscente->tipologia === 'nuda_proprietario'
                    ? 'Chi vende la nuda proprietà la passa come nuda proprietà: chi compra entra come nudo proprietario. Se nello stesso atto si estingue anche l\'usufrutto, registra poi «Usufrutto → estinzione» con la stessa data.'
                    // Testi T6 della beta.38: la via a mano non vale quanto un passaggio. Le righe aperte da «Associa soggetto» non
                    // hanno un predecessore e per il riparto valgono da sempre (D7 stretto, decisione 23). Testi T8: «o dona».
                    : 'Nella vendita chi entra ha il ruolo di chi esce. Se chi vende o dona si tiene l\'usufrutto, è una vendita o donazione con riserva d\'usufrutto: spunta «Chi vende o dona resta usufruttuario». Se l\'usufrutto va a un\'altra persona, registrala a mano — chiudi la riga di chi vende con una data di fine da «Modifica associazione», poi da «Associa soggetto» apri chi compra come nudo proprietario e l\'usufruttuario — senza conguaglio automatico. Per il riparto le righe aperte da «Associa soggetto» valgono da sempre: un piano generato o ricalcolato dopo addebita all\'usufruttuario le sue voci anche per i mesi prima dell\'atto.');
            }

            // La riserva d'usufrutto (decisione 28): da un proprietario pieno, sulla sua quota intera — con meno
            // resterebbe un usufrutto su tutta la quota e una nuda proprietà su una parte, e il resto di nessuno —, e chi
            // compra entra nudo proprietario.
            if ($riserva && $uscente !== null) {
                if ((string) $uscente->tipologia !== 'proprietario') {
                    $v->errors()->add('sottotipo', sprintf(
                        'La riserva d\'usufrutto si dichiara quando vende un proprietario pieno: %s è %s, e non ha un usufrutto da riservarsi.',
                        $uscente->anagrafica?->nome ?? 'chi vende',
                        mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $uscente->tipologia)?->label() ?? (string) $uscente->tipologia),
                    ));
                }
                if ($this->filled('tipologia') && (string) $this->input('tipologia') !== 'nuda_proprietario') {
                    $v->errors()->add('tipologia', 'Nella vendita o donazione con riserva d\'usufrutto chi compra entra come nudo proprietario: chi vende resta usufruttuario.');
                }
                if ($this->filled('quota') && abs((float) $this->input('quota') - (float) $uscente->quota) > 0.001) {
                    $v->errors()->add('quota', sprintf(
                        'Nella vendita o donazione con riserva d\'usufrutto chi compra riceve la nuda proprietà di tutta la quota di chi vende (%s %%): chi vende resta usufruttuario sulla stessa quota.',
                        rtrim(rtrim(number_format((float) $uscente->quota, 2, ',', '.'), '0'), ','),
                    ));
                }
            }

            // Decisione 37 (1.11.0-beta.42): il passaggio porta tutta la quota di chi esce. Con un numero diverso la registrazione
            // chiudeva tutta la riga di chi esce e apriva chi entra alla quota scritta, e il resto non era di nessuno: Elsa al
            // 100 % che «vende 50» a Carlo, e il riparto addebitava a Carlo anche l'altra metà. La vendita di una parte della
            // propria quota — chi vende resta sulla parte che tiene — è la Coda 175. Non all'estinzione, dove la quota del modulo
            // non conta (tornano pieni i nudi, ciascuno alla sua), né alla fine di una locazione senza un nuovo inquilino; la
            // riserva ha la sua frase qui sopra.
            $tipo = (string) $this->input('tipo');
            $portaLaQuota = ($tipo === 'vendita' && ! $riserva)
                || ($tipo === 'usufrutto' && (string) $this->input('sottotipo') !== 'estinzione')
                || ($tipo === 'fine_locazione' && $this->filled('anagrafica_entrante_id'))
                // La successione porta tutta la quota del defunto, divisa fra gli eredi (decisione 65).
                || $tipo === 'successione';
            if ($uscente !== null && $portaLaQuota && $this->filled('quota') && is_numeric($this->input('quota'))
                && abs((float) $this->input('quota') - (float) $uscente->quota) > 0.001) {
                $v->errors()->add('quota', sprintf(
                    'Il passaggio porta tutta la quota di %s (%s %%): passarne solo una parte non è ancora previsto. Se l\'atto trasferisce solo una parte della quota, registralo a mano: chiudi la riga di chi esce al giorno prima dell\'atto da «Modifica associazione», poi da «Associa soggetto» riapri chi esce alla quota che tiene e apri chi entra alla sua, dal giorno dell\'atto, senza conguaglio automatico. Una riga con lo stesso ruolo di quella chiusa conta dal giorno dell\'atto. Una riga con un ruolo che sull\'unità non c\'era, come l\'usufruttuario di una costituzione, oppure aperta dopo un buco fra le date, per il riparto vale da sempre: un piano generato o ricalcolato dopo gliela addebita anche per i mesi prima dell\'atto.',
                    $uscente->anagrafica?->nome ?? 'chi esce',
                    rtrim(rtrim(number_format((float) $uscente->quota, 2, ',', '.'), '0'), ','),
                ));
            }

            // All'estinzione, su ogni unità del passaggio (l'unità e le pertinenze spuntate): quali nudi tornano pieni (decisione 57,
            // la stessa regola della registrazione), e le guardie che guardano proprio quei nudi. Qui, perché anteprima e registrazione
            // dicano lo stesso. Rilievo A5 della Fase 1-bis della .43: le guardie guardavano tutte le nude in corso, anche quella di
            // un'altra parte che resta sotto un altro usufrutto e non diventa piena, e rifiutavano l'estinzione con una frase falsa.
            $estinzione = $tipo === 'usufrutto' && (string) $this->input('sottotipo') === 'estinzione';
            if ($this->boolean('accrescimento') && ! $estinzione) {
                $v->errors()->add('accrescimento', 'L\'accrescimento vale solo per l\'estinzione dell\'usufrutto.');
            }
            if ($estinzione && $this->filled('decorrenza')) {
                $giorno = CarbonImmutable::parse($this->input('decorrenza'))->startOfDay();
                $il = $giorno->locale('it')->translatedFormat('j F Y');
                $usufrutto = $this->rigaUscente();
                $scelti = $this->filled('nudi_che_tornano') ? array_map('intval', (array) $this->input('nudi_che_tornano')) : null;
                // Decisione 53 (rilievi X2 e X6 del terzo giro): le guardie valgono per l'unità e per ogni pertinenza spuntata, con il
                // nome della pertinenza — prima sul box nascevano due righe piene sovrapposte.
                $unitaDaControllare = collect([$immobile])->concat($immobile->pertinenze()->whereIn('id', array_map('intval', (array) $this->input('pertinenze', [])))->get());
                foreach ($unitaDaControllare as $unita) {
                    $principale = (int) $unita->id === (int) $immobile->id;
                    $prefisso = $principale ? '' : $unita->nome . ': ';
                    $titolarita = $unita->titolarita()->with('anagrafica')->get();
                    // L'usufrutto di chi esce su questa unità e i suoi nudi; sulle pertinenze la scelta non c'è.
                    $suo = $usufrutto === null ? null : ($principale ? $usufrutto
                        : $titolarita->first(fn (TitolaritaImmobile $t) => (int) $t->anagrafica_id === (int) $usufrutto->anagrafica_id && $t->tipologia === 'usufruttuario' && $t->inCorsoIl($giorno)));
                    // 1.11.0-beta.44: con l'accrescimento la nuda resta nuda, e le guardie dei nudi che tornano pieni non c'entrano. Servono
                    // gli altri usufruttuari, in corso il giorno prima e il giorno dell'atto (una riga nata quel giorno non si somma).
                    if ($this->boolean('accrescimento')) {
                        $altri = $suo === null ? collect() : $titolarita->filter(fn (TitolaritaImmobile $t) => $t->tipologia === 'usufruttuario' && (int) $t->id !== (int) $suo->id
                            && (int) $t->anagrafica_id !== (int) $suo->anagrafica_id && $t->inCorsoIl($giorno) && $t->inCorsoIl($giorno->subDay()));
                        if ($suo !== null && $altri->isEmpty()) {
                            // Rilievo L15 della Fase 1-bis della .44: un altro usufrutto nato il giorno dell'atto c'è, ma non riceve (non si somma
                            // una riga nata quel giorno); la frase non dice «nessuno». Rilievo L5: sulla pertinenza la spunta da togliere è la sua.
                            $natoOggi = $titolarita->first(fn (TitolaritaImmobile $t) => $t->tipologia === 'usufruttuario' && (int) $t->anagrafica_id !== (int) $suo->anagrafica_id
                                && $t->inCorsoIl($giorno) && $t->data_inizio !== null && $t->data_inizio->equalTo($giorno));
                            $chi = $suo->anagrafica?->nome ?? 'chi esce';
                            $frase = match (true) {
                                // Giro sulle correzioni (GC10): il programma sa solo la data, e sulla pertinenza la spunta da togliere è la sua.
                                $natoOggi !== null => sprintf('il %s comincia anche l\'usufrutto di %s, e l\'accrescimento non si somma a una riga che comincia lo stesso giorno: %s',
                                    $il, $natoOggi->anagrafica?->nome ?? 'un altro usufruttuario', $principale
                                        ? 'correggi le righe a mano da «Modifica associazione», oppure togli la spunta dell\'accrescimento.'
                                        : 'togli la spunta della pertinenza e registra la sua estinzione dalla pertinenza, oppure correggi le righe a mano da «Modifica associazione».'),
                                $principale => sprintf('il %s nessun altro usufruttuario risulta in corso: senza un altro usufruttuario l\'usufrutto di %s non si accresce, e torna al nudo proprietario. Togli la spunta dell\'accrescimento.', $il, $chi),
                                default => sprintf('il %s lì nessun altro usufruttuario risulta in corso, e l\'usufrutto di %s su questa pertinenza non si accresce: togli la sua spunta fra le pertinenze e registra la sua estinzione dalla pertinenza.', $il, $chi),
                            };
                            $v->errors()->add($principale ? 'accrescimento' : 'pertinenze', $principale ? ucfirst($frase) : $prefisso . $frase);
                        }
                        // Decisione 67 (2), rilievo X4: con la nuda di più nudi proprietari il programma non sa quale usufrutto stia sopra
                        // quale nuda, e la parte di chi esce andrebbe anche a chi sta sopra un'altra nuda.
                        $nudi = $suo === null ? collect() : \App\Services\Subentro\AnteprimaPassaggio::nudiDistintiIl($suo, $giorno);
                        if ($nudi->count() > 1) {
                            // Giro sulle correzioni (GB4, GB7): sulla pertinenza la strada è la sua; e il legato congiunto porta per legge all'accrescimento.
                            $elencoNudi = $nudi->count() === 2 ? $nudi->implode(' e ') : $nudi->slice(0, -1)->implode(', ') . ' e ' . $nudi->last();
                            $frase = $principale
                                ? sprintf('il %s la nuda proprietà di questa unità è di più nudi proprietari (%s), e il programma non sa quale usufrutto stia sopra quale nuda: l\'accrescimento si registra da qui solo quando la nuda è di un solo nudo proprietario. Se l\'atto prevede l\'accrescimento, o l\'usufrutto è un legato a più persone insieme (artt. 675 e 678 c.c.), correggi le righe a mano da «Modifica associazione», senza conguaglio automatico; altrimenti togli la spunta dell\'accrescimento, e la parte di %s torna alla nuda come vuole la legge negli atti fra vivi.',
                                    $il, $elencoNudi, $suo->anagrafica?->nome ?? 'chi esce')
                                : sprintf('il %s la nuda proprietà di questa pertinenza è di più nudi proprietari (%s), e il programma non sa quale usufrutto stia sopra quale nuda: togli la spunta della pertinenza e registra la sua estinzione dalla pertinenza.',
                                    $il, $elencoNudi);
                            $v->errors()->add($principale ? 'accrescimento' : 'pertinenze', $principale ? ucfirst($frase) : $prefisso . $frase);
                        }
                        // Rilievo X1 della Fase 1-bis della .44: un altro usufruttuario la cui riga ha già una data di fine. La registrazione
                        // la chiudeva al giorno prima e apriva l'usufrutto accresciuto senza fine, sopra ciò che è successo dopo. Le frasi
                        // della decisione 53 (2) e del rilievo T2: rifare i passaggi in ordine, o correggere a mano.
                        foreach ($altri->filter(fn (TitolaritaImmobile $t) => $t->data_fine !== null) as $chiusa) {
                            $chi = $chiusa->anagrafica?->nome ?? 'Un usufruttuario';
                            $v->errors()->add('decorrenza', $prefisso . ($chiusa->passaggioCheLaChiude() !== null
                                ? sprintf('%s era usufruttuario di questa unità il %s, e un passaggio registrato dopo ha chiuso quella riga dal %s: con l\'accrescimento la sua parte cresce dal giorno dell\'estinzione, e il passaggio dopo andrebbe registrato sull\'usufrutto accresciuto. Annulla prima dallo storico dell\'unità i passaggi successivi, l\'ultimo per primo, registra l\'estinzione con l\'accrescimento e poi di nuovo quei passaggi; se l\'atto dice altro, correggi le righe a mano da «Modifica associazione».',
                                    $chi, $il, CarbonImmutable::parse($chiusa->data_fine)->addDay()->locale('it')->translatedFormat('j F Y'))
                                : sprintf('%s era usufruttuario di questa unità il %s, e la sua riga ha una data di fine scritta a mano, il %s: con l\'accrescimento la sua parte cresce dal giorno dell\'estinzione, e il programma non sa fino a quando. Correggi le righe a mano da «Modifica associazione» prima di registrare l\'estinzione.',
                                    $chi, $il, CarbonImmutable::parse($chiusa->data_fine)->locale('it')->translatedFormat('j F Y'))));
                        }
                        continue;
                    }
                    $regola = $suo !== null ? app(NudiDellEstinzione::class)->per($suo, $giorno, $principale ? $scelti : null, $principale && $this->boolean('nudi_per_quota')) : null;
                    // Un usufrutto senza nessun nudo proprietario in corso non ha una nuda a cui tornare: la registrazione chiudeva l'usufrutto
                    // senza far tornare pieno nessuno, e l'unità restava senza proprietario. Prima la fermava solo il modulo (1.11.0-beta.44).
                    if ($regola !== null && $regola['errore'] === null && $regola['nudi']->isEmpty()) {
                        // Rilievo L14 della Fase 1-bis della .44: la nuda censita come proprietà piena accanto all'usufrutto. Associarla di nuovo
                        // come nuda portava allo sforo; la strada è correggere il ruolo della riga che c'è.
                        // Giro sulle correzioni (GB8): solo quando la piena si sovrappone all'usufrutto; nell'unità mista la piena dell'altra metà è
                        // legittima, e la strada resta associare la nuda.
                        $pieno = $titolarita->first(fn (TitolaritaImmobile $t) => $t->tipologia === 'proprietario' && $t->inCorsoIl($giorno)
                            && (float) $t->quota + (float) $suo->quota > 100.001);
                        $frase = $pieno !== null
                            ? sprintf('il %s nessun nudo proprietario risulta in corso, e %s risulta proprietario pieno accanto all\'usufrutto di %s: se ha la nuda proprietà, correggi il ruolo della sua riga in «nudo proprietario» da «Modifica associazione», poi registra l\'estinzione.',
                                $il, $pieno->anagrafica?->nome ?? 'un proprietario', $suo->anagrafica?->nome ?? 'chi esce')
                            : sprintf('il %s nessun nudo proprietario risulta in corso: l\'usufrutto di %s non ha una nuda proprietà a cui tornare. Associa prima il nudo proprietario da «Associa soggetto», poi registra l\'estinzione.',
                            $il, $suo->anagrafica?->nome ?? 'chi esce');
                        $v->errors()->add($principale ? 'estinzione' : 'pertinenze', $principale ? ucfirst($frase) : $prefisso . $frase);
                        continue;
                    }
                    // Le nude a cui guardano le guardie: quelle che tornano piene. Senza la regola (manca la riga d'usufrutto, e un altro
                    // controllo lo rifiuta già) tutte le nude in corso, come prima.
                    $tornano = $regola === null ? null : $regola['nudi']->map(fn (TitolaritaImmobile $t) => (int) $t->id)->all();
                    $torna = fn (TitolaritaImmobile $t) => $tornano === null || in_array((int) $t->id, $tornano, true);

                    // Giro sulle correzioni della Fase 1-bis della .44 (G1, G2): il nudo che torna pieno è già proprietario pieno di un'altra
                    // parte, e quella riga ha una data di fine. La registrazione la chiudeva al giorno prima e sommava senza fine (anche il
                    // nudo nato oggi della decisione 36), sopra ciò che è successo dopo. Il nudo che non consolida niente si lascia stare.
                    foreach ($regola['nudi'] ?? [] as $nudo) {
                        if (isset($regola['consolida'][(int) $nudo->id]) && (float) $regola['consolida'][(int) $nudo->id] <= 0) {
                            continue;
                        }
                        // Con il nudo nato il giorno dell'atto, la piena nata anch'essa quel giorno ha la sua frase (decisione 36, qui sotto):
                        // qui solo quelle nate prima. Con il nudo nato prima, tutte (ultima revisione, UD2).
                        $natoOggi = $nudo->data_inizio !== null && $nudo->data_inizio->equalTo($giorno);
                        if (($frase = $this->fraseRigaGiaChiusa($unita, (int) $nudo->anagrafica_id, 'proprietario', $giorno, 'la nuda proprietà che torna piena', natePrima: $natoOggi)) !== null) {
                            $v->errors()->add('decorrenza', $prefisso . $frase);
                        }
                    }

                    // Decisione 36, rilievo R9 della Fase 1-bis della .42: un nudo proprietario nato oggi che è anche proprietario pieno da
                    // una riga nata oggi (la nuda comprata e un'altra parte avuta lo stesso giorno). La riga piena non si chiude a ieri
                    // (sarebbe rovesciata) e non si somma (una delle due dovrebbe sparire, e il registro non lo sa disfare): la nuda si
                    // registra prima dell'estinzione e la piena dopo, che allora somma da sé.
                    $nateOggi = $titolarita->filter(fn (TitolaritaImmobile $t) => $t->data_inizio !== null && $t->data_inizio->equalTo($giorno) && $t->inCorsoIl($giorno));
                    foreach ($nateOggi->where('tipologia', 'nuda_proprietario')->filter($torna) as $nudo) {
                        $piena = $nateOggi->first(fn (TitolaritaImmobile $t) => $t->tipologia === 'proprietario' && (int) $t->anagrafica_id === (int) $nudo->anagrafica_id);
                        if ($piena !== null) {
                            $v->errors()->add('decorrenza', $prefisso . $this->fraseNudaEPienaDelloStessoGiorno($unita, $nudo, $piena, $giorno));
                            break;
                        }
                    }
                    // Decisione 53 (2): un nudo proprietario in corso il giorno dell'estinzione, la cui riga un passaggio registrato dopo ha
                    // già chiuso. Dal giorno dell'estinzione quella nuda proprietà è piena: il passaggio dopo andrebbe rifatto sulla
                    // proprietà piena. Registrata così, chi aveva venduto la nuda restava proprietario pieno in silenzio.
                    $chiusa = $titolarita->first(fn (TitolaritaImmobile $t) => $t->tipologia === 'nuda_proprietario' && $torna($t) && $t->inCorsoIl($giorno) && $t->data_fine !== null);
                    // Rilievo T2 del quarto giro: una riga chiusa a mano (la via a mano della decisione 37) non ha un passaggio da annullare.
                    // Giro sulle correzioni (G3): un passaggio la chiude anche nel suo registro (la nuda di un'estinzione, una somma).
                    if ($chiusa !== null && $chiusa->passaggioCheLaChiude() === null) {
                        $v->errors()->add('decorrenza', $prefisso . sprintf(
                            '%s era nudo proprietario di questa unità il %s, e la sua riga è stata chiusa a mano dal %s: dal giorno dell\'estinzione quella nuda proprietà diventa piena. Correggi le righe a mano da «Modifica associazione» prima di registrare l\'estinzione.',
                            $chiusa->anagrafica?->nome ?? 'Un nudo proprietario', $il, CarbonImmutable::parse($chiusa->data_fine)->addDay()->locale('it')->translatedFormat('j F Y')));
                    } elseif ($chiusa !== null) {
                        $v->errors()->add('decorrenza', $prefisso . sprintf(
                            '%s era nudo proprietario di questa unità il %s, e un passaggio registrato dopo ha chiuso quella riga dal %s: dal giorno dell\'estinzione quella nuda proprietà diventa piena, e il passaggio dopo andrebbe registrato sulla proprietà piena. Annulla prima dallo storico dell\'unità i passaggi successivi, l\'ultimo per primo, registra l\'estinzione e poi di nuovo quei passaggi dalla riga piena; se l\'atto dice altro, correggi le righe a mano da «Modifica associazione».',
                            $chiusa->anagrafica?->nome ?? 'Un nudo proprietario', $il, CarbonImmutable::parse($chiusa->data_fine)->addDay()->locale('it')->translatedFormat('j F Y')));
                    }
                    // Rilievo A6 della Fase 1-bis della .43: una nuda che torna piena solo in parte (il consolidamento) ed è nata il giorno
                    // dell'estinzione. La registrazione la rifiutava dopo un'anteprima accettata.
                    foreach ($regola['consolida'] ?? [] as $id => $quota) {
                        $nata = $titolarita->firstWhere('id', (int) $id);
                        if ($nata?->data_inizio !== null && $nata->data_inizio->equalTo($giorno)) {
                            $frase = NudiDellEstinzione::fraseNudaNataLoStessoGiorno($nata->anagrafica?->nome);
                            $v->errors()->add('decorrenza', $prefisso === '' ? $frase : $prefisso . mb_lcfirst($frase));
                            break;
                        }
                    }
                    // Decisione 57: la scelta dei nudi. Decisione 61: il programma si ferma, e il modulo non mostra caselle.
                    if ($regola !== null && $regola['errore'] !== null) {
                        if ($principale) {
                            $v->errors()->add($regola['fermo'] ? 'estinzione' : 'nudi_che_tornano', $regola['errore']);
                        } else {
                            // Rilievo GT5: sulla pertinenza la via è togliere la spunta, e registrare l'estinzione lì a mano.
                            $v->errors()->add('pertinenze', $regola['fermo'] ? $unita->nome . ': ' . mb_lcfirst($regola['errore']) . ' Togli la spunta per registrare da qui l\'estinzione dell\'unità principale.'
                                : sprintf('%s: anche lì c\'è un altro usufrutto in corso, e il programma non sa quali nudi proprietari tornano proprietari pieni. Togli la spunta e registra l\'estinzione dalla pertinenza, dove si sceglie.', $unita->nome));
                        }
                    }
                }
            }

            // Lente di sicurezza (1.11.0-beta.44): chi entra è una persona di questo condominio. Prima il server controllava solo che
            // l'anagrafica esistesse: un passaggio poteva aprire una riga a una persona di un altro condominio, che il modulo non propone.
            /** @var Immobile $immobile */
            $delCondominio = fn (int $id) => \Illuminate\Support\Facades\DB::table('anagrafica_condominio')->where('anagrafica_id', $id)->where('condominio_id', $immobile->condominio_id)->exists();
            if ($this->filled('anagrafica_entrante_id') && ! $delCondominio((int) $this->input('anagrafica_entrante_id'))) {
                $v->errors()->add('anagrafica_entrante_id', 'Chi entra non è una persona di questo condominio: sceglila dall\'elenco, o creala dal modulo.');
            }

            if ($tipo === 'successione') {
                $this->controllaSuccessione($v, $immobile, $uscente, $delCondominio);
            }
            // Rilievo X1 della Fase 1-bis della .44 (il caso c'era già prima): chi compra è già titolare con lo stesso ruolo, e la sua riga
            // ha una data di fine.
            if ($tipo === 'vendita' && $this->filled('anagrafica_entrante_id') && $this->filled('decorrenza') && $this->filled('tipologia')) {
                foreach ($this->unitaDelPassaggio($immobile) as $unita) {
                    $frase = $this->fraseRigaGiaChiusa($unita, (int) $this->input('anagrafica_entrante_id'), (string) $this->input('tipologia'),
                        CarbonImmutable::parse((string) $this->input('decorrenza'))->startOfDay(), 'la quota comprata');
                    if ($frase !== null) {
                        $v->errors()->add('anagrafica_entrante_id', ((int) $unita->id === (int) $immobile->id ? '' : $unita->nome . ': ') . $frase);
                    }
                }
            }
            // Giro sulle correzioni (G1): nella riserva l'usufrutto che chi vende tiene, nella costituzione la nuda che chi costituisce tiene,
            // si sommano a una riga che ha già; con una data di fine la registrazione si fermava solo alla fine, dopo un'anteprima pulita.
            $riserva = $tipo === 'vendita' && (string) $this->input('sottotipo') === Subentro::RISERVA_USUFRUTTO;
            $costituzione = $tipo === 'usufrutto' && (string) $this->input('sottotipo') === 'costituzione';
            if ($uscente !== null && $this->filled('decorrenza') && ($riserva || $costituzione)) {
                [$ruolo, $cheCosa] = $riserva ? ['usufruttuario', 'l\'usufrutto riservato'] : ['nuda_proprietario', 'la nuda proprietà che resta'];
                foreach ($this->unitaDelPassaggio($immobile) as $unita) {
                    $frase = $this->fraseRigaGiaChiusa($unita, (int) $uscente->anagrafica_id, $ruolo, CarbonImmutable::parse((string) $this->input('decorrenza'))->startOfDay(), $cheCosa);
                    if ($frase !== null) {
                        $v->errors()->add('decorrenza', ((int) $unita->id === (int) $immobile->id ? '' : $unita->nome . ': ') . $frase);
                    }
                }
            }

            // Nella locazione e nell'usufrutto chi entra non è già titolare dell'unità: un proprietario non
            // è inquilino di se stesso, e il nudo proprietario non è il proprio usufruttuario. Nella vendita
            // invece un comproprietario può comprare la quota dell'altro: là si esclude solo chi esce.
            if ($this->filled('anagrafica_entrante_id') && in_array($this->input('tipo'), ['inizio_locazione', 'fine_locazione', 'usufrutto'], true)) {
                $oggi = DateHelper::oggiUtenteImmutable();
                $giaTitolare = $immobile->titolarita()->with('anagrafica')->where('anagrafica_id', (int) $this->input('anagrafica_entrante_id'))->get()
                    ->first(fn (TitolaritaImmobile $t) => $t->inCorsoIl($oggi));
                if ($giaTitolare !== null) {
                    $v->errors()->add('anagrafica_entrante_id', sprintf(
                        '%s è già %s di questa unità: non può entrare anche come %s.',
                        $giaTitolare->anagrafica?->nome ?? 'Questa persona',
                        mb_strtolower(RuoloAnagraficaImmobile::tryFrom($giaTitolare->tipologia)?->label() ?? $giaTitolare->tipologia),
                        mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $this->input('tipologia'))?->label() ?? (string) $this->input('tipologia')),
                    ));
                }
            }

            $pertinenze = array_map('intval', (array) $this->input('pertinenze', []));
            if ($pertinenze !== []) {
                $valide = $immobile->pertinenze()->whereIn('id', $pertinenze)->get();
                if ($valide->count() !== count($pertinenze)) {
                    $v->errors()->add('pertinenze', 'Una delle pertinenze indicate non è collegata a questa unità.');
                }
                // «Anteprima = scrittura» anche qui: chi esce deve essere titolare della pertinenza, con lo stesso
                // ruolo, il giorno prima della decorrenza — è lo stesso controllo che la registrazione fa, e
                // farlo già nell'anteprima ferma il pulsante prima del clic (verifica S5, R12).
                if ($uscente !== null && $this->filled('decorrenza')) {
                    $giornoPrima = CarbonImmutable::parse((string) $this->input('decorrenza'))->subDay();
                    foreach ($valide as $pertinenza) {
                        $li = $pertinenza->titolarita()->with('anagrafica')->where('anagrafica_id', $uscente->anagrafica_id)->where('tipologia', $uscente->tipologia)->get()
                            ->first(fn (TitolaritaImmobile $t) => $t->inCorsoIl($giornoPrima));
                        // Ultima revisione (UD1): la regola di G25 anche sulla pertinenza, come la registrazione.
                        if ($li !== null && $li->data_fine !== null && $li->passaggioCheLaChiude() !== null) {
                            $v->errors()->add('pertinenze', $pertinenza->nome . ': ' . self::fraseUscenteChiusa($li));
                            break;
                        }
                        if ($li === null) {
                            $ruolo = mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $uscente->tipologia)?->label() ?? $uscente->tipologia);
                            $v->errors()->add('pertinenze', sprintf('%s: %s non risulta %s alla data del passaggio. Togli la spunta, o registra il passaggio dalla pertinenza.', $pertinenza->nome, $uscente->anagrafica?->nome ?? 'chi esce', $ruolo));
                            break;
                        }
                    }
                }
            }
        });
    }

    /** I nomi dei campi come li legge l'amministratore: senza, Laravel scrive «copia autentica il è richiesto quando copia autentica è true». */
    public function attributes(): array
    {
        return [
            'tipo' => 'il tipo di passaggio',
            'sottotipo' => 'la forma del passaggio',
            'eredi' => 'gli eredi',
            'eredi.*.anagrafica_id' => 'l\'erede',
            'eredi.*.quota' => 'la quota dell\'erede',
            'arretrato' => 'l\'arretrato del defunto',
            'erede_di_riferimento' => 'l\'erede di riferimento',
            'riga_uscente_id' => 'chi esce',
            'anagrafica_entrante_id' => 'chi entra',
            'decorrenza' => 'la data dell\'atto',
            'quota' => 'la quota',
            'tipologia' => 'il ruolo',
            'copia_autentica' => 'la copia autentica',
            'copia_autentica_il' => 'la data di ricezione della copia autentica',
            'estremi_titolo' => 'gli estremi dell\'atto',
            'nota' => 'la nota',
            'data_fine_locazione' => 'la scadenza del contratto',
            'regime_contratto' => 'il regime del contratto',
            'pertinenze' => 'le pertinenze',
            'nota_cancello' => 'la nota di conferma',
            'allegato_titolo' => 'l\'allegato',
            'promemoria_giorni' => 'l\'anticipo del promemoria',
            'nota_conguaglio' => 'la ragione della rinuncia al conguaglio',
        ];
    }

    public function messages(): array
    {
        return [
            'copia_autentica_il.required_if' => 'Indica il giorno in cui hai ricevuto la copia autentica del titolo.',
            'data_fine_locazione.after' => 'La scadenza del contratto deve essere successiva alla data di inizio della locazione.',
            'tipologia.in' => 'Questo ruolo non è ammesso per il tipo di passaggio scelto.',
            'copia_autentica_il.before_or_equal' => 'La copia autentica non può essere stata ricevuta in un giorno futuro.',
            'anagrafica_entrante_id.required' => 'Scegli chi entra, o crea una nuova anagrafica.',
            'riga_uscente_id.required' => 'Scegli chi esce fra i titolari in corso.',
            'riga_uscente_id.prohibited' => 'L\'inizio di una locazione non ha chi esce: togli la riga, o usa «Fine locazione» per cambiare inquilino.',
            'decorrenza.required' => 'Scrivi la data dell\'atto: il programma non ne sceglie una al posto tuo.',
            'allegato_titolo.mimes' => 'L\'allegato deve essere un PDF.',
            'promemoria_giorni.required_if' => 'Scegli con quanto anticipo vuoi il promemoria.',
            'nota_conguaglio.required_if' => 'Hai rinunciato al conguaglio proposto: scrivi perché (almeno dieci caratteri).',
            'ordinaria_dopo_atto.required' => 'Manca la scelta su chi paga l\'ordinaria dal giorno dell\'atto: ricarica la pagina e scegli.',
            'eredi.required' => 'Indica almeno un erede, con la sua quota.',
            'eredi.min' => 'Indica almeno un erede, con la sua quota.',
            'eredi.*.anagrafica_id.distinct' => 'La stessa persona compare due volte fra gli eredi.',
            'eredi.*.anagrafica_id.required' => 'Scegli la persona di ogni erede, o creala dal modulo.',
            'eredi.*.quota.gt' => 'La quota di ogni erede dev\'essere più di zero.',
            'arretrato.required' => 'Manca la scelta sull\'arretrato del defunto: agli eredi per quota, o a suo nome.',
            'decorrenza.before_or_equal' => 'La data del decesso non può essere nel futuro.',
            'copia_autentica.declined_if' => 'Nella successione non c\'è una copia autentica del titolo: allega, se vuoi, la dichiarazione di successione.',
            'anagrafica_entrante_id.prohibited' => 'Nella successione entrano gli eredi: indicali nell\'elenco degli eredi.',
        ];
    }

    /** I ruoli che chi entra può avere, per tipo di passaggio (e sottotipo dell'usufrutto). */
    public static function ruoliEntrante(string $tipo, string $sottotipo = ''): array
    {
        return match ($tipo) {
            'vendita' => ['proprietario', 'nuda_proprietario'],
            'inizio_locazione', 'fine_locazione' => ['inquilino'],
            'usufrutto' => $sottotipo === 'estinzione' ? ['proprietario'] : ['usufruttuario'],
            // Gli eredi entrano nel ruolo del defunto (decisione 64): proprietari, o nudi con l'usufrutto che continua.
            'successione' => ['proprietario', 'nuda_proprietario'],
            default => RuoloAnagraficaImmobile::values(),
        };
    }

    /** I ruoli che chi esce deve avere, per tipo; vuoto = nessun vincolo (l'inizio di locazione non ha un uscente). */
    public static function ruoliUscente(string $tipo, string $sottotipo = ''): array
    {
        return match ($tipo) {
            'vendita' => ['proprietario', 'nuda_proprietario'],
            'fine_locazione' => ['inquilino'],
            'usufrutto' => $sottotipo === 'estinzione' ? ['usufruttuario'] : ['proprietario'],
            // Decisione 64: muore un proprietario, pieno o comproprietario, o un nudo proprietario. La morte dell'usufruttuario è
            // l'estinzione dell'usufrutto, quella dell'inquilino la fine della locazione.
            'successione' => ['proprietario', 'nuda_proprietario'],
            default => [],
        };
    }

    /** Il nome della voce di menu che fa uscire un titolare con quel ruolo. */
    private static function tipoPerRuolo(string $ruolo): string
    {
        return match ($ruolo) {
            'inquilino' => 'Fine locazione',
            'usufruttuario' => 'Usufrutto → estinzione',
            default => 'Vendita o donazione',
        };
    }

    /**
     * Le regole della successione che guardano la banca dati (decisioni 64, 65 e 66): gli eredi entrano nel ruolo del defunto con
     * quote che sommano alla sua, sono persone distinte del condominio e non il defunto; il legatario lascia l'arretrato a nome del
     * defunto; l'erede di riferimento è uno degli eredi; un erede che ha già l'usufrutto o la locazione dell'unità ferma il passaggio
     * con la via a mano (decisione 66, punto 2). L'erede di riferimento **obbligatorio** lo chiede il calcolo (`AnteprimaPassaggio`),
     * perché solo lui sa se ci sono bozze da passare.
     */
    private function controllaSuccessione(Validator $v, Immobile $immobile, ?TitolaritaImmobile $uscente, \Closure $delCondominio): void
    {
        $eredi = collect((array) $this->input('eredi', []))->filter(fn ($e) => is_array($e) && is_numeric($e['anagrafica_id'] ?? null))->values();
        foreach ($eredi as $i => $e) {
            if (! $delCondominio((int) $e['anagrafica_id'])) {
                $v->errors()->add("eredi.{$i}.anagrafica_id", 'Questo erede non è una persona di questo condominio: scegli la persona dall\'elenco, o creala dal modulo.');
            }
        }
        if ($uscente === null || $eredi->isEmpty()) {
            return;
        }
        $nome = $uscente->anagrafica?->nome ?? 'questa persona';
        if ($eredi->contains(fn ($e) => (int) $e['anagrafica_id'] === (int) $uscente->anagrafica_id)) {
            $v->errors()->add('eredi', sprintf('%s non può essere fra i suoi eredi.', $nome));
        }
        if ($this->filled('tipologia') && (string) $this->input('tipologia') !== (string) $uscente->tipologia) {
            $v->errors()->add('tipologia', sprintf('Gli eredi entrano nel ruolo di %s: %s.', $nome,
                mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $uscente->tipologia)?->label() ?? (string) $uscente->tipologia)));
        }
        // Le quote in centesimi di punto interi, come la scelta dei nudi (decisione 62): niente somme in virgola mobile. Rilievo X12 della
        // Fase 1-bis: una quota sotto lo 0,01 % diventava zero alla scrittura, e l'erede restava con le bozze senza una riga; con tre
        // decimali il programma registrava in silenzio una quota diversa da quella scritta.
        foreach ((array) $this->input('eredi', []) as $i => $e) {
            $centesimi = is_array($e) && is_numeric($e['quota'] ?? null) ? (float) $e['quota'] * 100 : null;
            if ($centesimi !== null && $centesimi > 0 && (round($centesimi) < 1 || abs($centesimi - round($centesimi)) > 1e-6)) {
                $v->errors()->add("eredi.{$i}.quota", 'La quota di ogni erede può avere al massimo due decimali e dev\'essere almeno 0,01 %.');
            }
        }
        $somma = (int) $eredi->sum(fn ($e) => (int) round((float) ($e['quota'] ?? 0) * 100));
        $delDefunto = (int) round((float) $uscente->quota * 100);
        if ($eredi->every(fn ($e) => is_numeric($e['quota'] ?? null)) && $somma !== $delDefunto) {
            $v->errors()->add('eredi', sprintf('Le quote degli eredi sommano %s %%, quella di %s è %s %%: devono coincidere.', self::numero($somma / 100), $nome, self::numero($delDefunto / 100)));
        }
        $legato = (string) $this->input('sottotipo') === Subentro::LEGATO;
        if ($legato && (string) $this->input('arretrato') === Subentro::ARRETRATO_AGLI_EREDI) {
            $v->errors()->add('arretrato', 'Chi riceve l\'unità per legato non eredita il patrimonio: l\'arretrato del defunto resta a suo nome («eredi di …») e ne rispondono gli eredi.');
        }
        // Con l'arretrato agli eredi la coppia e l'arretrato sono un conto solo (l'arretrato è la posizione del defunto meno la coppia):
        // senza la coppia ciascun erede riceverebbe una cifra senza senso.
        if ($this->boolean('rinuncia_conguaglio') && (string) $this->input('arretrato') === Subentro::ARRETRATO_AGLI_EREDI) {
            // Decisione 69 (2): il messaggio non manda più alla rinuncia «se gli eredi hanno regolato fra loro». Con l'arretrato a nome
            // del defunto la rinuncia lascia tutte le bozze all'erede di riferimento, e gli altri eredi non pagano niente nel programma.
            $v->errors()->add('rinuncia_conguaglio', 'Con l\'arretrato agli eredi il conguaglio e l\'arretrato fanno un conto solo: insieme danno a ciascun erede la sua quota di tutto ciò che il defunto ha lasciato aperto, e non si può rinunciare al solo conguaglio.');
        }
        if ($this->filled('erede_di_riferimento') && ! $eredi->contains(fn ($e) => (int) $e['anagrafica_id'] === (int) $this->input('erede_di_riferimento'))) {
            $v->errors()->add('erede_di_riferimento', 'L\'erede di riferimento deve essere uno degli eredi.');
        }
        // Decisione 66 (2): l'erede che ha già l'usufrutto dell'unità, quando muore il nudo proprietario, riunisce nella stessa persona
        // usufrutto e nuda proprietà di quella parte; e un erede inquilino diventerebbe proprietario di ciò che ha in locazione.
        if (! $this->filled('decorrenza')) {
            return;
        }
        $giorno = CarbonImmutable::parse((string) $this->input('decorrenza'))->startOfDay();
        // Rilievo L16 della Fase 1-bis della .44: il controllo vale anche per le pertinenze spuntate, con il loro nome; e non dice che
        // usufrutto e nuda «si riuniscono» per quella parte, perché l'usufrutto dell'erede può stare sopra la nuda di un altro.
        foreach ($this->unitaDelPassaggio($immobile) as $unita) {
            $prefisso = (int) $unita->id === (int) $immobile->id ? '' : $unita->nome . ': ';
            $inCorso = $unita->titolarita()->with('anagrafica')->whereIn('anagrafica_id', $eredi->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->get()
                ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($giorno));
            if ((string) $uscente->tipologia === 'nuda_proprietario' && ($u = $inCorso->firstWhere('tipologia', 'usufruttuario')) !== null) {
                $v->errors()->add('eredi', $prefisso . sprintf('%s ha già l\'usufrutto di questa unità: ereditando la nuda proprietà di %s, usufrutto e nuda proprietà possono riunirsi nella stessa persona, e il programma non lo registra ancora. Registra la successione a mano: chiudi da «Modifica associazione» la riga di %s al giorno prima del decesso e correggi le righe degli eredi secondo la dichiarazione di successione, senza conguaglio automatico.',
                    $u->anagrafica?->nome ?? 'Un erede', $nome, $nome));
            }
            if (($u = $inCorso->firstWhere('tipologia', 'inquilino')) !== null) {
                $v->errors()->add('eredi', $prefisso . sprintf('%s è inquilino di questa unità: prima registra la fine della locazione («Fine locazione»), poi la successione.', $u->anagrafica?->nome ?? 'Un erede'));
            }
        }
        // Rilievo X1 della Fase 1-bis: l'erede già titolare con lo stesso ruolo, la cui riga ha una data di fine.
        foreach ($this->unitaDelPassaggio($immobile) as $unita) {
            foreach ($eredi as $e) {
                $frase = $this->fraseRigaGiaChiusa($unita, (int) $e['anagrafica_id'], (string) $uscente->tipologia, $giorno, 'la quota ereditata');
                if ($frase !== null) {
                    $v->errors()->add('eredi', ((int) $unita->id === (int) $immobile->id ? '' : $unita->nome . ': ') . $frase);
                }
            }
        }
    }

    /**
     * Giro sulle correzioni (G25) e ultima revisione (UD1, UE4): la riga di chi esce che un passaggio registrato dopo ha già chiuso. In
     * minuscolo, per la pertinenza con il suo nome davanti.
     */
    private static function fraseUscenteChiusa(TitolaritaImmobile $riga): string
    {
        return sprintf('la riga di %s è stata chiusa dal %s da un passaggio registrato dopo: annulla prima dallo storico dell\'unità i passaggi successivi, l\'ultimo per primo, registra questo e poi di nuovo quei passaggi.',
            $riga->anagrafica?->nome ?? 'chi esce', CarbonImmutable::parse($riga->data_fine)->addDay()->locale('it')->translatedFormat('j F Y'));
    }

    /** L'unità del passaggio e le pertinenze spuntate che le appartengono. */
    private function unitaDelPassaggio(Immobile $immobile): \Illuminate\Support\Collection
    {
        return collect([$immobile])->concat($immobile->pertinenze()->whereIn('id', array_map('intval', (array) $this->input('pertinenze', [])))->get());
    }

    /**
     * Rilievo X1 della Fase 1-bis della .44: chi riceve è già titolare dell'unità con lo stesso ruolo, e la sua riga in corso il giorno del
     * passaggio ha già una data di fine — scritta da un passaggio registrato dopo, o a mano. La registrazione la chiudeva al giorno prima
     * e apriva la somma senza fine, sopra ciò che è successo dopo. Le frasi della decisione 53 (2) e del rilievo T2.
     */
    private function fraseRigaGiaChiusa(Immobile $unita, int $anagraficaId, string $tipologia, CarbonImmutable $giorno, string $cheCosa, bool $natePrima = false): ?string
    {
        $riga = $unita->titolarita()->with('anagrafica')->where('anagrafica_id', $anagraficaId)->where('tipologia', $tipologia)->get()
            ->first(fn (TitolaritaImmobile $t) => $t->inCorsoIl($giorno) && $t->data_fine !== null && (! $natePrima || ($t->data_inizio !== null && $t->data_inizio->lt($giorno))));
        if ($riga === null) {
            return null;
        }
        $chi = $riga->anagrafica?->nome ?? 'Questa persona';
        $ruolo = mb_strtolower(RuoloAnagraficaImmobile::tryFrom($tipologia)?->label() ?? $tipologia);
        // Giro sulle correzioni (G3): anche la riga chiusa da un passaggio nel suo registro (una somma, l'estinzione di una nuda). Ultima
        // revisione (UE3): se quel passaggio è un atto fra vivi in cui la persona ha ceduto proprio questa riga, rifarlo dopo porterebbe
        // tutta la riga sommata e non la parte dell'atto (decisione 37, Coda 175): la strada è a mano.
        $p = $riga->passaggioCheLaChiude();
        if ($p !== null && (int) $p->riga_uscente_id === (int) $riga->id && ! $p->successione() && ! $p->estinzioneUsufrutto()) {
            return sprintf('%s è già %s di questa unità, e con un passaggio registrato dopo ha ceduto quella riga dal %s: %s si sommerebbe a una riga che non è più in corso, e quel passaggio, rifatto dopo, porterebbe tutta la riga sommata e non la parte dell\'atto. Correggi le righe a mano da «Modifica associazione».',
                $chi, $ruolo, CarbonImmutable::parse($riga->data_fine)->addDay()->locale('it')->translatedFormat('j F Y'), $cheCosa);
        }
        if ($p !== null) {
            return sprintf('%s è già %s di questa unità, e un passaggio registrato dopo ha chiuso quella riga dal %s: %s si sommerebbe a una riga che non è più in corso. Annulla prima dallo storico dell\'unità i passaggi successivi, l\'ultimo per primo, registra questo passaggio e poi di nuovo quei passaggi; se l\'atto dice altro, correggi le righe a mano da «Modifica associazione».',
                $chi, $ruolo, CarbonImmutable::parse($riga->data_fine)->addDay()->locale('it')->translatedFormat('j F Y'), $cheCosa);
        }

        return sprintf('%s è già %s di questa unità, e la sua riga ha una data di fine scritta a mano, il %s: %s si sommerebbe a una riga che finisce. Correggi le righe a mano da «Modifica associazione» prima di registrare questo passaggio.',
            $chi, $ruolo, CarbonImmutable::parse($riga->data_fine)->locale('it')->translatedFormat('j F Y'), $cheCosa);
    }

    private static function numero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }

    /** La riga di chi esce, solo se è di questa unità: l'`id` arriva dal browser e non si fida di nessuno. */
    public function rigaUscente(): ?TitolaritaImmobile
    {
        if (! $this->filled('riga_uscente_id')) {
            return null;
        }

        /** @var Immobile $immobile */
        $immobile = $this->route('immobile');

        return $immobile->titolarita()->with('anagrafica')->find((int) $this->input('riga_uscente_id'));
    }

    /** I dati nella forma che `AnteprimaPassaggio::calcola()` e la scrittura (S5) ricevono. */
    public function datiPassaggio(): array
    {
        $d = $this->validated();

        return [
            'tipo' => $d['tipo'],
            'sottotipo' => $d['sottotipo'] ?? null,
            'riga_uscente' => $this->rigaUscente(),
            'entrante' => $this->entrante($d),
            'decorrenza' => CarbonImmutable::parse($d['decorrenza'])->startOfDay(),
            'quota' => (float) $d['quota'],
            'tipologia' => $d['tipologia'],
            'copia_autentica' => (bool) $d['copia_autentica'],
            'copia_autentica_il' => ! empty($d['copia_autentica_il']) ? CarbonImmutable::parse($d['copia_autentica_il']) : null,
            'estremi_titolo' => $d['estremi_titolo'] ?? null,
            'nota' => $d['nota'] ?? null,
            'data_fine_locazione' => ! empty($d['data_fine_locazione']) ? CarbonImmutable::parse($d['data_fine_locazione']) : null,
            'regime_contratto' => $d['regime_contratto'] ?? null,
            'pertinenze' => array_map('intval', $d['pertinenze'] ?? []),
            'nudi_che_tornano' => ! empty($d['nudi_che_tornano']) ? array_map('intval', $d['nudi_che_tornano']) : null,
            'nudi_per_quota' => (bool) ($d['nudi_per_quota'] ?? false),
            'accrescimento' => $d['tipo'] === 'usufrutto' && ($d['sottotipo'] ?? null) === 'estinzione' && (bool) ($d['accrescimento'] ?? false),
            'ho_letto' => (bool) ($d['ho_letto'] ?? false),
            'nota_cancello' => $d['nota_cancello'] ?? null,
            'allegato_titolo' => $this->file('allegato_titolo'),
            'promemoria_scadenza' => (bool) ($d['promemoria_scadenza'] ?? false),
            'promemoria_giorni' => isset($d['promemoria_giorni']) ? (int) $d['promemoria_giorni'] : null,
            'rinuncia_conguaglio' => (bool) ($d['rinuncia_conguaglio'] ?? false),
            'nota_conguaglio' => isset($d['nota_conguaglio']) && trim((string) $d['nota_conguaglio']) !== '' ? trim((string) $d['nota_conguaglio']) : null,
            'ordinaria_dopo_atto' => $d['ordinaria_dopo_atto'] ?? null,
            'voci_da_tenere' => array_map('intval', $d['voci_da_tenere'] ?? []),
            'ordinaria_impronta' => $d['ordinaria_impronta'] ?? null,
            // Decisione 65: gli eredi con la quota che ereditano, il legato, l'arretrato, l'erede di riferimento.
            'eredi' => $d['tipo'] === 'successione' ? $this->eredi($d) : [],
            'legato' => $d['tipo'] === 'successione' && ($d['sottotipo'] ?? null) === Subentro::LEGATO,
            'arretrato' => $d['arretrato'] ?? null,
            'erede_di_riferimento' => ! empty($d['erede_di_riferimento']) ? Anagrafica::find((int) $d['erede_di_riferimento']) : null,
        ];
    }

    /**
     * Gli eredi, nell'ordine del modulo: l'ordine conta solo per il centesimo di resto, che va al primo (`MoneyHelper::ripartisciPerQuote`).
     *
     * @return list<array{anagrafica: Anagrafica, quota: float}>
     */
    private function eredi(array $d): array
    {
        $persone = Anagrafica::whereIn('id', array_map(fn ($e) => (int) $e['anagrafica_id'], $d['eredi'] ?? []))->get()->keyBy('id');

        return array_values(array_map(fn ($e) => ['anagrafica' => $persone[(int) $e['anagrafica_id']], 'quota' => round((float) $e['quota'], 2)], $d['eredi'] ?? []));
    }

    /**
     * Chi entra, per chi legge una persona sola (il passaggio, le bozze, il flash): nella successione l'erede di riferimento, o l'erede
     * unico, o il primo; negli altri tipi la persona del modulo.
     */
    private function entrante(array $d): ?Anagrafica
    {
        if ($d['tipo'] === 'successione') {
            $id = ! empty($d['erede_di_riferimento']) ? (int) $d['erede_di_riferimento'] : (int) (($d['eredi'] ?? [])[0]['anagrafica_id'] ?? 0);

            return $id > 0 ? Anagrafica::find($id) : null;
        }

        return ! empty($d['anagrafica_entrante_id']) ? Anagrafica::find($d['anagrafica_entrante_id']) : null;
    }
    /**
     * Rilievi V8, W3, W4 e decisione 52: la strada del rifiuto si sceglie dai dati, ma solo nel caso semplice. La nuda deve tornare
     * prima dell'estinzione (che la fa piena) e la piena dopo (che allora somma): registrata prima l'estinzione, chi aveva venduto la
     * nuda diventerebbe proprietario pieno. Si parte dal **primo** passaggio che ha toccato la riga piena nata quel giorno (anche
     * sommando su di lei) e si annulla tutto ciò che nello storico viene dopo, nell'ordine della guardia dell'annullamento; poi si
     * rifanno le vendite della nuda, questa estinzione e infine gli altri. Fuori dal caso semplice — una riserva (un secondo
     * usufrutto), passaggi registrati con o da una pertinenza, un passaggio in cui esce il nudo, uno dopo questa data in cui il nudo
     * entra — la frase dice il fatto e rimanda alla correzione a mano: ogni ramo in più della ricetta aveva prodotto difetti nuovi,
     * per un caso raro.
     */
    private function fraseNudaEPienaDelloStessoGiorno(Immobile $unita, TitolaritaImmobile $nudo, TitolaritaImmobile $piena, CarbonImmutable $giorno): string
    {
        $chi = $nudo->anagrafica?->nome ?? 'Il nudo proprietario';
        $nudoId = (int) $nudo->anagrafica_id;
        $il = $giorno->locale('it')->translatedFormat('j F Y');
        $testa = sprintf('%s è nudo proprietario e proprietario pieno di questa unità da due righe nate lo stesso giorno, il %s, e l\'estinzione non le può riunire.', $chi, $il);

        // Padri e figli: una riga del box può venire dal passaggio figlio di uno registrato dall'unità principale (rilievo X5).
        $passaggi = Subentro::where('immobile_id', $unita->id)->orderBy('decorrenza')->orderBy('id')->get()->values();
        $primo = $passaggi->search(fn (Subentro $s) => (int) $s->riga_entrante_id === (int) $piena->id || in_array((int) $piena->id, $s->righeDelRegistro(), true));
        if ($primo === false) {
            return $testa . sprintf(' La riga piena di %s dal %s è stata associata a mano: va corretta a mano da «Modifica associazione» prima dell\'estinzione.', $chi, $il);
        }
        $daAnnullare = $passaggi->slice($primo)->values();
        // Rilievo Y1 del quarto giro: la frase non afferma che una strada non esiste — dice che il programma non ne indica una.
        $aMano = fn (string $perche) => $testa . ' ' . $perche . ': per questo caso il programma non indica una strada. Correggi le righe a mano da «Modifica associazione», o chiedi assistenza.';
        $data = fn (Subentro $s) => $s->decorrenza->locale('it')->translatedFormat('j F Y');
        if ($daAnnullare->contains(fn (Subentro $s) => $s->riservaUsufrutto())) {
            return $aMano('Fra i passaggi da rifare c\'è una vendita o donazione con riserva d\'usufrutto, che apre un secondo usufrutto');
        }
        // Rilievo T7 della Fase 1-bis della .43: la nuda e la piena le ha aperte un'altra estinzione dello stesso giorno (il
        // consolidamento di una nuda sola sotto due usufrutti che finiscono insieme). Annullarla e rifarla dopo dà lo stesso rifiuto
        // a parti invertite: la ricetta girerebbe in tondo. Solo quando è proprio quell'estinzione ad aver aperto le righe (GT7).
        // Rilievo HT2: anche quando l'estinzione dello stesso giorno ha toccato una delle due righe (si è sommata sulla piena e ha
        // aperto la nuda) senza essere il passaggio che ha aperto la piena.
        $apertura = $passaggi[$primo];
        $estinzione = $apertura->estinzioneUsufrutto() && $apertura->decorrenza->equalTo($giorno) ? $apertura
            : $daAnnullare->first(fn (Subentro $s) => $s->estinzioneUsufrutto() && $s->decorrenza->equalTo($giorno)
                && array_intersect([(int) $nudo->id, (int) $piena->id], $s->righeDelRegistro()) !== []);
        if ($estinzione !== null) {
            return $aMano(sprintf('Le due righe vengono da un\'altra estinzione dello stesso giorno (%s): annullarla e registrarla dopo questa darebbe lo stesso rifiuto', AnnullaPassaggioAction::descrivi($estinzione)));
        }
        if (($x = $daAnnullare->first(fn (Subentro $s) => $s->subentro_padre_id !== null || $s->pertinenze()->exists())) !== null) {
            return $aMano(sprintf('Fra i passaggi da rifare ce n\'è uno registrato insieme a una pertinenza: %s, dal %s', AnnullaPassaggioAction::descrivi($x->padre ?? $x), $data($x)));
        }
        if (($x = $daAnnullare->first(fn (Subentro $s) => (int) $s->anagrafica_uscente_id === $nudoId)) !== null) {
            return $aMano(sprintf('Fra i passaggi da rifare c\'è %s, dal %s, in cui esce %s', AnnullaPassaggioAction::descrivi($x), $data($x), $chi));
        }
        $eNuda = fn (Subentro $s) => $s->tipo_passaggio === 'vendita' && $s->tipologia === 'nuda_proprietario';
        // Rilievo Y1: anche una vendita della nuda datata dopo l'estinzione a chiunque, non solo al nudo — dal giorno dell'estinzione
        // quella nuda è piena, e la ricetta finiva nel rifiuto della decisione 53.
        if (($x = $daAnnullare->first(fn (Subentro $s) => ! $s->decorrenza->equalTo($giorno) && ((int) $s->anagrafica_entrante_id === $nudoId || $eNuda($s)))) !== null) {
            return $aMano(sprintf('Fra i passaggi da rifare c\'è %s, dal %s, dopo questa estinzione', AnnullaPassaggioAction::descrivi($x), $data($x)));
        }
        $nude = $daAnnullare->filter($eNuda)->values();
        $altri = $daAnnullare->reject($eNuda)->values();
        $elenco = fn ($lista) => collect($lista)->map(fn (Subentro $s) => AnnullaPassaggioAction::descrivi($s))->join(', ', ' e ');

        if ($nude->isEmpty() && $altri->count() === 1) {
            return $testa . sprintf(' Annulla dallo storico dell\'unità %s, registra questa estinzione e poi di nuovo quel passaggio.', $elenco($altri));
        }
        $annulla = sprintf(' Annulla dallo storico dell\'unità, l\'ultimo per primo, %s;', $elenco($daAnnullare->reverse()));

        return $testa . $annulla . ($nude->isEmpty()
            ? sprintf(' poi registra questa estinzione e di nuovo, nell\'ordine, %s.', $elenco($altri))
            : ($altri->isEmpty()
                ? sprintf(' poi registra di nuovo %s e infine questa estinzione.', $elenco($nude))
                : sprintf(' poi registra di nuovo %s, questa estinzione e infine %s.', $elenco($nude), $elenco($altri))));
    }
}
