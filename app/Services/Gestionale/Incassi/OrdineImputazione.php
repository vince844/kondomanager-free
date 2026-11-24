<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Incassi;

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Debiti\AccordiRientro;
use App\Services\Gestionale\Debiti\Scadenza;

/**
 * L'ordine che il programma propone per imputare un versamento quando nessuno dice a che cosa va (decisione 218): per scadenza
 * della rata, dalla più vecchia, emesse o no; a pari scadenza la rata 0 (il pregresso), poi le rate emesse, poi le bozze; a pari
 * ancora, l'id della rata. È una proposta del programma, non l'ordine della legge: l'art. 1193 c.c. è la ragione della scelta, e
 * potrebbe non valere fra le rate di un solo piano.
 *
 * Prima di tutte le rate vengono gli interessi e le spese addebitati alla persona già scaduti o che scadono oggi (gli accessori, i più vecchi
 * prima): per l'art. 1194 c.c. il debitore non imputa al capitale prima che agli interessi e alle spese senza il consenso del creditore, e
 * l'amministratore che sceglie altrimenti con «Scelta da te» lo dà. La regola vale per un debito già chiesto: un accessorio che scade più avanti
 * sta fra le rate, per la sua scadenza, e un versamento sulla rata scaduta non è fuori ordine per lui.
 *
 * **Con un accordo di rientro in corso** le rate e gli accessori che l'accordo copre escono dall'ordine proposto, per la parte coperta: la persona ha un
 * calendario e il creditore l'ha accettato. Un versamento senza una scelta va alle rate correnti; solo «Versamento dell'accordo» va all'accordo, e lì
 * l'ordine è lo stesso ristretto alle parti coperte (prima gli interessi e le spese, poi il capitale più vecchio). Una rata coperta solo in parte resta
 * nell'ordine per la parte fuori dall'accordo, che un versamento su quella rata paga per prima (la regola del segno dell'accordo).
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
    public function __construct(private readonly AccordiRientro $accordi) {}

    public const ORDINE_SCADENZE = 'ordine_scadenze';
    public const INDICATA_DAL_VERSANTE = 'indicata_dal_versante';
    public const SCELTA_AMMINISTRATORE = 'scelta_amministratore';
    public const FUORI_ORDINE = 'fuori_ordine';
    public const COMPENSAZIONE = 'compensazione';
    public const ACCORDO_RIENTRO = 'accordo_rientro';
    /** Coda 713: l'incasso di un erede tutto sul suo debito ereditato (decisione 529). Un calcolo, non una dichiarazione, come la compensazione. */
    public const DEBITO_EREDITATO = 'debito_ereditato';

    /** Le due dichiarazioni che il modulo può mandare quando la distribuzione si discosta dall'ordine. */
    public const SCELTE = [self::INDICATA_DAL_VERSANTE, self::SCELTA_AMMINISTRATORE];

    /**
     * La chiave con cui si confrontano due rate: un accessorio (una quota `accessorio` del piano degli addebiti) scaduto o del giorno prima del
     * capitale; uno che scade più avanti fra le rate, per la sua scadenza.
     *
     * @param  string  $scadenza  `Y-m-d`
     * @param  string|null  $oggi  `Y-m-d`; senza, il giorno dell'amministratore
     * @return array{0:int, 1:string, 2:int, 3:int}
     */
    public static function chiave(string $scadenza, int $numeroRata, bool $emessa, int $rataId, bool $accessorio = false, ?string $oggi = null): array
    {
        return [self::inTesta($accessorio, $scadenza, $oggi) ? 0 : 1, $scadenza, $numeroRata === 0 ? 0 : ($emessa ? 1 : 2), $rataId];
    }

    /** Se un accessorio sta in testa all'ordine: scaduto o del giorno. */
    public static function inTesta(bool $accessorio, string $scadenza, ?string $oggi = null): bool
    {
        return $accessorio && Scadenza::giorno($scadenza) <= Scadenza::giorno($oggi ?? DateHelper::oggiUtente());
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
     * `in_accordo`: la parte della rata (tutte le sue unità) che un accordo di rientro in corso oggi copre, in centesimi; zero senza accordo.
     *
     * @return list<array{rata_id:int, numero_rata:int, descrizione:string, scadenza:string, emessa:bool, residuo:int, aperto:int, gestione_id:?int, gestione:?string, unita:string, accessorio:bool, in_accordo:int}>
     */
    public function rateAperte(int $condominioId, int $anagraficaId): array
    {
        $inAccordo = [];
        foreach ($this->accordi->partiInCorso($condominioId, [$anagraficaId], DateHelper::oggiUtente())[$anagraficaId] ?? [] as $chiave => $centesimi) {
            $rata = (int) explode('|', $chiave)[0];
            $inAccordo[$rata] = ($inAccordo[$rata] ?? 0) + $centesimi;
        }
        $tutte = RataQuote::query()
            ->where('anagrafica_id', $anagraficaId)
            ->whereHas('rata.pianoRate', fn ($p) => $p->where('condominio_id', $condominioId))
            ->with(['rata.pianoRate.gestione:id,nome', 'immobile'])
            ->get();
        $netto = $tutte->groupBy('rata_id')->map(fn ($g) => (int) $g->sum(fn (RataQuote $q) => (int) $q->importo - (int) $q->importo_pagato));
        $quote = $tutte->filter(fn (RataQuote $q) => (int) $q->importo > 0 && (int) $q->importo > (int) $q->importo_pagato && ($netto[$q->rata_id] ?? 0) > 0);

        $oggi = DateHelper::oggiUtente();
        $rate = $quote->groupBy('rata_id')->map(function ($gruppo) use ($netto, $oggi, $inAccordo) {
            $rata = $gruppo->first()->rata;
            $scadenza = Scadenza::giorno($rata->data_scadenza);
            $emessa = $gruppo->contains(fn (RataQuote $q) => $q->scrittura_contabile_id !== null);
            $lordo = (int) $gruppo->sum(fn (RataQuote $q) => (int) $q->importo - (int) $q->importo_pagato);
            $accessorio = $gruppo->contains(fn (RataQuote $q) => $q->tipo === RataQuote::TIPO_ACCESSORIO);

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
                'accessorio'  => $accessorio,
                'in_accordo'  => min($inAccordo[(int) $rata->id] ?? 0, min($lordo, (int) $netto[$rata->id])),
                'chiave'      => self::chiave($scadenza, (int) $rata->numero_rata, $emessa, (int) $rata->id, $accessorio, $oggi),
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
     * - `debito_ereditato` (Coda 713): il denaro va tutto al debito ereditato dell'erede (`$ereditatoCents`, decisione 529), fuori dalle sue rate: non c'è
     *   un versamento sulle rate da mettere in ordine. Il denaro sul debito ereditato non entra mai nel contante qui (`$contanteCents` è senza di lui, come
     *   lo manda il modulo): contato, sarebbe una parte «in più» davanti alla prima rata aperta dell'erede, e l'incasso «fuori ordine».
     * - `ordine_scadenze`: ogni rata che riceve **denaro** (la parte che il credito non copre, come la dice il pianificatore) ha davanti
     *   nell'ordine solo rate che l'incasso salda, col denaro o col credito; e la parte in più che diventa credito non lascia rate
     *   aperte dopo nell'ordine.
     * - `fuori_ordine` altrimenti, con la rata più vecchia che resta aperta dopo l'incasso e se a scostarsi è la parte in più.
     * - Con un accordo di rientro in corso l'ordine è la parte delle rate **fuori** dall'accordo; con `$versamentoAccordo` prima la parte **dentro**
     *   l'accordo e poi quella fuori, e l'esito che segue l'ordine è `accordo_rientro`. Senza la scelta, denaro sulla parte coperta è fuori ordine
     *   (`nell_accordo`); con la scelta, denaro su una rata fuori dall'accordo mentre l'accordo resta aperto (`fuori_dall_accordo`), o la parte in più a
     *   credito con una rata aperta, è fuori ordine come sempre.
     *
     * @param  array<int|string, array{rata_id:int|string, importo:int|float|string}>  $pagamentiOrdinari  le righe a importo positivo, con le chiavi della richiesta
     * @param  array<int|string, int>  $contantePerRiga  per chiave, i centesimi che paga il denaro (`PianoCreditoIncasso`, `contante`)
     * @param  int  $contanteCents  il denaro dell'incasso, senza la parte sul debito ereditato
     * @param  int  $ereditatoCents  la parte sul debito ereditato (Coda 713); di serie zero: la rotta del modulo non la manda, e la toglie già dal totale
     * @return array{esito:string, rata_aperta:?array, parte_in_piu:bool, nell_accordo:bool, fuori_dall_accordo:bool}
     */
    public function esito(int $condominioId, int $paganteId, array $pagamentiOrdinari, array $contantePerRiga, int $contanteCents, bool $versamentoAccordo = false,
        int $ereditatoCents = 0): array
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

        // L'ordine come una fila di parti. Senza la scelta, le parti delle rate fuori dall'accordo; il denaro oltre la parte fuori di una rata va alla
        // parte nell'accordo, ed è fuori dall'ordine. Con «Versamento dell'accordo», prima le parti nell'accordo e poi quelle fuori, ognuna nel suo
        // ordine: la parte in più oltre l'accordo segue l'ordine di sempre. Il denaro di una rata va prima alla sua parte del perimetro (il motore
        // paga prima le sue quote).
        $fila = [];
        $nellAccordo = false;
        foreach ($aperte as $r) {
            $dentro = $r['in_accordo'];
            $fuoriParte = $r['aperto'] - $dentro;
            $c = $coperto[$r['rata_id']] ?? 0;
            $suDentro = $versamentoAccordo ? min($c, $dentro) : max(0, $c - $fuoriParte);
            if (! $versamentoAccordo && $dentro > 0 && $suDentro > 0) {
                $nellAccordo = true;
            }
            $parti = $versamentoAccordo ? [['dentro', $dentro, $suDentro]] : [];
            $parti[] = ['fuori', $fuoriParte, $c - $suDentro];
            foreach ($parti as [$fase, $perimetro, $sopra]) {
                if ($perimetro > 0) {
                    $fila[$fase === 'dentro' ? 0 : 1][] = ['rata' => $r, 'fase' => $fase, 'perimetro' => $perimetro, 'coperto' => $sopra,
                        'denaro' => $sopra > 0 && ($denaro[$r['rata_id']] ?? 0) > 0];
                }
            }
        }
        $fila = [...($fila[0] ?? []), ...($fila[1] ?? [])];

        // La prima parte che l'incasso lascia aperta, e se dopo di lei qualcosa riceve denaro.
        $primaAperta = null;
        $faseAperta = null;
        $denaroDopo = false;
        $denaroFuori = false;
        foreach ($fila as $voce) {
            $r = $voce['rata'];
            $resta = $voce['perimetro'] - min($voce['coperto'], $voce['perimetro']);
            if ($primaAperta !== null && $voce['denaro']) {
                $denaroDopo = true;
                $denaroFuori = $denaroFuori || $voce['fase'] === 'fuori';
            }
            if ($primaAperta === null && $resta > 0) {
                $faseAperta = $voce['fase'];
                $primaAperta = ['rata_id' => $r['rata_id'], 'numero_rata' => $r['numero_rata'], 'descrizione' => $r['descrizione'], 'scadenza' => $r['scadenza'],
                    'emessa' => $r['emessa'], 'gestione' => $r['gestione'], 'unita' => $r['unita'], 'resta' => $resta];
            }
        }

        if ($contanteCents <= 0) {
            return ['esito' => $ereditatoCents > 0 ? self::DEBITO_EREDITATO : self::COMPENSAZIONE, 'rata_aperta' => null, 'parte_in_piu' => false,
                'nell_accordo' => false, 'fuori_dall_accordo' => false];
        }

        $fuori = $denaroDopo || $nellAccordo || ($parteInPiu && $primaAperta !== null);

        return [
            'esito'        => $fuori ? self::FUORI_ORDINE : ($versamentoAccordo ? self::ACCORDO_RIENTRO : self::ORDINE_SCADENZE),
            'rata_aperta'  => $fuori ? $primaAperta : null,
            'parte_in_piu' => $fuori && $parteInPiu && ! $denaroDopo && ! $nellAccordo,
            // Senza la scelta, denaro sulla parte di una rata che l'accordo copre.
            'nell_accordo' => $nellAccordo,
            // Con la scelta, denaro su una rata fuori dall'accordo mentre una parte dell'accordo resta aperta.
            'fuori_dall_accordo' => $versamentoAccordo && $denaroFuori && $faseAperta === 'dentro',
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
            filter_var($validated['versamento_accordo'] ?? false, FILTER_VALIDATE_BOOLEAN),
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
            self::ACCORDO_RIENTRO => 'Versamento dell\'accordo di rientro',
            self::DEBITO_EREDITATO => 'Sul debito ereditato',
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
