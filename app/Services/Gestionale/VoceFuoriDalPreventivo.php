<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Gestionale\Conto;
use Illuminate\Support\Facades\DB;

/**
 * Coda 742 (decisione 653 (2)): una riga di fattura su una voce che **nel preventivo vale € 0** si mette a rate come una spesa fuori preventivo, con «Spese
 * fuori preventivo» e la fattura nel carrello. Il caso: la fattura di € 1.400,00 sulla voce «Manutenzione tetto», a € 0 nel preventivo, registrata in sforo.
 *
 * Una regola sola, per tutti quelli che decidono che cosa il piano delle fatture mette a rate (il carrello, la richiesta, il motore, la parte del piano della
 * fattura, il netto con le note collegate, il blocco della voce, il riquadro «Le rate»). La riga:
 *
 * - è su una voce foglia di spesa, non tecnica (le righe «Fuori preventivo» hanno già il loro segno), con l'importo a zero;
 * - **nessun piano in bozza o approvato chiede già con una cifra la voce, o il capitolo sopra di lei**, dalle voci del preventivo: così la stessa spesa non
 *   si chiede due volte (il motore dà la cifra del capitolo anche alla voce a € 0 sotto di lui). Chiesta dalle voci, il carrello non la offre; messa nel
 *   carrello, la copertura della voce conta il piano (`BudgetCoverageService`, passo 3), «Dalle voci» non la chiede più, né il suo capitolo con una cifra, e
 *   la richiesta lo rifiuta ({@see pianiDelleFattureSulConto()}), «Sposta spesa» non dà budget a lei né al capitolo sopra di lei ({@see contiConLaVoceARate()}),
 *   e finché un piano delle fatture la tiene, in bozza o approvato, la voce non cambia importo (`Conto::pianiCheBloccanoLaVoce`): con un importo, o con una
 *   cifra in un piano, uscirebbe dalla regola, e il preventivo la chiederebbe di nuovo;
 * - **la fattura non ha righe «Fuori preventivo» né righe di un'unità**. Una fattura mista era già nei piani delle fatture per quelle righe, con un importo
 *   che non dice quali righe chiede: con la riga sulla voce a € 0 in più, il «Ricalcola» di un piano già fatto divideva quell'importo anche sulla voce, e
 *   un condòmino pagava una parte della riga dell'unità di un altro (regressione trovata nella revisione del lavoro, 1.12.0-beta.14). La sua riga sulla voce
 *   a € 0 resta al conguaglio del rendiconto, e il riquadro «Le rate» lo dice. Finché la fattura è in un piano delle fatture, la sua composizione non
 *   cambia (`FatturaPassivaService::motivoComposizioneNelPiano`): una riga di un'unità aggiunta o tolta sposterebbe la regola sotto un piano già fatto;
 * - **il fondo di riserva non la copre già**: né la copertura dello sforo della fattura (senza voce), né una copertura dal fondo sulla sua voce (quella della
 *   pregressa con «Usa fondo riserva», tappa 6). La spesa coperta dal fondo, chiesta anche ai condòmini, si pagherebbe due volte.
 *
 * **Le due letture.** La regola qui sopra ({@see righe()}) dice quali righe un piano delle fatture **nuovo** può prendere: la leggono il carrello, la
 * richiesta e la creazione del piano, che accende il segno `righe_fattura.is_rateizzata`. Una riga presa **resta presa finché il suo piano c'è**
 * ({@see prese()}), anche quando la voce prende poi una cifra in un altro piano (il preventivo creato «con tutte le voci» le dà il suo residuo, il «Ricalcola»
 * con le voci da includere ne scrive il capitolo): chi guarda un piano che c'è già legge le due insieme ({@see righeOPrese()}), cioè il motore, il blocco della
 * voce, la composizione della fattura, il netto con le note, il riquadro «Le rate» e i piani delle fatture sulle voci. L'eliminazione del piano spegne il
 * segno guardando il segno, non la regola (`PianoRateController::destroy`).
 */
final class VoceFuoriDalPreventivo
{
    /** Le righe (della tabella o dell'alias `$righe`) su una voce a € 0 che il piano delle fatture può mettere a rate. */
    public static function righe($query, string $righe = 'righe_fattura'): void
    {
        $query->whereNull("{$righe}.immobile_id")->whereExists(fn ($q) => self::voce($q, $righe))
            ->whereNotExists(fn ($q) => self::rigaDelleAltre($q, $righe))
            ->whereNotExists(fn ($q) => self::copertaDalFondo($q, $righe));
    }

    /**
     * Le righe che un piano delle fatture ha già preso: il segno `is_rateizzata` acceso, senza unità, non «Fuori preventivo», su una voce di spesa, e la
     * fattura in un piano straordinario (quello dato, se c'è). Non legge l'importo della voce né i piani delle voci: la riga resta presa finché il piano c'è.
     */
    public static function prese($query, string $righe = 'righe_fattura', ?int $pianoId = null): void
    {
        $query->where("{$righe}.is_rateizzata", true)->whereNull("{$righe}.immobile_id")
            ->where(fn ($s) => $s->where("{$righe}.is_sopravvenienza", false)->orWhereNull("{$righe}.is_sopravvenienza"))
            ->whereExists(fn ($v) => $v->select(DB::raw(1))->from('conti as voce_presa')->whereColumn('voce_presa.id', "{$righe}.conto_id")
                ->whereIn('voce_presa.tipo', Conto::TIPI_DI_SPESA))
            ->whereExists(fn ($p) => $p->select(DB::raw(1))->from('piano_rate_fatture as fattura_nel_piano')
                ->join('piani_rate as piano_della_riga', 'piano_della_riga.id', '=', 'fattura_nel_piano.piano_rate_id')
                ->whereColumn('fattura_nel_piano.fattura_passiva_id', "{$righe}.fattura_passiva_id")->where('piano_della_riga.tipo', 'straordinario')
                ->when($pianoId !== null, fn ($q) => $q->where('piano_della_riga.id', $pianoId)));
    }

    /** Le righe di un piano delle fatture che c'è già: quelle che la regola offre oggi, o quelle che un piano ha preso ({@see prese()}). */
    public static function righeOPrese($query, string $righe = 'righe_fattura', ?int $pianoId = null): void
    {
        $query->where(fn ($q) => $q->where(fn ($a) => self::righe($a, $righe))->orWhere(fn ($b) => self::prese($b, $righe, $pianoId)));
    }

    /**
     * Le righe che non sono su una voce a € 0, per l'elenco di ciò che il carrello vuoto non mostra «sulle voci» (che rimanda a «Dalle voci del
     * preventivo»): una voce a € 0 non si chiede dalle voci, anche quando la sua riga resta fuori dal carrello (la fattura mista, il fondo).
     */
    public static function nonRighe($query, string $righe = 'righe_fattura'): void
    {
        $query->whereNotExists(fn ($q) => self::voce($q, $righe));
    }

    /** Gli id delle righe della fattura su una voce a € 0, offerte oggi o già prese da un piano ({@see righeOPrese()}). @return list<int> */
    public static function idDellaFattura(int $fatturaId): array
    {
        return self::idDelleFatture([$fatturaId]);
    }

    /** Lo stesso per più fatture, in una query. @param list<int> $fattureIds @return list<int> */
    public static function idDelleFatture(array $fattureIds): array
    {
        if ($fattureIds === []) {
            return [];
        }

        return DB::table('righe_fattura')->whereIn('fattura_passiva_id', $fattureIds)->where(fn ($q) => self::righeOPrese($q))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Gli id delle righe della fattura che un piano delle fatture nuovo può prendere oggi, con la sola regola ({@see righe()}). @return list<int> */
    public static function idDaOffrire(int $fatturaId): array
    {
        return DB::table('righe_fattura')->where('fattura_passiva_id', $fatturaId)->where(fn ($q) => self::righe($q))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Le righe della fattura su una voce che nel preventivo vale € 0 e che il piano delle fatture **non** mette a rate, con il perché: `mista` (la fattura ha
     * righe «Fuori preventivo» o di un'unità: la riga resta al conguaglio) o `fondo` con il nome del fondo che la copre. `sparito` dice il fondo la cui
     * cassa non c'è più (eliminata con le versioni di prima della 1.12.0-beta.14, che non lo impedivano): nessun fondo paga la spesa, e la copertura va
     * rifatta. Per il riquadro «Le rate» e per il rifiuto del carrello.
     *
     * @return list<array{voce: string, perche: string, fondo: ?string, sparito: bool}>
     */
    public static function fuoriDalCarrello(int $fatturaId): array
    {
        $righe = DB::table('righe_fattura')->join('conti', 'conti.id', '=', 'righe_fattura.conto_id')->where('righe_fattura.fattura_passiva_id', $fatturaId)
            ->whereNull('righe_fattura.immobile_id')->where(fn ($q) => $q->where('righe_fattura.is_sopravvenienza', false)->orWhereNull('righe_fattura.is_sopravvenienza'))
            ->whereExists(fn ($q) => self::voce($q, 'righe_fattura'))
            ->orderBy('conti.nome')->get(['righe_fattura.id', 'righe_fattura.conto_id', 'conti.nome']);
        if ($righe->isEmpty()) {
            return [];
        }
        $prese = array_flip(self::idDellaFattura($fatturaId));
        $fondi = DB::table('fattura_coperture')->where('fattura_passiva_id', $fatturaId)->where('tipo_copertura', 'fondo_riserva')->get(['conto_id', 'fondo_id']);

        $esito = [];
        foreach ($righe->unique('conto_id') as $r) {
            if (isset($prese[(int) $r->id])) {
                continue;
            }
            $fondo = $fondi->first(fn ($c) => $c->conto_id === null || (int) $c->conto_id === (int) $r->conto_id);
            $nome = $fondo === null || $fondo->fondo_id === null ? null : self::nomeDelFondo((int) $fondo->fondo_id);
            $esito[] = ['voce' => (string) $r->nome, 'perche' => $fondo !== null ? 'fondo' : 'mista', 'fondo' => $nome,
                'sparito' => $fondo !== null && $fondo->fondo_id !== null && $nome === null];
        }

        return $esito;
    }

    /**
     * Coda 742, il verso «Dalle voci»: i piani delle fatture (in bozza o approvati) che mettono a rate una riga sulla voce data, con il numero delle fatture.
     * Una cifra chiesta qui sulla stessa voce la chiederebbe due volte (`CreatePianoRateRequest`).
     *
     * @return list<array{piano: string, fattura: string}>
     */
    public static function pianiDelleFattureSullaVoce(int $voceId): array
    {
        return array_map(fn (array $p) => ['piano' => $p['piano'], 'fattura' => $p['fattura']], self::pianiDelleFattureSulleVoci([$voceId]));
    }

    /**
     * Lo stesso per un conto chiesto «Dalle voci» con una cifra: la voce stessa, o un capitolo con una voce a € 0 sotto di lui (il motore dà la cifra del
     * capitolo anche a lei). Con la voce o il capitolo, la voce a € 0 e il piano che la mette già a rate.
     *
     * @return list<array{voce: string, piano: string, fattura: string}>
     */
    public static function pianiDelleFattureSulConto(int $contoId): array
    {
        $sotto = [$contoId];
        $livello = [$contoId];
        for ($giri = 0; $giri < 3 && $livello !== []; $giri++) {
            $livello = DB::table('conti')->whereIn('parent_id', $livello)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $sotto = array_merge($sotto, $livello);
        }

        return self::pianiDelleFattureSulleVoci($sotto);
    }

    /**
     * La frase di «Dalle voci» per un conto chiesto con una cifra mentre un piano delle fatture mette a rate la sua voce a € 0 (o quella sotto il capitolo):
     * la dicono la richiesta della creazione e la sincronizzazione delle voci da includere (`SyncOrphanChaptersAction`).
     *
     * @param  array{voce: string, piano: string, fattura: string}  $primo  la prima riga di {@see pianiDelleFattureSulConto()}
     */
    public static function fraseDalleVoci(string $conto, array $primo): string
    {
        return $primo['voce'] === $conto
            ? "La voce «{$conto}» non è nel preventivo, e la sua spesa è già nel piano «{$primo['piano']}» con la fattura n. {$primo['fattura']}: chiesta "
                . 'anche qui, si chiederebbe due volte. Toglila da questo piano, o togli prima la fattura da quel piano.'
            : "Il capitolo «{$conto}» contiene la voce «{$primo['voce']}», che non è nel preventivo, e la sua spesa è già nel piano «{$primo['piano']}» con la "
                . "fattura n. {$primo['fattura']}: con una cifra qui, il capitolo la chiederebbe due volte. Togli il capitolo da questo piano, o chiedi le sue "
                . 'altre voci una per una, o togli prima la fattura da quel piano.';
    }

    /**
     * I conti del piano dei conti a cui «Sposta spesa» non dà budget: le voci a € 0 la cui spesa un piano delle fatture mette già a rate, e i capitoli sopra di
     * loro (fino a tre livelli, come {@see pianiDelleFattureSulConto()}). Con una cifra in un piano la voce uscirebbe dalla regola ({@see voce()}), la sua riga
     * dal piano delle fatture, e il blocco della voce e la guardia della composizione non la vedrebbero più (`BudgetMovementService`). Una query per le voci.
     *
     * @return list<int>
     */
    public static function contiConLaVoceARate(int $pianoContoId): array
    {
        $padri = DB::table('conti')->where('piano_conto_id', $pianoContoId)->pluck('parent_id', 'id');
        $voci = array_unique(array_column(self::pianiDelleFattureSulleVoci($padri->keys()->map(fn ($id) => (int) $id)->all()), 'voce_id'));
        $esito = [];
        foreach ($voci as $id) {
            for ($giri = 0, $conto = (int) $id; $conto !== null && $giri < 4; $giri++) {
                $esito[$conto] = true;
                $conto = ($padri[$conto] ?? null) === null ? null : (int) $padri[$conto];
            }
        }

        return array_keys($esito);
    }

    /**
     * I piani delle fatture (in bozza o approvati) che mettono a rate una riga sulle voci date, una riga per voce, piano e fattura.
     *
     * @param  list<int>  $vociIds
     * @return list<array{voce_id: int, voce: string, piano: string, fattura: string, piano_id: int, stato: string, fattura_id: int}>
     */
    public static function pianiDelleFattureSulleVoci(array $vociIds): array
    {
        if ($vociIds === []) {
            return [];
        }

        return DB::table('righe_fattura')->join('piano_rate_fatture', 'piano_rate_fatture.fattura_passiva_id', '=', 'righe_fattura.fattura_passiva_id')
            ->join('piani_rate', 'piani_rate.id', '=', 'piano_rate_fatture.piano_rate_id')
            ->join('fatture_passive', 'fatture_passive.id', '=', 'righe_fattura.fattura_passiva_id')
            ->join('conti as la_voce', 'la_voce.id', '=', 'righe_fattura.conto_id')
            ->whereIn('righe_fattura.conto_id', $vociIds)->where('piani_rate.tipo', 'straordinario')->whereIn('piani_rate.stato', ['bozza', 'approvato'])
            ->where(fn ($q) => self::righeOPrese($q))
            ->orderBy('piani_rate.id')->orderBy('fatture_passive.id')->distinct()
            ->get(['la_voce.id as voce_id', 'la_voce.nome as voce', 'piani_rate.id as piano_id', 'piani_rate.nome', 'piani_rate.stato', 'fatture_passive.id as fattura_id', 'fatture_passive.numero_documento'])
            ->map(fn ($r) => ['voce_id' => (int) $r->voce_id, 'voce' => (string) $r->voce, 'piano' => (string) $r->nome, 'fattura' => (string) ($r->numero_documento ?? 'S/N'),
                'piano_id' => (int) $r->piano_id, 'stato' => (string) $r->stato, 'fattura_id' => (int) $r->fattura_id])->values()->all();
    }

    /**
     * Gli id, fra quelli dati, dei conti che oggi sono una voce a € 0 per la regola ({@see voce()}): per guardare le righe di una fattura prima di scriverle
     * (`FatturaPassivaService::motivoComposizioneNelPiano`).
     *
     * @param  list<int>  $contiIds
     * @return list<int>
     */
    public static function vociAZero(array $contiIds): array
    {
        $contiIds = array_values(array_unique(array_filter(array_map('intval', $contiIds))));
        if ($contiIds === []) {
            return [];
        }

        return DB::table('conti as voce_da_guardare')->whereIn('voce_da_guardare.id', $contiIds)->whereExists(fn ($q) => self::voce($q, 'voce_da_guardare', 'id'))
            ->pluck('voce_da_guardare.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * La voce della riga (`{$righe}.{$colonna}`) vale € 0, è una foglia di spesa non tecnica, e nessun piano in bozza o approvato la chiede con una cifra, né
     * chiede con una cifra il capitolo sopra di lei (fino a tre livelli: il piano dei conti ne ha due).
     */
    private static function voce($q, string $righe, string $colonna = 'conto_id'): void
    {
        $q->select(DB::raw(1))->from('conti as voce_a_zero')->whereColumn('voce_a_zero.id', "{$righe}.{$colonna}")
            ->where('voce_a_zero.importo', 0)
            ->whereIn('voce_a_zero.tipo', Conto::TIPI_DI_SPESA)
            ->where(fn ($t) => $t->where('voce_a_zero.is_tecnico', false)->orWhereNull('voce_a_zero.is_tecnico'))
            ->whereNotExists(fn ($f) => $f->select(DB::raw(1))->from('conti as figlio_della_voce')->whereColumn('figlio_della_voce.parent_id', 'voce_a_zero.id'))
            ->whereNotExists(fn ($p) => $p->select(DB::raw(1))->from('piano_rate_capitoli as capitolo_nel_piano')
                ->join('piani_rate as piano_delle_voci', 'piano_delle_voci.id', '=', 'capitolo_nel_piano.piano_rate_id')
                ->whereIn('piano_delle_voci.stato', ['bozza', 'approvato'])->where('capitolo_nel_piano.importo', '>', 0)
                ->where(fn ($catena) => $catena->whereColumn('capitolo_nel_piano.conto_id', 'voce_a_zero.id')
                    ->orWhereColumn('capitolo_nel_piano.conto_id', 'voce_a_zero.parent_id')
                    ->orWhereIn('capitolo_nel_piano.conto_id', fn ($nonno) => $nonno->select('padre_del_capitolo.parent_id')->from('conti as padre_del_capitolo')
                        ->whereColumn('padre_del_capitolo.id', 'voce_a_zero.parent_id')->whereNotNull('padre_del_capitolo.parent_id'))));
    }

    /** Un'altra riga della stessa fattura che il piano delle fatture prende già: «Fuori preventivo», o di un'unità. */
    private static function rigaDelleAltre($q, string $righe): void
    {
        $q->select(DB::raw(1))->from('righe_fattura as altra_riga')->whereColumn('altra_riga.fattura_passiva_id', "{$righe}.fattura_passiva_id")
            ->where(fn ($a) => $a->where('altra_riga.is_sopravvenienza', true)->orWhereNotNull('altra_riga.immobile_id'));
    }

    /** Una copertura dal fondo di riserva della fattura: quella dello sforo (senza voce) o quella sulla voce della riga. */
    private static function copertaDalFondo($q, string $righe): void
    {
        $q->select(DB::raw(1))->from('fattura_coperture as dal_fondo')->whereColumn('dal_fondo.fattura_passiva_id', "{$righe}.fattura_passiva_id")
            ->where('dal_fondo.tipo_copertura', 'fondo_riserva')
            ->where(fn ($c) => $c->whereNull('dal_fondo.conto_id')->orWhereColumn('dal_fondo.conto_id', "{$righe}.conto_id"));
    }

    /** Il nome del fondo di una copertura: la cassa del suo conto (`fondo_id` è il conto del fondo). */
    private static function nomeDelFondo(int $contoDelFondo): ?string
    {
        return DB::table('casse')->where('conto_contabile_id', $contoDelFondo)->value('nome');
    }
}
