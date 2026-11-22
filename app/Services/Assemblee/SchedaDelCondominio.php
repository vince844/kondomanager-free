<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Models;
use App\Models\Assemblee;
use App\Models\Condominio;
use App\Models\Gestionale;
use App\Services\RegistroModifiche\CatalogoDelRegistro;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * R18 del progetto della tappa 2 (§13, rilievo 12): **un id che arriva nella richiesta è del condominio della rotta**. Un risolutore solo per ogni scheda che
 * arriva nella query o nel corpo (`oggetto_tipo`/`oggetto_id` di «Scegli la delibera» e del memo, `delibera_id` delle pagine, la tabella di proprietà, «Per
 * quale assemblea»): una scheda di Corso Italia 3 chiesta da Via Roma 12 dà **404**, come un tipo che l'elenco non conosce.
 *
 * I tipi sono i nomi stabili del registro, mai le classi PHP: gli stessi di `assemblee_punti_oggetti.oggetto_tipo`, `delibere_collegamenti.oggetto_tipo` e
 * `assemblee_memo.origine_tipo`.
 */
final class SchedaDelCondominio
{
    /** Tipo => [classe, come si lega al condominio: la colonna, o una relazione letta con una query]. */
    public const TIPI = [
        'fattura_passiva' => [Gestionale\FatturaPassiva::class, 'condominio_id'],
        'piano_rate' => [Gestionale\PianoRate::class, 'condominio_id'],
        'gestione' => [Models\Gestione::class, 'condominio_id'],
        'cassa' => [Gestionale\Cassa::class, 'condominio_id'],
        // I sei mesi dei crediti di un esercizio (Coda 736): l'esercizio letto con un altro tipo, prima di «esercizio» perché `tipoDi()` lo riconosca.
        'sei_mesi' => [Gestionale\SeiMesiDellEsercizio::class, 'condominio_id'],
        'esercizio' => [Models\Esercizio::class, 'condominio_id'],
        'accordo_rientro' => [Gestionale\AccordoRientro::class, 'condominio_id'],
        'dispensa_sei_mesi' => [Gestionale\DispensaSeiMesi::class, 'condominio_id'],
        'regola_recupero' => [Gestionale\RegolaRecupero::class, 'condominio_id'],
        'contributo_versato' => [Gestionale\ContributoVersato::class, 'condominio_id'],
        'scrittura_contabile' => [Gestionale\ScritturaContabile::class, 'condominio_id'],
        'rendiconto_versione' => [Gestionale\RendicontoVersione::class, 'condominio_id'],
        'preventivo_versione' => [Gestionale\VersionePreventivo::class, 'condominio_id'],
        'tabella' => [Models\Tabella::class, 'condominio_id'],
        'palazzina' => [Models\Palazzina::class, 'condominio_id'],
        'scala' => [Models\Scala::class, 'condominio_id'],
        'immobile' => [Models\Immobile::class, 'condominio_id'],
        'segnalazione' => [Models\Segnalazione::class, 'condominio_id'],
        'assemblea' => [Assemblee\Assemblea::class, 'condominio_id'],
        'delibera' => [Assemblee\Delibera::class, 'condominio_id'],
        'memo_assemblea' => [Assemblee\MemoDellAssemblea::class, 'condominio_id'],
        'punto' => [Assemblee\PuntoDellOrdineDelGiorno::class, 'assemblea'],
        'rata_quote' => [Gestionale\RataQuote::class, 'rata'],
        'anagrafica' => [Models\Anagrafica::class, 'persona'],
        'compito' => [Models\Evento::class, 'compito'],
    ];

    /** La scheda di quel tipo e di quell'id, se è del condominio; altrimenti 404. */
    public static function risolvi(Condominio $condominio, string $tipo, int $id): Model
    {
        $voce = self::TIPI[$tipo] ?? null;
        abort_if($voce === null, 404);
        [$classe, $legame] = $voce;
        $scheda = $classe::query()->find($id);
        abort_if($scheda === null || ! self::eDelCondominio($condominio, $scheda, $legame), 404);

        return $scheda;
    }

    /** Come {@see risolvi()}, ma con `null` al posto del 404: per le letture che vanno avanti senza la scheda. */
    public static function cerca(Condominio $condominio, ?string $tipo, ?int $id): ?Model
    {
        if ($tipo === null || $id === null || ! isset(self::TIPI[$tipo])) {
            return null;
        }
        [$classe, $legame] = self::TIPI[$tipo];
        $scheda = $classe::query()->find($id);

        return $scheda !== null && self::eDelCondominio($condominio, $scheda, $legame) ? $scheda : null;
    }

    private static function eDelCondominio(Condominio $condominio, Model $scheda, string $legame): bool
    {
        if ($legame === 'persona') {
            return self::personaDelCondominio($condominio, (int) $scheda->getKey());
        }
        if ($legame === 'compito') {
            return DB::table('condominio_evento')->where('evento_id', $scheda->getKey())->where('condominio_id', $condominio->id)->exists();
        }

        return self::condominioDi($scheda, $legame) === (int) $condominio->id;
    }

    /** Il tipo stabile di una scheda (`fattura_passiva`), mai la classe. */
    public static function tipoDi(Model $scheda): string
    {
        foreach (self::TIPI as $tipo => [$classe]) {
            if ($scheda instanceof $classe) {
                return $tipo;
            }
        }

        return CatalogoDelRegistro::tipoDi($scheda);
    }

    /** Il condominio di una scheda: dalla colonna, o dalla relazione per il punto, la quota, la persona (la prima) e il compito. */
    public static function condominioDi(Model $scheda, ?string $legame = null): ?int
    {
        $legame ??= self::TIPI[self::tipoDi($scheda)][1] ?? 'condominio_id';
        $id = match ($legame) {
            'assemblea' => DB::table('assemblee')->where('id', $scheda->getAttribute('assemblea_id'))->value('condominio_id'),
            'rata' => DB::table('rate')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')->where('rate.id', $scheda->getAttribute('rata_id'))
                ->value('piani_rate.condominio_id'),
            'persona' => null,
            'compito' => DB::table('condominio_evento')->where('evento_id', $scheda->getKey())->orderBy('condominio_id')->value('condominio_id'),
            default => $scheda->getAttribute($legame),
        };

        return $id === null ? null : (int) $id;
    }

    /** Una persona è «del condominio» se vi è collegata: il controllo di {@see risolvi()} per l'anagrafica. */
    public static function personaDelCondominio(Condominio $condominio, int $anagraficaId): bool
    {
        return DB::table('anagrafica_condominio')->where('condominio_id', $condominio->id)->where('anagrafica_id', $anagraficaId)->exists();
    }

    /**
     * Il nome di una scheda come lo dicono le righe e il memo: «Fattura n. 45 · Idraulica Bianchi · € 1.800,00», «Piano «Facciata 2026 — rate»», «Dispensa
     * dei sei mesi del 22/10/2026». Copiato nel memo, resta se la scheda sparisce.
     */
    public static function nomeDi(Model $scheda): string
    {
        $tipo = self::tipoDi($scheda);
        $euro = fn ($c) => $c === null ? null : FormatoDelValore::testo((int) $c, 'euro');
        $insieme = fn (...$p) => implode(' · ', array_values(array_filter($p, fn ($x) => $x !== null && $x !== '')));

        return match ($tipo) {
            'fattura_passiva' => $insieme('Fattura n. ' . $scheda->getAttribute('numero_documento'),
                DB::table('fornitori')->where('id', $scheda->getAttribute('fornitore_id'))->value('ragione_sociale'), $euro($scheda->getAttribute('totale_documento'))),
            'piano_rate' => 'Piano «' . $scheda->getAttribute('nome') . '»',
            'gestione' => 'Gestione «' . $scheda->getAttribute('nome') . '»',
            'cassa' => 'Fondo «' . $scheda->getAttribute('nome') . '»',
            'esercizio' => (string) $scheda->getAttribute('nome'),
            'sei_mesi' => 'Sei mesi per i crediti del ' . trim((string) preg_replace('/^esercizio\s+/iu', '', (string) $scheda->getAttribute('nome'))),
            'accordo_rientro' => 'Accordo di rientro di ' . DB::table('anagrafiche')->where('id', $scheda->getAttribute('anagrafica_id'))->value('nome'),
            'dispensa_sei_mesi' => 'Dispensa dei sei mesi' . (($d = FormatoDelValore::testo($scheda->getAttributes()['data_delibera'] ?? null, 'data')) !== null ? ' del ' . $d : ''),
            'regola_recupero' => 'Regola del recupero dei crediti' . (($d = FormatoDelValore::testo($scheda->getAttributes()['dal'] ?? null, 'data')) !== null ? ' dal ' . $d : ''),
            'contributo_versato' => 'Già versato',
            'scrittura_contabile' => 'Scrittura «' . ($scheda->getAttribute('causale') ?? $scheda->getAttribute('descrizione') ?? $scheda->getKey()) . '»',
            'rendiconto_versione' => 'Rendiconto, versione ' . $scheda->getAttribute('numero'),
            'preventivo_versione' => 'Preventivo, versione ' . $scheda->getAttribute('numero'),
            'anagrafica' => (string) $scheda->getAttribute('nome'),
            'compito' => 'Compito «' . $scheda->getAttribute('title') . '»',
            'segnalazione' => 'Segnalazione',
            'rata_quote' => 'Addebito',
            default => ($d = CatalogoDelRegistro::descrizionePer($scheda)) !== null ? $d->nome($scheda) : ucfirst(str_replace('_', ' ', $tipo)),
        };
    }
}
