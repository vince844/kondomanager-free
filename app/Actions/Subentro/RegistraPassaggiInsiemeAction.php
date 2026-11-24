<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Actions\Subentro;

use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Decisione 705 (1.12.0-beta.14): più persone che escono con lo stesso atto. Le righe spuntate in «Chi esce» diventano un passaggio per persona, nell'ordine
 * delle righe, con la stessa unità, data, chi entra, atto, pertinenze, cancello e scelte: **tutti o nessuno**, nella stessa transazione e sotto un gesto solo
 * del registro delle modifiche ({@see GestiDelRegistro::passaggiRegistratiInsieme()}).
 *
 * Sotto il cofano ogni passaggio è quello di oggi: {@see RegistraSubentroAction::execute()} blocca l'unità e la riga di chi esce, ricalcola il pannello sullo
 * stato lasciato dai passaggi di prima e scrive quei numeri (anteprima = scrittura); il suo gesto si conta nel lotto dell'insieme. Chi entra riceve la somma:
 * il primo apre la sua riga, gli altri sommano sulla riga nata quel giorno (`apriSommando`). Il legame è `subentri.insieme_id`, l'id del primo, su tutti.
 *
 * - **Il titolo** si allega al primo; gli altri ricevono lo stesso `documento_id`, e il nome del documento dice tutte le persone. Se un passaggio dopo il primo
 *   si ferma, il file si toglie dal disco (il passaggio che lo ha scritto lo toglie solo se si ferma lui).
 * - **L'impronta dell'ordinaria** la controlla il primo; gli altri no: le voci le ha già spostate il primo.
 * - **L'impronta dell'insieme** ({@see impronta()}): ricalcolata dai pannelli che ogni passaggio ha scritto e confrontata con quella del pannello del modulo
 *   **prima del commit**; diversa, si ferma e niente resta. È l'impronta dell'ordinaria allargata all'insieme.
 * - **Un rifiuto** di un passaggio qualsiasi annulla tutto, con il nome della persona davanti («La parte di Marta Acciaioli: …»); la riga di chi esce ha la
 *   sua chiave (`righe_uscenti.<i>`), il resto la chiave di oggi.
 *
 * Il compito del nuovo titolare non convocato e il riquadro verde li fa il controllore, dopo il commit, una volta per l'insieme.
 */
final class RegistraPassaggiInsiemeAction
{
    /**
     * @param array<string,mixed> $dati `AnteprimaPassaggioRequest::datiPassaggio()` con `membri` (i dati di ciascuna persona, nell'ordine delle righe) e
     *                                  `impronta_del_gruppo` (quella del pannello; nulla, nessun confronto)
     * @return array{passaggi: list<Subentro>, esiti: list<array<string,mixed>>, subentro: Subentro, ultimo: Subentro, documento: ?Documento, impronta: string, avvisi: list<string>}
     */
    public function execute(Condominio $condominio, Immobile $immobile, array $dati, User $utente): array
    {
        $membri = array_values((array) ($dati['membri'] ?? []));
        if (count($membri) < 2) {
            throw new InvalidArgumentException('I passaggi registrati insieme sono almeno due: con una persona sola si registra il passaggio di oggi.');
        }
        $nomi = self::nomiDiChiEsce($membri);
        $path = null;

        try {
            return RegistroModifiche::durante(GestiDelRegistro::passaggiRegistratiInsieme($condominio, $immobile), function () use ($condominio, $immobile, $dati, $utente, $membri, $nomi, &$path) {
                self::bloccaTutti($immobile, $membri);
                $esiti = [];
                $primo = null;
                $documento = null;
                foreach ($membri as $i => $membro) {
                    $membro['nomi_uscenti'] = $nomi;
                    // Coda 786: le stesse frasi della prova a secco.
                    $membro = array_merge($membro, self::datiDelGruppo($membri, $i, array_map(fn (array $e) => (int) $e['subentro']->id, $esiti)));
                    if ($primo !== null) {
                        $membro['insieme_id'] = (int) $primo->id;
                        $membro['allegato_titolo'] = null;
                        $membro['documento_id'] = $documento?->id;
                        $membro['ordinaria_impronta'] = null;
                    }
                    try {
                        // Un'azione nuova per ciascuno: il pannello di ognuno si calcola da capo, sullo stato lasciato dai precedenti.
                        $esito = app(RegistraSubentroAction::class)->execute($condominio, $immobile, $membro, $utente);
                    } catch (ValidationException $e) {
                        throw self::conIlNome($e, $nomi[$i] ?? null, $i, $immobile, $nomi);
                    }
                    if ($primo === null) {
                        $primo = $esito['subentro'];
                        $primo->forceFill(['insieme_id' => (int) $primo->id])->save();
                        $documento = $esito['documento'] ?? null;
                        $path = $documento?->path;
                    }
                    $esiti[] = $esito;
                }

                // L'impronta, prima del commit: se il pannello del modulo diceva altro, niente resta.
                $impronta = self::impronta(array_map(fn (array $e) => $e['anteprima'], $esiti));
                $atteso = $dati['impronta_del_gruppo'] ?? null;
                if (is_string($atteso) && $atteso !== '' && $atteso !== $impronta) {
                    $diversa = self::parteDiversa($atteso, $impronta);
                    throw ValidationException::withMessages(['passaggio' => sprintf('Mentre registravi è cambiato qualcosa%s: il pannello è stato ricalcolato, ricontrolla e conferma.',
                        $diversa !== null && isset($nomi[$diversa]) ? ' (la parte di ' . $nomi[$diversa] . ')' : '')]);
                }

                $passaggi = Subentro::whereIn('id', array_map(fn (array $e) => (int) $e['subentro']->id, $esiti))->orderBy('id')->get()->all();

                return [
                    'passaggi' => $passaggi,
                    'esiti' => $esiti,
                    'subentro' => $passaggi[0],
                    'ultimo' => $passaggi[count($passaggi) - 1],
                    'documento' => $documento,
                    'impronta' => $impronta,
                    'avvisi' => array_values(array_unique(array_merge(...array_map(fn (array $e) => (array) ($e['avvisi'] ?? []), $esiti)))),
                ];
            });
        } catch (\Throwable $e) {
            if ($path !== null && app(ArchivioDocumenti::class)->esiste($path)) {
                app(ArchivioDocumenti::class)->elimina($path);
            }
            throw $e;
        }
    }

    /**
     * L'impronta dell'insieme: una parte per persona, nell'ordine, ognuna un hash dei numeri che il suo passaggio scrive — le coppie per gestione, unità,
     * esercizio e persona; le bozze che passano (rata, unità, quota pura); le cifre delle fermate dopo «Scrivi», ridotte e risalite; l'impronta
     * dell'ordinaria; il numero dei motivi del cancello. Le parti sono unite da un punto, perché chi registra sappia **quale** è cambiata. Niente id delle
     * righe che i passaggi di prima scrivono: dopo una prova tolta, su MySQL, i contatori non tornano indietro.
     *
     * @param list<array<string,mixed>> $anteprime i pannelli di ciascuno (`AnteprimaPassaggio::calcola()`), nell'ordine
     */
    public static function impronta(array $anteprime): string
    {
        return implode('.', array_map(fn (array $a) => substr(hash('sha256', (string) json_encode(self::numeriDellaParte($a))), 0, 12), $anteprime));
    }

    /** @return array<string, mixed> */
    private static function numeriDellaParte(array $a): array
    {
        $conguaglio = $a['rate']['conguaglio'] ?? null;
        $entrante = $conguaglio['anagrafica_entrante_id'] ?? null;
        $numero = fn ($v) => is_numeric($v) ? (int) $v : null;
        $fermate = fn (string $chiave) => array_map(fn ($f) => is_array($f) ? [
            $numero($f['immobile_id'] ?? null), $numero($f['gestione_id'] ?? null), $numero($f['esercizio_id'] ?? null), $numero($f['persona_id'] ?? null),
            $numero($f['cifra'] ?? null), $numero($f['residuo'] ?? null), $numero($f['prima'] ?? null), $numero($f['dopo'] ?? null),
        ] : null, array_values((array) ($a['rate'][$chiave] ?? [])));

        return [
            'coppie' => array_map(fn (array $c) => [(int) ($c['gestione_id'] ?? 0), (int) ($c['immobile_id'] ?? 0), $numero($c['esercizio_id'] ?? null),
                (int) ($c['anagrafica_entrante_id'] ?? $entrante ?? 0), (int) ($c['importo'] ?? 0)], array_values((array) ($conguaglio['coppie'] ?? []))),
            'bozze' => array_map(fn (array $b) => [(int) ($b['rata_id'] ?? 0), (int) ($b['immobile_id'] ?? 0), (int) ($b['quota_pura'] ?? 0), (int) ($b['pregresso'] ?? 0)],
                array_values((array) ($conguaglio['bozze_riassegnate'] ?? []))),
            'fermate' => $fermate('fermate_dopo_scrivi'),
            'ridotte' => $fermate('fermate_ridotte'),
            'risalgono' => $fermate('fermate_risalgono'),
            'ordinaria' => $a['ordinaria']['impronta'] ?? null,
            'motivi' => count((array) ($a['cancello']['motivi'] ?? [])),
        ];
    }

    /** La prima parte diversa fra due impronte dell'insieme (indice della persona), o null se non si sa dire. */
    private static function parteDiversa(string $atteso, string $impronta): ?int
    {
        $a = explode('.', $atteso);
        $b = explode('.', $impronta);
        if (count($a) !== count($b)) {
            return null;
        }
        foreach ($b as $i => $parte) {
            if (($a[$i] ?? null) !== $parte) {
                return $i;
            }
        }

        return null;
    }

    /**
     * I lock di tutti i passaggi, prima di ogni lettura semplice (il dubbio dello scettico della tappa 7; come `AnnullaPassaggioAction::blocca` per
     * l'annullamento): l'unità e le pertinenze spuntate di ogni persona, nello stesso ordine di `RegistraSubentroAction` (le unità, poi le righe), e le loro
     * righe di titolarità, quelle di chi esce comprese. Su MySQL l'istantanea nasce alla prima lettura senza lock: preso solo dal suo passaggio, il lock del
     * box della sola Marta arriverebbe dopo le letture della parte di Cecilia, e Marta leggerebbe il box com'era prima di ciò che un'altra scheda vi ha scritto
     * nel frattempo. Ogni passaggio li riprende per sé, già suoi.
     *
     * @param list<array<string,mixed>> $membri
     */
    private static function bloccaTutti(Immobile $immobile, array $membri): void
    {
        $unita = collect([(int) $immobile->id])->merge(collect($membri)->flatMap(fn (array $m) => array_map('intval', (array) ($m['pertinenze'] ?? []))))
            ->unique()->sort()->values()->all();
        Immobile::bloccaPerScrivere($unita);
        DB::table('anagrafica_immobile')->whereIn('immobile_id', $unita)->lockForUpdate()->pluck('id');
    }

    /**
     * Coda 786 (decisione 705): ciò che il pannello di una persona deve sapere del gruppo, per non trattare i gemelli come «gli altri». `con_chi_esce` sono le
     * anagrafiche delle altre persone che escono con lo stesso atto (le loro quote stanno nella loro parte), `passaggi_gemelli` gli id dei passaggi già scritti
     * prima di questo (nella prova a secco o nella registrazione: il piano che hanno preso non è «preso da un passaggio di prima»).
     *
     * @param list<array<string,mixed>> $membri
     * @param list<int> $scritti gli id dei passaggi del gruppo già scritti
     * @return array{con_chi_esce: list<int>, passaggi_gemelli: list<int>}
     */
    public static function datiDelGruppo(array $membri, int $i, array $scritti): array
    {
        $altre = [];
        foreach ($membri as $k => $m) {
            if ($k !== $i && ($m['riga_uscente']?->anagrafica_id ?? null) !== null) {
                $altre[] = (int) $m['riga_uscente']->anagrafica_id;
            }
        }

        return ['con_chi_esce' => $altre, 'passaggi_gemelli' => array_values($scritti)];
    }

    /** I nomi di chi esce, nell'ordine delle righe. @param list<array<string,mixed>> $membri @return list<string> */
    public static function nomiDiChiEsce(array $membri): array
    {
        return array_map(fn (array $m) => (string) ($m['riga_uscente']?->anagrafica?->nome ?? 'chi esce'), $membri);
    }

    /**
     * Il rifiuto di un passaggio, con il nome della persona davanti: «La parte di Marta Acciaioli: questo periodo si è chiuso …». La riga di chi esce va sulla
     * sua riga del modulo (`righe_uscenti.<i>`); il resto sulla chiave di oggi. La frase che comincia con un nome proprio (l'unità, una persona) lo tiene.
     *
     * @param list<string> $nomi
     */
    public static function conIlNome(ValidationException $e, ?string $nome, int $i, Immobile $immobile, array $nomi): ValidationException
    {
        $propri = array_values(array_filter([$immobile->nome, $immobile->etichetta, ...$nomi, ...$immobile->pertinenze()->pluck('nome')->all()]));
        $testa = 'La parte di ' . ($nome ?? 'chi esce') . ': ';
        $messaggi = [];
        foreach ($e->errors() as $chiave => $frasi) {
            $chiave = $chiave === 'riga_uscente_id' ? 'righe_uscenti.' . $i : $chiave;
            foreach ((array) $frasi as $frase) {
                $frase = (string) $frase;
                $proprio = collect($propri)->contains(fn (string $p) => $p !== '' && str_starts_with($frase, $p));
                $messaggi[$chiave][] = $testa . ($proprio ? $frase : mb_lcfirst($frase));
            }
        }

        return ValidationException::withMessages($messaggi);
    }
}
