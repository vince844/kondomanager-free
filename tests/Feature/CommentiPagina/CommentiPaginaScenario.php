<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Lo scenario dei test della pagina «Commenti» del pannello (Coda 657, decisione 442)
 *
 * Quello dei commenti alle comunicazioni (`CommentiScenario.php`), più qualche funzione per leggere la pagina e per contare
 * le notifiche per destinatario.
 */

use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';

/** Le props della pagina, come array. */
function cpProps($test, User $user, array $query = []): array
{
    $risposta = $test->actingAs($user)->get(route('admin.commenti.index', $query))->assertOk();

    return json_decode(json_encode($risposta->viewData('page')['props']), true);
}

/** Le righe della pagina. */
function cpRighe($test, User $user, array $query = []): array
{
    return cpProps($test, $user, $query)['commenti']['data'];
}

/** Gli id dei commenti della pagina, in ordine. */
function cpIds($test, User $user, array $query = []): array
{
    return array_column(cpRighe($test, $user, $query), 'id');
}

/** Un utente dello studio con un ruolo su misura. */
function cpStudioConRuolo(string $nome, array $permessi): User
{
    $ruolo = ccmRuolo('Ruolo di ' . $nome, $permessi);

    return ccmStudioSenzaAnagrafica($nome, $ruolo);
}

/**
 * Lo scenario: una segnalazione in Aurora, una comunicazione a due palazzi e una comunicazione a due persone, ognuna con
 * commenti nei tre stati e uno eliminato.
 *
 * @return array{s: Segnalazione, c: Comunicazione, p: Comunicazione, id: array<string, int>}
 */
function cpScenario($test): array
{
    $s = ccmSegnalazione($test->aurora, $test->mario, ['subject' => 'Il cancello non chiude']);
    $c = ccmComunicazione([$test->aurora, $test->borea], $test->admin, ['subject' => 'Lavori al tetto', 'commenti_moderati' => true]);
    $p = ccmComunicazione([$test->aurora, $test->borea], $test->admin, ['subject' => 'Avviso personale'], [$test->mario, $test->lucia]);

    $id = [];
    $id['s_pub'] = ccmCommentoSalvato($s, $test->mario, 'Il cancello è ancora aperto', $test->aurora);
    $id['s_att'] = ccmCommentoSalvato($s, $test->mario, 'Si può sapere quando passano?', $test->aurora, null, 'in_attesa');
    $id['s_nas'] = ccmCommentoSalvato($s, $test->paolo, 'Un commento sgradevole', $test->aurora, null, 'nascosto');
    $id['s_eli'] = ccmCommentoSalvato($s, $test->paolo, 'Un commento eliminato', $test->aurora);
    Commento::query()->whereKey($id['s_eli'])->delete();

    $id['c_aur'] = ccmCommentoSalvato($c, $test->mario, 'Per Aurora: quando iniziano?', $test->aurora);
    $id['c_aur_att'] = ccmCommentoSalvato($c, $test->mario, 'Per Aurora: e la polvere?', $test->aurora, null, 'in_attesa');
    $id['c_bor'] = ccmCommentoSalvato($c, $test->lucia, 'Per Borea: va bene lunedì', $test->borea);
    $id['c_bor_att'] = ccmCommentoSalvato($c, $test->lucia, 'Per Borea: serve il permesso?', $test->borea, null, 'in_attesa');

    $id['p_mario'] = ccmCommentoSalvato($p, $test->mario, 'Mario allo studio', $test->aurora, $test->mario);
    $id['p_lucia'] = ccmCommentoSalvato($p, $test->lucia, 'Lucia allo studio', $test->borea, $test->lucia, 'in_attesa');

    return ['s' => $s, 'c' => $c, 'p' => $p, 'id' => $id];
}

/** Le notifiche partite, per destinatario e classe: `utente|Classe => quante`. */
function cpNotifiche(): array
{
    $conteggi = [];
    foreach (User::all() as $u) {
        foreach (Notification::sentNotifications()[User::class][$u->id] ?? [] as $classe => $invii) {
            $conteggi[$u->id . '|' . class_basename($classe)] = count($invii);
        }
    }
    ksort($conteggi);

    return $conteggi;
}
