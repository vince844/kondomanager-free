<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Models\Anagrafica;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\MezzoDiConvocazione;
use App\Services\Assemblee\Sala\FotografiaDellaSeduta;
use App\Services\Assemblee\Sala\PresenzeDellaSala;

/**
 * Chi riceve il verbale (V10, decisione 595 (3), scena 6 della bozza approvata): **la lista la propone il programma, la corregge l'amministratore**.
 *
 * - **In presenza** il programma propone la lista prudente: chi non è mai entrato, e chi era fuori al voto di un punto (uscito prima, o entrato dopo il voto:
 *   decisione 605), per quel punto. Chi c'era e non ha votato conta come astenuto. Per ogni punto conta la testa del punto: i comproprietari sono
 *   rappresentati da chi c'era (art. 67 c. 2 disp. att.), l'usufruttuario e il nudo proprietario secondo la materia, il conduttore sui suoi punti. Il
 *   comproprietario entrato «insieme» a chi rappresenta l'unità, presente senza voto, ha le entrate e le uscite di quella riga.
 * - **Per votazione, non per numero del punto** (Coda 750 B2): il rendiconto e il riparto votati a parte sono due delibere, e una persona può essere
 *   contraria all'uno e favorevole all'altro, o fuori a uno solo dei due voti. Dove una riga mostra il punto, il punto votato due volte dice quale
 *   («assente al riparto del punto 1»).
 * - **In videoconferenza o mista** il verbale va a tutti i convocati del giorno, con le formalità della convocazione (art. 66 disp. att.).
 * - **L'amministratore toglie o aggiunge** una persona prima di spedire, con il perché, vedendo da quando corrono i suoi trenta giorni; chi è tolto conta come
 *   chi c'era e non ha votato; chi è aggiunto riceve il verbale come assente per i punti scelti. La scelta resta nel registro delle modifiche.
 * - **Il mezzo** è quello della convocazione (la riga di `assemblee_convocati`), o della scheda per chi non era convocato; senza un mezzo che vale, a mano.
 *   **Spedito** come la convocazione: la PEC partita, la raccomandata spedita, la consegna a mano registrata.
 *
 * Per ogni persona i termini delle sue delibere ({@see TerminiDellImpugnazione}): `dal` è il giorno da cui corrono (la delibera, o la ricezione), `fino_al`
 * l'ultimo giorno; per un assente che non ha ancora ricevuto il verbale `da_consegnare`.
 */
final class DestinatariDelVerbale
{
    public function __construct(private readonly ConvocatiDellAssemblea $convocati, private readonly TerminiDellImpugnazione $termini) {}

    /** @return array<string, mixed> */
    public function per(VerbaleDellAssemblea $verbale): array
    {
        $verbale->loadMissing(['assemblea', 'seduta']);
        $a = $verbale->assemblea;
        $seduta = $verbale->seduta;
        $tutti = in_array($a->modalita, [ModalitaAssemblea::VIDEOCONFERENZA, ModalitaAssemblea::MISTA], true);
        $votazioni = VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->with(['voti', 'punto'])->orderBy('votata_il')->get()
            ->filter(fn (VotazioneDelPunto $v) => $v->maggioranza !== Maggioranza::SENZA_VOTO && $v->delibera_id !== null)->values();
        $delibere = Delibera::query()->whereIn('id', $votazioni->pluck('delibera_id'))->get()->keyBy('id');
        $punti = $votazioni->filter(fn ($v) => in_array($delibere[$v->delibera_id]?->esito, [EsitoDelibera::APPROVATA, EsitoDelibera::APPROVATA_CON_MODIFICHE,
            EsitoDelibera::RESPINTA], true))->values();
        // Il punto votato due volte (il rendiconto e il riparto): ognuna delle due votazioni è una delibera, e dove si mostra il punto ha il suo oggetto.
        $divisi = $votazioni->groupBy(fn (VotazioneDelPunto $v) => (int) $v->punto_id)->filter(fn ($g) => $g->count() > 1)->keys()->map(fn ($k) => (int) $k)->all();
        $voce = fn (VotazioneDelPunto $v) => ['votazione_id' => (int) $v->id, 'delibera_id' => (int) $v->delibera_id, 'numero' => (int) $v->punto->numero,
            'oggetto' => in_array((int) $v->punto_id, $divisi, true) ? self::oggettoDi($v) : null];
        $persone = [];
        $nomi = [];
        $fotografia = (array) $seduta->fotografia;
        foreach ((array) ($fotografia['persone'] ?? []) as $id => $p) {
            $nomi[(int) $id] = (string) $p['nome'];
        }
        // Per ogni votazione deliberata (una delibera: il rendiconto e il riparto sono due): chi c'era, con il voto della sua testa, e chi era assente.
        $presenteA = [];
        foreach ($punti as $v) {
            $usufruttuari = [];
            foreach ((array) ($v->scelte['usufruttuari'] ?? []) as $u) {
                $usufruttuari[(int) $u['immobile_id']] = (string) $u['perche'];
            }
            $intervenute = collect((array) ($v->intervenuti['teste'] ?? []))->keyBy('chiave');
            $voti = $v->voti->keyBy('testa');
            foreach (FotografiaDellaSeduta::testeDelPunto($fotografia, (int) $v->punto_id, $usufruttuari)['teste'] as $t) {
                $intervenuta = $intervenute->has($t['chiave']);
                $voto = $voti->get($t['chiave']);
                $conta = $voto === null ? 'non_votato' : ($voto->voto?->value === 'discorde' ? ($voto->decisione?->value ?? 'astenuto') : $voto->voto?->value);
                foreach ((array) $t['persone'] as $p) {
                    $presenteA[(int) $p][(int) $v->id] = $intervenuta ? $conta : null;
                    $nomi[(int) $p] ??= (string) $t['nome'];
                }
            }
        }
        $giornoDellaSeduta = $seduta->fissata_il->format('Y-m-d');
        $convocati = collect($this->convocati->alGiorno($a, $giornoDellaSeduta))->keyBy('anagrafica_id');
        $fissati = ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->get()->keyBy(fn ($c) => (int) $c->anagrafica_id);
        $presenze = self::presenzeDiOgnuno((int) $seduta->id);
        $scelte = (array) ($verbale->trasmissione ?? []);
        $invii = RegistroInvio::query()->where('tipo', RegistroInvio::TIPO_VERBALE)->where('documentabile_type', $a->getMorphClass())
            ->where('documentabile_id', $a->id)->where('dati->verbale_id', (int) $verbale->id)->orderBy('id')->get()->groupBy(fn ($r) => (int) $r->anagrafica_id);
        $numeriDeiPunti = $punti->map(fn ($v) => (int) $v->punto->numero)->unique()->values()->all();
        $ids = array_values(array_unique([...array_keys($presenteA), ...$convocati->keys()->map(fn ($k) => (int) $k)->all(),
            ...array_map('intval', array_keys((array) ($scelte['aggiunti'] ?? [])))]));
        foreach ($ids as $id) {
            /** @var array<int, ?string> $suoi votazione => il voto di chi c'era, o null se assente */
            $suoi = $presenteA[$id] ?? [];
            $convocato = $convocati->get($id);
            if ($suoi === [] && $convocato !== null && is_array($convocato['punti'])) {
                // Il conduttore convocato per punti che qui non si sono deliberati.
                continue;
            }
            $assente = array_keys(array_filter($suoi, fn ($x) => $x === null));
            $tolto = $scelte['tolti'][$id] ?? null;
            $aggiunto = $scelte['aggiunti'][$id] ?? null;
            $righe = collect($presenze[$id] ?? []);
            $entrato = $righe->isNotEmpty();
            if ($tutti) {
                $gruppo = 'tutti';
                $proposto = true;
            } elseif (($assente !== [] && count($assente) === count($suoi) || $suoi === []) && ! $entrato) {
                $gruppo = 'mai_entrati';
                $proposto = true;
            } elseif ($assente !== []) {
                $gruppo = 'fuori';
                $proposto = true;
            } else {
                $gruppo = 'cera';
                $proposto = false;
            }
            if ($aggiunto !== null && ! $proposto) {
                $gruppo = 'aggiunti';
            }
            $riceve = ($proposto && $tolto === null) || $aggiunto !== null;
            // Le votazioni per cui riceve il verbale come assente: quelle in cui era fuori, o, per chi è aggiunto a mano, tutte quelle dei punti scelti.
            if ($aggiunto !== null && ! $proposto) {
                $scelti = array_map('intval', (array) ($aggiunto['punti'] ?? $numeriDeiPunti));
                $assentiA = $punti->filter(fn ($v) => in_array((int) $v->punto->numero, $scelti, true))->map(fn ($v) => (int) $v->id)->values()->all();
            } else {
                $assentiA = $assente;
            }
            $vociAssente = $punti->filter(fn ($v) => in_array((int) $v->id, $assentiA, true))->map($voce)->values()->all();
            $invio = ($invii->get($id) ?? collect())->last();
            $persona = [
                'anagrafica_id' => $id,
                'nome' => (string) ($convocato['nome'] ?? $nomi[$id] ?? Anagrafica::query()->whereKey($id)->value('nome') ?? ''),
                'unita' => (string) ($convocato['unita_parole'] ?? ''),
                'gruppo' => $gruppo,
                'perche' => $this->perche($gruppo, $vociAssente, $righe, $punti),
                'punti_assente' => array_values(array_unique(array_column($vociAssente, 'numero'))),
                'voti' => self::votiPerPunto($suoi, $punti),
                'voti_per_votazione' => $punti->filter(fn ($v) => array_key_exists((int) $v->id, $suoi))
                    ->map(fn ($v) => $voce($v) + ['voto' => $suoi[(int) $v->id]])->values()->all(),
                'proposto' => $proposto,
                'riceve' => $riceve,
                'tolto' => $tolto,
                'aggiunto' => $aggiunto,
                'convocato' => $fissati->has($id),
                'mezzo' => $this->mezzo($fissati->get($id), $convocato, $id),
                'invio' => $invio === null ? null : self::invio($invio),
            ];
            $persona['termini'] = $this->terminiDi($persona, $suoi, $assentiA, $punti, $delibere, $voce);
            $persone[] = $persona;
        }
        usort($persone, fn ($x, $y) => [['mai_entrati' => 0, 'tutti' => 0, 'fuori' => 1, 'aggiunti' => 2, 'cera' => 3][$x['gruppo']], mb_strtolower($x['nome'])]
            <=> [['mai_entrati' => 0, 'tutti' => 0, 'fuori' => 1, 'aggiunti' => 2, 'cera' => 3][$y['gruppo']], mb_strtolower($y['nome'])]);
        $ricevono = array_values(array_filter($persone, fn ($p) => $p['riceve']));

        return [
            'videoconferenza' => $tutti,
            'punti' => $punti->map(fn (VotazioneDelPunto $v) => ['punto_id' => (int) $v->punto_id, 'numero' => (int) $v->punto->numero, 'testo' => (string) $v->punto->testo,
                'votazione_id' => (int) $v->id, 'votazione' => (int) $v->numero, 'oggetto' => $voce($v)['oggetto'],
                'votata_il' => $v->votata_il?->copy()->utc()->toIso8601ZuluString(), 'delibera_id' => (int) $v->delibera_id,
                'esito' => $delibere[$v->delibera_id]?->esito?->value, 'deliberata_il' => $delibere[$v->delibera_id]?->deliberata_il?->format('Y-m-d')])->values()->all(),
            'persone' => $persone,
            'quanti' => count($ricevono),
            'spediti' => count(array_filter($ricevono, fn ($p) => $p['invio']['spedito'] ?? false)),
            'consegnati' => count(array_filter($ricevono, fn ($p) => ($p['invio']['ricevuta_il'] ?? null) !== null)),
            'per_mezzo' => array_count_values(array_map(fn ($p) => (string) ($p['mezzo']['mezzo'] ?? 'a_mano'), array_filter($ricevono, fn ($p) => ! ($p['invio']['spedito'] ?? false)))),
        ];
    }

    /** Chi ha ricevuto il verbale e chi no: «spedito» come la convocazione. Ogni destinatario ha il suo invio spedito. */
    public function tuttiSpediti(VerbaleDellAssemblea $verbale): bool
    {
        $d = $this->per($verbale);

        return $d['quanti'] === $d['spediti'];
    }

    /**
     * Le righe di presenza di ognuno, per persona: le sue, e quelle di chi l'ha portata in sala «insieme» (il comproprietario presente senza voto entra ed
     * esce con la riga di chi rappresenta l'unità, rilievo C53: la riga è una, e vale per tutti e due). Coda 750 B3: senza le righe «insieme» il
     * comproprietario in sala risultava mai entrato, o senza l'ora in cui è entrato.
     *
     * @return array<int, list<PresenzaInSala>>
     */
    public static function presenzeDiOgnuno(int $sedutaId): array
    {
        $righe = [];
        foreach (PresenzaInSala::query()->where('seduta_id', $sedutaId)->orderBy('entrata_il')->get() as $r) {
            $righe[(int) $r->anagrafica_id][] = $r;
            foreach ((array) $r->insieme as $x) {
                $righe[(int) ($x['anagrafica_id'] ?? 0)][] = $r;
            }
        }

        return $righe;
    }

    /**
     * I termini di una persona, raggruppati per come corrono: assente (dalla consegna), contrario, astenuto, senza voto o tolto dalla lista (dalla delibera). I
     * favorevoli non hanno termine. Si contano **per votazione**, cioè per delibera: chi è contrario al rendiconto e favorevole al riparto ha il termine del
     * solo rendiconto. `punti` sono i numeri, senza doppioni; `delibere` e `voci` dicono quali delibere, con l'oggetto per il punto votato due volte.
     *
     * @param array<string, mixed> $persona
     * @param array<int, ?string> $suoi votazione => il voto di chi c'era, o null se assente
     * @param list<int> $assentiA le votazioni per cui riceve il verbale come assente
     * @return list<array<string, mixed>>
     */
    private function terminiDi(array $persona, array $suoi, array $assentiA, $punti, $delibere, \Closure $voce): array
    {
        $gruppi = [];
        $ricevuta = $persona['invio']['termini_dal'] ?? null;
        $come = $persona['invio']['termini_come'] ?? null;
        foreach ($punti as $v) {
            $id = (int) $v->id;
            $assente = in_array($id, $assentiA, true);
            if (! array_key_exists($id, $suoi) && ! $assente) {
                continue;
            }
            $giorno = $delibere[$v->delibera_id]?->deliberata_il?->format('Y-m-d');
            if ($assente && $persona['riceve']) {
                $chiave = 'assente';
                $dal = $ricevuta;
            } else {
                $voto = $suoi[$id] ?? null;
                if ($voto === 'favorevole') {
                    continue;
                }
                $chiave = $assente ? 'tolto' : ($voto ?? 'non_votato');
                $dal = $giorno;
            }
            $g = $chiave . '|' . (string) $dal;
            $gruppi[$g] ??= ['punti' => [], 'perche' => $chiave, 'dal' => $dal, 'fino_al' => $dal === null ? null : $this->termini->finoAl($dal),
                'da_consegnare' => $chiave === 'assente' && $dal === null, 'dal_come' => $chiave === 'assente' && $dal !== null ? $come : null, 'delibere' => [], 'voci' => []];
            $numero = (int) $v->punto->numero;
            if (! in_array($numero, $gruppi[$g]['punti'], true)) {
                $gruppi[$g]['punti'][] = $numero;
            }
            $gruppi[$g]['delibere'][] = (int) $v->delibera_id;
            $gruppi[$g]['voci'][] = $voce($v);
        }

        return array_values($gruppi);
    }

    /** I giorni della compiuta giacenza: la raccomandata non ritirata vale come ricevuta dieci giorni dopo l'avviso. */
    public const GIORNI_DELLA_GIACENZA = 10;

    /**
     * Il giorno da cui corrono i trenta giorni di chi era assente, e come («consegna», «ritiro», «compiuta_giacenza»), nella lettura prudente (decisione 615 (1),
     * proposta (a) della domanda sulla giacenza): la consegna; per la raccomandata in giacenza **il ritiro**, o **la compiuta giacenza** (l'avviso più dieci
     * giorni) finché non è ritirata. `ricevuta_il` resta l'avviso, che è il giorno giusto per la convocazione (Cass. 23396/2017), non per i termini del verbale.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function giornoDeiTermini(RegistroInvio $r): array
    {
        $ricevuta = $r->ricevuta_il?->format('Y-m-d');
        if ($ricevuta === null) {
            return [null, null];
        }
        // Il ritiro è un evento dell'invio; il suo giorno è quello dell'esito (la consegna allo sportello).
        $ritirata = collect((array) ($r->dati['eventi'] ?? []))->contains(fn ($e) => ($e['evento'] ?? null) === 'ritiro');
        if ($ritirata && $r->esito === RegistroInvio::ESITO_CONSEGNATA && $r->esito_il !== null) {
            $ritiro = $r->esito_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d');

            return [max($ricevuta, $ritiro), 'ritiro'];
        }
        if ($r->esito === RegistroInvio::ESITO_IN_GIACENZA) {
            return [\Carbon\CarbonImmutable::parse($ricevuta)->addDays(self::GIORNI_DELLA_GIACENZA)->format('Y-m-d'), 'compiuta_giacenza'];
        }

        return [$ricevuta, 'consegna'];
    }

    /** « (dal ritiro del 28/12/2026)», « (dalla compiuta giacenza, il 28/12/2026)»; niente per la consegna. */
    public static function daQuando(?string $come, ?string $dal): string
    {
        if ($dal === null) {
            return '';
        }
        $giorno = \Carbon\Carbon::parse($dal)->format('d/m/Y');

        return match ($come) {
            'ritiro' => ' (dal ritiro del ' . $giorno . ')',
            'compiuta_giacenza' => ' (dalla compiuta giacenza, il ' . $giorno . ')',
            default => '',
        };
    }

    /** @return array<string, mixed> */
    public static function invio(RegistroInvio $r): array
    {
        $vale = ! in_array($r->stato, [RegistroInvio::STATO_NON_PARTITA, RegistroInvio::STATO_IN_CODA], true) && $r->esito !== RegistroInvio::ESITO_NON_CONSEGNATA;
        [$terminiDal, $terminiCome] = self::giornoDeiTermini($r);

        return ['id' => (int) $r->id, 'canale' => $r->canale, 'mezzo' => $r->dati['mezzo'] ?? null, 'stato' => $r->stato, 'esito' => $r->esito,
            'termini_dal' => $terminiDal, 'termini_come' => $terminiCome,
            'esito_parola' => \App\Enums\Assemblee\EsitoInvio::parolaDi($r->esito), 'esito_il' => $r->esito_il?->copy()->utc()->toIso8601ZuluString(),
            'ricevuta_il' => $r->ricevuta_il?->format('Y-m-d'), 'spedito' => $vale && $r->stato !== RegistroInvio::STATO_STAMPATA, 'errore' => $r->errore,
            // L'ora in cui la lettera o la PEC è nata con la copia di allora: dopo «Sostituisci la copia» dice chi ha quella di prima.
            'creato_il' => $r->created_at?->copy()->utc()->toIso8601ZuluString(),
            // Il giorno in cui è partita (l'emissione, o la creazione se manca): «l'ultimo il …» della card della trasmissione fatta.
            'emesso_il' => ($r->emesso_il ?? $r->created_at)?->copy()->utc()->toIso8601ZuluString()];
    }

    /** L'oggetto di una delle due votazioni di un punto: `rendiconto` o `riparto`; la seconda, senza oggetto scritto, è il riparto. */
    public static function oggettoDi(VotazioneDelPunto $v): string
    {
        return in_array($v->oggetto, ['rendiconto', 'riparto'], true) ? (string) $v->oggetto : ((int) $v->numero === 2 ? 'riparto' : 'rendiconto');
    }

    /**
     * Per numero del punto, come ha contato chi c'era: solo i punti in cui ogni votazione dice lo stesso voto. Il punto votato due volte con due voti diversi,
     * o con una votazione da assente, non c'è: si legge in `voti_per_votazione`, una riga per votazione.
     *
     * @param array<int, ?string> $suoi
     * @return array<int, string>
     */
    private static function votiPerPunto(array $suoi, $punti): array
    {
        $perPunto = [];
        foreach ($punti as $v) {
            if (array_key_exists((int) $v->id, $suoi)) {
                $perPunto[(int) $v->punto->numero][] = $suoi[(int) $v->id];
            }
        }

        return array_map(fn ($voti) => (string) $voti[0], array_filter($perPunto, fn ($voti) => ! in_array(null, $voti, true) && count(array_unique($voti)) === 1));
    }

    /**
     * «al punto 4», «ai punti 1 e 2», «al riparto del punto 1», «ai punti 2 e 3 e al rendiconto del punto 1»: il punto votato due volte è intero quando ci
     * sono tutte e due le sue votazioni, altrimenti si dice quale.
     *
     * @param list<array{numero: int, oggetto: ?string}> $voci
     */
    public static function aiPunti(array $voci): string
    {
        $perPunto = [];
        foreach ($voci as $x) {
            $perPunto[(int) $x['numero']][] = $x['oggetto'] ?? null;
        }
        $interi = [];
        $parti = [];
        foreach ($perPunto as $numero => $oggetti) {
            if (in_array(null, $oggetti, true) || array_diff(['rendiconto', 'riparto'], $oggetti) === []) {
                $interi[] = (string) $numero;
            } else {
                foreach (array_unique($oggetti) as $o) {
                    $parti[] = 'al ' . $o . ' del punto ' . $numero;
                }
            }
        }
        $pezzi = $interi === [] ? [] : [(count($interi) === 1 ? 'al punto ' : 'ai punti ') . ParoleDelVerbale::elenco($interi)];

        return ParoleDelVerbale::elenco([...$pezzi, ...$parti]);
    }

    /**
     * «assente a tutti i punti», «fuori dalle 19:30: assente ai punti 4, 5 e 6», «in sala dalle 19:20: assente al punto 1», «fuori dalle 19:02: assente al
     * riparto del punto 1». Le parole senza genere (rilievo C59): il programma non sa se è un uomo o una donna.
     *
     * @param list<array<string, mixed>> $assente le votazioni in cui era fuori
     */
    private function perche(string $gruppo, array $assente, $presenze, $punti): string
    {
        if ($gruppo === 'cera') {
            return 'c\'era a tutti i punti';
        }
        if ($gruppo === 'tutti') {
            return 'in videoconferenza o mista: il verbale va a tutti';
        }
        if ($gruppo === 'mai_entrati' || $assente === []) {
            return $assente === [] ? 'aggiunta a mano' : 'assente a tutti i punti';
        }
        $votazioni = array_column($assente, 'votazione_id');
        $quando = $punti->filter(fn ($v) => in_array((int) $v->id, $votazioni, true))->map(fn ($v) => $v->votata_il)->values();
        $righe = $presenze ?? collect();
        $prima = $righe->min('entrata_il');
        $uscite = $righe->pluck('uscita_il')->filter();
        $ultima = $uscite->max();
        $suPunti = 'assente ' . self::aiPunti($assente);
        if ($prima !== null && $quando->every(fn ($q) => $q->lt($prima))) {
            return 'in sala dalle ' . ParoleDelVerbale::ora($prima) . ': ' . $suPunti;
        }
        if ($ultima !== null && $quando->every(fn ($q) => $q->gte($ultima))) {
            return 'fuori dalle ' . ParoleDelVerbale::ora($ultima) . ': ' . $suPunti;
        }

        return $suPunti;
    }

    /** Il mezzo: quello della convocazione, o della scheda; senza un mezzo che vale, a mano. @return array<string, mixed> */
    private function mezzo(?ConvocatoDellAssemblea $fissato, ?array $convocato, int $id): array
    {
        if ($fissato?->mezzo !== null) {
            $mezzo = $fissato->mezzo->value;

            return ['mezzo' => $mezzo, 'parola' => MezzoDiConvocazione::parola($mezzo), 'dalla_convocazione' => true, 'indirizzo' => $mezzo === 'pec'
                ? $fissato->pec : $fissato->indirizzo, 'perche' => null];
        }
        $persona = $convocato ?? (Anagrafica::query()->whereKey($id)->first()?->only(['pec', 'indirizzo', 'email', 'mezzo_convocazione', 'pec_comunicata_il']) ?? []);
        $m = MezzoDiConvocazione::per($persona);
        $mezzo = $m['valido'] ? (string) $m['mezzo'] : 'a_mano';

        return ['mezzo' => $mezzo, 'parola' => MezzoDiConvocazione::parola($mezzo), 'dalla_convocazione' => false,
            'indirizzo' => $mezzo === 'pec' ? ($persona['pec'] ?? null) : ($persona['indirizzo'] ?? null), 'perche' => $m['valido'] ? null : $m['perche']];
    }
}
