<?php

use App\Http\Requests\Gestionale\Movimenti\StoreIncassoRateRequest;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * beta.49 — Chi è «condòmino di questo stabile», per la validazione del pagante.
 *
 * ## Il difetto: due criteri per la stessa domanda
 *
 * La schermata di incasso **propone** i paganti con un criterio (`IncassoRateController:125`,
 * `whereHas('immobili')` — possiede un'unità qui) e la Request **li valida** con un altro
 * (`anagrafica_condominio`, il pivot). Le due divergono, quindi il modulo offre una persona che
 * il server poi rifiuta, con un messaggio che sembra assurdo a chi ha scelto un nome dall'elenco
 * che il gestionale stesso gli ha dato: «l'elemento pagante id selezionato non è valido».
 *
 * È la forma della beta.44 — la stessa domanda con due risposte in due posti.
 *
 * ## Perché è un blocco di rilascio della 1.10
 *
 * Sull'**importato** le due divergono sempre, perché l'importatore popola le unità e non il
 * pivot. Misurato l'11/08/2026 su «Le Terrazze», entrato con la beta.47: 16 anagrafiche con
 * unità, **0** nel pivot. Un amministratore importa il suo stabile e non può registrare un solo
 * incasso.
 *
 * Non è solo dell'importatore: `CreateImmobileAnagraficaRequest:38` valida `anagrafica_id` senza
 * filtro sul condominio, quindi anche assegnando a mano un'anagrafica a un'unità si ottiene lo
 * stesso stato.
 *
 * ## La proprietà di sicurezza che NON deve rompersi
 *
 * La regola non esiste per capriccio: impedisce di incassare intestando la ricevuta a
 * un'anagrafica di **un altro condominio**. Allargare il criterio non deve aprire quella porta —
 * è il motivo per cui il terzo test di questo file conta quanto i primi due.
 *
 * ## Cosa questo file NON copre
 *
 * - La popolazione del pivot in `LivelloSoggetti` e in `ImmobileAnagraficaController`: sono la
 *   seconda metà della correzione e hanno i loro test.
 */
function condominioConUnita(string $nome = 'Condominio Prova'): object
{
    $condominio = Condominio::factory()->create(['nome' => $nome]);

    $immobile = Immobile::create([
        'condominio_id'   => $condominio->id,
        'codice_immobile' => 'C-' . $condominio->id,
        'nome'            => 'Int 1',
        'descrizione'     => 'Unità di prova',
        'interno'         => '1',
    ]);

    return (object) compact('condominio', 'immobile');
}

function conUnita(Anagrafica $a, object $s): void
{
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $a->id,
        'immobile_id'   => $s->immobile->id,
        'tipologia'     => 'proprietario',
        'quota'         => 100,
        'attivo'        => true,
        'data_inizio'   => now(),
    ]);
}

function paganteAmmesso(int $anagraficaId, Condominio $condominio): bool
{
    // Si interroga la regola vera della Request, non una copia: se la regola cambia e questo
    // file non se ne accorge, il test non serve a niente.
    $request = new StoreIncassoRateRequest();
    $request->setRouteResolver(fn () => new class($condominio) {
        public function __construct(private Condominio $c) {}
        public function parameter(string $n) { return $this->c; }
    });

    $regole = $request->rules();

    return Validator::make(['pagante_id' => $anagraficaId], ['pagante_id' => $regole['pagante_id']])->passes();
}

test('chi possiede un\'unità nello stabile può pagare, anche senza riga nel pivot', function () {
    // È lo stato di ogni condominio importato: unità sì, pivot no.
    $s = condominioConUnita();
    $anagrafica = Anagrafica::factory()->create();
    conUnita($anagrafica, $s);

    expect(paganteAmmesso($anagrafica->id, $s->condominio))->toBeTrue();
});

test('chi è nel pivot senza possedere unità può ancora pagare', function () {
    // Il caso che il pivot copre e le unità no — a database esiste davvero: una persona
    // collegata al condominio, con utente attivo, che non possiede niente. Allargare il
    // criterio non deve toglierle il diritto che aveva.
    $s = condominioConUnita();
    $anagrafica = Anagrafica::factory()->create();
    $anagrafica->condomini()->attach($s->condominio->id);

    expect(paganteAmmesso($anagrafica->id, $s->condominio))->toBeTrue();
});

test('un\'anagrafica di un altro condominio resta rifiutata', function () {
    // La proprietà di sicurezza. Senza questo test, allargare il criterio è indistinguibile
    // dal toglierlo.
    $mio   = condominioConUnita('Il mio');
    $altro = condominioConUnita('Un altro');

    $estraneo = Anagrafica::factory()->create();
    conUnita($estraneo, $altro);
    $estraneo->condomini()->attach($altro->condominio->id);

    expect(paganteAmmesso($estraneo->id, $mio->condominio))->toBeFalse();
});

test('un\'anagrafica che non appartiene a nessun condominio resta rifiutata', function () {
    $s = condominioConUnita();
    $orfana = Anagrafica::factory()->create();

    expect(paganteAmmesso($orfana->id, $s->condominio))->toBeFalse();
});
