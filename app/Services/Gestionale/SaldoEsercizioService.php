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
     * V1.9: Calcola i saldi basandosi esclusivamente sulla GESTIONE.
     */
    public function calcolaSaldoApplicabile(Gestione $gestione): array 
    {
        // 1. Verifica Blocco sulla gestione specifica
        if ($gestione->saldo_applicato) {
            return [
                'saldo' => 0,
                'has_movimenti' => false,
                'applicabile' => false,
                'motivo' => "I saldi pregressi sono già stati integrati nel piano rate della gestione \"{$gestione->nome}\".",
            ];
        }
        
        $saldoInCentesimi = 0;
        $haMovimenti = false; 
        $motivo = "";
        $isPrimoAnno = false;

        // 2. Interroga direttamente la nuova tabella saldi (Wallet) isolando la gestione_id
        $queryManuale = DB::table('saldi')
            ->where('gestione_id', $gestione->id)
            ->where('is_applicato', false);

        $saldoInCentesimi = (int) $queryManuale->sum('saldo_iniziale');
        
        // Verifica se esistono righe con importi reali
        $haMovimenti = (clone $queryManuale)->where('saldo_iniziale', '!=', 0)->exists();
        
        if ($haMovimenti) {
            $motivo = "Saldi pregressi rilevati nel wallet per questa gestione.";
        } else {
            $motivo = "Nessun saldo pregresso da elaborare per questa gestione.";
            $isPrimoAnno = true;
        }

        return [
            'saldo' => $saldoInCentesimi,
            'has_movimenti' => $haMovimenti,
            'applicabile' => true, 
            'motivo' => $motivo,
            'is_primo_anno' => $isPrimoAnno
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