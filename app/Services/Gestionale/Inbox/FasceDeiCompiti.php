<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

use App\Helpers\DateHelper;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La regola di «scaduto» dell'Action inbox, scritta qui e solo qui: la leggono le card, il grafico «Da quanto aspettano», i gruppi dell'elenco, i filtri e il
 * numero nel menu. Il giorno del compito (la data di `start_time`, come l'agenda la scrive) si confronta con il giorno dell'amministratore
 * (`DateHelper::oggiUtente()`), non con l'ora del server: alle 00:30 di Roma il compito di ieri è scaduto anche se sul server è ancora ieri.
 *
 * **Un compito di oggi non è scaduto**: è nei prossimi 7 giorni (oggi e i sette giorni dopo), e la riga dice «oggi». Scaduto è un compito con il giorno prima
 * di oggi, come una data passata del riquadro dei sei mesi.
 *
 * Le fasce, in giorni da oggi: oltre 90 giorni fa (prima di 90 giorni fa), da 31 a 90 giorni, gli ultimi 30 giorni (da 30 giorni fa a ieri), i prossimi 7
 * giorni (da oggi a fra 7), fra 8 e 30 giorni, oltre (fra 31 giorni o più).
 *
 * **Tre tipi non hanno una scadenza** e stanno a parte, ognuno nel suo gruppo: le segnalazioni di guasto aperte (il giorno è quello in cui sono state aperte),
 * i bonifici segnalati dai condòmini (il giorno della segnalazione del pagamento) e ciò che aspetta un'approvazione (il giorno del primo commento o allegato
 * ancora in attesa; decisioni 445 e 449). Non sono né scaduti né in arrivo: non contano nelle card «Scaduti» e «Prossimi 7 giorni», nelle schede «Scaduti» e
 * «In arrivo», nel grafico «Da quanto aspettano»; hanno le loro card. Nell'elenco i gruppi vanno in quest'ordine: gli scaduti, le segnalazioni aperte, i
 * bonifici da verificare, ciò che è da moderare, gli altri.
 */
final class FasceDeiCompiti
{
    public const BONIFICO = 'verifica_pagamento';
    public const SEGNALAZIONE = 'segnalazione_guasto';
    public const MODERARE = 'da_moderare';
    public const BONIFICI = 'bonifici';
    public const SEGNALAZIONI = 'segnalazioni';
    public const DA_MODERARE = 'da_moderare';

    /** I tipi senza una scadenza, e la fascia (che è anche il gruppo) in cui stanno a parte. */
    public const A_PARTE = [self::SEGNALAZIONE => self::SEGNALAZIONI, self::BONIFICO => self::BONIFICI, self::MODERARE => self::DA_MODERARE];

    /** Le fasce in ordine, con i giorni da oggi: da (compreso) e a (escluso); null è senza limite. */
    public const FASCE = [
        'oltre_90' => [null, -90],
        'da_31_a_90' => [-90, -30],
        'ultimi_30' => [-30, 0],
        'prossimi_7' => [0, 8],
        'fra_8_e_30' => [8, 31],
        'oltre_30' => [31, null],
    ];

    public const ETICHETTE = [
        'oltre_90' => 'oltre 90 giorni fa',
        'da_31_a_90' => 'da 31 a 90 giorni',
        'ultimi_30' => 'ultimi 30 giorni',
        'prossimi_7' => 'prossimi 7 giorni',
        'fra_8_e_30' => 'fra 8 e 30 giorni',
        'oltre_30' => 'fra più di 30 giorni',
    ];

    /** Le fasce dei compiti scaduti. */
    public const SCADUTE = ['oltre_90', 'da_31_a_90', 'ultimi_30'];

    /** Le barre del grafico «Da quanto aspettano». */
    public const DEL_GRAFICO = ['oltre_90', 'da_31_a_90', 'ultimi_30', 'prossimi_7', 'fra_8_e_30'];

    /** Le fasce dei compiti in arrivo: dal giorno di oggi in poi. */
    public const IN_ARRIVO = ['prossimi_7', 'fra_8_e_30', 'oltre_30'];

    /**
     * I filtri per data dell'elenco: le schede «Scaduti» e «In arrivo» (la card «Scaduti» è la scheda), e ogni barra del grafico (la card «Prossimi 7 giorni»
     * è la barra «prossimi 7 giorni»), che sta dentro la sua scheda.
     */
    public const QUANDO = [
        'scaduti' => self::SCADUTE,
        'in_arrivo' => self::IN_ARRIVO,
        'oltre_90' => ['oltre_90'],
        'da_31_a_90' => ['da_31_a_90'],
        'ultimi_30' => ['ultimi_30'],
        'prossimi_7' => ['prossimi_7'],
        'fra_8_e_30' => ['fra_8_e_30'],
    ];

    /** I gruppi dell'elenco, nell'ordine in cui si vedono. */
    public const GRUPPI = ['scaduti', 'segnalazioni', 'bonifici', 'da_moderare', 'avanti'];

    /** Le schede dell'elenco: «Tutti», «Scaduti», «In arrivo». */
    public const SCHEDE = ['tutti', 'scaduti', 'in_arrivo'];

    public function __construct(public readonly string $oggi) {}

    /** La regola con il giorno dell'amministratore di adesso. */
    public static function oggi(): self
    {
        return new self(DateHelper::oggiUtente());
    }

    /** Il tipo del compito in SQL, da `meta.type` (lo scrivono `InboxService::createTask` e i listener), come lo legge la casella da sempre. */
    public static function sqlTipo(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "JSON_EXTRACT(eventi.meta, '$.\"type\"')"
            : "CONVERT(JSON_UNQUOTE(JSON_EXTRACT(eventi.meta, '$.\"type\"')) USING utf8mb4)";
    }

    /** La priorità di una segnalazione in SQL, da `meta.priority` (la scrive `CompitoDellaSegnalazione`). */
    public static function sqlPriorita(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "JSON_EXTRACT(eventi.meta, '$.\"priority\"')"
            : "CONVERT(JSON_UNQUOTE(JSON_EXTRACT(eventi.meta, '$.\"priority\"')) USING utf8mb4)";
    }

    /** La mezzanotte del giorno a `$giorni` giorni da oggi, nella forma in cui l'agenda scrive le ore. */
    public function inizioDelGiorno(int $giorni): string
    {
        return CarbonImmutable::parse($this->oggi, 'UTC')->addDays($giorni)->format('Y-m-d') . ' 00:00:00';
    }

    /** I giorni da oggi al giorno del compito: negativi prima, zero oggi, positivi dopo. */
    public function giorni(string $giorno): int
    {
        return (int) CarbonImmutable::parse($this->oggi, 'UTC')->diffInDays(CarbonImmutable::parse(substr($giorno, 0, 10), 'UTC'), false);
    }

    /** La fascia di un compito dal suo giorno e dal suo tipo; la segnalazione e il bonifico segnalato sono a parte. */
    public function fascia(string $giorno, ?string $tipo): string
    {
        if (isset(self::A_PARTE[(string) $tipo])) {
            return self::A_PARTE[(string) $tipo];
        }
        $d = $this->giorni($giorno);
        foreach (self::FASCE as $chiave => [$da, $a]) {
            if (($da === null || $d >= $da) && ($a === null || $d < $a)) {
                return $chiave;
            }
        }

        return 'oltre_30';
    }

    /** La scheda di un filtro per data: «Scaduti» per gli scaduti e le loro barre, «In arrivo» per gli altri, «Tutti» senza filtro. */
    public static function scheda(?string $quando): string
    {
        if ($quando === null || ! isset(self::QUANDO[$quando])) {
            return 'tutti';
        }

        return array_intersect(self::QUANDO[$quando], self::SCADUTE) !== [] ? 'scaduti' : 'in_arrivo';
    }

    /** Il gruppo dell'elenco di una fascia: gli scaduti, le segnalazioni, i bonifici, gli altri («Più avanti»). */
    public static function gruppo(string $fascia): string
    {
        if (in_array($fascia, self::A_PARTE, true)) {
            return $fascia;
        }

        return in_array($fascia, self::SCADUTE, true) ? 'scaduti' : 'avanti';
    }

    /**
     * La fascia in SQL, con le stesse soglie: un `CASE` sul giorno del compito.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function sqlFascia(string $tipoSql): array
    {
        $casi = [];
        $valori = [];
        foreach (self::A_PARTE as $tipo => $fascia) {
            $casi[] = "WHEN {$tipoSql} = ? THEN ?";
            $valori[] = $tipo;
            $valori[] = $fascia;
        }
        foreach (self::FASCE as $chiave => [, $a]) {
            if ($a !== null) {
                $casi[] = 'WHEN eventi.start_time < ? THEN ?';
                $valori[] = $this->inizioDelGiorno($a);
                $valori[] = $chiave;
            }
        }

        return ['(CASE ' . implode(' ', $casi) . ' ELSE ? END)', [...$valori, array_key_last(self::FASCE)]];
    }

    /**
     * L'ordine dei gruppi in SQL: gli scaduti, le segnalazioni aperte, i bonifici da verificare, ciò che è da moderare, gli altri.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function sqlOrdineDeiGruppi(string $tipoSql): array
    {
        return ["(CASE WHEN {$tipoSql} = ? THEN 1 WHEN {$tipoSql} = ? THEN 2 WHEN {$tipoSql} = ? THEN 3 WHEN eventi.start_time < ? THEN 0 ELSE 4 END)",
            [self::SEGNALAZIONE, self::BONIFICO, self::MODERARE, $this->inizioDelGiorno(0)]];
    }

    /** L'elenco filtrato per data (una delle chiavi di `QUANDO`): le fasce sono contigue, e la segnalazione e il bonifico segnalato non sono in nessuna. */
    public function filtraQuando(Builder $query, string $tipoSql, string $quando): void
    {
        $fasce = self::QUANDO[$quando] ?? null;
        if ($fasce === null) {
            return;
        }
        $da = self::FASCE[$fasce[0]][0];
        $a = self::FASCE[$fasce[array_key_last($fasce)]][1];
        if ($da !== null) {
            $query->where('eventi.start_time', '>=', $this->inizioDelGiorno($da));
        }
        if ($a !== null) {
            $query->where('eventi.start_time', '<', $this->inizioDelGiorno($a));
        }
        $query->whereRaw("COALESCE({$tipoSql}, '') NOT IN (" . implode(', ', array_fill(0, count(self::A_PARTE), '?')) . ')', array_keys(self::A_PARTE));
    }

    /**
     * I compiti del numero nel menu: quelli con il giorno arrivato (oggi compreso, con la stessa soglia delle fasce), i bonifici segnalati, le segnalazioni e ciò
     * che è da moderare, qualunque sia il loro giorno.
     */
    public function filtraPerIlMenu(Builder $query, string $tipoSql): void
    {
        $query->where(fn (Builder $q) => $q->where('eventi.start_time', '<', $this->inizioDelGiorno(1))
            ->orWhereRaw("{$tipoSql} IN (?, ?, ?)", [self::BONIFICO, self::SEGNALAZIONE, self::MODERARE]));
    }
}
