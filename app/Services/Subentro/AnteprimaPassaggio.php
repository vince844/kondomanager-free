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
    /** I nudi proprietari in corso alla decorrenza dell'ultimo `calcola()` (S8-30). */
    private Collection $nudi;

    public function __construct(
        private readonly ConguaglioPassaggio $conguaglioPassaggio = new ConguaglioPassaggio(),
        private readonly FrasiObbligati $frasiObbligati = new FrasiObbligati(),
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
        $rateEmesse = $uscente
            ? $tutteLeEmesse->whereIn('anagrafica_id', $intestatari)->values()
            : collect();
        $altreEmesse = $uscente
            ? $tutteLeEmesse->whereNotIn('anagrafica_id', $intestatari)->values()
            : $tutteLeEmesse;
        $morosita = $uscente ? $this->morosita($immobile, $uscente->anagrafica_id) : null;

        // S5: il conguaglio vero (D9), un solo calcolo per il pannello e per la registrazione.
        $conguaglio = $this->conguaglio($tipo, $dati, $uscente, $entrante, $nudoProprietario, $immobileIds, $decorrenza);

        // Decisione 28.6 (rilievo B2 della Fase 1-bis): le unità del passaggio che dopo la riserva restano «miste».
        $miste = $this->riserva($tipo, $dati) && $uscente !== null ? $this->altriProprietariPieni($immobileIds, $uscente, $decorrenza) : [];

        return [
            'riferimento' => [
                'uscente_fino_al' => $uscente ? $giornoPrima->toDateString() : null,
                'entrante_dal' => $decorrenza->toDateString(),
                'frase' => $this->fraseRiferimento($tipo, $dati, $nomeUscente, $nomeEntrante, $giornoPrima, $decorrenza, $proprietari, $nudoProprietario),
            ],
            'anagrafica' => [
                'frasi' => $this->blocco1($tipo, $dati, $immobile, $nomeUscente, $nomeEntrante, $ruolo, $quota, $giornoPrima, $decorrenza, $proprietari, $nudoProprietario),
                'pertinenze' => $this->nomiPertinenze($immobile, $dati['pertinenze'] ?? []),
                'avvisi' => $this->avvisiUnitaMista($miste, $nomeUscente, $nomeEntrante, $decorrenza),
            ],
            'rate' => [
                'stato' => $conguaglio['stato'],
                'emesse' => $rateEmesse->map(fn ($r) => collect($r)->except('anagrafica_id')->all())->values()->all(),
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
            'cancello' => $this->cancello($tipo, $condominio, $immobileIds, $uscente, $tutteLeEmesse, $intestatari, $conguaglio['riassegnazione'] ?? [], $nomeEntrante, $conguaglio['quote'] ?? [], $this->riserva($tipo, $dati) ? $decorrenza : null, array_keys($miste)),
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
        $controparte = $estinzione ? $nudo?->anagrafica : $entrante;
        if ($controparte === null) {
            return $nessuno;
        }

        // Decisione 25 (B3a): nella vendita le bozze di chi esce, dalla decorrenza in poi, passano a chi entra.
        // R4 (Fase 1-bis, decisione del 26/09/2026): nella vendita della nuda proprietà le ordinarie di un piano generato
        // prima dell'usufrutto restano fuori — sono dell'usufruttuario (art. 1004 c.c.).
        // Riserva d'usufrutto (decisione 28): una vendita per le straordinarie, ma l'ordinaria resta fuori — la deve la stessa
        // persona prima e dopo, ora come usufruttuario (art. 1004 c.c.).
        $esito = $this->conguaglioPassaggio->calcola($uscente->anagrafica, $controparte, $immobileIds, $decorrenza, soloOrdinario: $tipo === 'usufrutto', riassegnaBozze: $tipo === 'vendita', nudaProprieta: $tipo === 'vendita' && $uscente->tipologia === 'nuda_proprietario', soloStraordinario: $this->riserva($tipo, $dati));
        if ($esito['stato'] === 'nessuna_rata') {
            return $nessuno;
        }

        // S8-30: con più nudi proprietari il debito di ogni coppia si divide fra loro per quota registrata (la
        // stessa chiave che il motore usa per i comproprietari), una coppia per nudo; `anagrafica_entrante_id`
        // sta dentro la coppia anche con un nudo solo, per uniformità (RegistraSubentroAction la legge da lì).
        $nudi = $estinzione ? $this->nudi : collect();
        if ($nudi->count() > 1) {
            $pesi = $nudi->mapWithKeys(fn (TitolaritaImmobile $t) => [(int) $t->anagrafica_id => (float) $t->quota])->all();
            $nomi = $nudi->mapWithKeys(fn (TitolaritaImmobile $t) => [(int) $t->anagrafica_id => $t->anagrafica?->nome])->all();
            $coppie = [];
            $righe = [];
            foreach ($esito['coppie'] as $c) {
                $parti = MoneyHelper::ripartisciPerQuote((int) $c['importo'], $pesi);
                foreach ($parti as $anagraficaId => $cents) {
                    if ((int) $cents === 0) {
                        continue;
                    }
                    $coppie[] = array_replace($c, ['anagrafica_entrante_id' => (int) $anagraficaId, 'entrante_nome' => $nomi[$anagraficaId] ?? '?', 'importo' => (int) $cents, 'importo_formattato' => MoneyHelper::format((int) $cents)]);
                }
                $righe[] = sprintf('Sulla gestione %s il debito di %s si divide per quota: %s.', $c['gestione'] ?? 'gestione', MoneyHelper::format((int) $c['importo']), implode(', ', array_map(fn ($id) => sprintf('%s a %s (%s %%)', MoneyHelper::format((int) $parti[$id]), $nomi[$id] ?? '?', rtrim(rtrim(number_format($pesi[$id], 2, ',', '.'), '0'), ',')), array_keys($parti))));
            }
            $esito['coppie'] = $coppie;
            array_push($esito['frasi'], ...$righe);
        } else {
            $esito['coppie'] = array_map(fn ($c) => $c + ['anagrafica_entrante_id' => $esito['anagrafica_entrante_id'], 'entrante_nome' => $controparte->nome], $esito['coppie']);
        }

        return $esito;
    }

    /** «Rossi Mario (60 %) e Neri Paolo (40 %)» con più nudi; il nome solo con uno; il ripiego senza nessuno. */
    private function elencoNudi(?TitolaritaImmobile $nudo, string $ripiego = 'il nudo proprietario'): string
    {
        if ($this->nudi->count() > 1) {
            return $this->elenco($this->nudi->map(fn (TitolaritaImmobile $t) => sprintf('%s (%s %%)', $t->anagrafica?->nome ?? '?', rtrim(rtrim(number_format((float) $t->quota, 2, ',', '.'), '0'), ',')))->all());
        }

        return $nudo?->anagrafica?->nome ?? $ripiego;
    }

    /** Il verbo al numero giusto: «torna proprietario pieno» / «tornano proprietari pieni». */
    private function tornaPieno(string $verbo = 'torna'): string
    {
        return $this->nudi->count() > 1 ? ($verbo === 'risulterà' ? 'risulteranno proprietari pieni' : 'tornano proprietari pieni') : ($verbo === 'risulterà' ? 'risulterà proprietario pieno' : 'torna proprietario pieno');
    }

    /** La vendita con riserva d'usufrutto (decisione 28), dal modulo: la dichiara l'amministratore. */
    private function riserva(string $tipo, array $dati): bool
    {
        return $tipo === 'vendita' && ($dati['sottotipo'] ?? null) === Subentro::RISERVA_USUFRUTTO;
    }

    /**
     * Decisione 28.6 (rilievo B2 della Fase 1-bis): le unità del passaggio su cui alla decorrenza resta un altro
     * proprietario pieno — riga «proprietario» in corso di un'altra persona, con una quota. Con la riserva sulla quota di
     * chi vende l'unità resta in parte in piena proprietà e in parte in nuda proprietà e usufrutto, e il motore la divide
     * male (limite S8-8, preesistente: ogni ruolo si normalizza sulle sue quote). Se l'altra quota è già nuda proprietà e
     * usufrutto, o se questa è la seconda riserva che rende l'unità coerente, non ce n'è nessuno.
     *
     * @return array<int, Collection<int, TitolaritaImmobile>> per unità, nell'ordine del passaggio (la principale prima)
     */
    private function altriProprietariPieni(array $immobileIds, TitolaritaImmobile $uscente, CarbonImmutable $decorrenza): array
    {
        $altri = TitolaritaImmobile::query()->with('anagrafica')
            ->whereIn('immobile_id', $immobileIds)
            ->where('tipologia', 'proprietario')
            ->where('anagrafica_id', '!=', (int) $uscente->anagrafica_id)
            ->where('quota', '>', 0)
            ->orderBy('id')
            ->get()
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza))
            ->groupBy('immobile_id');

        return collect($immobileIds)->filter(fn ($id) => $altri->has($id))->mapWithKeys(fn ($id) => [(int) $id => $altri[$id]->values()])->all();
    }

    /**
     * L'avviso dell'unità mista (decisione 28.6): avviso, non cancello — la riserva si registra. Dice solo ciò che i numeri
     * misurati confermano (test «i numeri su cui si regge l'avviso» in `RiservaUsufruttoTest`): nei piani generati o
     * ricalcolati dopo, le voci sul «Proprietario» non arrivano a chi compra e l'altro proprietario pieno paga anche la quota
     * venduta; la straordinaria deliberata dopo l'atto va tutta a lui; le voci sull'usufruttuario tutte a chi vende.
     *
     * @param array<int, Collection<int, TitolaritaImmobile>> $miste
     * @return list<string>
     */
    private function avvisiUnitaMista(array $miste, ?string $uscente, ?string $entrante, CarbonImmutable $dal): array
    {
        if ($miste === []) {
            return [];
        }
        $nomiUnita = Immobile::whereIn('id', array_keys($miste))->pluck('nome', 'id');
        $dalA = $this->data($dal);

        return array_values(array_map(function (int $id, Collection $altri) use ($nomiUnita, $uscente, $entrante, $dalA) {
            $uno = $altri->count() === 1;
            $nomi = $this->elenco($altri->map(fn (TitolaritaImmobile $t) => $t->anagrafica?->nome)->filter()->all());

            return sprintf(
                'Su %s %s anche %s: dopo il passaggio l\'unità è in parte in piena proprietà e in parte in nuda proprietà e usufrutto, e i piani generati o ricalcolati non la sanno dividere. Dal %s le voci sul «Proprietario» non vanno a %s, nudo proprietario, ma ai proprietari pieni, e %s %s anche la quota venduta, in tutto o in parte; una spesa straordinaria sul «Proprietario» deliberata dal %s in poi va per intero a %s; le voci sull\'«Usufruttuario», e quelle sull\'«Inquilino» se non c\'è un inquilino, vanno per intero a %s, che resta usufruttuario. Prima di generare o ricalcolare un piano su questa unità, controllane il riparto.',
                $nomiUnita[$id] ?? 'questa unità',
                $uno ? 'resta proprietario pieno' : 'restano proprietari pieni',
                $this->elenco($altri->map(fn (TitolaritaImmobile $t) => sprintf('%s (%s %%)', $t->anagrafica?->nome ?? '?', $this->quota($t->quota)))->all()),
                $dalA, $entrante ?? 'chi compra', $nomi, $uno ? 'paga' : 'pagano', $dalA, $nomi, $uscente ?? 'chi vende',
            );
        }, array_keys($miste), $miste));
    }

    /**
     * Decisione 28.5 (rilievo B1 della Fase 1-bis): i piani ancora ricalcolabili le cui righe di riparto danno a chi vende
     * voci **ordinarie** chieste al «Proprietario» (lo è anche una voce senza coefficienti), su una competenza che arriva
     * alla decorrenza: ricalcolati dopo la riserva, dal giorno dell'atto le darebbero al nudo proprietario. Un piano senza
     * righe di riparto (anteriore alla beta.29) non dice niente, e l'avviso non si scrive.
     *
     * @param list<int> $pianoIds
     * @return list<array{nome: string, conti: list<string>}>
     */
    private function vociOrdinarieAlNudo(array $pianoIds, int $anagraficaId, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        if ($pianoIds === [] || $immobileIds === []) {
            return [];
        }

        return DB::table('righe_riparto')
            ->join('piani_rate', 'piani_rate.id', '=', 'righe_riparto.piano_rate_id')
            ->join('gestioni', 'gestioni.id', '=', 'piani_rate.gestione_id')
            ->whereIn('righe_riparto.piano_rate_id', $pianoIds)
            ->where('righe_riparto.anagrafica_id', $anagraficaId)
            ->whereIn('righe_riparto.immobile_id', $immobileIds)
            ->where('righe_riparto.tipo', 'riparto')
            ->where('righe_riparto.ruolo_richiesto', 'proprietario')
            ->where(fn ($q) => $q->whereNull('righe_riparto.competenza_al')->orWhereDate('righe_riparto.competenza_al', '>=', $decorrenza->toDateString()))
            ->orderBy('piani_rate.id')->orderBy('righe_riparto.id')
            ->get(['piani_rate.id', 'piani_rate.nome', 'gestioni.tipo', 'righe_riparto.conto_nome'])
            ->filter(fn ($r) => NaturaGestione::daStringa($r->tipo) === NaturaGestione::Ordinaria)
            ->groupBy('id')
            ->map(fn (Collection $righe) => ['nome' => (string) $righe->first()->nome, 'conti' => $righe->pluck('conto_nome')->filter()->unique()->values()->all()])
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
            ->where('rate.stato', 'emessa')
            ->where('rate_quote.stato', '!=', 'annullata')
            ->orderBy('rate.data_scadenza')
            ->orderBy('rate.numero_rata')
            ->get([
                'rate.numero_rata', 'rate.data_scadenza', 'rate_quote.importo', 'rate_quote.importo_pagato',
                'rate_quote.anagrafica_id', 'anagrafiche.nome as intestatario', 'piani_rate.nome as piano', 'rate_quote.tipo',
            ])
            ->map(fn ($r) => [
                'anagrafica_id' => (int) $r->anagrafica_id,
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
            ->where('rate.stato', 'emessa')
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
        if ($tutte->isEmpty()) {
            return ['Nessuna rata emessa su questa unità. Non c\'è niente da conguagliare.'];
        }

        // Nell'usufrutto chi «entra» nel conguaglio non è sempre chi entra nel modulo: nell'estinzione nessuno
        // entra, è il nudo proprietario che torna pieno; nella costituzione chi esce resta come nudo proprietario.
        $estinzione = $tipo === 'usufrutto' && ($dati['sottotipo'] ?? 'costituzione') === 'estinzione';
        if ($estinzione) {
            $entrante = $this->elencoNudi($nudo);
        }

        $frasi = [];
        if ($tipo === 'inizio_locazione') {
            $frasi[] = sprintf('Le %d quote già emesse su questa unità non si toccano: restano intestate a %s. Dal %s le voci a carico dell\'inquilino verranno intestate a %s.', $tutte->count(), $this->elenco($tutte->pluck('intestatario')->unique()->values()->all()), $this->data($dal), $entrante ?? 'chi entra');
        } elseif ($tipo === 'fine_locazione') {
            if ($diChiEsce->isEmpty()) {
                $frasi[] = sprintf('Nessuna rata emessa è intestata a %s: non c\'è niente da conguagliare. Le altre quote dell\'unità restano a chi le ha ricevute.', $uscente);
            } elseif ($conguaglio['stato'] === 'calcolato') {
                $frasi[] = sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s e %s, che entra come inquilino, è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota è divisa in proporzione ai giorni.', $uscente, $entrante ?? 'chi entra');
                array_push($frasi, ...$frasiConguaglio);
            } else {
                $frasi[] = sprintf('Le rate già emesse non si toccano: le %d quote intestate a %s (%s) restano sue. Dalla prossima generazione le voci a carico dell\'inquilino tornano al proprietario.', $diChiEsce->count(), $uscente, MoneyHelper::format((int) $diChiEsce->sum('importo')));
            }
        } elseif ($diChiEsce->isEmpty()) {
            $frasi[] = sprintf('Nessuna rata emessa è intestata a %s: non c\'è niente da conguagliare fra chi esce e chi entra. Le %d quote dell\'unità restano a chi le ha ricevute (%s).', $uscente, $altre->count(), $this->elenco($altre->pluck('intestatario')->unique()->values()->all()));
        } elseif ($tipo === 'usufrutto') {
            $frasi[] = $estinzione
                ? sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s e %s, che %s, è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria divisa in proporzione ai giorni, la quota straordinaria resta al nudo proprietario (art. 63 disp. att. c.c.; artt. 1004-1005 c.c.).', $uscente, $entrante, $this->tornaPieno())
                : sprintf('Le rate già emesse non si toccano. Il conguaglio fra %s, che resta come nudo proprietario, e %s è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria divisa in proporzione ai giorni (dal %s all\'usufruttuario, art. 1004 c.c.), la quota straordinaria resta al nudo proprietario (art. 1005 c.c.).', $uscente, $entrante ?? 'chi entra', $this->data($dal));
            array_push($frasi, ...$frasiConguaglio);
            if ($altre->isNotEmpty()) {
                $frasi[] = sprintf('Le altre %d quote emesse su questa unità restano a %s: questo passaggio non le riguarda.', $altre->count(), $this->elenco($altre->pluck('intestatario')->unique()->values()->all()));
            }
        } elseif ($this->riserva($tipo, $dati)) {
            // Testi T5 della Fase 1-bis: la straordinaria segue la competenza — la delibera, o quella dichiarata sulla fattura
            // (decisione 26) —; l'art. 1005 c.c. dice di chi è dal giorno dell'atto, non quale data conta.
            $frasi[] = sprintf('Le rate già emesse non si toccano. %s resta usufruttuario e continua a dovere la quota ordinaria (art. 1004 c.c.): l\'ordinaria non si conguaglia. La quota straordinaria va a chi era titolare alla data della delibera o, se la fattura dichiara la competenza, si divide per giorni su quella; dal %s il titolare è %s, nudo proprietario (art. 1005 c.c.). Dove serve, il conguaglio è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano.', $uscente, $this->data($dal), $entrante ?? 'chi entra');
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
        return $this->frasiObbligati->frasi($tipo, $dati, $condominio, $uscente, $entrante, $dal, $proprietari, $this->nudi->count() > 1 ? $this->nudi->map(fn (TitolaritaImmobile $t) => ['nome' => $t->anagrafica?->nome, 'quota' => (float) $t->quota])->all() : $nudo?->anagrafica?->nome, registrato: false);
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
     * @return array{richiesto: bool, motivi: list<string>, informazioni: list<string>}
     */
    private function cancello(string $tipo, Condominio $condominio, array $immobileIds, ?TitolaritaImmobile $uscente, Collection $rateEmesse, array $intestatari = [], array $riassegnazione = [], ?string $entrante = null, array $quoteConguaglio = [], ?CarbonImmutable $decorrenzaRiserva = null, array $miste = []): array
    {
        $motivi = [];
        $informazioni = [];

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
                ->filter(fn ($q) => $q['esclusa'] || ($tipo === 'vendita' && $tuttaDiChiEsce($gruppiConguaglio[$q['piano_rate_id'] . '|' . $q['immobile_id'] . '|' . $q['intestatario_id']])))->count())
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
            $ferma = fn ($q) => $q['esclusa'] || ((int) $q['quota_pura'] === 0 && (int) $q['entrante'] === 0);
            $emesseDiPredecessori = collect($quoteConguaglio)->where('in_bozza', false)->filter(fn ($q) => ! empty($q['ereditata_da']));
            $escluse = $emesseDiPredecessori->filter($ferma);
            $restanti = $emesseAiPredecessori->count() - $escluse->count();
            if ($restanti > 0) {
                $diChi = $escluse->isEmpty() ? $emesseAiPredecessori->pluck('intestatario') : $emesseDiPredecessori->reject($ferma)->pluck('ereditata_da');
                // L1-7: vale per vendita, locazione e usufrutto, e per catene di qualunque lunghezza — non «ha acquistato».
                $motivi[] = sprintf('%d %s a %s, la cui competenza è passata a %s con un passaggio precedente: la parte che ne resta passa ancora', $restanti, $restanti === 1 ? 'quota di rata già emessa' : 'quote di rate già emesse', $diChi->unique()->implode(', '), $uscente->anagrafica?->nome);
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
                ->where('rate.stato', '!=', 'emessa')
                ->groupBy('rate.piano_rate_id', 'piani_rate.nome', 'rate_quote.anagrafica_id', 'anagrafiche.nome')
                ->get(['rate.piano_rate_id', 'piani_rate.nome', 'rate_quote.anagrafica_id', 'anagrafiche.nome as intestatario', DB::raw('COUNT(*) as n')])
            : collect();
        [$immutabili, $ricalcolabili] = $bozzePerPiano->partition(fn ($p) => DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $p->piano_rate_id)->whereNotNull('rate_quote.scrittura_contabile_id')->exists());
        // Le bozze di un piano ancora ricalcolabile contano solo se sono di chi esce: quelle di un predecessore non
        // entrano nel conguaglio e quel piano era già da ricalcolare prima di questo passaggio.
        $ricalcolabiliDiChiEsce = $uscente !== null ? $ricalcolabili->where('anagrafica_id', (int) $uscente->anagrafica_id) : collect();
        if ($ricalcolabiliDiChiEsce->isNotEmpty()) {
            // Decisione 28.5 (rilievo B1 della Fase 1-bis): nella riserva un piano ricalcolato dopo l'atto addebita secondo i
            // coefficienti, e le voci ordinarie sul «Proprietario» (anche quelle senza coefficienti) scendono dal giorno
            // dell'atto al nudo proprietario (`catenaRiparto`). Si dice quando le righe di riparto del piano lo mostrano, con la
            // via; su un'unità mista (decisione 28.6) il ricalcolo non arriva al nudo, e lo dice l'avviso del blocco 1. La via è
            // solo «Usufruttuario» (decisione 29.3): la catena usufruttuario → proprietario → nudo non arriva mai all'inquilino,
            // mentre con «Inquilino» su un'unità affittata l'inquilino pagherebbe anche le spese del locatore.
            $alNudo = $decorrenzaRiserva !== null
                ? $this->vociOrdinarieAlNudo($ricalcolabiliDiChiEsce->pluck('piano_rate_id')->all(), (int) $uscente->anagrafica_id, array_values(array_diff($immobileIds, $miste)), $decorrenzaRiserva)
                : [];
            foreach ($alNudo as $piano) {
                $motivi[] = sprintf('il piano «%s», non ancora emesso, intesta quote a %s: se lo ricalcoli, dal %s le voci sul «Proprietario» (%s) vanno a %s, nudo proprietario, perché il programma addebita secondo i coefficienti anche se fra le parti l\'ordinaria è dell\'usufruttuario (art. 1004 c.c.); se devono restare a %s, che resta usufruttuario, prima di ricalcolare metti quelle voci su «Usufruttuario», che dove non c\'è usufrutto le dà al proprietario e mai all\'inquilino; non su «Inquilino», che su un\'unità affittata le fa pagare all\'inquilino (guida «Ruoli e usufrutto»)', $piano['nome'], $uscente->anagrafica?->nome, $this->data($decorrenzaRiserva), implode(', ', $piano['conti']), $entrante ?? 'chi compra', $uscente->anagrafica?->nome);
            }
            if ($ricalcolabiliDiChiEsce->pluck('piano_rate_id')->unique()->count() > count($alNudo)) {
                $motivi[] = sprintf('un piano rate già generato intesta quote a %s: il destinatario cambierebbe', $uscente->anagrafica?->nome);
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
            ->whereIn('motivo_bozza', ['ordinaria_riservata', 'ordinaria_dell_usufruttuario', 'straordinaria_del_nudo', 'solo_pregresso', 'straordinaria_di_chi_esce', 'fattura_di_chi_esce'])
            ->countBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['intestatario_id'])->all();
        $nonRisoltePerPiano = collect($quoteConguaglio)->where('in_bozza', true)->where('motivo_bozza', 'non_risolta')
            ->countBy(fn ($q) => $q['piano_rate_id'] . '|' . $q['intestatario_id'])->all();
        foreach ($immutabili as $p) {
            $passano = $uscente !== null && (int) $p->anagrafica_id === (int) $uscente->anagrafica_id ? min((int) $p->n, $passanoPerPiano[(int) $p->piano_rate_id] ?? 0) : 0;
            $ferme = min((int) $p->n - $passano, $fermePerPiano[$p->piano_rate_id . '|' . $p->anagrafica_id] ?? 0);
            $nonRisolte = min((int) $p->n - $passano - $ferme, $nonRisoltePerPiano[$p->piano_rate_id . '|' . $p->anagrafica_id] ?? 0);
            $restano = (int) $p->n - $passano - $ferme - $nonRisolte;
            // Il numero davanti a ogni parte solo quando le parti sono più d'una.
            $conNumero = count(array_filter([$passano, $restano, $nonRisolte, $ferme])) > 1;
            $testa = sprintf('il piano «%s» ha %d %s non ancora %s intestat%s a %s: non si può più ricalcolare', $p->nome, $p->n, $p->n === 1 ? 'quota' : 'quote', $p->n === 1 ? 'emessa' : 'emesse', $p->n === 1 ? 'a' : 'e', $p->intestatario);
            $parti = array_filter([
                $passano > 0 ? sprintf('%s a %s (cambia l\'intestatario, non l\'importo)', $passano === (int) $p->n ? ($passano === 1 ? 'passa' : 'passano') : sprintf('%d %s', $passano, $passano === 1 ? 'passa' : 'passano'), $entrante ?? 'chi entra') : null,
                $restano > 0 ? ($conNumero
                    ? sprintf('%d %s %s compres%s nel conguaglio', $restano, $restano === 1 ? 'resta sua' : 'restano sue', $restano === 1 ? 'ed è' : 'e sono', $restano === 1 ? 'a' : 'e')
                    : sprintf('%s %s compres%s nel conguaglio', $restano === 1 ? 'resta sua' : 'restano sue', $restano === 1 ? 'ed è' : 'e sono', $restano === 1 ? 'a' : 'e')) : null,
                // Decisione 28.8 c: non «comprese nel conguaglio» — il conguaglio non ne propone (è la frase del blocco 2).
                $nonRisolte > 0 ? sprintf('%s%s, senza conguaglio: la competenza del piano non si può determinare', $conNumero ? $nonRisolte . ' ' : '', $nonRisolte === 1 ? 'resta sua' : 'restano sue') : null,
                $ferme > 0 ? sprintf('%s%s: il conguaglio non %s tocca', $conNumero ? $ferme . ' ' : '', $ferme === 1 ? 'resta sua' : 'restano sue', $ferme === 1 ? 'la' : 'le') : null,
            ]);
            // Decisione del 29/09 (28.7): un piano le cui bozze restano tutte a chi le ha, senza che nessuna parte cambi persona,
            // non chiede la spunta; basta una bozza che passa, che si conguaglia o la cui competenza non si sa, e la frase
            // intera resta fra i motivi.
            if ($passano === 0 && $restano === 0 && $nonRisolte === 0) {
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

        return ['richiesto' => $motivi !== [], 'motivi' => $motivi, 'informazioni' => $informazioni];
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
