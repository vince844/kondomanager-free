<?php

namespace App\Services\Subentro;

use App\Enums\NaturaGestione;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestionale\Subentro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decisioni 55 e 56 (1.11.0-beta.43): **dove è finita** ogni riga di riparto di una quota emessa, il giorno prima del passaggio.
 *
 * Fino alla .42 il conguaglio riconosceva la quota di una riga di riparto dal suo ruolo risolto. Nelle catene lo stesso ruolo
 * può indicare due quote diverse della stessa persona — la nuda proprietà avuta due volte, un ritorno, la metà ricomprata, la
 * riserva su un'unità mista — e il conguaglio sbagliava, o si fermava («le quote sono passate per più strade»). Dalla .43 ogni
 * riga di riparto porta la riga di titolarità da cui viene (`righe_riparto.anagrafica_immobile_id`, scritta dal motore); qui la
 * si segue in avanti, passaggio per passaggio, con le operazioni che ciascuno ha scritto nel suo registro.
 *
 * Come:
 * - si parte dalla riga di titolarità T della riga di riparto, com'era alla generazione del piano. Si seguono **solo i passaggi
 *   registrati dopo** che le righe di riparto del piano sono state scritte (il registro dice l'ultima quota che esisteva,
 *   `quota_max_id`): quelli di prima sono già nel riparto. Un passaggio senza registro (prima della .37) conta solo se cade
 *   nella competenza del piano;
 * - la riga viaggia in «pacchi», ciascuno con la sua frazione. Una vendita sposta la riga di chi esce su quella di chi entra, e
 *   le righe che chi entra somma con l'acquisto; la riserva d'usufrutto e la costituzione dividono la riga per natura e per
 *   lato della voce, come la scelta sull'ordinaria (`ConguaglioPassaggio::latiDellAnello`), e la parte che va all'usufrutto si
 *   ricorda la nuda proprietà a cui è legata (la «coppia»); l'estinzione rende ogni parte dell'usufrutto alla sua nuda
 *   proprietà — quella della coppia, seguita nelle vendite della nuda e nelle somme (`NudiDellEstinzione::seguiLaNuda`), e la
 *   parte censita ai nudi che nessuna parte lega, per quota;
 * - il giorno della decorrenza, con i passaggi dello stesso giorno già registrati, la parte che sta sulla riga di chi esce
 *   (U) **è arrivata**, dal giorno in cui ci è arrivata; le altre sono di altri: una riga che chi esce tiene (`tiene:`), una
 *   riga che chi esce ha già ceduto (`ceduta:`, anche quella di un predecessore arrivata a chi esce e poi ceduta, o
 *   `usufruttuario` per l'ordinaria finita a un usufruttuario, art. 1004 c.c.), una riga di un predecessore che non è mai
 *   arrivata a chi esce (`mai_arrivata:`). Se adesso si estingue l'usufrutto, la parte arrivata va al suo nudo con la stessa
 *   regola, e ne viene il peso per nudo.
 *
 * Dove il legame non arriva — la riga di riparto non dice da dove viene e non si deduce con certezza, la riga di titolarità
 * è cambiata senza un passaggio, un passaggio di prima della .37 tocca la quota, una parte dell'usufrutto non sa a quale nuda
 * tornare, un passaggio di prima della .43 l'ha fatta passare senza una coppia di conguaglio (decisione 60) — la riga è
 * **indecidibile**, con la frase che lo dice. Che cosa farne lo decide il conguaglio: dove la catena è
 * ambigua si ferma, altrove tiene le regole di prima. Fa eccezione la nuda nata da una somma dopo la generazione e tornata
 * piena solo in parte (decisione 63): lì le regole di prima sbagliano anche per chi esce, e la riga porta `ferma`, che ferma il
 * suo gruppo ovunque; il calcolo per pezzi, che la deciderebbe, è la Coda 225.
 *
 * I piani generati prima della .43 non hanno il legame: si deduce qui, al volo e senza scriverlo, solo dove la riga di
 * titolarità possibile è una sola (`legamiDedotti`).
 *
 * Le righe e i passaggi si leggono una volta per unità, non per riga di riparto.
 */
final class GenealogiaDellaQuota
{
    /** Le sigle dei passaggi di adesso. */
    public const VENDITA = 'vendita';
    public const NUDA = 'nuda';
    public const RISERVA = 'riserva';
    public const COSTITUZIONE = 'costituzione';
    public const ESTINZIONE = 'estinzione';
    public const FINE_LOCAZIONE = 'fine_locazione';
    /**
     * Decisione 65 (1.11.0-beta.44): la successione, che divide la quota del defunto sulle righe degli eredi. Lo stesso arco porta
     * l'estinzione con l'accrescimento, che divide l'usufrutto di chi muore sulle righe degli usufruttuari che restano.
     */
    public const SUCCESSIONE = 'successione';

    private const TOLLERANZA = 0.005;

    /** @var array<int, string> */
    private array $nomi = [];

    /** @var array<string, bool> passaggio|piano|unità → il passaggio ha preso il piano senza scrivere una coppia sull'unità */
    private array $senzaCoppia = [];

    /** @var array<string, mixed> le letture di `senzaCoppia()`, una volta per passaggio, piano e coppia (rilievo T17) */
    private array $letture = [];

    /** L'ultimo `muovi()` ha dato un indecidibile che ferma il gruppo anche fuori da una catena ambigua (rilievo H2). */
    private bool $ferma = false;

    /**
     * @param list<int> $immobileIds
     * @param array<int, int> $righeUscenti unità → la riga di chi esce (U)
     * @param array<int, list<array{id: int, anagrafica_id: int, quota: float}>> $nudiOra unità → i nudi che tornano pieni adesso (all'estinzione)
     * @param Collection<int, object> $righe le righe lorde di riparto da seguire (riparto e addebito diretto)
     * @param array<int, array{prima_quota: int, dal: string, natura: string}> $piani piano → la prima quota, l'inizio della competenza, la natura
     * @return array<int, array{parti: list<array{frazione: float, arrivo: ?string, esito: ?string, nudi: ?array<int, float>}>}|array{indecidibile: string, ferma?: bool}>
     */
    public function calcola(int $uscenteId, array $immobileIds, array $righeUscenti, CarbonImmutable $decorrenza, string $adesso, array $nudiOra, Collection $righe, array $piani): array
    {
        $esiti = [];
        foreach ($immobileIds as $immobileId) {
            $qui = $righe->filter(fn ($r) => (int) $r->immobile_id === (int) $immobileId);
            if ($qui->isEmpty()) {
                continue;
            }
            $u = $righeUscenti[(int) $immobileId] ?? null;
            if ($u === null) {
                continue;
            }
            $esiti += $this->perUnita((int) $immobileId, $uscenteId, (int) $u, $decorrenza, $adesso, $nudiOra[(int) $immobileId] ?? [], $qui, $piani);
        }

        return $esiti;
    }

    /** @return array<int, array<string, mixed>> */
    private function perUnita(int $immobileId, int $uscenteId, int $u, CarbonImmutable $decorrenza, string $adesso, array $nudiOra, Collection $righe, array $piani): array
    {
        $titolarita = DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)
            ->get(['id', 'anagrafica_id', 'tipologia', 'quota', 'attivo', 'data_inizio', 'data_fine', 'created_at'])->keyBy('id');
        $passaggi = Subentro::where('immobile_id', $immobileId)->whereDate('decorrenza', '<=', $decorrenza->toDateString())
            ->orderBy('decorrenza')->orderBy('id')
            ->get(['id', 'subentro_padre_id', 'tipo_passaggio', 'tipologia', 'decorrenza', 'riga_uscente_id', 'riga_entrante_id', 'anagrafica_uscente_id', 'anagrafica_entrante_id', 'registro'])
            ->values();
        $archi = $passaggi->map(fn (Subentro $s) => $this->arco($s, $titolarita))->all();
        [$coppie, $nudaDopo] = $this->struttura($archi, $titolarita);

        // I piani generati prima della .43 non hanno il legame: si deduce dove la riga possibile è una sola.
        $senza = $righe->filter(fn ($r) => $r->anagrafica_immobile_id === null);
        $dedotti = $senza->isEmpty() ? [] : self::legamiDedotti($righe, $titolarita, $archi, $piani);

        $esiti = [];
        foreach ($righe as $r) {
            $t = $r->anagrafica_immobile_id !== null ? (int) $r->anagrafica_immobile_id : ($dedotti[(int) $r->id] ?? null);
            $esiti[(int) $r->id] = $this->seguiLaRiga($r, $t, $uscenteId, $u, $decorrenza, $adesso, $nudiOra, $titolarita, $archi, $coppie, $nudaDopo, $piani[(int) $r->piano_rate_id] ?? null);
        }

        return $esiti;
    }

    /**
     * Che cosa ha fatto un passaggio alle righe dell'unità, dal suo registro: le righe chiuse, quelle aperte o cambiate (con la
     * persona e il ruolo di dopo), il «prima» delle righe toccate.
     *
     * @return array<string, mixed>
     */
    private function arco(Subentro $s, Collection $titolarita): array
    {
        $ops = $s->registro['righe'] ?? null;
        $tipo = match (true) {
            // L'estinzione con l'accrescimento sposta l'usufrutto sulle righe degli altri usufruttuari, per quota, come la successione
            // sposta la quota sulle righe degli eredi: la nuda resta nuda (1.11.0-beta.44).
            $s->conAccrescimento() => self::SUCCESSIONE,
            $s->estinzioneUsufrutto() => self::ESTINZIONE,
            $s->tipo_passaggio === 'usufrutto' => self::COSTITUZIONE,
            $s->riservaUsufrutto() => self::RISERVA,
            $s->tipo_passaggio === 'vendita' => self::VENDITA,
            $s->tipo_passaggio === 'fine_locazione' => self::FINE_LOCAZIONE,
            $s->successione() => self::SUCCESSIONE,
            default => 'altro',
        };
        $chiuse = [];
        $nuove = [];
        $prima = [];
        foreach (is_array($ops) ? $ops : [] as $op) {
            $id = (int) ($op['id'] ?? 0);
            match ($op['operazione'] ?? null) {
                'chiusa' => $chiuse[$id] = $op['prima']['data_fine'] ?? null,
                'aperta' => $nuove[$id] = ['anagrafica_id' => (int) ($op['dopo']['anagrafica_id'] ?? ($titolarita[$id]->anagrafica_id ?? 0)), 'tipologia' => (string) ($op['dopo']['tipologia'] ?? ($titolarita[$id]->tipologia ?? '')), 'quota' => (float) ($op['dopo']['quota'] ?? ($titolarita[$id]->quota ?? 0))],
                'modificata' => [$nuove[$id] = ['anagrafica_id' => (int) ($titolarita[$id]->anagrafica_id ?? 0), 'tipologia' => (string) ($op['dopo']['tipologia'] ?? ($titolarita[$id]->tipologia ?? '')), 'quota' => (float) ($op['dopo']['quota'] ?? ($titolarita[$id]->quota ?? 0)), 'modificata' => true], $prima[$id] = (array) ($op['prima'] ?? [])],
                default => null,
            };
        }

        return [
            'id' => (int) $s->id,
            'tipo' => $tipo,
            'giorno' => $s->decorrenza->toDateString(),
            'registro' => is_array($ops),
            // Decisione 60: registrato prima della .43 (il registro ha la versione 1 o 2), e il passaggio che ha le coppie (la
            // principale, per una pertinenza).
            'prima_43' => is_array($ops) && (int) ($s->registro['versione'] ?? 0) < 3,
            'padre' => (int) ($s->subentro_padre_id ?? $s->id),
            'quota_max_id' => isset($s->registro['quota_max_id']) ? (int) $s->registro['quota_max_id'] : null,
            'voce' => $s->ordinariaComeLaVoce(),
            'u' => $s->riga_uscente_id !== null ? (int) $s->riga_uscente_id : null,
            'e' => $s->riga_entrante_id !== null ? (int) $s->riga_entrante_id : null,
            'chi_esce' => $s->anagrafica_uscente_id !== null ? (int) $s->anagrafica_uscente_id : null,
            'chiuse' => $chiuse,
            'nuove' => $nuove,
            'prima' => $prima,
            // Decisione 65: gli eredi con la quota ereditata e la riga aperta, dal registro (non dalla riga, che può essere sommata); con
            // l'accrescimento gli usufruttuari che ricevono.
            'eredi' => $s->destinatari(),
            'accrescimento' => $s->conAccrescimento(),
        ];
    }

    /** La riga aperta o cambiata dal passaggio per una persona e un ruolo. */
    private static function nuovaDi(array $arco, int $anagraficaId, string $tipologia): ?int
    {
        foreach ($arco['nuove'] as $id => $n) {
            if ($n['anagrafica_id'] === $anagraficaId && $n['tipologia'] === $tipologia) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * La struttura degli usufrutti nati dai passaggi, su tutta la storia dell'unità (anche prima della generazione): le parti
     * legate a una nuda proprietà (`coppie`: usufrutto → [nuda, quota]) e, per seguire una nuda, la riga in cui un passaggio
     * l'ha portata (`nudaDopo`: nuda → [riga, posizione del passaggio]) — la vendita della nuda, e la somma con altra nuda.
     *
     * @return array{0: array<int, list<array{nuda: int, quota: float}>>, 1: array<int, array{0: int, 1: int}>}
     */
    private function struttura(array $archi, Collection $titolarita): array
    {
        $coppie = [];
        $nudaDopo = [];
        foreach ($archi as $k => $a) {
            if (! $a['registro']) {
                // Prima della .37 solo la vendita si ricostruisce: la riga di chi vende passa a quella di chi compra.
                if (in_array($a['tipo'], [self::VENDITA], true) && $a['u'] !== null && $a['e'] !== null && ($titolarita[$a['u']]->tipologia ?? null) === 'nuda_proprietario') {
                    $nudaDopo[$a['u']] ??= [$a['e'], $k];
                }
                continue;
            }
            if ($a['tipo'] === self::RISERVA && $a['u'] !== null && $a['e'] !== null && $a['chi_esce'] !== null) {
                $v = self::nuovaDi($a, $a['chi_esce'], 'usufruttuario');
                if ($v !== null) {
                    // L'usufrutto di chi vende somma quello che aveva già (la parte censita, o un'altra riserva) e la parte nuova.
                    $parti = isset($a['nuove'][$v]['modificata']) ? ($coppie[$v] ?? []) : [];
                    foreach (array_keys($a['chiuse']) as $c) {
                        if ($c !== $a['u'] && (int) ($titolarita[$c]->anagrafica_id ?? 0) === $a['chi_esce'] && ($titolarita[$c]->tipologia ?? null) === 'usufruttuario') {
                            array_push($parti, ...($coppie[$c] ?? []));
                        }
                    }
                    $parti[] = ['nuda' => $a['e'], 'quota' => (float) ($titolarita[$a['u']]->quota ?? 0)];
                    $coppie[$v] = $parti;
                }
            }
            if ($a['tipo'] === self::COSTITUZIONE && $a['u'] !== null && $a['e'] !== null && $a['chi_esce'] !== null) {
                $w = self::nuovaDi($a, $a['chi_esce'], 'nuda_proprietario');
                if ($w !== null) {
                    $coppie[$a['e']] = [['nuda' => $w, 'quota' => (float) ($titolarita[$a['u']]->quota ?? 0)]];
                }
            }
            // La nuda che passa: venduta (la riga di chi vende va su quella di chi compra), o sommata con altra nuda della stessa
            // persona (chi compra che era già nudo, la nuda che resta dopo un consolidamento).
            foreach (array_keys($a['chiuse']) as $c) {
                if (($titolarita[$c]->tipologia ?? null) !== 'nuda_proprietario') {
                    continue;
                }
                $dopo = in_array($a['tipo'], [self::VENDITA], true) && $c === $a['u'] ? $a['e']
                    : self::nuovaDi($a, (int) ($titolarita[$c]->anagrafica_id ?? 0), 'nuda_proprietario');
                if ($dopo !== null && $dopo !== $c) {
                    $nudaDopo[$c] ??= [$dopo, $k];
                }
            }
        }

        return [$coppie, $nudaDopo];
    }

    /**
     * Rilievo G2: la riga di nuda `$x` è nata dalla somma di nude di origine diversa prima del passaggio in posizione `$fino`: ci
     * arrivano due o più nude, o una nuda sola che vale meno della riga (il resto è venuto da una riserva o da una costituzione).
     * Contano solo i passaggi registrati dopo la generazione del piano (`$dopo`): una somma fatta prima il riparto la contiene già,
     * perché la riga è nata sulla nuda sommata intera (rilievo H1 del terzo giro). Una nuda arrivata intera da una vendita si
     * risale, perché la somma può essere avvenuta prima di quella vendita (rilievo H2). Conta anche la somma sul posto: una nuda
     * nata lo stesso giorno che un passaggio dopo la generazione fa salire, senza frecce (rilievo K1 del quarto giro).
     *
     * @param array<int, array<string, mixed>> $dopo posizione → arco, i passaggi registrati dopo la generazione del piano
     * @return array{riga: int, giorno: string}|null la riga in cui le nude si sono sommate e il giorno; null se nessuna somma
     */
    private static function nudaSommata(int $x, Collection $titolarita, array $nudaDopo, array $dopo, int $fino, array $visti = []): ?array
    {
        if (isset($visti[$x])) {
            return null;
        }
        $visti[$x] = true;
        foreach ($dopo as $k => $a) {
            if ($k < $fino && isset($a['prima'][$x]['quota']) && ($a['nuove'][$x]['tipologia'] ?? '') === 'nuda_proprietario'
                && (float) $a['nuove'][$x]['quota'] > (float) $a['prima'][$x]['quota'] + self::TOLLERANZA) {
                return ['riga' => $x, 'giorno' => (string) $a['giorno']];
            }
        }
        $arrivate = array_filter($nudaDopo, fn ($d) => (int) $d[0] === $x && $d[1] < $fino && isset($dopo[$d[1]]));
        if ($arrivate === []) {
            return null;
        }
        $ultima = max(array_column($arrivate, 1));
        $c = (int) array_key_first($arrivate);
        if (count($arrivate) > 1 || (float) ($titolarita[$x]->quota ?? 0) > (float) ($titolarita[$c]->quota ?? 0) + self::TOLLERANZA) {
            return ['riga' => $x, 'giorno' => (string) $dopo[$ultima]['giorno']];
        }

        return self::nudaSommata($c, $titolarita, $nudaDopo, $dopo, (int) $nudaDopo[$c][1], $visti);
    }

    /** La nuda in cui è finita `$nuda`, seguendo i passaggi fino a quello in posizione `$fino` escluso. */
    private static function nudaCorrente(int $nuda, array $nudaDopo, int $fino): int
    {
        return NudiDellEstinzione::seguiLaNuda($nuda, fn (int $id): ?int => isset($nudaDopo[$id]) && $nudaDopo[$id][1] < $fino ? (int) $nudaDopo[$id][0] : null);
    }

    /**
     * Lo stato di una riga di titolarità alla generazione del piano: si tolgono le modifiche dei passaggi registrati dopo,
     * dal primo (il «prima» del primo passaggio che l'ha toccata).
     *
     * @return array{tipologia: string, quota: float, data_fine: ?string}
     */
    private static function allaGenerazione(int $id, Collection $titolarita, array $dopoLaGenerazione): array
    {
        $t = $titolarita[$id];
        $stato = ['tipologia' => (string) $t->tipologia, 'quota' => (float) $t->quota, 'data_fine' => $t->data_fine === null ? null : substr((string) $t->data_fine, 0, 10)];
        $tipologia = $quota = $fine = false;
        foreach ($dopoLaGenerazione as $a) {
            if (! $a['registro']) {
                if ($a['u'] === $id && ! $fine) {
                    $stato['data_fine'] = null;
                    $fine = true;
                }
                continue;
            }
            if (array_key_exists($id, $a['chiuse']) && ! $fine) {
                $stato['data_fine'] = $a['chiuse'][$id];
                $fine = true;
            }
            if (isset($a['prima'][$id])) {
                if (isset($a['prima'][$id]['tipologia']) && ! $tipologia) {
                    $stato['tipologia'] = (string) $a['prima'][$id]['tipologia'];
                    $tipologia = true;
                }
                if (isset($a['prima'][$id]['quota']) && ! $quota) {
                    $stato['quota'] = (float) $a['prima'][$id]['quota'];
                    $quota = true;
                }
            }
        }

        return $stato;
    }

    /**
     * I passaggi registrati dopo che il piano è stato generato: il registro dice l'ultima quota che esisteva. Senza (prima della
     * .37) non si sa, e conta il passaggio che cade nella competenza del piano.
     *
     * @return array<int, array<string, mixed>> posizione → arco
     */
    private static function dopoLaGenerazione(array $archi, ?array $piano): array
    {
        $dopo = [];
        foreach ($archi as $k => $a) {
            $si = $a['quota_max_id'] !== null
                ? $piano !== null && $a['quota_max_id'] >= (int) $piano['prima_quota']
                : $piano === null || $a['giorno'] >= (string) $piano['dal'];
            if ($si) {
                $dopo[$k] = $a;
            }
        }

        return $dopo;
    }

    /**
     * Segue una riga di riparto fino al giorno della decorrenza, con i passaggi dello stesso giorno già registrati.
     *
     * @return array{parti: list<array<string, mixed>>}|array{indecidibile: string, ferma?: bool}
     */
    private function seguiLaRiga(object $r, ?int $t, int $uscenteId, int $u, CarbonImmutable $decorrenza, string $adesso, array $nudiOra, Collection $titolarita, array $archi, array $coppie, array $nudaDopo, ?array $piano): array
    {
        $giorno = $decorrenza->toDateString();
        // Una riga che non ha giorni dalla decorrenza in poi non passa comunque: niente da decidere. Né il suo tratto né la sua
        // competenza congelata (la straordinaria alla data della delibera, la fattura con la competenza dichiarata) arrivano al giorno.
        if (($r->titolarita_al !== null && substr((string) $r->titolarita_al, 0, 10) < $giorno)
            || (($r->competenza_al ?? null) !== null && substr((string) $r->competenza_al, 0, 10) < $giorno)) {
            return ['parti' => [['frazione' => 1.0, 'arrivo' => null, 'esito' => null, 'nudi' => null]]];
        }
        if ($t === null || ! isset($titolarita[$t])) {
            return ['indecidibile' => sprintf('il dettaglio del riparto non dice da quale riga di titolarità viene la quota di %s, e non si deduce con certezza', $this->nome((int) $r->anagrafica_id))];
        }
        $dopo = self::dopoLaGenerazione($archi, $piano);
        $allora = self::allaGenerazione($t, $titolarita, $dopo);
        if ((int) $titolarita[$t]->anagrafica_id !== (int) $r->anagrafica_id || $allora['tipologia'] !== (string) $r->ruolo_risolto
            || ($r->quota_possesso !== null && abs($allora['quota'] - (float) $r->quota_possesso) > self::TOLLERANZA)) {
            return ['indecidibile' => sprintf('la riga di titolarità di %s da cui viene la quota (%s) è cambiata dopo la generazione del piano, e nessun passaggio registrato dice come',
                $this->nome((int) $r->anagrafica_id), $this->ruolo((string) $r->ruolo_risolto))];
        }
        $straordinaria = ($piano['natura'] ?? null) === NaturaGestione::Straordinaria->value;
        $latoP = ($r->ruolo_richiesto ?? null) === 'proprietario' || ($r->tipo ?? null) === RigaRiparto::TIPO_AD_PERSONAM;

        // I pacchi di partenza. Una riga d'usufrutto nata da passaggi porta le sue parti legate a una nuda: si divide subito.
        $pacchi = [];
        $legate = 0.0;
        foreach ($coppie[$t] ?? [] as $c) {
            $f = $allora['quota'] > 0 ? $c['quota'] / $allora['quota'] : 0.0;
            $pacchi[] = ['riga' => $t, 'f' => $f, 'coppia' => $c['nuda'], 'arrivo' => null, 'da_chi_esce' => (int) $titolarita[$t]->anagrafica_id === $uscenteId];
            $legate += $f;
        }
        if ($legate < 1.0 - 0.0001) {
            $pacchi[] = ['riga' => $t, 'f' => 1.0 - $legate, 'coppia' => null, 'arrivo' => null, 'da_chi_esce' => (int) $titolarita[$t]->anagrafica_id === $uscenteId];
        }

        $chiusePerPassaggio = [];
        foreach ($dopo as $k => $a) {
            if (! $a['registro']) {
                // Prima della .37: si ricostruisce solo la vendita (e la fine della locazione), dalla riga di chi esce a quella di
                // chi entra. Ogni altro passaggio che tocca la quota non si ricostruisce, e nemmeno le righe che ha chiuso il giorno
                // prima (la riga che chi entra somma, il nudo che torna pieno).
                $vigilia = CarbonImmutable::parse($a['giorno'])->subDay()->toDateString();
                foreach ($pacchi as $i => $p) {
                    $diChiEsce = $a['u'] !== null && $p['riga'] === $a['u'];
                    $chiusaQuelGiorno = ($titolarita[$p['riga']]->data_fine ?? null) !== null && substr((string) $titolarita[$p['riga']]->data_fine, 0, 10) === $vigilia;
                    if (($diChiEsce && (! in_array($a['tipo'], [self::VENDITA, self::FINE_LOCAZIONE], true) || $a['e'] === null)) || (! $diChiEsce && $chiusaQuelGiorno)) {
                        return ['indecidibile' => sprintf('la quota è passata con il passaggio del %s, registrato da una versione che non annotava le righe toccate (prima della 1.11.0-beta.37)', $this->data($a['giorno']))];
                    }
                    if ($diChiEsce) {
                        $pacchi[$i] = $this->sposta($p, $a['e'], $a['giorno'], $titolarita);
                    }
                }
                if ($a['u'] !== null) {
                    $chiusePerPassaggio[$a['u']] = true;
                }
                continue;
            }
            $nuovi = [];
            foreach ($pacchi as $p) {
                $this->ferma = false;
                $mossi = $this->muovi($p, $a, $k, $straordinaria, $latoP, $titolarita, $coppie, $nudaDopo, $dopo);
                if (is_string($mossi)) {
                    // Rilievo H2 del terzo giro e decisione 63: dove la genealogia sa che non lo sa (la nuda sommata), il gruppo si ferma
                    // anche fuori da una catena ambigua, e anche per la riga di chi esce: le regole del ruolo lì sbagliano quando torna
                    // piena la parte dell'altro usufrutto (rilievo K2 del quarto giro).
                    return ['indecidibile' => $mossi] + ($this->ferma ? ['ferma' => true] : []);
                }
                // Decisione 60 (1.11.0-beta.43): un passaggio della .41 o della .42 che ha preso il piano nel conguaglio e non ha scritto
                // nessuna coppia su questa unità. Lì, su una catena ambigua, il conguaglio si fermava per tutta l'unità e la parte che
                // passava si regolava a mano: se e come, il programma non lo sa. La quota che lo attraversa cambiando persona non si
                // segue; con una coppia scritta sull'unità quel passaggio non si era fermato, e la si segue.
                if ($a['prima_43'] && $this->cambiaPersona($p, $mossi, $titolarita) && $this->senzaCoppia($a, (int) $r->piano_rate_id, (int) $r->immobile_id)) {
                    return ['indecidibile' => sprintf('la quota è passata con il passaggio del %s, registrato prima della 1.11.0-beta.43 senza una coppia di conguaglio su questa unità: non si sa se la parte di quel tratto è stata regolata a mano', $this->data($a['giorno']))];
                }
                // Un pacco passato per una riga di chi esce lo ricorda: se poi va a un altro, chi esce l'ha ceduto (rilievo T6).
                foreach ($mossi as $i => $m) {
                    $mossi[$i]['da_chi_esce'] = ! empty($m['da_chi_esce']) || (int) ($titolarita[$m['riga']]->anagrafica_id ?? 0) === $uscenteId;
                }
                array_push($nuovi, ...$mossi);
            }
            $pacchi = $nuovi;
            foreach (array_keys($a['chiuse']) as $c) {
                $chiusePerPassaggio[$c] = true;
            }
        }

        // Il giorno della decorrenza, prima di questo passaggio: dove sta ogni pacco.
        $parti = [];
        foreach ($pacchi as $p) {
            $x = $titolarita[$p['riga']] ?? null;
            if ($x === null) {
                return ['indecidibile' => sprintf('la riga di titolarità a cui è arrivata la quota di %s non c\'è più', $this->nome((int) $r->anagrafica_id))];
            }
            if ($p['riga'] === $u) {
                $nudi = null;
                if ($adesso === self::ESTINZIONE) {
                    $nudi = $this->aiNudi($p, $u, $nudiOra, $titolarita, $coppie, $nudaDopo, PHP_INT_MAX);
                    if (is_string($nudi)) {
                        return ['indecidibile' => sprintf($nudi, $this->data($giorno))];
                    }
                    $nudi = array_map(fn (array $n) => $n[1], $nudi);
                    $perPersona = [];
                    foreach ($nudi as $riga => $f) {
                        $chi = (int) collect($nudiOra)->firstWhere('id', $riga)['anagrafica_id'];
                        $perPersona[$chi] = ($perPersona[$chi] ?? 0.0) + $f;
                    }
                    $nudi = $perPersona;
                }
                $parti[] = ['frazione' => $p['f'], 'arrivo' => $p['arrivo'], 'esito' => null, 'nudi' => $nudi];
                continue;
            }
            // Il giorno della decorrenza, non quello prima (rilievo A3 della Fase 1-bis della .43): le righe aperte e chiuse dai
            // passaggi dello stesso giorno già registrati contano nell'ordine di registrazione (decisione 59, U3), come per chi esce.
            $inCorso = (bool) $x->attivo && ($x->data_inizio === null || substr((string) $x->data_inizio, 0, 10) <= $giorno) && ($x->data_fine === null || substr((string) $x->data_fine, 0, 10) >= $giorno);
            if (! $inCorso && ! isset($chiusePerPassaggio[$p['riga']])) {
                return ['indecidibile' => sprintf('la riga di titolarità di %s (%s) risulta chiusa il %s, e nessun passaggio registrato dopo la generazione del piano dice a chi è passata',
                    $this->nome((int) $x->anagrafica_id), $this->ruolo((string) $x->tipologia), $this->data($x->data_fine === null ? null : substr((string) $x->data_fine, 0, 10)))];
            }
            $esito = match (true) {
                (int) $x->anagrafica_id === $uscenteId => ($inCorso ? 'tiene:' . $x->tipologia : 'ceduta:' . $r->ruolo_risolto),
                // La quota di un predecessore arrivata a chi esce e già ceduta da chi esce a un altro: non è «mai arrivata» (T6).
                (int) $r->anagrafica_id !== $uscenteId && ! empty($p['da_chi_esce']) => ($adesso === self::NUDA && ! $straordinaria && $x->tipologia === 'usufruttuario' ? 'usufruttuario' : 'ceduta:' . $r->ruolo_risolto),
                // La parte dell'usufrutto di un predecessore che un'estinzione ha dato a un altro nudo (la frase di sempre).
                (int) $r->anagrafica_id !== $uscenteId && ! empty($p['divisa']) => 'mai_arrivata:' . ($p['divisa_come'] ?? 'altri_nudi'),
                (int) $r->anagrafica_id !== $uscenteId => 'mai_arrivata:' . $r->ruolo_risolto,
                // L'ordinaria di chi vende la nuda, finita all'usufruttuario: la frase dell'art. 1004 c.c.
                $adesso === self::NUDA && ! $straordinaria && $x->tipologia === 'usufruttuario' => 'usufruttuario',
                default => 'ceduta:' . $r->ruolo_risolto,
            };
            $parti[] = ['frazione' => $p['f'], 'arrivo' => null, 'esito' => $esito, 'nudi' => null];
        }

        return ['parti' => self::unisci($parti)];
    }

    /** Le parti uguali (stesso giorno d'arrivo, stesso esito) diventano una: le frazioni si sommano, i pesi dei nudi anche. */
    private static function unisci(array $parti): array
    {
        $unite = [];
        foreach ($parti as $p) {
            $k = ($p['arrivo'] ?? '') . '|' . ($p['esito'] ?? '');
            if (! isset($unite[$k])) {
                $unite[$k] = ['frazione' => 0.0, 'arrivo' => $p['arrivo'], 'esito' => $p['esito'], 'nudi' => $p['nudi'] === null ? null : []];
            }
            $unite[$k]['frazione'] += $p['frazione'];
            foreach ($p['nudi'] ?? [] as $chi => $f) {
                $unite[$k]['nudi'][$chi] = ($unite[$k]['nudi'][$chi] ?? 0.0) + $f * $p['frazione'];
            }
        }
        // I pesi dei nudi, dentro la parte, come frazioni della parte.
        foreach ($unite as $k => $p) {
            if ($p['nudi'] !== null && $p['frazione'] > 0) {
                $unite[$k]['nudi'] = array_map(fn ($f) => $f / $p['frazione'], $p['nudi']);
            }
        }
        // Prima le parti arrivate a chi esce: ciascuna si arrotonda per sé, e il resto della riga va a quelle di un'altra quota,
        // come faceva la parte arrivata con un'estinzione a più nudi.
        uasort($unite, fn ($a, $b) => [($a['esito'] ?? null) !== null, (string) ($a['arrivo'] ?? '')] <=> [($b['esito'] ?? null) !== null, (string) ($b['arrivo'] ?? '')]);

        return array_values(array_filter($unite, fn ($p) => $p['frazione'] > 0.0000001));
    }

    /** Un passaggio ha portato almeno una parte del pacco a un'altra persona. */
    private static function cambiaPersona(array $p, array $mossi, Collection $titolarita): bool
    {
        $prima = (int) ($titolarita[$p['riga']]->anagrafica_id ?? 0);
        foreach ($mossi as $m) {
            if ((int) ($titolarita[$m['riga']]->anagrafica_id ?? 0) !== $prima) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decisione 60: il passaggio ha preso il piano nel suo conguaglio (`PianoRate::presoNelConguaglioDa`, dal registro dalla .42,
     * con la regola di allora per la .41) e non ha scritto nessuna coppia sull'unità. Si legge solo per i passaggi di prima della
     * .43 che spostano una quota, una volta per passaggio, piano e unità.
     */
    private function senzaCoppia(array $a, int $pianoId, int $immobileId): bool
    {
        $chiave = $a['id'] . '|' . $pianoId . '|' . $immobileId;
        if (! array_key_exists($chiave, $this->senzaCoppia)) {
            $passaggio = $this->letture['passaggio:' . $a['id']] ??= Subentro::find($a['id']);
            $piano = $this->letture['piano:' . $pianoId] ??= PianoRate::find($pianoId);
            $conCoppia = $this->letture['coppia:' . $a['padre'] . '|' . $immobileId]
                ??= DB::table('saldi')->where('subentro_id', $a['padre'])->where('immobile_id', $immobileId)->exists();
            $this->senzaCoppia[$chiave] = $passaggio !== null && $piano !== null && ! $conCoppia && $piano->presoNelConguaglioDa($passaggio);
        }

        return $this->senzaCoppia[$chiave];
    }

    /**
     * Giro sulle correzioni della Fase 1-bis della .44 (GC6): perché una parte del pacco non è arrivata a chi esce. Il motivo giusto è
     * quello della divisione in cui la parte si è staccata dalla linea di chi esce, e camminando in avanti non si sa quale sia: con
     * divisioni di tipo diverso nella storia del pacco, la frase che le comprende.
     */
    private static function divisaCome(?string $prima, string $adesso): string
    {
        return $prima === null || $prima === $adesso ? $adesso : 'altri_titolari';
    }

    /** Il pacco su un'altra riga: se cambia la persona, da quel giorno è arrivato a lei. */
    private function sposta(array $p, int $riga, string $giorno, Collection $titolarita, float $f = 1.0, ?int $coppia = -1): array
    {
        $prima = (int) ($titolarita[$p['riga']]->anagrafica_id ?? 0);
        $dopo = (int) ($titolarita[$riga]->anagrafica_id ?? 0);

        return ['riga' => $riga, 'f' => $p['f'] * $f, 'coppia' => $coppia === -1 ? $p['coppia'] : $coppia, 'arrivo' => $prima === $dopo ? $p['arrivo'] : $giorno, 'divisa' => $p['divisa'] ?? false,
            'da_chi_esce' => $p['da_chi_esce'] ?? false, 'divisa_come' => $p['divisa_come'] ?? null];
    }

    /**
     * Un passaggio sposta un pacco: dove va, con quale frazione e quale coppia. Una stringa se non si sa.
     *
     * @return list<array<string, mixed>>|string
     */
    private function muovi(array $p, array $a, int $k, bool $straordinaria, bool $latoP, Collection $titolarita, array $coppie, array $nudaDopo, array $dopo): array|string
    {
        $x = $p['riga'];
        $chi = fn (int $id) => (int) ($titolarita[$id]->anagrafica_id ?? 0);
        $ruolo = fn (int $id) => (string) ($titolarita[$id]->tipologia ?? '');
        $chiusa = array_key_exists($x, $a['chiuse']);

        switch ($a['tipo']) {
            case self::VENDITA:
            case self::FINE_LOCAZIONE:
                if ($x === $a['u']) {
                    return $a['e'] !== null ? [$this->sposta($p, $a['e'], $a['giorno'], $titolarita)] : [$p];
                }
                // Chi entra che era già titolare con lo stesso ruolo somma la sua riga in quella nuova.
                if ($chiusa && $a['e'] !== null && $chi($x) === $chi($a['e']) && $ruolo($x) === $ruolo($a['e'])) {
                    return [$this->sposta($p, $a['e'], $a['giorno'], $titolarita)];
                }

                return [$p];

            case self::RISERVA:
                $v = $a['chi_esce'] !== null ? self::nuovaDi($a, $a['chi_esce'], 'usufruttuario') : null;
                if ($x === $a['u']) {
                    // La straordinaria passa al nudo proprietario; l'ordinaria resta a chi vende, usufruttuario (art. 1004 c.c.), salvo
                    // «come la voce», che fa passare le voci sul «Proprietario».
                    if ($straordinaria || ($a['voce'] && $latoP)) {
                        return $a['e'] !== null ? [$this->sposta($p, $a['e'], $a['giorno'], $titolarita)] : 'la riserva d\'usufrutto del ' . $this->data($a['giorno']) . ' non dice chi ha comprato la nuda proprietà';
                    }

                    return $v !== null ? [$this->sposta($p, $v, $a['giorno'], $titolarita, 1.0, $a['e'])] : 'la riserva d\'usufrutto del ' . $this->data($a['giorno']) . ' non dice quale riga d\'usufrutto ha aperto';
                }
                if ($chiusa && $v !== null && $chi($x) === $a['chi_esce'] && $ruolo($x) === 'usufruttuario') {
                    return [$this->sposta($p, $v, $a['giorno'], $titolarita)];
                }
                if ($chiusa && $a['e'] !== null && $chi($x) === $chi($a['e']) && $ruolo($x) === 'nuda_proprietario') {
                    return [$this->sposta($p, $a['e'], $a['giorno'], $titolarita)];
                }

                return [$p];

            case self::COSTITUZIONE:
                $w = $a['chi_esce'] !== null ? self::nuovaDi($a, $a['chi_esce'], 'nuda_proprietario') : null;
                if ($x === $a['u']) {
                    if ($w === null) {
                        return 'la costituzione dell\'usufrutto del ' . $this->data($a['giorno']) . ' non dice quale riga di nuda proprietà ha aperto';
                    }
                    // La straordinaria resta al nudo proprietario (art. 1005 c.c.); l'ordinaria va all'usufruttuario, salvo «come la
                    // voce», che lascia al nudo le voci sul «Proprietario».
                    if ($straordinaria || ($a['voce'] && $latoP)) {
                        return [$this->sposta($p, $w, $a['giorno'], $titolarita)];
                    }

                    return $a['e'] !== null ? [$this->sposta($p, $a['e'], $a['giorno'], $titolarita, 1.0, $w)] : 'la costituzione dell\'usufrutto del ' . $this->data($a['giorno']) . ' non dice chi è l\'usufruttuario';
                }
                if ($chiusa && $w !== null && $chi($x) === $a['chi_esce'] && $ruolo($x) === 'nuda_proprietario') {
                    return [$this->sposta($p, $w, $a['giorno'], $titolarita)];
                }

                return [$p];

            case self::ESTINZIONE:
                $nudi = $this->nudiDellArco($a, $titolarita);
                if ($x === $a['u']) {
                    // Prima senza la nuda di chi esce, poi con: un'estinzione registrata dalla .42 poteva fare chi esce pieno della nuda
                    // di un'altra parte, ancora in usufrutto (il difetto D2), e lì la parte va ai nudi che la .42 avrebbe dato senza di
                    // lui. Dalla .43 la nuda di chi esce torna piena solo senza un altro usufrutto, e la seconda prova la trova.
                    $elenco = fn (bool $conChiEsce) => array_values(array_map(fn ($id) => ['id' => $id, 'anagrafica_id' => $nudi[$id]['anagrafica_id'], 'quota' => $nudi[$id]['quota']],
                        array_filter(array_keys($nudi), fn ($id) => $conChiEsce || ! $nudi[$id]['di_chi_esce'])));
                    $verso = $this->aiNudi($p, $x, $elenco(false), $titolarita, $coppie, $nudaDopo, $k);
                    if (is_string($verso) && collect($nudi)->contains('di_chi_esce', true)) {
                        $verso = $this->aiNudi($p, $x, $elenco(true), $titolarita, $coppie, $nudaDopo, $k);
                    }
                    if (is_string($verso)) {
                        return sprintf($verso, $this->data($a['giorno']));
                    }
                    $mossi = [];
                    foreach ($verso as $nuda => [$riga, $f]) {
                        // Divisa fra più nudi: ogni parte è quella che l'estinzione ha riunito alla nuda di ciascuno.
                        $mosso = $this->sposta($p, (int) $nudi[$nuda]['piena'], $a['giorno'], $titolarita, $f, null);
                        $mosso['divisa'] = $mosso['divisa'] || count($verso) > 1;
                        $mosso['divisa_come'] = count($verso) > 1 ? self::divisaCome($mosso['divisa_come'] ?? null, 'altri_nudi') : ($mosso['divisa_come'] ?? null);
                        $mossi[] = $mosso;
                    }

                    return $mossi;
                }
                if (isset($nudi[$x])) {
                    // La nuda che torna piena; con il consolidamento solo per la parte dell'usufrutto che finisce, il resto resta nudo.
                    $n = $nudi[$x];
                    $piena = $n['totale'] > 0 ? $n['quota'] / $n['totale'] : 1.0;
                    // Rilievo G2 del giro sulle correzioni: una nuda nata dalla somma di nude di origine diversa (la riserva che si somma
                    // alla nuda di chi compra, una nuda comprata che si somma a quella che c'era) e che torna piena solo in parte. La
                    // divisione per quota darebbe alla piena anche una parte del pacco che era sulla nuda di prima, e che resta nuda: dove
                    // il pacco non dice da quale delle due viene, non si segue (decisione 56: dove il legame non arriva, ci si ferma;
                    // decisione 63: il calcolo per pezzi è la Coda 225). La frase dice dove e quando le nude si sono sommate (rilievo K4).
                    if ($n['resto'] !== null && $piena < 1.0 && ($somma = self::nudaSommata($x, $titolarita, $nudaDopo, $dopo, $k)) !== null) {
                        $this->ferma = true;
                        $di = $this->nome((int) ($titolarita[$x]->anagrafica_id ?? 0));

                        return $somma['riga'] === $x
                            ? sprintf('la nuda proprietà di %s, nata da una somma il %s, è tornata piena solo in parte con l\'estinzione del %s: non si sa quale parte di questa quota sia rimasta nuda',
                                $di, $this->data($somma['giorno']), $this->data($a['giorno']))
                            : sprintf('la nuda proprietà di %s viene da quella di %s, nata da una somma il %s, ed è tornata piena solo in parte con l\'estinzione del %s: non si sa quale parte di questa quota sia rimasta nuda',
                                $di, $this->nome((int) ($titolarita[$somma['riga']]->anagrafica_id ?? 0)), $this->data($somma['giorno']), $this->data($a['giorno']));
                    }
                    $mossi = $n['piena'] === $x ? [$p] : [$this->sposta($p, $n['piena'], $a['giorno'], $titolarita, $piena)];
                    if ($n['resto'] !== null && $piena < 1.0) {
                        $mossi[] = $this->sposta($p, $n['resto'], $a['giorno'], $titolarita, 1.0 - $piena);
                    }

                    return $mossi;
                }
                // La piena che il nudo aveva già, sommata nella riga che torna piena (decisione 36).
                if ($chiusa && $ruolo($x) === 'proprietario') {
                    foreach ($nudi as $n) {
                        if ($n['anagrafica_id'] === $chi($x) && $n['piena'] !== $x) {
                            return [$this->sposta($p, $n['piena'], $a['giorno'], $titolarita)];
                        }
                    }
                }

                return [$p];

            case self::SUCCESSIONE:
                // Decisione 65 (1.11.0-beta.44): la quota sulla riga del defunto si divide sulle righe degli eredi, ciascuno con la quota che ha
                // ereditato; un erede già titolare con lo stesso ruolo ha sommato la sua riga in quella nuova, e il pacco ci va per intero.
                $eredi = array_values(array_filter($a['eredi'] ?? [], fn ($e) => $e['riga_id'] !== null));
                if ($x === $a['u']) {
                    $totale = array_sum(array_column($eredi, 'quota'));
                    if ($eredi === [] || $totale <= 0) {
                        return 'il passaggio del ' . $this->data($a['giorno']) . ' non dice a chi è andata la quota';
                    }
                    $mossi = [];
                    foreach ($eredi as $e) {
                        $mosso = $this->sposta($p, (int) $e['riga_id'], $a['giorno'], $titolarita, (float) $e['quota'] / $totale);
                        $mosso['divisa'] = $mosso['divisa'] || count($eredi) > 1;
                        // Rilievo X3 della Fase 1-bis: la parte rimasta agli altri si dice per quello che è, non come l'estinzione.
                        $mosso['divisa_come'] = count($eredi) > 1 ? self::divisaCome($mosso['divisa_come'] ?? null, ! empty($a['accrescimento']) ? 'altri_usufruttuari' : 'altri_eredi') : ($mosso['divisa_come'] ?? null);
                        $mossi[] = $mosso;
                    }

                    return $mossi;
                }
                if ($chiusa) {
                    foreach ($eredi as $e) {
                        if ($chi($x) === (int) $e['anagrafica_id'] && $ruolo($x) === $ruolo((int) $e['riga_id'])) {
                            return [$this->sposta($p, (int) $e['riga_id'], $a['giorno'], $titolarita)];
                        }
                    }
                }

                return [$p];

            default:
                return [$p];
        }
    }

    /**
     * I nudi che un'estinzione registrata ha fatto tornare pieni: la riga di nuda (chiusa, o cambiata sul posto), la riga piena
     * in cui torna, la nuda che resta col consolidamento, la quota che torna piena e quella della nuda di prima.
     *
     * @return array<int, array{anagrafica_id: int, piena: int, resto: ?int, quota: float, totale: float}>
     */
    private function nudiDellArco(array $a, Collection $titolarita): array
    {
        $nudi = [];
        foreach ($a['chiuse'] as $c => $fine) {
            $t = $titolarita[$c] ?? null;
            // Anche chi esce, se era nudo e l'estinzione gli ha aperto una riga piena (decisione 57 ➕, senza un altro usufrutto in
            // corso): la sua nuda va seguita come quella degli altri. Senza una riga piena aperta per lui resta fuori (sotto).
            if ($c === $a['u'] || $t === null || $t->tipologia !== 'nuda_proprietario') {
                continue;
            }
            $piena = self::nuovaDi($a, (int) $t->anagrafica_id, 'proprietario');
            if ($piena === null) {
                continue;
            }
            $resto = self::nuovaDi($a, (int) $t->anagrafica_id, 'nuda_proprietario');
            $totale = (float) $t->quota;
            $nudi[(int) $c] = ['anagrafica_id' => (int) $t->anagrafica_id, 'di_chi_esce' => (int) $t->anagrafica_id === $a['chi_esce'], 'piena' => $piena, 'resto' => $resto,
                'quota' => round($totale - ($resto !== null ? (float) $a['nuove'][$resto]['quota'] : 0.0), 2), 'totale' => $totale];
        }
        foreach ($a['prima'] as $id => $prima) {
            if (($prima['tipologia'] ?? null) === 'nuda_proprietario' && ($a['nuove'][$id]['tipologia'] ?? null) === 'proprietario') {
                $quota = (float) ($prima['quota'] ?? ($titolarita[$id]->quota ?? 0));
                $nudi[(int) $id] = ['anagrafica_id' => (int) ($titolarita[$id]->anagrafica_id ?? 0), 'di_chi_esce' => (int) ($titolarita[$id]->anagrafica_id ?? 0) === $a['chi_esce'], 'piena' => (int) $id, 'resto' => null, 'quota' => $quota, 'totale' => $quota];
            }
        }

        return $nudi;
    }

    /**
     * A quali nudi torna un pacco dell'usufrutto che si estingue: la nuda della sua coppia, seguita nelle vendite e nelle somme;
     * senza coppia (la parte censita), i nudi che nessuna parte di quell'usufrutto lega, per quota, se valgono proprio la parte
     * censita. Una stringa (con un `%s` per la data) se non si sa.
     *
     * @param list<array{id: int, anagrafica_id: int, quota: float}> $nudi
     * @return array<int, array{0: int, 1: float}>|string riga di nuda → [riga, frazione del pacco]
     */
    private function aiNudi(array $p, int $usufrutto, array $nudi, Collection $titolarita, array $coppie, array $nudaDopo, int $fino): array|string
    {
        $nonSi = fn () => sprintf('non si sa a quale nuda proprietà sia tornata la parte di %s dell\'usufrutto estinto il %%s', $this->nome((int) ($titolarita[$usufrutto]->anagrafica_id ?? 0)));
        $perId = collect($nudi)->keyBy('id');
        if ($nudi === []) {
            return $nonSi();
        }
        if ($p['coppia'] !== null) {
            $z = self::nudaCorrente((int) $p['coppia'], $nudaDopo, $fino);

            return $perId->has($z) ? [$z => [$z, 1.0]] : $nonSi();
        }
        $legate = [];
        $quotaLegata = 0.0;
        foreach ($coppie[$usufrutto] ?? [] as $c) {
            $legate[self::nudaCorrente((int) $c['nuda'], $nudaDopo, $fino)] = true;
            $quotaLegata += (float) $c['quota'];
        }
        $liberi = $perId->reject(fn ($n) => isset($legate[(int) $n['id']]));
        $censita = round((float) ($titolarita[$usufrutto]->quota ?? 0) - $quotaLegata, 2);
        $somma = round((float) $liberi->sum('quota'), 2);
        if ($liberi->isEmpty() || $somma <= 0 || abs($somma - $censita) > 0.01) {
            return $nonSi();
        }

        return $liberi->mapWithKeys(fn ($n) => [(int) $n['id'] => [(int) $n['id'], (float) $n['quota'] / $somma]])->all();
    }

    /**
     * Decisione 55: la riga di titolarità da cui viene una riga di riparto di un piano generato prima della .43, **solo dove la
     * risposta è una sola**. Ogni filtro deve essere vero per la riga da cui la riga di riparto è nata davvero, così «una sola»
     * vuol dire «quella»:
     * - stessa persona, ruolo = `ruolo_risolto`, com'era alla generazione (i passaggi registrati dopo si tolgono);
     * - attiva, con quota, non aperta da un passaggio registrato dopo la generazione né creata dopo la riga di riparto;
     * - non finita prima del tratto della riga (o della sua competenza); non cominciata dopo, se è entrata con un passaggio
     *   (D7: una riga censita il motore la conta dall'inizio del periodo);
     * - l'addebito diretto all'unità senza tratto è per persona: il legame solo se la persona ha **una sola** riga fra tutti i ruoli
     *   di diritto reale, come il motore della .43;
     * - due righe gemelle dello stesso piano (stessa chiave) vengono da due righe di titolarità: nessuna delle due.
     *
     * @param Collection<int, object> $righe le righe di riparto dell'unità (tutte quelle dei piani che servono)
     * @param array<int, array<string, mixed>> $archi i passaggi dell'unità (`arco()`)
     * @return array<int, int> riga di riparto → riga di titolarità
     */
    public static function legamiDedotti(Collection $righe, Collection $titolarita, array $archi, array $piani): array
    {
        $giorno = fn ($d) => $d === null || $d === '' ? null : substr((string) $d, 0, 10);
        $chiave = fn ($r) => implode('|', [(int) $r->piano_rate_id, $r->tipo, (int) $r->anagrafica_id, (string) $r->ruolo_risolto, (int) ($r->conto_id ?? 0), (int) ($r->tabella_id ?? 0),
            (string) ($r->ruolo_richiesto ?? ''), (int) ($r->riga_fattura_id ?? 0), $giorno($r->competenza_dal ?? null), $giorno($r->competenza_al ?? null), $giorno($r->titolarita_dal), $giorno($r->titolarita_al)]);
        $gemelle = $righe->groupBy($chiave)->filter(fn ($g) => $g->count() > 1)->keys()->flip()->all();
        $entrate = [];
        foreach ($archi as $a) {
            if ($a['e'] !== null) {
                $entrate[$a['e']] = true;
            }
            foreach (array_keys($a['nuove']) as $id) {
                $entrate[(int) $id] = true;
            }
        }

        $legami = [];
        foreach ($righe as $r) {
            if ($r->anagrafica_immobile_id !== null || ($r->ruolo_risolto ?? null) === null || isset($gemelle[$chiave($r)])) {
                continue;
            }
            $dopo = self::dopoLaGenerazione($archi, $piani[(int) $r->piano_rate_id] ?? null);
            $aperteDopo = [];
            foreach ($dopo as $a) {
                foreach ($a['nuove'] as $id => $n) {
                    if (empty($n['modificata'])) {
                        $aperteDopo[(int) $id] = true;
                    }
                }
            }
            [$dal, $al] = $giorno($r->titolarita_dal) !== null && $giorno($r->titolarita_al) !== null
                ? [$giorno($r->titolarita_dal), $giorno($r->titolarita_al)]
                : [$giorno($r->competenza_dal ?? null), $giorno($r->competenza_al ?? null)];
            $atemporale = ($r->tipo ?? null) === RigaRiparto::TIPO_AD_PERSONAM && $giorno($r->titolarita_dal) === null;
            $candidate = $titolarita->filter(function ($t) use ($r, $dal, $al, $dopo, $titolarita, $aperteDopo, $entrate, $giorno, $atemporale) {
                if ((int) $t->anagrafica_id !== (int) $r->anagrafica_id || ! (bool) $t->attivo || isset($aperteDopo[(int) $t->id])) {
                    return false;
                }
                if ($t->created_at !== null && ($r->created_at ?? null) !== null && (string) $t->created_at > (string) $r->created_at) {
                    return false;
                }
                $allora = self::allaGenerazione((int) $t->id, $titolarita, $dopo);
                $ruoli = $atemporale ? ['proprietario', 'nuda_proprietario', 'usufruttuario'] : [(string) $r->ruolo_risolto];

                return in_array($allora['tipologia'], $ruoli, true) && $allora['quota'] > 0.0
                    && ($dal === null || $allora['data_fine'] === null || $allora['data_fine'] >= $dal)
                    && ($al === null || ! isset($entrate[(int) $t->id]) || $giorno($t->data_inizio) === null || $giorno($t->data_inizio) <= $al);
            });
            if ($candidate->count() === 1 && (string) self::allaGenerazione((int) $candidate->first()->id, $titolarita, $dopo)['tipologia'] === (string) $r->ruolo_risolto) {
                $legami[(int) $r->id] = (int) $candidate->first()->id;
            }
        }

        return $legami;
    }

    private function nome(int $anagraficaId): string
    {
        if (! array_key_exists($anagraficaId, $this->nomi)) {
            $this->nomi[$anagraficaId] = (string) (DB::table('anagrafiche')->where('id', $anagraficaId)->value('nome') ?? 'una persona della catena');
        }

        return $this->nomi[$anagraficaId];
    }

    private function ruolo(string $tipologia): string
    {
        return match ($tipologia) {
            'proprietario' => 'piena proprietà',
            'nuda_proprietario' => 'nuda proprietà',
            'usufruttuario' => 'usufrutto',
            'inquilino' => 'locazione',
            default => $tipologia,
        };
    }

    private function data(?string $iso): string
    {
        return $iso ? CarbonImmutable::parse($iso)->locale('it')->translatedFormat('j F Y') : '—';
    }
}
