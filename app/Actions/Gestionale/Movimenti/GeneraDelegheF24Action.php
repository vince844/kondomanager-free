<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\StatoDelegaF24;
use App\Enums\Fiscale\TipoRitenuta;
use App\Enums\StatoPagamentoFornitore;
use App\Models\Condominio;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\RigaF24;
use App\Services\Gestionale\PlafondRitenuteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Costruisce le deleghe F24 in bozza a partire dalle ritenute già operate e non ancora
 * versate.
 *
 * L'ordine dei passi non è arbitrario:
 *
 *  1. **Dai pagamenti**, non dalle fatture. È il pagamento il fatto generatore della
 *     ritenuta (art. 25-ter, *«all'atto del pagamento»*), quindi è la sua data a decidere
 *     il mese di riferimento in F24 e la scadenza del versamento. Che in contabilità il
 *     conto 2202 si accenda ancora alla registrazione della fattura è un'altra questione,
 *     ed è la Fase 2 del design: non cambia quale sia la data fiscalmente giusta.
 *  2. **Esclude ciò che è già in una delega**, tramite la pivot: rigenerare non deve mai
 *     produrre un secondo versamento delle stesse ritenute.
 *  3. **Raggruppa con `PlafondRitenuteService`**, che applica le soglie e le date di legge.
 *  4. **Spezza in righe** per codice tributo × mese × anno, e **splitta la delega** oltre
 *     le sei righe che il tracciato ammette.
 *
 * Le deleghe nascono in `bozza`: nessun effetto contabile finché non si conferma il
 * versamento. Rigenerare cancella le bozze precedenti e le ricostruisce — è il motivo per
 * cui il plafond è una funzione pura e non un contatore: si ricalcola da capo senza paura.
 */
class GeneraDelegheF24Action
{
    /**
     * Il tracciato F24 ammette sei righe di sezione Erario per modello.
     *
     * Pubblica da quando esiste la stampa: lo stesso sei vive anche in
     * `ModelloF24Service::RIGHE_SEZIONE_ERARIO`, perché di là è il numero di righe *stampate*
     * sul foglio e di qua è il punto in cui la delega si spezza. Sono due fatti distinti che
     * devono coincidere, e `ModelloF24StampaTest` li tiene agganciati: se divergessero, una
     * delega da sette righe perderebbe la settima **senza dirlo a nessuno**.
     */
    public const MAX_RIGHE_PER_DELEGA = 6;

    public function __construct(private PlafondRitenuteService $plafond) {}

    /**
     * @return \Illuminate\Support\Collection<int,DelegaF24>
     */
    public function esegui(Condominio $condominio, int $esercizioId): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($condominio, $esercizioId) {

            // Le bozze si rifanno da zero: non sono documenti, sono una proposta.
            DelegaF24::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizioId)
                ->where('stato', StatoDelegaF24::BOZZA->value)
                ->each(fn (DelegaF24 $d) => $d->righe()->delete() && $d->delete());

            $ritenute = $this->ritenuteDaVersare($condominio, $esercizioId);

            if ($ritenute->isEmpty()) {
                return collect();
            }

            $gruppi = $this->plafond->raggruppaPerScadenza($ritenute->all());

            $create = collect();

            foreach ($gruppi as $gruppo) {
                foreach ($this->spezzaInDeleghe($gruppo) as $righeDelega) {
                    $create->push($this->creaDelega($condominio, $esercizioId, $gruppo, $righeDelega));
                }
            }

            return $create;
        });
    }

    /**
     * Le ritenute operate e non ancora coperte da una delega viva.
     *
     * Un pagamento stornato non ha più ritenuta da versare: lo storno l'ha annullata.
     *
     * È **pubblica** perché serve a due chiamanti: qui, per costruire le deleghe, e allo
     * scadenzario, per sapere se il ricalcolo è necessario — cioè per rispondere alla
     * domanda «c'è qualcosa che non ho ancora messo in nessuna delega?» senza duplicare
     * la definizione di «scoperta», che è la parte delicata.
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    public function ritenuteDaVersare(Condominio $condominio, int $esercizioId): \Illuminate\Support\Collection
    {
        // Solo le deleghe ANCORA IN PIEDI bloccano un pagamento. Dopo uno storno le
        // ritenute tornano da versare, e devono rientrare nel calcolo: escluderle
        // guardando solo la pivot le farebbe sparire per sempre dal plafond.
        $giaVersati = DB::table('riga_f24_pagamento')
            ->join('righe_f24', 'righe_f24.id', '=', 'riga_f24_pagamento.riga_f24_id')
            ->join('deleghe_f24', 'deleghe_f24.id', '=', 'righe_f24.delega_f24_id')
            ->whereNotIn('deleghe_f24.stato', [
                StatoDelegaF24::STORNATA->value,
                StatoDelegaF24::ANNULLATA->value,
            ])
            ->pluck('riga_f24_pagamento.pagamento_fornitore_id')
            ->all();

        return PagamentoFornitore::query()
            ->with('fornitore')
            ->where('condominio_id', $condominio->id)
            // L'esercizio non sta sul pagamento ma sulla sua scrittura: `pagamenti_fornitori`
            // porta i dati documentali, la collocazione contabile vive nel giornale.
            ->whereHas('scrittura', fn ($q) => $q->where('esercizio_id', $esercizioId))
            ->where('importo_ritenuta', '>', 0)
            ->whereNull('pagamento_padre_id')
            ->where('stato', '!=', StatoPagamentoFornitore::STORNATO->value)
            ->whereNotIn('id', $giaVersati)
            ->orderBy('data_pagamento')
            ->get()
            ->map(fn (PagamentoFornitore $p) => [
                'data_pagamento' => $p->data_pagamento,
                'importo' => (int) $p->importo_ritenuta,
                'tipo_ritenuta' => $this->tipoRitenuta($p),
                'pagamento' => $p,
            ]);
    }

    /**
     * Il regime di ritenuta di un pagamento, dall'anagrafica del fornitore.
     *
     * Il fallback su `APPALTO_4` non è una scorciatoia: è il regime della stragrande
     * maggioranza dei fornitori di un condominio, ed è quello che la 1.9 applicava
     * implicitamente con `perc_ritenuta = 4`. Le anagrafiche precedenti alla Fase 1 hanno
     * `tipo_ritenuta` vuoto, e rifiutarsi di generare la delega le renderebbe invisibili —
     * peggio che classificarle nel regime che quasi certamente è il loro. La percentuale
     * registrata, quando c'è, ha comunque la precedenza sul default.
     */
    private function tipoRitenuta(PagamentoFornitore $pagamento): TipoRitenuta
    {
        // Lo SCATTO al momento del pagamento ha la precedenza sull'anagrafica viva: il
        // regime che conta è quello applicato allora. Correggere oggi l'anagrafica di un
        // fornitore non deve riclassificare versamenti già fatti — un F24 presentato non
        // si riscrive. Disponibile dallo `schema_version` 2 dello snapshot.
        $snapshot = $pagamento->fornitore_snapshot ?? [];

        if (! empty($snapshot['tipo_ritenuta'])) {
            return TipoRitenuta::from($snapshot['tipo_ritenuta']);
        }

        if (isset($snapshot['perc_ritenuta'])) {
            return ((int) $snapshot['perc_ritenuta']) === 20
                ? TipoRitenuta::LAVORO_AUTONOMO_20
                : TipoRitenuta::APPALTO_4;
        }

        // Pagamenti registrati prima dello snapshot v2: si ricade sull'anagrafica.
        $fornitore = $pagamento->fornitore;

        if ($fornitore?->tipo_ritenuta) {
            return $fornitore->tipo_ritenuta instanceof TipoRitenuta
                ? $fornitore->tipo_ritenuta
                : TipoRitenuta::from($fornitore->tipo_ritenuta);
        }

        // Il default su APPALTO_4 non è una scorciatoia: è il regime della stragrande
        // maggioranza dei fornitori di un condominio, ed è quello che la 1.9 applicava
        // implicitamente con `perc_ritenuta = 4`. Escludere le anagrafiche non ancora
        // classificate le renderebbe invisibili al versamento — peggio che collocarle nel
        // regime che quasi certamente è il loro.
        return ((int) ($fornitore->perc_ritenuta ?? 4)) === 20
            ? TipoRitenuta::LAVORO_AUTONOMO_20
            : TipoRitenuta::APPALTO_4;
    }

    /**
     * Le righe di un gruppo, spezzate in blocchi da sei.
     *
     * Una riga è la combinazione codice tributo × mese × anno: due ritenute dello stesso
     * tributo operate nello stesso mese si sommano, di mesi diversi no. Oltre le sei righe
     * si emettono più modelli per la stessa scadenza, come l'Agenzia consente.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function spezzaInDeleghe(array $gruppo): array
    {
        $perRiga = [];

        foreach ($gruppo['ritenute'] as $ritenuta) {
            $pagamento = $ritenuta['pagamento'];
            $tipo = $ritenuta['tipo_ritenuta'] instanceof TipoRitenuta
                ? $ritenuta['tipo_ritenuta']
                : TipoRitenuta::from($ritenuta['tipo_ritenuta']);

            $natura = $this->naturaPercipiente($pagamento);
            $data = $pagamento->data_pagamento;

            $chiave = sprintf('%s-%02d-%d', $tipo->codiceTributo($natura), $data->month, $data->year);

            $perRiga[$chiave] ??= [
                'codice_tributo' => $tipo->codiceTributo($natura),
                'rateazione_mese_rif' => sprintf('00%02d', $data->month),
                'anno_riferimento' => (string) $data->year,
                'importo_debito' => 0,
                'pagamenti' => [],
            ];

            $perRiga[$chiave]['importo_debito'] += (int) $ritenuta['importo'];
            $perRiga[$chiave]['pagamenti'][] = ['id' => $pagamento->id, 'importo' => (int) $ritenuta['importo']];
        }

        ksort($perRiga);

        return array_chunk(array_values($perRiga), self::MAX_RIGHE_PER_DELEGA);
    }

    /** IRPEF o IRES: decide 1019 contro 1020, e non si deduce dal tipo di spesa. */
    private function naturaPercipiente(PagamentoFornitore $pagamento): NaturaPercipiente
    {
        $snapshot = $pagamento->fornitore_snapshot ?? [];

        if (! empty($snapshot['natura_percipiente'])) {
            return NaturaPercipiente::from($snapshot['natura_percipiente']);
        }

        $valore = $pagamento->fornitore?->natura_percipiente;

        if ($valore instanceof NaturaPercipiente) {
            return $valore;
        }

        return $valore
            ? NaturaPercipiente::from($valore)
            : NaturaPercipiente::PERSONA_FISICA_IRPEF;
    }

    private function creaDelega(Condominio $condominio, int $esercizioId, array $gruppo, array $righe): DelegaF24
    {
        $totale = array_sum(array_column($righe, 'importo_debito'));

        $delega = DelegaF24::create([
            'uuid' => (string) Str::uuid(),
            'condominio_id' => $condominio->id,
            'esercizio_id' => $esercizioId,
            'stato' => StatoDelegaF24::BOZZA,
            'plafond' => $gruppo['plafond'],
            'data_scadenza' => $gruppo['scadenza']->toDateString(),
            'cf_contribuente' => $condominio->codice_fiscale,
            'denominazione_contribuente' => $condominio->nome,
            'totale_debito' => $totale,
            'saldo' => $totale,
            'motivo_codice' => $gruppo['motivo'],
        ]);

        foreach ($righe as $i => $riga) {
            $rigaF24 = RigaF24::create([
                'delega_f24_id' => $delega->id,
                'ordine' => $i + 1,
                'sezione' => 'erario',
                'codice_tributo' => $riga['codice_tributo'],
                'rateazione_mese_rif' => $riga['rateazione_mese_rif'],
                'anno_riferimento' => $riga['anno_riferimento'],
                'importo_debito' => $riga['importo_debito'],
            ]);

            foreach ($riga['pagamenti'] as $p) {
                $rigaF24->pagamenti()->attach($p['id'], ['importo' => $p['importo']]);
            }
        }

        return $delega->load('righe');
    }
}
