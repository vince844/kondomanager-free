<?php

namespace App\Services\Gestionale\Incassi;

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Debiti\Scadenza;

/**
 * L'ordine che il programma propone per imputare un versamento quando nessuno dice a che cosa va (decisione 218): per scadenza
 * della rata, dalla più vecchia, emesse o no; a pari scadenza la rata 0 (il pregresso), poi le rate emesse, poi le bozze; a pari
 * ancora, l'id della rata. È una proposta del programma, non l'ordine della legge: l'art. 1193 c.c. è la ragione della scelta, e
 * potrebbe non valere fra le rate di un solo piano.
 *
 * L'ordine è fatto delle rate su cui la persona ha un debito prima dell'incasso (il netto delle sue quote sulla rata, `importo −
 * importo_pagato`, è maggiore di zero: una quota a credito della stessa persona sulla stessa rata lo riduce), in tutte le gestioni e in tutte le sue unità del condominio. Il grano è la rata: il motore paga insieme tutte le quote della
 * persona su una rata (la più grande per prima), quindi due unità della stessa rata stanno nello stesso posto.
 *
 * Una fonte sola: la situazione debitoria manda le righe in quest'ordine (con la {@see chiave()}), la rotta di sola lettura dice al
 * modulo l'esito prima di salvare e il salvataggio ({@see StoreIncassoRateAction}) rifà lo stesso calcolo e lo scrive. Non rifiuta
 * niente: un incasso fuori ordine senza una scelta si registra «fuori ordine, non dichiarata».
 */
final class OrdineImputazione
{
    public const ORDINE_SCADENZE = 'ordine_scadenze';
    public const INDICATA_DAL_VERSANTE = 'indicata_dal_versante';
    public const SCELTA_AMMINISTRATORE = 'scelta_amministratore';
    public const FUORI_ORDINE = 'fuori_ordine';
    public const COMPENSAZIONE = 'compensazione';

    /** Le due dichiarazioni che il modulo può mandare quando la distribuzione si discosta dall'ordine. */
    public const SCELTE = [self::INDICATA_DAL_VERSANTE, self::SCELTA_AMMINISTRATORE];

    /**
     * La chiave con cui si confrontano due rate.
     *
     * @param  string  $scadenza  `Y-m-d`
     * @return array{0:string, 1:int, 2:int}
     */
    public static function chiave(string $scadenza, int $numeroRata, bool $emessa, int $rataId): array
    {
        return [$scadenza, $numeroRata === 0 ? 0 : ($emessa ? 1 : 2), $rataId];
    }

    /**
     * Le rate su cui la persona ha un debito, nell'ordine: per rata il suo residuo (centesimi), quanto resta davvero aperto, la scadenza,
     * se è emessa (una sua quota con la scrittura di emissione), la gestione e le unità delle quote aperte.
     *
     * Una rata è aperta solo se il **netto** della persona su quella rata (tutte le sue quote, anche a credito: il box a credito accanto
     * all'appartamento a debito sulla rata 0, la gemella a credito di una vendita seguita da una ricompra) è positivo, come la mostra la
     * situazione debitoria e come la riempie il modulo. `residuo` resta il lordo delle quote a debito, che è quello che il motore paga e
     * su cui si misura il denaro applicato; `aperto` è quanto la rata tiene aperta, `min(lordo, netto)`, e su quello si misura «resta».
     * Il netto è della persona: il credito di un'altra persona sulla stessa unità (la ricerca per unità) non lo tocca.
     *
     * @return list<array{rata_id:int, numero_rata:int, descrizione:string, scadenza:string, emessa:bool, residuo:int, aperto:int, gestione_id:?int, gestione:?string, unita:string}>
     */
    public function rateAperte(int $condominioId, int $anagraficaId): array
    {
        $tutte = RataQuote::query()
            ->where('anagrafica_id', $anagraficaId)
            ->whereHas('rata.pianoRate', fn ($p) => $p->where('condominio_id', $condominioId))
            ->with(['rata.pianoRate.gestione:id,nome', 'immobile'])
            ->get();
        $netto = $tutte->groupBy('rata_id')->map(fn ($g) => (int) $g->sum(fn (RataQuote $q) => (int) $q->importo - (int) $q->importo_pagato));
        $quote = $tutte->filter(fn (RataQuote $q) => (int) $q->importo > 0 && (int) $q->importo > (int) $q->importo_pagato && ($netto[$q->rata_id] ?? 0) > 0);

        $rate = $quote->groupBy('rata_id')->map(function ($gruppo) use ($netto) {
            $rata = $gruppo->first()->rata;
            $scadenza = Scadenza::giorno($rata->data_scadenza);
            $emessa = $gruppo->contains(fn (RataQuote $q) => $q->scrittura_contabile_id !== null);
            $lordo = (int) $gruppo->sum(fn (RataQuote $q) => (int) $q->importo - (int) $q->importo_pagato);

            return [
                'rata_id'     => (int) $rata->id,
                'numero_rata' => (int) $rata->numero_rata,
                'descrizione' => (string) ($rata->descrizione ?: 'Rata n.' . $rata->numero_rata),
                'scadenza'    => $scadenza,
                'emessa'      => $emessa,
                'residuo'     => $lordo,
                'aperto'      => min($lordo, (int) $netto[$rata->id]),
                'gestione_id' => $rata->pianoRate?->gestione_id,
                'gestione'    => $rata->pianoRate?->gestione?->nome,
                'unita'       => $gruppo->map(fn (RataQuote $q) => $q->immobile?->etichetta)->filter()->unique()->implode(', '),
                'chiave'      => self::chiave($scadenza, (int) $rata->numero_rata, $emessa, (int) $rata->id),
            ];
        })->sortBy('chiave')->values();

        return $rate->map(function (array $r) {
            unset($r['chiave']);

            return $r;
        })->all();
    }

    /**
     * L'esito di una distribuzione, prima di scriverla.
     *
     * - `compensazione`: l'incasso non porta denaro (solo credito). Non è «secondo l'ordine» anche se lo segue: non c'è un versamento
     *   da imputare.
     * - `ordine_scadenze`: ogni rata che riceve **denaro** (la parte che il credito non copre, come la dice il pianificatore) ha davanti
     *   nell'ordine solo rate che l'incasso salda, col denaro o col credito; e la parte in più che diventa credito non lascia rate
     *   aperte dopo nell'ordine.
     * - `fuori_ordine` altrimenti, con la rata più vecchia che resta aperta dopo l'incasso e se a scostarsi è la parte in più.
     *
     * @param  array<int|string, array{rata_id:int|string, importo:int|float|string}>  $pagamentiOrdinari  le righe a importo positivo, con le chiavi della richiesta
     * @param  array<int|string, int>  $contantePerRiga  per chiave, i centesimi che paga il denaro (`PianoCreditoIncasso`, `contante`)
     * @return array{esito:string, rata_aperta:?array, parte_in_piu:bool}
     */
    public function esito(int $condominioId, int $paganteId, array $pagamentiOrdinari, array $contantePerRiga, int $contanteCents): array
    {
        $quoteFaro = RataQuote::whereIn('id', collect($pagamentiOrdinari)->pluck('rata_id')->map(fn ($id) => (int) $id)->all())->pluck('rata_id', 'id');

        $coperto = [];
        $denaro = [];
        foreach ($pagamentiOrdinari as $k => $p) {
            $rataId = $quoteFaro[(int) $p['rata_id']] ?? null;
            if ($rataId === null) {
                continue;
            }
            $importo = MoneyHelper::toCents($p['importo']);
            $coperto[$rataId] = ($coperto[$rataId] ?? 0) + $importo;
            $denaro[$rataId] = ($denaro[$rataId] ?? 0) + ($contantePerRiga[$k] ?? $importo);
        }

        $aperte = $this->rateAperte($condominioId, $paganteId);
        $applicato = 0;
        foreach ($aperte as $r) {
            $applicato += min($denaro[$r['rata_id']] ?? 0, $r['residuo']);
        }
        $parteInPiu = max(0, $contanteCents - $applicato) > 0;

        // La prima rata che l'incasso lascia aperta, e se dopo di lei qualcosa riceve denaro.
        $primaAperta = null;
        $denaroDopo = false;
        foreach ($aperte as $r) {
            $resta = $r['aperto'] - min($coperto[$r['rata_id']] ?? 0, $r['aperto']);
            if ($primaAperta !== null && ($denaro[$r['rata_id']] ?? 0) > 0) {
                $denaroDopo = true;
            }
            if ($primaAperta === null && $resta > 0) {
                $primaAperta = ['rata_id' => $r['rata_id'], 'numero_rata' => $r['numero_rata'], 'descrizione' => $r['descrizione'], 'scadenza' => $r['scadenza'],
                    'emessa' => $r['emessa'], 'gestione' => $r['gestione'], 'unita' => $r['unita'], 'resta' => $resta];
            }
        }

        if ($contanteCents <= 0) {
            return ['esito' => self::COMPENSAZIONE, 'rata_aperta' => null, 'parte_in_piu' => false];
        }

        $fuori = $denaroDopo || ($parteInPiu && $primaAperta !== null);

        return [
            'esito'        => $fuori ? self::FUORI_ORDINE : self::ORDINE_SCADENZE,
            'rata_aperta'  => $fuori ? $primaAperta : null,
            'parte_in_piu' => $fuori && $parteInPiu && ! $denaroDopo,
        ];
    }

    /**
     * L'esito per una richiesta come la riceve il salvataggio: lo stesso piano del credito del motore
     * ({@see StoreIncassoRateAction::pianoDelCredito()}), lo stesso calcolo.
     *
     * @param  array<string, mixed>  $validated
     * @return array{esito:string, rata_aperta:?array, parte_in_piu:bool}
     */
    public function perRichiesta(array $validated, int $condominioId): array
    {
        $quoteRighe = RataQuote::with('rata.pianoRate')
            ->whereIn('id', collect($validated['dettaglio_pagamenti'])->pluck('rata_id'))
            ->get()
            ->keyBy('id');
        // L'id come il numero che `integer` ha validato: «+5» o « 5» passano la regola, e non sono una chiave della raccolta.
        $gestioneDi = fn (array $p) => ($quoteRighe[(int) $p['rata_id']] ?? null)?->rata?->pianoRate?->gestione_id;
        $piano = StoreIncassoRateAction::pianoDelCredito($validated, $gestioneDi);

        return $this->esito(
            $condominioId,
            (int) $validated['pagante_id'],
            array_filter($validated['dettaglio_pagamenti'], fn ($p) => $p['importo'] > 0),
            $piano['contante'],
            MoneyHelper::toCents($validated['importo_totale']),
        );
    }

    /** Come la pagina dell'incasso dice l'imputazione; per gli incassi di prima, che non l'hanno, lo dice. */
    public static function etichetta(?string $imputazione): string
    {
        return match ($imputazione) {
            self::ORDINE_SCADENZE => 'Secondo l\'ordine delle scadenze',
            self::INDICATA_DAL_VERSANTE => 'Indicata da chi ha versato',
            self::SCELTA_AMMINISTRATORE => 'Scelta dall\'amministratore',
            self::FUORI_ORDINE => 'Fuori ordine, non dichiarata',
            self::COMPENSAZIONE => 'Compensazione con il credito',
            default => 'Non dichiarata: registrato prima che il programma la chiedesse',
        };
    }

    /**
     * Che cosa si scrive sull'incasso: il fatto dell'esito, salvo che la distribuzione si discosti e il modulo mandi una dichiarazione
     * (con la sua nota). Una dichiarazione mandata con una distribuzione che segue l'ordine non cambia il fatto, e la sua nota non si
     * scrive.
     *
     * @param  array{esito:string}  $esito
     * @return array{0:string, 1:?string}
     */
    public static function daScrivere(array $esito, ?string $scelta, ?string $nota): array
    {
        if ($esito['esito'] !== self::FUORI_ORDINE || ! in_array($scelta, self::SCELTE, true)) {
            return [$esito['esito'], null];
        }

        $nota = trim((string) $nota);

        return [$scelta, $nota === '' ? null : mb_substr($nota, 0, 255)];
    }
}
