<?php

namespace App\Services\Gestionale;

use App\Helpers\MoneyHelper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Il pagato alla data D di una fattura, e come si divide fra le parti del suo costo. Una funzione sola, con due perimetri: le fatture la cui
 * scrittura di competenza è di un esercizio (il riepilogo per competenza: il «da pagare» delle spese dell'esercizio) oppure tutte le fatture con
 * la scrittura di competenza datata entro D (la situazione patrimoniale: le fatture aperte alla data, di ogni esercizio). Le due cifre non sono
 * lo stesso fatto e non si confrontano.
 *
 * LA REGOLA.
 * - Il pagato al fornitore: le allocazioni `pagamento` e `compensazione` della fattura con la scrittura datata entro D (lo storno aggiunge
 *   allocazioni negative con la sua data, quindi il pagato a una data non cambia dopo), con il segno del documento: una nota di credito
 *   (netto a pagare negativo) ha il pagato negativo.
 * - La ritenuta: è pagata quando il versamento F24 che la contiene è datato entro D (e non è stornato entro D). Il legame va dalla riga dell'F24
 *   al pagamento; per un pagamento su più fatture la ritenuta di ciascuna è la sua quota, con la regola della ritenuta del pagamento
 *   (`PagamentoFornitoreService::ritenutaDellaQuota`). Fino ad allora sta nel «da pagare», e la colonna «di cui ritenute da versare» la dice.
 * - Le parti del costo: le righe della scrittura di competenza fuori dai debiti verso fornitori e verso l'Erario, per voce; senza voce, su un
 *   conto di costo (le «Spese bancarie» di sistema a parte) o su un altro conto (la parte di una sola unità, le passate gestioni). Il pagato di
 *   una fattura su più parti, pagata in parte, si divide fra le parti in proporzione al loro costo, con il centesimo di `MoneyHelper::ripartisciPerQuote`:
 *   un pagamento non dice quale voce paga, e la pagina lo scrive.
 * - Il residuo verso il fornitore (la situazione patrimoniale): il netto a pagare, in valore assoluto, meno le stesse allocazioni entro D; è il
 *   debito della fattura sul conto «Debiti verso fornitori», al netto della ritenuta (la ritenuta sta sull'Erario dalla registrazione). La ritenuta
 *   del residuo: la ritenuta della parte ancora aperta, con la regola della quota del pagamento (`ritenutaDellaQuota` sul residuo), cioè quella che il
 *   pagamento del resto tratterrà; con il segno del documento, perché una nota di credito con la sua ritenuta la toglie dall'Erario. Una parte chiusa
 *   con una nota di credito non è più aperta, e la sua ritenuta non c'è più.
 */
final class PagatoAllaData
{
    /** La chiave della parte di costo senza voce su un conto di costo, che non è la commissione. */
    public const COSTO_SENZA_VOCE = 'costo';

    /** La chiave della commissione senza voce, sulle «Spese bancarie» di sistema. */
    public const COMMISSIONE_SENZA_VOCE = 'commissione';

    /** La chiave delle parti senza voce che non sono un costo (la parte di una sola unità, le passate gestioni). */
    public const ALTRO = 'altro';

    /**
     * Con `$soloAperte` le sole fatture con il residuo verso il fornitore diverso da zero alla data.
     *
     * @return array<int, array{fattura_id:int, numero:string, fornitore:?string, data:string, esercizio_id:int, gestione_id:?int, contestata:bool, segno:int,
     *     totale:int, pagato:int, da_pagare:int, pagato_in_piu:int, ritenute_da_versare:int, parti: array<int|string, array{costo:int, pagato:int, ritenute_da_versare:int}>,
     *     documento:?string, scadenza:?string, nota_credito:bool, pregressa:bool, stato_pagamento:?string, netto:int, allocato:int, residuo:int, ritenuta:int,
     *     ritenuta_del_residuo:int}>
     */
    public function perFatture(int $condominioId, string $alla, ?int $esercizioId = null, bool $soloAperte = false): array
    {
        $dopo = CarbonImmutable::parse($alla, 'UTC')->addDay()->format('Y-m-d');

        $fatture = DB::table('fatture_passive as fp')
            ->join('fattura_scrittura as fs', fn ($j) => $j->on('fs.fattura_passiva_id', '=', 'fp.id')->where('fs.tipo', 'competenza'))
            ->join('scritture_contabili as sc', fn ($j) => $j->on('sc.id', '=', 'fs.scrittura_contabile_id')->whereNull('sc.deleted_at'))
            ->leftJoin('fornitori as fo', 'fo.id', '=', 'fp.fornitore_id')
            ->where('sc.condominio_id', $condominioId)
            ->when($esercizioId !== null, fn ($q) => $q->where('sc.esercizio_id', $esercizioId), fn ($q) => $q->where('sc.data_competenza', '<', $dopo))
            // Le sole fatture aperte alla data (la situazione patrimoniale): il residuo verso il fornitore diverso da zero, con le stesse allocazioni
            // del pagato. Una fattura chiusa non si legge, e la lettura non cresce con gli anni.
            ->when($soloAperte, fn ($q) => $q->whereRaw('ABS(fp.netto_a_pagare) <> COALESCE((SELECT SUM(fa.importo_allocato) FROM fattura_scrittura fa'
                .' JOIN scritture_contabili sa ON sa.id = fa.scrittura_contabile_id AND sa.deleted_at IS NULL'
                ." WHERE fa.fattura_passiva_id = fp.id AND fa.tipo IN ('pagamento', 'compensazione') AND sa.data_competenza < ?), 0)", [$dopo]))
            ->orderBy('sc.data_competenza')->orderBy('fp.id')
            ->get(['fp.id', 'fp.numero_documento', 'fp.netto_a_pagare', 'fp.importo_ritenuta', 'fp.stato_approvazione', 'fo.ragione_sociale', 'sc.id as competenza_id',
                'sc.esercizio_id', 'sc.data_competenza', 'sc.gestione_id', 'fp.data_documento', 'fp.data_scadenza', 'fp.tipo_documento', 'fp.is_pregresso', 'fp.stato_pagamento'])
            ->unique('id');
        if ($fatture->isEmpty()) {
            return [];
        }
        $ids = $fatture->pluck('id')->map(fn ($id) => (int) $id)->all();

        $parti = $this->partiDelCosto($fatture->pluck('competenza_id')->map(fn ($id) => (int) $id)->all());
        $allocato = DB::table('fattura_scrittura as fs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('sc.id', '=', 'fs.scrittura_contabile_id')->whereNull('sc.deleted_at'))
            ->whereIn('fs.fattura_passiva_id', $ids)->whereIn('fs.tipo', ['pagamento', 'compensazione'])->where('sc.data_competenza', '<', $dopo)
            ->groupBy('fs.fattura_passiva_id')->select('fs.fattura_passiva_id', DB::raw('SUM(fs.importo_allocato) as allocato'))
            ->pluck('allocato', 'fattura_passiva_id');
        $ritenute = $this->ritenuteVersate($fatture, $dopo);

        $esito = [];
        foreach ($fatture as $f) {
            $id = (int) $f->id;
            $segno = (int) $f->netto_a_pagare < 0 ? -1 : 1;
            $partiF = $parti[(int) $f->competenza_id] ?? [];
            $totale = (int) array_sum($partiF);
            $versata = $ritenute[$id] ?? 0;
            $pagato = $segno * (int) ($allocato[$id] ?? 0) + $versata;
            // Il da pagare di una fattura non scende sotto zero: il pagato oltre il costo è «pagato in più», a parte. Una nota di credito resta col suo segno.
            $pagatoInPiu = $segno > 0 ? max(0, $pagato - $totale) : 0;
            $daPagare = $totale - $pagato + $pagatoInPiu;
            $ritenutaDaVersare = $segno > 0 ? max(0, min((int) $f->importo_ritenuta - $versata, $daPagare)) : 0;

            $pagatoPerParte = $partiF === [] ? [] : MoneyHelper::ripartisciPerQuote($pagato, array_map('abs', $partiF));
            $ritenutePerParte = $partiF === [] || $ritenutaDaVersare === 0 ? [] : MoneyHelper::ripartisciPerQuote($ritenutaDaVersare, array_map('abs', $partiF));
            $netto = abs((int) $f->netto_a_pagare);
            $allocatoF = (int) ($allocato[$id] ?? 0);
            $esito[$id] = [
                'fattura_id' => $id, 'numero' => (string) ($f->numero_documento ?? 'FT#'.$id), 'fornitore' => $f->ragione_sociale,
                'data' => substr((string) $f->data_competenza, 0, 10), 'esercizio_id' => (int) $f->esercizio_id,
                'gestione_id' => $f->gestione_id === null ? null : (int) $f->gestione_id,
                'contestata' => $f->stato_approvazione === 'contestata', 'segno' => $segno,
                'totale' => $totale, 'pagato' => $pagato, 'da_pagare' => $daPagare, 'pagato_in_piu' => $pagatoInPiu, 'ritenute_da_versare' => $ritenutaDaVersare,
                'parti' => array_map(fn ($chiave) => ['costo' => $partiF[$chiave], 'pagato' => (int) ($pagatoPerParte[$chiave] ?? 0),
                    'ritenute_da_versare' => (int) ($ritenutePerParte[$chiave] ?? 0)], array_combine(array_keys($partiF), array_keys($partiF))),
                'documento' => $f->data_documento === null ? null : substr((string) $f->data_documento, 0, 10),
                'scadenza' => $f->data_scadenza === null ? null : substr((string) $f->data_scadenza, 0, 10),
                'nota_credito' => $segno < 0, 'pregressa' => (bool) $f->is_pregresso, 'stato_pagamento' => $f->stato_pagamento,
                'netto' => $netto, 'allocato' => $allocatoF, 'residuo' => $netto - $allocatoF, 'ritenuta' => (int) $f->importo_ritenuta,
                'ritenuta_del_residuo' => $segno * PagamentoFornitoreService::ritenutaDellaQuota(abs((int) $f->importo_ritenuta), $netto, max(0, $netto - $allocatoF)),
            ];
        }

        return $esito;
    }

    /**
     * Le parti del costo di ogni scrittura di competenza: per voce, e senza voce per natura del conto. Il segno è quello del costo (dare − avere),
     * quindi negativo per una nota di credito.
     *
     * @param  list<int>  $scrittureIds
     * @return array<int, array<int|string, int>>
     */
    private function partiDelCosto(array $scrittureIds): array
    {
        $esito = [];
        foreach (DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereIn('rs.scrittura_id', $scrittureIds)
            ->where(fn ($q) => $q->whereNull('cc.ruolo')->orWhereNotIn('cc.ruolo', ['debiti_fornitori', 'debiti_erario_ritenute']))
            ->orderBy('rs.id')
            ->get(['rs.scrittura_id', 'rs.voce_spesa_id', 'rs.tipo_riga', 'rs.importo', 'cc.tipo', 'cc.ruolo']) as $r) {
            $chiave = self::parte($r->voce_spesa_id, $r->tipo instanceof \BackedEnum ? $r->tipo->value : $r->tipo, $r->ruolo);
            $esito[(int) $r->scrittura_id][$chiave] = ($esito[(int) $r->scrittura_id][$chiave] ?? 0) + ($r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo);
        }

        return $esito;
    }

    /** La chiave di una parte di costo: la voce, o la natura della riga senza voce. */
    public static function parte(mixed $voceId, ?string $tipoConto, ?string $ruolo): int|string
    {
        if ($voceId !== null) {
            return (int) $voceId;
        }
        if ($ruolo === 'spese_bancarie') {
            return self::COMMISSIONE_SENZA_VOCE;
        }

        return $tipoConto === 'costo' ? self::COSTO_SENZA_VOCE : self::ALTRO;
    }

    /**
     * La ritenuta versata entro la data, per fattura: per ogni pagamento della fattura (allocazione `pagamento`, positiva, entro la data) non stornato
     * entro la data, la quota della ritenuta della fattura, se il pagamento è in un versamento F24 datato entro la data e non stornato entro la data.
     *
     * @return array<int,int>
     */
    private function ritenuteVersate(\Illuminate\Support\Collection $fatture, string $dopo): array
    {
        $conRitenuta = $fatture->filter(fn ($f) => (int) $f->importo_ritenuta > 0 && (int) $f->netto_a_pagare > 0)->keyBy('id');
        if ($conRitenuta->isEmpty()) {
            return [];
        }
        $pagamenti = DB::table('fattura_scrittura as fs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('sc.id', '=', 'fs.scrittura_contabile_id')->whereNull('sc.deleted_at'))
            ->join('pagamenti_fornitori as pf', 'pf.scrittura_contabile_id', '=', 'sc.id')
            ->whereIn('fs.fattura_passiva_id', $conRitenuta->keys()->all())->where('fs.tipo', 'pagamento')->where('fs.importo_allocato', '>', 0)
            ->where('sc.data_competenza', '<', $dopo)
            // Un pagamento stornato entro la data non ha più una ritenuta da versare.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('scritture_contabili as st')->whereColumn('st.scrittura_padre_id', 'sc.id')
                ->where('st.tipo_movimento', 'storno_pagamento_fornitore')->whereNull('st.deleted_at')->where('st.data_competenza', '<', $dopo))
            ->get(['fs.fattura_passiva_id', 'fs.importo_allocato', 'pf.id as pagamento_id']);
        if ($pagamenti->isEmpty()) {
            return [];
        }
        $versati = DB::table('riga_f24_pagamento as rp')
            ->join('righe_f24 as r', 'r.id', '=', 'rp.riga_f24_id')
            ->join('deleghe_f24 as d', 'd.id', '=', 'r.delega_f24_id')
            ->join('scritture_contabili as v', fn ($j) => $j->on('v.id', '=', 'd.scrittura_contabile_id')->whereNull('v.deleted_at'))
            ->whereIn('rp.pagamento_fornitore_id', $pagamenti->pluck('pagamento_id')->unique()->all())
            ->where('v.data_competenza', '<', $dopo)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('scritture_contabili as st')->whereColumn('st.scrittura_padre_id', 'v.id')
                ->where('st.tipo_movimento', 'storno_pagamento_f24')->whereNull('st.deleted_at')->where('st.data_competenza', '<', $dopo))
            ->distinct()->pluck('rp.pagamento_fornitore_id')->map(fn ($id) => (int) $id)->flip();

        $esito = [];
        foreach ($pagamenti as $p) {
            if (! isset($versati[(int) $p->pagamento_id])) {
                continue;
            }
            $f = $conRitenuta[(int) $p->fattura_passiva_id];
            $esito[(int) $f->id] = ($esito[(int) $f->id] ?? 0)
                + PagamentoFornitoreService::ritenutaDellaQuota((int) $f->importo_ritenuta, (int) $f->netto_a_pagare, (int) $p->importo_allocato);
        }

        return $esito;
    }
}
