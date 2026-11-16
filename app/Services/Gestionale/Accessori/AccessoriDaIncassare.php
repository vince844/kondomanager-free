<?php

namespace App\Services\Gestionale\Accessori;

use App\Enums\ContoContabileCategoria;
use App\Enums\ContoContabileTipo;
use App\Exceptions\Gestionale\VoceDelVersamentoMancanteException;
use App\Helpers\MoneyHelper;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ContoTabellaMillesimale;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use Illuminate\Support\Facades\DB;

/**
 * Il denaro degli interessi e delle spese addebitati a una persona senza una fattura: del condominio quando è versato.
 *
 * - **Alla registrazione** l'emissione scrive DARE «Crediti verso condomini» con la persona, l'unità e la rata, AVERE «Accessori da incassare» (un conto
 *   di sistema fra le passività, nato alla prima richiesta): il credito c'è, e la sua contropartita; le spese di nessuno cambiano, e un interesse che la
 *   persona non paga non riduce le spese di nessuno. Non va su «Gestione rate», che il rendiconto legge come le quote emesse.
 * - **Al versamento**, con l'incasso, per la parte che il versamento copre: DARE «Accessori da incassare», AVERE il mastro della voce scelta
 *   dall'amministratore, con la voce. È la forma dell'incasso da terzi, scritta quando il denaro c'è. Lo storno dell'incasso la rovescia con il resto
 *   della scrittura.
 *
 * Le righe della voce di queste scritture si riconoscono dal conto «Accessori da incassare» nella stessa scrittura ({@see coppia()}): il consuntivo per
 * voce le dice «versato dai condòmini per interessi e spese», fuori dalle entrate da terzi.
 *
 * La spesa per il ritardo con la sua fattura sull'unità non passa di qui: l'emissione di sempre chiude l'anticipazione, con la riga del riparto.
 */
final class AccessoriDaIncassare
{
    public const RUOLO = 'accessori_da_incassare';

    public const NOME = 'Accessori da incassare';

    /** Il conto «Accessori da incassare» del condominio; nasce alla prima richiesta, fra le passività, come gli altri conti di sistema. */
    public static function conto(int $condominioId): ContoContabile
    {
        $esiste = ContoContabile::where('condominio_id', $condominioId)->where('ruolo', self::RUOLO)->whereNull('deleted_at')->first();
        if ($esiste !== null) {
            return $esiste;
        }
        $passivo = ContoContabile::where('condominio_id', $condominioId)->where('codice', '2000')->whereNull('parent_id')->value('id');
        // Il codice: 2102, accanto agli anticipi; se un conto dell'amministratore lo ha già, il primo libero dopo.
        $codice = '2102';
        for ($n = 1; ContoContabile::withTrashed()->where('condominio_id', $condominioId)->where('codice', $codice)->exists(); $n++) {
            $codice = '2102.' . $n;
        }

        return ContoContabile::create([
            'condominio_id' => $condominioId,
            'parent_id' => $passivo,
            'codice' => $codice,
            'nome' => self::NOME,
            'descrizione' => 'Interessi di mora e spese addebitati a singole persone e non ancora versati: diventano del condominio quando sono versati.',
            'tipo' => ContoContabileTipo::PASSIVO->value,
            'categoria' => ContoContabileCategoria::FONDI->value,
            'ruolo' => self::RUOLO,
            'di_sistema' => true,
            'attivo' => true,
            'livello' => 1,
        ]);
    }

    /**
     * Il predicato delle scritture con una riga su «Accessori da incassare», da usare dentro un `whereExists` su `sc`: le righe con la voce di queste
     * scritture sono il versato per interessi e spese.
     */
    public static function coppia($sub)
    {
        return $sub->select(DB::raw(1))
            ->from('righe_scritture as rx')
            ->join('conti_contabili as cx', 'cx.id', '=', 'rx.conto_contabile_id')
            ->whereColumn('rx.scrittura_id', 'sc.id')
            ->where('cx.ruolo', self::RUOLO);
    }

    /**
     * Le coppie del versamento di un incasso: per ogni accessorio senza fattura che l'incasso (e la sua compensazione) ha toccato, la parte che ora è
     * coperta e prima no va da «Accessori da incassare» alla voce. Si scrivono nella scrittura dell'incasso, che lo storno rovescia per intero. La parte
     * di un versamento oltre l'accessorio (un credito sulla sua rata) non è sua, e il credito preso da lui per pagare altro non lo scopre.
     *
     * @param list<int> $scritture la scrittura dell'incasso e le sue figlie che hanno toccato le quote
     */
    public function scriviIlVersamento(ScritturaContabile $incasso, array $scritture): void
    {
        $perQuota = DB::table('quota_scrittura')->whereIn('scrittura_contabile_id', $scritture)->groupBy('rate_quota_id')
            ->selectRaw('rate_quota_id, SUM(importo_pagato) as importo')->pluck('importo', 'rate_quota_id');
        if ($perQuota->isEmpty()) {
            return;
        }
        $quote = RataQuote::whereIn('id', $perQuota->keys()->all())->where('tipo', RataQuote::TIPO_ACCESSORIO)->with('rata')->get();
        $conto = null;
        $esercizio = null;
        foreach ($quote as $q) {
            $dati = AddebitiIndividuali::dati($q);
            if (($dati['riga_fattura_id'] ?? null) !== null || ! isset($dati['conto_id'])) {
                continue;
            }
            $importo = (int) $q->importo;
            $ora = (int) $q->fresh()->importo_pagato;
            $prima = $ora - (int) $perQuota[$q->id];
            $parte = min($importo, max(0, $ora)) - min($importo, max(0, $prima));
            if ($parte === 0) {
                continue;
            }
            $conto ??= self::conto((int) $incasso->condominio_id);
            $esercizio ??= Esercizio::find($incasso->esercizio_id);
            $voce = $this->voceNellEsercizio((int) $dati['conto_id'], $esercizio, $q, $parte);
            if ($voce === null) {
                continue;
            }
            $nota = trim((string) ($q->rata?->descrizione ?? 'Addebito')) . ($parte > 0 ? ' — versato' : ' — tolto dal versato');
            foreach ([[$conto->id, null, $parte > 0 ? 'dare' : 'avere'], [$voce->conto_contabile_id, $voce->id, $parte > 0 ? 'avere' : 'dare']] as [$contoId, $voceId, $verso]) {
                $incasso->righe()->create([
                    'conto_contabile_id' => $contoId,
                    'voce_spesa_id' => $voceId,
                    'anagrafica_id' => $q->anagrafica_id,
                    'tipo_riga' => $verso,
                    'importo' => abs($parte),
                    'note' => $nota,
                ]);
            }
        }
    }

    /**
     * La voce che riceve il versamento: quella scelta all'addebito, se la sua gestione è dell'esercizio del versamento; altrimenti la voce con lo stesso
     * nome e lo stesso tipo in una gestione dello stesso tipo di quell'esercizio (l'interesse del 2026 versato nel 2027 va all'«Interessi di mora»
     * dell'ordinaria del 2027, dove il rendiconto del 2027 lo legge e lo divide). Se quella voce non c'è, nasce nel piano dei conti della gestione, con
     * il mastro e le tabelle della voce scelta; se il piano dei conti non c'è ancora, l'incasso non si registra e la frase lo dice.
     *
     * @param RataQuote|null $quota l'addebito versato, per la frase
     * @param int $parte i centesimi versati, per la frase
     *
     * @throws VoceDelVersamentoMancanteException
     */
    public function voceNellEsercizio(int $voceId, ?Esercizio $esercizio, ?RataQuote $quota = null, int $parte = 0): ?Conto
    {
        $voce = Conto::with('pianoConto.gestione')->find($voceId);
        if ($voce === null || $voce->conto_contabile_id === null || $esercizio === null) {
            return $voce;
        }
        $gestioni = DB::table('esercizio_gestione')->where('esercizio_id', $esercizio->id)->pluck('gestione_id')->map(fn ($id) => (int) $id)->all();
        if (in_array((int) $voce->pianoConto?->gestione_id, $gestioni, true)) {
            return $voce;
        }
        $tipo = $voce->pianoConto?->gestione?->tipo;
        $gemella = Conto::query()->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')->join('gestioni as g', 'g.id', '=', 'pc.gestione_id')
            ->whereIn('pc.gestione_id', $gestioni)->where('g.tipo', $tipo)->whereRaw('LOWER(conti.nome) = ?', [mb_strtolower(trim((string) $voce->nome))])
            ->where('conti.tipo', $voce->tipo)->whereNotNull('conti.conto_contabile_id')->orderBy('conti.id')->first(['conti.*']);
        if ($gemella !== null) {
            return $gemella;
        }
        $piano = PianoConto::query()->join('gestioni as g', 'g.id', '=', 'piani_conti.gestione_id')->whereIn('piani_conti.gestione_id', $gestioni)
            ->where('g.tipo', $tipo)->where('piani_conti.condominio_id', $voce->pianoConto?->condominio_id)->orderBy('piani_conti.id')->first(['piani_conti.*']);
        if ($piano === null) {
            throw new VoceDelVersamentoMancanteException(sprintf('I %s di «%s» vanno alla voce «%s» di una gestione di tipo «%s» dell\'esercizio «%s», che non ha ancora il '
                . 'piano dei conti: crealo, poi registra l\'incasso.', MoneyHelper::format($parte), trim((string) ($quota?->rata?->descrizione ?? 'un addebito')),
                trim((string) $voce->nome), (string) $tipo, (string) $esercizio->nome));
        }

        return $this->gemellaNuova($voce, $piano, $esercizio);
    }

    /** La voce con lo stesso nome nel piano dei conti dato: lo stesso mastro, lo stesso tipo, le stesse tabelle con le loro ripartizioni; nessun importo. */
    private function gemellaNuova(Conto $voce, PianoConto $piano, Esercizio $esercizio): Conto
    {
        $nuova = $voce->replicate(['piano_conto_id', 'parent_id', 'importo', 'descrizione']);
        $nuova->fill([
            'piano_conto_id' => $piano->id,
            'parent_id' => null,
            'importo' => 0,
            'descrizione' => 'Nata con il versamento di interessi o spese addebitati in un altro esercizio: la voce «' . trim((string) $voce->nome)
                . '» per il rendiconto di «' . $esercizio->nome . '».',
        ]);
        $nuova->save();
        foreach (ContoTabellaMillesimale::with('ripartizioni')->where('conto_id', $voce->id)->get() as $legame) {
            $copia = $legame->replicate(['conto_id']);
            $copia->conto_id = $nuova->id;
            $copia->save();
            foreach ($legame->ripartizioni as $r) {
                $ripartizione = $r->replicate(['conto_tabella_millesimale_id']);
                $ripartizione->conto_tabella_millesimale_id = $copia->id;
                $ripartizione->save();
            }
        }

        return $nuova;
    }
}
