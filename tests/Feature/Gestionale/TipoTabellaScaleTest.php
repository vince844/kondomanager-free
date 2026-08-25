<?php

/**
 * Il tipo di tabella `scale` esiste nel database e non nelle due tendine.
 *
 * ## Il difetto, misurato il 17/08/2026 (coda ㊱)
 *
 * `tabelle.tipo` è un enum di otto valori e **`scale` è fra questi** dal primo giorno. Lo prevede il
 * tipo TypeScript, la lista lo mostra con la sua icona, e soprattutto **l'importatore lo produce**:
 * `LivelloTabelle::TIPI` mappa «scala» e «scale» sul valore `scale` e scrive con `Tabella::create()`,
 * che non passa da nessuna validazione.
 *
 * **Fino alla beta.57** le due tendine e le due regole `in:` ne conoscevano solo sette, senza `scale`.
 * Il risultato era una tabella che il programma **sapeva creare e non sapeva più salvare**: si importava
 * uno stabile da Danea con la tabella «SCALE A», la si apriva in modifica, e qualunque salvataggio
 * moriva in validazione — anche senza toccare il tipo, perché la regola è `required` e vale su ogni
 * `update`. Il caso non era teorico: nel database di sviluppo esisteva già una tabella in quello stato,
 * arrivata da un'importazione reale.
 *
 * ## Perché il test viene prima
 *
 * Perché su queste rotte non c'era **niente**: `grep` di `tabelle.store|tabelle.update|TabellaController`
 * in `tests/` restituiva zero. Le due tendine, le due regole `in:` e la rotta di modifica erano
 * scoperte per intero, ed è il motivo per cui il disallineamento è potuto restare lì dal primo giorno.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre le tendine (sono Vue e non hanno test): quelle si guardano a video. Non copre gli altri
 * sette tipi, che non sono in discussione, né il tipo `manuale`, che non esiste e appartiene a
 * Iniziativa A in 1.11.
 */

use App\Models\Condominio;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->condominio = Condominio::factory()->create();
});

/** Una tabella come la scrive l'importatore: `Tabella::create()`, nessuna validazione di mezzo. */
function tabellaImportata(Condominio $condominio, User $autore, string $tipo = 'scale'): Tabella
{
    return Tabella::create([
        'condominio_id'   => $condominio->id,
        'nome'            => 'SCALE A',
        'tipo'            => $tipo,
        'quota'           => 'millesimi',
        'numero_decimali' => 2,
        'created_by'      => $autore->id,
        'updated_by'      => $autore->id,
    ]);
}

it('l\'importatore produce davvero «scale»: è l\'antecedente di tutto il resto', function () {
    // ⚠️ Reperto della Fase 1-bis. Gli altri test provano il **conseguente** — le rotte accettano `scale` —
    // ma niente proteggeva il ramo che quel valore lo genera: `LivelloTabelle::TIPI` mappa «scala» e
    // «scale», due voci che sembrano un doppione dentro una mappa il cui ordine è dichiarato
    // significativo («si ferma alla prima»). Chi la snellisce non trova nessun test che si accende, e il
    // difetto che questa beta chiude tornerebbe dall'altro capo: nessuno produrrebbe più `scale`, e la
    // voce in tendina resterebbe a descrivere un caso che non arriva più.
    $mappa = (new ReflectionClass(\App\Services\Import\Livelli\LivelloTabelle::class))
        ->getConstant('TIPI');

    expect($mappa)->toHaveKey('scala')
        ->and($mappa)->toHaveKey('scale')
        ->and($mappa['scala'])->toBe('scale')
        ->and($mappa['scale'])->toBe('scale');
});

it('il database accetta «scale»: è l\'enum a dirlo, non un\'opinione', function () {
    // Controprova che tiene in piedi tutto il resto: se un giorno l'enum perdesse `scale`, il difetto
    // non sarebbe più un disallineamento fra strati ma una scelta, e questo test lo direbbe per primo.
    $tabella = tabellaImportata($this->condominio, $this->user);

    expect(DB::table('tabelle')->where('id', $tabella->id)->value('tipo'))->toBe('scale');
});

it('una tabella «scale» importata si può risalvare senza cambiarle il tipo', function () {
    $tabella = tabellaImportata($this->condominio, $this->user);

    $this->actingAs($this->user)
        ->put(route('admin.gestionale.tabelle.update', [
            'condominio' => $this->condominio->id,
            'tabella'    => $tabella->id,
        ]), [
            'nome'            => 'SCALE A',
            'tipo'            => 'scale',
            'quota'           => 'millesimi',
            'numero_decimali' => 2,
            'updated_by'      => $this->user->id,
            'descrizione'     => 'Aggiornata la descrizione, il tipo non si tocca.',
        ])
        ->assertSessionHasNoErrors();

    expect($tabella->fresh()->tipo)->toBe('scale');
});

it('si può creare una tabella di tipo «scale» dall\'interfaccia, come l\'importatore la crea da sé', function () {
    // Se l'importatore può produrre quel tipo, l'amministratore deve poterlo scegliere: altrimenti il
    // programma sa fare una cosa che non concede di fare a mano, e chi la riceve non la sa rifare.
    $this->actingAs($this->user)
        ->post(route('admin.gestionale.tabelle.store', ['condominio' => $this->condominio->id]), [
            'nome'            => 'SCALE B',
            'tipologia'       => 'scale',
            'quota'           => 'millesimi',
            'numero_decimali' => 2,
            'created_by'      => $this->user->id,
            'condominio_id'   => $this->condominio->id,
        ])
        ->assertSessionHasNoErrors();

    expect(Tabella::where('condominio_id', $this->condominio->id)->where('tipo', 'scale')->count())->toBe(1);
});
