<?php

/**
 * Cosa resta scoperto: MySQL (qui sqlite: la `LIKE` e l'`UPDATE` sono standard, il confronto di collazione no); la frase spezzata a mano su due
 * righe (non è la frase esatta e non si tocca: sta nello scenario «ritoccata a mano»); il database di un cliente con milioni di eventi (la
 * lettura a blocchi si prova con 450 promemoria).
 *
 * La migrazione che riscrive le descrizioni dei promemoria già salvate con «verrà utilizzato automaticamente» (decisione 87.5): questa
 * versione tocca i dati del database, e non le colonne.
 *
 * La frase vecchia sta in due forme del testo, quella della 1.8.0 («… (es. avanzo esercizio precedente)», rilasciata con una riga vuota dopo il
 * punto e in una versione intermedia con un solo a capo) e quella delle versioni successive («… registrato nella rata n. N»), con e senza la nota
 * della rata. La migrazione riscrive solo
 * la frase esatta, solo sugli eventi `scadenza_rata_condomino`, senza toccare `updated_at` (il portale filtra i promemoria pagati sui
 * 30 giorni da lì), e una riga a registro per ogni promemoria riscritto. Rieseguita non cambia niente. Legge a blocchi per `id`: le righe
 * riscritte escono dal filtro e un paginatore per posizione ne salterebbe una ogni due blocchi.
 *
 * La frase nuova dipende dal credito: «il credito resta a tuo favore. L'amministratore può usarlo per compensare una tua quota da pagare o
 * restituirtelo.» se la persona ha ancora credito su quella rata, «il credito è stato usato o restituito.» se ogni sua quota a credito su
 * quella rata è stata consumata (la rata 0 di un promemoria già «usato» dall'amministratore): la prima direbbe il falso.
 *
 * Gli stati non si ricalcolano (decisione 87.9), salvo quelli del caso misto scritti prima della .52, che la decisione 90.1 riallinea
 * (`Beta52PromemoriaMistiRiallineatiTest`): dopo il riallineamento la migrazione scrive nel registro quanti promemoria «paid» hanno ancora una
 * quota da pagare, e solo quelli (una quota annullata, un promemoria non saldato, uno saldato per intero non contano).
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const B52T_MIGRAZIONE = '2026_10_17_100000_riscrivi_descrizioni_promemoria_credito';

const B52T_VECCHIA_134 = "Gentile Bice, questa voce rappresenta un credito a tuo favore registrato nella rata n. 5.\n\nNon è richiesto alcun pagamento: l'importo verrà utilizzato automaticamente per compensare le rate successive.";
const B52T_VECCHIA_180 = "Gentile Bice, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\nNon è richiesto alcun pagamento: l'importo verrà utilizzato automaticamente per compensare le rate successive.";
const B52T_VECCHIA_180_DUE_A_CAPO = "Gentile Bice, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n\nNon è richiesto alcun pagamento: l'importo verrà utilizzato automaticamente per compensare le rate successive.";
const B52T_FRASE_NUOVA = "il credito resta a tuo favore. L'amministratore può usarlo per compensare una tua quota da pagare o restituirtelo.";
const B52T_FRASE_USATO = 'il credito è stato usato o restituito.';

function b52tEventoConDescrizione(string $descrizione, string $tipo = 'scadenza_rata_condomino', string $status = 'paid', ?int $creatore = null): int
{
    return (int) DB::table('eventi')->insertGetId([
        'title' => 'Scadenza rata 5 - Piano', 'description' => $descrizione, 'start_time' => '2026-05-05 00:00:00', 'end_time' => '2026-05-05 23:59:00',
        'created_by' => $creatore ?? App\Models\User::factory()->create()->id, 'visibility' => 'private', 'is_approved' => true, 'tipo' => $tipo,
        'meta' => json_encode(['type' => $tipo, 'status' => $status, 'importo_originale' => -1000, 'importo_restante' => -1000]),
        'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00',
    ]);
}

/**
 * Un promemoria della rata 0 con la frase vecchia, e la quota della persona su quella rata (`$importo` con il segno, `$pagato` con il segno): serve a
 * dire se il credito è ancora lì. La persona ha una quota a credito sulla rata e il promemoria punta alla rata dal `meta`.
 */
function b52tPromemoriaConQuota(int $importo, int $pagato, string $stato): int
{
    $c = App\Models\Condominio::factory()->create();
    $piano = App\Models\Gestionale\PianoRate::factory()->create(['condominio_id' => $c->id]);
    $rata = App\Models\Gestionale\Rata::factory()->create(['piano_rate_id' => $piano->id, 'numero_rata' => 0]);
    $persona = App\Models\Anagrafica::factory()->create();
    DB::table('rate_quote')->insert(['rata_id' => $rata->id, 'anagrafica_id' => $persona->id, 'importo' => $importo, 'importo_pagato' => $pagato, 'stato' => $stato, 'tipo' => 'saldo_iniziale', 'data_scadenza' => '2026-01-31', 'created_at' => now(), 'updated_at' => now()]);
    $id = b52tEventoConDescrizione(str_replace('rata n. 5', 'rata n. 0', B52T_VECCHIA_134));
    DB::table('eventi')->where('id', $id)->update(['meta' => json_encode(['type' => 'scadenza_rata_condomino', 'status' => 'pending', 'importo_originale' => $importo, 'importo_restante' => $importo, 'context' => ['rata_id' => $rata->id]])]);
    DB::table('anagrafica_evento')->insert(['anagrafica_id' => $persona->id, 'evento_id' => $id]);

    return $id;
}

function b52tEseguiMigrazione(): array
{
    $percorso = database_path('migrations/'.B52T_MIGRAZIONE.'.php');
    expect(file_exists($percorso))->toBeTrue('La migrazione non esiste: '.B52T_MIGRAZIONE);

    $registro = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$registro) {
        if (str_starts_with($m->message, 'Migrazione: descrizione del promemoria')) {
            $registro[] = $m->message;
        }
    });
    (require $percorso)->up();

    return $registro;
}

it('M1 riscrive la frase esatta nelle due forme storiche e lascia il resto del testo, senza toccare stato, meta e updated_at', function () {
    $nuovo = b52tEventoConDescrizione(B52T_VECCHIA_134);
    $vecchio = b52tEventoConDescrizione(B52T_VECCHIA_180);
    $conNota = b52tEventoConDescrizione(B52T_VECCHIA_134."\n\nNote: scadenza prorogata al 10 maggio");
    $metaPrima = DB::table('eventi')->where('id', $nuovo)->value('meta');

    b52tEseguiMigrazione();

    expect(DB::table('eventi')->where('id', $nuovo)->value('description'))->toBe(str_replace("l'importo verrà utilizzato automaticamente per compensare le rate successive.", B52T_FRASE_NUOVA, B52T_VECCHIA_134));
    expect(DB::table('eventi')->where('id', $vecchio)->value('description'))->toContain('(es. avanzo esercizio precedente).')->toContain('Non è richiesto alcun pagamento: '.B52T_FRASE_NUOVA)->not->toContain('automaticamente');
    expect(DB::table('eventi')->where('id', $conNota)->value('description'))->toEndWith(B52T_FRASE_NUOVA."\n\nNote: scadenza prorogata al 10 maggio");
    expect(DB::table('eventi')->where('id', $nuovo)->value('meta'))->toBe($metaPrima);
    expect(DB::table('eventi')->whereIn('id', [$nuovo, $vecchio, $conNota])->pluck('updated_at')->unique()->all())->toBe(['2026-03-01 10:00:00']);
});

it('M2 rieseguita non cambia niente e non scrive nel registro', function () {
    $id = b52tEventoConDescrizione(B52T_VECCHIA_134);
    expect(b52tEseguiMigrazione())->toHaveCount(1);
    $dopoIlPrimoGiro = DB::table('eventi')->where('id', $id)->first();

    expect(b52tEseguiMigrazione())->toBe([]);

    expect(DB::table('eventi')->where('id', $id)->first())->toEqual($dopoIlPrimoGiro);
});

it('M3 non tocca ciò che non è la frase esatta: una descrizione ritoccata a mano, un promemoria senza credito, un evento di un altro tipo con la stessa frase', function () {
    $ritoccata = b52tEventoConDescrizione("Gentile Bice, il tuo credito verrà utilizzato automaticamente dall'amministratore.");
    $scadenza = b52tEventoConDescrizione('Gentile Bice, ti ricordiamo la scadenza della rata condominiale n. 5.', status: 'pending');
    $altroTipo = b52tEventoConDescrizione(B52T_VECCHIA_134, tipo: EventoTipo::VERIFICA_PAGAMENTO->value, status: 'pending');
    $prima = DB::table('eventi')->whereIn('id', [$ritoccata, $scadenza, $altroTipo])->get()->keyBy('id');

    expect(b52tEseguiMigrazione())->toBe([]);

    expect(DB::table('eventi')->whereIn('id', [$ritoccata, $scadenza, $altroTipo])->get()->keyBy('id'))->toEqual($prima);
});

it('M4 ogni promemoria riscritto va a registro, con il suo id', function () {
    $a = b52tEventoConDescrizione(B52T_VECCHIA_134);
    $b = b52tEventoConDescrizione(B52T_VECCHIA_180);

    $registro = b52tEseguiMigrazione();

    expect($registro)->toHaveCount(2);
    expect($registro[0])->toContain("promemoria {$a} ")->and($registro[1])->toContain("promemoria {$b} ");
});

it('M5 un promemoria riscritto non riappare nella finestra del portale: il suo updated_at di quattro mesi fa resta quello', function () {
    $id = b52tEventoConDescrizione(B52T_VECCHIA_134);
    DB::table('eventi')->where('id', $id)->update(['updated_at' => now()->subDays(120)]);
    $prima = DB::table('eventi')->where('id', $id)->value('updated_at');

    b52tEseguiMigrazione();

    expect(Evento::find($id)->updated_at->lt(now()->subDays(30)))->toBeTrue();
    expect(DB::table('eventi')->where('id', $id)->value('updated_at'))->toBe($prima);
});

it('M6 con più di duecento promemoria li riscrive tutti: la lettura a blocchi non ne salta nessuno', function () {
    $creatore = App\Models\User::factory()->create()->id;
    $ids = [];
    for ($i = 0; $i < 450; $i++) {
        $ids[] = b52tEventoConDescrizione(B52T_VECCHIA_134, creatore: $creatore);
    }

    $registro = b52tEseguiMigrazione();

    expect($registro)->toHaveCount(450);
    expect(DB::table('eventi')->whereIn('id', $ids)->where('description', 'like', '%automaticamente%')->count())->toBe(0);
    expect(DB::table('eventi')->whereIn('id', $ids)->where('description', 'like', '%'.B52T_FRASE_NUOVA.'%')->count())->toBe(450);
});

it('M7 un credito già usato o restituito non resta «a tuo favore»: la frase dice che è stato usato o restituito; con del credito ancora lì, o usato in parte, dice la frase di sempre', function () {
    $usato = b52tPromemoriaConQuota(-10000, -10000, 'pagata');
    $disponibile = b52tPromemoriaConQuota(-10000, 0, 'credito');
    $inParte = b52tPromemoriaConQuota(-10000, -4000, 'credito');

    expect(b52tEseguiMigrazione())->toHaveCount(3);

    $d = fn (int $id) => DB::table('eventi')->where('id', $id)->value('description');
    expect($d($usato))->not->toContain('resta a tuo favore')->not->toContain('automaticamente')->toContain('Non è richiesto alcun pagamento: '.B52T_FRASE_USATO);
    expect($d($disponibile))->toContain('Non è richiesto alcun pagamento: '.B52T_FRASE_NUOVA)->not->toContain('automaticamente');
    expect($d($inParte))->toContain('Non è richiesto alcun pagamento: '.B52T_FRASE_NUOVA)->not->toContain(B52T_FRASE_USATO);
});

it('un promemoria senza il riferimento alla rata nel meta riceve la frase di sempre: non c\'è una quota da leggere', function () {
    $id = b52tEventoConDescrizione(B52T_VECCHIA_180);

    b52tEseguiMigrazione();

    expect(DB::table('eventi')->where('id', $id)->value('description'))->toContain('Non è richiesto alcun pagamento: '.B52T_FRASE_NUOVA);
});

it('M7c la forma della 1.8.0 rilasciata, con la riga vuota dopo il punto, si riscrive come le altre: la frase nuova al posto di quella vecchia, e il resto com\'è', function () {
    $id = b52tEventoConDescrizione(B52T_VECCHIA_180_DUE_A_CAPO."\n\nNote: scadenza prorogata al 10 maggio");

    expect(b52tEseguiMigrazione())->toHaveCount(1);

    expect(DB::table('eventi')->where('id', $id)->value('description'))->toBe(
        "Gentile Bice, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n\nNon è richiesto alcun pagamento: ".B52T_FRASE_NUOVA."\n\nNote: scadenza prorogata al 10 maggio"
    );
});

/** Un promemoria con lo `status` dato e le quote della persona sulla rata (`[importo, pagato, stato]`, con il segno), per il conteggio della migrazione. */
function b52tPromemoriaConLeQuote(string $status, array $quote): int
{
    $c = App\Models\Condominio::factory()->create();
    $piano = App\Models\Gestionale\PianoRate::factory()->create(['condominio_id' => $c->id]);
    $rata = App\Models\Gestionale\Rata::factory()->create(['piano_rate_id' => $piano->id, 'numero_rata' => 3]);
    $persona = App\Models\Anagrafica::factory()->create();
    foreach ($quote as [$importo, $pagato, $stato]) {
        DB::table('rate_quote')->insert(['rata_id' => $rata->id, 'anagrafica_id' => $persona->id, 'importo' => $importo, 'importo_pagato' => $pagato, 'stato' => $stato, 'tipo' => 'ordinaria', 'data_scadenza' => '2026-01-31', 'created_at' => now(), 'updated_at' => now()]);
    }
    $id = b52tEventoConDescrizione('Gentile Bice, ti ricordiamo la scadenza della rata condominiale n. 3.', status: $status);
    DB::table('eventi')->where('id', $id)->update(['meta' => json_encode(['type' => 'scadenza_rata_condomino', 'status' => $status, 'importo_originale' => 0, 'importo_restante' => 0, 'context' => ['rata_id' => $rata->id]])]);
    DB::table('anagrafica_evento')->insert(['anagrafica_id' => $persona->id, 'evento_id' => $id]);

    return $id;
}

// Cambiata l'attesa (prima «due su cinque»): con la decisione 90.1 il promemoria misto scritto prima della .52 si riallinea e non è più «paid»
// (`Beta52PromemoriaMistiRiallineatiTest`); il conteggio, che viene dopo il riallineamento, resta per quelli che non sono misti.
it('M8 il registro dice quanti promemoria saldati hanno ancora una quota da pagare: uno su cinque, dopo il riallineamento dei misti (decisioni 87.9 e 90.1)', function () {
    b52tPromemoriaConLeQuote('paid', [[5000, 0, 'da_pagare'], [-5000, 0, 'credito']]);   // misto, scritto prima della .52: si riallinea e non si conta
    b52tPromemoriaConLeQuote('paid', [[4000, 4000, 'pagata']]);                           // tutto pagato
    b52tPromemoriaConLeQuote('paid', [[5000, 0, 'annullata']]);                           // la quota da pagare è annullata: non conta
    b52tPromemoriaConLeQuote('pending', [[5000, 0, 'da_pagare']]);                        // non è saldato: non è il caso che si conta
    b52tPromemoriaConLeQuote('paid', [[5000, 2000, 'parziale']]);                         // pagato in parte
    $registro = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$registro) {
        if (str_contains($m->message, 'già salvati come saldati')) {
            $registro[] = $m->message;
        }
    });

    (require database_path('migrations/'.B52T_MIGRAZIONE.'.php'))->up();

    expect($registro)->toHaveCount(1);
    expect($registro[0])->toContain('con una quota ancora da pagare: 1.');
});

// Cambiato lo scenario (prima il misto «credito oltre il debito»): il misto scritto prima della .52 ora si riallinea (decisione 90.1); il
// conteggio non tocca il promemoria che conta, e quello è il saldato non misto con una quota pagata in parte.
it('M8b il conteggio non cambia niente: nessun promemoria è toccato, nemmeno lo stato', function () {
    $id = b52tPromemoriaConLeQuote('paid', [[5000, 2000, 'parziale']]);
    $prima = DB::table('eventi')->where('id', $id)->first();

    (require database_path('migrations/'.B52T_MIGRAZIONE.'.php'))->up();

    expect(DB::table('eventi')->where('id', $id)->first())->toEqual($prima);
});
