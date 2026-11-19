<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

use App\Models\Condominio;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * I suggerimenti per il recupero dei crediti che chiedono le regole del recupero del condominio: chi è oltre i giorni di tolleranza senza una lettera,
 * per chi ci sono interessi da proporre, e per chi si fermano (un anno senza il tasso legale). Sono suggerimenti, mai azioni: dicono quante persone e quali, e la porta per guardarle; non scrivono niente e non
 * dicono che l'amministratore deve agire.
 *
 * La forma di ogni suggerimento, la stessa per chi li mostra: `chiave` (che cosa), `frase` (detta a chi legge), `persone` (gli identificativi, per
 * sceglierle nell'elenco), `porta` (`seleziona`: sceglile e guarda le lettere; `proponi_interessi`: apri la proposta per loro).
 */
final class SuggerimentiDelRecupero
{
    public function __construct(private readonly PropostaInteressi $interessi, private readonly RegistroLettere $lettere) {}

    /**
     * I suggerimenti per le righe dell'elenco «Rate scadute» di oggi.
     *
     * @param Collection<int, array<string, mixed>> $righe
     * @return list<array{chiave: string, frase: string, persone: list<int>, porta: string}>
     */
    public function perElenco(Condominio $condominio, Collection $righe, string $oggi, ?DebitoEreditato $ereditato = null): array
    {
        if ($righe->isEmpty()) {
            return [];
        }
        $regole = (new RegoleDelRecupero())->delCondominio((int) $condominio->id);
        $esito = [];

        $tolleranza = RegoleDelRecupero::tolleranza($regole, $oggi);
        if ($tolleranza !== null) {
            $lettere = $this->lettere->ultimePerPersone((int) $condominio->id, $righe->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all(), $oggi);
            $oltre = [];
            foreach ($righe as $r) {
                // La rata più vecchia oltre la tolleranza, fuori da un accordo di rientro che la tiene fuori dalle lettere.
                $scadenze = self::scadenzeOltreLaTolleranza($r['rate'] ?? [], $tolleranza);
                if ($scadenze === []) {
                    continue;
                }
                $conta = $lettere[(int) $r['anagrafica_id']]['conta'] ?? null;
                $giorno = $conta === null ? null : substr((string) ($conta['data_dei_filtri'] ?? $conta['data']), 0, 10);
                if ($giorno === null || $giorno <= (string) $scadenze[0]) {
                    $oltre[] = (int) $r['anagrafica_id'];
                }
            }
            if ($oltre !== []) {
                $esito[] = ['chiave' => 'oltre_tolleranza', 'frase' => self::fraseOltreTolleranza(count($oltre), $tolleranza), 'persone' => $oltre, 'porta' => 'seleziona'];
            }
        }

        // `$ereditato`: la lettura del debito ereditato dell'elenco, che la pagina fa una volta (P1 della lente P della beta.12).
        $interessi = $this->interessi->perSuggerimenti($condominio, $oggi, $righe->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all(), $ereditato);
        if ($interessi['con'] !== []) {
            $n = count($interessi['con']);
            $esito[] = ['chiave' => 'interessi_da_proporre', 'frase' => 'Interessi di mora da proporre per ' . ($n === 1 ? '1 persona' : $n . ' persone') . '.',
                'persone' => $interessi['con'], 'porta' => 'proponi_interessi'];
        }
        // Chi ha solo righe ferme: il programma non tace, dice perché e fin dove può proporre.
        if ($interessi['fermi'] !== []) {
            $esito[] = ['chiave' => 'interessi_fermi', 'frase' => self::fraseFermi(count($interessi['fermi']), (string) $interessi['perche']),
                'persone' => $interessi['fermi'], 'porta' => 'proponi_interessi'];
        }

        return $esito;
    }

    /**
     * Le scadenze delle rate oltre i giorni di tolleranza del condominio, in ordine, la più vecchia prima: le rate scadute da più giorni della tolleranza (o
     * senza un numero di giorni, il pregresso d'anzianità non nota), tolte quelle che un accordo di rientro in corso tiene per intero fuori dalle lettere. È
     * la condizione sola del suggerimento «oltre la tolleranza» e della regola del passo proposto ({@see \App\Services\Gestionale\Debiti\PassoProposto}):
     * il sollecito si propone oltre la tolleranza.
     *
     * @param list<array{scadenza: string, giorni_ritardo: ?int, residuo: int, accordo?: ?array{in_accordo?: int, fuori_dalle_lettere?: bool}}> $rate
     * @return list<string>
     */
    public static function scadenzeOltreLaTolleranza(array $rate, int $tolleranza): array
    {
        $scadenze = [];
        foreach ($rate as $d) {
            $oltre = $d['giorni_ritardo'] === null || $d['giorni_ritardo'] > $tolleranza;
            $inAccordo = ($d['accordo']['fuori_dalle_lettere'] ?? false) && (int) ($d['accordo']['in_accordo'] ?? 0) >= (int) $d['residuo'];
            if ($oltre && ! $inAccordo) {
                $scadenze[] = (string) $d['scadenza'];
            }
        }
        sort($scadenze);

        return $scadenze;
    }

    /**
     * «Per 3 persone gli interessi di mora si fermano: il tasso legale del 2027 non è ancora nel programma. Puoi proporli fino al 31/12/2026.» Dalla
     * frase del calcolo, la prima parte e il giorno fin dove si può proporre.
     */
    public static function fraseFermi(int $persone, string $perche): string
    {
        $chi = $persone === 1 ? '1 persona' : $persone . ' persone';
        $motivo = trim(explode(':', $perche, 2)[0]);
        $fino = preg_match('/fino al (\d{2}\/\d{2}\/\d{4})/', $perche, $m) === 1 ? ' Puoi proporli fino al ' . $m[1] . '.' : '';

        return 'Per ' . $chi . ' gli interessi di mora si fermano: ' . mb_strtolower(mb_substr($motivo, 0, 1)) . mb_substr($motivo, 1) . '.' . $fino;
    }

    /** «3 persone oltre i 15 giorni di tolleranza senza una lettera.» */
    public static function fraseOltreTolleranza(int $persone, int $giorni): string
    {
        $chi = $persone === 1 ? '1 persona' : $persone . ' persone';

        return $giorni === 0 ? $chi . ' con rate scadute senza una lettera.'
            : $chi . ' oltre ' . ($giorni === 1 ? 'il giorno' : 'i ' . $giorni . ' giorni') . ' di tolleranza senza una lettera.';
    }
}
