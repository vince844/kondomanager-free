<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnosi delle date di competenza — **sola lettura**.
 *
 * Nata nella 1.10 quando `anagrafica_immobile.data_inizio/data_fine` erano scritte e mai lette da
 * nessun calcolo (la beta.50 aveva corretto i testi che promettevano il contrario; questo comando
 * misurava il danno a chi ci si era fidato: *un avviso senza lo strumento per misurare il danno è
 * metà lavoro*).
 *
 * ➕ **Dalla 1.11.0-beta.31 (B2 del progetto `docs/subentro_e_competenza_temporale.md`) il motore le
 * legge**: `data_fine` filtra sempre, `data_inizio` con un predecessore chiuso (D7), e la spesa si
 * divide per giorni fra chi entra e chi esce (D8). Il segnale cambia verso: una `data_fine` compilata
 * non è più «ignorata», è la data da cui quel soggetto **non paga più** — va controllata perché è
 * voluta, non perché è inerte. Il piano B2 chiede di lanciare questo comando **prima di rigenerare un
 * piano con il motore nuovo**: le righe elencate sono quelle che cambieranno destinatario.
 *
 * ## Perché non ripara
 *
 * Riparare significherebbe rigenerare quote di piani già emessi, cioè toccare scritture
 * contabili — una decisione dell'amministratore, non di un comando. Qui si dice **se** e
 * **dove**. Stessa scelta di `kondomanager:verifica-saldi-solidali`, e per la stessa ragione.
 *
 * ## I tre segnali, e cosa significano
 *
 * 1. **`data_fine` valorizzata** — dalla beta.31 interrompe davvero l'addebito da quel giorno: è
 *    l'elenco delle righe che il motore temporale tratta diversamente da prima.
 * 2. **`attivo = false`** — righe che non partecipano già oggi. Vanno guardate perché
 *    **nessuna interfaccia le può riaccendere**: chi le ha spente l'ha fatto da database o da
 *    un percorso che non esiste più.
 * 3. **Somma delle quote ≠ 100 su (immobile, tipologia)** fra le righe **in corso** — è il segnale
 *    più importante ed è quello che costa denaro: sono i subentri rappresentati come comproprietà,
 *    senza data di fine. Due titolari al 100 % si normalizzano a 200 e prendono il 50 % ciascuno.
 *    Dalla beta.31 una riga chiusa non entra nella somma: venditore chiuso e acquirente in corso
 *    non sono più un falso allarme, sono un passaggio registrato bene.
 *
 * ➕ **Dalla 1.12.0-beta.14 (Coda 485) un quinto segnale**: le successioni con un erede che ha a sua volta una successione con il
 *    decesso prima del defunto, o lo stesso giorno. Il motore gli intesta anche i giorni dopo il suo decesso; la registrazione oggi
 *    le rifiuta, quelle scritte prima restano. La strada è dell'amministratore: annullare la successione del defunto e registrarla
 *    di nuovo con gli eredi giusti.
 *
 * ➕ **Dalla 1.12.0-beta.15 altri tre**: l'erede con un'estinzione dell'usufrutto registrata prima del decesso del defunto (l'estinzione non dice la
 *    causa: il pannello del passaggio avvisa e non ferma, qui si elencano le successioni già scritte); le righe di proprietà chiuse a quota 0 seguite
 *    da un altro proprietario (`RigheAQuotaZero`, la stessa lista della pagina delle unità); le pertinenze con due righe in corso della stessa persona
 *    e dello stesso ruolo, una a quota 0, che fermano il passaggio dell'unità.
 */
class VerificaTitolaritaCommand extends Command
{
    protected $signature = 'kondomanager:verifica-titolarita
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca le associazioni con date di competenza che cambiano il riparto (chiuse, spente, quote che non fanno 100) e gli eredi con il decesso prima del defunto. Non modifica nulla.';

    public function handle(): int
    {
        $condomini = $this->option('condominio')
            ? Condominio::where('id', $this->option('condominio'))->get()
            : Condominio::orderBy('id')->get();

        if ($condomini->isEmpty()) {
            $this->warn('Nessun condominio trovato.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->info('Date di competenza che il riparto legge dalla 1.11 — sola lettura, niente viene modificato.');
        $this->line('');

        $totaleSegnali = 0;

        foreach ($condomini as $condominio) {
            $conFine   = $this->righeConDataFine($condominio->id);
            $spente    = $this->righeSpente($condominio->id);
            $sospette  = $this->quoteNonAlCento($condominio->id);
            $stessoGiorno = $this->chiusuraEAperturaLoStessoGiorno($condominio->id);
            $erediMortiPrima = $this->erediMortiPrima($condominio->id);
            $erediConEstinzione = $this->erediConEstinzionePrima($condominio->id);
            $aQuotaZero = app(\App\Services\Gestionale\Titolarita\RigheAQuotaZero::class)->perCondominio((int) $condominio->id);
            $pertinenzeDoppie = $this->pertinenzeConDueRighe($condominio->id);

            $segnali = $conFine->count() + $spente->count() + $sospette->count() + $stessoGiorno->count() + count($erediMortiPrima)
                + count($erediConEstinzione) + count($aQuotaZero) + count($pertinenzeDoppie);
            if ($segnali === 0) {
                continue;
            }
            $totaleSegnali += $segnali;

            $this->line("<options=bold>#{$condominio->id} — {$condominio->nome}</>");

            if ($conFine->isNotEmpty()) {
                $this->line('');
                $this->line("  <fg=yellow>Data fine compilata ({$conFine->count()}):</> da quel giorno il soggetto non paga più; alla prossima generazione paga solo chi è titolare nel periodo di competenza — per giorni sull'ordinario, alla data della delibera sullo straordinario.");
                $this->table(
                    ['Unità', 'Soggetto', 'Ruolo', 'Dal', 'Al', 'Quota'],
                    $conFine->map(fn ($r) => [
                        $r->unita ?? '—', $r->soggetto ?? '—', $r->tipologia,
                        $r->data_inizio ?? '—', $r->data_fine, $r->quota . ' %',
                    ])->all()
                );
            }

            if ($spente->isNotEmpty()) {
                $this->line('');
                $this->line("  <fg=yellow>Associazioni disattivate ({$spente->count()}):</> non partecipano al riparto, e nessuna schermata le riaccende.");
                $this->table(
                    ['Unità', 'Soggetto', 'Ruolo', 'Quota'],
                    $spente->map(fn ($r) => [$r->unita ?? '—', $r->soggetto ?? '—', $r->tipologia, $r->quota . ' %'])->all()
                );
            }

            if ($sospette->isNotEmpty()) {
                $this->line('');
                // ⚠️ **Le cause sono due, e nominarne una sola manda fuori strada.** Fino alla
                // beta.52 qui si leggeva solo «probabile subentro registrato come comproprietà».
                // Poi si è scoperto che sul condominio 33 le due unità con somma 200 venivano da
                // **importazioni ripetute** — la prima aveva scritto i coniugi separati, la
                // seconda la coppia come soggetto unico — e chi leggeva il messaggio andava a
                // cercare un subentro che non c'era mai stato.
                $this->line("  <fg=red>Quote che non fanno 100 ({$sospette->count()}):</> la spesa si divide fra più soggetti di quanti ne abbia davvero l'unità.");
                $this->line('  <fg=gray>Due cause tipiche: un subentro registrato come comproprietà — chi è uscito continua a pagare —</>');
                $this->line("  <fg=gray>oppure due importazioni che hanno portato la stessa proprietà in forme diverse (la coppia come</>");
                $this->line('  <fg=gray>soggetto unico e i due coniugi separati). Guarda le date di creazione delle righe per distinguerle.</>');
                $this->table(
                    ['Unità', 'Ruolo', 'Titolari', 'Somma quote'],
                    $sospette->map(fn ($r) => [$r->unita ?? '—', $r->tipologia, $r->n, $r->somma . ' %'])->all()
                );
            }

            if ($stessoGiorno->isNotEmpty()) {
                $this->line('');
                // D7 stretto (decisione 23, 1.11.0-beta.31): il predecessore è la riga chiusa il GIORNO PRIMA. Una coppia
                // scritta a mano prima della beta.31 con chiusura e apertura lo stesso giorno («venduto il 30/6»: 30/6 e
                // 30/6) non ha più un predecessore — l'acquirente è «aperto da sempre» e paga l'anno intero.
                $this->line("  <fg=red>Chiusura e apertura lo stesso giorno ({$stessoGiorno->count()}):</> per il motore la seconda riga non ha un predecessore e conta dall'inizio del periodo.");
                $this->line('  <fg=gray>Sposta la chiusura al giorno prima, o la decorrenza al giorno dopo: il passaggio è di un giorno solo.</>');
                $this->table(
                    ['Unità', 'Ruolo', 'Chi esce', 'Chi entra', 'Giorno', 'Somma quel giorno'],
                    $stessoGiorno->map(fn ($r) => [$r->unita ?? '—', $r->tipologia, $r->uscente ?? '—', $r->entrante ?? '—', $r->giorno, $r->somma . ' %'])->all()
                );
            }

            if ($erediMortiPrima !== []) {
                $this->line('');
                $this->line('  <fg=red>Eredi con il decesso prima del defunto o lo stesso giorno (' . count($erediMortiPrima) . '):</> il riparto intesta all\'erede anche i giorni dopo il suo decesso.');
                $this->line('  <fg=gray>Annulla la successione del defunto e registrala di nuovo con gli eredi come li dice la dichiarazione di successione.</>');
                $this->table(['Unità', 'Defunto', 'Decesso', 'Erede', 'Decesso dell\'erede'], $erediMortiPrima);
            }

            if ($erediConEstinzione !== []) {
                $this->line('');
                $this->line('  <fg=yellow>Eredi con un\'estinzione dell\'usufrutto prima del decesso del defunto (' . count($erediConEstinzione) . '):</> se l\'usufrutto si è estinto per la morte dell\'erede, il riparto gli intesta anche i giorni dopo.');
                $this->line('  <fg=gray>L\'estinzione non dice la causa: se è per la morte, annulla la successione del defunto e registrala di nuovo con gli eredi come li dice la dichiarazione.</>');
                $this->table(['Unità', 'Defunto', 'Decesso', 'Erede', 'Estinzione dell\'usufrutto'], $erediConEstinzione);
            }

            if ($aQuotaZero !== []) {
                $this->line('');
                $this->line('  <fg=red>Righe di proprietà chiuse a quota 0 seguite da un altro proprietario (' . count($aQuotaZero) . '):</> in quei giorni nessuno paga per l\'unità.');
                $this->line('  <fg=gray>Correggi la riga dallo storico dell\'unità («Correggi questa riga»), una per una.</>');
                $this->table(['Unità', 'Soggetto', 'Ruolo', 'Periodo', 'Giorni'], array_map(fn (array $r) => [$r['unita'], $r['persona'], $r['tipologia'], $r['periodo'], (string) $r['giorni']], $aQuotaZero));
            }

            if ($pertinenzeDoppie !== []) {
                $this->line('');
                $this->line('  <fg=red>Pertinenze con due righe in corso della stessa persona e dello stesso ruolo, una a quota 0 (' . count($pertinenzeDoppie) . '):</> il passaggio dell\'unità non sa quale chiudere e si ferma.');
                $this->line('  <fg=gray>Correggi le righe dallo storico della pertinenza prima del passaggio.</>');
                $this->table(['Pertinenza', 'Soggetto', 'Ruolo', 'Righe', 'Quote'], $pertinenzeDoppie);
            }

            $this->line('');
        }

        if ($totaleSegnali === 0) {
            $this->info('Nessun segnale: nessuna data di fine compilata, nessuna associazione spenta, tutte le quote in corso fanno 100, nessuna chiusura e apertura lo stesso giorno, nessun erede con il decesso prima del defunto o con un\'estinzione dell\'usufrutto di prima, nessuna riga a quota 0 seguita da un altro proprietario, nessuna pertinenza con due righe della stessa persona.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn("{$totaleSegnali} segnali in totale.");
        $this->line('Il riparto legge le date: prima di rigenerare un piano controlla che ogni data di fine elencata sia voluta.');
        $this->line('');

        return self::SUCCESS;
    }

    /** Chi ha compilato la data di fine credendo che interrompesse l'addebito. */
    private function righeConDataFine(int $condominioId)
    {
        return DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->leftJoin('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
            ->where('i.condominio_id', $condominioId)
            ->whereNotNull('ai.data_fine')
            ->orderBy('i.interno')
            ->select([
                'i.interno as unita', 'a.nome as soggetto',
                'ai.tipologia', 'ai.data_inizio', 'ai.data_fine', 'ai.quota',
            ])
            ->get();
    }

    /** Righe già escluse dal riparto, che nessuna interfaccia può riaccendere. */
    private function righeSpente(int $condominioId)
    {
        return DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->leftJoin('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
            ->where('i.condominio_id', $condominioId)
            ->where('ai.attivo', false)
            ->orderBy('i.interno')
            ->select(['i.interno as unita', 'a.nome as soggetto', 'ai.tipologia', 'ai.quota'])
            ->get();
    }

    /**
     * Coppie (immobile, tipologia) con più titolari attivi la cui somma di quote non fa 100.
     *
     * ⚠️ Si guardano solo le righe **attive**: sono quelle che il motore somma davvero. Una
     * riga spenta non entra nel denominatore, quindi non falsa il riparto — e comparirebbe
     * qui come falso allarme.
     */
    /**
     * Le coppie (stessa unità, stesso ruolo) con una riga chiusa il giorno in cui l'altra decorre, e la somma delle
     * quote quel giorno sopra 100: per D7 stretto (decisione 23) la seconda non ha un predecessore. Scritte a mano
     * prima della guardia per giorno della 1.11.0-beta.31 (verifica S8-bis, L2-5).
     */
    private function chiusuraEAperturaLoStessoGiorno(int $condominioId)
    {
        return DB::table('anagrafica_immobile as a')
            ->join('anagrafica_immobile as b', function ($j) {
                $j->on('b.immobile_id', '=', 'a.immobile_id')
                    ->on('b.tipologia', '=', 'a.tipologia')
                    ->on('b.data_inizio', '=', 'a.data_fine')
                    ->on('b.id', '!=', 'a.id');
            })
            ->join('immobili as i', 'i.id', '=', 'a.immobile_id')
            ->leftJoin('anagrafiche as au', 'au.id', '=', 'a.anagrafica_id')
            ->leftJoin('anagrafiche as ab', 'ab.id', '=', 'b.anagrafica_id')
            ->where('i.condominio_id', $condominioId)
            ->where('a.attivo', true)->where('b.attivo', true)
            ->whereNotNull('a.data_fine')
            ->whereRaw('a.quota + b.quota > 100')
            ->orderBy('i.interno')
            ->get([
                'i.interno as unita', 'a.tipologia', 'au.nome as uscente', 'ab.nome as entrante', 'a.data_fine as giorno',
                DB::raw('a.quota + b.quota as somma'),
            ]);
    }

    /**
     * Coda 485 (1.12.0-beta.14): le successioni non annullate con un erede che ha una successione non annullata del condominio con il decesso fino al
     * giorno del decesso del defunto, lo stesso giorno compreso (art. 4 c.c.). Le stesse due condizioni della registrazione
     * (`AnteprimaPassaggioRequest::controllaIDecessi`), lette sulle successioni già scritte; quelle delle pertinenze hanno gli stessi eredi
     * dell'unità principale e non si ripetono.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private function erediMortiPrima(int $condominioId): array
    {
        $successioni = \App\Models\Gestionale\Subentro::with(['uscente', 'immobile'])->where('condominio_id', $condominioId)->where('tipo_passaggio', 'successione')
            ->whereNull('subentro_padre_id')->orderBy('decorrenza')->orderBy('id')->get();
        $righe = [];
        foreach ($successioni as $s) {
            foreach ($s->eredi() as $e) {
                $sua = $successioni->first(fn ($x) => (int) $x->anagrafica_uscente_id === (int) $e['anagrafica_id'] && (int) $x->id !== (int) $s->id
                    && $x->decorrenza->toDateString() <= $s->decorrenza->toDateString());
                if ($sua !== null) {
                    $righe[] = [(string) ($s->immobile?->nome ?? '—'), (string) ($s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? '—')), $s->decorrenza->format('d/m/Y'),
                        (string) ($sua->uscente?->nome ?? ($sua->registro['nomi']['uscente'] ?? '—')),
                        $sua->decorrenza->format('d/m/Y') . ', successione su ' . ($sua->immobile?->nome ?? '—')];
                }
            }
        }

        return $righe;
    }

    /**
     * Le successioni non annullate con un erede che ha un'estinzione dell'usufrutto non annullata del condominio con la data fino al decesso del defunto. La
     * lettura dell'avviso del pannello (`AnteprimaPassaggioRequest::avvisiDeiDecessi`) sulle successioni già scritte: l'estinzione non dice la causa, e il
     * segnale non dice che è un errore.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private function erediConEstinzionePrima(int $condominioId): array
    {
        $passaggi = \App\Models\Gestionale\Subentro::with(['uscente', 'immobile'])->where('condominio_id', $condominioId)->whereIn('tipo_passaggio', ['successione', 'usufrutto'])
            ->whereNull('subentro_padre_id')->orderBy('decorrenza')->orderBy('id')->get();
        $estinzioni = $passaggi->filter(fn ($x) => $x->estinzioneUsufrutto());
        $righe = [];
        foreach ($passaggi->where('tipo_passaggio', 'successione') as $s) {
            foreach ($s->eredi() as $e) {
                $sua = $estinzioni->first(fn ($x) => (int) $x->anagrafica_uscente_id === (int) $e['anagrafica_id'] && (int) $x->anagrafica_uscente_id !== (int) $s->anagrafica_uscente_id
                    && $x->decorrenza->toDateString() <= $s->decorrenza->toDateString());
                if ($sua !== null) {
                    $righe[] = [(string) ($s->immobile?->nome ?? '—'), (string) ($s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? '—')), $s->decorrenza->format('d/m/Y'),
                        (string) ($sua->uscente?->nome ?? ($sua->registro['nomi']['uscente'] ?? '—')), $sua->decorrenza->format('d/m/Y') . ', estinzione su ' . ($sua->immobile?->nome ?? '—')];
                }
            }
        }

        return $righe;
    }

    /**
     * Le pertinenze con due o più righe in corso oggi della stessa persona e dello stesso ruolo, una a quota 0: il passaggio dell'unità principale non sa
     * quale chiudere (`TitolaritaImmobile::rigaCheEsceDallaPertinenza`) e si ferma. Le righe doppie sull'unità principale non sono qui: lì il modulo
     * sceglie la riga a mano.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private function pertinenzeConDueRighe(int $condominioId): array
    {
        $oggi = DateHelper::oggiUtente();

        return DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->leftJoin('anagrafiche as an', 'an.id', '=', 'ai.anagrafica_id')
            ->where('i.condominio_id', $condominioId)->whereNotNull('i.pertinenza_di_immobile_id')->where('ai.attivo', true)
            ->where(fn ($q) => $q->whereNull('ai.data_fine')->orWhereDate('ai.data_fine', '>=', $oggi))
            ->where(fn ($q) => $q->whereNull('ai.data_inizio')->orWhereDate('ai.data_inizio', '<=', $oggi))
            ->orderBy('i.nome')->orderBy('ai.id')
            ->get(['i.id as immobile_id', 'i.nome as unita', 'ai.anagrafica_id', 'an.nome as soggetto', 'ai.tipologia', 'ai.quota'])
            ->groupBy(fn ($r) => $r->immobile_id . '|' . $r->anagrafica_id . '|' . $r->tipologia)
            ->filter(fn ($g) => $g->count() > 1 && $g->contains(fn ($r) => (float) $r->quota == 0.0))
            ->map(fn ($g) => [(string) ($g->first()->unita ?? '—'), (string) ($g->first()->soggetto ?? '—'), (string) $g->first()->tipologia, (string) $g->count(),
                $g->map(fn ($r) => rtrim(rtrim(number_format((float) $r->quota, 2, '.', ''), '0'), '.') . ' %')->implode(', ')])
            ->values()->all();
    }

    private function quoteNonAlCento(int $condominioId)
    {
        $oggi = DateHelper::oggiUtente();

        return DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->where('i.condominio_id', $condominioId)
            ->where('ai.attivo', true)
            // B2: una riga chiusa a oggi, o che decorre dopo oggi, non entra nella somma — è un passaggio, non una
            // comproprietà (S8-13: il venditore chiuso al 14/10 e l'acquirente dal 15/10 facevano 200 fino a quel giorno).
            ->where(fn ($q) => $q->whereNull('ai.data_fine')->orWhereDate('ai.data_fine', '>=', $oggi))
            ->where(fn ($q) => $q->whereNull('ai.data_inizio')->orWhereDate('ai.data_inizio', '<=', $oggi))
            ->groupBy('i.interno', 'ai.immobile_id', 'ai.tipologia')
            ->havingRaw('COUNT(*) > 1 AND ABS(SUM(ai.quota) - 100) > 0.01')
            ->select([
                'i.interno as unita',
                'ai.tipologia',
                DB::raw('COUNT(*) as n'),
                DB::raw('SUM(ai.quota) as somma'),
            ])
            ->get();
    }
}
