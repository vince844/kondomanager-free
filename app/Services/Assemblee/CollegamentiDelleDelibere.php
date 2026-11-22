<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La cerniera (R1–R4 del progetto della tappa 2): una scheda di oggi legata a una delibera, con il ruolo. **Il solo scrittore di `delibere_collegamenti`**
 * (lo controlla `SoloIlServizioScriveICollegamentiTest`).
 *
 * - **R1, la provenienza e non i conti**: il collegamento aggiunge la provenienza; le colonne di oggi restano la verità dei motori e si scrivono per la strada
 *   che hanno già (chi chiama scrive la data che {@see EsitoDelCollegamento::$dataEntrata} gli dice). Sul piano già emesso con una data diversa la data
 *   **non** entra, e l'esito lo dice. La delibera deve essere **del condominio della scheda** (§13, rilievo 12): altrimenti 404.
 * - **R2**: una delibera corretta dopo non cambia niente da sola; {@see posti()} elenca le schede che la usano, con la loro data di oggi.
 * - **R3**: niente si collega senza il clic: il servizio si chiama solo dal gesto di una persona (la scelta, la ratifica, la raccolta delle righe spuntate).
 * - **R4**: una delibera revocata o annullata resta collegata; le schede lo mostrano, nessun conto cambia.
 *
 * Collegare, cambiare, scollegare sono una riga sulla scheda: «Delibera: nessuna → assemblea del 22/10/2026, punto 1 «Rifacimento della facciata…»», con il
 * rimando alla delibera. Una delibera per ruolo per scheda: «Cambia» toglie la riga e ne scrive una nuova.
 *
 * **R5 del seguito**: dopo ogni collegamento, {@see \App\Services\Assemblee\Seguito\CoseDaFare::dopoIlCollegamento()} fa la cosa da fare che la scheda esegue
 * (il piano con la delibera, la dispensa, la regola): è l'unico aggancio, perché questo è l'unico scrittore. Nell'altro senso, tolto o cambiato il
 * collegamento, {@see \App\Services\Assemblee\Seguito\CoseDaFare::dopoLoScollegamento()} rimette da fare la cosa che quella scheda aveva fatto.
 */
final class CollegamentiDelleDelibere
{
    public static function collega(Model $scheda, string $ruolo, Delibera $delibera, ?string $nota, User $utente): EsitoDelCollegamento
    {
        $ruoloEnum = RuoloCollegamento::tryFrom($ruolo);
        if ($ruoloEnum === null) {
            throw ValidationException::withMessages(['ruolo' => 'Il ruolo della delibera non è fra quelli del programma.']);
        }
        abort_if(SchedaDelCondominio::condominioDi($scheda) !== (int) $delibera->condominio_id, 404);
        [$siSceglie, $perche] = DelibereDelCondominio::siSceglie($delibera, $ruolo);
        if (! $siSceglie) {
            throw ValidationException::withMessages(['delibera_id' => 'Questa delibera non si sceglie: ' . $perche . '.']);
        }
        $tipo = SchedaDelCondominio::tipoDi($scheda);
        $nota = $nota === null || trim($nota) === '' ? null : trim($nota);

        $deliberaPrima = null;
        $collegamento = DB::transaction(function () use ($scheda, $tipo, $ruolo, $delibera, $nota, $utente, &$deliberaPrima) {
            $prima = self::riga($tipo, (int) $scheda->getKey(), $ruolo);
            $deliberaPrima = $prima?->delibera;
            if ($prima !== null && (int) $prima->delibera_id === (int) $delibera->id) {
                if ($prima->nota !== $nota) {
                    $prima->update(['nota' => $nota]);
                }

                return $prima;
            }
            $prima?->delete();
            $nuovo = CollegamentoAllaDelibera::create(['delibera_id' => $delibera->id, 'condominio_id' => $delibera->condominio_id, 'oggetto_tipo' => $tipo,
                'oggetto_id' => (int) $scheda->getKey(), 'ruolo' => $ruolo, 'nota' => $nota, 'collegata_da' => $utente->id, 'collegata_il' => now()]);
            RegistroModifiche::gesto(GestiDelRegistro::deliberaDellaScheda($scheda, $ruolo,
                $deliberaPrima === null ? null : DeliberaInParole::nelRegistro($deliberaPrima), DeliberaInParole::nelRegistro($delibera), $delibera, $nota));

            return $nuovo;
        });
        // R5 del seguito delle delibere: «Cambia» toglie la delibera di prima, e la cosa che la scheda aveva fatto per lei torna da fare; la scheda nata con la
        // delibera fa la cosa da fare che aspettava (il piano, la dispensa, la regola), e chiude il compito.
        if ($deliberaPrima !== null && (int) $deliberaPrima->id !== (int) $delibera->id) {
            \App\Services\Assemblee\Seguito\CoseDaFare::dopoLoScollegamento($scheda, $ruolo, $deliberaPrima);
        }
        \App\Services\Assemblee\Seguito\CoseDaFare::dopoIlCollegamento($scheda, $ruolo, $delibera, $utente);

        [$dataEntrata, $differenza] = self::laData($scheda, $ruolo, $delibera);

        return new EsitoDelCollegamento($collegamento->refresh(), $dataEntrata, $differenza, self::conseguenza($scheda, $delibera));
    }

    /** Toglie il collegamento di quel ruolo: «Delibera: … → nessuna». Le colonne di oggi della scheda restano come sono. */
    public static function scollega(Model $scheda, string $ruolo, User $utente): void
    {
        $riga = self::riga(SchedaDelCondominio::tipoDi($scheda), (int) $scheda->getKey(), $ruolo);
        if ($riga === null) {
            return;
        }
        DB::transaction(function () use ($riga, $scheda, $ruolo) {
            $delibera = $riga->delibera;
            $riga->delete();
            RegistroModifiche::gesto(GestiDelRegistro::deliberaDellaScheda($scheda, $ruolo, $delibera === null ? null : DeliberaInParole::nelRegistro($delibera),
                null, $delibera));
            // R5 del seguito nell'altro senso: la cosa da fare che questa scheda aveva fatto per la delibera torna da fare.
            if ($delibera !== null) {
                \App\Services\Assemblee\Seguito\CoseDaFare::dopoLoScollegamento($scheda, $ruolo, $delibera);
            }
        });
    }

    /** La delibera di una scheda per quel ruolo. */
    public static function delibera(Model $scheda, string $ruolo): ?Delibera
    {
        return self::riga(SchedaDelCondominio::tipoDi($scheda), (int) $scheda->getKey(), $ruolo)?->delibera;
    }

    /**
     * R2: le schede che usano la delibera, con la data che hanno **oggi** (che può essere diversa da quella della delibera: non cambia da sola).
     *
     * @return list<array{oggetto_tipo: string, oggetto_id: int, ruolo: string, parola: string, nome: string, data: ?string, nota: ?string, esiste: bool}>
     */
    public static function posti(Delibera $delibera): array
    {
        $posti = [];
        foreach (CollegamentoAllaDelibera::query()->where('delibera_id', $delibera->id)->orderBy('id')->get() as $c) {
            $scheda = SchedaDelCondominio::TIPI[$c->oggetto_tipo][0] ?? null;
            $modello = $scheda === null ? null : $scheda::query()->find($c->oggetto_id);
            $posti[] = [
                'oggetto_tipo' => $c->oggetto_tipo,
                'oggetto_id' => (int) $c->oggetto_id,
                'ruolo' => (string) $c->ruolo?->value,
                'parola' => (string) $c->ruolo?->parola(),
                'nome' => $modello === null ? 'Scheda che non c\'è più' : SchedaDelCondominio::nomeDi($modello),
                'data' => $modello === null ? null : self::dataNellaScheda($modello, (string) $c->ruolo?->value),
                'nota' => $c->nota,
                'esiste' => $modello !== null,
            ];
        }

        return $posti;
    }

    /** «La usano 2 schede: il piano «Facciata 2026 — rate» dice 30/11/2026, la dispensa dei sei mesi dice 30/11/2026». */
    public static function frasiDeiPosti(array $posti): ?string
    {
        if ($posti === []) {
            return null;
        }
        $pezzi = array_map(fn ($p) => $p['nome'] . ($p['data'] !== null ? ' dice ' . \Illuminate\Support\Carbon::parse($p['data'])->format('d/m/Y') : ''), $posti);

        return 'La usano ' . (count($posti) === 1 ? '1 scheda' : count($posti) . ' schede') . ': ' . implode(', ', $pezzi);
    }

    /**
     * La conseguenza scritta accanto alla proposta, dove la delibera muove chi paga (§1.4, il piano del saldo di un'opera; decisioni 11, 12, 322): «Con questa
     * delibera le rate del piano passano a chi era titolare il 22/10/2026». Decide l'amministratore.
     */
    public static function conseguenza(?Model $scheda, Delibera $delibera): ?string
    {
        if (! $scheda instanceof PianoRate) {
            return null;
        }

        return 'Con questa delibera le rate del piano passano a chi era titolare il ' . $delibera->deliberata_il?->format('d/m/Y');
    }

    /**
     * Una scheda che se ne va porta via i suoi collegamenti, nello stesso gesto: una riga «Delibera: … → nessuna» per ruolo. Lo chiamano gli eventi di
     * eliminazione dei modelli che si collegano ({@see registraLeEliminazioni()}): altrimenti la delibera resterebbe «usata» da una scheda che non c'è più, e
     * non si eliminerebbe.
     */
    public static function togliDellaScheda(Model $scheda): void
    {
        $tipo = SchedaDelCondominio::tipoDi($scheda);
        $righe = CollegamentoAllaDelibera::query()->where('oggetto_tipo', $tipo)->where('oggetto_id', (int) $scheda->getKey())->get();
        foreach ($righe as $riga) {
            $delibera = $riga->delibera;
            $ruolo = (string) $riga->ruolo?->value;
            $riga->delete();
            RegistroModifiche::gesto(GestiDelRegistro::deliberaDellaScheda($scheda, $ruolo, $delibera === null ? null : DeliberaInParole::nelRegistro($delibera),
                null, $delibera));
        }
    }

    /** I collegamenti di una delibera a schede che non ci sono più (eliminate prima che il programma li togliesse da sé): se ne vanno con la delibera. */
    public static function togliDiSchedeCheNonCiSonoPiu(Delibera $delibera, User $utente): void
    {
        foreach (CollegamentoAllaDelibera::query()->where('delibera_id', $delibera->id)->get() as $c) {
            $classe = SchedaDelCondominio::TIPI[$c->oggetto_tipo][0] ?? null;
            if ($classe === null || ! $classe::query()->whereKey($c->oggetto_id)->exists()) {
                $c->delete();
            }
        }
    }

    /**
     * Le schede che si collegano a una delibera (i tipi dei ruoli del §1.4): alla loro eliminazione i collegamenti se ne vanno. Una scheda con il cestino
     * (la scrittura contabile) li perde solo quando se ne va davvero; nel cestino resta «Scheda che non c'è più», che non trattiene la delibera.
     */
    public static function registraLeEliminazioni(): void
    {
        foreach (['fattura_passiva', 'piano_rate', 'cassa', 'accordo_rientro', 'dispensa_sei_mesi', 'regola_recupero', 'contributo_versato', 'scrittura_contabile',
            'rendiconto_versione', 'rata_quote'] as $tipo) {
            $classe = SchedaDelCondominio::TIPI[$tipo][0];
            $evento = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($classe), true) ? 'forceDeleted' : 'deleted';
            $classe::$evento(fn (Model $scheda) => self::togliDellaScheda($scheda));
        }
    }

    /** I collegamenti di una delibera: una riga. */
    public static function quanti(Delibera $delibera): int
    {
        return CollegamentoAllaDelibera::query()->where('delibera_id', $delibera->id)->count();
    }

    /**
     * Il condominio di prova se ne va con tutto (R15): i suoi collegamenti si tolgono qui, dentro il suo gesto, prima delle delibere. È l'unica scrittura in
     * blocco della tabella, e sta in questo servizio come le altre.
     */
    public static function togliDelCondominioDiProva(int $condominioId): void
    {
        CollegamentoAllaDelibera::query()->where('condominio_id', $condominioId)->delete();
    }

    private static function riga(string $tipo, int $id, string $ruolo): ?CollegamentoAllaDelibera
    {
        return CollegamentoAllaDelibera::query()->where('oggetto_tipo', $tipo)->where('oggetto_id', $id)->where('ruolo', $ruolo)->first();
    }

    /**
     * R1: la data che entra nella scheda per la sua strada, o `null` con la differenza. Il piano rate (ruolo «delibera»): non immutabile, entra il giorno della
     * riunione (se le rate ci sono, la pagina dice «ricalcola il piano prima di emettere»); immutabile con la data diversa, la data resta e la differenza si
     * dice. Il riferimento all'assemblea dei lavori urgenti non porta date (la competenza sta sulle fatture).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function laData(Model $scheda, string $ruolo, Delibera $delibera): array
    {
        $giorno = $delibera->deliberata_il?->format('Y-m-d');
        if ($ruolo === RuoloCollegamento::RIFERIMENTO->value) {
            return [null, null];
        }
        if ($scheda instanceof PianoRate && $ruolo === RuoloCollegamento::DELIBERA->value) {
            $nelPiano = $scheda->data_delibera_assemblea === null ? null : \Illuminate\Support\Carbon::parse($scheda->data_delibera_assemblea)->format('Y-m-d');
            if ($scheda->eImmutabile() && $nelPiano !== null && $nelPiano !== $giorno) {
                return [null, 'La delibera è del ' . $delibera->deliberata_il->format('d/m/Y') . ', il piano dice '
                    . \Illuminate\Support\Carbon::parse($nelPiano)->format('d/m/Y')];
            }
        }

        return [$giorno, null];
    }

    /** La data della delibera come la scheda la tiene oggi (Y-m-d), per R2. */
    public static function dataNellaScheda(Model $scheda, string $ruolo): ?string
    {
        $tipo = SchedaDelCondominio::tipoDi($scheda);
        $extra = (array) ($scheda->getAttribute('dati_extra') ?? []);
        $valore = match (true) {
            $tipo === 'piano_rate' => $scheda->getAttribute('data_delibera_assemblea'),
            $tipo === 'dispensa_sei_mesi' => $ruolo === RuoloCollegamento::REVOCA_DELLA_DISPENSA->value ? $scheda->getAttribute('revocata_il')
                : $scheda->getAttribute('data_delibera'),
            $tipo === 'accordo_rientro' => $scheda->getAttribute('data_autorizzazione'),
            $tipo === 'fattura_passiva' && $ruolo === RuoloCollegamento::RATIFICA->value => $extra['ratifica_assembleare']['deliberata_il']
                ?? $extra['ratifica_assembleare']['approvato_il'] ?? null,
            $tipo === 'fattura_passiva' && $ruolo === RuoloCollegamento::COMPETENZA->value => $scheda->getAttribute('competenza_dal'),
            $tipo === 'fattura_passiva' && $ruolo === RuoloCollegamento::ORIGINE_DELLA_SPESA->value => $extra['log_legale_sopravvenienza']['data_assemblea'] ?? null,
            default => null,
        };
        if ($valore === null || $valore === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($valore instanceof \DateTimeInterface ? $valore->format('Y-m-d H:i:s') : (string) $valore)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
