<?php

namespace App\Http\Requests\Gestionale\Immobile\Anagrafica;

use App\Helpers\DateHelper;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
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
            // dichiara e non si deduce. I sottotipi di un tipo non valgono per l'altro.
            'sottotipo' => ['nullable', 'required_if:tipo,usufrutto', Rule::in($tipo === 'vendita' ? [Subentro::RISERVA_USUFRUTTO] : ['costituzione', 'estinzione'])],
            'riga_uscente_id' => [
                // L'inizio di una locazione non ha chi esce (S8-20): una riga qui chiuderebbe il proprietario.
                Rule::prohibitedIf(fn () => $tipo === 'inizio_locazione'),
                Rule::requiredIf(fn () => in_array($tipo, ['vendita', 'fine_locazione', 'usufrutto'], true)),
                'nullable', 'integer',
            ],
            'anagrafica_entrante_id' => [
                Rule::requiredIf(fn () => $tipo === 'vendita' || $tipo === 'inizio_locazione' || ($tipo === 'usufrutto' && $this->input('sottotipo') !== 'estinzione')),
                'nullable', 'integer', Rule::exists('anagrafiche', 'id'),
            ],
            // Nessun valore predefinito: la data del passaggio la dichiara l'amministratore (decisione 12,
            // «non si tira a indovinare»).
            'decorrenza' => ['required', 'date'],
            'quota' => ['required', 'numeric', 'min:0', 'max:100'],
            // Il ruolo di chi entra è legato al tipo: una vendita non fa entrare un inquilino, una
            // locazione non fa entrare un proprietario. L'elenco per tipo sta in `ruoliEntrante()`.
            'tipologia' => ['required', Rule::in(self::ruoliEntrante($tipo, (string) $this->input('sottotipo')))],
            'copia_autentica' => ['required', 'boolean'],
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
        foreach (['copia_autentica', 'ho_letto', 'promemoria_scadenza', 'rinuncia_conguaglio'] as $campo) {
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
                        'Nella vendita o donazione con riserva d\'usufrutto chi compra riceve la nuda proprietà di tutta la quota di chi vende (%s %%): chi vende resta usufruttuario sulla stessa quota.',
                        rtrim(rtrim(number_format((float) $uscente->quota, 2, ',', '.'), '0'), ','),
                    ));
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
                        $li = $pertinenza->titolarita()->where('anagrafica_id', $uscente->anagrafica_id)->where('tipologia', $uscente->tipologia)->get()
                            ->first(fn (TitolaritaImmobile $t) => $t->inCorsoIl($giornoPrima));
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
            'sottotipo' => 'costituzione o estinzione',
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
        ];
    }

    /** I ruoli che chi entra può avere, per tipo di passaggio (e sottotipo dell'usufrutto). */
    public static function ruoliEntrante(string $tipo, string $sottotipo = ''): array
    {
        return match ($tipo) {
            'vendita' => ['proprietario', 'nuda_proprietario'],
            'inizio_locazione', 'fine_locazione' => ['inquilino'],
            'usufrutto' => $sottotipo === 'estinzione' ? ['proprietario'] : ['usufruttuario'],
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
            'entrante' => ! empty($d['anagrafica_entrante_id']) ? Anagrafica::find($d['anagrafica_entrante_id']) : null,
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
        ];
    }
}
