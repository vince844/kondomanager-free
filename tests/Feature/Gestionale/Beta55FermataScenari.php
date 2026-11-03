<?php

/**
 * 1.11.0-beta.55 — gli aiuti dei test delle fermate dopo «Scrivi» che leggono la catena della riga che chi esce vende (Code 345, 346, 347 e 348).
 *
 * NON contiene nessun it() o test — solo funzioni di supporto, tutte con il prefisso `fer55` (i nomi delle funzioni di Pest sono globali).
 *
 * Lo scenario è quello della fermata dopo «Scrivi» (`fds50Scenario`): un'unità da 1000 millesimi, Ines inquilina dal 2019, la fine di Ines il 1/7 con «Scrivi»
 * (la coppia è 36000 × 184/365 = 18147,95 → 18148), emesso fino al 30/9. Ogni passaggio passa dalle rotte vere (anteprima e registrazione) e un minuto dopo:
 * il successivo ha una data di creazione più tarda.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

/** La base: i titolari dati, Ines inquilina, il preventivo emesso fino al 30/6, la fine di Ines il 1/7 con «Scrivi», emesso fino al 30/9. Carlo e Dora sono nel condominio. */
function fer55Base($test, array $titolari): array
{
    $s = fds50Scenario($titolari);
    foreach (['Carlo', 'Dora'] as $nome) {
        isset($s['persone'][$nome]) || fclPersona($s, $nome);
    }
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($test, $s, '2026-09-30');
    $test->travel(1)->minutes();

    return $s;
}

/** L'unità mista: Ugo proprietario pieno della metà A e nudo proprietario della metà B, Zeno usufruttuario della metà B (la coppia è Ugo 9074 e Zeno 9074). */
function fer55Mista($test): array
{
    return fer55Base($test, [['Ugo', 'proprietario', 50], ['Ugo', 'nuda_proprietario', 50], ['Zeno', 'usufruttuario', 50]]);
}

/** Un passaggio dalle rotte vere (anteprima e registrazione), e un minuto dopo. Restituisce il passaggio registrato. */
function fer55Passo($test, array $s, array $dati): Subentro
{
    $r = fds50Registra($test, $s, $dati);
    $test->travel(1)->minutes();

    return $r['subentro'];
}

/**
 * La successione di `$defunto` (la riga con quel ruolo, per la quota data) ai suoi eredi (nome → quota ereditata), con l'arretrato agli eredi o a nome del
 * defunto; con `$conguaglio` la scelta della decisione 77 («Scrivi» o «Non scriverlo»), che si chiede solo con l'arretrato a nome del defunto.
 */
function fer55Successione(array $s, string $defunto, string $ruolo, string $il, float|int $quota, array $eredi, string $arretrato = Subentro::ARRETRATO_AGLI_EREDI, ?string $conguaglio = null): array
{
    return ['tipo' => 'successione', 'tipologia' => $ruolo, 'riga_uscente_id' => fds50Riga($s, $defunto, $ruolo), 'decorrenza' => $il, 'quota' => $quota,
        'eredi' => array_map(fn (string $nome, float|int $q) => ['anagrafica_id' => $s['persone'][$nome]->id, 'quota' => $q], array_keys($eredi), array_values($eredi)),
        'arretrato' => $arretrato, 'erede_di_riferimento' => $s['persone'][array_key_first($eredi)]->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 77', 'pertinenze' => []]
        + ($conguaglio !== null ? ['conguaglio' => $conguaglio] : []);
}

/** Le chiavi di una fermata che contano per il confronto: a carico di chi, la cifra, chi riceve, chi esce, la fermata di prima, il Wallet. */
function fer55Breve(array $fermate): array
{
    return array_map(fn (array $f) => [
        'persone' => $f['persone'] ?? [$f['persona']], 'cifra' => (int) $f['cifra'], 'riceve' => collect($f['parti'])->mapWithKeys(fn ($p) => [$p['nome'] => (int) $p['cifra']])->all(),
        'uscente' => $f['uscente'] ?? null, 'dopo_la_fermata' => $f['dopo_la_fermata'] ?? null, 'wallet' => (bool) ($f['wallet'] ?? false), 'strada' => $f['strada'] ?? null,
    ], $fermate);
}

/** Le fermate che il registro del passaggio conserva, nella forma breve. */
function fer55Registro(Subentro $passaggio): array
{
    return fer55Breve(fds50Fermate($passaggio->fresh()));
}

/** Le fermate che l'anteprima del passaggio propone, nella forma breve. */
function fer55Proposte(array $anteprima): array
{
    return fer55Breve($anteprima['rate']['fermate_dopo_scrivi'] ?? []);
}

/** La frase del pannello che nomina il passaggio: la prima stringa con il testo dato. */
function fer55Frase(array $anteprima, string $con): string
{
    return (string) collect(fds50Testi($anteprima))->first(fn (string $t) => str_contains($t, $con));
}

/** Il testo dello storico di una fermata: la voce del passaggio che comincia con il conguaglio della fine di Ines. */
function fer55Storico(array $s, Subentro $passaggio): string
{
    return (string) collect(fds50Testi(fds50Storico($s, $passaggio)))->first(fn (string $t) => str_starts_with($t, 'Il conguaglio scritto alla fine locazione di Ines'));
}

/** Le frasi del pannello, i motivi del cancello e lo storico del passaggio che dicono la cifra data: nessuna deve nominare chi non c'entra. */
function fer55FrasiDellaCifra(array $anteprima, array $storico, string $cifra): array
{
    return [...fds50Con($anteprima, $cifra), ...fds50Con($storico, $cifra)];
}

/** Le fermate che l'inizio di un conduttore riduce, nella forma breve: il passaggio, la cifra, quanto ne restava prima, quanto ne resta e la parte di chi riceve. */
function fer55Ridotte(array $anteprima): array
{
    return array_map(fn (array $f) => ['passaggio' => $f['passaggio'] ?? null, 'cifra' => (int) $f['cifra'], 'prima' => (int) $f['prima'], 'residuo' => (int) $f['residuo'],
        'parti' => collect($f['parti'])->mapWithKeys(fn ($p) => [$p['nome'] => (int) $p['cifra']])->all()], $anteprima['rate']['fermate_ridotte'] ?? []);
}

/** Le fermate di prima la cui parte risale con la fine di un conduttore, nella forma breve: il passaggio, da quanto a quanto, verso chi. */
function fer55Risalgono(array $anteprima): array
{
    return array_map(fn (array $f) => ['passaggio' => $f['passaggio'] ?? null, 'prima' => (int) $f['prima'], 'dopo' => (int) $f['dopo'], 'verso' => (array) $f['verso']],
        $anteprima['rate']['fermate_risalgono'] ?? []);
}

/** Nessuna frase del pannello ha un punto interrogativo al posto di un nome: «rimasta a carico di ?» è il segnaposto di una persona che il registro non sa. */
function fer55SenzaNomiMancanti(array $anteprima): void
{
    foreach (fds50Testi($anteprima) as $testo) {
        expect(preg_match('/(^|[\s(])\?([\s.,;)]|$)/u', $testo))->toBe(0, 'Un nome manca nella frase: ' . $testo);
    }
}
