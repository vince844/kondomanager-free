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
 *   rappresentati da chi c'era (art. 67 c. 2 disp. att.), l'usufruttuario e il nudo proprietario secondo la materia, il conduttore sui suoi punti.
 * - **In videoconferenza o mista** il verbale va a tutti i convocati del giorno, con le formalità della convocazione (art. 66 disp. att.).
 * - **L'amministratore toglie o aggiunge** una persona prima di spedire, con il perché, vedendo da quando corrono i suoi trenta giorni; chi è tolto conta come
 *   chi c'era e non ha votato; chi è aggiunto riceve il verbale come assente per i punti scelti. La scelta resta nel registro delle modifiche.
 * - **Il mezzo** è quello della convocazione (la riga di `assemblee_convocati`), o della scheda per chi non era convocato; senza un mezzo che vale, a mano.
 *   **Spedito** come la convocazione: la PEC partita, la raccomandata spedita, la consegna a mano registrata.
 *
 * Per ogni persona i termini dei suoi punti ({@see TerminiDellImpugnazione}): `dal` è il giorno da cui corrono (la delibera, o la ricezione), `fino_al` l'ultimo
 * giorno; per un assente che non ha ancora ricevuto il verbale `da_consegnare`.
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
        $persone = [];
        $nomi = [];
        $fotografia = (array) $seduta->fotografia;
        foreach ((array) ($fotografia['persone'] ?? []) as $id => $p) {
            $nomi[(int) $id] = (string) $p['nome'];
        }
        // Per ogni punto deliberato: chi c'era (con il voto della sua testa) e chi era assente.
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
                    $presenteA[(int) $p][(int) $v->punto->numero] = $intervenuta ? $conta : null;
                    $nomi[(int) $p] ??= (string) $t['nome'];
                }
            }
        }
        $giornoDellaSeduta = $seduta->fissata_il->format('Y-m-d');
        $convocati = collect($this->convocati->alGiorno($a, $giornoDellaSeduta))->keyBy('anagrafica_id');
        $fissati = ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->get()->keyBy(fn ($c) => (int) $c->anagrafica_id);
        $presenze = PresenzaInSala::query()->where('seduta_id', $seduta->id)->orderBy('entrata_il')->get()->groupBy(fn ($p) => (int) $p->anagrafica_id);
        $scelte = (array) ($verbale->trasmissione ?? []);
        $invii = RegistroInvio::query()->where('tipo', RegistroInvio::TIPO_VERBALE)->where('documentabile_type', $a->getMorphClass())
            ->where('documentabile_id', $a->id)->where('dati->verbale_id', (int) $verbale->id)->orderBy('id')->get()->groupBy(fn ($r) => (int) $r->anagrafica_id);
        $numeriDeiPunti = $punti->map(fn ($v) => (int) $v->punto->numero)->values()->all();
        $ids = array_values(array_unique([...array_keys($presenteA), ...$convocati->keys()->map(fn ($k) => (int) $k)->all(),
            ...array_map('intval', array_keys((array) ($scelte['aggiunti'] ?? [])))]));
        foreach ($ids as $id) {
            $suoi = $presenteA[$id] ?? [];
            $convocato = $convocati->get($id);
            if ($suoi === [] && $convocato !== null && is_array($convocato['punti'])) {
                // Il conduttore convocato per punti che qui non si sono deliberati.
                continue;
            }
            $assente = array_keys(array_filter($suoi, fn ($x) => $x === null));
            $tolto = $scelte['tolti'][$id] ?? null;
            $aggiunto = $scelte['aggiunti'][$id] ?? null;
            $entrato = ($presenze->get($id) ?? collect())->isNotEmpty();
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
            $puntiAssente = $aggiunto !== null && ! $proposto ? array_map('intval', (array) ($aggiunto['punti'] ?? $numeriDeiPunti)) : $assente;
            $invio = ($invii->get($id) ?? collect())->last();
            $persona = [
                'anagrafica_id' => $id,
                'nome' => (string) ($convocato['nome'] ?? $nomi[$id] ?? Anagrafica::query()->whereKey($id)->value('nome') ?? ''),
                'unita' => (string) ($convocato['unita_parole'] ?? ''),
                'gruppo' => $gruppo,
                'perche' => $this->perche($gruppo, $puntiAssente, $presenze->get($id), $punti),
                'punti_assente' => $puntiAssente,
                'voti' => array_filter($suoi, fn ($x) => $x !== null),
                'proposto' => $proposto,
                'riceve' => $riceve,
                'tolto' => $tolto,
                'aggiunto' => $aggiunto,
                'convocato' => $fissati->has($id),
                'mezzo' => $this->mezzo($fissati->get($id), $convocato, $id),
                'invio' => $invio === null ? null : self::invio($invio),
            ];
            $persona['termini'] = $this->terminiDi($persona, $suoi, $punti, $delibere, $tutti);
            $persone[] = $persona;
        }
        usort($persone, fn ($x, $y) => [['mai_entrati' => 0, 'tutti' => 0, 'fuori' => 1, 'aggiunti' => 2, 'cera' => 3][$x['gruppo']], mb_strtolower($x['nome'])]
            <=> [['mai_entrati' => 0, 'tutti' => 0, 'fuori' => 1, 'aggiunti' => 2, 'cera' => 3][$y['gruppo']], mb_strtolower($y['nome'])]);
        $ricevono = array_values(array_filter($persone, fn ($p) => $p['riceve']));

        return [
            'videoconferenza' => $tutti,
            'punti' => $punti->map(fn (VotazioneDelPunto $v) => ['punto_id' => (int) $v->punto_id, 'numero' => (int) $v->punto->numero, 'testo' => (string) $v->punto->testo,
                'votazione_id' => (int) $v->id, 'votata_il' => $v->votata_il?->copy()->utc()->toIso8601ZuluString(), 'delibera_id' => (int) $v->delibera_id,
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
     * I termini di una persona, raggruppati per come corrono: assente (dalla consegna), contrario, astenuto, senza voto o tolto dalla lista (dalla delibera). I
     * favorevoli non hanno termine.
     *
     * @param array<string, mixed> $persona
     * @param array<int, ?string> $suoi numero del punto => il voto di chi c'era, o null se assente
     * @return list<array<string, mixed>>
     */
    private function terminiDi(array $persona, array $suoi, $punti, $delibere, bool $tutti): array
    {
        $gruppi = [];
        $ricevuta = $persona['invio']['termini_dal'] ?? null;
        $come = $persona['invio']['termini_come'] ?? null;
        foreach ($punti as $v) {
            $numero = (int) $v->punto->numero;
            if (! array_key_exists($numero, $suoi) && ! in_array($numero, $persona['punti_assente'], true)) {
                continue;
            }
            $giorno = $delibere[$v->delibera_id]?->deliberata_il?->format('Y-m-d');
            $assente = in_array($numero, $persona['punti_assente'], true);
            if ($assente && $persona['riceve']) {
                $chiave = 'assente';
                $dal = $ricevuta;
            } else {
                $voto = $suoi[$numero] ?? null;
                if ($voto === 'favorevole' && ! $assente) {
                    continue;
                }
                $chiave = $assente ? 'tolto' : ($voto ?? 'non_votato');
                if ($voto === 'favorevole') {
                    continue;
                }
                $dal = $giorno;
            }
            $gruppi[$chiave . '|' . (string) $dal] ??= ['punti' => [], 'perche' => $chiave, 'dal' => $dal, 'fino_al' => $dal === null ? null : $this->termini->finoAl($dal),
                'da_consegnare' => $chiave === 'assente' && $dal === null, 'dal_come' => $chiave === 'assente' && $dal !== null ? $come : null];
            $gruppi[$chiave . '|' . (string) $dal]['punti'][] = $numero;
        }

        return array_values($gruppi);
    }

    /** «assente a tutti i punti», «uscito alle 19:30: assente ai punti 4, 5 e 6», «entrato alle 19:20: assente al punto 1». @param list<int> $assente */
    private function perche(string $gruppo, array $assente, $presenze, $punti): string
    {
        if ($gruppo === 'cera') {
            return 'c\'era a tutti i punti';
        }
        if ($gruppo === 'tutti') {
            return 'in videoconferenza o mista: il verbale va a tutti';
        }
        if ($gruppo === 'mai_entrati' || $assente === []) {
            return $assente === [] ? 'aggiunto' : 'assente a tutti i punti';
        }
        $quando = $punti->filter(fn ($v) => in_array((int) $v->punto->numero, $assente, true))->map(fn ($v) => $v->votata_il)->values();
        $righe = $presenze ?? collect();
        $prima = $righe->min('entrata_il');
        $uscite = $righe->pluck('uscita_il')->filter();
        $ultima = $uscite->max();
        $suPunti = 'assente ' . (count($assente) === 1 ? 'al punto ' : 'ai punti ') . ParoleDelVerbale::elenco(array_map('strval', $assente));
        if ($prima !== null && $quando->every(fn ($q) => $q->lt($prima))) {
            return 'entrato alle ' . ParoleDelVerbale::ora($prima) . ': ' . $suPunti;
        }
        if ($ultima !== null && $quando->every(fn ($q) => $q->gte($ultima))) {
            return 'uscito alle ' . ParoleDelVerbale::ora($ultima) . ': ' . $suPunti;
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
}
