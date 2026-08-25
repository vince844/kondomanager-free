<?php

namespace App\Services;

use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use Recurr\Rule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PianoRateCreatorService
{
    /**
     * Il giorno del mese in cui scadono le rate quando l'amministratore non lo indica.
     *
     * Deve restare uguale al `default(5)` della migrazione di `piani_rate`: sono due copie
     * dello stesso numero, e il test `CalendarioRateBonificheTest` le tiene agganciate.
     */
    public const GIORNO_SCADENZA_PREDEFINITO = 5;

    public function verificaGestione(int $gestioneId): Gestione
    {
        $gestione = Gestione::with(['pianoConto.conti', 'esercizi'])->findOrFail($gestioneId);

        if (!$gestione->pianoConto) {
            throw new RuntimeException("The selected management (gestione) has no linked chart of accounts (piano conti).");
        }
        if (!$gestione->data_inizio) {
            throw new RuntimeException("The selected management (gestione) has no defined start date.");
        }

        return $gestione;
    }

    public function creaPianoRate(array $data, Condominio $condominio): PianoRate
    {
        $attributi = [
            'gestione_id'          => $data['gestione_id'],
            'condominio_id'        => $condominio->id,
            'nome'                 => $data['nome'],
            'descrizione'          => $data['descrizione'] ?? null,
            'metodo_distribuzione' => $data['metodo_distribuzione'] ?? 'prima_rata',
            'numero_rate'          => $data['numero_rate'],
            'note'                 => $data['note'] ?? null,
            'attivo'               => true,
            // NULL non è un dato mancante: è la scelta di far partire il piano dall'inizio
            // della gestione, cioè il comportamento di sempre. Vedi
            // `PianoRate::dataPartenzaCalendario()`.
            'data_prima_scadenza'  => $data['data_prima_scadenza'] ?? null,
        ];

        // Qui c'era `$data['giorno_scadenza'] ?? 1`, e quell'1 non lasciava mai parlare il
        // default dichiarato dallo schema — `integer('giorno_scadenza')->default(5)`,
        // `2025_11_05_093141_create_piani_rate_table.php:21`. Chi non sceglieva il giorno
        // otteneva un piano che scade il **primo** del mese invece che il cinque.
        //
        // Il valore si scrive esplicitamente e non si lascia al database, benché il default
        // ci sia: omettendo la chiave, il record a database nasce giusto ma **l'oggetto in
        // memoria non lo sa** — Eloquent non rilegge dopo l'insert, e chiunque legga
        // `giorno_scadenza` sul modello appena creato prende `null`. Il default resta in
        // migrazione come rete per gli insert che non passano di qui.
        $attributi['giorno_scadenza'] = $data['giorno_scadenza'] ?? self::GIORNO_SCADENZA_PREDEFINITO;

        return PianoRate::create($attributi);
    }

    public function creaRicorrenza(PianoRate $pianoRate, array $data): void
    {
        $gestione = $pianoRate->gestione;

        // Terzo e ultimo punto in cui la partenza era cablata sull'inizio della gestione.
        // La `rrule` va costruita sulla data vera, non solo spostata dopo: `setStartDate`
        // determina anche da quale mese il ricorrente comincia a contare.
        $partenza = $pianoRate->dataPartenzaCalendario();
        $start    = new \DateTime(
            $partenza ? $partenza->toDateString() : $gestione->data_inizio,
            new \DateTimeZone('Europe/Rome')
        );

        $frequency = strtoupper($data['recurrence_frequency']);
        $interval  = max(1, (int)($data['recurrence_interval'] ?? 1));
        $byDay     = $data['recurrence_by_day'] ?? [];
        $giorno    = $data['giorno_scadenza'] ?? $pianoRate->giorno_scadenza;

        $rule = (new Rule())
            ->setStartDate($start)
            ->setFreq($frequency)
            ->setInterval($interval)
            ->setCount($pianoRate->numero_rate);

        $bySetPos      = null;
        $byMonthDayVal = null;

        if ($frequency === 'WEEKLY' && !empty($byDay)) {
            $rule->setByDay($byDay);
        }

        elseif ($frequency === 'MONTHLY') {

            if (!empty($byDay)) {
                $rule->setByDay($byDay);
                $rule->setBySetPosition([1]); // primo giorno utile nel mese
                $bySetPos = 1;

            } else {
                if ($giorno >= 29) {
                    $rule->setByMonthDay([-1]); // ultimo giorno del mese
                    $byMonthDayVal = -1;
                } else {
                    $rule->setByMonthDay([$giorno]);
                    $byMonthDayVal = $giorno;
                }
            }
        }

        if (!empty($data['recurrence_until'])) {
            $until = new \DateTime($data['recurrence_until'], new \DateTimeZone('Europe/Rome'));
            $rule->setUntil($until);
        }

        $pianoRate->ricorrenza()->create([
            'frequency'     => strtolower($frequency),
            'interval'      => $interval,
            'by_day'        => !empty($byDay) ? $byDay : null,
            'by_month_day'  => $byMonthDayVal,
            'by_set_pos'    => $bySetPos,
            'until'         => $data['recurrence_until'] ?? null,
            'rrule'         => $rule->getString(),
            'timezone'      => 'Europe/Rome',
        ]);
    }

    /**
     * Verifica che i capitoli selezionati non siano già presenti in altri piani rate ATTIVI.
     */
    public function convalidaCapitoliUnici(int $gestioneId, array $capitoliIds): void
    {
        if (empty($capitoliIds)) return;

        // 1. Controllo se esiste un piano globale attivo (senza capitoli in pivot)
        $globale = PianoRate::where('gestione_id', $gestioneId)
            ->where('attivo', true)
            ->whereDoesntHave('capitoli')
            ->exists();

        if ($globale) {
            throw new RuntimeException("Esiste già un piano rate attivo che include tutte le spese. Impossibile creare piani parziali.");
        }

        // 2. Controllo Overlap Gerarchico
        // Recuperiamo TUTTI i conti già impegnati in piani attivi per questa gestione
        $contiImpegnatiIds = DB::table('piano_rate_capitoli')
            ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
            ->where('piani_rate.gestione_id', $gestioneId)
            ->where('piani_rate.attivo', true)
            ->pluck('conto_id')
            ->toArray();

        foreach ($capitoliIds as $id) {
            $conto = Conto::with(['parent', 'sottoconti'])->findOrFail($id);
            
            // Calcoliamo l'intero ramo (Lui + Antenati + Discendenti)
            $ramo = array_merge([$id], $conto->getAllChildrenIds(), $conto->getAllAncestorsIds());

            // Se uno qualsiasi di questi ID è già impegnato, abbiamo un conflitto
            $intersezione = array_intersect($ramo, $contiImpegnatiIds);

            if (!empty($intersezione)) {
                // Recuperiamo il nome del primo conflitto per l'errore
                $nomeConflitto = Conto::where('id', reset($intersezione))->value('nome');
                throw new RuntimeException("Conflitto gerarchico: la voce '{$conto->nome}' è legata a '{$nomeConflitto}', che è già presente in un altro piano.");
            }
        }
    }
}
