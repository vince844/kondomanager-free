<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Chi scrive il verbale (V6, scena 13 della bozza approvata): **una persona alla volta per parte**; gli altri leggono, e il testo cambia sotto i loro occhi.
 * Durante l'assemblea ogni punto è una parte: due persone scrivono due punti diversi insieme.
 *
 * - Chi ha la pagina aperta e chi scrive stanno nella cache, come il blocco della convocazione: **90 secondi**; chi ha la pagina aperta lo rinnova la lettura
 *   periodica, chi scrive il salvataggio (o la pagina mentre scrive). Chi scrive chiude la pagina, o non scrive per un minuto e mezzo: la parte torna libera.
 * - **«Chiedi di scrivere»** aspetta **60 secondi** la risposta di chi scrive: «Lascia scrivere Marco» (il suo testo è già salvato: la pagina salva prima di
 *   rispondere) o «Continuo io». Senza risposta: se in quel minuto chi scriveva non ha scritto niente, scrive chi ha chiesto; se stava scrivendo, la
 *   richiesta scade, e chi ha chiesto lo vede.
 * - **È un aiuto, non una serratura**: i salvataggi non lo controllano ({@see GestiDelVerbale}); senza rete si scrive lo stesso, e se due testi toccano la
 *   stessa parte restano due versioni.
 */
final class ChiScriveIlVerbale
{
    public const DURATA = 90;

    public const ATTESA_DELLA_RISPOSTA = 60;

    /**
     * La lettura periodica della pagina (e l'apertura): il dispositivo è fra chi ha il verbale aperto; con `$scrivo` prende la parte se è libera (o è già sua).
     *
     * @return array<string, mixed> lo stato della parte ({@see stato()})
     */
    public function presenza(VerbaleDellAssemblea $verbale, string $parte, string $dispositivo, User $utente, bool $scrivo = false): array
    {
        $aperti = $this->aperti($verbale);
        $aperti[$dispositivo] = ['utente_id' => (int) $utente->id, 'nome' => (string) $utente->name, 'parte' => $parte, 'fino' => $this->adesso() + self::DURATA];
        Cache::put($this->chiave($verbale, 'aperto'), $aperti, self::DURATA * 2);
        $this->risolviLaRichiesta($verbale, $parte);
        $scrive = $this->chiScrive($verbale, $parte);
        // La lettura periodica rinnova chi ha la pagina aperta; chi scrive lo rinnova il salvataggio (o la pagina che sta scrivendo, con `$scrivo`): chi non
        // scrive per un minuto e mezzo lascia la parte libera (scena 13).
        if ($scrivo && ($scrive === null || $scrive['dispositivo'] === $dispositivo)) {
            $this->prende($verbale, $parte, $dispositivo, $utente, $scrive['scritto_il'] ?? null);
        }

        return $this->stato($verbale, $parte, $dispositivo);
    }

    /** Un salvataggio arrivato: chi scrive resta lui (o diventa lui se la parte era libera), e l'ora dell'ultima scrittura si rinnova. */
    public function haScritto(VerbaleDellAssemblea $verbale, string $parte, string $dispositivo, User $utente): void
    {
        $scrive = $this->chiScrive($verbale, $parte);
        if ($scrive === null || $scrive['dispositivo'] === $dispositivo) {
            $this->prende($verbale, $parte, $dispositivo, $utente, $this->adesso());
        }
    }

    /** «Chiedi di scrivere»: se la parte è libera la prende subito; se no la richiesta aspetta 60 secondi la risposta. @return array<string, mixed> */
    public function chiedi(VerbaleDellAssemblea $verbale, string $parte, string $dispositivo, User $utente): array
    {
        $this->risolviLaRichiesta($verbale, $parte);
        $scrive = $this->chiScrive($verbale, $parte);
        if ($scrive === null || $scrive['dispositivo'] === $dispositivo) {
            $this->prende($verbale, $parte, $dispositivo, $utente, null);
        } else {
            Cache::put($this->chiave($verbale, $parte . ':richiesta'), ['utente_id' => (int) $utente->id, 'nome' => (string) $utente->name,
                'dispositivo' => $dispositivo, 'il' => $this->adesso(), 'scade' => $this->adesso() + self::ATTESA_DELLA_RISPOSTA], self::DURATA * 2);
            Cache::forget($this->chiave($verbale, $parte . ':esito:' . $dispositivo));
        }

        return $this->stato($verbale, $parte, $dispositivo);
    }

    /** La risposta di chi scrive: `lascia` (scrive chi ha chiesto) o `continuo`. Solo il dispositivo che scrive risponde. @return array<string, mixed> */
    public function rispondi(VerbaleDellAssemblea $verbale, string $parte, string $dispositivo, string $risposta): array
    {
        $scrive = $this->chiScrive($verbale, $parte);
        $richiesta = Cache::get($this->chiave($verbale, $parte . ':richiesta'));
        if ($scrive !== null && $scrive['dispositivo'] === $dispositivo && is_array($richiesta)) {
            Cache::forget($this->chiave($verbale, $parte . ':richiesta'));
            if ($risposta === 'lascia') {
                Cache::put($this->chiave($verbale, $parte . ':scrive'), ['utente_id' => $richiesta['utente_id'], 'nome' => $richiesta['nome'],
                    'dispositivo' => $richiesta['dispositivo'], 'dal' => $this->adesso(), 'scritto_il' => null, 'fino' => $this->adesso() + self::DURATA], self::DURATA * 2);
                $this->esito($verbale, $parte, (string) $richiesta['dispositivo'], 'lasciato', (string) $scrive['nome']);
            } else {
                $this->esito($verbale, $parte, (string) $richiesta['dispositivo'], 'continua', (string) $scrive['nome']);
            }
        }

        return $this->stato($verbale, $parte, $dispositivo);
    }

    /** Chi scrive lascia la parte (la pagina chiusa, «Lascia»). */
    public function lascia(VerbaleDellAssemblea $verbale, string $parte, string $dispositivo): void
    {
        $scrive = $this->chiScrive($verbale, $parte);
        if ($scrive !== null && $scrive['dispositivo'] === $dispositivo) {
            Cache::forget($this->chiave($verbale, $parte . ':scrive'));
        }
    }

    /**
     * Lo stato di una parte per un dispositivo: chi scrive (e se è lui), la richiesta aperta (per chi scrive: «Marco Galli chiede di scrivere», con i secondi
     * che restano; per chi ha chiesto: «aspetto la risposta»), l'esito dell'ultima richiesta di questo dispositivo (`lasciato`, `continua`, `scaduta`), chi
     * altro ha il verbale aperto.
     *
     * @return array<string, mixed>
     */
    public function stato(VerbaleDellAssemblea $verbale, string $parte, string $dispositivo): array
    {
        $this->risolviLaRichiesta($verbale, $parte);
        $scrive = $this->chiScrive($verbale, $parte);
        $richiesta = Cache::get($this->chiave($verbale, $parte . ':richiesta'));
        $esito = Cache::get($this->chiave($verbale, $parte . ':esito:' . $dispositivo));
        $adesso = $this->adesso();

        return [
            'parte' => $parte,
            'scrive' => $scrive === null ? null : ['nome' => $scrive['nome'], 'dispositivo' => $scrive['dispositivo'], 'sono_io' => $scrive['dispositivo'] === $dispositivo,
                'dal' => CarbonImmutable::createFromTimestamp((int) $scrive['dal'])->utc()->toIso8601ZuluString()],
            'richiesta' => is_array($richiesta) ? ['nome' => $richiesta['nome'], 'dispositivo' => $richiesta['dispositivo'], 'mia' => $richiesta['dispositivo'] === $dispositivo,
                'per_me' => $scrive !== null && $scrive['dispositivo'] === $dispositivo, 'secondi' => max(0, (int) $richiesta['scade'] - $adesso)] : null,
            'esito_della_richiesta' => is_array($esito) ? $esito : null,
            'aperto_da' => array_values(array_map(fn ($d, $a) => ['dispositivo' => $d, 'nome' => $a['nome'], 'parte' => $a['parte']],
                array_keys(array_diff_key($this->aperti($verbale), [$dispositivo => true])), array_diff_key($this->aperti($verbale), [$dispositivo => true]))),
        ];
    }

    /** Chi ha il verbale aperto adesso, per dispositivo. @return array<string, array<string, mixed>> */
    public function aperti(VerbaleDellAssemblea $verbale): array
    {
        $adesso = $this->adesso();

        return array_filter((array) Cache::get($this->chiave($verbale, 'aperto'), []), fn ($a) => (int) ($a['fino'] ?? 0) >= $adesso);
    }

    /** Chi scrive la parte adesso, se non è scaduto. @return ?array<string, mixed> */
    private function chiScrive(VerbaleDellAssemblea $verbale, string $parte): ?array
    {
        $scrive = Cache::get($this->chiave($verbale, $parte . ':scrive'));

        return is_array($scrive) && (int) ($scrive['fino'] ?? 0) >= $this->adesso() ? $scrive : null;
    }

    private function prende(VerbaleDellAssemblea $verbale, string $parte, string $dispositivo, User $utente, ?int $scrittoIl): void
    {
        $prima = $this->chiScrive($verbale, $parte);
        Cache::put($this->chiave($verbale, $parte . ':scrive'), ['utente_id' => (int) $utente->id, 'nome' => (string) $utente->name, 'dispositivo' => $dispositivo,
            'dal' => $prima !== null && $prima['dispositivo'] === $dispositivo ? (int) $prima['dal'] : $this->adesso(), 'scritto_il' => $scrittoIl,
            'fino' => $this->adesso() + self::DURATA], self::DURATA * 2);
    }

    /**
     * La richiesta senza risposta dopo 60 secondi: se chi scriveva non ha scritto in quel minuto, la parte passa a chi ha chiesto; se no la richiesta scade.
     * Chi scriveva e non c'è più (scaduto): la parte è libera, e passa a chi ha chiesto.
     */
    private function risolviLaRichiesta(VerbaleDellAssemblea $verbale, string $parte): void
    {
        $richiesta = Cache::get($this->chiave($verbale, $parte . ':richiesta'));
        if (! is_array($richiesta)) {
            return;
        }
        $scrive = $this->chiScrive($verbale, $parte);
        $scaduta = $this->adesso() >= (int) $richiesta['scade'];
        if ($scrive !== null && ! $scaduta) {
            return;
        }
        Cache::forget($this->chiave($verbale, $parte . ':richiesta'));
        $haScritto = $scrive !== null && $scrive['scritto_il'] !== null && (int) $scrive['scritto_il'] >= (int) $richiesta['il'];
        if ($haScritto) {
            $this->esito($verbale, $parte, (string) $richiesta['dispositivo'], 'scaduta', (string) $scrive['nome']);

            return;
        }
        Cache::put($this->chiave($verbale, $parte . ':scrive'), ['utente_id' => $richiesta['utente_id'], 'nome' => $richiesta['nome'],
            'dispositivo' => $richiesta['dispositivo'], 'dal' => $this->adesso(), 'scritto_il' => null, 'fino' => $this->adesso() + self::DURATA], self::DURATA * 2);
        $this->esito($verbale, $parte, (string) $richiesta['dispositivo'], 'lasciato', (string) ($scrive['nome'] ?? ''));
    }

    private function esito(VerbaleDellAssemblea $verbale, string $parte, string $dispositivo, string $esito, string $di): void
    {
        Cache::put($this->chiave($verbale, $parte . ':esito:' . $dispositivo), ['esito' => $esito, 'di' => $di, 'il' => $this->adesso()], self::ATTESA_DELLA_RISPOSTA);
    }

    private function chiave(VerbaleDellAssemblea $verbale, string $che): string
    {
        return 'assemblee:verbale:' . $verbale->id . ':' . $che;
    }

    private function adesso(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }
}
