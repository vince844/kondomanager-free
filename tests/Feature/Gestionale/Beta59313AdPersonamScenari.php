<?php

/**
 * Gli scenari della Coda 313: la spesa ad personam rateizzata in un piano straordinario, dalla fattura all'incasso, con le rotte
 * che le pagine chiamano.
 *
 * Il caso della riga della roadmap: una fattura da € 1.220,00 (imponibile € 1.000,00 + IVA 22 %) con una sola riga sull'unità
 * «Interno 1» di Mario Rossi, registrata dal modulo della fattura (`fatture.store`): la fattura scrive DARE «Crediti verso
 * condomini» con l'unità, senza persona (l'anticipazione, art. 63). Un piano straordinario dal carrello la finanzia
 * (`esercizi.piani-rate.store`, generato subito), approvato dalla sua pagina (`piani-rate.update-stato`), emesso
 * (`piani-rate.emetti`) e incassato (`movimenti-rate.store`).
 *
 * La regola delle cifre (decisione 153.1, progetto della Coda 313): l'emissione di una quota con una parte ad personam `a` (quota
 * pura `Q`) scrive DARE «Crediti verso condomini» a nome della persona per `Q`, AVERE «Gestione rate» per `Q − a` e AVERE «Crediti
 * verso condomini» con l'unità e la rata, senza persona, per `a`. Dopo l'incasso pieno «Crediti verso condomini» dell'unità è a
 * zero, «Gestione rate» e il risultato di gestione non hanno l'ad personam, e nessuna persona paga una cifra diversa.
 *
 * Le cifre sono in centesimi. Prefisso `b59ap`: Pest carica tutti i file nello stesso processo.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

if (! function_exists('b59apAdmin')) {
    function b59apAdmin(): User
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
        $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $ruolo->givePermissionTo($permesso);
        $u = User::factory()->create();
        $u->assignRole($ruolo);

        return $u;
    }

    /**
     * Il condominio: esercizio 2026 aperto, una gestione straordinaria «Lavori 2026» con il suo piano dei conti, la tabella
     * «Proprietà» (Interno 1 e Interno 2 a 500 millesimi), i conti di sistema, una banca. Interno 1 è di `$titolari`
     * (nome => quota di proprietà), Interno 2 di Bice Bianchi. Con `$tipoGestione` = «ordinaria» la gestione è ordinaria (un
     * piano da fatture su una gestione ordinaria, come in `RiassegnazioneBozzeTest`); `$saldi` sono i saldi iniziali della
     * gestione per persona (chiave: il nome in minuscolo; positivo = debito): con un saldo il piano creato dalla pagina li applica.
     *
     * @param array<string, int> $titolari
     * @param array<string, int> $saldi
     */
    function b59apBase(array $titolari = ['Mario Rossi' => 100], string $tipoGestione = 'straordinaria', array $saldi = []): array
    {
        static $seq = 0;
        $seq++;
        \Illuminate\Support\Facades\Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
        $c = Condominio::factory()->create();
        $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
        $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Lavori 2026', 'tipo' => $tipoGestione, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
        legaAEsercizio($e, $g->id);
        $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti lavori']);
        $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);

        $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
        foreach ([
            ['10.20', 'Crediti verso condomini', 'attivo', 'crediti_condomini', 'crediti'],
            ['20.10', 'Anticipi', 'passivo', 'anticipi_condomini', 'debiti'],
            ['20.20', 'Gestione rate', 'passivo', 'gestione_rate', 'debiti'],
            ['20.30', 'Debiti verso fornitori', 'passivo', 'debiti_fornitori', 'debiti'],
            ['20.40', 'Debiti verso erario', 'passivo', 'debiti_erario_ritenute', 'debiti'],
            ['30.10', 'Passate gestioni', 'passivo', 'passate_gestioni', 'debiti'],
            // Sul database dei test la colonna `tipo` ammette solo attivo e passivo: il conto delle sopravvenienze si crea prima,
            // come fa `setupContabile()`, e il servizio della fattura lo trova.
            ['40.10', 'Sopravvenienze passive', 'passivo', 'sopravvenienze_passive', 'debiti'],
        ] as [$codice, $nome, $tipo, $ruolo, $categoria]) {
            ContoContabile::create(['condominio_id' => $c->id, 'codice' => $codice, 'nome' => $nome, 'tipo' => $tipo, 'ruolo' => $ruolo, 'categoria' => $categoria]);
        }
        $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);

        $fornitore = \App\Models\Fornitore::find(DB::table('fornitori')->insertGetId([
            'ragione_sociale' => 'Edile Ponti Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
            'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $unita = [];
        $persone = [];
        $righeTitolarita = [];
        $crea = function (string $nome) use ($c, $seq, &$persone): Anagrafica {
            $chiave = strtolower(strtok($nome, ' '));
            $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "b59ap-{$chiave}{$seq}@test.it", 'indirizzo' => 'Via Roma 1',
                'codice_fiscale' => strtoupper(str_pad("B59AP{$chiave}{$seq}", 16, 'X'))]);
            $p->condomini()->syncWithoutDetaching([$c->id]);

            return $persone[$chiave] = $p;
        };
        foreach (['1' => $titolari, '2' => ['Bice Bianchi' => 100]] as $interno => $chi) {
            $unita[$interno] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "B59AP-{$seq}-{$interno}", 'nome' => "Interno {$interno}", 'interno' => $interno]);
            DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita[$interno]->id, 'valore' => 500.0, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($chi as $nome => $quota) {
                $p = $crea($nome);
                $righeTitolarita[strtolower(strtok($nome, ' '))] = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita[$interno]->id,
                    'tipologia' => 'proprietario', 'quota' => $quota, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach ($saldi as $chiave => $importo) {
            $unitaDi = DB::table('anagrafica_immobile')->where('id', $righeTitolarita[$chiave])->value('immobile_id');
            \App\Models\Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $persone[$chiave]->id, 'immobile_id' => $unitaDi,
                'gestione_id' => $g->id, 'saldo_iniziale' => $importo, 'origine' => 'manuale', 'is_applicato' => false]);
        }

        return compact('c', 'e', 'g', 'pc', 'tabella', 'cassa', 'fornitore', 'unita', 'persone', 'righeTitolarita');
    }

    /**
     * La riga ad personam del modulo della fattura: imponibile e aliquota in euro, «fuori preventivo» e l'unità scelta (la spesa
     * privata imprevista: il modulo non chiede il capitolo, e il servizio non ne mette).
     */
    function b59apRigaAdPersonam(array $s, float $imponibile = 1000.00, int $aliquota = 22, string $interno = '1', string $descrizione = 'Riparazione balcone'): array
    {
        return ['descrizione' => $descrizione, 'importo_imponibile' => $imponibile, 'aliquota_iva' => $aliquota, 'conto_id' => null,
            'immobile_id' => $s['unita'][$interno]->id, 'is_sopravvenienza' => true, 'concorre_base_ritenuta' => true];
    }

    /** La riga di spesa comune «fuori preventivo», senza unità: il modulo manda il «log legale» e il servizio crea la voce sulla tabella. */
    function b59apRigaComune(float $imponibile, int $aliquota = 0, string $descrizione = 'Ponteggio facciata'): array
    {
        return ['descrizione' => $descrizione, 'importo_imponibile' => $imponibile, 'aliquota_iva' => $aliquota, 'conto_id' => null,
            'immobile_id' => null, 'is_sopravvenienza' => true, 'concorre_base_ritenuta' => true];
    }

    /**
     * La fattura dal modulo (`fatture.store`), come la manda la pagina. Con una riga di spesa comune (`is_sopravvenienza` senza
     * unità) il modulo manda anche il «log legale», e il servizio crea la voce imprevista sulla tabella «Proprietà».
     */
    function b59apFattura($test, array $s, array $righe, string $numero = 'FT-313'): FatturaPassiva
    {
        $comune = collect($righe)->contains(fn ($r) => ($r['is_sopravvenienza'] ?? false) && empty($r['immobile_id']));
        $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), datiBase([$s['c'], $s['e'], $s['g'], $s['fornitore']], [
            'numero_documento' => $numero, 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'stato_approvazione' => 'approvata',
            'applica_ritenuta' => false, 'righe' => $righe,
            'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null,
                'log_legale_sopravvenienza' => $comune ? [
                    'nome_voce' => 'Rifacimento facciata', 'origine_decisionale' => 'delibera_assembleare', 'data_assemblea' => '2026-02-01',
                    'tipo_ripartizione' => 'millesimale', 'is_ordinario' => false, 'richiede_copertura' => false, 'tabella_millesimale_id' => $s['tabella']->id,
                    'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
                ] : null],
        ]))->assertSessionHasNoErrors();

        return FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', $numero)->firstOrFail();
    }

    /**
     * Il piano straordinario dal carrello (`esercizi.piani-rate.store`, generato subito) e approvato dalla sua pagina. `$importo`
     * è il campo «importo» del carrello in euro (null: tutta la fattura).
     */
    function b59apPiano($test, array $s, FatturaPassiva $fattura, int $numeroRate = 2, ?string $importo = null, string $nome = 'Balcone interno 1', bool $approva = true): PianoRate
    {
        $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c']->id, $s['e']->id]), [
            'gestione_id' => $s['g']->id, 'nome' => $nome, 'tipo' => 'straordinario',
            'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera del 1° febbraio 2026', 'data_delibera_assemblea' => '2026-02-01',
            'fatture_config' => [['id' => $fattura->id, 'importo' => $importo ?? number_format($fattura->totale_documento / 100, 2, ',', '')]],
            'metodo_distribuzione' => 'prima_rata', 'numero_rate' => $numeroRate, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-04-10',
            'capitoli_ids' => [], 'genera_subito' => true, 'accetta_destinatari' => true, 'nota_destinatari' => 'Delibera del 1° febbraio 2026',
        ])->assertSessionHasNoErrors();
        $piano = PianoRate::where('condominio_id', $s['c']->id)->where('nome', $nome)->firstOrFail();
        expect(DB::table('rate')->where('piano_rate_id', $piano->id)->count())->toBe($numeroRate);
        if ($approva) {
            $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-02-01'])
                ->assertSessionHasNoErrors();
        }

        return $piano->fresh();
    }

    function b59apRata(PianoRate $piano, int $numero): Rata
    {
        return Rata::where('piano_rate_id', $piano->id)->where('numero_rata', $numero)->firstOrFail();
    }

    function b59apQuota(PianoRate $piano, int $numero, Anagrafica $p): RataQuote
    {
        return RataQuote::where('rata_id', b59apRata($piano, $numero)->id)->where('anagrafica_id', $p->id)->firstOrFail();
    }

    /** L'emissione dalla pagina del piano. */
    function b59apEmetti($test, array $s, PianoRate $piano, array $numeri, string $il = '2026-04-01')
    {
        session()->forget('message');

        return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), [
            'rate_ids' => array_map(fn ($n) => b59apRata($piano, $n)->id, $numeri), 'data_emissione' => $il, 'invia_notifiche' => false,
        ]);
    }

    function b59apEuro(int $c): string
    {
        return sprintf('%s%d.%02d', $c < 0 ? '-' : '', intdiv(abs($c), 100), abs($c) % 100);
    }

    /** Un incasso dal modulo «Nuovo incasso»: `$righe` = [numero della rata => centesimi] sulle quote di `$chi`. Restituisce l'incasso. */
    function b59apIncassa($test, array $s, PianoRate $piano, Anagrafica $chi, array $righe, string $il = '2026-04-15'): int
    {
        $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
            'pagante_id' => $chi->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $il, 'importo_totale' => b59apEuro(array_sum($righe)),
            'descrizione' => 'Bonifico', 'eccedenza' => '0.00',
            'dettaglio_pagamenti' => array_map(fn ($n, $cent) => ['rata_id' => b59apQuota($piano, $n, $chi)->id, 'importo' => b59apEuro($cent)], array_keys($righe), array_values($righe)),
        ])->assertSessionHasNoErrors();

        return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
    }

    function b59apConto(array $s, string $ruolo): int
    {
        return (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->value('id');
    }

    /** «Crediti verso condomini» dell'unità: DARE − AVERE di tutte le righe con quell'unità, con la persona o senza, su tutto il giornale. */
    function b59apCreditiUnita(array $s, string $interno = '1'): int
    {
        return (int) DB::table('righe_scritture')->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
            ->whereNull('scritture_contabili.deleted_at')->where('righe_scritture.conto_contabile_id', b59apConto($s, 'crediti_condomini'))
            ->where('righe_scritture.immobile_id', $s['unita'][$interno]->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END), 0) AS n")->value('n');
    }

    /** «Gestione rate»: AVERE − DARE, su tutto il giornale del condominio. */
    function b59apGestioneRate(array $s): int
    {
        return -(int) DB::table('righe_scritture')->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
            ->whereNull('scritture_contabili.deleted_at')->where('righe_scritture.conto_contabile_id', b59apConto($s, 'gestione_rate'))
            ->selectRaw("COALESCE(SUM(CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END), 0) AS n")->value('n');
    }

    /** La pagina dello stato patrimoniale: i crediti verso condomini della sintesi, le quote emesse e il risultato di gestione (D18). */
    function b59apStatoPatrimoniale($test, array $s): array
    {
        $pagina = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $s['e']]))
            ->assertOk()->viewData('page')['props']['pagina'];

        return [
            'crediti' => (int) $pagina['sintesi']['crediti_condomini'],
            'quote_emesse' => (int) $pagina['risultato_gestione']['quote_emesse'],
            'risultato' => (int) $pagina['risultato_gestione']['risultato'],
        ];
    }

    /** Il saldo dell'estratto conto della persona (positivo = debito), dalla sua pagina. */
    function b59apEstratto($test, array $s, Anagrafica $chi): int
    {
        return (int) $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $chi]))
            ->assertOk()->viewData('page')['props']['stats']['saldo_raw'];
    }

    /** Le righe di una scrittura come [ruolo, verso, importo, persona?, unità?], in un ordine fisso. */
    function b59apRighe(int $scritturaId): array
    {
        return DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
            ->where('righe_scritture.scrittura_id', $scritturaId)
            ->get(['conti_contabili.ruolo', 'righe_scritture.tipo_riga', 'righe_scritture.importo', 'righe_scritture.anagrafica_id', 'righe_scritture.immobile_id'])
            ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo, $r->anagrafica_id === null ? null : (int) $r->anagrafica_id, $r->immobile_id === null ? null : (int) $r->immobile_id])
            ->sortBy(fn ($r) => $r[0].'|'.$r[1].'|'.str_pad((string) $r[2], 12, '0', STR_PAD_LEFT).'|'.($r[3] ?? 0))->values()->all();
    }

    /** La scrittura d'emissione della rata: quella a cui sono legate le sue quote. */
    function b59apEmissione(PianoRate $piano, int $numero): int
    {
        return (int) DB::table('rate_quote')->where('rata_id', b59apRata($piano, $numero)->id)->whereNotNull('scrittura_contabile_id')->min('scrittura_contabile_id');
    }

    /**
     * L'emissione come la scriveva la 1.11.0-beta.58 (e, per le quote a debito, la 1.9 e la 1.10): le righe della persona da
     * `righeDiEmissione`, e la spesa intera della quota (`quota_pura_gestione`, con l'ad personam) su «Gestione rate». Serve a
     * costruire i dati che le installazioni hanno già: la rotta di oggi scrive la riga di chiusura.
     */
    function b59apEmettiComePrima(array $s, PianoRate $piano, int $numero, string $il = '2026-04-01'): int
    {
        $rata = b59apRata($piano, $numero)->load('rateQuote');
        $sc = \App\Models\Gestionale\ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $piano->gestione_id,
            'data_registrazione' => now(), 'data_competenza' => $il, 'causale' => 'Emissione '.$rata->descrizione, 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata']);
        $gestione = 0;
        $pregresso = 0;
        foreach ($rata->rateQuote as $q) {
            $righe = $q->righeDiEmissione();
            foreach ($righe as $i => [$verso, $importo]) {
                $sc->righe()->create(['conto_contabile_id' => b59apConto($s, 'crediti_condomini'), 'anagrafica_id' => $q->anagrafica_id, 'immobile_id' => $q->immobile_id,
                    'rata_id' => $rata->id, 'tipo_riga' => $verso, 'importo' => $importo, 'note' => $q->notaDiEmissione($i, $verso, (string) $rata->descrizione)]);
            }
            $netto = array_sum(array_map(fn ($r) => $r[0] === 'dare' ? $r[1] : -$r[1], $righe));
            $spesa = $righe === [] ? 0 : $q->componenti()['spesa'];
            $gestione += $spesa;
            $pregresso += $netto - $spesa;
            $q->update(['scrittura_contabile_id' => $sc->id]);
        }
        foreach ([['gestione_rate', $gestione], ['passate_gestioni', $pregresso]] as [$ruolo, $totale]) {
            if ($totale !== 0) {
                $sc->righe()->create(['conto_contabile_id' => b59apConto($s, $ruolo), 'tipo_riga' => $totale > 0 ? 'avere' : 'dare', 'importo' => abs($totale), 'note' => 'Totale emissione '.$rata->descrizione]);
            }
        }
        $rata->update(['stato' => 'emessa']);

        return (int) $sc->id;
    }
}

if (! function_exists('b59apPianoConSaldi')) {
    /**
     * Il piano straordinario con il saldo riportato di Rossi nelle sue quote, come lo generano i piani che applicano i saldi
     * (`forzaApplicazioneSaldi`, metodo «prima rata»): ogni quota ha la spesa dell'anno (con la parte ad personam) e, sulla prima
     * rata, il saldo riportato. Approvato.
     */
    function b59apPianoConSaldi(array $s, FatturaPassiva $fattura, int $numeroRate = 2, string $metodo = 'prima_rata'): PianoRate
    {
        $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Balcone con saldi',
            'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => $numeroRate, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-04-10',
            'metodo_distribuzione' => $metodo, 'applica_saldi' => true, 'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera del 1° febbraio 2026',
            'data_delibera_assemblea' => '2026-02-01']);
        $piano->fatture()->attach($fattura->id, ['importo_collegato' => (int) $fattura->totale_documento]);
        app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true,
            notaDestinatari: 'Delibera del 1° febbraio 2026', esercizio: $s['e']);
        $piano->update(['stato' => 'approvato']);

        return $piano->fresh();
    }

    /**
     * L'emissione della 1.10.0 (v1.10.0 EmissioneRateController.php:121-268, come `rlcEmettiComeLa110` di `RiallineaCreditiTest`):
     * salta le quote ≤ 0, DARE l'importo intero, «Gestione rate» la quota pura (con l'ad personam), «Passate gestioni» la differenza.
     */
    function b59apEmettiComeLa110(array $s, PianoRate $piano, int $numero, string $il = '2026-04-01'): int
    {
        $rata = b59apRata($piano, $numero)->load('rateQuote');
        $sc = \App\Models\Gestionale\ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $piano->gestione_id,
            'data_registrazione' => now(), 'data_competenza' => $il, 'causale' => 'Emissione '.$rata->descrizione, 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata']);
        $tg = 0;
        $tp = 0;
        foreach ($rata->rateQuote as $q) {
            $imp = (int) $q->importo;
            if ($imp <= 0) {
                continue;
            }
            $g = (int) ($q->regole_calcolo['importi']['quota_pura_gestione'] ?? $imp);
            $sc->righe()->create(['conto_contabile_id' => b59apConto($s, 'crediti_condomini'), 'anagrafica_id' => $q->anagrafica_id, 'immobile_id' => $q->immobile_id,
                'rata_id' => $rata->id, 'tipo_riga' => 'dare', 'importo' => $imp, 'note' => 'Quota '.$rata->descrizione]);
            $q->update(['scrittura_contabile_id' => $sc->id]);
            $tg += $g;
            $tp += $imp - $g;
        }
        foreach ([['gestione_rate', $tg], ['passate_gestioni', $tp]] as [$ruolo, $t]) {
            if ($t !== 0) {
                $sc->righe()->create(['conto_contabile_id' => b59apConto($s, $ruolo), 'tipo_riga' => $t > 0 ? 'avere' : 'dare', 'importo' => abs($t), 'note' => 'Totale emissione '.$rata->descrizione]);
            }
        }
        $rata->update(['stato' => 'emessa']);

        return (int) $sc->id;
    }
}

if (! function_exists('b59apSenzaChiave')) {
    /** Le quote del piano come le ha generate una versione di prima: senza `importi.addebito_diretto` nello snapshot. */
    function b59apSenzaChiave(PianoRate $piano): void
    {
        foreach (RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->get() as $q) {
            $regole = $q->regole_calcolo;
            unset($regole['importi']['addebito_diretto']);
            DB::table('rate_quote')->where('id', $q->id)->update(['regole_calcolo' => json_encode($regole)]);
        }
    }
}
