<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\RegistroModifica;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La lettura del registro per le pagine e per i casi speciali (oscuramento, ripristino): la «Storia» di una scheda e le righe di un condominio (o
 * dello studio) con i filtri del §14. Prima di leggere si sigilla (§3).
 *
 * **Le righe annullate da un ripristino** (decisione 546, P14): la riga `ripristino_copia` ha nel lotto `sequenza_comune`, l'ultima riga della copia; le righe
 * fra quella e il ripristino sono la storia che il ripristino ha tolto. Una riga è annullata se c'è un ripristino dopo di lei, **lui stesso non annullato**,
 * con la riga in comune prima di lei. Si legge dal più recente all'indietro: ogni ripristino vivo fa saltare alla sua riga in comune, e i ripristini nel
 * tratto saltato non contano più. Così una copia fatta **dopo** un primo ripristino porta con sé quel ripristino e le righe che annullava, e le lascia
 * annullate; una copia fatta **prima** di lui, ripristinata dopo, lo annulla a sua volta e rimette in vita quelle righe. La «Storia», i soggetti e
 * «Ripristina» le saltano; la pagina del registro le mostra con il badge «Annullata dal ripristino del …».
 */
final class LetturaDelRegistro
{
    public const PER_PAGINA = 20;

    /**
     * I ripristini di una copia che contano, dal più recente, con le sequenze che annullano (dalla riga in comune, esclusa, alla riga del ripristino,
     * esclusa). Una lista vuota se non ce n'è. Si legge una volta e si passa dove serve: l'indice (`azione`, `sequenza`) la rende una lettura breve.
     *
     * @return list<array{riga_id: int, avvenuta_il: Carbon, dalla: int, alla: int, copia_del: ?string}>
     */
    public static function annullamento(): array
    {
        $ripristini = DB::table('registro_modifiche')->where('azione', 'ripristino_copia')->whereNotNull('sequenza')->orderByDesc('sequenza')
            ->get(['id', 'sequenza', 'avvenuta_il', 'lotto']);
        $intervalli = [];
        // Fin dove arriva la storia viva: sopra, le righe annullate da un ripristino più recente (lui stesso vivo).
        $vivaFino = PHP_INT_MAX;
        foreach ($ripristini as $ripristino) {
            $sequenza = (int) $ripristino->sequenza;
            if ($sequenza > $vivaFino) {
                // Annullato a sua volta: la copia ripristinata dopo è di prima di lui.
                continue;
            }
            $lotto = json_decode((string) $ripristino->lotto, true) ?: [];
            if (! isset($lotto['sequenza_comune'])) {
                // Una copia senza righe in comune (le righe di prima sono nel PDF): niente da annullare, e la storia prima di lei resta com'è.
                continue;
            }
            $comune = (int) $lotto['sequenza_comune'];
            $intervalli[] = ['riga_id' => (int) $ripristino->id, 'avvenuta_il' => Carbon::parse((string) $ripristino->avvenuta_il, 'UTC'),
                'dalla' => $comune, 'alla' => $sequenza, 'copia_del' => $lotto['copia_del'] ?? null];
            $vivaFino = $comune;
        }

        return $intervalli;
    }

    /**
     * Il ripristino che annulla la riga, se c'è.
     *
     * @param list<array{riga_id: int, avvenuta_il: Carbon, dalla: int, alla: int, copia_del: ?string}>|null $annullamento quello letto, o `null` per leggerlo
     * @return array{riga_id: int, avvenuta_il: Carbon, dalla: int, alla: int, copia_del: ?string}|null
     */
    public static function annullataDa(RegistroModifica $riga, ?array $annullamento = null): ?array
    {
        if ($riga->sequenza === null) {
            return null;
        }
        foreach ($annullamento ?? self::annullamento() as $intervallo) {
            if ($riga->sequenza > $intervallo['dalla'] && $riga->sequenza < $intervallo['alla']) {
                return $intervallo;
            }
        }

        return null;
    }

    /** Se una riga è annullata da un ripristino. */
    public static function eAnnullata(RegistroModifica $riga, ?array $annullamento = null): bool
    {
        return self::annullataDa($riga, $annullamento) !== null;
    }

    /**
     * La «Storia» di una scheda, dalla più recente, a pagine, senza le righe annullate (P14). Compaiono: le righe della scheda (`scheda_tipo`/`scheda_id`),
     * quelle dell'oggetto senza un'altra scheda, e per una persona (anagrafica, fornitore) le righe dei legami che la nominano fra i soggetti (la
     * titolarità, il rappresentante, l'accordo: `NELLA_STORIA_DEI_SOGGETTI`).
     *
     * @return Builder<RegistroModifica>
     */
    public static function storia(string $tipo, ?int $id): Builder
    {
        SigilloDelRegistro::sigillaSenzaFermare();
        $legami = array_values(array_map(fn ($d) => $d::TIPO, array_filter(CatalogoDelRegistro::TRATTO, fn ($d) => $d::NELLA_STORIA_DEI_SOGGETTI)));

        $query = RegistroModifica::query()->where(function (Builder $q) use ($tipo, $id, $legami) {
            $q->where(fn (Builder $w) => $w->where('scheda_tipo', $tipo)->when($id === null, fn ($x) => $x->whereNull('scheda_id'), fn ($x) => $x->where('scheda_id', $id)))
                ->orWhere(fn (Builder $w) => $w->whereNull('scheda_tipo')->where('oggetto_tipo', $tipo)
                    ->when($id === null, fn ($x) => $x->whereNull('oggetto_id'), fn ($x) => $x->where('oggetto_id', $id)));
            if ($id !== null && in_array($tipo, ['anagrafica', 'fornitore'], true)) {
                $q->orWhere(fn (Builder $w) => $w->whereIn('oggetto_tipo', $legami)->whereIn('id', DB::table('registro_modifiche_soggetti')
                    ->where('soggetto_tipo', $tipo)->where('soggetto_id', $id)->select('riga_id')));
            }
        });

        return self::senzaAnnullate($query)->orderByDesc('avvenuta_il')->orderByDesc('id');
    }

    /**
     * Le righe **per rimando** (§6.4 del progetto della tappa 2): quelle di altre schede che rimandano a una di queste cose, per esempio le schede collegate a
     * una delibera dell'assemblea («Delibera: nessuna → assemblea del 22/10/2026, punto 1»), che il «Diario» mette accanto alle righe dell'assemblea. Senza le
     * annullate, dalla più recente. Il rimando è un JSON della riga: la lettura è per tipo e per id, con l'indice della scheda fuori gioco; le righe con un
     * rimando sono poche rispetto al registro.
     *
     * @param list<int> $ids
     * @return Builder<RegistroModifica>
     */
    public static function perRimando(string $tipo, array $ids): Builder
    {
        SigilloDelRegistro::sigillaSenzaFermare();
        $query = RegistroModifica::query()->whereNotNull('rimando')->where('rimando->tipo', $tipo)
            ->whereIn('rimando->id', $ids === [] ? [0] : array_values(array_map('intval', $ids)));

        return self::senzaAnnullate($query)->orderByDesc('avvenuta_il')->orderByDesc('id');
    }

    /**
     * La «Storia» di un elenco intero (per le pagine con una riga per scheda e una sola intestazione: le risorse e i fondi di un condominio,
     * le categorie dei fornitori dello studio): le righe di tutte le schede di quel tipo, nel condominio se c'è, dalla più recente, senza le annullate.
     *
     * @return Builder<RegistroModifica>
     */
    public static function storiaDiUnElenco(string $tipo, ?int $condominioId): Builder
    {
        SigilloDelRegistro::sigillaSenzaFermare();
        $query = RegistroModifica::query()->where(fn (Builder $q) => $q->where('scheda_tipo', $tipo)
            ->orWhere(fn (Builder $w) => $w->whereNull('scheda_tipo')->where('oggetto_tipo', $tipo)));
        if ($condominioId !== null) {
            $query->whereIn('id', DB::table('registro_modifiche_condomini')->where('condominio_id', $condominioId)->select('riga_id'));
        }

        return self::senzaAnnullate($query)->orderByDesc('avvenuta_il')->orderByDesc('id');
    }

    /**
     * Le righe di un condominio (o di tutto lo studio con `null`), con i filtri del §14: `chi` (un id di persona, o «programma»), `area`, `azione`, `mese`
     * («2026-12»), `cerca` (nel nome dell'oggetto, nel riepilogo e nei campi); per la stampa anche `dal` e `al` («2026-01-01», giorni di Roma, tutti e due
     * compresi). Le righe annullate restano: la pagina le segna.
     *
     * @param array{chi?: int|string|null, area?: ?string, azione?: ?string, mese?: ?string, cerca?: ?string, condominio?: ?int, dal?: ?string, al?: ?string} $filtri
     * @return Builder<RegistroModifica>
     */
    public static function delCondominio(?int $condominioId, array $filtri = []): Builder
    {
        SigilloDelRegistro::sigillaSenzaFermare();
        $condominioId ??= isset($filtri['condominio']) ? (int) $filtri['condominio'] : null;
        $query = RegistroModifica::query();
        if ($condominioId !== null) {
            $query->whereIn('id', DB::table('registro_modifiche_condomini')->where('condominio_id', $condominioId)->select('riga_id'));
        }
        $chi = $filtri['chi'] ?? null;
        if ($chi === Autore::PROGRAMMA) {
            $query->where('autore_tipo', Autore::PROGRAMMA);
        } elseif ($chi !== null && $chi !== '') {
            $query->where('autore_tipo', Autore::PERSONA)->where('autore_id', (int) $chi);
        }
        if (! empty($filtri['area'])) {
            $query->where('area', $filtri['area']);
        }
        if (! empty($filtri['azione'])) {
            $query->where('azione', $filtri['azione']);
        }
        if (! empty($filtri['mese']) && preg_match('/^\d{4}-\d{2}$/', (string) $filtri['mese'])) {
            // Il mese di Roma, non quello del server: le righe sono in UTC.
            $fuso = (string) config('app.user_timezone', 'Europe/Rome');
            $inizio = Carbon::parse($filtri['mese'] . '-01 00:00:00', $fuso)->utc();
            $query->where('avvenuta_il', '>=', $inizio->format('Y-m-d H:i:s'))
                ->where('avvenuta_il', '<', $inizio->copy()->setTimezone($fuso)->addMonth()->utc()->format('Y-m-d H:i:s'));
        }
        // Il periodo dal … al … della stampa: giorni di Roma, dall'inizio del primo alla fine del secondo (le righe sono in UTC).
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        if (! empty($filtri['dal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filtri['dal'])) {
            $query->where('avvenuta_il', '>=', Carbon::parse($filtri['dal'] . ' 00:00:00', $fuso)->utc()->format('Y-m-d H:i:s'));
        }
        if (! empty($filtri['al']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filtri['al'])) {
            $query->where('avvenuta_il', '<', Carbon::parse($filtri['al'] . ' 00:00:00', $fuso)->addDay()->utc()->format('Y-m-d H:i:s'));
        }
        if (! empty($filtri['cerca'])) {
            $cerca = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filtri['cerca']) . '%';
            $query->where(fn (Builder $q) => $q->where('oggetto_nome', 'like', $cerca)->orWhere('riepilogo', 'like', $cerca)->orWhere('campi', 'like', $cerca));
        }

        return $query->orderByDesc('avvenuta_il')->orderByDesc('id');
    }

    /**
     * Una riga come la legge una pagina (P18): `prima` e `dopo` dei campi, mai `valore_*`; nessuna colonna tecnica (le impronte, la sequenza); la scritta
     * del ripristino che l'annulla, calcolata alla lettura.
     *
     * @return array<string, mixed>
     */
    public static function perLaPagina(RegistroModifica $riga, ?array $annullamento = null): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $annullata = self::annullataDa($riga, $annullamento);

        return [
            'id' => $riga->id,
            'avvenuta_il' => $riga->avvenuta_il->copy()->setTimezone($fuso)->format('d/m/Y H:i'),
            'registrata_il' => $riga->registrata_il->copy()->setTimezone($fuso)->format('d/m/Y H:i'),
            'autore' => ['tipo' => $riga->autore_tipo, 'nome' => $riga->autore_nome, 'ruolo' => $riga->autore_ruolo, 'origine' => $riga->origine,
                'dettaglio' => $riga->origine_dettaglio],
            'area' => $riga->area,
            'azione' => $riga->azione,
            'oggetto' => ['tipo' => $riga->oggetto_tipo, 'id' => $riga->oggetto_id, 'nome' => $riga->oggetto_nome],
            'scheda' => ['tipo' => $riga->scheda_tipo ?? $riga->oggetto_tipo, 'id' => $riga->scheda_tipo !== null ? $riga->scheda_id : $riga->oggetto_id],
            'riepilogo' => $riga->riepilogo,
            'perche' => $riga->perche,
            'campi' => array_map(fn ($c) => ['chiave' => $c['chiave'] ?? null, 'etichetta' => $c['etichetta'] ?? null, 'prima' => $c['prima'] ?? null,
                'dopo' => $c['dopo'] ?? null, 'segreto' => (bool) ($c['segreto'] ?? false)], $riga->campi ?? []),
            'rimando' => $riga->rimando,
            'oscurata' => $riga->oscurata_il !== null,
            'annullata_dal_ripristino_del' => $annullata === null ? null : $annullata['avvenuta_il']->copy()->setTimezone($fuso)->format('d/m/Y'),
        ];
    }

    /**
     * Senza le righe annullate: una condizione per ogni ripristino che conta (di solito nessuno, o uno).
     *
     * @param Builder<RegistroModifica> $query
     * @return Builder<RegistroModifica>
     */
    private static function senzaAnnullate(Builder $query): Builder
    {
        foreach (self::annullamento() as $intervallo) {
            if ($intervallo['alla'] - $intervallo['dalla'] < 2) {
                continue;
            }
            $query->where(fn (Builder $q) => $q->whereNull('sequenza')->orWhereNotBetween('sequenza', [$intervallo['dalla'] + 1, $intervallo['alla'] - 1]));
        }

        return $query;
    }
}
