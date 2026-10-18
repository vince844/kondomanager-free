<?php

/**
 * Aiuti dei test sui totali con le voci di entrata e sul promemoria del portale.
 *
 * Cosa resta scoperto: nessun test qui, solo gli scenari; i tipi di voce diversi da «spesa» ed «entrata» non si costruiscono (la colonna
 * `conti.tipo` è un enum) e i database diversi da sqlite non si provano.
 *
 * Gli scenari si costruiscono dalle rotte, come la pagina: le voci dal modale della nuova voce, il piano dalla rotta di creazione
 * (così la pivot `piano_rate_capitoli` esiste, positiva, come la scrive la pagina), l'approvazione, l'emissione e l'incasso dalle
 * rotte dell'amministratore. Il condominio ha due unità di Ugo e Bice sulla tabella «Proprietà» (A e C, 500 millesimi ciascuna) e il
 * box D di Bice, solo sulla tabella «Box» (1000 millesimi).
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CondominioService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function b52tAdmin(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $u = User::factory()->create();
    $u->assignRole($ruolo);

    return $u;
}

/** Su sqlite mancano le due funzioni JSON che `PianoRateResource` usa per `has_saldi`: si registrano per il test. */
function b52tSqliteJson(): void
{
    DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($x) => $x);
}

function b52tCondominio(): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 10000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);

    return ['c' => $c, 'cassa' => $cassa, 'tabella' => $tabella, 'seq' => $seq, 'persone' => [], 'unita' => []];
}

function b52tUnita(array &$s, string $sigla, string $chi, Tabella $tabella, float $millesimi): void
{
    $s['unita'][$sigla] = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "B52T-{$s['seq']}-{$sigla}", 'nome' => "Interno {$sigla}", 'interno' => $sigla]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $s['unita'][$sigla]->id, 'valore' => $millesimi, 'created_at' => now(), 'updated_at' => now()]);
    $s['persone'][$chi] ??= tap(Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "b52t-{$chi}{$s['seq']}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => strtoupper(str_pad($chi, 6, 'X')) . str_pad((string) $s['seq'], 10, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$sigla]->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** Una voce dal modale della nuova voce (la rotta `conti.store`), con la tabella e il 100 % al proprietario. */
function b52tVoce($test, array $s, string $nome, string $tipo, string $importo, Tabella $tabella, ?int $parentId = null, array $extra = []): Conto
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), array_merge([
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => $parentId, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ], $extra))->assertSessionHasNoErrors();

    return Conto::where('piano_conto_id', $s['pc']->id)->where('nome', $nome)->firstOrFail();
}

/**
 * Spese `$spese` su «Proprietà» (A di Ugo e C di Bice) e proventi `$entrata` su una voce di entrata sulla tabella «Box» (solo il box D
 * di Bice). Il piano non è ancora creato: lo crea `b52tPiano()`. `$soloEntrata`: niente voce di spesa.
 */
function b52tScenario($test, string $spese = '1.200,00', string $entrata = '240,00', bool $soloEntrata = false): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    $box = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Box', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    b52tUnita($s, 'D', 'bice', $box, 1000.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc, 'box' => $box];
    if (! $soloEntrata) {
        $s['spesa'] = b52tVoce($test, $s, 'Spese generali', 'spesa', $spese, $s['tabella']);
    }
    $s['entrata'] = b52tVoce($test, $s, 'Proventi antenna', 'entrata', $entrata, $box);

    return $s;
}

/** Il piano come lo crea la pagina: dalla rotta di creazione, «lascia vuoto» o con le voci scelte (`capitoli_config`). */
function b52tPiano($test, array $s, array $extra = [], string $nome = 'Preventivo 2026'): PianoRate
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
        'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [], 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ], $extra))->assertSessionHasNoErrors();

    return PianoRate::where('gestione_id', $s['g']->id)->where('nome', $nome)->firstOrFail();
}

/** Somma delle quote generate, per persona (centesimi), in ordine alfabetico di nome. */
function b52tSommeQuote(PianoRate $piano): array
{
    $somme = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->join('anagrafiche', 'anagrafiche.id', '=', 'rate_quote.anagrafica_id')
        ->selectRaw('anagrafiche.nome as nome, sum(rate_quote.importo) as tot')->groupBy('anagrafiche.nome')->get()
        ->mapWithKeys(fn ($r) => [$r->nome => (int) $r->tot])->all();
    ksort($somme);

    return $somme;
}

/** La somma delle quote generate dal motore su un piano, escluse quelle della rata 0 (i saldi). */
function b52tQuoteDelMotore(PianoRate $piano): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->where('rate.numero_rata', '!=', 0)->sum('rate_quote.importo');
}

/** Il già versato dell'unità sulla voce, come lo registra la pagina «Già versato». */
function b52tGiaVersato($test, array $s, Conto $voce, string $unita, int $cents): void
{
    $test->actingAs($test->user)->put("/admin/gestionale/{$s['c']->id}/contributi/{$voce->id}", [
        'natura' => 'avanzo', 'righe' => [['immobile_id' => $s['unita'][$unita]->id, 'gia_versato' => $cents]], 'liquidita_stato' => 'gia_in_apertura',
    ])->assertSessionHasNoErrors();
}

/**
 * Un capitolo padre con due sottoconti dello stesso tipo, arrivato dopo il piano e incluso con «Ricalcola con voci orfane»: è
 * l'unico percorso della pagina che mette un padre nella pivot. Il piano nasce con la sola voce «Pulizia» (€ 1.200,00).
 */
function b52tScenarioConPadre($test, string $tipoFigli = 'spesa'): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc];
    $mk = function (string $nome, ?int $parent, int $importo, string $tipo) use ($pc, $s) {
        $c = Conto::create(['piano_conto_id' => $pc->id, 'parent_id' => $parent, 'nome' => $nome, 'tipo' => $tipo, 'importo' => $importo]);
        if ($parent !== null || $importo > 0) {
            $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $c->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $c;
    };
    $s['pulizia'] = $mk('Pulizia', null, 120000, 'spesa');
    $s['piano'] = b52tPiano($test, $s);
    sleep(1); // i sottoconti devono nascere dopo il piano: lo snapshot del motore ignora quelli arrivati prima, e qui servono dopo
    $s['padre'] = $mk('Manutenzione', null, 0, 'spesa');
    $s['figlio1'] = $mk('Ascensore', $s['padre']->id, 60000, $tipoFigli);
    $s['figlio2'] = $mk('Caldaia', $s['padre']->id, 40000, $tipoFigli);
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'orphan_ids' => [$s['padre']->id], 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

/**
 * Un capitolo «Antenna» con due sottoconti di tipo diverso: «Manutenzione antenna» (spesa € 100,00, sulla tabella «Proprietà») e «Affitto
 * antenna» (entrata € 500,00, sulla tabella «Box»), accanto alle «Spese generali» da € 1.200,00. Senza la voce di entrata a zero dello
 * scenario di base. Il piano rate non c'è: serve alle pagine e alla stampa del piano dei conti.
 */
function b52tScenarioCapitoloMisto($test): array
{
    $s = b52tScenario($test, entrata: '0,00');
    $s['entrata']->delete();
    $padre = b52tVoce($test, $s, 'Antenna', 'spesa', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    b52tVoce($test, $s, 'Manutenzione antenna', 'spesa', '100,00', $s['tabella'], $padre->id, ['isSottoConto' => true]);
    b52tVoce($test, $s, 'Affitto antenna', 'entrata', '500,00', $s['box'], $padre->id, ['isSottoConto' => true]);

    return $s + ['padre' => $padre];
}

// ── Il promemoria del portale ────────────────────────────────────────────────────────────────────────────────────

function b52tApprova($test, array $s, PianoRate $piano): void
{
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])->assertSessionHasNoErrors();
}

function b52tEmetti($test, array $s, PianoRate $piano, int $numeroRata = 1, bool $notifiche = true): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => [b52tRata($piano, $numeroRata)], 'data_emissione' => '2026-01-31', 'invia_notifiche' => $notifiche])->assertSessionHasNoErrors();
}

function b52tRata(PianoRate $piano, int $n): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', $n)->value('id');
}

function b52tQuotaId(array $s, PianoRate $piano, string $chi, string $unita, int $n): int
{
    return (int) DB::table('rate_quote')->where('rata_id', b52tRata($piano, $n))->where('anagrafica_id', $s['persone'][$chi]->id)->where('immobile_id', $s['unita'][$unita]->id)->value('id');
}

function b52tEuro(int $cent): string
{
    return number_format($cent / 100, 2, '.', '');
}

/** Il promemoria di una persona su una rata (il modello, riletto dal database). */
function b52tEvento(array $s, PianoRate $piano, string $chi, int $n): ?Evento
{
    return Evento::where('tipo', 'scadenza_rata_condomino')->where('meta->context->rata_id', b52tRata($piano, $n))
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $s['persone'][$chi]->id))->first();
}

/** Le quote di una persona su una rata, come [interno, importo, pagato, stato]. */
function b52tQuote(array $s, PianoRate $piano, string $chi, int $n): array
{
    return RataQuote::where('rata_id', b52tRata($piano, $n))->where('anagrafica_id', $s['persone'][$chi]->id)->orderBy('id')->get()
        ->map(fn ($q) => [$q->immobile?->interno, (int) $q->importo, (int) $q->importo_pagato, $q->stato])->all();
}

/**
 * Un incasso dalla rotta. `$righe`: quota_id => centesimi. Con un importo negativo la riga è un credito usato («Usa credito»); `$contanti` è
 * ciò che entra in banca, `$eccedenza` quanto avanza come anticipo.
 */
function b52tIncasso($test, array $s, string $chi, array $righe, int $contanti, int $eccedenza = 0): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-05', 'importo_totale' => b52tEuro($contanti),
        'descrizione' => 'Bonifico', 'eccedenza' => b52tEuro($eccedenza),
        'dettaglio_pagamenti' => array_map(fn ($qid, $c) => ['rata_id' => $qid, 'importo' => b52tEuro($c)], array_keys($righe), array_values($righe)),
    ])->assertSessionHasNoErrors();
}

/**
 * Come `b52tScenarioConPadre()`, ma nella forma dei piani di prima della 1.9: i sottoconti nascono **prima** del piano (lo snapshot
 * del motore li vede) e la pivot è spostata sul padre (la somma dei figli, € 1.000,00) invece che sulle foglie; poi «Ricalcola».
 */
function b52tScenarioConPadreLegacy($test, string $tipoFigli = 'spesa'): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc];
    $mk = function (string $nome, ?int $parent, int $importo, string $tipo) use ($pc, $s) {
        $c = Conto::create(['piano_conto_id' => $pc->id, 'parent_id' => $parent, 'nome' => $nome, 'tipo' => $tipo, 'importo' => $importo]);
        if ($parent !== null || $importo > 0) {
            $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $c->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $c;
    };
    $s['pulizia'] = $mk('Pulizia', null, 120000, 'spesa');
    $s['padre'] = $mk('Manutenzione', null, 0, 'spesa');
    $s['figlio1'] = $mk('Ascensore', $s['padre']->id, 60000, $tipoFigli);
    $s['figlio2'] = $mk('Caldaia', $s['padre']->id, 40000, $tipoFigli);
    sleep(1); // il piano nasce dopo i sottoconti: lo snapshot del motore li comprende
    $s['piano'] = b52tPiano($test, $s);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->whereIn('conto_id', [$s['figlio1']->id, $s['figlio2']->id])->delete();
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['padre']->id, 'importo' => 100000, 'note' => 'forma di prima della 1.9', 'created_at' => now(), 'updated_at' => now()]);
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

/**
 * Come `b52tScenarioConPadreLegacy()`, ma il padre in pivot ha figli di tipo diverso: «Ascensore» è una spesa di `$importoSpesa` e «Caldaia» un'entrata di
 * `$importoEntrata` (centesimi). La pivot del padre vale `$pivot`, e il piano si rigenera: le quote sono quelle che il motore ripartisce fra i due figli.
 */
function b52tScenarioConPadreMisto($test, int $importoSpesa = 60000, int $importoEntrata = 40000, ?int $pivot = null): array
{
    $s = b52tScenarioConPadreLegacy($test, 'spesa');
    $s['figlio2']->update(['tipo' => 'entrata']);
    DB::table('conti')->where('id', $s['figlio1']->id)->update(['importo' => $importoSpesa]);
    DB::table('conti')->where('id', $s['figlio2']->id)->update(['importo' => $importoEntrata]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['padre']->id)->update(['importo' => $pivot ?? $importoSpesa + $importoEntrata]);
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

/** Un piano straordinario da una fattura di € 1.000,00 (collegata per intero), nella gestione dello scenario: il piano non nasce dalle voci ma dalle fatture. */
function b52tPianoStraordinarioDaFattura(array $s, int $importoCollegato = 100000): PianoRate
{
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Fornitore di prova Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fattura = \App\Models\Gestionale\FatturaPassiva::create([
        'condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['e']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-FACCIATA',
        'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'is_pregresso' => false, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'importo_ritenuta' => 0,
        'totale_documento' => 100000, 'netto_a_pagare' => 100000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ]);
    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Facciata 2026', 'stato' => 'bozza', 'tipo' => 'straordinario',
        'numero_rate' => 2, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-03-10', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => true,
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-02-01']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => $importoCollegato]);

    return $piano;
}

// ── Il promemoria: le frasi e lo scenario K3 ─────────────────────────────────────────────────────────────────────

const B52T_CREDITO_PURO = "Gentile Bice, questa voce rappresenta un credito a tuo favore registrato nella rata n. 1.\n\nNon è richiesto alcun pagamento: il credito resta a tuo favore. L'amministratore può usarlo per compensare una tua quota da pagare o restituirtelo.";
const B52T_SCADENZA = "Gentile Bice, ti ricordiamo la scadenza della rata condominiale n. 1.\n\nVerifica il dettaglio quote e il netto da versare nello scontrino qui sotto. Dopo aver effettuato il versamento, potrai segnalarlo all'amministratore cliccando sul pulsante corrispondente.";
const B52T_NETTO_CON_CREDITO = "\n\nIl netto da versare tiene conto di un credito di € 10,00 su un'altra tua quota di questa rata: la compensazione la registra l'amministratore.";

/** K3 fino all'emissione della prima rata: spese € 1.200,00 ed entrata € 120,00. Bice ha C +5000 e il box D −1000 sulla rata 1, il netto è 4000. */
function b52tK3($test, bool $emetti = true, bool $notifiche = true): array
{
    $s = b52tScenario($test, '1.200,00', '120,00');
    $piano = b52tPiano($test, $s);
    b52tApprova($test, $s, $piano);
    if ($emetti) {
        b52tEmetti($test, $s, $piano, 1, $notifiche);
    }

    return [$s, $piano, b52tQuotaId($s, $piano, 'bice', 'C', 1), b52tQuotaId($s, $piano, 'bice', 'D', 1)];
}

/** Il promemoria di Bice sulla rata, riletto: stato, importi, descrizione. */
function b52tBice(array $s, $piano, int $n = 1): array
{
    $e = b52tEvento($s, $piano, 'bice', $n);
    $m = $e->meta;

    return ['status' => $m['status'], 'originale' => $m['importo_originale'], 'pagato' => $m['importo_pagato'], 'restante' => $m['importo_restante'], 'da_compensare' => $m['da_compensare'] ?? null, 'descrizione' => $e->description];
}

/** Un utente del portale collegato alla persona dello scenario (la anagrafica ha `user_id`). */
function b52tUtentePortale(array $s, string $chi): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole(\App\Enums\Role::UTENTE->value);
    $s['persone'][$chi]->update(['user_id' => $utente->id]);

    return $utente;
}
