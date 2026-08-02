<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Helpers\MoneyHelper; 
use Illuminate\Support\Facades\DB;

class SaldoEsercizioService
{
    /**
     * Quanto pregresso questa gestione ha ancora da far assorbire da un piano.
     *
     * La domanda è «esistono saldi LIBERI?», non «il flag di gestione è acceso?».
     * Sembra la stessa cosa e non lo è: dalla beta.32 `gestioni.saldo_applicato`
     * è un derivato che `allineaFlagGestione()` accende con un `exists()`, cioè
     * appena UN saldo risulta bloccato. Usarlo come gate — com'era fino alla
     * beta.34 — significava che il primo piano emesso rendeva inassorbibili
     * tutti i saldi rimasti liberi, compresi quelli inseriti dopo.
     *
     * È l'ultimo posto in cui sopravviveva il «troppo largo» della beta.33: là
     * il pannello si sbloccava riga per riga, qui la creazione del piano
     * continuava a ragionare per gestione. L'effetto era che una correzione
     * trovata a marzo si poteva registrare ma non chiedere più a nessuno.
     *
     * Il numero proposto deve coincidere con quello che il piano assorbirebbe
     * davvero, quindi applica gli stessi due filtri di `GenerateSaldiAction`:
     * righe libere e `fornitore_id` nullo — i debiti verso fornitori vivono
     * nella stessa tabella ma non entrano mai in un piano rate.
     */
    public function calcolaSaldoApplicabile(Gestione $gestione): array
    {
        $queryLiberi = DB::table('saldi')
            ->where('gestione_id', $gestione->id)
            ->where('is_applicato', false)
            ->whereNull('fornitore_id');

        $saldoInCentesimi = (int) $queryLiberi->sum('saldo_iniziale');
        $haMovimenti = (clone $queryLiberi)->where('saldo_iniziale', '!=', 0)->exists();

        if ($haMovimenti) {
            return [
                'saldo' => $saldoInCentesimi,
                'has_movimenti' => true,
                'applicabile' => true,
                'motivo' => 'Saldi pregressi rilevati nel wallet per questa gestione.',
                'is_primo_anno' => false,
            ];
        }

        // Nessuna riga libera. Restano due situazioni molto diverse, e dirle
        // con lo stesso messaggio è ciò che confondeva: «non ce n'erano mai
        // stati» e «ci sono, ma li ha già presi un piano».
        $giaAssorbiti = DB::table('saldi')
            ->where('gestione_id', $gestione->id)
            ->where('is_applicato', true)
            ->whereNull('fornitore_id')
            ->exists();

        if ($giaAssorbiti) {
            return [
                'saldo' => 0,
                'has_movimenti' => false,
                'applicabile' => false,
                'motivo' => "I saldi pregressi di questa gestione sono già stati integrati in un piano rate. "
                          . "Per chiederne altri, registrali prima nei Saldi Iniziali della gestione \"{$gestione->nome}\".",
                'is_primo_anno' => false,
            ];
        }

        return [
            'saldo' => 0,
            'has_movimenti' => false,
            'applicabile' => true,
            'motivo' => 'Nessun saldo pregresso da elaborare per questa gestione.',
            'is_primo_anno' => true,
        ];
    }
    
    /**
     * Allinea il lucchetto a ciò che il piano rate contiene DAVVERO, dopo ogni
     * generazione o rigenerazione delle rate.
     *
     * È l'unico punto in cui un piano chiude il lucchetto, e lo chiude a proprio
     * nome (`saldi.piano_rate_id`). L'invariante che mantiene è: i saldi
     * intestati a un piano sono esattamente quelli finiti nelle sue quote —
     * così un ricalcolo che assorbe saldi diversi (o nessuno) non lascia
     * lucchetti orfani dietro di sé.
     *
     * @param int[] $saldiConsumatiIds Le righe della tabella `saldi` realmente
     *                                 finite nelle quote appena generate.
     */
    public function sincronizzaLucchetti(PianoRate $pianoRate, Gestione $gestione, array $saldiConsumatiIds, ?int $saldoTotale = null): void
    {
        $consumati = array_values(array_unique(array_map('intval', $saldiConsumatiIds)));

        // 1. Rilascia i saldi che questo piano teneva bloccati ma che non
        //    compaiono più nelle sue quote (tipicamente dopo un ricalcolo).
        Saldo::where('piano_rate_id', $pianoRate->id)
            ->when(!empty($consumati), fn ($q) => $q->whereNotIn('id', $consumati))
            ->update(['is_applicato' => false, 'piano_rate_id' => null]);

        // 2. Blocca (o riconferma) i saldi assorbiti, intestandoli a questo piano.
        //    Il filtro sui fornitori è ridondante oggi (non entrano nella
        //    generazione) ma è la stessa difesa applicata in ogni altro punto:
        //    un debito verso terzi non può finire intestato a un piano rate.
        if (!empty($consumati)) {
            Saldo::whereIn('id', $consumati)
                ->whereNull('fornitore_id')
                ->update(['is_applicato' => true, 'piano_rate_id' => $pianoRate->id]);
        }

        $this->allineaFlagGestione($gestione, $saldoTotale);
    }

    /**
     * Riapre il lucchetto tenuto da un piano rate che sta per essere eliminato.
     * Tocca solo i saldi intestati a quel piano: i debiti verso fornitori, che
     * vivono nella stessa tabella con is_applicato=true per restare fuori dai
     * piani rate, non sono mai stati intestati a nessuno e restano chiusi.
     */
    public function rilasciaLucchetti(PianoRate $pianoRate, ?Gestione $gestione = null): int
    {
        $rilasciati = Saldo::where('piano_rate_id', $pianoRate->id)
            ->update(['is_applicato' => false, 'piano_rate_id' => null]);

        if ($gestione) {
            $this->allineaFlagGestione($gestione);
        }

        return $rilasciati;
    }

    /**
     * Il flag di gestione è un derivato, non una decisione: è acceso finché
     * esiste almeno un saldo di condòmino bloccato su quella gestione.
     */
    public function allineaFlagGestione(Gestione $gestione, ?int $saldoTotale = null): void
    {
        $restaBloccato = Saldo::where('gestione_id', $gestione->id)
            ->where('is_applicato', true)
            ->whereNull('fornitore_id')
            ->exists();

        // Se il lucchetto resta chiuso ma non stiamo processando un nuovo
        // importo, la nota esistente descrive ancora la situazione: riscriverla
        // con uno zero inventato la renderebbe falsa.
        $nota = match (true) {
            !$restaBloccato        => null,
            $saldoTotale !== null  => $this->notaSaldo($saldoTotale),
            default                => $gestione->nota_saldo,
        };

        $gestione->update([
            'saldo_applicato' => $restaBloccato,
            'nota_saldo'      => $nota,
        ]);
    }

    private function notaSaldo(int $saldoApplicato): string
    {
        $importoFormattato = ($saldoApplicato > 0 ? '+' : '') . MoneyHelper::format($saldoApplicato);

        return sprintf(
            "Saldo Netto %s processato il %s",
            $importoFormattato,
            now()->format('d/m/Y H:i')
        );
    }
}