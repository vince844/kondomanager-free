<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Services\Assemblee\SchedaDelCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Una riga del riepilogo «Che cosa cambia nel gestionale» (R2), sempre nella stessa forma. **La chiave** dice la delibera, la scheda e il ruolo
 * (`12:piano_rate:7:delibera`) o la cosa da fare (`12:cosa:crea_piano_straordinario`): la conferma rimanda le chiavi spuntate, e il server applica solo le
 * righe che calcola lui. **Applicata** non è una colonna: è il collegamento che c'è (o la cosa da fare che esiste).
 *
 * I tipi: `applica` (un cambio nei conti, adesso), `cosa` (una cosa da fare con la data e la persona; anche l'avviso ai condòmini e la risposta a una
 * respinta), `delega` (in vigore, nessun effetto nei conti), `memo` (un punto per la prossima assemblea, dalla finestra del memo), `fuori` (resta fuori, con
 * il perché: il piano diverso dal preventivo, la versione da rifare), `gia_fatto` (niente cambia, e la riga lo dice), `nota` (rinviata, respinta senza
 * origine: una frase).
 *
 * **Una chiave per scheda e ruolo**, qualunque sia il tipo della riga: la riga dell'accordo che diventa «già autorizzato» fra l'apertura e la conferma ha la
 * stessa chiave, e la conferma risponde con la sua frase invece di «ricarica la pagina».
 *
 * **Approvata con modifiche** (R6): una riga `applica` delle schede che si correggono per la loro strada (l'accordo, la ratifica, il fondo, i lavori, la
 * dispensa) nasce spenta, con la frase «correggi prima …, poi «Applica»»; la spunta resta all'amministratore. Il rendiconto e il preventivo aspettano la
 * versione corretta, e la loro riga è già quella giusta.
 */
final class RigaDelSeguito
{
    /** Le catene la cui riga, con modifiche, è già sulla versione corretta (R6): restano spuntate. */
    public const CATENE_CHE_ASPETTANO_LA_VERSIONE = ['rendiconto', 'preventivo', 'piano'];

    /** Un cambio nei conti, sulla scheda con il ruolo. @return array<string, mixed> */
    public static function applica(ContestoDellaDelibera $c, Model $scheda, string $ruolo, string $catena, string $titolo, string $frase, bool $spuntata = true): array
    {
        $tipo = SchedaDelCondominio::tipoDi($scheda);
        if ($c->conModifiche() && ! in_array($catena, self::CATENE_CHE_ASPETTANO_LA_VERSIONE, true)) {
            $spuntata = false;
            $frase = 'Approvata con modifiche: correggi prima ' . lcfirst(SchedaDelCondominio::nomeDi($scheda)) . ' come ha deciso l\'assemblea, poi «Applica». '
                . $frase;
        }

        return array_merge(self::forma($c, self::chiaveDellaScheda($c->delibera->id, $tipo, (int) $scheda->getKey(), $ruolo), 'applica', $catena, $titolo,
            $frase), [
            'spuntata' => $spuntata,
            'si_applica' => true,
            'applicata' => CollegamentoAllaDelibera::query()->where('delibera_id', $c->delibera->id)->where('oggetto_tipo', $tipo)
                ->where('oggetto_id', (int) $scheda->getKey())->where('ruolo', $ruolo)->exists(),
            'scheda' => ['tipo' => $tipo, 'id' => (int) $scheda->getKey(), 'nome' => SchedaDelCondominio::nomeDi($scheda)],
            'ruolo' => $ruolo,
            'conto' => 'cambi',
        ]);
    }

    /** Una cosa da fare proposta: la conferma la crea, con la data e la persona scelte. @return array<string, mixed> */
    public static function cosa(ContestoDellaDelibera $c, TipoCosaDaFare $tipo, string $catena, string $titolo, string $frase, string $testo, bool $spuntata = true,
        ?Model $scheda = null): array
    {
        $conto = match ($tipo) {
            TipoCosaDaFare::AVVISA_CONDOMINI => 'comunicazioni',
            TipoCosaDaFare::DOPO_IL_RIFIUTO => 'respinte',
            default => 'cose',
        };

        return array_merge(self::forma($c, self::chiaveDellaCosa($c->delibera->id, $tipo), 'cosa', $catena, $titolo, $frase), [
            'spuntata' => $spuntata,
            'si_applica' => true,
            'applicata' => CosaDaFareDellaDelibera::query()->where('delibera_id', $c->delibera->id)->where('tipo', $tipo->value)->exists(),
            'scheda' => $scheda === null ? null : ['tipo' => SchedaDelCondominio::tipoDi($scheda), 'id' => (int) $scheda->getKey(),
                'nome' => SchedaDelCondominio::nomeDi($scheda)],
            'cosa' => ['tipo' => $tipo->value, 'parola' => $tipo->parola(), 'testo' => $testo],
            'conto' => $conto,
        ]);
    }

    /**
     * Una riga che non si spunta: la delega in vigore, il punto per la prossima assemblea, ciò che resta fuori o è già fatto, una frase. Con la scheda e il
     * ruolo la chiave è quella della scheda (la stessa della riga `applica`); senza il perché, la conferma risponde con il titolo e la frase.
     *
     * @return array<string, mixed>
     */
    public static function senzaSpunta(ContestoDellaDelibera $c, string $tipo, string $catena, string $titolo, string $frase, ?string $percheNo = null,
        ?Model $scheda = null, bool $applicata = false, array $dati = [], ?string $ruolo = null): array
    {
        $chiave = $scheda !== null && $ruolo !== null ? self::chiaveDellaScheda($c->delibera->id, SchedaDelCondominio::tipoDi($scheda), (int) $scheda->getKey(), $ruolo)
            : $c->delibera->id . ':' . $tipo . ($scheda !== null ? ':' . SchedaDelCondominio::tipoDi($scheda) . ':' . $scheda->getKey() : '');
        if ($percheNo === null && in_array($tipo, ['gia_fatto', 'fuori'], true)) {
            $percheNo = rtrim($titolo, '.') . '. ' . $frase;
        }

        return array_merge(self::forma($c, $chiave, $tipo, $catena, $titolo, $frase), [
            'spuntata' => false,
            'si_applica' => false,
            'applicata' => $applicata,
            'perche_no' => $percheNo,
            'scheda' => $scheda === null ? null : ['tipo' => SchedaDelCondominio::tipoDi($scheda), 'id' => (int) $scheda->getKey(),
                'nome' => SchedaDelCondominio::nomeDi($scheda)],
            'ruolo' => $ruolo,
            'conto' => match ($tipo) {
                'delega' => 'deleghe',
                'memo' => 'punti',
                'fuori' => 'fuori',
                default => null,
            },
            'dati' => $dati,
        ]);
    }

    public static function chiaveDellaCosa(int $deliberaId, TipoCosaDaFare $tipo): string
    {
        return $deliberaId . ':cosa:' . $tipo->value;
    }

    /** «12:piano_rate:7:delibera»: la delibera, la scheda e il ruolo. */
    public static function chiaveDellaScheda(int $deliberaId, string $tipoScheda, int $id, string $ruolo): string
    {
        return $deliberaId . ':' . $tipoScheda . ':' . $id . ':' . $ruolo;
    }

    /** @return array<string, mixed> */
    private static function forma(ContestoDellaDelibera $c, string $chiave, string $tipo, string $catena, string $titolo, string $frase): array
    {
        return ['chiave' => $chiave, 'delibera_id' => (int) $c->delibera->id, 'punto' => $c->punto?->numero, 'tipo' => $tipo, 'catena' => $catena,
            'titolo' => $titolo, 'frase' => $frase, 'perche_no' => null, 'scheda' => null, 'ruolo' => null, 'cosa' => null, 'dati' => []];
    }
}
