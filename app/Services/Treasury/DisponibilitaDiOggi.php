<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Treasury;

use App\Models\Condominio;
use App\Models\Gestionale\NotaSintetica;
use App\Services\Gestionale\LiquiditaVincolataService;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\StatoPatrimonialeService;
use App\Services\Gestionale\TipoDelFondo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «Disponibile oggi» della tesoreria e «Da dove viene»: la liquidità della fotografia dello Stato patrimoniale a oggi, scomposta voce per voce
 * ({@see LiquiditaVincolataService::allaData()}), meno ciò che trattengono i fondi che non si possono usare: il loro saldo sopra zero
 * ({@see \App\Services\Gestionale\TipoDelFondo::trattiene()}; un fondo vincolato in rosso è già nel totale). È la stessa cifra della pagina dello Stato
 * patrimoniale allo stesso giorno (`liquidita_totale − vincolata`) e, con un conto solo e senza registrazioni dopo oggi, della riga «Si può usare» della scheda
 * «Cassa» del rendiconto, che legge anche le registrazioni dopo oggi: una fonte sola, nessun calcolo a parte. Non supera mai tutto il denaro.
 *
 * **«Oggi» vuol dire oggi**: i movimenti con la data di competenza fino a oggi (il confronto è «minore del giorno dopo», come la fotografia), più le
 * aperture delle casse sempre, anche con una data dopo oggi (una cassa creata con un saldo registra l'apertura all'inizio del primo esercizio: il denaro c'è
 * già). I movimenti dopo oggi, non di apertura, non sono nella cifra: quelli entro la finestra entrano nel flusso al loro giorno, sui conti della cifra (la
 * liquidità meno i conti dei fondi che non si possono usare; un accantonamento futuro in un fondo vincolato toglie denaro alla cifra quel giorno).
 *
 * Le righe di «Da dove viene» sommano «Tutto il denaro»; tolti i fondi vincolati sopra zero (le righe «tolte»), danno la cifra. Una differenza che le righe
 * non spiegano ha la sua riga («Altra liquidità»): le parti fanno sempre il totale.
 */
final class DisponibilitaDiOggi
{
    public function __construct(
        private readonly StatoPatrimonialeService $statoPatrimoniale,
        private readonly LiquiditaVincolataService $liquidita,
    ) {}

    /**
     * @return array{
     *     cifra:int, totale:int, solo_conti_correnti:bool, righe:list<array<string,mixed>>, tolte:list<array<string,mixed>>,
     *     futuri:array{quanti:int, cifra:int, primo:?string, nel_flusso:array<string, list<array{voce:string, importo:int}>>, nel_flusso_quanti:int, nel_flusso_cifra:int},
     *     banca:list<array<string,mixed>>, esercizio_id:?int
     * }
     */
    public function perCondominio(Condominio $condominio, string $oggi, int $giorni = 30): array
    {
        $foto = $this->statoPatrimoniale->calcola($condominio, allaData: $oggi, apertureSempre: true);
        $l = $this->liquidita->allaData($condominio, $foto['attivo']['voci'], $foto['liquidita_non_contabilizzata'], 'aperto');

        $righe = [];
        foreach ($l['libere'] as $c) {
            if ($c['saldo'] !== 0 || $c['attiva']) {
                $righe[] = ['voce' => (string) $c['cassa'], 'tipo' => (string) $c['tipo'], 'saldo' => (int) $c['saldo'], 'cassa_id' => $c['cassa_id'],
                    'conto_contabile_id' => $c['conto_contabile_id'], 'attiva' => (bool) $c['attiva'], 'etichetta' => null];
            }
        }
        foreach ($l['altra_voci'] as $v) {
            $righe[] = ['voce' => (string) ($v['nome'] ?? $v['voce']), 'tipo' => (string) ($v['tipo'] ?? 'altro'), 'saldo' => (int) $v['saldo'], 'cassa_id' => $v['cassa_id'] ?? null,
                'conto_contabile_id' => $v['conto_contabile_id'] ?? null, 'attiva' => (bool) ($v['attiva'] ?? true), 'etichetta' => null];
        }
        $tolte = [];
        foreach ($l['fondi'] as $f) {
            if ($f['saldo'] === 0 && ! $f['attiva']) {
                continue;
            }
            $righe[] = ['voce' => (string) $f['cassa'], 'tipo' => 'fondo', 'saldo' => (int) $f['saldo'], 'cassa_id' => $f['cassa_id'], 'conto_contabile_id' => $f['conto_contabile_id'],
                'attiva' => (bool) $f['attiva'], 'etichetta' => $f['etichetta'], 'si_puo_usare' => ! $f['vincolato']];
            // Si toglie ciò che il fondo trattiene: un fondo vincolato in rosso non toglie niente, il suo rosso è già nel totale.
            $trattiene = TipoDelFondo::trattiene((int) $f['saldo'], ! $f['vincolato']);
            if ($trattiene !== 0) {
                $tolte[] = ['voce' => (string) $f['cassa'], 'saldo' => $trattiene, 'cassa_id' => $f['cassa_id'], 'etichetta' => $f['etichetta']];
            }
        }
        // Le parti fanno sempre il totale: una differenza che le righe non spiegano si dice con il suo nome.
        $resto = (int) $l['liquidita_totale'] - (int) array_sum(array_column($righe, 'saldo'));
        if ($resto !== 0) {
            $righe[] = ['voce' => 'Altra liquidità', 'tipo' => 'altro', 'saldo' => $resto, 'cassa_id' => null, 'conto_contabile_id' => null, 'attiva' => true, 'etichetta' => null];
        }

        $cifra = (int) $l['liquidita_totale'] - (int) array_sum(array_column($tolte, 'saldo'));
        // «Disponibile oggi» dice da dove viene; con i soli conti correnti la casella lo dice («sul conto corrente»).
        $soloContiCorrenti = collect($righe)->filter(fn ($r) => $r['saldo'] !== 0)->every(fn ($r) => $r['tipo'] === 'banca' || ($r['tipo'] === 'fondo' && ! ($r['si_puo_usare'] ?? false)));

        $contiFuori = array_values(array_filter(array_map(fn ($f) => $f['vincolato'] ? (int) $f['conto_contabile_id'] : null, $l['fondi'])));

        return [
            'cifra' => $cifra,
            'totale' => (int) $l['liquidita_totale'],
            'solo_conti_correnti' => $soloContiCorrenti && $righe !== [],
            'righe' => $righe,
            'tolte' => $tolte,
            'futuri' => $this->futuri((int) $condominio->id, $oggi, $giorni, $contiFuori),
            'banca' => $this->bancaDellaNota((int) $condominio->id),
            'esercizio_id' => self::esercizioDiOggi((int) $condominio->id, $oggi),
        ];
    }

    /**
     * I movimenti dopo oggi, non di apertura, sui conti della cifra: quanti sono, la loro cifra, il primo giorno; e quelli entro la finestra, per giorno, che
     * entrano nel flusso. Una registrazione che sui conti della cifra non sposta niente (un passaggio dalla banca ai contanti) non conta.
     *
     * @param  list<int>  $contiFuori  i conti dei fondi che non si possono usare
     */
    private function futuri(int $condominioId, string $oggi, int $giorni, array $contiFuori): array
    {
        $dopo = CarbonImmutable::parse($oggi, 'UTC')->addDay()->format('Y-m-d');
        $fine = CarbonImmutable::parse($oggi, 'UTC')->addDays($giorni)->format('Y-m-d');
        $righe = DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
            ->whereNull('sc.deleted_at')
            ->whereNull('cc.deleted_at')
            ->where('cc.condominio_id', $condominioId)
            ->where('cc.categoria', 'liquidita')
            ->where('sc.data_competenza', '>=', $dopo)
            ->where('sc.tipo_movimento', '<>', 'apertura')
            ->when($contiFuori !== [], fn ($q) => $q->whereNotIn('rs.conto_contabile_id', $contiFuori))
            ->groupBy('sc.id', 'sc.data_competenza', 'sc.causale')
            ->orderBy('sc.data_competenza')->orderBy('sc.id')
            ->selectRaw("sc.id, sc.data_competenza, sc.causale, SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as netto")
            ->get()
            ->map(fn ($r) => ['giorno' => substr((string) $r->data_competenza, 0, 10), 'voce' => (string) ($r->causale ?? ''), 'importo' => (int) $r->netto])
            ->filter(fn ($r) => $r['importo'] !== 0)
            ->values();

        $nelFlusso = $righe->filter(fn ($r) => $r['giorno'] <= $fine);

        return [
            'quanti' => $righe->count(),
            'cifra' => (int) $righe->sum('importo'),
            'primo' => $righe->first()['giorno'] ?? null,
            'nel_flusso' => $nelFlusso->groupBy('giorno')->map(fn ($g) => $g->map(fn ($r) => ['voce' => $r['voce'], 'importo' => $r['importo']])->values()->all())->all(),
            'nel_flusso_quanti' => $nelFlusso->count(),
            'nel_flusso_cifra' => (int) $nelFlusso->sum('importo'),
        ];
    }

    /**
     * Il saldo dell'estratto conto della banca scritto nella nota del rendiconto, per ogni cassa il più recente per data: una cifra
     * dell'amministratore, con la sua data e il rendiconto da cui viene.
     *
     * @return list<array{cassa_id:int, cassa:string, data:string, saldo:int, esercizio:string}>
     */
    private function bancaDellaNota(int $condominioId): array
    {
        $note = NotaSintetica::query()->join('esercizi', 'esercizi.id', '=', 'note_sintetiche.esercizio_id')
            ->where('note_sintetiche.condominio_id', $condominioId)
            ->get(['note_sintetiche.contenuto', 'esercizi.nome as esercizio']);
        $perCassa = [];
        foreach ($note as $n) {
            foreach (NotaSinteticaDelRendiconto::normalizza((array) ($n->contenuto ?? []))['banca'] as $cassa => $b) {
                if ($b['saldo'] === null || $b['data'] === null) {
                    continue;
                }
                if (! isset($perCassa[$cassa]) || $b['data'] > $perCassa[$cassa]['data']) {
                    $perCassa[$cassa] = ['cassa_id' => (int) $cassa, 'data' => (string) $b['data'], 'saldo' => (int) $b['saldo'], 'esercizio' => (string) $n->esercizio];
                }
            }
        }
        if ($perCassa === []) {
            return [];
        }
        $nomi = DB::table('casse')->where('condominio_id', $condominioId)->whereIn('id', array_keys($perCassa))->pluck('nome', 'id');

        return array_values(array_map(fn ($b) => $b + ['cassa' => (string) ($nomi[$b['cassa_id']] ?? '')],
            array_filter($perCassa, fn ($b) => isset($nomi[$b['cassa_id']]))));
    }

    /**
     * L'esercizio aperto che contiene oggi, o il primo aperto: dove si leggono i movimenti di un conto, e dove si crea un piano rate (le azioni consigliate, il
     * compito del deficit ereditato).
     */
    public static function esercizioDiOggi(int $condominioId, string $oggi): ?int
    {
        $aperti = DB::table('esercizi')->where('condominio_id', $condominioId)->where('stato', 'aperto')->orderBy('data_inizio')->orderBy('id')
            ->get(['id', 'data_inizio', 'data_fine']);
        $dentro = $aperti->first(fn ($e) => substr((string) $e->data_inizio, 0, 10) <= $oggi && substr((string) $e->data_fine, 0, 10) >= $oggi);

        return ($dentro ?? $aperti->first())?->id;
    }
}
