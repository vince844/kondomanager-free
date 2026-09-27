<?php

namespace App\Services\Gestionale;

use Illuminate\Support\Facades\DB;

/**
 * Quanto vale una fattura **al netto delle note di credito collegate** (Coda 165, 1.11.0-beta.36; decisione 26, punto 6
 * in `docs/subentro_e_competenza_temporale.md` §9). Una regola sola, letta da carrello, creazione del piano, ricalcolo,
 * cruscotto, motore e messaggi della scala: due lettori con due regole sono due schermate che si contraddicono.
 *
 * **Quale parte riduce una nota** lo dice la riga della nota, non un calcolo che indovina:
 * - sulla stessa **unità** di una riga ad personam della fattura, o sulla stessa **voce** di una riga fuori preventivo
 *   (per la pregressa: della copertura «sopravvenienza») → riduce la parte che il piano finanzia, **fino a quanto la
 *   fattura ha su quell'unità o su quella voce**; l'eccedenza non è attribuibile. È lo stesso pavimento del motore
 *   (`NettoNoteCollegate::applicaAiComponenti`), che non porta un componente sotto zero: senza, il netto diceva € 50,00
 *   e il motore ripartiva € 100,00 (R2 della Fase 1-bis);
 * - sulla voce di una riga **a preventivo** → riduce la parte a preventivo, che il piano non finanzia (il credito lo
 *   registra già il giornale su quel capitolo: toglierlo anche dal piano lo conterebbe due volte);
 * - su nient'altro della fattura → **non attribuibile**;
 * - una nota **senza righe** — la nota pregressa, che il servizio registra con la sola testata — vale la sua testata, ed
 *   è non attribuibile: fino alla Fase 1-bis valeva € 0,00, e una fattura annullata dalla nota di dicembre importata a
 *   gennaio restava chiesta per intero (R1).
 *
 * E comunque la fattura non chiede mai più del documento al netto: `netto = max(0, min(S − note sulla parte del piano,
 * T − tutte le note))`, dove S è la parte che il piano finanzia e T il documento. Una nota non attribuibile riduce il
 * netto solo quando la parte a preventivo non basta ad assorbirla.
 *
 * S è la somma dei componenti che il motore ripartisce: righe ad personam e fuori preventivo con la loro voce, coperture
 * «sopravvenienza» con una voce. Una riga o una copertura senza voce il motore la scarta, e qui non conta.
 *
 * Importi **lordi** (imponibile più IVA), la base del carrello e del motore; centesimi; il segno delle righe si tiene
 * (una «riga in diminuzione» positiva su una nota riduce la rettifica invece di aumentarla). Una nota contestata non
 * conta. La nota nata da uno storno interno non si collega mai (ha già `stornata_da_id`), quindi qui non arriva.
 *
 * **Una nota in più, non ancora scritta.** I messaggi della scala e gli avvisi dicono quanto varrebbe la fattura *dopo*
 * la nota che si sta registrando o collegando: le sue righe si passano in `$righeInPiu` e seguono lo stesso ciclo delle
 * note a database (R4 della Fase 1-bis: prima si toglieva l'intero importo dalla parte del piano, anche per una nota
 * sulla parte a preventivo, e l'avviso chiedeva di restituire denaro mai chiesto in più).
 */
final class NettoNoteCollegate
{
    /**
     * @param  list<int>  $idFatture
     * @param  array<int, list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>>  $righeInPiu  per fattura, le
     *         righe di una nota non ancora scritta; `riduzione` è la magnitudine lorda in centesimi (positiva riduce)
     * @return array<int, array{
     *     documento: int, parte_piano: int, netto: int, rettificato: int, rettificato_piano: int,
     *     note: list<array{id: int, numero: string, data: ?string, importo: int}>,
     *     componenti: list<array{immobile_id: ?int, conto_id: ?int, importo: int}>
     * }> una voce per ogni fattura chiesta che ha almeno una nota collegata (o righe in più); chi non ne ha non compare
     */
    public static function perFatture(array $idFatture, array $righeInPiu = []): array
    {
        $idFatture = array_values(array_unique(array_map('intval', $idFatture)));
        if ($idFatture === []) {
            return [];
        }

        $note = DB::table('fatture_passive')
            ->whereIn('fattura_rettificata_id', $idFatture)
            ->where('tipo_documento', 'nota_credito')
            ->where('stato_approvazione', '!=', 'contestata')
            ->orderBy('data_documento')->orderBy('id')
            ->get(['id', 'fattura_rettificata_id', 'numero_documento', 'data_documento', 'importo_imponibile', 'importo_iva']);

        $conNote = $note->pluck('fattura_rettificata_id')->map(fn ($v) => (int) $v)
            ->merge(array_keys(array_intersect_key($righeInPiu, array_flip($idFatture))))
            ->unique()->values()->all();
        if ($conNote === []) {
            return [];
        }

        $fatture = DB::table('fatture_passive')->whereIn('id', $conNote)->get(['id', 'is_pregresso', 'importo_imponibile', 'importo_iva'])->keyBy('id');
        $righeFatture = DB::table('righe_fattura')->whereIn('fattura_passiva_id', $conNote)
            ->orderBy('id')
            ->get(['fattura_passiva_id', 'conto_id', 'immobile_id', 'is_sopravvenienza', 'importo_imponibile', 'importo_iva'])
            ->groupBy('fattura_passiva_id');
        $coperture = DB::table('fattura_coperture')->whereIn('fattura_passiva_id', $conNote)
            ->where('tipo_copertura', 'sopravvenienza')
            ->get(['fattura_passiva_id', 'conto_id', 'importo'])
            ->groupBy('fattura_passiva_id');
        $righeNote = $note->isEmpty() ? collect() : DB::table('righe_fattura')->whereIn('fattura_passiva_id', $note->pluck('id')->all())
            ->orderBy('id')
            ->get(['fattura_passiva_id', 'conto_id', 'immobile_id', 'importo_imponibile', 'importo_iva'])
            ->groupBy('fattura_passiva_id');

        $esito = [];
        foreach ($conNote as $idFattura) {
            $f = $fatture[$idFattura] ?? null;
            if ($f === null) {
                continue;
            }
            $documento = (int) $f->importo_imponibile + (int) $f->importo_iva;

            // La parte che il piano finanzia, per unità e per voce: gli stessi componenti del motore. La capienza di una
            // chiave è la somma dei suoi componenti positivi — il motore non porta un componente sotto zero.
            $partePiano = 0;
            $capImmobile = [];
            $capConto = [];
            $contiPreventivo = [];
            if ($f->is_pregresso) {
                foreach ($coperture[$idFattura] ?? [] as $c) {
                    if ($c->conto_id === null) {
                        continue;
                    }
                    $imp = abs((int) $c->importo);
                    $partePiano += $imp;
                    $capConto[(int) $c->conto_id] = ($capConto[(int) $c->conto_id] ?? 0) + $imp;
                }
            } else {
                foreach ($righeFatture[$idFattura] ?? [] as $r) {
                    $lordo = (int) $r->importo_imponibile + (int) $r->importo_iva;
                    if ($r->immobile_id !== null) {
                        $partePiano += $lordo;
                        $capImmobile[(int) $r->immobile_id] = ($capImmobile[(int) $r->immobile_id] ?? 0) + max(0, $lordo);
                    } elseif ($r->is_sopravvenienza && $r->conto_id !== null) {
                        $partePiano += $lordo;
                        $capConto[(int) $r->conto_id] = ($capConto[(int) $r->conto_id] ?? 0) + max(0, $lordo);
                    } elseif (! $r->is_sopravvenienza && $r->conto_id !== null) {
                        $contiPreventivo[(int) $r->conto_id] = true;
                    }
                }
            }

            $rettificato = 0;
            $elenco = [];
            /** @var array<string, array{immobile_id: ?int, conto_id: ?int, riduzione: int, cap: int}> $perChiave */
            $perChiave = [];

            // Si accumula per chiave, e il pavimento si applica alla somma: applicato riga per riga dipendeva dall'ordine
            // delle righe della nota (V1 della verifica delle correzioni) — riordinarle a netto invariato cambiava chi paga.
            $accumula = function (?int $immobile, ?int $conto, int $riduzione) use (&$rettificato, &$perChiave, $capImmobile, $capConto, $contiPreventivo): void {
                $rettificato += $riduzione;
                if ($immobile !== null && array_key_exists($immobile, $capImmobile)) {
                    $chiave = 'i' . $immobile;
                    $perChiave[$chiave] ??= ['immobile_id' => $immobile, 'conto_id' => null, 'riduzione' => 0, 'cap' => $capImmobile[$immobile]];
                } elseif ($immobile === null && $conto !== null && array_key_exists($conto, $capConto) && ! isset($contiPreventivo[$conto])) {
                    $chiave = 'c' . $conto;
                    $perChiave[$chiave] ??= ['immobile_id' => null, 'conto_id' => $conto, 'riduzione' => 0, 'cap' => $capConto[$conto]];
                } else {
                    return; // A preventivo, o non attribuibile: conta solo nel tetto del documento.
                }
                $perChiave[$chiave]['riduzione'] += $riduzione;
            };

            foreach ($note->where('fattura_rettificata_id', $idFattura) as $n) {
                $righe = $righeNote[$n->id] ?? collect();
                $importoNota = 0;
                if ($righe->isEmpty()) {
                    // La nota pregressa: la sola testata, negativa. Non attribuibile.
                    $importoNota = -((int) $n->importo_imponibile + (int) $n->importo_iva);
                    $accumula(null, null, $importoNota);
                }
                foreach ($righe as $rn) {
                    // Le righe della nota sono negative: la rettifica è il loro opposto.
                    $riduzione = -((int) $rn->importo_imponibile + (int) $rn->importo_iva);
                    $importoNota += $riduzione;
                    $accumula($rn->immobile_id !== null ? (int) $rn->immobile_id : null, $rn->conto_id !== null ? (int) $rn->conto_id : null, $riduzione);
                }
                $elenco[] = ['id' => (int) $n->id, 'numero' => (string) $n->numero_documento, 'data' => $n->data_documento, 'importo' => $importoNota];
            }

            foreach ($righeInPiu[$idFattura] ?? [] as $r) {
                $accumula(
                    isset($r['immobile_id']) ? (int) $r['immobile_id'] : null,
                    isset($r['conto_id']) ? (int) $r['conto_id'] : null,
                    (int) $r['riduzione'],
                );
            }

            // Per chiave: la somma delle riduzioni, fino alla capienza (i componenti positivi della chiave, come il
            // motore). Una somma negativa — le righe «in diminuzione» vincono — fa crescere la chiave. Un componente solo
            // per chiave: il motore lo consuma per intero, perché non supera la capienza.
            $rettificatoPiano = 0;
            $componenti = [];
            foreach ($perChiave as $c) {
                $attribuita = $c['riduzione'] > 0 ? min($c['riduzione'], max(0, $c['cap'])) : $c['riduzione'];
                if ($attribuita === 0) {
                    continue;
                }
                $rettificatoPiano += $attribuita;
                $componenti[] = ['immobile_id' => $c['immobile_id'], 'conto_id' => $c['conto_id'], 'importo' => -$attribuita];
            }

            $esito[$idFattura] = [
                'documento' => $documento,
                'parte_piano' => $partePiano,
                'netto' => max(0, min($partePiano - $rettificatoPiano, $documento - $rettificato)),
                'rettificato' => $rettificato,
                'rettificato_piano' => $rettificatoPiano,
                'note' => $elenco,
                'componenti' => $componenti,
            ];
        }

        return $esito;
    }

    /**
     * Le riduzioni delle note collegate, applicate ai componenti di una fattura come li costruisce il motore (Coda 165).
     * Ogni riduzione va sui componenti della stessa unità (ad personam) o della stessa voce (fuori preventivo, o la
     * copertura «sopravvenienza» della pregressa), e non li porta sotto zero. Sta qui, e non nel motore, perché la
     * leggono anche la copertura del cruscotto e il piano dei conti (`ripartoPerConto`): una funzione sola, o due
     * schermate che si contraddicono (V6 della verifica delle correzioni).
     *
     * Le riduzioni arrivano già una per chiave e già entro la capienza (`perFatture`): qui non si perde niente.
     *
     * @param  list<array<string, mixed>>  $componenti  con `immobile_id`, `conto_id`, `importo`
     * @param  list<array{immobile_id: ?int, conto_id: ?int, importo: int}>  $riduzioni  importi col segno della nota
     * @return list<array<string, mixed>>
     */
    public static function applicaAiComponenti(array $componenti, array $riduzioni): array
    {
        foreach ($riduzioni as $r) {
            $delta = (int) $r['importo'];
            foreach ($componenti as $i => $c) {
                if ($delta === 0) {
                    break;
                }
                $stessa = $r['immobile_id'] !== null
                    ? $c['immobile_id'] === $r['immobile_id']
                    : ($c['immobile_id'] === null && $c['conto_id'] === $r['conto_id']);
                if (! $stessa) {
                    continue;
                }
                // Una riduzione negativa (le righe «in diminuzione» della nota vincono): il componente cresce.
                if ($delta > 0) {
                    $componenti[$i]['importo'] += $delta;
                    $delta = 0;
                    break;
                }
                if ($componenti[$i]['importo'] <= 0) {
                    continue;
                }
                $tolto = min($componenti[$i]['importo'], -$delta);
                $componenti[$i]['importo'] -= $tolto;
                $delta += $tolto;
            }
        }

        return array_values(array_filter($componenti, fn (array $c) => (int) $c['importo'] !== 0));
    }

    /**
     * Quanto un piano ripartisce su ciascuna **voce** per una fattura con note collegate: i componenti del motore, le note
     * applicate, il target (`importo_collegato`, o con lo zero il minore fra naturale e netto) distribuito in proporzione
     * come fa il motore. Solo per i piani **senza** quote: per gli altri vale `ripartoRegistratoPerConto`. Le unità ad personam non sono voci e restano fuori. Per la copertura del cruscotto e del piano
     * dei conti, che scalavano l'importo del piano sul lordo della fattura senza la nota (V6): la voce coperta per intero
     * risultava in deficit. Le fatture senza note, in un piano che non ne ha una rettificata, restano col calcolo di
     * prima: il suo difetto è più vecchio della beta.
     *
     * @return array<int, int>|null  centesimi per conto_id, o `null` se la fattura non ha note collegate
     */
    public static function ripartoPerConto(int $idFattura, int $importoCollegato): ?array
    {
        $netto = self::perFattura($idFattura);
        if ($netto === null) {
            return null;
        }

        $f = DB::table('fatture_passive')->where('id', $idFattura)->first(['is_pregresso']);
        $componenti = [];
        if ($f?->is_pregresso) {
            foreach (DB::table('fattura_coperture')->where('fattura_passiva_id', $idFattura)->where('tipo_copertura', 'sopravvenienza')->whereNotNull('conto_id')->get() as $c) {
                if (($imp = abs((int) $c->importo)) !== 0) {
                    $componenti[] = ['immobile_id' => null, 'conto_id' => (int) $c->conto_id, 'importo' => $imp];
                }
            }
        } else {
            $righe = DB::table('righe_fattura')->where('fattura_passiva_id', $idFattura)
                ->where(fn ($q) => $q->where('is_sopravvenienza', true)->orWhereNotNull('immobile_id'))
                ->orderBy('id')->get();
            foreach ($righe as $r) {
                $imp = (int) $r->importo_imponibile + (int) $r->importo_iva;
                if ($imp === 0 || ($r->immobile_id === null && $r->conto_id === null)) {
                    continue;
                }
                $componenti[] = ['immobile_id' => $r->immobile_id !== null ? (int) $r->immobile_id : null, 'conto_id' => $r->conto_id !== null ? (int) $r->conto_id : null, 'importo' => $imp];
            }
        }

        $componenti = self::applicaAiComponenti($componenti, $netto['componenti']);
        $naturale = array_sum(array_column($componenti, 'importo'));
        if ($naturale <= 0) {
            return [];
        }
        $target = $importoCollegato > 0 ? $importoCollegato : min($naturale, $netto['netto']);
        $importi = $target === $naturale
            ? array_column($componenti, 'importo')
            : \App\Helpers\MoneyHelper::distribuisciPesiNormalizzati(array_map(fn ($c) => $c['importo'] / $naturale, $componenti), $target);

        $perConto = [];
        foreach ($componenti as $i => $c) {
            if ($c['immobile_id'] === null && $c['conto_id'] !== null) {
                $perConto[$c['conto_id']] = ($perConto[$c['conto_id']] ?? 0) + (int) ($importi[$i] ?? 0);
            }
        }

        return $perConto;
    }

    /**
     * Quanto un piano **con le quote già generate** ripartisce su ciascuna voce: la somma delle sue righe di riparto
     * (`righe_riparto`, tipo «riparto», persistite dalla beta.29), non la regola di oggi. Con incassi una nota si collega e
     * le rate restano, per decisione: rifatto con le note di oggi, il cruscotto mostrava la voce coperta per € 300,00 più
     * di quanto il piano chiede (W1 del terzo giro — lo stesso principio di R6 per la stampa ricostruita). Vale per tutto
     * il piano, anche per le fatture senza note che stanno insieme a una fattura rettificata: le righe di riparto non
     * portano la fattura, e il piano non si divide (X2 del quarto giro). `null` se il piano non ha righe: per una bozza
     * non generata vale `ripartoPerConto`; per un piano con le quote di prima della beta.29 si legge senza note (X1).
     *
     * @return array<int, int>|null  centesimi per conto_id
     */
    public static function ripartoRegistratoPerConto(int $pianoId): ?array
    {
        $righe = DB::table('righe_riparto')->where('piano_rate_id', $pianoId)->where('tipo', 'riparto')->whereNotNull('conto_id')
            ->selectRaw('conto_id, SUM(importo) as totale')->groupBy('conto_id')->pluck('totale', 'conto_id');

        return $righe->isEmpty() ? null : $righe->map(fn ($v) => (int) $v)->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();
    }

    /**
     * Il netto di una fattura sola, o `null` se non ha note collegate né righe in più (vale la sua parte piena).
     *
     * @param  list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>  $righeInPiu
     */
    public static function perFattura(int $idFattura, array $righeInPiu = []): ?array
    {
        return self::perFatture([$idFattura], $righeInPiu === [] ? [] : [$idFattura => $righeInPiu])[$idFattura] ?? null;
    }

    /**
     * Le righe di una nota già scritta, nella forma di `$righeInPiu`: dalle sue righe, o dalla testata se non ne ha (la
     * nota pregressa). Per «Collega a una fattura» e per l'elenco delle candidate di una nota esistente.
     *
     * @return list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>
     */
    public static function righeDellaNota(int $idNota): array
    {
        $righe = DB::table('righe_fattura')->where('fattura_passiva_id', $idNota)->orderBy('id')
            ->get(['conto_id', 'immobile_id', 'importo_imponibile', 'importo_iva']);
        if ($righe->isEmpty()) {
            $testata = DB::table('fatture_passive')->where('id', $idNota)->first(['importo_imponibile', 'importo_iva']);

            return $testata === null ? [] : [[
                'conto_id' => null, 'immobile_id' => null,
                'riduzione' => abs((int) $testata->importo_imponibile + (int) $testata->importo_iva),
            ]];
        }

        return $righe->map(fn ($r) => [
            'conto_id' => $r->conto_id !== null ? (int) $r->conto_id : null,
            'immobile_id' => $r->immobile_id !== null ? (int) $r->immobile_id : null,
            'riduzione' => -((int) $r->importo_imponibile + (int) $r->importo_iva),
        ])->values()->all();
    }

    /** Somma per chiave (unità o voce) delle riduzioni sulla parte del piano, negative: per dire se una modifica riduce di più una chiave. */
    public static function impronta(?array $netto): array
    {
        $somme = [];
        foreach ($netto['componenti'] ?? [] as $c) {
            $chiave = ($c['immobile_id'] !== null ? 'i' . $c['immobile_id'] : 'c' . $c['conto_id']);
            $somme[$chiave] = ($somme[$chiave] ?? 0) + (int) $c['importo'];
        }
        ksort($somme);

        return array_filter($somme, fn ($v) => $v !== 0);
    }
}
