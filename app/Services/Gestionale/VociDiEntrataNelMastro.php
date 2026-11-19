<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\ContoContabileCategoria;
use App\Enums\ContoContabileTipo;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\DB;

/**
 * Coda 680 (decisione 493 (15)): le voci di entrata del piano dei conti e il loro mastro.
 *
 * - **Il mastro delle entrate**: le voci di entrata nuove (gli interessi di mora, un affitto delle parti comuni, un rimborso) vanno nel mastro «Entrate e
 *   proventi» (ruolo `entrate_proventi`), che nasce alla prima richiesta nei condomini di prima, come «Accessori da incassare». Fino alla 1.12.0-beta.11 il
 *   piano dei conti le metteva in «Costi per Servizi» (`ContoController::resolveContoContabileId`, dalla 1.9.0), senza guardare il tipo. Il mastro è un conto
 *   del conto economico **in diminuzione dei costi** (tipo e categoria dei costi), non un conto di tipo ricavo: il rendiconto conta le entrate come costi in
 *   meno (il risultato di gestione è «quote − costi», i ricavi del motore sono zero: `StatoPatrimonialePaginaService::risultatoGestione`, l'incasso da terzi,
 *   il controllo «I costi tornano con il riepilogo», la nota sintetica). Un mastro di tipo ricavo farebbe uscire le entrate dal risultato: è una scelta del
 *   rendiconto, non di questa correzione. Così le entrate non finiscono più nel mastro delle spese per servizi, e tutte le cifre del rendiconto restano quelle.
 * - **Il controllo**, in sola lettura: le voci di entrata con un mastro di costo, con le scritture che le hanno usate (le righe con la voce su quel mastro).
 * - **La correzione**, solo su comando dell'amministratore: sposta il mastro della voce, per le scritture che verranno. Le righe già scritte restano dove
 *   sono, con la data e il mastro di allora: correggerle cambierebbe un rendiconto già fatto, e lo decide l'amministratore con una scrittura sua.
 */
final class VociDiEntrataNelMastro
{
    public const RUOLO = 'entrate_proventi';

    public const NOME = 'Entrate e proventi';

    /** Il mastro delle entrate del condominio; nasce alla prima richiesta, fra i ricavi, come gli altri conti di sistema. */
    public static function mastro(int $condominioId): ContoContabile
    {
        $esiste = ContoContabile::where('condominio_id', $condominioId)->where('ruolo', self::RUOLO)->whereNull('deleted_at')->first();
        if ($esiste !== null) {
            return $esiste;
        }
        // Il codice: 7001; se un conto dell'amministratore lo ha già, il primo libero dopo.
        $codice = '7001';
        for ($n = 1; ContoContabile::withTrashed()->where('condominio_id', $condominioId)->where('codice', $codice)->exists(); $n++) {
            $codice = '7001.' . $n;
        }

        return ContoContabile::create([
            'condominio_id' => $condominioId,
            'parent_id' => null,
            'codice' => $codice,
            'nome' => self::NOME,
            'descrizione' => 'Interessi di mora versati, affitti delle parti comuni, rimborsi e le altre entrate del condominio.',
            // In diminuzione dei costi, come le entrate nel rendiconto: vedi sopra.
            'tipo' => ContoContabileTipo::COSTO->value,
            'categoria' => ContoContabileCategoria::COSTI->value,
            'ruolo' => self::RUOLO,
            'di_sistema' => true,
            'attivo' => true,
            'livello' => 1,
        ]);
    }

    /** Una voce di entrata: ogni tipo che non è una spesa ({@see Conto::TIPI_DI_SPESA}). */
    public static function diEntrata(?string $tipo): bool
    {
        return $tipo !== null && $tipo !== '' && ! in_array($tipo, Conto::TIPI_DI_SPESA, true);
    }

    /**
     * Le voci di entrata del condominio (o di un piano dei conti) con un mastro di costo che non è quello delle entrate, e le scritture che le hanno usate.
     *
     * @return list<array{id: int, nome: string, piano_conto_id: int, gestione: ?string, mastro: string, scritture: list<array{id: int, data: string, causale: string, importo: int, verso: string}>, totale: int, frase: string}>
     */
    public function perCondominio(int $condominioId, ?int $pianoContoId = null): array
    {
        $voci = DB::table('conti as c')->join('piani_conti as pc', 'pc.id', '=', 'c.piano_conto_id')->join('conti_contabili as m', 'm.id', '=', 'c.conto_contabile_id')
            ->leftJoin('gestioni as g', 'g.id', '=', 'pc.gestione_id')
            ->where('pc.condominio_id', $condominioId)->when($pianoContoId !== null, fn ($q) => $q->where('pc.id', $pianoContoId))
            ->whereNotIn('c.tipo', Conto::TIPI_DI_SPESA)
            ->where(fn ($w) => $w->where('m.tipo', ContoContabileTipo::COSTO->value)->orWhere('m.categoria', ContoContabileCategoria::COSTI->value))
            // Il mastro delle entrate è fra i costi anche lui, in diminuzione: una voce che ci sta è a posto.
            ->where(fn ($w) => $w->whereNull('m.ruolo')->orWhere('m.ruolo', '!=', self::RUOLO))
            ->orderBy('pc.id')->orderBy('c.nome')
            ->get(['c.id', 'c.nome', 'c.piano_conto_id', 'c.conto_contabile_id', 'g.nome as gestione', 'm.codice', 'm.nome as mastro']);
        $esito = [];
        foreach ($voci as $v) {
            $scritture = DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
                ->whereNull('sc.deleted_at')->where('rs.voce_spesa_id', $v->id)->where('rs.conto_contabile_id', $v->conto_contabile_id)
                ->orderBy('sc.data_competenza')->orderBy('sc.id')
                ->get(['sc.id', 'sc.data_competenza', 'sc.causale', 'rs.importo', 'rs.tipo_riga'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'data' => substr((string) $r->data_competenza, 0, 10), 'causale' => (string) $r->causale, 'importo' => (int) $r->importo,
                    'verso' => (string) $r->tipo_riga])->values()->all();
            $totale = (int) array_sum(array_map(fn ($r) => $r['verso'] === 'avere' ? $r['importo'] : -$r['importo'], $scritture));
            $esito[] = [
                'id' => (int) $v->id, 'nome' => (string) $v->nome, 'piano_conto_id' => (int) $v->piano_conto_id, 'gestione' => $v->gestione,
                'mastro' => trim($v->codice . ' ' . $v->mastro), 'scritture' => $scritture, 'totale' => $totale,
                'frase' => $scritture === []
                    ? sprintf('«%s» è una voce di entrata nel mastro «%s», delle spese: nessuna scrittura l\'ha ancora usata.', $v->nome, trim($v->codice . ' ' . $v->mastro))
                    : sprintf('«%s» è una voce di entrata nel mastro «%s», delle spese: %s l\'%s usata, per %s, che restano su quel mastro anche dopo la correzione.',
                        $v->nome, trim($v->codice . ' ' . $v->mastro), count($scritture) === 1 ? 'una scrittura' : count($scritture) . ' scritture',
                        count($scritture) === 1 ? 'ha' : 'hanno', MoneyHelper::format(abs($totale))),
            ];
        }

        return $esito;
    }

    /**
     * Su comando dell'amministratore: le voci date (di questo condominio, di entrata, con un mastro di costo) passano al mastro delle entrate, per le scritture
     * che verranno. Le righe già scritte non si toccano. Restituisce quante voci ha spostato.
     *
     * @param list<int>|null $voci senza, tutte quelle del controllo
     */
    public function correggi(int $condominioId, ?array $voci = null, ?int $pianoContoId = null): int
    {
        $daSpostare = array_column($this->perCondominio($condominioId, $pianoContoId), 'id');
        if ($voci !== null) {
            $daSpostare = array_values(array_intersect($daSpostare, array_map('intval', $voci)));
        }
        if ($daSpostare === []) {
            return 0;
        }
        $mastro = self::mastro($condominioId);

        return Conto::whereIn('id', $daSpostare)->update(['conto_contabile_id' => $mastro->id]);
    }
}
