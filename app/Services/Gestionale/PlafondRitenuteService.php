<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\Fiscale\PlafondRitenuta;
use App\Enums\Fiscale\TipoRitenuta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Quando va versata una ritenuta operata, e con quali altre finisce nella stessa delega.
 *
 * È una **funzione pura**: entra un elenco di ritenute operate (data del pagamento, importo,
 * regime), esce l'elenco delle scadenze con ciò che ciascuna raccoglie. Nessuna tabella,
 * nessuno stato — il design lo impone al §2.1: *«plafond, scadenze e accumulatori sono
 * funzione pura, non tabella»*. Il che significa anche che si può ricalcolare da capo in
 * qualsiasi momento e confrontare col già versato, invece di fidarsi di un contatore.
 *
 * ## Le regole, dal dossier normativo
 *
 * Non sono una sola: **dipendono dal regime**, e confonderle produce versamenti tardivi
 * (sanzionabili) o anticipati.
 *
 * **Appalti, 4% — art. 25-ter** (`SOGLIA_500_TRE_FINESTRE`)
 * Si versa entro il 16 del mese successivo a quello in cui il **cumulo** raggiunge 500 € («raggiunga»: 500 € esatti bastano).
 * A prescindere dalla soglia si versa comunque entro il **16 giugno** e il **16 dicembre**.
 * Le ritenute operate a **dicembre** vanno entro il **16 gennaio** successivo.
 *
 * **Lavoro autonomo e provvigioni, 20% e 23% — artt. 25 e 25-bis** (`SOGLIA_100_ANNUALE`)
 * Soglia **100 €** e **una sola** data-limite annuale, il 16 dicembre. Il confronto non è lo stesso del 4%: si rinvia finché
 * il cumulo «non supera» 100 € (art. 9, c. 4, D.Lgs. 1/2024), quindi 100 € esatti si rinviano ancora
 * (`PlafondRitenuta::superaLaSoglia()`, Coda 717). Valgono le stesse regole per i non residenti al 30%.
 * È un accumulatore **separato**: le ritenute 4% non concorrono a raggiungere i 100 €, né
 * viceversa. Il dossier lo dice esplicitamente — *«non riusare la stessa logica del 4%»*.
 *
 * **Le tre regole che valgono per tutti i regimi con la soglia** (Coda 717, dai casi di t=202):
 * 1. il confronto con la soglia è quello del regime (sopra);
 * 2. la soglia si guarda sul **mese**: si sommano tutte le ritenute operate nel mese, con ciò che i mesi prima hanno rinviato,
 *    e se il cumulo fa scattare il versamento si versa tutto il 16 del mese dopo **quel mese**. Fino alla 1.12.0-beta.13 si
 *    guardava a ogni pagamento: con € 120,00 + € 30,00 di un professionista ad aprile i € 30,00 finivano al 16 dicembre,
 *    sette mesi dopo il 18 maggio giusto;
 * 3. una scadenza fra il 1° e il 20 agosto vale fino al 20 (`CalendarioFiscaleService::scadenzaFiscale()`).
 *
 * **Lavoro dipendente — art. 23** (`MENSILE_SEMPRE`)
 * Nessun rinvio: 16 del mese successivo, sempre. Il motore paghe è fuori scope, ma la
 * regola è blindata qui perché applicare per errore la facoltà di rinvio a queste ritenute
 * sarebbe un versamento tardivo.
 *
 * ## Cosa questo servizio NON fa
 *
 * Non decide il codice tributo (dipende dalla natura del percipiente, non dal regime: lo fa
 * `TipoRitenuta::codiceTributo()`), non conosce ciò che è già stato versato, e non applica
 * il ravvedimento — che è Fase 4 del design, in 1.11.
 */
class PlafondRitenuteService
{
    public function __construct(private CalendarioFiscaleService $calendario) {}

    /**
     * Raggruppa le ritenute operate nelle deleghe da versare.
     *
     * @param  iterable<int,array{data_pagamento: CarbonInterface|string, importo: int, tipo_ritenuta: TipoRitenuta|string}>  $ritenute
     * @return array<int,array{scadenza: CarbonImmutable, plafond: PlafondRitenuta, importo: int, ritenute: array<int,array<string,mixed>>, motivo: string}>
     */
    public function raggruppaPerScadenza(iterable $ritenute): array
    {
        $perPlafond = [];

        foreach ($ritenute as $riga) {
            $tipo = $riga['tipo_ritenuta'] instanceof TipoRitenuta
                ? $riga['tipo_ritenuta']
                : TipoRitenuta::from($riga['tipo_ritenuta']);

            $perPlafond[$tipo->plafond()->value][] = [
                'data' => CarbonImmutable::parse($riga['data_pagamento'])->startOfDay(),
                'importo' => (int) $riga['importo'],
                'tipo_ritenuta' => $tipo,
                'originale' => $riga,
            ];
        }

        $deleghe = [];

        foreach ($perPlafond as $plafondValue => $righe) {
            $plafond = PlafondRitenuta::from($plafondValue);
            usort($righe, fn ($a, $b) => $a['data'] <=> $b['data']);

            foreach ($this->maturaGruppi($righe, $plafond) as $gruppo) {
                $deleghe[] = $gruppo;
            }
        }

        $deleghe = $this->consolidaPerScadenza($deleghe);

        usort($deleghe, fn ($a, $b) => $a['scadenza'] <=> $b['scadenza']);

        return $deleghe;
    }

    /**
     * Accumula le ritenute di un singolo plafond e le chiude in gruppi.
     *
     * Un gruppo si chiude quando succede la prima delle tre cose: il cumulo del mese fa scattare la
     * soglia, si arriva a una data-limite di legge, oppure finiscono le ritenute. Le
     * ritenute di dicembre stanno sempre per conto loro, perché il loro termine è il 16
     * gennaio a prescindere da tutto.
     *
     * Il passo è il **mese**, non la ritenuta (Coda 717): si aggiungono tutte le ritenute del mese, poi si guarda la soglia
     * sul cumulo (con ciò che i mesi prima hanno rinviato), poi la data-limite fra questo mese e il prossimo che ha ritenute.
     * Guardando a ogni ritenuta, un mese che superava la soglia a metà chiudeva il gruppo lì, e le ritenute dello stesso mese
     * venute dopo ripartivano da zero e si rinviavano: € 500,00 + € 40,00 di appalti ad aprile davano € 40,00 al 16 giugno.
     *
     * @param  array<int,array<string,mixed>>  $righe  ordinate per data
     * @return array<int,array<string,mixed>>
     */
    private function maturaGruppi(array $righe, PlafondRitenuta $plafond): array
    {
        $soglia = $this->soglia($plafond);
        $gruppi = [];
        $corrente = [];
        $cumulo = 0;

        // Dicembre si separa PRIMA di accumulare, non durante. Trattandolo dentro il ciclo
        // sfuggiva il caso di una ritenuta di ottobre seguita da una del 10 dicembre: la
        // seconda cade prima del 16 dicembre, quindi non faceva scattare la data-limite,
        // e la prima finiva trascinata al 16 gennaio — cioè versata in ritardo.
        $dicembre = array_values(array_filter($righe, fn ($r) => $r['data']->month === 12));
        $righe = array_values(array_filter($righe, fn ($r) => $r['data']->month !== 12));

        foreach ($this->raggruppaPerAnno($dicembre) as $anno => $righeAnno) {
            $gruppi[] = [
                'scadenza' => $this->calendario->scadenzaFiscale(
                    CarbonImmutable::create($anno + 1, 1, 16)->startOfDay()
                ),
                'plafond' => $plafond,
                'importo' => array_sum(array_column($righeAnno, 'importo')),
                'ritenute' => array_column($righeAnno, 'originale'),
                'motivo' => 'ritenute_dicembre',
            ];
        }

        $chiudi = function (CarbonImmutable $scadenzaTeorica, string $motivo) use (&$gruppi, &$corrente, &$cumulo, $plafond) {
            if ($corrente === []) {
                return;
            }

            $gruppi[] = [
                'scadenza' => $this->calendario->scadenzaFiscale($scadenzaTeorica),
                'plafond' => $plafond,
                'importo' => $cumulo,
                'ritenute' => array_column($corrente, 'originale'),
                'motivo' => $motivo,
            ];

            $corrente = [];
            $cumulo = 0;
        };

        // Le ritenute per mese, nell'ordine: le righe arrivano già ordinate per data.
        $mesi = [];
        foreach ($righe as $riga) {
            $mesi[$riga['data']->format('Y-m')][] = $riga;
        }
        $chiavi = array_keys($mesi);

        foreach ($chiavi as $i => $chiave) {
            foreach ($mesi[$chiave] as $riga) {
                $corrente[] = $riga;
                $cumulo += $riga['importo'];
            }

            // L'ultima ritenuta del mese e la prima del mese dopo che ne ha: le date-limite si guardano al cambio di mese.
            $data = end($mesi[$chiave])['data'];
            $prossima = isset($chiavi[$i + 1]) ? $mesi[$chiavi[$i + 1]][0]['data'] : null;

            // Soglia scattata con il cumulo del mese: si versa entro il 16 del mese successivo a QUESTO mese.
            if ($soglia !== null && $plafond->superaLaSoglia($cumulo, $soglia)) {
                $chiudi(
                    $data->startOfMonth()->addMonthNoOverflow()->day(16)->startOfDay(),
                    'soglia_raggiunta'
                );

                continue;
            }

            // Nessun rinvio possibile per questo regime.
            if ($plafond === PlafondRitenuta::MENSILE_SEMPRE) {
                $chiudi(
                    $data->startOfMonth()->addMonthNoOverflow()->day(16)->startOfDay(),
                    'mensile_obbligatorio'
                );

                continue;
            }

            // Data-limite di legge attraversata prima della prossima ritenuta.
            $limite = $this->dataLimiteAttraversata($data, $prossima, $plafond);

            if ($limite !== null) {
                $chiudi($limite, 'termine_di_legge');

                continue;
            }
        }

        // Ciò che resta è ancora sotto soglia: matura alla prossima data-limite utile.
        if ($corrente !== []) {
            $ultima = end($corrente)['data'];
            $chiudi($this->prossimaDataLimite($ultima, $plafond), 'termine_di_legge');
        }

        return $gruppi;
    }

    /**
     * Fonde i gruppi che maturano nella stessa data e nello stesso plafond.
     *
     * L'accumulo li produce separati — due pagamenti che superano la soglia ciascuno
     * chiudono due gruppi — ma all'Erario si presenta **un F24 per scadenza**, non uno per
     * volta che il contatore ha toccato la soglia. La separazione serve al calcolo, non al
     * documento.
     *
     * @param  array<int,array<string,mixed>>  $deleghe
     * @return array<int,array<string,mixed>>
     */
    private function consolidaPerScadenza(array $deleghe): array
    {
        $fuse = [];

        foreach ($deleghe as $delega) {
            $chiave = $delega['scadenza']->format('Y-m-d').'|'.$delega['plafond']->value;

            if (! isset($fuse[$chiave])) {
                $fuse[$chiave] = $delega;

                continue;
            }

            $fuse[$chiave]['importo'] += $delega['importo'];
            $fuse[$chiave]['ritenute'] = array_merge($fuse[$chiave]['ritenute'], $delega['ritenute']);
        }

        return array_values($fuse);
    }

    /**
     * Raggruppa per anno solare, conservando l'ordine.
     *
     * @param  array<int,array<string,mixed>>  $righe
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function raggruppaPerAnno(array $righe): array
    {
        $perAnno = [];

        foreach ($righe as $riga) {
            $perAnno[(int) $riga['data']->year][] = $riga;
        }

        ksort($perAnno);

        return $perAnno;
    }

    /** La soglia in centesimi, o `null` quando il rinvio non è ammesso. */
    private function soglia(PlafondRitenuta $plafond): ?int
    {
        return match ($plafond) {
            PlafondRitenuta::SOGLIA_500_TRE_FINESTRE => (int) config('fiscale.plafond.appalto_4', 50_000),
            PlafondRitenuta::SOGLIA_100_ANNUALE => (int) config('fiscale.plafond.lavoro_autonomo_20', 10_000),
            PlafondRitenuta::MENSILE_SEMPRE => null,
        };
    }

    /**
     * I mesi che chiudono una finestra: dopo di essi scatta una data-limite.
     *
     * Per il 4% sono maggio (→ 16 giugno) e novembre (→ 16 dicembre). Per il 100 € solo
     * novembre (→ 16 dicembre): una sola data-limite annuale, come prescrive il dossier.
     *
     * @return array<int,int>
     */
    private function mesiDiChiusura(PlafondRitenuta $plafond): array
    {
        return match ($plafond) {
            PlafondRitenuta::SOGLIA_500_TRE_FINESTRE => [5, 11],
            PlafondRitenuta::SOGLIA_100_ANNUALE => [11],
            PlafondRitenuta::MENSILE_SEMPRE => [],
        };
    }

    /**
     * La data-limite che cade fra questa ritenuta e la prossima, se ce n'è una.
     *
     * Serve a chiudere il gruppo al momento giusto quando le ritenute sono sparse: due
     * ritenute a marzo e a settembre non stanno nella stessa delega, perché in mezzo c'è
     * il 16 giugno.
     *
     * ## Si confrontano le FINESTRE, non le date
     *
     * La prima versione chiedeva «la prossima ritenuta cade dopo la data-limite?», e su una
     * coppia marzo/settembre funzionava. Sbagliava però tutto il mezzo mese che sta **fra il
     * 1° e il 15 giugno**: una ritenuta operata il 10 giugno cade *prima* del 16, quindi non
     * faceva scattare la chiusura — e si portava dietro tutte quelle di gennaio-maggio fino al
     * 16 dicembre, cioè **versate in ritardo di sei mesi**, con sanzione.
     *
     * La domanda giusta non è *quando* cade la prossima ritenuta, ma **a quale finestra
     * appartiene**: il 16 giugno chiude ciò che è stato operato fino a maggio, e una ritenuta
     * di giugno matura già nella finestra successiva, qualunque giorno del mese sia.
     *
     * Il caso di dicembre era stato scoperto e corretto separatamente (vedi `maturaGruppi`,
     * dove dicembre si partiziona prima di accumulare). Era lo **stesso difetto sulla finestra
     * gemella**: là era stato corretto il caso, qui si corregge la classe.
     */
    private function dataLimiteAttraversata(CarbonImmutable $data, ?CarbonImmutable $prossima, PlafondRitenuta $plafond): ?CarbonImmutable
    {
        $limite = $this->prossimaDataLimite($data, $plafond);

        // Se non c'è una ritenuta successiva, la chiusura la fa il chiamante.
        if ($limite === null || $prossima === null) {
            return null;
        }

        $limiteProssima = $this->prossimaDataLimite($prossima, $plafond);

        // Stessa data-limite = stessa finestra: le due ritenute restano insieme. Data-limite
        // più avanti = la prossima è già oltre, e il gruppo va chiuso qui.
        return $limiteProssima === null || $limiteProssima->greaterThan($limite)
            ? $limite
            : null;
    }

    /** La prima data-limite di legge a partire da una ritenuta operata in quel mese. */
    private function prossimaDataLimite(CarbonImmutable $data, PlafondRitenuta $plafond): ?CarbonImmutable
    {
        $mesi = $this->mesiDiChiusura($plafond);

        if ($mesi === []) {
            return $data->addMonthNoOverflow()->day(16)->startOfDay();
        }

        foreach ($mesi as $mese) {
            if ($data->month <= $mese) {
                return CarbonImmutable::create($data->year, $mese + 1, 16)->startOfDay();
            }
        }

        // Oltre l'ultimo mese di chiusura: resta solo il termine di gennaio.
        return CarbonImmutable::create($data->year + 1, 1, 16)->startOfDay();
    }
}
