<?php

/**
 * Lo scenario condiviso dei test sulla voce di entrata (i tre ingressi che ne rovesciano il segno, il perimetro delle fatture, il fermo del piano).
 *
 * Costruisce un condominio con due unità uguali (Ugo e Bice, 500 e 500 millesimi) e le voci del collaudo (spese 1.200,00 ed entrata 240,00,
 * create dalla rotta come fa l'amministratore); il piano passa dalla rotta e la generazione dalla sua Action. Tutto il denaro è in
 * centesimi, positivo = debito. Non è un test: il nome non finisce in `Test.php` e Pest non lo raccoglie.
 *
 * Cosa resta scoperto: niente da solo; le prove stanno nei file `Beta52VoceEntrata…Test.php`, che lo caricano con `require_once` così ognuno passa anche lanciato da solo.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\CondominioService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

const BE52_FERMO_TESTA_UNA = 'Il piano non si ricalcola: la voce di spesa «Rimborso» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00.';
const BE52_FERMO_CORREGGI_LA_VOCE = ' Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';
const BE52_FERMO_LA_COPIA = ' Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia: togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza».'
    .' Togliendola si perdono i suoi tratti di competenza, se ne ha: annotali prima e riscrivili ricreando il piano.';
const BE52_FERMO_SENZA_VOCI = ' Senza voci nel piano, il piano ripartisce tutte le voci del piano dei conti: «Rimborso» ne fa parte.';
const BE52_COSTO_DELLA_BOZZA = 'si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima';
const BE52_FERMO_APPROVATO = ' Il piano è approvato: riportalo prima in bozza ('.BE52_COSTO_DELLA_BOZZA.').';

const BE52_TESTO_SPESA_NEGATIVA = 'Una voce di spesa non può avere un importo negativo. Se è un provento, per esempio l\'affitto di un\'antenna, scegli «Entrata» e scrivi l\'importo senza il segno.';
const BE52_TESTO_ENTRATA_CON_MENO = 'L\'importo di una voce di entrata si scrive senza il segno: è il tipo «Entrata» a ridurre quanto si chiede ai condòmini.';
const BE52_TESTO_CONFIG_NEGATIVA = 'L\'importo di una voce nel piano non può essere negativo. Scrivi l\'importo senza il segno: lo decide il tipo della voce.';

function be52Utente(): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $u = User::factory()->create();
    $u->assignRole($ruolo);

    return $u;
}

/** Un condominio con i mastri di sistema, una cassa e la tabella «Proprietà»; esercizio 2026 aperto e gestione ordinaria. */
function be52Condominio(): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    app(CondominioService::class)->ensureDefaultConti($c);
    $banca = ContoContabile::where('condominio_id', $c->id)->where('ruolo', 'cassa')->first()
        ?? ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 1000000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($c, $e);

    return ['c' => $c, 'cassa' => $cassa, 'tabella' => $tabella, 'e' => $e, 'g' => $g, 'seq' => $seq, 'persone' => [], 'unita' => []];
}

function be52Unita(array &$s, string $sigla, string $chi, Tabella $tabella, float $millesimi): void
{
    $s['unita'][$sigla] = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "S52-{$s['seq']}-{$sigla}", 'nome' => "Interno {$sigla}", 'interno' => $sigla]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $s['unita'][$sigla]->id, 'valore' => $millesimi, 'created_at' => now(), 'updated_at' => now()]);
    $s['persone'][$chi] ??= tap(Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "be52-{$chi}{$s['seq']}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => strtoupper(str_pad($chi, 6, 'X')) . str_pad((string) $s['seq'], 10, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$sigla]->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** Crea una voce dal modale (rotta store), come fa l'amministratore. Restituisce la voce creata. */
function be52Voce($test, array $s, PianoConto $pc, string $nome, string $tipo, string $importo): Conto
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    return Conto::where('piano_conto_id', $pc->id)->where('nome', $nome)->firstOrFail();
}

/** Due unita uguali (Ugo e Bice, 500 e 500) e le due voci del collaudo: spese 1.200,00 ed entrata 240,00. */
function be52Scenario($test, string $importoEntrata = '240,00', string $importoSpesa = '1.200,00'): array
{
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    be52Unita($s, 'C', 'bice', $s['tabella'], 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $s['spesa'] = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', $importoSpesa);
    $s['entrata'] = be52Voce($test, $s, $s['pc'], 'Affitto antenna', 'entrata', $importoEntrata);

    return $s;
}

/** Piano ordinario dalla rotta, «lascia vuoto» (nessuna voce scelta: entrano tutte), con generazione. */
function be52PianoDallaRotta($test, array $s, string $nome = 'Preventivo 2026', int $rate = 12): PianoRate
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => $rate,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();

    return PianoRate::where('nome', $nome)->where('condominio_id', $s['c']->id)->firstOrFail();
}

/** Piano ordinario dalla rotta con le sole voci scelte (`capitoli_ids`), con generazione. */
function be52PianoConVoci($test, array $s, array $contiIds, string $nome = 'Solo le voci scelte', array $extra = []): PianoRate
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1, 'capitoli_ids' => $contiIds,
    ], $extra))->assertSessionHasNoErrors();

    return PianoRate::where('nome', $nome)->where('condominio_id', $s['c']->id)->firstOrFail();
}

/** Il totale che il piano chiede (somma delle quote di tutte le rate) e quanto chiede a ciascuno. */
function be52Totale(PianoRate $piano): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo');
}

function be52PerPersona(PianoRate $piano, array $s): array
{
    $out = [];
    foreach ($s['persone'] as $chi => $a) {
        $out[$chi] = (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $a->id)->sum('rate_quote.importo');
    }

    return $out;
}

function be52Pivot(PianoRate $piano): array
{
    return DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->orderBy('conto_id')->pluck('importo', 'conto_id')->map(fn ($v) => $v === null ? null : (int) $v)->all();
}

function be52Ricalcola($test, array $s, PianoRate $piano)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]));
}

function be52Fornitore(): \App\Models\Fornitore
{
    $id = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Fornitore Test Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return \App\Models\Fornitore::find($id);
}

/** Il payload di `fatture.store` per una riga sul conto dato. Importo imponibile in euro, IVA 0 per leggere cifre tonde. */
function be52PayloadFattura(array $s, \App\Models\Fornitore $f, int $contoId, float $importoEuro, array $extra = [], string $num = 'FT-1'): array
{
    return array_merge([
        'fornitore_id' => $f->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'tipo_documento' => 'fattura',
        'numero_documento' => $num, 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'modalita_pagamento' => 'bonifico',
        'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [['descrizione' => 'Servizio', 'importo_imponibile' => $importoEuro, 'aliquota_iva' => 0, 'conto_id' => $contoId, 'is_sopravvenienza' => false]],
    ], $extra);
}

function be52Errori(): array
{
    return session('errors') ? session('errors')->toArray() : [];
}

/** Il corpo di `conti.update` come lo manda il modale, con le correzioni che servono. */
function be52PayloadVoce(array $s, array $over = []): array
{
    return array_merge(['nome' => 'X', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '100,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false, 'confermaConversioneCapitolo' => false], $over);
}

function be52UpdateVoce($test, array $s, Conto $voce, array $over)
{
    return $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $voce]), be52PayloadVoce($s, array_merge(['nome' => $voce->nome], $over)));
}

/** Un condominio con due unità uguali e un piano dei conti vuoto: le voci le aggiunge il test. */
function be52Vuoto($test): array
{
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    be52Unita($s, 'C', 'bice', $s['tabella'], 500.0);
    $s['pc'] = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);

    return $s;
}

function be52Detach($test, array $s, PianoRate $piano, int $contoId)
{
    return $test->actingAs($test->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano, $contoId]));
}

function be52RicalcolaCon($test, array $s, PianoRate $piano, array $corpo = [])
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), $corpo);
}

function be52Flash(): ?array
{
    return session('message');
}

/** Una fattura scritta dal servizio (senza passare dalla Request), com'era possibile prima delle guardie: serve a costruire il dato vecchio. */
function be52FatturaDalServizio(array $s, \App\Models\Fornitore $f, int $contoId, float $euro, string $num = 'FT-VECCHIA', array $extra = []): \App\Models\Gestionale\FatturaPassiva
{
    $dati = array_merge(be52PayloadFattura($s, $f, $contoId, $euro, [], $num), $extra);

    return (new \App\Services\Gestionale\FatturaPassivaService())->registraFattura($dati, $s['c']->id);
}

/** Il corpo di `fatture.update` come lo manda il modulo di modifica. */
function be52PayloadModifica(\App\Models\Gestionale\FatturaPassiva $ft, array $s, array $righe, array $extra = []): array
{
    return array_merge(['gestione_id' => $s['g']->id, 'numero_documento' => $ft->numero_documento, 'data_documento' => $ft->data_documento->format('Y-m-d'),
        'data_scadenza' => $ft->data_scadenza->format('Y-m-d'), 'modalita_pagamento' => 'bonifico', 'righe' => $righe], $extra);
}

function be52Riga(int $contoId, float $euro, array $extra = []): array
{
    return array_merge(['descrizione' => 'Servizio', 'importo_imponibile' => $euro, 'aliquota_iva' => 0, 'conto_id' => $contoId], $extra);
}

/** Un fondo di riserva (mastro e cassa di tipo fondo) del condominio dello scenario. */
function be52Fondo(array $s, string $nome): ContoContabile
{
    $cc = ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => 'F-'.$s['c']->id.'-'.substr(md5($nome), 0, 4), 'nome' => $nome, 'tipo' => 'attivo', 'ruolo' => 'fondo_riserva', 'categoria' => 'liquidita']);
    Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $cc->id, 'nome' => $nome, 'tipo' => 'fondo', 'attiva' => true, 'saldo_iniziale' => 500000]);

    return $cc;
}

/** Un debito verso un fornitore (saldo iniziale negativo) del condominio dello scenario. */
function be52DebitoFornitore(array $s, \App\Models\Fornitore $f, int $cents = -100000): int
{
    return DB::table('saldi')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'fornitore_id' => $f->id, 'saldo_iniziale' => $cents, 'created_at' => now(), 'updated_at' => now()]);
}

/** Il corpo di una fattura pregressa (nessuna riga, coperture dichiarate). */
function be52PayloadPregressa(array $s, \App\Models\Fornitore $f, array $extra, string $num): array
{
    return array_merge([
        'fornitore_id' => $f->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $num,
        'data_documento' => '2024-11-30', 'data_scadenza' => '2024-12-31', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'competenza_dal' => '2024-11-01', 'competenza_al' => '2024-11-30', 'is_pregresso' => true, 'imponibile_pregresso' => 500.00, 'aliquota_iva_pregressa' => 22,
        'data_competenza_originaria' => '2024-11-30', 'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null], 'righe' => [],
    ], $extra);
}

/** I mastri «passate gestioni» che una pregressa richiede, per ogni condominio dato. */
function be52MastriPregresso(array ...$scenari): void
{
    foreach ($scenari as $s) {
        ContoContabile::firstOrCreate(['condominio_id' => $s['c']->id, 'ruolo' => 'passate_gestioni'], ['codice' => 'PASS-'.$s['c']->id, 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'categoria' => 'debiti', 'attivo' => true]);
    }
}

/** Le righe di giornale che portano un mastro, una voce o un'unità che non sono del condominio dato. */
function be52RigheAltrui(array $mio, array $altro): array
{
    return [
        'con_mastro_altrui' => DB::table('righe_scritture as r')->join('conti_contabili as cc', 'cc.id', '=', 'r.conto_contabile_id')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'r.scrittura_id')->where('sc.condominio_id', $mio['c']->id)->where('cc.condominio_id', '!=', $mio['c']->id)->count(),
        'con_voce_altrui' => DB::table('righe_scritture')->whereIn('voce_spesa_id', DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.condominio_id', $altro['c']->id)->pluck('conti.id'))->count(),
        'con_unita_altrui' => DB::table('righe_scritture')->whereIn('immobile_id', DB::table('immobili')->where('condominio_id', $altro['c']->id)->pluck('id'))->count(),
    ];
}

function be52Costi(array $s): int
{
    return (int) app(\App\Services\Gestionale\StatoPatrimonialeService::class)->calcola($s['c'], $s['e'])['costi'];
}

/** Una gestione straordinaria dello stesso condominio, con il suo piano dei conti. */
function be52GestioneStraordinaria(array $s): array
{
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $s['e'], 'straordinaria');
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti straordinario']);

    return ['g' => $g, 'pc' => $pc];
}

/** Un piano ordinario senza voci nella pivot (com'erano i piani più vecchi): il motore legge le radici del piano dei conti. */
function be52PianoSenzaPivot(array $s, string $nome = 'Piano globale'): PianoRate
{
    return PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => $nome, 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => true]);
}

/** Crea una voce (voce, capitolo o sottoconto) dal modale, con i dati dati sopra quelli di una spesa normale. */
function be52PostVoce($test, array $s, array $dati)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), array_merge([
        'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false], $dati));
}

/**
 * Un capitolo «Manutenzioni» con i suoi figli e la sua copia nel piano. Gli importi dei figli sono in centesimi, riscritti dopo la creazione
 * (la rotta rifiuta il meno); `$nascite` dice quando è nato un figlio (di default il giorno prima del piano); `$conTabelle` dà al capitolo
 * le tabelle millesimali proprie.
 *
 * @param array<string, int> $figli nome => importo in centesimi
 * @param array<string, string> $nascite nome => data e ora di nascita
 */
function be52CapitoloConFigli($test, array $s, PianoRate $piano, array $figli, ?int $copia, bool $conTabelle = false, array $nascite = []): Conto
{
    be52PostVoce($test, $s, ['nome' => 'Manutenzioni', 'importo' => '0,00', 'tabella_millesimale_id' => null, 'isCapitolo' => true]);
    $padre = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Manutenzioni')->firstOrFail();
    if ($conTabelle) {
        $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $padre->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($figli as $nome => $cent) {
        be52PostVoce($test, $s, ['nome' => $nome, 'importo' => '10,00', 'parent_id' => $padre->id, 'isSottoConto' => true]);
        DB::table('conti')->where('piano_conto_id', $s['pc']->id)->where('nome', $nome)->update(['importo' => $cent, 'created_at' => $nascite[$nome] ?? now()->subDay()]);
    }
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $padre->id, 'importo' => $copia, 'created_at' => now(), 'updated_at' => now()]);

    return $padre;
}

/** Il totale che il piano chiede dopo un «Ricalcola», e il flash di quel ricalcolo. */
function be52RicalcolaETotale($test, array $s, PianoRate $piano): array
{
    be52Ricalcola($test, $s, $piano->fresh());

    return [be52Flash()['type'] ?? null, be52Totale($piano->fresh())];
}

/** Gli id delle quote del piano, nell'ordine in cui sono state scritte: se un ricalcolo non le tocca restano gli stessi. */
function be52Quote(PianoRate $piano): array
{
    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')->pluck('rate_quote.id')->all();
}

/**
 * Il piano «lascia vuoto» con la spesa base (€ 1.200,00) e una voce in più, nata positiva e poi riscritta com'era possibile con le versioni
 * in cui la rotta accettava il meno: `conti.importo` e la copia nel piano (`piano_rate_capitoli.importo`) hanno il valore dato.
 * Con le due voci iniziali a € 1.200,00 e € 240,00 il piano chiede 144000 centesimi (96000 se la voce è un'entrata).
 *
 * @return array{0: array, 1: PianoRate, 2: Conto, 3: Conto} scenario, piano, voce in più, spesa base
 */
function be52PianoVecchio($test, string $tipo = 'spesa', ?int $importoVoce = -24000, int|string|null $copia = -24000): array
{
    $s = be52Vuoto($test);
    $base = be52Voce($test, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $voce = be52Voce($test, $s, $s['pc'], 'Rimborso', $tipo, '240,00');
    $piano = be52PianoDallaRotta($test, $s);
    DB::table('conti')->where('id', $voce->id)->update(['importo' => $importoVoce]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $voce->id)->update(['importo' => $copia]);

    return [$s, $piano->fresh(), $voce->fresh(), $base];
}

/** Una fattura corrente tutta addebitata a una sola unità (riga con l'unità, senza capitolo), scritta dal servizio. */
function be52FatturaAdPersonam(array $s, \App\Models\Fornitore $f, int $immobileId, float $euro, string $num): \App\Models\Gestionale\FatturaPassiva
{
    return (new \App\Services\Gestionale\FatturaPassivaService())->registraFattura(array_merge(be52PayloadFattura($s, $f, 0, $euro, [], $num), [
        'righe' => [['descrizione' => 'Lavori in una sola unità', 'importo_imponibile' => $euro, 'aliquota_iva' => 0, 'immobile_id' => $immobileId]],
    ]), $s['c']->id);
}
