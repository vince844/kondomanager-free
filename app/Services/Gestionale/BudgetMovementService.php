<?php

namespace App\Services\Gestionale;

use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\BudgetMovement;
use App\Models\Gestionale\PianoConto;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetMovementService
{
    /** Il tipo di un capitolo con sottoconti di spesa e di entrata, che il motore divide fra le due ({@see Conto::eMisto()}). */
    public const TIPO_MISTO = 'misto';

    public function __construct(
        protected SpesaPerVoceService $spesaPerVoceService
    ) {}

    /**
     * Esegue lo spostamento di budget tra due voci all'interno dello stesso piano rate.
     *
     * @param  int|null  $reversesMovementId  valorizzato solo da reverseMovement(): lega il
     *         movimento appena creato a quello che sta stornando. Un chiamante esterno non lo
     *         passa mai — è la differenza fra "sposto budget" e "sto restituendo budget spostato".
     */
    public function moveBudget(PianoRate $piano, int $sourceId, int $destId, int $amount, string $reason, int $userId, ?int $reversesMovementId = null): BudgetMovement
    {
        // 1. Validazione di Base
        if ($sourceId === $destId) {
            throw ValidationException::withMessages(['destination_id' => 'Sorgente e destinazione devono essere diverse.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'L\'importo deve essere positivo.']);
        }

        // Il totale del piano è la somma delle voci col segno con cui il motore le distribuisce (spese con +, entrate con −): spostare budget
        // fra una spesa e un'entrata lo cambierebbe del doppio. Il segno è quello della voce o, per un capitolo, quello dei suoi sottoconti, e un
        // capitolo con spese ed entrate insieme non si sposta. Lo storno resta fuori: restituisce ciò che è già stato spostato, anche se nel
        // frattempo una delle due voci ha cambiato tipo.
        if ($reversesMovementId === null) {
            $this->controllaStessoTipo($piano, $sourceId, $destId);
        }

        return DB::transaction(function () use ($piano, $sourceId, $destId, $amount, $reason, $userId, $reversesMovementId) {

            // 2. Recupero Dati Pivot (con lock per evitare race conditions)
            $sourcePivot = DB::table('piano_rate_capitoli')
                ->where('piano_rate_id', $piano->id)
                ->where('conto_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if (!$sourcePivot) {
                throw ValidationException::withMessages(['source_id' => 'La voce sorgente non è presente in questo piano rate.']);
            }

            // Calcolo importo attuale sorgente
            // Se è NULL, significa "Tutto il residuo". Dobbiamo convertirlo in numero per sottrarre.
            // Recuperiamo l'importo originale dal Conto se è NULL.
            $sourceCurrentAmount = $sourcePivot->importo;
            if (is_null($sourceCurrentAmount)) {
                $contoSource = Conto::find($sourceId);
                $sourceCurrentAmount = $contoSource->importo; // Assumiamo che copra tutto il preventivo
            }

            // 3. Check Capienza.
            //
            // ⚠️ Non basta confrontare col pivot: quel numero è quanto QUESTO piano ha *pianificato*
            // di finanziare, non quanto è già stato speso davvero. Fino alla beta.73 si poteva
            // spostare via budget da una voce con fatture già registrate per l'intero importo —
            // il pivot scendeva, lo speso restava lì, e la voce risultava scoperta senza nessun
            // avviso al momento in cui contava. `SpesaPerVoceService` è la stessa fonte che
            // `BudgetCoverageService` usa per il cruscotto: non una query nuova su `righe_fattura`,
            // che è la fonte incompleta corretta nella beta.30 (salta regolazioni immediate e
            // fatture pregresse).
            $spesoGiaRegistrato = $this->spesoRegistratoSu($piano, $sourceId);
            $disponibileReale = $sourceCurrentAmount - $spesoGiaRegistrato;

            if ($amount > $disponibileReale) {
                if ($spesoGiaRegistrato > 0) {
                    throw ValidationException::withMessages(['amount' =>
                        'Questa voce ha già € ' . number_format($spesoGiaRegistrato / 100, 2, ',', '.') . ' di fatture registrate. '
                        . 'Puoi spostare al massimo € ' . number_format(max(0, $disponibileReale) / 100, 2, ',', '.') . ' senza lasciarla scoperta.'
                    ]);
                }
                throw ValidationException::withMessages(['amount' => 'Fondi insufficienti. Disponibili: € ' . number_format($sourceCurrentAmount / 100, 2, ',', '.')]);
            }

            // 4. Gestione Destinazione
            $destPivot = DB::table('piano_rate_capitoli')
                ->where('piano_rate_id', $piano->id)
                ->where('conto_id', $destId)
                ->lockForUpdate()
                ->first();

            // Una voce sovrapposta a un capitolo del piano, o a dei sottoconti del piano, perderebbe l'importo spostato (il motore dà la quota del capitolo
            // ai sottoconti e salta la loro riga), anche se ha già la sua riga: un piano che ha già righe così non si peggiora. Lo storno restituisce ciò
            // che c'è già e non crea righe nuove.
            if ($reversesMovementId === null) {
                $this->controllaDestinazioneNelPiano($piano, $destId, $destPivot !== null);
            }

            $destOldAmount = $destPivot ? ($destPivot->importo ?? Conto::find($destId)->importo) : 0;

            // 5. Esecuzione Spostamento (Aggiornamento Pivot)

            // A. Riduci Sorgente
            DB::table('piano_rate_capitoli')
                ->where('id', $sourcePivot->id)
                ->update(['importo' => $sourceCurrentAmount - $amount, 'updated_at' => now()]);

            // B. Aumenta/Crea Destinazione
            if ($destPivot) {
                // Se destinazione aveva importo NULL (tutto), dobbiamo convertirlo in numero + extra
                $newDestAmount = $destOldAmount + $amount;
                DB::table('piano_rate_capitoli')
                    ->where('id', $destPivot->id)
                    ->update(['importo' => $newDestAmount, 'updated_at' => now()]);
            } else {
                // Creiamo la riga pivot se non esiste
                DB::table('piano_rate_capitoli')->insert([
                    'piano_rate_id' => $piano->id,
                    'conto_id' => $destId,
                    'importo' => $amount,
                    'note' => 'Generato da Sposta Spesa: ' . $reason,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 6. Audit Log (Memoria Storica)
            $logId = DB::table('budget_movements')->insertGetId([
                'piano_rate_id' => $piano->id,
                'source_conto_id' => $sourceId,
                'destination_conto_id' => $destId,
                'user_id' => $userId,
                'amount' => $amount,
                'source_old_amount' => $sourceCurrentAmount,
                'destination_old_amount' => $destOldAmount,
                'reason' => $reason,
                'type' => $reversesMovementId ? 'reversal' : 'reallocation',
                'reverses_movement_id' => $reversesMovementId,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return BudgetMovement::find($logId);
        });
    }

    /**
     * Il tipo con cui «Sposta spesa» confronta le voci: «spesa» o «entrata» secondo il segno con cui il motore la distribuisce (`Conto::segno()`
     * alla data del piano: un capitolo di tipo «Spesa» con i soli sottoconti di entrata conta come un'entrata), oppure `TIPO_MISTO` per un capitolo con
     * sottoconti di spesa e di entrata.
     *
     * La voce arriva con `tabelleMillesimali` e i `sottoconti` (con le loro tabelle) già caricati, se chi chiama ne legge molte.
     */
    public static function tipoDelSegno(Conto $voce, ?CarbonInterface $alla): string
    {
        if ($voce->eMisto($alla)) {
            return self::TIPO_MISTO;
        }

        return $voce->segno($alla) > 0 ? 'spesa' : 'entrata';
    }

    /**
     * Sorgente e destinazione devono essere dello stesso tipo, quello del segno con cui il motore le distribuisce, e nessuna delle due è un capitolo con
     * spese ed entrate insieme. Le voci si leggono dentro i piani dei conti del condominio del piano: una voce di un altro condominio non si nomina
     * in nessun messaggio (la sua assenza dal perimetro la rifiuta la richiesta).
     *
     * @throws ValidationException sul campo `destination_id`
     */
    private function controllaStessoTipo(PianoRate $piano, int $sourceId, int $destId): void
    {
        $voci = Conto::query()
            ->whereIn('id', [$sourceId, $destId])
            ->whereIn('piano_conto_id', PianoConto::query()->where('condominio_id', $piano->condominio_id)->select('id'))
            ->with(['tabelleMillesimali', 'sottoconti.tabelleMillesimali'])
            ->get(['id', 'nome', 'tipo'])
            ->keyBy('id');
        $da = $voci->get($sourceId);
        $a = $voci->get($destId);

        if ($da === null || $a === null) {
            return;
        }

        $tipoDa = self::tipoDelSegno($da, $piano->created_at);
        $tipoA = self::tipoDelSegno($a, $piano->created_at);

        $misti = array_values(array_filter([$da, $a], fn (Conto $c) => ($c === $da ? $tipoDa : $tipoA) === self::TIPO_MISTO));

        if ($misti !== []) {
            throw ValidationException::withMessages(['destination_id' => self::fraseDelMisto(array_map(fn (Conto $c) => $c->nome, $misti))]);
        }

        if ($tipoDa === $tipoA) {
            return;
        }

        $chi = function (Conto $c, string $tipo): string {
            $contaComeSpesa = $tipo === 'spesa';
            $come = $contaComeSpesa ? 'una spesa' : 'un\'entrata';

            if (Conto::segnoDelTipo((string) $c->tipo) === ($contaComeSpesa ? 1 : -1)) {
                return '«' . $c->nome . '» è ' . $come;
            }

            return '«' . $c->nome . '» è un capitolo i cui sottoconti sono ' . ($contaComeSpesa ? 'spese' : 'entrate') . ' e nel piano conta come ' . $come;
        };

        throw ValidationException::withMessages(['destination_id' => $chi($da, $tipoDa) . ' e ' . $chi($a, $tipoA)
            . ': il budget si sposta solo fra voci dello stesso tipo, altrimenti il totale del piano cambierebbe. Scegli una voce dello stesso tipo.']);
    }

    /** Il rifiuto per uno o più capitoli con sottoconti di spesa e di entrata, che il piano divide fra le due: non si spostano e non si ricevono. */
    private static function fraseDelMisto(array $nomi): string
    {
        $chi = implode(' e ', array_map(fn (string $nome) => '«' . $nome . '»', $nomi));

        return count($nomi) === 1
            ? $chi . ' ha sottoconti di spesa e di entrata: il piano ne divide l\'importo fra le due, e spostare budget da o verso un capitolo così cambierebbe il totale del piano. Scegli una voce che sia solo una spesa o solo un\'entrata.'
            : $chi . ' hanno sottoconti di spesa e di entrata: il piano ne divide l\'importo fra le due, e spostare budget da o verso capitoli così cambierebbe il totale del piano. Scegli voci che siano solo spese o solo entrate.';
    }

    /**
     * Le voci già nel piano a cui una riga di `$contoId` si sovrapporrebbe: i capitoli che gli passano la quota (dal più vicino) e i sottoconti a cui
     * la passerebbe, a ogni livello. Il motore dà la quota di un capitolo ai suoi sottoconti e salta la loro riga: di due righe sovrapposte una non conta.
     *
     * La quota passa solo se il capitolo non ha tabelle millesimali proprie, e solo ai sottoconti nati fino alla data del piano (a tutti, se nessuno
     * lo è), con la stessa regola a ogni livello: `$raggiunti` dice, per ogni voce, a quali. Senza, la quota va a ogni sottoconto.
     *
     * @param  array<int, int|null>  $padri  id => parent_id di tutte le voci del piano dei conti
     * @param  list<int>  $nelPiano  le voci che hanno una riga nel piano
     * @param  array<int, list<int>>|null  $raggiunti  id => i sottoconti a cui il motore passa la quota della voce
     * @return array{capitoli: list<int>, sottoconti: list<int>}
     */
    public static function sovrapposteAlPiano(int $contoId, array $padri, array $nelPiano, ?array $raggiunti = null): array
    {
        if ($raggiunti === null) {
            $raggiunti = [];
            foreach ($padri as $id => $padre) {
                if ($padre !== null) {
                    $raggiunti[(int) $padre][] = (int) $id;
                }
            }
        }

        // Si risale finché il motore passa la quota alla voce da cui si viene: oltre un capitolo che non gliela passa nessuno la porta fin qui.
        $capitoli = [];
        $visti = [$contoId => true];
        $figlio = $contoId;
        for ($id = $padri[$figlio] ?? null; $id !== null && ! isset($visti[$id]); $id = $padri[$id] ?? null) {
            if (! in_array($figlio, $raggiunti[$id] ?? [], true)) {
                break;
            }
            $visti[$id] = true;
            if (in_array($id, $nelPiano, true)) {
                $capitoli[] = $id;
            }
            $figlio = $id;
        }

        $sottoconti = [];
        $visti = [$contoId => true];
        $daVedere = $raggiunti[$contoId] ?? [];
        while ($daVedere !== []) {
            $id = array_shift($daVedere);
            if (isset($visti[$id])) {
                continue;
            }
            $visti[$id] = true;
            if (in_array($id, $nelPiano, true)) {
                $sottoconti[] = $id;
            }
            array_push($daVedere, ...($raggiunti[$id] ?? []));
        }

        return ['capitoli' => $capitoli, 'sottoconti' => $sottoconti];
    }

    /**
     * Le voci del piano dei conti come le legge {@see sovrapposteAlPiano()}: i padri, i sottoconti a cui il motore passa la quota di ognuna (nessuno se la
     * voce ha tabelle proprie; quelli nati fino alla data del piano, o tutti se nessuno lo è: {@see Conto::sottocontiDelPiano()}) e le voci che hanno una
     * riga nel piano. Senza righe nel piano non c'è niente a cui sovrapporsi.
     *
     * @return array{padri: array<int, int|null>, raggiunti: array<int, list<int>>, nelPiano: list<int>}
     */
    private function perimetroDelPiano(PianoRate $piano, int $pianoContoId): array
    {
        $nelPiano = DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->orderBy('id')->pluck('conto_id')->map(fn ($id) => (int) $id)->all();

        if ($nelPiano === []) {
            return ['padri' => [], 'raggiunti' => [], 'nelPiano' => []];
        }

        $padri = [];
        $raggiunti = [];
        foreach (Conto::query()->where('piano_conto_id', $pianoContoId)->with(['tabelleMillesimali', 'sottoconti'])->get(['id', 'parent_id']) as $voce) {
            $padri[(int) $voce->id] = $voce->parent_id === null ? null : (int) $voce->parent_id;
            $raggiunti[(int) $voce->id] = $voce->tabelleMillesimali->isNotEmpty()
                ? []
                : $voce->sottocontiDelPiano($piano->created_at)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return ['padri' => $padri, 'raggiunti' => $raggiunti, 'nelPiano' => $nelPiano];
    }

    /** Che cosa fare quando un capitolo ha la sua riga insieme a quelle dei suoi sottoconti: l'ordine è quello che funziona, lo storno prima. */
    private static function uscita(string $capitolo): string
    {
        return 'togli il capitolo «' . $capitolo . '» dal piano con «Rimuovi voce e ricalcola»; se il capitolo ha ricevuto budget con «Sposta spesa», storna prima lo spostamento dallo storico (icona dell\'orologio, accanto all\'importo).';
    }

    /**
     * Una destinazione non deve essere sovrapposta a un capitolo del piano che le passa la quota né a dei sottoconti del piano a cui la passerebbe:
     * che abbia già la sua riga o no. Se ce l'ha (un piano già colpito) il rifiuto dice l'uscita.
     *
     * @throws ValidationException sul campo `destination_id`
     */
    private function controllaDestinazioneNelPiano(PianoRate $piano, int $destId, bool $giaNelPiano): void
    {
        $voce = Conto::query()->find($destId, ['id', 'nome', 'piano_conto_id']);

        if ($voce === null) {
            return;
        }

        $perimetro = $this->perimetroDelPiano($piano, (int) $voce->piano_conto_id);
        $sovrapposte = self::sovrapposteAlPiano($destId, $perimetro['padri'], $perimetro['nelPiano'], $perimetro['raggiunti']);

        if ($sovrapposte['capitoli'] !== []) {
            $capitolo = Conto::query()->with(['tabelleMillesimali', 'sottoconti.tabelleMillesimali'])->findOrFail($sovrapposte['capitoli'][0], ['id', 'nome', 'tipo']);

            if ($giaNelPiano) {
                throw ValidationException::withMessages(['destination_id' => '«' . $voce->nome . '» ha già la sua riga nel piano, ma anche il suo capitolo «' . $capitolo->nome . '» ha la sua: il piano conta solo quella del capitolo, e il budget spostato su «' . $voce->nome . '» non verrebbe contato. Per uscirne ' . self::uscita($capitolo->nome)]);
            }

            // Un capitolo con spese ed entrate insieme non si sposta: indicarlo come strada porterebbe a un altro rifiuto.
            if (self::tipoDelSegno($capitolo, $piano->created_at) === self::TIPO_MISTO) {
                throw ValidationException::withMessages(['destination_id' => self::fraseDelMisto([$capitolo->nome])]);
            }

            throw ValidationException::withMessages(['destination_id' => '«' . $voce->nome . '» non è nel piano, ma lo è il suo capitolo «' . $capitolo->nome . '»: con una riga anche per il sottoconto il piano conterebbe solo quella del capitolo e il budget spostato non verrebbe contato. Sposta il budget sul capitolo «' . $capitolo->nome . '».']);
        }

        if ($sovrapposte['sottoconti'] !== []) {
            $nomi = Conto::query()->whereIn('id', $sovrapposte['sottoconti'])->orderBy('nome')->pluck('nome')->all();

            if ($giaNelPiano) {
                throw ValidationException::withMessages(['destination_id' => count($nomi) === 1
                    ? '«' . $voce->nome . '» ha già la sua riga nel piano, ma anche il suo sottoconto «' . $nomi[0] . '» ha la sua: il piano conta solo quella del capitolo e non più quella del sottoconto, e finché restano così «Sposta spesa» non lavora su queste voci. Per uscirne ' . self::uscita($voce->nome)
                    : '«' . $voce->nome . '» ha già la sua riga nel piano, ma anche i suoi sottoconti ' . self::elencoDiNomi($nomi) . ' hanno la loro: il piano conta solo quella del capitolo e non più quelle dei sottoconti, e finché restano così «Sposta spesa» non lavora su queste voci. Per uscirne ' . self::uscita($voce->nome)]);
            }

            throw ValidationException::withMessages(['destination_id' => count($nomi) === 1
                ? '«' . $voce->nome . '» non è nel piano, ma lo è il suo sottoconto «' . $nomi[0] . '»: con una riga anche per il capitolo il piano conterebbe solo quella e non più la riga del sottoconto. Sposta il budget sul sottoconto.'
                : '«' . $voce->nome . '» non è nel piano, ma lo sono i suoi sottoconti ' . self::elencoDiNomi($nomi) . ': con una riga anche per il capitolo il piano conterebbe solo quella e non più le righe dei sottoconti. Sposta il budget su uno dei sottoconti.']);
        }
    }

    /** «A», «A» e «B», «A», «B» e «C»; oltre tre, i primi tre e quanti sono gli altri. */
    private static function elencoDiNomi(array $nomi): string
    {
        $tra = fn (array $voci) => count($voci) === 1 ? '«' . $voci[0] . '»' : '«' . implode('», «', array_slice($voci, 0, -1)) . '» e «' . end($voci) . '»';

        if (count($nomi) <= 3) {
            return $tra($nomi);
        }

        $altri = count($nomi) - 3;

        return '«' . implode('», «', array_slice($nomi, 0, 3)) . '» e ' . ($altri === 1 ? 'un altro' : 'altri ' . $altri);
    }

    /**
     * Le voci che la pagina del piano offre come destinazione, col tipo calcolato dal segno. Non si offre una voce sovrapposta a un capitolo del piano o a
     * dei sottoconti del piano, anche se ha già la sua riga: il server la rifiuterebbe ({@see controllaDestinazioneNelPiano()}).
     *
     * @return Collection<int, array{id: int, nome: string, tipo: string}>
     */
    public function destinazioniPerLaPagina(PianoRate $piano, int $pianoContoId): Collection
    {
        $voci = Conto::query()
            ->where('piano_conto_id', $pianoContoId)
            ->orderBy('nome')
            ->visibili()
            ->with(['tabelleMillesimali', 'sottoconti.tabelleMillesimali'])
            ->get(['id', 'nome', 'tipo']);

        $perimetro = $this->perimetroDelPiano($piano, $pianoContoId);

        return $voci
            ->reject(function (Conto $c) use ($perimetro) {
                $sovrapposte = self::sovrapposteAlPiano($c->id, $perimetro['padri'], $perimetro['nelPiano'], $perimetro['raggiunti']);

                return $sovrapposte['capitoli'] !== [] || $sovrapposte['sottoconti'] !== [];
            })
            ->map(fn (Conto $c) => ['id' => $c->id, 'nome' => $c->nome, 'tipo' => self::tipoDelSegno($c, $piano->created_at)])
            ->values();
    }

    /**
     * L'avviso della pagina del piano: i capitoli che hanno la loro riga insieme a quelle dei loro sottoconti. Il motore passa la quota del capitolo ai
     * sottoconti e salta la loro riga, quindi il piano chiede la somma di tutte e ne ripartisce meno, e «Ricalcola» non lo corregge. Si dice come uscirne.
     * Un capitolo dentro un altro che ha la sua riga non si elenca: si comincia dal più in alto.
     *
     * @return array{titolo: string, voci: list<string>, situazione: string, strade: list<string>}|null null se nel piano non c'è niente di sovrapposto
     */
    public function avvisoRigheSovrapposte(PianoRate $piano, int $pianoContoId): ?array
    {
        $perimetro = $this->perimetroDelPiano($piano, $pianoContoId);

        $capitoli = [];
        foreach ($perimetro['nelPiano'] as $id) {
            $sovrapposte = self::sovrapposteAlPiano($id, $perimetro['padri'], $perimetro['nelPiano'], $perimetro['raggiunti']);

            if ($sovrapposte['capitoli'] === [] && $sovrapposte['sottoconti'] !== []) {
                $capitoli[$id] = $sovrapposte['sottoconti'];
            }
        }

        if ($capitoli === []) {
            return null;
        }

        $nomi = Conto::query()->whereIn('id', array_merge(array_keys($capitoli), ...array_values($capitoli)))->pluck('nome', 'id');
        $voci = [];
        $strade = [];
        foreach ($capitoli as $id => $sottoconti) {
            $suoi = $nomi->only($sottoconti)->sort()->values()->all();
            $voci[] = 'Il capitolo «' . $nomi[$id] . '» ha la sua riga e '
                . (count($suoi) === 1 ? 'anche il suo sottoconto ' . self::elencoDiNomi($suoi) . ' ha la sua' : 'anche i suoi sottoconti ' . self::elencoDiNomi($suoi) . ' hanno la loro')
                . ': il piano conta solo quella del capitolo.';
            $strade[] = ucfirst(self::uscita($nomi[$id]));
        }

        return [
            'titolo' => count($capitoli) === 1
                ? 'Nel piano un capitolo ha la sua riga insieme a quelle dei suoi sottoconti'
                : 'Nel piano ' . count($capitoli) . ' capitoli hanno la loro riga insieme a quelle dei loro sottoconti',
            'voci' => $voci,
            'situazione' => 'La somma delle righe non è quella che il piano ripartisce: «Ricalcola» non lo corregge, e «Sposta spesa» non offre queste voci come destinazione.',
            'strade' => $strade,
        ];
    }

    /**
     * Storna un movimento: crea quello uguale e contrario, non tocca né cancella l'originale.
     *
     * Stessa filosofia della contabilità di tutto il progetto — non si cancella, si rettifica.
     * Passa dalla stessa `moveBudget()`, quindi eredita lo stesso controllo sullo speso: uno
     * storno che lascerebbe scoperta la voce che aveva ricevuto i fondi si blocca allo stesso modo.
     *
     * @throws ValidationException se il movimento è già stato stornato, o è esso stesso uno storno.
     */
    public function reverseMovement(BudgetMovement $movement, int $userId): BudgetMovement
    {
        if ($movement->reverses_movement_id !== null) {
            throw ValidationException::withMessages(['movement' => 'Non si può stornare uno storno: annullerebbe di nuovo l\'originale invece di lasciare la catena leggibile.']);
        }

        $giaStornato = BudgetMovement::where('reverses_movement_id', $movement->id)->exists();

        if ($giaStornato) {
            throw ValidationException::withMessages(['movement' => 'Questo movimento è già stato stornato.']);
        }

        $piano = $movement->pianoRate;
        $reason = 'Storno del movimento del ' . $movement->created_at->format('d/m/Y')
            . ($movement->reason ? " (\"{$movement->reason}\")" : '');

        try {
            return $this->moveBudget(
                $piano,
                $movement->destination_conto_id,
                $movement->source_conto_id,
                $movement->amount,
                $reason,
                $userId,
                reversesMovementId: $movement->id,
            );
        } catch (QueryException $e) {
            // Il SELECT sopra non basta da solo: due richieste quasi simultanee sullo stesso
            // movimento possono superarlo entrambe. L'indice unico su reverses_movement_id è il
            // vero guard finale — qui lo trasformiamo in un errore leggibile invece di un 500.
            if ($this->isDoppioStornoViolation($e)) {
                throw ValidationException::withMessages(['movement' => 'Questo movimento è già stato stornato.']);
            }

            throw $e;
        }
    }

    /**
     * Verifica se una QueryException è la violazione dell'indice unico su reverses_movement_id.
     */
    private function isDoppioStornoViolation(QueryException $e): bool
    {
        $isDuplicate = ($e->errorInfo[1] ?? null) === 1062
            || ($e->errorInfo[0] ?? null) === '23000';

        return $isDuplicate && str_contains($e->getMessage(), 'reverses_movement_id');
    }

    /**
     * Quanto è già stato speso su questa voce, letto dallo stesso posto del cruscotto.
     *
     * Nessun esercizio risolvibile (capita nei piani rate di test, o su una gestione senza
     * esercizio collegato) → nessuno speso conosciuto, non un blocco: è la stessa scelta che fa
     * il resto del progetto quando un dato non è disponibile, non un'assunzione ottimistica.
     */
    private function spesoRegistratoSu(PianoRate $piano, int $contoId): int
    {
        $esercizio = $piano->gestione->esercizi()->wherePivot('attiva', true)->first()
            ?? $piano->gestione->esercizi()->first();

        if (! $esercizio instanceof Esercizio) {
            return 0;
        }

        return max(0, $this->spesaPerVoceService->perEsercizio($esercizio, [$contoId])[$contoId] ?? 0);
    }
}
