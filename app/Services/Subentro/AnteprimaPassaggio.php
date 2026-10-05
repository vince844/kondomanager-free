<?php

namespace App\Services\Subentro;

use App\Helpers\DateHelper;
use App\Enums\NaturaGestione;
use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il pannello «Cosa cambierà» di «Registra passaggio»: quattro blocchi al futuro, calcolati qui e
 * mai lato client (§6.4 di `docs/pertinenze_vendita_locazione.md`).
 *
 * 1. **Anagrafica** — chi risulterà titolare fino a quando, chi da quando, con che ruolo e quota.
 * 2. **Rate già emesse** — le rate dell'unità già emesse, la morosità di chi esce, e il conguaglio.
 * 3. **Chi resta obbligato** — la solidarietà dell'art. 63 co. 4 disp. att. c.c., la copia autentica
 *    del titolo (co. 5), o — per la locazione — chi risponde verso il condominio.
 * 4. **Cosa non cambia** — millesimi, tabelle, teste, pertinenze: sempre quattro righe.
 *
 * Più il **cancello (1)** della decisione 14: spunta e nota se il passaggio tocca rate già emesse
 * **oppure** cambia un destinatario di un piano già generato, anche in bozza.
 *
 * Il blocco 2 porta il conguaglio **calcolato** da `ConguaglioPassaggio` (S5, S6): `stato` è `calcolato`
 * quando ci sono quote da conguagliare e `nessuno` altrimenti — mai uno zero al posto di un numero che non
 * c'è, perché si leggerebbe come «niente da conguagliare». Lo stesso calcolo produce la coppia che
 * `RegistraSubentroAction` scrive («anteprima = scrittura»), e l'amministratore può rinunciarvi con una nota.
 *
 * I testi sono quelli definitivi del §6: maiuscole solo a inizio frase, `€` prima dell'importo,
 * date a parole. Ogni sentenza citata è stata verificata su fonte il 17–18/09/2026.
 */
class AnteprimaPassaggio
{
    /**
     * @param array{
     *   tipo: string, sottotipo?: ?string, riga_uscente?: ?TitolaritaImmobile, entrante?: ?Anagrafica,
     *   decorrenza: CarbonImmutable, quota: float, tipologia: string, copia_autentica: bool,
     *   copia_autentica_il?: ?CarbonImmutable, pertinenze?: list<int>, data_fine_locazione?: ?CarbonImmutable
     * } $dati
     */
    /** I nudi proprietari in corso alla decorrenza dell'ultimo `calcola()` (S8-30): all'estinzione, quelli che tornano pieni (decisione 57). */
    private Collection $nudi;

    /** Decisione 57: da dove viene la scelta dei nudi che tornano pieni (`NudiDellEstinzione::DA_*`), solo all'estinzione. */
    private ?string $nudiDa = null;

    /** Consolidamento di legge: riga del nudo → quota che torna piena, quando la nuda vale più dell'usufrutto che finisce. */
    private array $consolida = [];

    /** La persona che esce, all'estinzione: se è anche fra i nudi che tornano pieni, non è la controparte di nessuno (T1). */
    private ?int $chiEsce = null;

    /** La riga di chi esce (per le frasi di «Chi resta obbligato» all'estinzione). */
    private ?int $rigaChiEsce = null;

    /** All'estinzione, sull'unità c'è un altro usufrutto in corso (rilievo GT6: la nota sull'accrescimento). */
    private bool $altroUsufrutto = false;

    public function __construct(
        private readonly ConguaglioPassaggio $conguaglioPassaggio = new ConguaglioPassaggio(),
        private readonly FrasiObbligati $frasiObbligati = new FrasiObbligati(),
        private readonly VociDaSpostare $vociDaSpostare = new VociDaSpostare(),
    ) {
    }

    public function calcola(Condominio $condominio, Immobile $immobile, array $dati): array
    {
        $tipo = $dati['tipo'];
        $decorrenza = $dati['decorrenza'];
        $giornoPrima = $decorrenza->subDay();
        $uscente = $dati['riga_uscente'] ?? null;
        $entrante = $dati['entrante'] ?? null;
        $oggi = DateHelper::oggiUtenteImmutable();

        $attuali = $immobile->titolarita()->with('anagrafica')->get()
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($oggi))
            ->values();
        $proprietari = $attuali->filter(fn ($t) => in_array($t->tipologia, ['proprietario', 'nuda_proprietario'], true));
        // I nudi proprietari **in corso alla decorrenza** (stesso filtro di `RegistraSubentroAction::applicaRighe`,
        // S8-30): all'estinzione tornano tutti proprietari pieni, ciascuno alla sua quota, e il conguaglio si
        // divide fra loro per quota. `$nudoProprietario` resta il primo, per la controparte del calcolo.
        $this->nudi = $immobile->titolarita()->with('anagrafica')->where('tipologia', 'nuda_proprietario')->get()
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza))->values();
        // Decisione 57 (1.11.0-beta.43, D2): all'estinzione tornano pieni solo i nudi dell'usufrutto che finisce — con un altro usufrutto
        // in corso non chi esce; senza, anche chi esce, e la sua parte resta a suo nome.
        $this->nudiDa = null;
        $this->consolida = [];
        $this->chiEsce = $uscente?->anagrafica_id !== null ? (int) $uscente->anagrafica_id : null;
        $this->rigaChiEsce = $uscente?->id !== null ? (int) $uscente->id : null;
        $this->altroUsufrutto = false;
        if ($tipo === 'usufrutto' && ($dati['sottotipo'] ?? null) === 'estinzione' && $uscente !== null) {
            $scelta = app(NudiDellEstinzione::class)->per($uscente, $decorrenza, $dati['nudi_che_tornano'] ?? null, (bool) ($dati['nudi_per_quota'] ?? false));
            $this->nudi = $scelta['nudi'];
            $this->nudiDa = $scelta['da'];
            $this->consolida = $scelta['consolida'];
            $this->altroUsufrutto = (bool) ($scelta['altro_usufrutto'] ?? false);
        }
        $nudoProprietario = $this->nudi->first() ?? $attuali->first(fn ($t) => $t->tipologia === 'nuda_proprietario');

        $nomeUscente = $uscente?->anagrafica?->nome;
        $nomeEntrante = $entrante?->nome;
        $ruolo = $this->ruolo($dati['tipologia']);
        $quota = $this->quota($dati['quota']);

        // Le unità del passaggio: la principale e le pertinenze spuntate (una quota emessa sul box conta).
        $immobileIds = array_values(array_unique([(int) $immobile->id, ...array_map('intval', $dati['pertinenze'] ?? [])]));
        $tutteLeEmesse = $this->rateEmesse($immobileIds);
        // Il blocco 2 elenca le quote emesse **a chi esce**: sono quelle che il conguaglio riguarda. Le
        // altre — comproprietari, inquilino — restano a chi le ha ricevute e si contano in una riga sola,
        // altrimenti su un'unità con tre proprietari e due piani la tabella ha 28 righe e non dice niente.
        // S8-3: con lo stesso insieme del conguaglio — chi esce **e i suoi predecessori** nella catena dei
        // passaggi (chi ha comprato a maggio e rivende a settembre non ha quote a suo nome: passano quelle
        // del venditore di maggio).
        $intestatari = $uscente
            ? $this->conguaglioPassaggio->intestatariConguagliabili((int) $uscente->anagrafica_id, $immobileIds)
            : [];
        // DV4 (decisione 59, 1.11.0-beta.43): un predecessore conta solo sulle unità dove ha ceduto qualcosa a chi esce.
        $conta = $uscente ? $this->conguaglioPassaggio->filtroConguagliabili((int) $uscente->anagrafica_id, $immobileIds) : null;
        $rateEmesse = $conta
            ? $tutteLeEmesse->filter(fn ($r) => $conta($r['anagrafica_id'], $r['immobile_id']))->values()
            : collect();
        $altreEmesse = $conta
            ? $tutteLeEmesse->reject(fn ($r) => $conta($r['anagrafica_id'], $r['immobile_id']))->values()
            : $tutteLeEmesse;
        $morosita = $uscente ? $this->morosita($immobile, $uscente->anagrafica_id) : null;

        // S5: il conguaglio vero (D9), un solo calcolo per il pannello e per la registrazione.
        $conguaglio = $this->conguaglio($tipo, $dati, $uscente, $entrante, $nudoProprietario, $immobileIds, $decorrenza);

        // Decisioni 31.5 e 31.6: alla costituzione e alla riserva d'usufrutto, chi paga l'ordinaria dal giorno dell'atto.
        $ordinaria = $this->bloccoOrdinaria($tipo, $dati, $condominio, $immobileIds, $uscente, $nomeUscente, $nomeEntrante, $decorrenza);

        return [
            // Decisione 57 (1.11.0-beta.43, D2): all'estinzione, i nudi che tornano pieni e da dove viene la scelta — tutti, dal
            // registro del passaggio da cui è nato l'usufrutto, o dall'amministratore. Il modulo mostra le caselle solo per la scelta.
            'nudi' => $this->nudiDa !== null ? ['da' => $this->nudiDa, 'righe' => $this->nudi->map(fn (TitolaritaImmobile $t) => (int) $t->id)->values()->all(), 'consolida' => (object) $this->consolida] : null,
            'riferimento' => [
                'uscente_fino_al' => $uscente ? $giornoPrima->toDateString() : null,
                'entrante_dal' => $decorrenza->toDateString(),
                'frase' => $this->fraseRiferimento($tipo, $dati, $nomeUscente, $nomeEntrante, $giornoPrima, $decorrenza, $proprietari, $nudoProprietario),
            ],
            'anagrafica' => [
                'frasi' => $this->blocco1($tipo, $dati, $immobile, $nomeUscente, $nomeEntrante, $ruolo, $quota, $giornoPrima, $decorrenza, $proprietari, $nudoProprietario),
                'pertinenze' => $this->nomiPertinenze($immobile, $dati['pertinenze'] ?? []),
            ],
            'rate' => [
                'stato' => $conguaglio['stato'],
                'emesse' => $rateEmesse->map(fn ($r) => collect($r)->except(['anagrafica_id', 'immobile_id'])->all())->values()->all(),
                'piani_distinti' => $rateEmesse->pluck('piano')->unique()->count(),
                'totale_emesso' => $rateEmesse->sum('importo'),
                'totale_emesso_formattato' => MoneyHelper::format((int) $rateEmesse->sum('importo')),
                'altre' => [
                    'quote' => $altreEmesse->count(),
                    'intestatari' => $altreEmesse->pluck('intestatario')->unique()->values()->all(),
                ],
                'morosita' => $morosita,
                'conguaglio' => $conguaglio['stato'] === 'calcolato' ? $conguaglio : null,
                'frasi' => $this->blocco2($tipo, $dati, $tutteLeEmesse, $rateEmesse, $altreEmesse, $morosita, $nomeUscente, $nomeEntrante, $decorrenza, $nudoProprietario, $conguaglio),
            ],
            'obbligati' => [
                // Decisione 28.8 a: le unità del passaggio, per la frase sui saldi intestati all'unità.
                'frasi' => $this->blocco3($tipo, ['immobili' => $immobileIds] + $dati, $condominio, $immobile, $nomeUscente, $nomeEntrante, $decorrenza, $proprietari, $nudoProprietario),
                'copia_autentica_mancante' => $tipo === 'vendita' && ! $dati['copia_autentica'],
            ],
            'invarianti' => [
                'frasi' => $this->blocco4($tipo, $dati, $condominio, $immobile, $uscente, $entrante),
            ],
            'ordinaria' => $ordinaria,
            'cancello' => $this->cancello($tipo, $condominio, $immobileIds, $uscente, $tutteLeEmesse->filter(fn ($r) => $conta === null || $conta($r['anagrafica_id'], $r['immobile_id']))->values(), $intestatari, $conguaglio['riassegnazione'] ?? [], $nomeEntrante, $conguaglio['quote'] ?? [], $this->riserva($tipo, $dati) ? $decorrenza : null, array_column(array_filter($ordinaria['voci'], fn ($v) => $v['spostata']), 'conto_id'),
                $ordinaria['scelta'] === Subentro::ORDINARIA_COME_LA_VOCE ? true : array_column(array_filter($ordinaria['voci'], fn ($v) => ! $v['spostata'] && ! $v['bloccata']), 'conto_id'), $conta),
        ];
    }

    /**
     * Chi esce e chi entra **nel conguaglio**, che non sono sempre chi esce e chi entra nel modulo:
     * nell'estinzione dell'usufrutto nessuno entra, è il nudo proprietario che torna pieno; nella
     * costituzione chi esce resta come nudo proprietario e le straordinarie restano sue (art. 1005 c.c.).
     * Inizio locazione: nessun uscente, niente da conguagliare. Fine locazione senza nuovo inquilino:
     * le quote emesse all'inquilino restano sue (da confermare con gli amministratori in beta).
     */
    public function conguaglio(string $tipo, array $dati, ?TitolaritaImmobile $uscente, ?Anagrafica $entrante, ?TitolaritaImmobile $nudo, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        $nessuno = ['stato' => 'nessuno', 'anagrafica_uscente_id' => null, 'anagrafica_entrante_id' => null, 'quote' => [], 'per_gestione' => [], 'coppie' => [], 'totale_entrante' => 0, 'totale_entrante_formattato' => MoneyHelper::format(0), 'pregressi' => 0, 'non_risolte' => [], 'frasi' => [], 'bozze_riassegnate' => [], 'riassegnazione' => []];
        if ($tipo === 'inizio_locazione') {
            return $nessuno; // la Request rifiuta già una riga uscente qui (S8-20); la promessa del docblock vale per ogni chiamante
        }
        if ($uscente?->anagrafica === null) {
            return $nessuno;
        }
        $estinzione = $tipo === 'usufrutto' && ($dati['sottotipo'] ?? 'costituzione') === 'estinzione';
        // Decisione 57: chi esce che torna pieno della sua parte (nessun altro usufrutto) non è una controparte, la sua parte resta a
        // lui. All'estinzione la controparte è il primo degli altri nudi, sull'unità o, se lì non ce ne sono, su una pertinenza.
        $altro = fn (?TitolaritaImmobile $t) => $t !== null && (int) $t->anagrafica_id !== (int) $uscente->anagrafica_id;
        $controparte = $estinzione ? ($altro($nudo) ? $nudo : $this->nudi->first($altro))?->anagrafica : $entrante;
        if ($controparte === null && ! $estinzione) {
            return $nessuno;
        }

        // Decisione 25 (B3a): nella vendita le bozze di chi esce, dalla decorrenza in poi, passano a chi entra.
        // R4 (Fase 1-bis, decisione del 26/09/2026): nella vendita della nuda proprietà le ordinarie di un piano generato
        // prima dell'usufrutto restano fuori — sono dell'usufruttuario (art. 1004 c.c.).
        // Riserva d'usufrutto (decisione 28): una vendita per le straordinarie, ma l'ordinaria resta fuori — la deve la stessa
        // persona prima e dopo, ora come usufruttuario (art. 1004 c.c.).
        // Rilievo D4 della Fase 1-bis della beta.41: l'estinzione di un usufrutto nato «come la voce» fa passare solo le voci
        // che l'usufruttuario aveva davvero — non quelle sul «Proprietario», già passate al nudo proprietario con la riserva
        // o rimaste sue con la costituzione. Senza, l'estinzione le faceva passare una seconda volta.
        $origine = $estinzione ? $this->usufruttoNatoComeLaVoce($uscente) : null;
        // U4 (decisione 59, 1.11.0-beta.43): nell'estinzione chi entra, unità per unità, è il nudo proprietario di quell'unità
        // alla decorrenza. Il box può avere un nudo suo, diverso da quello dell'appartamento: prima la coppia del box andava al
        // nudo dell'appartamento, che sul box non ha niente.
        $nudiPerUnita = [];
        $entrantiPerUnita = [];
        // Decisione 56 (1.11.0-beta.43): la genealogia della quota parte dalla riga di chi esce su ogni unità — sull'unità quella del
        // modulo, su una pertinenza quella che il passaggio chiuderà (la stessa ricerca di `RegistraSubentroAction`) — e, all'estinzione,
        // arriva ai nudi che tornano pieni, con la quota che torna piena.
        $consolidaPerUnita = [(int) $immobileIds[0] => $this->consolida];
        $righeUscenti = [(int) $immobileIds[0] => (int) $uscente->id];
        foreach (array_slice($immobileIds, 1) as $u) {
            $riga = TitolaritaImmobile::where('immobile_id', $u)->where('anagrafica_id', $uscente->anagrafica_id)->where('tipologia', $uscente->tipologia)->get()
                ->first(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza->subDay()) && ! $t->subentriComeUscente()->exists());
            if ($riga !== null) {
                $righeUscenti[(int) $u] = (int) $riga->id;
            }
        }
        if ($estinzione) {
            foreach ($immobileIds as $u) {
                // Su una pertinenza la stessa regola dell'unità (decisione 57), sulla riga d'usufrutto di chi esce su quella
                // pertinenza: prima si prendevano tutti i nudi in corso, e chi esce poteva tornare nudo di sé stesso sul box.
                $usufruttoQui = (int) $u === (int) $immobileIds[0] ? null
                    : TitolaritaImmobile::with('anagrafica')->where('immobile_id', $u)->where('anagrafica_id', $uscente->anagrafica_id)->where('tipologia', 'usufruttuario')->get()
                        ->first(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza->subDay()));
                $sceltaQui = $usufruttoQui !== null ? app(NudiDellEstinzione::class)->per($usufruttoQui, $decorrenza) : null;
                if ($sceltaQui !== null) {
                    $consolidaPerUnita[(int) $u] = $sceltaQui['consolida'];
                }
                $nudiPerUnita[(int) $u] = (int) $u === (int) $immobileIds[0] ? $this->nudi
                    : ($sceltaQui !== null ? $sceltaQui['nudi']
                        : TitolaritaImmobile::with('anagrafica')->where('immobile_id', $u)->where('tipologia', 'nuda_proprietario')->get()
                            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza))->values());
                $nomi = $nudiPerUnita[(int) $u]->filter($altro)->map(fn (TitolaritaImmobile $t) => $t->anagrafica?->nome)->filter()->values()->all();
                $controparte ??= $nudiPerUnita[(int) $u]->first($altro)?->anagrafica;
                if ($nomi !== [] && $nomi !== [$controparte?->nome]) {
                    $entrantiPerUnita[(int) $u] = $this->elenco($nomi);
                }
            }
        }
        if ($controparte === null) {
            // Il solo nudo è chi esce: torna pieno, e le quote dalla decorrenza restano a lui. Non passa niente.
            return $nessuno;
        }
        // Decisione 57: un'unità del passaggio dove il solo nudo è chi esce resta fuori dal conguaglio — torna pieno, e le sue
        // quote dalla decorrenza restano a lui —, e la frase lo dice. Dentro, il calcolo vedrebbe una coppia con sé stesso.
        $restaAChiEsce = $estinzione ? array_values(array_filter($immobileIds, fn ($u) => ($nudiPerUnita[(int) $u] ?? collect())->isNotEmpty()
            && $nudiPerUnita[(int) $u]->every(fn (TitolaritaImmobile $t) => ! $altro($t)))) : [];
        $immobileIds = array_values(array_diff($immobileIds, $restaAChiEsce));
        $nudiOra = array_map(fn (Collection $nudi) => $nudi->map(fn (TitolaritaImmobile $t) => ['id' => (int) $t->id, 'anagrafica_id' => (int) $t->anagrafica_id,
            'quota' => (float) ($consolidaPerUnita[(int) $t->immobile_id][(int) $t->id] ?? $t->quota)])->values()->all(), $nudiPerUnita);
        $passaggio = match (true) {
            $estinzione => GenealogiaDellaQuota::ESTINZIONE,
            $tipo === 'usufrutto' => GenealogiaDellaQuota::COSTITUZIONE,
            $this->riserva($tipo, $dati) => GenealogiaDellaQuota::RISERVA,
            $tipo === 'vendita' && $uscente->tipologia === 'nuda_proprietario' => GenealogiaDellaQuota::NUDA,
            $tipo === 'vendita' => GenealogiaDellaQuota::VENDITA,
            default => GenealogiaDellaQuota::FINE_LOCAZIONE,
        };
        $comeLaVoce = ($this->sceltaSullOrdinaria($tipo, $dati) && $this->sceltaOrdinaria($dati) === Subentro::ORDINARIA_COME_LA_VOCE)
            || $origine !== null;
        $esito = $this->conguaglioPassaggio->calcola($uscente->anagrafica, $controparte, $immobileIds, $decorrenza, soloOrdinario: $tipo === 'usufrutto', riassegnaBozze: $tipo === 'vendita', nudaProprieta: $tipo === 'vendita' && $uscente->tipologia === 'nuda_proprietario', soloStraordinario: $this->riserva($tipo, $dati),
            ordinariaComeLaVoce: $comeLaVoce, ruoliCheRestano: $this->ruoliCheRestano($tipo, $dati, $uscente, $immobileIds, $decorrenza),
            piuNudi: $estinzione && $this->nudi->count() > 1, entrantiPerUnita: $entrantiPerUnita, passaggio: $passaggio, righeUscenti: $righeUscenti, nudiOra: $nudiOra);
        if ($esito['stato'] === 'nessuna_rata') {
            return $nessuno;
        }
        // La frase di testa dell'estinzione dice la scelta del passaggio da cui l'usufrutto è nato (Fase 1-bis della beta.41).
        $esito['ordinaria_per_voce_dal'] = $origine !== null ? substr((string) $origine->decorrenza, 0, 10) : null;

        // S8-30: con più nudi proprietari il debito di ogni coppia si divide fra loro per quota registrata (la
        // stessa chiave che il motore usa per i comproprietari), una coppia per nudo; `anagrafica_entrante_id`
        // sta dentro la coppia anche con un nudo solo, per uniformità (RegistraSubentroAction la legge da lì).
        if ($estinzione) {
            $coppie = [];
            $righe = [];
            foreach ($esito['coppie'] as $c) {
                // U4: i nudi dell'unità della coppia; se l'unità non ne ha, quelli dell'unità principale, come prima.
                $nudi = $nudiPerUnita[(int) ($c['immobile_id'] ?? 0)] ?? collect();
                $nudi = $nudi->isNotEmpty() ? $nudi : $this->nudi;
                if ($nudi->count() <= 1) {
                    $nudo = $nudi->first();
                    if ($nudo !== null && ! $altro($nudo)) {
                        continue; // mai una coppia con sé stesso (un'unità senza nudi ripiega su quelli della principale)
                    }
                    $coppie[] = $c + ['anagrafica_entrante_id' => $nudo !== null ? (int) $nudo->anagrafica_id : $esito['anagrafica_entrante_id'], 'entrante_nome' => $nudo?->anagrafica?->nome ?? $controparte->nome];
                    continue;
                }
                $pesi = $nudi->mapWithKeys(fn (TitolaritaImmobile $t) => [(int) $t->anagrafica_id => (float) ($consolidaPerUnita[(int) $t->immobile_id][(int) $t->id] ?? $t->quota)])->all();
                $nomi = $nudi->mapWithKeys(fn (TitolaritaImmobile $t) => [(int) $t->anagrafica_id => $t->anagrafica?->nome])->all();
                $perQuota = MoneyHelper::ripartisciPerQuote((int) $c['importo'], $pesi);
                // Decisione 56 (1.11.0-beta.43): dove la genealogia sa a quale nudo torna ogni parte dell'usufrutto (la riserva che lega
                // una parte alla sua nuda), la coppia si divide così; il resto, se c'è, per quota come prima.
                $genealogia = array_intersect_key(array_map('intval', (array) ($c['per_nudo'] ?? [])), $pesi);
                $resto = (int) $c['importo'] - (int) array_sum($genealogia);
                $parti = $genealogia === [] ? $perQuota : $genealogia;
                if ($genealogia !== [] && $resto !== 0) {
                    foreach (MoneyHelper::ripartisciPerQuote($resto, $pesi) as $anagraficaId => $cents) {
                        $parti[$anagraficaId] = ($parti[$anagraficaId] ?? 0) + (int) $cents;
                    }
                }
                foreach ($parti as $anagraficaId => $cents) {
                    // La parte di chi esce, nudo che torna pieno, resta a lui: non è una coppia.
                    if ((int) $cents === 0 || (int) $anagraficaId === (int) $uscente->anagrafica_id) {
                        continue;
                    }
                    $coppie[] = array_replace($c, ['anagrafica_entrante_id' => (int) $anagraficaId, 'entrante_nome' => $nomi[$anagraficaId] ?? '?', 'importo' => (int) $cents, 'importo_formattato' => MoneyHelper::format((int) $cents)]);
                }
                if ($genealogia !== [] && count(array_filter($parti, fn ($cents) => (int) $cents !== 0)) <= 1) {
                    continue;
                }
                // La parte di chi esce resta a suo nome: la frase dice di quanto scende il suo credito.
                $resta = (int) ($parti[(int) $uscente->anagrafica_id] ?? 0);
                $coda = $resta !== 0 ? sprintf('; il credito di %s scende quindi a %s', $uscente->anagrafica?->nome ?? 'chi esce', MoneyHelper::format((int) $c['importo'] - $resta)) : '';
                $righe[] = $genealogia === [] || array_filter($perQuota, fn ($cents) => (int) $cents !== 0) == array_filter($parti, fn ($cents) => (int) $cents !== 0)
                    ? sprintf('Sulla gestione %s il debito di %s si divide per quota: %s%s.', $c['gestione'] ?? 'gestione', MoneyHelper::format((int) $c['importo']), implode(', ', array_map(fn ($id) => sprintf((int) $id === (int) $uscente->anagrafica_id ? '%s restano a %s (%s %%)' : '%s a %s (%s %%)', MoneyHelper::format((int) $parti[$id]), $nomi[$id] ?? '?', rtrim(rtrim(number_format($pesi[$id], 2, ',', '.'), '0'), ',')), array_keys($parti))), $coda)
                    : sprintf('Sulla gestione %s il debito di %s si divide secondo la parte dell\'usufrutto che torna a ciascun nudo proprietario: %s%s.', $c['gestione'] ?? 'gestione', MoneyHelper::format((int) $c['importo']), implode(', ', array_map(fn ($id) => sprintf((int) $id === (int) $uscente->anagrafica_id ? '%s restano a %s' : '%s a %s', MoneyHelper::format((int) $parti[$id]), $nomi[$id] ?? '?'), array_keys($parti))), $coda);
            }
            $esito['coppie'] = $coppie;
            foreach ($restaAChiEsce as $u) {
                $righe[] = sprintf('Per %s il nudo proprietario è %s: torna proprietario pieno, e le sue quote dal %s restano a suo nome, senza conguaglio.',
                    Immobile::find($u)?->nome ?? 'l\'unità', $uscente->anagrafica?->nome ?? 'chi esce', $this->data($decorrenza));
            }
            array_push($esito['frasi'], ...$righe);
        } else {
            $esito['coppie'] = array_map(fn ($c) => $c + ['anagrafica_entrante_id' => $esito['anagrafica_entrante_id'], 'entrante_nome' => $controparte->nome], $esito['coppie']);
        }

        return $esito;
    }

    /** «Rossi Mario (60 %) e Neri Paolo (40 %)» con più nudi; il nome solo con uno; il ripiego senza nessuno. */
    private function elencoNudi(?TitolaritaImmobile $nudo, string $ripiego = 'il nudo proprietario'): string
    {
        if ($this->nudi->count() > 1 && $this->consolida !== []) {
            // Decisione 62: con il consolidamento di più nudi la parte di ciascuno la dice `tornaPieno()`.
            return $this->elenco($this->nudi->map(fn (TitolaritaImmobile $t) => $t->anagrafica?->nome ?? '?')->all());
        }
        if ($this->nudi->count() > 1) {
            return $this->elenco($this->nudi->map(fn (TitolaritaImmobile $t) => sprintf('%s (%s %%)', $t->anagrafica?->nome ?? '?', rtrim(rtrim(number_format((float) $t->quota, 2, ',', '.'), '0'), ',')))->all());
        }

        return $nudo?->anagrafica?->nome ?? $ripiego;
    }

    /** Il verbo al numero giusto: «torna proprietario pieno» / «tornano proprietari pieni». */
    private function tornaPieno(string $verbo = 'torna'): string
    {
        $risultera = $verbo === 'risulterà';
        // Consolidamento di legge: pieno solo per la parte dell'usufrutto che finisce, nudo per il resto.
        if ($this->nudi->count() === 1 && ($quota = $this->consolida[(int) $this->nudi->first()->id] ?? null) !== null) {
            $resto = $this->numeroQuota(round((float) $this->nudi->first()->quota - (float) $quota, 2));
            return $risultera
                ? sprintf('risulterà proprietario pieno per %s e resterà nudo proprietario dell\'altro %s %%', NudiDellEstinzione::percentuale((float) $quota), $resto)
                : sprintf('torna proprietario pieno per %s, e resta nudo proprietario dell\'altro %s %%', NudiDellEstinzione::percentuale((float) $quota), $resto);
        }
        // Decisione 62: più nudi, ciascuno pieno per la sua parte dell'usufrutto che finisce. Rilievo G4: chi riceve tutta la sua
        // quota torna pieno per intero e non ha un resto; solo gli altri restano nudi del resto.
        if ($this->nudi->count() > 1 && $this->consolida !== []) {
            $inParte = $this->nudi->filter(fn (TitolaritaImmobile $t) => isset($this->consolida[(int) $t->id]))->values();
            $parti = $this->elenco($this->nudi->map(fn (TitolaritaImmobile $t) => isset($this->consolida[(int) $t->id])
                ? sprintf('%s per %s', $t->anagrafica?->nome ?? '?', NudiDellEstinzione::percentuale((float) $this->consolida[(int) $t->id]))
                : sprintf('%s per tutta la sua quota (%s %%)', $t->anagrafica?->nome ?? '?', $this->numeroQuota((float) $t->quota)))->all());
            $resto = match (true) {
                $inParte->count() === $this->nudi->count() => $risultera ? 'e resteranno nudi proprietari del resto' : 'e restano nudi proprietari del resto',
                $inParte->count() === 1 => sprintf($risultera ? 'e %s resterà nudo proprietario del resto' : 'e %s resta nudo proprietario del resto', $inParte->first()->anagrafica?->nome ?? '?'),
                default => sprintf($risultera ? 'e %s resteranno nudi proprietari del resto' : 'e %s restano nudi proprietari del resto', $this->elenco($inParte->map(fn (TitolaritaImmobile $t) => $t->anagrafica?->nome ?? '?')->all())),
            };

            return sprintf($risultera ? 'risulteranno proprietari pieni, ciascuno per la sua parte dell\'usufrutto che finisce (%s), %s' : 'tornano proprietari pieni, ciascuno per la sua parte dell\'usufrutto che finisce (%s), %s', $parti, $resto);
        }

        return $this->nudi->count() > 1 ? ($risultera ? 'risulteranno proprietari pieni' : 'tornano proprietari pieni') : ($risultera ? 'risulterà proprietario pieno' : 'torna proprietario pieno');
    }

    private function numeroQuota(float $quota): string
    {
        return rtrim(rtrim(number_format($quota, 2, ',', '.'), '0'), ',');
    }

    /** La vendita con riserva d'usufrutto (decisione 28), dal modulo: la dichiara l'amministratore. */
    private function riserva(string $tipo, array $dati): bool
    {
        return $tipo === 'vendita' && ($dati['sottotipo'] ?? null) === Subentro::RISERVA_USUFRUTTO;
    }

    /**
     * Fase 1-ter della beta.41 (03/10/2026), le righe di un'altra quota: i ruoli che chi esce **tiene accanto** alla
     * quota che esce, per unità — le sue altre righe in vigore il giorno dell'atto con un'altra tipologia (Ugo proprietario
     * pieno di metà e usufruttuario dell'altra). Le righe di riparto di quei ruoli sono di un'altra quota, e il conguaglio non
     * le fa passare. Prima passavano: chi entrava pagava i giorni dell'unità intera. Non la quota che il passaggio stesso
     * lascia a chi esce (la nuda proprietà della costituzione): le righe congelate di quel ruolo vengono da quando l'aveva
     * già, e sono la quota che esce — Ugo nudo proprietario torna pieno quando si estingue l'usufrutto di Rita e poi
     * costituisce l'usufrutto a Carlo: con la legge l'ordinaria delle sue righe da nudo passa a Carlo per i giorni.
     *
     * E i ruoli che chi esce **ha già ceduto** con un passaggio precedente sulla stessa unità: Ugo
     * proprietario pieno di metà e usufruttuario dell'altra, il suo usufrutto si estingue il 10/3 e a giugno vende la metà
     * piena. Il piano, con rate già emesse, non si ricalcola: le righe del suo usufrutto restano congelate sull'anno intero,
     * ma quella parte l'ha già regolata il conguaglio dell'estinzione, a favore della nuda proprietaria. Il ruolo ceduto è
     * quello di chi entra nella costituzione e nella riserva (chi esce resta nudo proprietario o usufruttuario), la riga di
     * chi esce negli altri passaggi; non conta se è la tipologia che esce adesso (Ugo vende metà a marzo e l'altra metà a
     * settembre). Un ruolo che chi esce non ha ceduto, ma che è cambiato sotto di lui (la nuda proprietà che diventa piena
     * quando si estingue l'usufrutto di un altro), passa: è la stessa quota.
     *
     * @param list<int> $immobileIds
     * @return array<int, list<string>>
     */
    private function ruoliCheRestano(string $tipo, array $dati, TitolaritaImmobile $uscente, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        $giorno = $decorrenza->toDateString();
        $ruoli = [];
        foreach (DB::table('anagrafica_immobile')->whereIn('immobile_id', $immobileIds)->where('anagrafica_id', $uscente->anagrafica_id)
            ->where('id', '!=', $uscente->id)->where('tipologia', '!=', $uscente->tipologia)
            ->where(fn ($q) => $q->whereNull('data_inizio')->orWhereDate('data_inizio', '<', $giorno))
            ->where(fn ($q) => $q->whereNull('data_fine')->orWhereDate('data_fine', '>=', $giorno))
            ->get(['immobile_id', 'tipologia']) as $r) {
            $ruoli[(int) $r->immobile_id][$r->tipologia] = true;
        }
        // U3 (decisione 59, 1.11.0-beta.43): contano anche i passaggi dello stesso giorno già registrati. Lo stesso giorno vale
        // l'ordine di registrazione, e questo passaggio non è ancora registrato: chi vende il 30/9 la nuda proprietà e poi, lo
        // stesso giorno, la metà piena ha già ceduto la nuda (la sua riga è chiusa il 29/9 e non è più fra quelle in vigore).
        // Prima la seconda vendita portava a chi comprava anche la quota già venduta con la prima.
        $precedenti = Subentro::whereIn('immobile_id', $immobileIds)->where('anagrafica_uscente_id', $uscente->anagrafica_id)
            ->whereDate('decorrenza', '<=', $giorno)->get();
        $righeUscenti = DB::table('anagrafica_immobile')->whereIn('id', $precedenti->pluck('riga_uscente_id')->filter())->pluck('tipologia', 'id');
        foreach ($precedenti as $p) {
            // Rilievo S-R3: un passaggio registrato prima della beta.37 non ha il sottotipo nel registro. Seconda revisione (M2-3):
            // l'estinzione salva come chi entra il primo nudo proprietario, quindi si riconosce dalla tipologia, «proprietario»
            // (come `StoricoTitolarita::sottotipo`), non da chi entra.
            $ceduto = $p->riservaUsufrutto() || ($p->tipo_passaggio === 'usufrutto' && ! $p->estinzioneUsufrutto())
                ? $p->tipologia
                : ($righeUscenti[(int) $p->riga_uscente_id] ?? null);
            if ($ceduto !== null && $ceduto !== $uscente->tipologia) {
                $ruoli[(int) $p->immobile_id][$ceduto] = true;
            }
        }

        return array_map('array_keys', $ruoli);
    }

    /** Rilievo D4: il passaggio «come la voce» da cui è nato l'usufrutto che si estingue, se c'è. */
    private function usufruttoNatoComeLaVoce(?TitolaritaImmobile $usufrutto): ?Subentro
    {
        if ($usufrutto === null || $usufrutto->tipologia !== 'usufruttuario') {
            return null;
        }
        $origine = Subentro::origineDellUsufrutto((int) $usufrutto->id, (int) $usufrutto->immobile_id);

        return $origine?->ordinariaComeLaVoce() ? $origine : null;
    }

    /** Decisione 31.5: la scelta sull'ordinaria si fa alla costituzione e alla riserva d'usufrutto, non all'estinzione. */
    private function sceltaSullOrdinaria(string $tipo, array $dati): bool
    {
        return ($tipo === 'usufrutto' && ($dati['sottotipo'] ?? 'costituzione') !== 'estinzione') || $this->riserva($tipo, $dati);
    }

    /**
     * La scelta dal modulo. Alla costituzione e alla riserva la richiesta la vuole (rilievo A3): il ripiego sulla legge
     * (art. 1004 c.c.) vale solo per i passaggi in cui la scelta non si fa.
     */
    private function sceltaOrdinaria(array $dati): string
    {
        return ($dati['ordinaria_dopo_atto'] ?? null) === Subentro::ORDINARIA_COME_LA_VOCE ? Subentro::ORDINARIA_COME_LA_VOCE : Subentro::ORDINARIA_ALL_USUFRUTTUARIO;
    }

    /**
     * Decisioni 31.5 e 31.6 (1.11.0-beta.41): chi paga l'ordinaria dal giorno dell'atto. La legge (art. 1004 c.c.) è già
     * proposta; scegliendola, le voci sul «Proprietario» delle gestioni ordinarie passano all'«Usufruttuario», tutte spuntate
     * salvo quelle che l'amministratore toglie (`voci_da_tenere`), con le altre unità in usufrutto che la voce tocca. Con
     * «come la voce» il conguaglio segue la voce e le voci non si toccano.
     *
     * @param list<int> $immobileIds
     * @return array{applicabile: bool, scelta: ?string, usufruttuario: ?string, nudo: ?string, voci: list<array<string, mixed>>, frasi: list<string>}
     */
    private function bloccoOrdinaria(string $tipo, array $dati, Condominio $condominio, array $immobileIds, ?TitolaritaImmobile $uscente, ?string $nomeUscente, ?string $nomeEntrante, CarbonImmutable $decorrenza): array
    {
        if ($uscente === null || ! $this->sceltaSullOrdinaria($tipo, $dati)) {
            // Rilievo D4: all'estinzione nessuna scelta, ma quella dell'usufrutto che si chiude si eredita e si registra.
            $origine = $tipo === 'usufrutto' ? $this->usufruttoNatoComeLaVoce($uscente) : null;

            return ['applicabile' => false, 'scelta' => null, 'usufruttuario' => null, 'nudo' => null, 'voci' => [], 'frasi' => [], 'frasi_bloccate' => [], 'frasi_altri_usufrutti' => [], 'impronta' => null,
                'ereditata' => $origine === null ? null : ['scelta' => Subentro::ORDINARIA_COME_LA_VOCE, 'subentro_id' => (int) $origine->id, 'decorrenza' => $origine->decorrenza->toDateString()]];
        }
        $scelta = $this->sceltaOrdinaria($dati);
        // Nella costituzione chi esce resta nudo proprietario e chi entra è l'usufruttuario; nella riserva il contrario.
        $usufruttuario = $this->riserva($tipo, $dati) ? $nomeUscente : $nomeEntrante;
        $nudo = $this->riserva($tipo, $dati) ? $nomeEntrante : $nomeUscente;
        $daTenere = array_flip(array_map('intval', $dati['voci_da_tenere'] ?? []));
        // Decisione 31.8: una voce bloccata da un piano approvato non si sposta; si elenca, con la ragione.
        $voci = array_map(fn (array $v) => $v + ['spostata' => $scelta === Subentro::ORDINARIA_ALL_USUFRUTTUARIO && ! $v['bloccata'] && ! isset($daTenere[$v['id']])],
            $this->vociDaSpostare->candidate((int) $condominio->id, $immobileIds, $decorrenza));

        $dal = $this->data($decorrenza);
        $frasi = [];
        $frasiBloccate = [];
        if ($scelta === Subentro::ORDINARIA_ALL_USUFRUTTUARIO) {
            $spostate = array_values(array_filter($voci, fn ($v) => $v['spostata']));
            // Rilievo T-A1 della revisione della Fase 1-ter: senza rate emesse sull'unità non c'è conguaglio, e la frase non lo promette.
            // Con il criterio unico della beta.42 (decisione 34.1): c'è conguaglio se l'unità ha quote in un piano che non si ricalcola più.
            $emesse = \App\Models\Gestionale\PianoRate::immutabiliFra(DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->whereIn('rate_quote.immobile_id', $immobileIds)->distinct()->pluck('rate.piano_rate_id')) !== [];
            // Verifica a video: i piani dopo fanno lo stesso solo per le voci che passano davvero. Rilievo A1 della Fase 1-bis: e
            // solo per le voci di oggi — una voce creata dopo, o di una gestione che si aprirà, parte dal «Proprietario».
            // Rilievo T2-3 della seconda revisione della Fase 1-ter: nella riserva l'ordinaria resta a chi vende e non si conguaglia
            // (il blocco delle rate lo dice): «nel conguaglio» era vero solo per la costituzione.
            $riserva = $this->riserva($tipo, $dati);
            $sulleEmesse = $riserva ? 'restano sue anche sulle rate già emesse, senza conguaglio,' : 'nel conguaglio delle rate già emesse';
            $frasi[] = sprintf('Dal %s le spese ordinarie sono di %s, usufruttuario (art. 1004 c.c.)%s Le voci create dopo, e quelle delle gestioni che si apriranno, partono dal «Proprietario»: per darle all\'usufruttuario mettile su «Usufruttuario».', $dal, $usufruttuario ?? 'chi ha l\'usufrutto', match (true) {
                $emesse && $voci !== [] && count($spostate) === count($voci) => ': ' . $sulleEmesse . ' e, per le voci di oggi, nei piani generati o ricalcolati dopo.',
                $emesse && $spostate !== [] => ': ' . $sulleEmesse . ' e, per le voci che passano all\'«Usufruttuario», nei piani generati o ricalcolati dopo.',
                $emesse => ': ' . rtrim($sulleEmesse, ',') . '.',
                $voci !== [] && count($spostate) === count($voci) => ': per le voci di oggi, nei piani generati o ricalcolati dopo.',
                $spostate !== [] => ': per le voci che passano all\'«Usufruttuario», nei piani generati o ricalcolati dopo.',
                // Rilievo T2-7: una frase a sé, non un «ma» dopo i due punti.
                default => '. Su questa unità però non ci sono rate emesse da conguagliare e nessuna voce si sposta: in questo passaggio la scelta non cambia niente.',
            });
            if ($spostate !== []) {
                $frasi[] = sprintf('Per i piani che verranno, %s dal «Proprietario» all\'«Usufruttuario»: %s.', count($spostate) === 1 ? 'questa voce passa' : 'queste voci passano', $this->elenco(array_map(fn ($v) => sprintf('%s (%s, %s)', $v['conto'], $v['tabella'], $v['gestione']), $spostate)));
                $altre = collect($spostate)->flatMap(fn ($v) => $v['altre_unita'])->groupBy('immobile_id');
                if ($altre->isNotEmpty()) {
                    $frasi[] = sprintf('Una voce vale per tutta la tabella: cambia chi paga anche su %s, dal nudo proprietario all\'usufruttuario. Dove non c\'è usufrutto paga il proprietario, come prima.', $this->elenco($altre->map(function (Collection $u) {
                        $importo = $u->sum(fn ($x) => (int) ($x['importo'] ?? 0));

                        return sprintf('%s (%s)%s', $u->first()['immobile'], $u->first()['usufruttuari'], $importo !== 0 ? sprintf(', %s nell\'ultimo piano', MoneyHelper::format($importo)) : '');
                    })->values()->all()));
                }
            }
            // Decisioni 31.8 e 31.9, testi della revisione della Fase 1-ter (T-A1, T-B1, T-B2): il rimedio vero dipende dal piano che
            // blocca la voce. Con rate a giornale la scelta passa dal conguaglio (nella riserva l'ordinaria resta a chi vende), e
            // spostare la voce non serve. Senza, non c'è conguaglio e il piano ricalcolato seguirebbe la voce rimasta sul
            // «Proprietario»: la scelta non lo raggiunge, e lo si dice. Un piano straordinario da fatture tiene la voce sul
            // «Proprietario» per i piani ordinari. Seconda revisione (T2-1, T2-2): si classifica per piano, non per voce — una voce
            // bloccata da piani di casi diversi ha una frase sua, che nomina ogni piano con il suo caso e non promette il rimedio.
            $caso = fn (array $p) => $p['da_fatture'] ? 'fatture' : ($p['a_giornale'] ? 'giornale' : 'senza');
            $casi = fn (array $v) => collect($v['piani_bloccanti'] ?? [])->map($caso)->unique()->values()->all();
            $bloccate = collect($voci)->filter(fn ($v) => $v['bloccata']);
            $senzaGiornale = $bloccate->filter(fn ($v) => $casi($v) === ['senza']);
            $conGiornale = $bloccate->filter(fn ($v) => $casi($v) === ['giornale']);
            $daFatture = $bloccate->filter(fn ($v) => $casi($v) === ['fatture']);
            $miste = $bloccate->diffKeys($senzaGiornale)->diffKeys($conGiornale)->diffKeys($daFatture);
            $una = fn (Collection $vv) => $vv->count() === 1;
            $nomiPiani = fn (Collection $vv) => $vv->flatMap(fn ($v) => $v['piani_bloccanti'] ?? [])->pluck('nome')->unique()->values();
            // «nel piano «A»» / «nei piani «A» e «B»», e l'aggettivo che si accorda.
            $nel = function (Collection $vv, string $uno, string $piu) use ($nomiPiani) {
                $nomi = $nomiPiani($vv);

                return $nomi->count() === 1 ? sprintf('nel piano «%s», %s', $nomi[0], $uno) : sprintf('nei piani %s, %s', $this->elenco($nomi->map(fn ($n) => '«' . $n . '»')->all()), $piu);
            };
            $conGiornaleVale = $riserva
                ? sprintf('l\'ordinaria resta a %s, che resta usufruttuario, anche sulle quote ancora in bozza, e non si conguaglia', $usufruttuario ?? 'chi vende')
                : sprintf('il conguaglio dà a %s l\'ordinaria dal %s, anche sulle quote ancora in bozza', $usufruttuario ?? 'chi ha l\'usufrutto', $dal);
            if ($senzaGiornale->isNotEmpty()) {
                $unPiano = $nomiPiani($senzaGiornale)->count() === 1;
                $frasiBloccate[] = sprintf('%s %s %s: %s, non c\'è conguaglio, e generato o ricalcolato %s darà dal %s l\'ordinaria di %s a %s, nudo proprietario: la scelta non lo raggiunge. Per applicarla, prima di registrare il passaggio %s: con il passaggio %s; poi %s.',
                    $una($senzaGiornale) ? 'La voce' : 'Le voci', $this->nomiVoci($senzaGiornale->values()->all()) . ($una($senzaGiornale) ? ' è' : ' sono'),
                    $nel($senzaGiornale, 'approvato e ancora senza niente a giornale', 'approvati e ancora senza niente a giornale'),
                    $una($senzaGiornale) ? 'non si sposta' : 'non si spostano', $unPiano ? 'il piano' : 'ogni piano', $dal, $una($senzaGiornale) ? 'quella voce' : 'quelle voci', $nudo ?? 'il nudo proprietario',
                    $unPiano ? 'riporta il piano in bozza dalla sua pagina' : 'riporta in bozza, dalla loro pagina, tutti questi piani',
                    $una($senzaGiornale) ? 'la voce si sposterà' : 'le voci si sposteranno', $unPiano ? 'riapprova il piano e ricalcolalo' : 'riapprovali e ricalcolali');
            }
            if ($conGiornale->isNotEmpty()) {
                $frasiBloccate[] = sprintf('%s %s %s: %s, e per %s non serve: %s. %s sul «Proprietario» per i piani che verranno in questa gestione.',
                    $una($conGiornale) ? 'La voce' : 'Le voci', $this->nomiVoci($conGiornale->values()->all()) . ($una($conGiornale) ? ' è' : ' sono'),
                    $nel($conGiornale, 'che non si ricalcola più', 'che non si ricalcolano più'),
                    $una($conGiornale) ? 'non si sposta' : 'non si spostano', $nomiPiani($conGiornale)->count() === 1 ? 'quel piano' : 'quei piani', $conGiornaleVale, $una($conGiornale) ? 'Resta' : 'Restano');
            }
            if ($daFatture->isNotEmpty()) {
                $frasiBloccate[] = sprintf('%s %s nelle fatture %s: %s sul «Proprietario», e nei piani ordinari generati o ricalcolati %s a %s, nudo proprietario.',
                    $una($daFatture) ? 'La voce' : 'Le voci', $this->nomiVoci($daFatture->values()->all()) . ($una($daFatture) ? ' è' : ' sono'),
                    str_replace(['nel piano', 'nei piani'], ['del piano straordinario', 'dei piani straordinari'], $nel($daFatture, 'approvato', 'approvati')),
                    $una($daFatture) ? 'resta' : 'restano', $una($daFatture) ? 'andrà' : 'andranno', $nudo ?? 'il nudo proprietario');
            }
            foreach ($miste as $v) {
                $perCaso = collect($v['piani_bloccanti'] ?? [])->groupBy($caso)->map(fn (Collection $pp) => $this->elenco($pp->pluck('nome')->unique()->map(fn ($n) => '«' . $n . '»')->values()->all()));
                $parti = array_values(array_filter([
                    isset($perCaso['giornale']) ? sprintf('%s, che non si ricalcola più: lì %s', $perCaso['giornale'], $conGiornaleVale) : null,
                    isset($perCaso['senza']) ? sprintf('%s, approvato e ancora senza niente a giornale: generato o ricalcolato, darà dal %s l\'ordinaria della voce a %s, nudo proprietario', $perCaso['senza'], $dal, $nudo ?? 'il nudo proprietario') : null,
                    isset($perCaso['fatture']) ? sprintf('%s, straordinario, nelle sue fatture', $perCaso['fatture']) : null,
                ]));
                // Nessun rimedio: un piano a giornale non torna in bozza, e uno straordinario in bozza sposterebbe anche la sua parte
                // straordinaria (art. 1005).
                $frasiBloccate[] = sprintf('La voce %s è bloccata da più piani — %s. Non si sposta.', $this->nomiVoci([$v]), implode('; ', $parti));
            }
            array_push($frasi, ...$frasiBloccate);
            $tenute = array_values(array_filter($voci, fn ($v) => ! $v['spostata'] && ! $v['bloccata']));
            if ($tenute !== []) {
                $frasi[] = sprintf(count($tenute) === 1 ? 'Resta sul «Proprietario» la voce %s: nei piani che verranno andrà a %s, nudo proprietario.' : 'Restano sul «Proprietario» le voci %s: nei piani che verranno andranno a %s, nudo proprietario.',
                    $this->nomiVoci($tenute), $nudo ?? 'il nudo proprietario');
            }
        } else {
            $frasi[] = sprintf('Dal %s le spese ordinarie seguono la voce: quelle sul «Proprietario» vanno a %s, nudo proprietario, le altre a %s, usufruttuario. Lo stesso nel conguaglio delle rate già emesse. Le voci non si toccano.', $dal, $nudo ?? 'il nudo proprietario', $usufruttuario ?? 'chi ha l\'usufrutto');
        }
        // Decisione 33 (1.11.0-beta.42, D-U1): un usufrutto della gestione nato con la scelta opposta, anche finito. In una chiave
        // sua: il modulo mostra delle `frasi` solo la prima, la conclusione, e queste devono vedersi prima della conferma.
        $altriUsufrutti = $this->usufruttiConLaSceltaOpposta($scelta, $voci, $immobileIds, $decorrenza);

        return ['applicabile' => true, 'scelta' => $scelta, 'usufruttuario' => $usufruttuario, 'nudo' => $nudo, 'voci' => $voci, 'frasi' => $frasi,
            // Le frasi delle voci bloccate, che il modulo mostra nel riquadro del lucchetto (rilievo T-B1).
            'frasi_bloccate' => $frasiBloccate,
            'frasi_altri_usufrutti' => $altriUsufrutti,
            // Rilievo S1: il modulo la rimanda con la registrazione, che rifiuta se l'elenco è cambiato nel frattempo.
            'impronta' => VociDaSpostare::impronta($voci), 'ereditata' => null];
    }

    /**
     * Decisione 33 (1.11.0-beta.42, domanda D-U1): la scelta sull'ordinaria agisce sulla voce, e la voce vale per tutto l'anno
     * del piano e per tutta la tabella. Un usufrutto della stessa gestione nato con la scelta opposta — anche finito — ne è
     * toccato, e il pannello lo dice prima della conferma (`decide_l_amministratore.md`): il motore non cambia, servirebbero
     * coefficienti datati.
     *
     * - Con la legge: le voci che passano all'«Usufruttuario» vanno all'usufruttuario anche per i giorni di un usufrutto nato
     *   «come dice ogni voce», nei piani generati o ricalcolati dopo (nel rapporto, € 292,60 per 89 giorni che quella scelta
     *   dava al nudo proprietario).
     * - Con «come dice ogni voce»: le voci che un usufrutto nato con la legge ha già spostato sono sull'«Usufruttuario», e la
     *   scelta le dà all'usufruttuario anche qui.
     *
     * Solo gli usufrutti con la scelta registrata (dalla 1.11.0-beta.41): prima la scelta non c'era.
     *
     * @param list<array<string, mixed>> $voci
     * @param list<int> $immobileIds
     * @return list<string>
     */
    private function usufruttiConLaSceltaOpposta(string $scelta, array $voci, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        $frasi = [];
        if ($scelta === Subentro::ORDINARIA_ALL_USUFRUTTUARIO) {
            $spostate = array_values(array_filter($voci, fn ($v) => $v['spostata']));
            if ($spostate === []) {
                return [];
            }
            $associazioni = DB::table('conto_tabella_millesimale as ctm')->join('conti', 'conti.id', '=', 'ctm.conto_id')
                ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->join('gestioni', 'gestioni.id', '=', 'piani_conti.gestione_id')
                ->whereIn('ctm.id', array_column($spostate, 'id'))->orderBy('conti.nome')
                ->get(['ctm.tabella_id', 'conti.nome as conto', 'gestioni.id as gestione_id', 'gestioni.nome as gestione', 'gestioni.data_inizio', 'gestioni.data_fine']);
            $perUsufrutto = [];
            foreach ($associazioni as $a) {
                $righe = DB::table('anagrafica_immobile')
                    ->join('anagrafiche', 'anagrafiche.id', '=', 'anagrafica_immobile.anagrafica_id')->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
                    ->whereIn('anagrafica_immobile.immobile_id', DB::table('quote_tabella')->where('tabella_id', $a->tabella_id)->where('valore', '>', 0)->select('immobile_id'))
                    ->where('anagrafica_immobile.tipologia', 'usufruttuario')
                    ->when($a->data_fine !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('anagrafica_immobile.data_inizio')->orWhereDate('anagrafica_immobile.data_inizio', '<=', substr((string) $a->data_fine, 0, 10))))
                    ->when($a->data_inizio !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('anagrafica_immobile.data_fine')->orWhereDate('anagrafica_immobile.data_fine', '>=', substr((string) $a->data_inizio, 0, 10))))
                    ->orderBy('anagrafica_immobile.id')
                    ->get(['anagrafica_immobile.id', 'anagrafica_immobile.immobile_id', 'anagrafica_immobile.data_inizio', 'anagrafica_immobile.data_fine', 'anagrafiche.nome', 'immobili.nome as immobile']);
                foreach ($righe as $r) {
                    if (! (Subentro::origineDellUsufrutto((int) $r->id, (int) $r->immobile_id)?->ordinariaComeLaVoce() ?? false)) {
                        continue;
                    }
                    $perUsufrutto[$r->id . '|' . $a->gestione_id] ??= ['riga' => $r, 'gestione' => $a->gestione, 'voci' => []];
                    $perUsufrutto[$r->id . '|' . $a->gestione_id]['voci'][] = $a->conto;
                }
            }
            foreach ($perUsufrutto as $u) {
                $r = $u['riga'];
                $voci = array_values(array_unique($u['voci']));
                $periodo = $r->data_fine !== null
                    ? sprintf('dal %s al %s', $this->data(CarbonImmutable::parse($r->data_inizio)), $this->data(CarbonImmutable::parse($r->data_fine)))
                    : sprintf('dal %s', $this->data(CarbonImmutable::parse($r->data_inizio)));
                $frasi[] = sprintf('L\'usufrutto di %s su %s, %s, è nato con la scelta «come dice ogni voce»: spostando %s sull\'«Usufruttuario», i piani della gestione «%s» generati o ricalcolati dopo daranno l\'ordinaria di %s a %s, usufruttuario, anche per i giorni di quell\'usufrutto, e non più al nudo proprietario. Per lasciarla al nudo proprietario, togli la spunta alla voce qui sopra.',
                    $r->nome, $r->immobile, $periodo, $this->elenco($voci), $u['gestione'], count($voci) === 1 ? 'quella voce' : 'quelle voci', $r->nome);
            }

            return $frasi;
        }

        // «Come dice ogni voce»: le voci che un usufrutto nato con la legge ha spostato, ancora sull'«Usufruttuario», in una
        // tabella di questa unità e in una gestione aperta il giorno dell'atto.
        $tabelle = DB::table('quote_tabella')->whereIn('immobile_id', $immobileIds)->where('valore', '>', 0)->pluck('tabella_id')->map(fn ($id) => (int) $id)->all();
        $unitaDelleTabelle = DB::table('quote_tabella')->whereIn('tabella_id', $tabelle)->where('valore', '>', 0)->pluck('immobile_id')->unique()->all();
        $origini = Subentro::with('immobile')->whereIn('immobile_id', $unitaDelleTabelle)
            ->whereIn('tipo_passaggio', ['usufrutto', 'vendita'])->orderBy('decorrenza')->orderBy('id')->get()
            ->filter(fn (Subentro $s) => ($s->registro['ordinaria_dopo_atto'] ?? null) === Subentro::ORDINARIA_ALL_USUFRUTTUARIO && ! empty($s->registro['voci_spostate']));
        foreach ($origini as $origine) {
            $ids = array_map(fn ($v) => (int) ($v['id'] ?? 0), $origine->registro['voci_spostate']);
            $voci = DB::table('conto_tabella_millesimale as ctm')->join('conti', 'conti.id', '=', 'ctm.conto_id')
                ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->join('gestioni', 'gestioni.id', '=', 'piani_conti.gestione_id')
                ->whereIn('ctm.id', $ids)->whereIn('ctm.tabella_id', $tabelle)
                ->where(fn ($q) => $q->whereNull('gestioni.data_fine')->orWhereDate('gestioni.data_fine', '>=', $decorrenza->toDateString()))
                ->whereExists(fn ($q) => $q->from('conto_tabella_ripartizioni')->whereColumn('conto_tabella_ripartizioni.conto_tabella_millesimale_id', 'ctm.id')->where('soggetto', 'usufruttuario'))
                ->orderBy('conti.nome')->pluck('conti.nome')->unique()->values()->all();
            if ($voci === []) {
                continue;
            }
            $chi = $origine->riservaUsufrutto() ? ($origine->registro['nomi']['uscente'] ?? null) : ($origine->registro['nomi']['entrante'] ?? null);
            $una = count($voci) === 1;
            $frasi[] = sprintf('%s %s %s già sull\'«Usufruttuario» dall\'usufrutto di %s su %s, nato il %s con la legge: con «come dice ogni voce» %s paga l\'usufruttuario anche qui, perché è il ruolo che la voce dice oggi.',
                $una ? 'La voce' : 'Le voci', $this->elenco($voci), $una ? 'è' : 'sono', $chi ?? 'chi l\'aveva', $origine->immobile?->nome ?? 'un\'unità', $this->data($origine->decorrenza), $una ? 'la' : 'le');
        }

        return $frasi;
    }

    /**
     * Decisione 28.5 (rilievo B1 della Fase 1-bis): i piani ancora ricalcolabili le cui righe di riparto danno a chi vende
     * voci **ordinarie** chieste al «Proprietario» (lo è anche una voce senza coefficienti), su una competenza che arriva
     * alla decorrenza: ricalcolati dopo la riserva, dal giorno dell'atto le darebbero al nudo proprietario. Un piano senza
     * righe di riparto (anteriore alla beta.29) non dice niente, e l'avviso non si scrive.
     *
     * Le voci bloccate (31.8, 31.9) a parte: `bloccati_qui` se le blocca solo questo piano, `bloccati_altrove` se le blocca
     * anche, o soltanto, un altro (rilievo T-B2).
     *
     * @param list<int> $pianoIds
     * @return list<array{nome: string, conti: list<string>, per_scelta: list<string>, bloccati_qui: list<string>, bloccati_altrove: list<string>}>
     */
    private function vociOrdinarieAlNudo(array $pianoIds, int $anagraficaId, array $immobileIds, CarbonImmutable $decorrenza, array $contiSpostati = [], array|bool $perScelta = []): array
    {
        // Rilievo A5 della Fase 1-bis: le voci che vanno al nudo proprietario per la scelta del passaggio — tutte con «come
        // dice ogni voce», quelle a cui si è tolta la spunta con la legge — si dicono a parte, senza il consiglio di spostarle.
        $diScelta = fn ($r) => $perScelta === true || in_array((int) $r->conto_id, is_array($perScelta) ? $perScelta : [], true);

        if ($pianoIds === [] || $immobileIds === []) {
            return [];
        }

        $righe = DB::table('righe_riparto')
            ->join('piani_rate', 'piani_rate.id', '=', 'righe_riparto.piano_rate_id')
            ->join('gestioni', 'gestioni.id', '=', 'piani_rate.gestione_id')
            ->whereIn('righe_riparto.piano_rate_id', $pianoIds)
            ->where('righe_riparto.anagrafica_id', $anagraficaId)
            ->whereIn('righe_riparto.immobile_id', $immobileIds)
            ->where('righe_riparto.tipo', 'riparto')
            ->where('righe_riparto.ruolo_richiesto', 'proprietario')
            // Decisione 31.5: le voci che questo passaggio sposta all'«Usufruttuario» non vanno più al nudo proprietario.
            ->whereNotIn('righe_riparto.conto_id', $contiSpostati)
            ->where(fn ($q) => $q->whereNull('righe_riparto.competenza_al')->orWhereDate('righe_riparto.competenza_al', '>=', $decorrenza->toDateString()))
            ->orderBy('piani_rate.id')->orderBy('righe_riparto.id')
            ->get(['piani_rate.id', 'piani_rate.nome', 'gestioni.tipo', 'righe_riparto.conto_nome', 'righe_riparto.conto_id'])
            ->filter(fn ($r) => NaturaGestione::daStringa($r->tipo) === NaturaGestione::Ordinaria);
        // Chi blocca le voci, proposte o no (nota dello scettico di D2-2 nella seconda revisione della Fase 1-ter).
        $bloccatiDa = $this->vociDaSpostare->bloccatiDa($righe->pluck('conto_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all());
        $bloccata = fn ($r) => array_key_exists((int) $r->conto_id, $bloccatiDa);
        $nomi = fn (Collection $c) => $c->pluck('conto_nome')->filter()->unique()->values()->all();

        return $righe->groupBy('id')
            ->map(function (Collection $righe) use ($diScelta, $bloccatiDa, $bloccata, $nomi) {
                $pianoId = (int) $righe->first()->id;
                $libere = $righe->reject($diScelta);
                $soloQui = fn ($r) => ($bloccatiDa[(int) $r->conto_id] ?? []) === [$pianoId];

                return [
                    'nome' => (string) $righe->first()->nome,
                    'conti' => $nomi($libere->reject($bloccata)),
                    'per_scelta' => $nomi($righe->filter($diScelta)),
                    'bloccati_qui' => $nomi($libere->filter(fn ($r) => $bloccata($r) && $soloQui($r))),
                    'bloccati_altrove' => $nomi($libere->filter(fn ($r) => $bloccata($r) && ! $soloQui($r))),
                ];
            })
            ->values()->all();
    }

    // --- Riferimento e blocco 1 ------------------------------------------------------------------

    private function fraseRiferimento(string $tipo, array $dati, ?string $uscente, ?string $entrante, CarbonImmutable $fino, CarbonImmutable $dal, Collection $proprietari, ?TitolaritaImmobile $nudo): string
    {
        $finoA = $this->data($fino);
        $dalA = $this->data($dal);

        return match ($tipo) {
            'vendita' => $this->riserva($tipo, $dati)
                ? sprintf('%s risulterà proprietario pieno fino al %s compreso; dal %s usufruttuario. %s nudo proprietario dal %s.', $uscente, $finoA, $dalA, $entrante ?? 'Chi entra', $dalA)
                : sprintf('%s risulterà titolare fino al %s compreso. %s dal %s.', $uscente, $finoA, $entrante ?? 'Chi entra', $dalA),
            'inizio_locazione' => sprintf('%s %s risulterà inquilino dal %s.', $this->fraseProprietariRestano($proprietari), $entrante ?? 'Chi entra', $dalA),
            'fine_locazione' => $entrante
                ? sprintf('%s risulterà inquilino fino al %s compreso. %s dal %s.', $uscente, $finoA, $entrante, $dalA)
                : sprintf('%s risulterà inquilino fino al %s compreso. L\'unità resta sfitta dal %s.', $uscente, $finoA, $dalA),
            'usufrutto' => ($dati['sottotipo'] ?? 'costituzione') === 'estinzione'
                ? sprintf('%s risulterà usufruttuario fino al %s compreso. Dal %s %s %s.', $uscente, $finoA, $dalA, $this->elencoNudi($nudo), $this->tornaPieno())
                : sprintf('%s risulterà proprietario pieno fino al %s compreso; dal %s nudo proprietario. %s usufruttuario dal %s.', $uscente, $finoA, $dalA, $entrante ?? 'Chi entra', $dalA),
            default => '',
        };
    }

    private function fraseProprietariRestano(Collection $proprietari): string
    {
        $nomi = $proprietari->map(fn ($t) => $t->anagrafica?->nome)->filter()->values();

        return match ($nomi->count()) {
            0 => 'Nessun proprietario risulta oggi su questa unità.',
            1 => sprintf('Il proprietario resta %s.', $nomi[0]),
            default => sprintf('I proprietari restano %s.', $this->elenco($nomi->all())),
        };
    }

    private function blocco1(string $tipo, array $dati, Immobile $immobile, ?string $uscente, ?string $entrante, string $ruolo, string $quota, CarbonImmutable $fino, CarbonImmutable $dal, Collection $proprietari, ?TitolaritaImmobile $nudo): array
    {
        $finoA = $this->data($fino);
        $dalA = $this->data($dal);
        $frasi = [];

        switch ($tipo) {
            case 'vendita':
                if ($this->riserva($tipo, $dati)) {
                    // Riserva d'usufrutto (decisione 28): lo specchio della costituzione.
                    $frasi[] = sprintf('%s risulterà proprietario pieno fino al %s e usufruttuario dal %s, sulla stessa quota.', $uscente, $finoA, $dalA);
                    $frasi[] = sprintf('%s risulterà nudo proprietario dal %s, al %s %%.', $entrante ?? 'Chi entra', $dalA, $quota);
                    break;
                }
                $frasi[] = sprintf('%s risulterà titolare fino al %s. %s dal %s, come %s al %s %%.', $uscente, $finoA, $entrante ?? 'Chi entra', $dalA, $ruolo, $quota);
                break;
            case 'inizio_locazione':
                $frasi[] = sprintf('%s risulterà inquilino dal %s, al %s %%.', $entrante ?? 'Chi entra', $dalA, $quota);
                $frasi[] = $this->fraseProprietariRestano($proprietari) . ' La locazione si aggiunge, non sostituisce.';
                if (! empty($dati['data_fine_locazione'])) {
                    $frasi[] = sprintf('La data di fine (%s) è una scadenza, non un automatismo: il programma non chiude la locazione da solo.', $this->data($dati['data_fine_locazione']));
                }
                break;
            case 'fine_locazione':
                $frasi[] = sprintf('%s risulterà inquilino fino al %s.', $uscente, $finoA);
                $frasi[] = $entrante
                    ? sprintf('%s inquilino dal %s, al %s %%.', $entrante, $dalA, $quota)
                    : sprintf('L\'unità resta sfitta dal %s: nessun inquilino risulterà registrato.', $dalA);
                break;
            case 'usufrutto':
                if (($dati['sottotipo'] ?? 'costituzione') === 'estinzione') {
                    $frasi[] = sprintf('%s risulterà usufruttuario fino al %s.', $uscente, $finoA);
                    $frasi[] = sprintf('%s %s dal %s.', $this->elencoNudi($nudo, 'Il nudo proprietario'), $this->tornaPieno('risulterà'), $dalA);
                    // Rilievo T12 della Fase 1-bis della .43: con un altro usufrutto in corso è la regola di legge quando l'atto non dice
                    // altro; l'accrescimento all'altro usufruttuario arriva con la successione.
                    if ($this->altroUsufrutto) {
                        $frasi[] = 'È la regola di legge quando l\'atto non dice altro: se l\'atto prevede che l\'usufrutto si accresca all\'altro usufruttuario, non registrare l\'estinzione da qui e correggi le righe da «Modifica associazione».';
                    }
                } else {
                    $frasi[] = sprintf('%s risulterà proprietario pieno fino al %s e nudo proprietario dal %s.', $uscente, $finoA, $dalA);
                    $frasi[] = sprintf('%s risulterà usufruttuario dal %s, al %s %%.', $entrante ?? 'Chi entra', $dalA, $quota);
                }
                break;
        }

        // Le pertinenze collegate: quelle spuntate seguono, quelle non spuntate restano dove sono, e
        // si dice (D5 di pertinenze: verso il condominio conta il titolo, non la presunzione).
        $tutte = $immobile->pertinenze()->get();
        if ($tutte->isNotEmpty() && in_array($tipo, ['vendita', 'usufrutto'], true)) {
            $scelte = $tutte->whereIn('id', $dati['pertinenze'] ?? []);
            $escluse = $tutte->whereNotIn('id', $dati['pertinenze'] ?? []);
            if ($scelte->isNotEmpty()) {
                $frasi[] = sprintf('Il passaggio si applica anche a: %s.', $this->elenco($scelte->pluck('nome')->all()));
            }
            if ($escluse->isNotEmpty()) {
                $frasi[] = sprintf('%s: il passaggio non %s tocca, %s a %s.',
                    $this->elenco($escluse->pluck('nome')->all()),
                    $escluse->count() === 1 ? 'la' : 'le',
                    $escluse->count() === 1 ? 'resta' : 'restano',
                    $uscente ?? 'chi ne è titolare oggi');
            }
        }

        return $frasi;
    }

    private function nomiPertinenze(Immobile $immobile, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $immobile->pertinenze()->whereIn('id', $ids)->pluck('nome')->values()->all();
    }

    // --- Blocco 2: le rate già emesse ------------------------------------------------------------

    /** @return Collection<int, array{rata: int, scadenza: string, importo: int, importo_formattato: string, intestatario: string, piano: string}> */
    private function rateEmesse(array $immobileIds): Collection
    {
        return DB::table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
            ->join('anagrafiche', 'anagrafiche.id', '=', 'rate_quote.anagrafica_id')
            ->whereIn('rate_quote.immobile_id', $immobileIds)
            // Decisione 34 (1.11.0-beta.42): emessa è la rata a giornale, non quella che lo stato dice «emessa».
            ->whereExists(\App\Models\Gestionale\Rata::aGiornale())
            ->where('rate_quote.stato', '!=', 'annullata')
            ->orderBy('rate.data_scadenza')
            ->orderBy('rate.numero_rata')
            ->get([
                'rate.numero_rata', 'rate.data_scadenza', 'rate_quote.importo', 'rate_quote.importo_pagato',
                'rate_quote.anagrafica_id', 'rate_quote.immobile_id', 'anagrafiche.nome as intestatario', 'piani_rate.nome as piano', 'rate_quote.tipo',
            ])
            ->map(fn ($r) => [
                'anagrafica_id' => (int) $r->anagrafica_id,
                'immobile_id' => (int) $r->immobile_id,
                'rata' => (int) $r->numero_rata,
                'scadenza' => substr((string) $r->data_scadenza, 0, 10),
                'importo' => (int) $r->importo,
                'importo_formattato' => MoneyHelper::format((int) $r->importo),
                'intestatario' => $r->intestatario,
                'piano' => $r->piano,
                'natura' => $r->tipo,
            ]);
    }

    /** Scaduto e non pagato di chi esce, sulle rate emesse di questa unità. */
    private function morosita(Immobile $immobile, int $anagraficaId): ?array
    {
        $residuo = (int) DB::table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate_quote.immobile_id', $immobile->id)
            ->where('rate_quote.anagrafica_id', $anagraficaId)
            ->whereExists(\App\Models\Gestionale\Rata::aGiornale())
            ->whereIn('rate_quote.stato', ['da_pagare', 'parzialmente_pagata'])
            ->whereDate('rate.data_scadenza', '<', DateHelper::oggiUtente())
            ->selectRaw('COALESCE(SUM(rate_quote.importo - rate_quote.importo_pagato), 0) as residuo')
            ->value('residuo');

        if ($residuo <= 0) {
            return null;
        }

        return [
            'importo' => $residuo,
            'importo_formattato' => MoneyHelper::format($residuo),
            'intestatario' => Anagrafica::find($anagraficaId)?->nome,
        ];
    }

    private function blocco2(string $tipo, array $dati, Collection $tutte, Collection $diChiEsce, Collection $altre, ?array $morosita, ?string $uscente, ?string $entrante, CarbonImmutable $dal, ?TitolaritaImmobile $nudo, array $conguaglio = ['stato' => 'nessuno', 'frasi' => []]): array
    {
        // Le frasi del conguaglio calcolato, senza la sua riga d'apertura (il blocco ne ha già una per tipo).
        $frasiConguaglio = $conguaglio['stato'] === 'calcolato' ? array_slice($conguaglio['frasi'], 1) : [];
        // Rilievo R5 della Fase 1-bis della .42: un piano fermo per un movimento o un conguaglio di prima (decisioni 34.1 e 38) entra
        // nel conguaglio senza nessuna quota a giornale. Lì «nessuna rata emessa, niente da conguagliare» contraddiceva la coppia.
        $nienteAGiornale = $diChiEsce->isEmpty() && $conguaglio['stato'] === 'calcolato';
        if ($tutte->isEmpty() && ! $nienteAGiornale) {
            return ['Nessuna rata emessa su questa unità. Non c\'è niente da conguagliare.'];
        }

        // Nell'usufrutto chi «entra» nel conguaglio non è sempre chi entra nel modulo: nell'estinzione nessuno
        // entra, è il nudo proprietario che torna pieno; nella costituzione chi esce resta come nudo proprietario.
        $estinzione = $tipo === 'usufrutto' && ($dati['sottotipo'] ?? 'costituzione') === 'estinzione';
        // Rilievo T1 della Fase 1-bis della .43: chi esce che torna pieno della sua parte non è la controparte del conguaglio.
        $altriNudi = $this->nudi->reject(fn (TitolaritaImmobile $t) => $this->chiEsce !== null && (int) $t->anagrafica_id === $this->chiEsce)->values();
        $tornaPienoChi = $this->tornaPieno();
        // Rilievo GT1 del giro sulle correzioni: sull'unità il solo nudo è chi esce, ma su una pertinenza c'è un altro nudo e la sua
        // coppia si propone: l'apertura nomina chi riceve le coppie.
        // Rilievo HT3: per persona, non per nome (due omonimi su due pertinenze sono due persone).
        $nomiDelleCoppie = collect($conguaglio['coppie'] ?? [])->unique('anagrafica_entrante_id')->pluck('entrante_nome')->filter()->values()->all();
        if ($estinzione) {
            $entrante = $altriNudi->count() === $this->nudi->count() ? $this->elencoNudi($nudo)
                : $this->elenco($altriNudi->map(fn (TitolaritaImmobile $t) => $t->anagrafica?->nome ?? '?')->all());
            if ($altriNudi->count() !== $this->nudi->count()) {
                $tornaPienoChi = $altriNudi->count() > 1 ? 'tornano proprietari pieni' : 'torna proprietario pieno';
            }
            if ($altriNudi->isEmpty() && $nomiDelleCoppie !== []) {
                $entrante = $this->elenco($nomiDelleCoppie);
                $tornaPienoChi = count($nomiDelleCoppie) > 1 ? 'tornano proprietari pieni' : 'torna proprietario pieno';
            }
        }

        $frasi = [];
        // Un conguaglio calcolato senza nessuna coppia: nella vendita l'apertura non annuncia «due righe di saldo» che non ci
        // sono, e le frasi del conguaglio dicono perché (rilievo F3-3 della revisione della Fase 1-ter). Nell'usufrutto l'apertura
        // spiega anche la regola dell'ordinaria e la scelta, e si toglie solo quando il conguaglio è fermo.
        $senzaCoppie = $conguaglio['stato'] === 'calcolato' && collect($conguaglio['coppie'] ?? [])->every(fn ($c) => (int) ($c['importo'] ?? 0) === 0);
        if ($tipo === 'inizio_locazione') {
            $frasi[] = sprintf('Le %d quote già emesse su questa unità non si toccano: restano intestate a %s. Dal %s le voci a carico dell\'inquilino verranno intestate a %s.', $tutte->count(), $this->elenco($tutte->pluck('intestatario')->unique()->values()->all()), $this->data($dal), $entrante ?? 'chi entra');
        } elseif ($tipo === 'fine_locazione') {
            if ($diChiEsce->isEmpty() && ! $nienteAGiornale) {
                $frasi[] = sprintf('Nessuna rata emessa è intestata a %s: non c\'è niente da conguagliare. Le altre quote dell\'unità restano a chi le ha ricevute.', $uscente);
            } elseif ($conguaglio['stato'] === 'calcolato') {
                // Rilievo R4 della Fase 1-bis della .42: con il conguaglio fermo (decisione 40) l'apertura non annuncia due righe che
                // non ci sono.
                $frasi[] = $senzaCoppie
                    ? 'Le rate già emesse non si toccano.'
                    : sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s e %s, che entra come inquilino, è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota è divisa in proporzione ai giorni.', $uscente, $entrante ?? 'chi entra');
                array_push($frasi, ...$frasiConguaglio);
            } else {
                $frasi[] = sprintf('Le rate già emesse non si toccano: le %d quote intestate a %s (%s) restano sue. Dalla prossima generazione le voci a carico dell\'inquilino tornano al proprietario.', $diChiEsce->count(), $uscente, MoneyHelper::format((int) $diChiEsce->sum('importo')));
            }
        } elseif ($diChiEsce->isEmpty() && ! $nienteAGiornale) {
            $frasi[] = sprintf('Nessuna rata emessa è intestata a %s: non c\'è niente da conguagliare fra chi esce e chi entra. Le %d quote dell\'unità restano a chi le ha ricevute (%s).', $uscente, $altre->count(), $this->elenco($altre->pluck('intestatario')->unique()->values()->all()));
        } elseif ($estinzione && $this->nudi->isNotEmpty() && $altriNudi->isEmpty() && $nomiDelleCoppie === []) {
            // Il solo nudo è chi esce: torna pieno, e non c'è niente da conguagliare.
            $frasi[] = sprintf('Le rate già emesse non si toccano. %s torna proprietario pieno: le quote dal %s restano a suo nome, senza conguaglio.', $uscente, $this->data($dal));
        } elseif ($tipo === 'usufrutto' && $senzaCoppie && ! empty($conguaglio['non_risolte'])) {
            $frasi[] = 'Le rate già emesse non si toccano.';
            array_push($frasi, ...$frasiConguaglio);
        } elseif ($tipo === 'usufrutto') {
            $frasi[] = $estinzione
                ? (! empty($conguaglio['ordinaria_per_voce_dal'])
                    // Rilievo D4: l'usufrutto è nato «come dice ogni voce»; le voci sul «Proprietario» erano già del nudo proprietario.
                    ? sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s e %s, che %s, è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: l\'ordinaria segue la voce, per la scelta «come dice ogni voce» del passaggio del %s — le voci sul «Proprietario» sono già del nudo proprietario, le altre divise in proporzione ai giorni —, e la quota straordinaria resta al nudo proprietario (art. 63 disp. att. c.c.; art. 1005 c.c.).', $uscente, $entrante, $tornaPienoChi, $this->data(CarbonImmutable::parse($conguaglio['ordinaria_per_voce_dal'])))
                    : sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s e %s, che %s, è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria divisa in proporzione ai giorni, la quota straordinaria resta al nudo proprietario (art. 63 disp. att. c.c.; artt. 1004-1005 c.c.).', $uscente, $entrante, $tornaPienoChi))
                : ($this->sceltaOrdinaria($dati) === Subentro::ORDINARIA_COME_LA_VOCE
                    // Decisione 31.5: «come dice ogni voce».
                    ? sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s, che resta come nudo proprietario, e %s è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: l\'ordinaria segue la voce, come hai scelto — le voci che non sono sul «Proprietario» divise in proporzione ai giorni (dal %s all\'usufruttuario), quelle sul «Proprietario» restano al nudo proprietario —, e la quota straordinaria resta al nudo proprietario (art. 1005 c.c.).', $uscente, $entrante ?? 'chi entra', $this->data($dal))
                    : sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s, che resta come nudo proprietario, e %s è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria divisa in proporzione ai giorni (dal %s all\'usufruttuario, art. 1004 c.c.), la quota straordinaria resta al nudo proprietario (art. 1005 c.c.).', $uscente, $entrante ?? 'chi entra', $this->data($dal)));
            array_push($frasi, ...$frasiConguaglio);
            if ($altre->isNotEmpty()) {
                $frasi[] = sprintf('Le altre %d quote emesse su questa unità restano a %s: questo passaggio non le riguarda.', $altre->count(), $this->elenco($altre->pluck('intestatario')->unique()->values()->all()));
            }
        } elseif ($this->riserva($tipo, $dati)) {
            // Testi T5 della Fase 1-bis: la straordinaria segue la competenza — la delibera, o quella dichiarata sulla fattura
            // (decisione 26) —; l'art. 1005 c.c. dice di chi è dal giorno dell'atto, non quale data conta.
            $frasi[] = sprintf($this->sceltaOrdinaria($dati) === Subentro::ORDINARIA_COME_LA_VOCE
                // Decisione 31.5: «come dice ogni voce».
                ? 'Le rate già emesse non si toccano. %s resta usufruttuario; l\'ordinaria segue la voce, come hai scelto: le voci sul «Proprietario» passano dal giorno dell\'atto a chi compra la nuda proprietà, le altre restano sue. La quota straordinaria va a chi era titolare alla data della delibera o, se la fattura dichiara la competenza, si divide per giorni su quella; dal %s il titolare è %s, nudo proprietario (art. 1005 c.c.). Dove serve, il conguaglio è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano.'
                : 'Le rate già emesse non si toccano. %s resta usufruttuario e continua a dovere la quota ordinaria (art. 1004 c.c.): l\'ordinaria non si conguaglia. La quota straordinaria va a chi era titolare alla data della delibera o, se la fattura dichiara la competenza, si divide per giorni su quella; dal %s il titolare è %s, nudo proprietario (art. 1005 c.c.). Dove serve, il conguaglio è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano.', $uscente, $this->data($dal), $entrante ?? 'chi entra');
            array_push($frasi, ...$frasiConguaglio);
            if ($altre->isNotEmpty()) {
                $frasi[] = sprintf('Le altre %d quote emesse su questa unità restano a %s: questo passaggio non le riguarda.', $altre->count(), $this->elenco($altre->pluck('intestatario')->unique()->values()->all()));
            }
        } elseif ($senzaCoppie) {
            $frasi[] = 'Le rate già emesse non si toccano.';
            array_push($frasi, ...$frasiConguaglio);
            if ($altre->isNotEmpty()) {
                $frasi[] = sprintf('Le altre %d quote emesse su questa unità restano a %s: questo passaggio non le riguarda.', $altre->count(), $this->elenco($altre->pluck('intestatario')->unique()->values()->all()));
            }
        } else {
            // Testi T5 (cantiere C6): come nella riserva, la straordinaria segue la competenza — la delibera, o quella dichiarata
            // sulla fattura, che la divide per giorni (decisione 26) —, non «per intero» alla data della delibera.
            $frasi[] = sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s e %s è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria è divisa in proporzione ai giorni; la quota straordinaria va a chi era titolare alla data della delibera o, se la fattura dichiara la competenza, si divide per giorni su quella (art. 63 disp. att. c.c.; Cass. civ. 30 agosto 2025 n. 24236).', $uscente, $entrante ?? 'chi entra');
            array_push($frasi, ...$frasiConguaglio);
            if ($altre->isNotEmpty()) {
                $frasi[] = sprintf('Le altre %d quote emesse su questa unità restano a %s: questo passaggio non le riguarda.', $altre->count(), $this->elenco($altre->pluck('intestatario')->unique()->values()->all()));
            }
        }

        if ($nienteAGiornale && isset($frasi[0])) {
            // Rilievo R5 e punto 8 della ripresa: l'apertura dice il fatto vero al posto di «Le rate già emesse non si toccano», una
            // volta sola per tutti i rami, con la ragione di ogni piano.
            $perche = collect($conguaglio['quote'] ?? [])->pluck('piano_rate_id')->unique()
                ->map(fn ($id) => \App\Models\Gestionale\PianoRate::find((int) $id))->filter()
                ->map(fn ($piano) => ($f = $piano->fraseDelFermo()) !== null ? sprintf('il piano «%s» %s', $piano->nome, $f) : null)->filter()->values()->all();
            // Rilievo T-F del terzo giro: con più piani fermi la frase va al plurale.
            $frasi[0] = sprintf(count($perche) > 1
                ? 'Nessuna rata di questa unità è ancora a giornale, ma %s: non si ricalcolano più, e le loro quote si conguagliano qui.'
                : 'Nessuna rata di questa unità è ancora a giornale, ma %s: non si ricalcola più, e le sue quote si conguagliano qui.', $perche !== [] ? implode('; ', $perche) : 'il piano non si riscrive più')
                . preg_replace('/^Le rate già emesse non si toccano[.:]?/u', '', $frasi[0]);
        }

        if ($morosita !== null) {
            $frasi[] = sprintf('%s ha %s scaduti e non pagati. Restano suoi: il conguaglio si calcola sulla competenza, non sui pagamenti.', $morosita['intestatario'], $morosita['importo_formattato']);
        }

        return $frasi;
    }

    // --- Blocco 3: chi resta obbligato -----------------------------------------------------------

    /**
     * Le frasi vivono in `FrasiObbligati` (S6): qui la voce dell'anteprima, con i valori proposti; nello
     * storico la voce del vademecum, dai fatti registrati (`FrasiObbligati::daSubentro`).
     */
    private function blocco3(string $tipo, array $dati, Condominio $condominio, Immobile $immobile, ?string $uscente, ?string $entrante, CarbonImmutable $dal, Collection $proprietari, ?TitolaritaImmobile $nudo): array
    {
        // 1.11.0-beta.43: ogni nudo porta la sua quota e, con la nuda che torna piena solo in parte, la sua parte. Le righe in corso il
        // giorno dell'atto, tolte quella di chi esce e quelle di chi torna pieno, dicono se sull'unità restano altri titolari (un altro
        // usufrutto con i suoi nudi, il proprietario pieno dell'altra metà): allora chi torna pieno lo è della parte che finisce.
        $nudi = $this->nudi->map(fn (TitolaritaImmobile $t) => ['nome' => $t->anagrafica?->nome, 'quota' => (float) $t->quota]
            + (isset($this->consolida[(int) $t->id]) ? ['parte' => (float) $this->consolida[(int) $t->id]] : []))->values()->all();
        $restano = $tipo === 'usufrutto' && ($dati['sottotipo'] ?? null) === 'estinzione'
            ? $immobile->titolarita()->whereIn('tipologia', ['proprietario', 'nuda_proprietario', 'usufruttuario'])->get()
                ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($dal) && (int) $t->id !== $this->rigaChiEsce
                    && ! $this->nudi->contains('anagrafica_id', $t->anagrafica_id))
            : collect();

        return $this->frasiObbligati->frasi($tipo, $dati, $condominio, $uscente, $entrante, $dal, $proprietari,
            $nudi !== [] ? $nudi : $nudo?->anagrafica?->nome, registrato: false,
            altriTitolari: $restano->isNotEmpty(), usufruttoCheResta: $restano->contains('tipologia', 'usufruttuario'));
    }

    /** I conti del condominio con almeno un coefficiente a carico dell'inquilino (vive in `FrasiObbligati`). */
    private function vociACaricoDellInquilino(Condominio $condominio): Collection
    {
        return $this->frasiObbligati->vociACaricoDellInquilino($condominio);
    }

    // --- Blocco 4: cosa non cambia ---------------------------------------------------------------

    private function blocco4(string $tipo, array $dati, Condominio $condominio, Immobile $immobile, ?TitolaritaImmobile $uscente, ?Anagrafica $entrante): array
    {
        return [
            'Millesimi: invariati.',
            'Tabelle millesimali: invariate.',
            $this->fraseTeste($tipo, $dati, $condominio, $immobile, $uscente, $entrante),
            'Pertinenze: il collegamento è descrittivo, non sposta importi.',
        ];
    }

    /**
     * Le teste in assemblea si contano per persona (Cass. 25558/2020: chi possiede più unità conta una
     * testa sola), e il numero dipende da **entrambi**: chi esce lo perde solo se non possiede altro nel
     * condominio, chi entra lo acquista solo se non ne possedeva già. Guardare solo chi esce — com'era
     * nella prima stesura — dava la frase opposta al vero nel caso più comune, il venditore della sua
     * unica unità a un acquirente nuovo (uno esce, uno entra: il numero non cambia).
     */
    private function fraseTeste(string $tipo, array $dati, Condominio $condominio, Immobile $immobile, ?TitolaritaImmobile $uscente, ?Anagrafica $entrante): string
    {
        if (in_array($tipo, ['inizio_locazione', 'fine_locazione'], true)) {
            return 'Teste in assemblea: invariate, l\'inquilino non entra nel conteggio dei condòmini.';
        }

        if ($this->riserva($tipo, $dati)) {
            return 'Teste in assemblea: si contano per persona; chi vende resta come usufruttuario e continua a contare, e chi compra la nuda proprietà si aggiunge se non era già condòmino.';
        }

        if ($tipo === 'usufrutto') {
            return ($dati['sottotipo'] ?? 'costituzione') === 'estinzione'
                ? 'Teste in assemblea: si contano per persona; il nudo proprietario era già condòmino e resta, l\'usufruttuario esce dal conteggio se non possiede altro in questo condominio.'
                : 'Teste in assemblea: si contano per persona; chi resta come nudo proprietario continua a contare, e l\'usufruttuario si aggiunge se non era già condòmino.';
        }

        if ($uscente?->anagrafica === null || $entrante === null) {
            return 'Teste in assemblea: si contano per persona, e il numero cambia solo se chi vende non possiede altre unità in questo condominio o se chi compra ne possedeva già una.';
        }

        $altreUscente = $this->altreUnitaDiProprieta($condominio, $immobile, (int) $uscente->anagrafica_id);
        $altreEntrante = $this->altreUnitaDiProprieta($condominio, $immobile, (int) $entrante->id);
        $nomeU = $uscente->anagrafica->nome;
        $nomeE = $entrante->nome;

        // Una vendita di quota fra comproprietari: nessuno entra e nessuno esce dal condominio.
        $entranteGiaSuQuestaUnita = $immobile->titolarita()->where('anagrafica_id', $entrante->id)->get()
            ->contains(fn (TitolaritaImmobile $t) => $t->inCorsoIl(DateHelper::oggiUtenteImmutable()) && in_array($t->tipologia, ['proprietario', 'nuda_proprietario'], true));

        $esce = $altreUscente === 0;
        $entra = $altreEntrante === 0 && ! $entranteGiaSuQuestaUnita;

        return match (true) {
            $esce && $entra => sprintf('Teste in assemblea: si contano per persona, e il numero non cambia — %s esce dal conteggio, %s vi entra.', $nomeU, $nomeE),
            $esce && ! $entra => sprintf('Teste in assemblea: si contano per persona, e il numero scende di uno — %s esce dal conteggio, %s era già nel conteggio.', $nomeU, $nomeE),
            ! $esce && $entra => sprintf('Teste in assemblea: si contano per persona, e il numero sale di uno — %s resta nel conteggio per le altre unità che possiede, %s entra nel condominio per la prima volta.', $nomeU, $nomeE),
            default => sprintf('Teste in assemblea: si contano per persona, e il numero non cambia — %s resta nel conteggio per le altre unità che possiede, %s era già nel conteggio.', $nomeU, $nomeE),
        };
    }

    /** Le altre unità del condominio di cui la persona è oggi proprietaria o nuda proprietaria (regola di `inCorsoIl()`). */
    private function altreUnitaDiProprieta(Condominio $condominio, Immobile $immobile, int $anagraficaId): int
    {
        $oggi = DateHelper::oggiUtenteImmutable();

        return TitolaritaImmobile::query()
            ->join('immobili', 'immobili.id', '=', 'anagrafica_immobile.immobile_id')
            ->where('immobili.condominio_id', $condominio->id)
            ->where('anagrafica_immobile.anagrafica_id', $anagraficaId)
            ->where('anagrafica_immobile.immobile_id', '!=', $immobile->id)
            ->whereIn('anagrafica_immobile.tipologia', ['proprietario', 'nuda_proprietario'])
            ->get(['anagrafica_immobile.*'])
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($oggi))
            ->pluck('immobile_id')->unique()->count();
    }

    // --- Il cancello (1) -------------------------------------------------------------------------

    /**
     * Decisione 14: spunta e nota se il passaggio tocca rate già emesse **oppure** cambia un
     * destinatario di un piano già generato, anche in bozza. Se non tocca nulla di emesso e non cambia
     * destinatari, il pulsante è attivo subito: un cancello che scatta sempre è un cancello che nessuno
     * legge.
     *
     * «Cambia un destinatario» si legge così: esiste una quota di un piano di questa unità intestata a
     * chi esce (la sua parte passerebbe, in tutto o in parte, a chi entra); per l'inizio di una
     * locazione, esiste un piano sull'unità **e** almeno una voce a carico dell'inquilino.
     *
     * **Le quote che restano per legge a chi le ha non chiedono la spunta** (decisione di Vincenzo del 29/09/2026, beta.38,
     * dal referto C3 sulla rivendita della nuda proprietà dopo la riserva). Sono quelle che il conguaglio esclude — `esclusa`
     * sulle emesse, i motivi `ordinaria_riservata`, `ordinaria_dell_usufruttuario` e `straordinaria_del_nudo` sulle bozze:
     * l'ordinaria dell'usufruttuario (art. 1004 c.c.), la straordinaria del nudo proprietario (art. 1005 c.c.). Il
     * passaggio non le tocca e nessuna cambia persona: vanno in `informazioni`, che il modulo mostra senza spunta. Con loro,
     * dalla decisione 28.8 c, le bozze in cui la parte di chi entra è zero per costruzione: `solo_pregresso`,
     * `straordinaria_di_chi_esce` e `fattura_di_chi_esce`; nella vendita le emesse di chi esce con lo stesso criterio delle
     * ultime due; e le emesse di un predecessore di soli saldi pregressi (le gemelle). Le altre ragioni restano `motivi`,
     * come prima: quote che il conguaglio divide, bozze che passano o si conguagliano, quote di un piano con la competenza da
     * determinare, piani ricalcolabili, voci a carico dell'inquilino.
     *
     * @return array{richiesto: bool, motivi: list<string>, informazioni: list<string>, avvisi: list<string>}
     */
    private function cancello(string $tipo, Condominio $condominio, array $immobileIds, ?TitolaritaImmobile $uscente, Collection $rateEmesse, array $intestatari = [], array $riassegnazione = [], ?string $entrante = null, array $quoteConguaglio = [], ?CarbonImmutable $decorrenzaRiserva = null, array $contiSpostati = [], array|bool $contiPerScelta = [], ?\Closure $conta = null): array
    {
        $motivi = [];
        $informazioni = [];
        $avvisi = [];

        // S8-3: le quote di chi esce e dei suoi predecessori (stesso insieme del conguaglio); la frase
        // distingue le due cose, perché «quota emessa a Rossi» quando esce Bianchi va spiegata.
        $intestatari = $intestatari !== [] ? $intestatari : ($uscente ? [(int) $uscente->anagrafica_id] : []);
        $emesseDiChiEsce = $uscente
            ? $rateEmesse->where('anagrafica_id', (int) $uscente->anagrafica_id)->count()
            : 0;
        // Decisione del 29/09: le emesse di chi esce che il conguaglio esclude per legge (la riserva, R4, l'usufrutto) si
        // dicono senza spunta; le altre il conguaglio le divide, e toccano rate già emesse. Il numero è quello del calcolo.
        // Decisione 28.8 c (28.7, «vale ovunque»; primo dubbio del cantiere C7): nella vendita, anche quelle in cui la parte
        // di chi entra è zero per costruzione — una straordinaria, o una voce con la competenza dichiarata sulla fattura,
        // tutta di chi esce: il criterio di `straordinaria_di_chi_esce` e `fattura_di_chi_esce` sulle bozze
        // (`ConguaglioPassaggio::decidiBozze`), sullo stesso gruppo (piano, unità, intestatario). Non quelle di un piano con la
        // competenza da determinare: il programma non sa se cambino persona, e restano fra le toccate.
        $gruppiConguaglio = collect($quoteConguaglio)->groupBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['immobile_id'] . '|' . $q['intestatario_id']);
        $tuttaDiChiEsce = fn (Collection $g) => ! $g->contains(fn ($x) => $x['non_risolta'])
            && $g->every(fn ($x) => (int) $x['entrante'] === 0)
            && $g->contains(fn ($x) => $x['natura'] === NaturaGestione::Straordinaria->value
                || collect($x['per_capitolo'] ?? [])->contains(fn ($c) => ($c['gradino'] ?? null) === 'dichiarata'));
        $escluseDiChiEsce = $uscente
            ? min($emesseDiChiEsce, collect($quoteConguaglio)->where('in_bozza', false)->where('intestatario_id', (int) $uscente->anagrafica_id)
                // Fase 1-ter della beta.41: anche le quote fatte solo di righe di un'altra quota (`tutta_fuori`: l'ordinaria dell'usufruttuario
                // nella vendita della nuda, R4 riga per riga; un ruolo che chi esce tiene) — nessuna parte cambia persona.
                ->filter(fn ($q) => $q['esclusa'] || ! empty($q['tutta_fuori']) || ($tipo === 'vendita' && $tuttaDiChiEsce($gruppiConguaglio[$q['piano_rate_id'] . '|' . $q['immobile_id'] . '|' . $q['intestatario_id']])))->count())
            : 0;
        $toccate = $emesseDiChiEsce - $escluseDiChiEsce;
        if ($toccate > 0) {
            $motivi[] = sprintf('%d %s a %s su questa unità', $toccate, $toccate === 1 ? 'quota di rata già emessa' : 'quote di rate già emesse', $uscente->anagrafica?->nome);
        }
        if ($escluseDiChiEsce > 0) {
            $uno = $escluseDiChiEsce === 1;
            $informazioni[] = sprintf('%d %s a %s su questa unità: %s, questo passaggio non %s tocca', $escluseDiChiEsce, $uno ? 'quota di rata già emessa' : 'quote di rate già emesse', $uscente->anagrafica?->nome, $uno ? 'resta sua' : 'restano sue', $uno ? 'la' : 'le');
        }
        $emesseAiPredecessori = $uscente
            ? $rateEmesse->whereIn('anagrafica_id', $intestatari)->where('anagrafica_id', '!=', (int) $uscente->anagrafica_id)
            : collect();
        if ($emesseAiPredecessori->isNotEmpty()) {
            // Testi T3 (beta.38): quelle che il conguaglio lascia fuori — l'ordinaria che una vendita con riserva d'usufrutto
            // ha lasciato a chi vendeva (rilievo B3), la straordinaria nell'usufrutto — non «passano ancora»: il passaggio
            // non le tocca. Il numero è quello del calcolo, come per le bozze. Decisione 28.8 c (sonda C1): nemmeno le quote
            // emesse di chi vendeva prima fatte di soli saldi pregressi — le gemelle lasciate dal suo passaggio, o la sua rata
            // zero: quota pura zero, niente a chi entra. Stanno fuori dal numero e dalla frase del motivo, fra le informazioni.
            // Decisione 48: una quota mai arrivata a chi esce non è «ferma per legge»: ha il suo motivo, con la spunta.
            $ferma = fn ($q) => empty($q['mai_passata']) && ($q['esclusa'] || ! empty($q['tutta_fuori']) || ((int) $q['quota_pura'] === 0 && (int) $q['entrante'] === 0));
            $emesseDiPredecessori = collect($quoteConguaglio)->where('in_bozza', false)->filter(fn ($q) => ! empty($q['ereditata_da']));
            $escluse = $emesseDiPredecessori->filter($ferma);
            $restanti = $emesseAiPredecessori->count() - $escluse->count();
            if ($restanti > 0) {
                $diChi = $escluse->isEmpty() ? $emesseAiPredecessori->pluck('intestatario') : $emesseDiPredecessori->reject($ferma)->pluck('ereditata_da');
                // L1-7: vale per vendita, locazione e usufrutto, e per catene di qualunque lunghezza — non «ha acquistato».
                // Verifica a video della seconda revisione della Fase 1-ter: dove il conguaglio si ferma, non «passa ancora».
                $ferme = $emesseDiPredecessori->reject($ferma)->filter(fn ($q) => ! empty($q['senza_istantanea']) || ! empty($q['catena_ambigua']))->count();
                // Decisione 35, rilievo R8 della Fase 1-bis della .42: le quote che il passaggio di prima non ha fatto passare non
                // «passano ancora», e la loro competenza non è «passata»: hanno una frase loro.
                $mai = $emesseDiPredecessori->reject($ferma)->filter(fn ($q) => ! empty($q['mai_passata']))->count();
                $motivi[] = $mai > 0 && $mai === $restanti
                    ? sprintf('%d %s a %s: %s, senza conguaglio: il passaggio di prima non %s ha %s passare, e il piano non è stato ricalcolato', $restanti, $restanti === 1 ? 'quota di rata già emessa' : 'quote di rate già emesse',
                        $diChi->unique()->implode(', '), $restanti === 1 ? 'resta sua' : 'restano sue', $restanti === 1 ? 'la' : 'le', $restanti === 1 ? 'fatta' : 'fatte')
                    : sprintf('%d %s a %s, la cui competenza è passata a %s con un passaggio precedente: %s', $restanti, $restanti === 1 ? 'quota di rata già emessa' : 'quote di rate già emesse', $diChi->unique()->implode(', '), $uscente->anagrafica?->nome,
                        match (true) {
                            $ferme > 0 && $ferme === $restanti => 'la parte che ne resta non si separa con certezza, senza conguaglio',
                            $ferme + $mai === $restanti => 'senza conguaglio: la parte che passa non si separa con certezza, o il passaggio di prima non le ha fatte passare',
                            default => 'la parte che ne resta passa ancora',
                        });
            }
            if ($escluse->isNotEmpty()) {
                // Decisione del 29/09: restano a chi le ha, per legge o perché sono di soli saldi pregressi, e si dicono senza spunta.
                $uno = $escluse->count() === 1;
                $informazioni[] = sprintf('%d %s a %s: %s, questo passaggio non %s tocca', $escluse->count(), $uno ? 'quota di rata già emessa' : 'quote di rate già emesse', $escluse->pluck('ereditata_da')->unique()->implode(', '), $uno ? 'resta sua' : 'restano sue', $uno ? 'la' : 'le');
            }
        }

        // Le quote in bozza di chi esce, piano per piano: se il piano ha già emesso a giornale non si ricalcola più
        // (decisione 21, S8-1) — le bozze restano sue, e sono comprese nel conguaglio salvo quelle che il calcolo esclude
        // per legge (testi T2) o in cui nessuna parte cambia persona (decisione 28.8 c); altrimenti il cancello (2) del
        // ricalcolo farà passare il destinatario a chi entra.
        // Lo stesso insieme del conguaglio anche per le bozze (L1-9): le bozze di un predecessore in un piano già a
        // giornale sono comprese, salvo quelle escluse per legge — l'ordinaria che una vendita con riserva d'usufrutto ha
        // lasciato a chi vendeva (rilievo B3, referto C3) —, e il cancello le nomina con il suo nome.
        $bozzePerPiano = $uscente
            ? DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')->join('anagrafiche', 'anagrafiche.id', '=', 'rate_quote.anagrafica_id')
                ->whereIn('rate_quote.immobile_id', $immobileIds)
                ->whereIn('rate_quote.anagrafica_id', $intestatari)
                // DV4 (decisione 59): le bozze di un predecessore contano solo sulle unità dove ha ceduto qualcosa a chi esce.
                ->where(function ($q) use ($intestatari, $immobileIds, $conta) {
                    foreach ($intestatari as $a) {
                        foreach ($immobileIds as $u) {
                            if ($conta === null || $conta((int) $a, (int) $u)) {
                                $q->orWhere(fn ($w) => $w->where('rate_quote.anagrafica_id', $a)->where('rate_quote.immobile_id', $u));
                            }
                        }
                    }
                })
                // Decisione 34: in bozza è la rata che non è andata a giornale, anche se una versione prima l'ha segnata «emessa».
                ->whereNotExists(\App\Models\Gestionale\Rata::aGiornale())
                ->groupBy('rate.piano_rate_id', 'piani_rate.nome', 'rate_quote.anagrafica_id', 'anagrafiche.nome')
                ->get(['rate.piano_rate_id', 'piani_rate.nome', 'rate_quote.anagrafica_id', 'anagrafiche.nome as intestatario', DB::raw('COUNT(*) as n')])
            : collect();
        // Decisione 34.1: non si ricalcola più il piano con una quota a giornale o un movimento — lo stesso criterio del ricalcolo
        // e del conguaglio. Prima contava solo la scrittura, e un piano con un incasso su una bozza finiva fra i ricalcolabili.
        $idImmutabili = \App\Models\Gestionale\PianoRate::immutabiliFra($bozzePerPiano->pluck('piano_rate_id')->all());
        [$immutabili, $ricalcolabili] = $bozzePerPiano->partition(fn ($p) => in_array((int) $p->piano_rate_id, $idImmutabili, true));
        // Le bozze di un piano ancora ricalcolabile contano solo se sono di chi esce: quelle di un predecessore non
        // entrano nel conguaglio e quel piano era già da ricalcolare prima di questo passaggio.
        $ricalcolabiliDiChiEsce = $uscente !== null ? $ricalcolabili->where('anagrafica_id', (int) $uscente->anagrafica_id) : collect();
        if ($ricalcolabiliDiChiEsce->isNotEmpty()) {
            // Decisione 28.5 (rilievo B1 della Fase 1-bis): nella riserva un piano ricalcolato dopo l'atto addebita secondo i
            // coefficienti, e le voci ordinarie sul «Proprietario» (anche quelle senza coefficienti) scendono dal giorno
            // dell'atto al nudo proprietario (`catenaRiparto`). Si dice quando le righe di riparto del piano lo mostrano, con la
            // via. Anche sull'unità mista (Coda 170, decisione 31.1): il ricalcolo dà al nudo la parte venduta. La via è
            // solo «Usufruttuario» (decisione 29.3): la catena usufruttuario → proprietario → nudo non arriva mai all'inquilino,
            // mentre con «Inquilino» su un'unità affittata l'inquilino pagherebbe anche le spese del locatore.
            $alNudo = $decorrenzaRiserva !== null
                ? $this->vociOrdinarieAlNudo($ricalcolabiliDiChiEsce->pluck('piano_rate_id')->all(), (int) $uscente->anagrafica_id, $immobileIds, $decorrenzaRiserva, $contiSpostati, $contiPerScelta)
                : [];
            foreach ($alNudo as $piano) {
                if ($piano['per_scelta'] !== []) {
                    // Rilievo A5: con la scelta fatta piano ricalcolato e conguaglio sono già coerenti; si dice il fatto, niente consiglio.
                    $motivi[] = sprintf('il piano «%s», non ancora emesso, intesta quote a %s: se lo ricalcoli, dal %s le voci sul «Proprietario» (%s) vanno a %s, nudo proprietario, %s',
                        $piano['nome'], $uscente->anagrafica?->nome, $this->data($decorrenzaRiserva), implode(', ', $piano['per_scelta']), $entrante ?? 'chi compra',
                        $contiPerScelta === true ? 'per la scelta «come dice ogni voce»' : 'perché hai tolto loro la spunta');
                }
                // Rilievo T-B2 della revisione della Fase 1-ter: per una voce bloccata (31.8, 31.9) «mettila su Usufruttuario»
                // contraddiceva il riquadro delle voci bloccate — la pagina della voce la vieta, o il passaggio non la sposta.
                // Se la blocca solo questo piano, che non ha niente a giornale, il rimedio è riportarlo in bozza prima di
                // registrare: con il passaggio la voce si sposta. Se la blocca un altro piano, dal passaggio non c'è rimedio.
                if ($piano['bloccati_qui'] !== []) {
                    $motivi[] = sprintf('il piano «%s», non ancora emesso, intesta quote a %s: se lo ricalcoli, dal %s le voci sul «Proprietario» (%s) vanno a %s, nudo proprietario, anche se fra le parti l\'ordinaria è dell\'usufruttuario (art. 1004 c.c.): il piano le blocca, e il passaggio non le sposta. Se devono restare a %s, che resta usufruttuario, riporta il piano in bozza dalla sua pagina prima di registrare il passaggio: con il passaggio si sposteranno su «Usufruttuario»; poi riapprova il piano e ricalcolalo. Dopo la registrazione la strada è annullare il passaggio dallo storico dell\'unità, riportare il piano in bozza e registrarlo di nuovo; il piano va comunque ricalcolato prima di emetterlo',
                        $piano['nome'], $uscente->anagrafica?->nome, $this->data($decorrenzaRiserva), implode(', ', $piano['bloccati_qui']), $entrante ?? 'chi compra', $uscente->anagrafica?->nome);
                }
                if ($piano['bloccati_altrove'] !== []) {
                    $motivi[] = sprintf('il piano «%s», non ancora emesso, intesta quote a %s: se lo ricalcoli, dal %s le voci sul «Proprietario» (%s) vanno a %s, nudo proprietario, anche se fra le parti l\'ordinaria è dell\'usufruttuario (art. 1004 c.c.): un altro piano approvato le blocca, e il passaggio non le sposta — il riquadro «Chi paga l\'ordinaria dal giorno dell\'atto» dice quale; il piano va comunque ricalcolato prima di emetterlo',
                        $piano['nome'], $uscente->anagrafica?->nome, $this->data($decorrenzaRiserva), implode(', ', $piano['bloccati_altrove']), $entrante ?? 'chi compra');
                }
                if ($piano['conti'] === []) {
                    continue;
                }
                $motivi[] = sprintf('il piano «%s», non ancora emesso, intesta quote a %s: se lo ricalcoli, dal %s le voci sul «Proprietario» (%s) vanno a %s, nudo proprietario, perché il programma addebita secondo i coefficienti anche se fra le parti l\'ordinaria è dell\'usufruttuario (art. 1004 c.c.); se devono restare a %s, che resta usufruttuario, prima di ricalcolare metti quelle voci su «Usufruttuario», che dove non c\'è usufrutto le dà al proprietario e mai all\'inquilino; non su «Inquilino», che su un\'unità affittata le fa pagare all\'inquilino (guida «Ruoli e usufrutto»)', $piano['nome'], $uscente->anagrafica?->nome, $this->data($decorrenzaRiserva), implode(', ', $piano['conti']), $entrante ?? 'chi compra', $uscente->anagrafica?->nome);
            }
            if ($ricalcolabiliDiChiEsce->pluck('piano_rate_id')->unique()->count() > count($alNudo)) {
                $motivi[] = sprintf('un piano rate già generato intesta quote a %s: il destinatario cambierebbe', $uscente->anagrafica?->nome);
            }
            // Decisione 58 (1.11.0-beta.43, Coda 217): l'accordo «le rate di questo piano le paga chi vende» non ha un posto nel
            // passaggio. Il piano si ricalcola comunque per giorni, e verso il condominio la posizione è di chi compra; i pagamenti
            // si registrano con «Versato da» (decisione 30), che resta scritto su entrambi gli estratti conto. Rilievo T4 della Fase
            // 1-bis della .43: è un avviso a sé, non una delle «quote che questo passaggio non tocca».
            if ($tipo === 'vendita' && $decorrenzaRiserva === null) {
                $nomi = $ricalcolabiliDiChiEsce->pluck('nome')->unique()->values()->all();
                $avvisi[] = sprintf('se le parti si sono accordate che %s le paga %s: %s si ricalcola comunque per giorni, e verso il condominio le rate dal giorno dell\'atto sono di %s; i pagamenti di %s si registrano con «Versato da», che resta scritto su entrambi gli estratti conto',
                    count($nomi) === 1 ? 'le rate del piano «' . $nomi[0] . '»' : 'le rate dei piani ' . $this->elenco(array_map(fn ($n) => '«' . $n . '»', $nomi)),
                    $uscente->anagrafica?->nome, count($nomi) === 1 ? 'il piano' : 'ogni piano', $entrante ?? 'chi compra', $uscente->anagrafica?->nome);
            }
        }
        // Decisione 25 (B3a): nella vendita le bozze di chi esce dalla decorrenza in poi passano a chi entra; le altre
        // restano e sono comprese nel conguaglio, salvo quelle escluse per legge o ferme (qui sotto, testi T2 e decisione
        // 28.8 c). Il numero è quello del calcolo, non un secondo conteggio.
        $passanoPerPiano = collect($riassegnazione)->mapWithKeys(fn ($r) => [(int) $r['piano_rate_id'] => (int) $r['quote']])->all();
        // Testi T2 (V2 della verifica a video): le bozze che il calcolo esclude per legge — l'ordinaria che la riserva lascia
        // a chi vende, quella dell'usufruttuario nella vendita della nuda proprietà (R4), la straordinaria nell'usufrutto —
        // restano sue ma il conguaglio non le tocca. Il motivo e il numero sono quelli del calcolo.
        // Decisione 28.8 c (28.7): lo stesso per le bozze che il conguaglio non esclude ma in cui nessuna parte cambia
        // persona, perché la parte di chi entra è zero per costruzione: quelle di soli saldi pregressi (quota pura zero; il
        // pregresso resta a chi lo ha, anche nelle catene e nell'estinzione dell'usufrutto) e le spese tutte di chi esce,
        // straordinarie o con la competenza dichiarata sulla fattura prima del passaggio. Non quelle di un piano con la
        // competenza da determinare: il programma non sa se cambino persona, e restano un motivo, con la loro ragione.
        $fermePerPiano = collect($quoteConguaglio)->where('in_bozza', true)
            ->whereIn('motivo_bozza', ['ordinaria_riservata', 'ordinaria_dell_usufruttuario', 'straordinaria_del_nudo', 'solo_pregresso', 'straordinaria_di_chi_esce', 'fattura_di_chi_esce', 'altra_quota'])
            ->countBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['intestatario_id'])->all();
        $nonRisoltePerPiano = collect($quoteConguaglio)->where('in_bozza', true)->where('motivo_bozza', 'non_risolta')
            ->countBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['intestatario_id'])->all();
        // Le bozze senza composizione con un saldo pregresso assorbito (piani della 1.7.x): restano, senza conguaglio.
        $senzaPerPiano = collect($quoteConguaglio)->where('in_bozza', true)->where('motivo_bozza', 'senza_istantanea')
            ->countBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['intestatario_id'])->all();
        // Le bozze di una catena di passaggi in cui il ruolo della riga non dice di quale quota è: restano, senza conguaglio.
        $ambiguePerPiano = collect($quoteConguaglio)->where('in_bozza', true)->where('motivo_bozza', 'catena_ambigua')
            ->countBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['intestatario_id'])->all();
        // Decisione 35 (beta.42): le bozze di un predecessore che il suo passaggio non ha fatto passare: restano, senza conguaglio.
        $maiPerPiano = collect($quoteConguaglio)->where('in_bozza', true)->where('motivo_bozza', 'mai_passata')
            ->countBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['intestatario_id'])->all();
        foreach ($immutabili as $p) {
            $passano = $uscente !== null && (int) $p->anagrafica_id === (int) $uscente->anagrafica_id ? min((int) $p->n, $passanoPerPiano[(int) $p->piano_rate_id] ?? 0) : 0;
            $ferme = min((int) $p->n - $passano, $fermePerPiano[$p->piano_rate_id . '|' . $p->anagrafica_id] ?? 0);
            $nonRisolte = min((int) $p->n - $passano - $ferme, $nonRisoltePerPiano[$p->piano_rate_id . '|' . $p->anagrafica_id] ?? 0);
            $senza = min((int) $p->n - $passano - $ferme - $nonRisolte, $senzaPerPiano[$p->piano_rate_id . '|' . $p->anagrafica_id] ?? 0);
            $ambigue = min((int) $p->n - $passano - $ferme - $nonRisolte - $senza, $ambiguePerPiano[$p->piano_rate_id . '|' . $p->anagrafica_id] ?? 0);
            $mai = min((int) $p->n - $passano - $ferme - $nonRisolte - $senza - $ambigue, $maiPerPiano[$p->piano_rate_id . '|' . $p->anagrafica_id] ?? 0);
            $restano = (int) $p->n - $passano - $ferme - $nonRisolte - $senza - $ambigue - $mai;
            // Il numero davanti a ogni parte solo quando le parti sono più d'una.
            $conNumero = count(array_filter([$passano, $restano, $nonRisolte, $ferme, $senza, $ambigue, $mai])) > 1;
            // D1 (1.11.0-beta.42): alla fine di una locazione senza un nuovo inquilino il conguaglio non si calcola — chi paga
            // dopo l'uscita lo sceglierà l'amministratore (decisione 32, con la beta della locazione) —, e la frase non lo promette.
            $senzaConguaglio = $tipo === 'fine_locazione' && $entrante === null;
            // Rilievo R5 e punto 8 della ripresa: senza nessuna quota a giornale il perché non è ovvio, e si dice la ragione vera.
            $senzaGiornale = ! DB::table('rate')->where('piano_rate_id', $p->piano_rate_id)->whereExists(\App\Models\Gestionale\Rata::aGiornale())->exists();
            $perche = $senzaGiornale ? \App\Models\Gestionale\PianoRate::find($p->piano_rate_id)?->fraseDelFermo() : null;
            $testa = sprintf('il piano «%s» ha %d %s non ancora %s intestat%s a %s: non si può più ricalcolare%s', $p->nome, $p->n, $p->n === 1 ? 'quota' : 'quote', $p->n === 1 ? 'emessa' : 'emesse', $p->n === 1 ? 'a' : 'e', $p->intestatario,
                $perche !== null ? ' (' . $perche . ')' : '');
            $parti = array_filter([
                $passano > 0 ? sprintf('%s a %s (cambia l\'intestatario, non l\'importo)', $passano === (int) $p->n ? ($passano === 1 ? 'passa' : 'passano') : sprintf('%d %s', $passano, $passano === 1 ? 'passa' : 'passano'), $entrante ?? 'chi entra') : null,
                // Rilievo A10: chi decide (decisione 32) e il rimedio, come nelle altre fermate.
                $restano > 0 && $senzaConguaglio ? sprintf('%s%s, senza conguaglio: alla fine di una locazione senza un nuovo inquilino il programma non lo calcola, e chi paga i giorni dopo l\'uscita lo decide l\'amministratore — se serve, con un saldo manuale dal Wallet sulla stessa gestione', $conNumero ? $restano . ' ' : '', $restano === 1 ? 'resta sua' : 'restano sue') : null,
                $restano > 0 && ! $senzaConguaglio ? ($conNumero
                    ? sprintf('%d %s %s compres%s nel conguaglio', $restano, $restano === 1 ? 'resta sua' : 'restano sue', $restano === 1 ? 'ed è' : 'e sono', $restano === 1 ? 'a' : 'e')
                    : sprintf('%s %s compres%s nel conguaglio', $restano === 1 ? 'resta sua' : 'restano sue', $restano === 1 ? 'ed è' : 'e sono', $restano === 1 ? 'a' : 'e')) : null,
                // Decisione 28.8 c: non «comprese nel conguaglio» — il conguaglio non ne propone (è la frase del blocco 2).
                $nonRisolte > 0 ? sprintf('%s%s, senza conguaglio: la competenza del piano non si può determinare', $conNumero ? $nonRisolte . ' ' : '', $nonRisolte === 1 ? 'resta sua' : 'restano sue') : null,
                $senza > 0 ? sprintf('%s%s, senza conguaglio: non %s quanta parte è saldo pregresso', $conNumero ? $senza . ' ' : '', $senza === 1 ? 'resta sua' : 'restano sue', $senza === 1 ? 'dice' : 'dicono') : null,
                $ambigue > 0 ? sprintf('%s%s, senza conguaglio: la parte che passa non si separa con certezza', $conNumero ? $ambigue . ' ' : '', $ambigue === 1 ? 'resta sua' : 'restano sue') : null,
                $mai > 0 ? sprintf('%s%s, senza conguaglio: il passaggio di prima non %s ha %s passare, e il piano non è stato ricalcolato', $conNumero ? $mai . ' ' : '', $mai === 1 ? 'resta sua' : 'restano sue', $mai === 1 ? 'la' : 'le', $mai === 1 ? 'fatta' : 'fatte') : null,
                $ferme > 0 ? sprintf('%s%s: il conguaglio non %s tocca', $conNumero ? $ferme . ' ' : '', $ferme === 1 ? 'resta sua' : 'restano sue', $ferme === 1 ? 'la' : 'le') : null,
            ]);
            // Decisione del 29/09 (28.7): un piano le cui bozze restano tutte a chi le ha, senza che nessuna parte cambi persona,
            // non chiede la spunta; basta una bozza che passa, che si conguaglia o la cui competenza non si sa, e la frase
            // intera resta fra i motivi.
            if ($passano === 0 && $restano === 0 && $nonRisolte === 0 && $senza === 0 && $ambigue === 0 && $mai === 0) {
                $informazioni[] = $testa . ', ' . implode('; ', $parti);
            } else {
                $motivi[] = $testa . ', ' . implode('; ', $parti);
            }
        }

        if ($tipo === 'inizio_locazione') {
            $haPiano = DB::table('rate_quote')->whereIn('immobile_id', $immobileIds)->exists();
            if ($haPiano && $this->vociACaricoDellInquilino($condominio)->isNotEmpty()) {
                $motivi[] = 'un piano rate già generato ha voci a carico dell\'inquilino: il destinatario cambierebbe';
            }
        }

        return ['richiesto' => $motivi !== [], 'motivi' => $motivi, 'informazioni' => $informazioni, 'avvisi' => $avvisi];
    }

    // --- Forma ----------------------------------------------------------------------------------

    private function data(CarbonImmutable|\DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->locale('it')->translatedFormat('j F Y');
    }

    private function ruolo(string $tipologia): string
    {
        return mb_strtolower(RuoloAnagraficaImmobile::tryFrom($tipologia)?->label() ?? $tipologia);
    }

    private function quota(float|int|string $quota): string
    {
        $q = (float) $quota;

        return floor($q) == $q ? (string) (int) $q : rtrim(rtrim(number_format($q, 2, ',', ''), '0'), ',');
    }

    /**
     * I nomi delle voci, una volta sola: più voci con lo stesso nome (gli imprevisti di uno stesso fornitore) si contano,
     * «Imprevisto (3 voci)». Lo stesso fa il modulo (`resources/js/lib/gestionale/passaggi/vociDaSpostare.ts`).
     *
     * @param list<array{conto: string}> $voci
     */
    private function nomiVoci(array $voci): string
    {
        return $this->elenco(collect($voci)->countBy('conto')->map(fn (int $n, string $nome) => $n > 1 ? sprintf('%s (%d voci)', $nome, $n) : $nome)->values()->all());
    }

    private function elenco(array $voci): string
    {
        $voci = array_values(array_filter($voci));
        if (count($voci) <= 1) {
            return $voci[0] ?? '';
        }
        $ultimo = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultimo;
    }

    private function nomiOClausola(Collection $titolari, string $clausola): string
    {
        $nomi = $titolari->map(fn ($t) => $t->anagrafica?->nome)->filter()->values()->all();

        return $nomi === [] ? $clausola : $this->elenco($nomi);
    }
}
