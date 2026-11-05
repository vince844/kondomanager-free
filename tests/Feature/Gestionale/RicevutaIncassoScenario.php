<?php

/**
 * Lo scenario condiviso dai test della ricevuta dell'incasso. Solo funzioni di supporto, nessun test.
 *
 * Un condominio con un esercizio, una gestione ordinaria, la banca, i conti dei crediti e delle passate gestioni, e un piano di quattro rate
 * emesse. Anna Bruni ha l'interno 4 e le sue quote; Carlo Bruni (il figlio, associato al condominio senza unità) può versare per lei; Ugo Neri
 * (interno 7) non ha email. Le cifre sono in centesimi e sono scelte perché i conti nei test si facciano a mano.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use App\Notifications\Gestionale\RicevutaIncassoNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function scenarioRicevutaIncasso(string $nome = 'Condominio Via dei Pini'): object
{
    $condominio = Condominio::create([
        'nome' => $nome, 'uuid' => (string) Str::uuid(),
        'codice_fiscale' => $nome === 'Condominio Via dei Pini' ? '90012345678' : '9' . str_pad((string) (abs(crc32($nome)) % 10000000000), 10, '0', STR_PAD_LEFT),
        'indirizzo' => 'Via dei Pini 12', 'comune' => 'Torino', 'cap' => '10100', 'provincia' => 'TO',
    ]);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $conto = fn (string $codice, string $nomeConto, string $tipo, string $ruolo, string $categoria) => ContoContabile::create([
        'condominio_id' => $condominio->id, 'codice' => $codice, 'nome' => $nomeConto,
        'tipo' => $tipo, 'ruolo' => $ruolo, 'categoria' => $categoria,
    ]);
    $contoBanca = $conto('10.10', 'Banca', 'attivo', 'banca', 'liquidita');
    $contoCassa = $conto('10.11', 'Cassa contanti', 'attivo', 'cassa', 'liquidita');
    $conto('10.20', 'Crediti vs Condomini', 'attivo', 'crediti_condomini', 'crediti');
    $conto('30.10', 'Passate gestioni', 'passivo', 'passate_gestioni', 'debiti');

    $banca = Cassa::create([
        'condominio_id' => $condominio->id, 'conto_contabile_id' => $contoBanca->id,
        'nome' => 'Conto BPER', 'tipo' => 'banca', 'attiva' => true,
    ]);
    $contanti = Cassa::create([
        'condominio_id' => $condominio->id, 'conto_contabile_id' => $contoCassa->id,
        'nome' => 'Cassa dello studio', 'tipo' => 'contanti', 'attiva' => true,
    ]);

    $persona = fn (string $nomePersona, ?string $email, ?string $email2 = null, string $indirizzo = 'Via dei Pini 12, 10100 Torino') => Anagrafica::create([
        'nome' => $nomePersona, 'email' => $email, 'email_secondaria' => $email2, 'indirizzo' => $indirizzo,
        'codice_fiscale' => strtoupper(Str::random(16)),
    ]);
    $anna = $persona('Anna Bruni', 'anna.bruni.' . $condominio->id . '@prova.it', 'anna.casa.' . $condominio->id . '@prova.it');
    $carlo = $persona('Carlo Bruni', 'carlo.bruni.' . $condominio->id . '@prova.it', null, 'Corso Francia 3, 10138 Torino');
    $ugo = $persona('Ugo Neri', null);

    $unita = fn (string $interno) => Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Appartamento ' . $interno, 'descrizione' => 'Appartamento',
        'interno' => $interno,
    ]);
    $int4 = $unita('4');
    $int7 = $unita('7');
    $int4->anagrafiche()->attach($anna->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $int7->anagrafiche()->attach($ugo->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    foreach ([$anna, $carlo, $ugo] as $p) {
        $p->condomini()->attach($condominio->id);
    }

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Preventivo 2026', 'numero_rate' => 4,
    ]);
    $rate = [];
    foreach ([1 => '2026-01-31', 2 => '2026-03-31', 3 => '2026-05-31', 4 => '2026-07-31'] as $n => $scadenza) {
        $rate[$n] = Rata::create([
            'piano_rate_id' => $piano->id, 'numero_rata' => $n, 'data_scadenza' => $scadenza,
            'importo_totale' => 30000, 'stato' => 'emessa',
        ]);
    }
    $quota = fn (Anagrafica $p, Immobile $i, int $n, int $importo) => RataQuote::create([
        'rata_id' => $rate[$n]->id, 'anagrafica_id' => $p->id, 'immobile_id' => $i->id,
        'importo' => $importo, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $rate[$n]->data_scadenza,
    ]);

    // Anna: € 150,00 la prima rata, € 120,50 la seconda, € 150,00 la terza e la quarta. Ugo: € 60,00 a rata.
    $anna1 = $quota($anna, $int4, 1, 15000);
    $anna2 = $quota($anna, $int4, 2, 12050);
    $anna3 = $quota($anna, $int4, 3, 15000);
    $anna4 = $quota($anna, $int4, 4, 15000);
    $ugo1 = $quota($ugo, $int7, 1, 6000);
    $ugo2 = $quota($ugo, $int7, 2, 6000);

    return (object) compact(
        'condominio', 'esercizio', 'gestione', 'banca', 'contanti', 'anna', 'carlo', 'ugo', 'int4', 'int7', 'piano', 'rate',
        'anna1', 'anna2', 'anna3', 'anna4', 'ugo1', 'ugo2'
    );
}

/**
 * Registra un incasso con l'azione del prodotto. `$righe` è una lista di [quota, euro]: un importo negativo usa il credito di quella quota.
 *
 * @param array<int, array{0: RataQuote, 1: float}> $righe
 */
function incassaPerRicevuta(object $s, Anagrafica $pagante, array $righe, string $data, float $versato, array $altro = []): ScritturaContabile
{
    $dettaglio = array_map(fn ($r) => ['rata_id' => $r[0]->id, 'importo' => $r[1]], $righe);
    $somma = array_sum(array_map(fn ($r) => $r[1], $righe));

    return app(StoreIncassoRateAction::class)->execute(array_merge([
        'pagante_id' => $pagante->id,
        'cassa_id' => $s->banca->id,
        'gestione_id' => $s->gestione->id,
        'data_pagamento' => $data,
        'importo_totale' => $versato,
        'descrizione' => 'Bonifico',
        'eccedenza' => round($versato - $somma, 2),
        'dettaglio_pagamenti' => $dettaglio,
    ], $altro), $s->condominio, $s->esercizio);
}

/** Una rata da pagare di Carlo, su una sua unità (il box 9): dove va la parte in più di quello che versa per Anna. */
function quotaDaPagareDiCarlo(object $s, int $rata = 3, int $importo = 10000): RataQuote
{
    $box = Immobile::create(['condominio_id' => $s->condominio->id, 'nome' => 'Box 9', 'descrizione' => 'Box', 'interno' => '9']);
    $box->anagrafiche()->attach($s->carlo->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);

    return RataQuote::create([
        'rata_id' => $s->rate[$rata]->id, 'anagrafica_id' => $s->carlo->id, 'immobile_id' => $box->id,
        'importo' => $importo, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $s->rate[$rata]->data_scadenza,
    ]);
}

/** Le cifre di una riga delle quote, in centesimi: importo, già versato prima, imputato, residuo dopo. */
function cifreDellaQuota(array $q): array
{
    return [$q['importo'], $q['prima'], $q['imputato'], $q['dopo']];
}

/**
 * I testi di ogni pagina di un PDF scritto senza compressione e con i caratteri di base, con la posizione di ognuno in millimetri dal bordo
 * sinistro e dal bordo alto di un A4: [pagina => [[testo, x, y], …]]. La y è quella della linea di base del testo.
 *
 * @return array<int, array<int, array{0: string, 1: float, 2: float}>>
 */
function testiDellePagineDelPdf(string $pdf): array
{
    preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $flussi);
    $mm = fn (string $punti) => round((float) $punti / 72 * 25.4, 1);
    $pagine = [];
    foreach ($flussi[1] as $flusso) {
        preg_match_all('/BT ([\d.]+) ([\d.]+) Td\s+\((.*?)\) Tj/s', $flusso, $t, PREG_SET_ORDER);
        if ($t !== []) {
            $pagine[] = array_map(fn ($m) => [$m[3], $mm($m[1]), round(297 - $mm($m[2]), 1)], $t);
        }
    }

    return $pagine;
}

/** Dove sta un testo in una pagina letta da `testiDellePagineDelPdf()`: tutte le volte che c'è, come [x, y] in millimetri. */
function doveStaNellaPagina(array $pagina, string $testo): array
{
    return array_values(array_map(fn ($t) => [$t[1], $t[2]], array_filter($pagina, fn ($t) => str_starts_with($t[0], $testo))));
}

function amministratoreRicevute(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user;
}

function trasportoCheRifiutaLeRicevute(): void
{
    Mail::extend('rotto_ricevute', fn () => new class extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Il server ha rifiutato l\'autenticazione');
        }

        public function __toString(): string
        {
            return 'rotto_ricevute';
        }
    });
    config(['mail.mailers.rotto_ricevute' => ['transport' => 'rotto_ricevute'], 'mail.default' => 'rotto_ricevute']);
}

/** Le notifiche della ricevuta partite verso un indirizzo, con la coda finta: vanno tutte a un indirizzo esplicito, non a una persona. */
function notificheRicevutaA(string $indirizzo): \Illuminate\Support\Collection
{
    return collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])
        ->flatMap(fn ($perChiave) => $perChiave[RicevutaIncassoNotification::class] ?? [])
        ->filter(fn ($voce) => ($voce['notifiable']->routes['mail'] ?? null) === $indirizzo)
        ->map(fn ($voce) => $voce['notification'])
        ->values();
}

/** N persone, ognuna con la primaria e la secondaria, un'unità, una quota di € 10,00 sulla rata 1 e il suo incasso: due messaggi a persona. */
function incassiDiPiuPersoneConDueIndirizzi(object $s, int $quante): array
{
    $ids = [];
    for ($i = 1; $i <= $quante; $i++) {
        $p = \App\Models\Anagrafica::create(['nome' => sprintf('Persona %02d', $i), 'email' => "p{$i}@prova.it", 'email_secondaria' => "p{$i}.casa@prova.it",
            'indirizzo' => 'Via dei Pini 12', 'codice_fiscale' => strtoupper(\Illuminate\Support\Str::random(16))]);
        $unita = \App\Models\Immobile::create(['condominio_id' => $s->condominio->id, 'nome' => "Appartamento {$i}0", 'descrizione' => 'Appartamento', 'interno' => "{$i}0"]);
        $unita->anagrafiche()->attach($p->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
        $quota = \App\Models\Gestionale\RataQuote::create(['rata_id' => $s->rate[1]->id, 'anagrafica_id' => $p->id, 'immobile_id' => $unita->id,
            'importo' => 1000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $s->rate[1]->data_scadenza]);
        $ids[] = incassaPerRicevuta($s, $p, [[$quota, 10.00]], '2026-02-01', 10.00)->id;
    }

    return $ids;
}
