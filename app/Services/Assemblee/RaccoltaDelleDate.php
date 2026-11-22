<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R9 del progetto della tappa 2 (decisione 492 (9), D10): **la raccolta delle date già scritte**, su proposta, con l'anteprima e la conferma. Le date di
 * quattro posti (piani rate, dispense, accordi, spese impreviste) si raggruppano per giorno e numero del verbale in riunioni a mano, con le schede di ognuna.
 * **L'anteprima non scrive niente** e **segna le date sospette**: la data del piano uguale al giorno in cui è stato approvato nel programma (la stessa lettura di
 * `VerificaDelibereCommand`, §13 rilievo 10). La conferma crea solo le righe spuntate, con i loro collegamenti (le date coincidono per costruzione), e copia
 * nel verbale della riunione il file del verbale di una dispensa se c'è. Rilanciata, non propone ciò che è già raccolto.
 *
 * Non propone ciò che non ha una data della riunione: le ratifiche di prima (la data è quella del clic), le regole e i fondi (solo testo).
 */
final class RaccoltaDelleDate
{
    public const FRASE_SOSPETTA = 'La data è il giorno in cui il piano è stato approvato nel programma: controllala sul verbale';

    /** Una data dopo oggi non è di una riunione già tenuta: un errore di battitura o una data prevista. Non si raccoglie finché non è corretta. */
    public const FRASE_DOPO_OGGI = 'La data è dopo oggi: controllala sul verbale';

    /** Tipo => ruolo del collegamento. */
    private const RUOLI = [
        'piano_rate' => RuoloCollegamento::DELIBERA,
        'dispensa_sei_mesi' => RuoloCollegamento::DISPENSA,
        'accordo_rientro' => RuoloCollegamento::AUTORIZZAZIONE,
        'fattura_passiva' => RuoloCollegamento::ORIGINE_DELLA_SPESA,
    ];

    /**
     * Le riunioni proposte, dalla più vecchia: il giorno, il numero del verbale, la riunione a mano che c'è già (se c'è), le schede con la casella (spuntata di
     * serie, tranne le sospette).
     *
     * @return list<array{chiave: string, giorno: string, numero_verbale: ?string, riunione_id: ?int, schede: list<array<string, mixed>>}>
     */
    public static function anteprima(Condominio $condominio): array
    {
        $gruppi = [];
        foreach (self::schede($condominio) as $s) {
            $chiave = $s['giorno'] . '|' . (DeliberaAMano::numero($s['numero_verbale']) ?? '');
            $gruppi[$chiave] ??= ['chiave' => $chiave, 'giorno' => $s['giorno'], 'numero_verbale' => $s['numero_verbale'], 'riunione_id' => null, 'schede' => []];
            $gruppi[$chiave]['schede'][] = $s;
        }
        ksort($gruppi);
        foreach ($gruppi as &$g) {
            $g['riunione_id'] = DeliberaAMano::riunioneDiDestinazione($condominio, ['giorno' => $g['giorno'], 'numero_verbale' => $g['numero_verbale']])?->id;
        }

        return array_values($gruppi);
    }

    /** Quante date da raccogliere: il numero della riga «In Via Roma ci sono 6 date scritte a mano: raccoglile». */
    public static function quanteDate(Condominio $condominio): int
    {
        return count(self::schede($condominio));
    }

    /**
     * Conferma le righe spuntate (`[[oggetto_tipo, oggetto_id], …]`): nascono le riunioni a mano (o si usa quella che c'è), un punto e una delibera
     * approvata per scheda, il collegamento. Le righe del registro: **una per scheda collegata** (il suo campo «Delibera») e il riepilogo sul condominio.
     *
     * `$perimetri`: per la chiave della riunione proposta, per chi era (`ambito`, `palazzina_id`, `scala_id`, risolti nel condominio); di serie tutto il
     * condominio. Una data dopo oggi spuntata non passa: la frase dice quale, e niente si raccoglie.
     *
     * @param list<array{0: string, 1: int}|array{oggetto_tipo: string, oggetto_id: int}> $spuntate
     * @param array<string, array<string, mixed>> $perimetri
     * @return array{riunioni: int, delibere: int, schede: int}
     */
    public static function conferma(Condominio $condominio, array $spuntate, User $utente, array $perimetri = []): array
    {
        $scelte = [];
        foreach ($spuntate as $s) {
            [$tipo, $id] = array_is_list($s) ? [$s[0], $s[1]] : [$s['oggetto_tipo'], $s['oggetto_id']];
            $scelte[(string) $tipo . ':' . (int) $id] = true;
        }
        if ($scelte === []) {
            throw ValidationException::withMessages(['spuntate' => 'Spunta almeno una data da raccogliere.']);
        }
        $gruppi = array_values(array_filter(array_map(function ($g) use ($scelte, $condominio, $perimetri) {
            $g['schede'] = array_values(array_filter($g['schede'], fn ($s) => isset($scelte[$s['oggetto_tipo'] . ':' . $s['oggetto_id']])));
            $g['perimetro'] = MemoriaDelCondominio::perimetro($condominio, (array) ($perimetri[$g['chiave']] ?? []));

            return $g;
        }, self::anteprima($condominio)), fn ($g) => $g['schede'] !== []));
        $dopoOggi = [];
        foreach ($gruppi as $g) {
            foreach ($g['schede'] as $s) {
                if ($s['giorno'] > \App\Helpers\DateHelper::oggiUtente()) {
                    $dopoOggi[] = 'La data ' . self::delTipo($s['oggetto_tipo']) . ' «' . self::nomeBreve($s) . '» è dopo oggi ('
                        . Carbon::parse($s['giorno'])->format('d/m/Y') . '): controllala sul verbale e correggila nella sua scheda, poi raccoglila.';
                }
            }
        }
        if ($dopoOggi !== []) {
            throw ValidationException::withMessages(['spuntate' => $dopoOggi]);
        }

        return DB::transaction(function () use ($condominio, $gruppi, $utente) {
            $daCollegare = [];
            $esito = RegistroModifiche::durante(GestiDelRegistro::dateRaccolte($condominio), function () use ($condominio, $gruppi, $utente, &$daCollegare) {
                $riunioni = 0;
                foreach ($gruppi as $g) {
                    [$ambito, $palazzina, $scala] = $g['perimetro'];
                    $riunione = DeliberaAMano::riunioneDiDestinazione($condominio, ['giorno' => $g['giorno'], 'numero_verbale' => $g['numero_verbale'],
                        'ambito' => $ambito, 'palazzina_id' => $palazzina, 'scala_id' => $scala]);
                    if ($riunione === null) {
                        $riunione = Assemblea::create(['condominio_id' => $condominio->id, 'ambito' => $ambito, 'palazzina_id' => $palazzina,
                            'scala_id' => $scala, 'tipo' => TipoAssemblea::ORDINARIA->value,
                            'origine' => OrigineAssemblea::A_MANO->value, 'stato' => StatoAssemblea::REGISTRATA_A_MANO->value, 'data' => $g['giorno'],
                            'numero_verbale' => $g['numero_verbale'], 'created_by' => $utente->id, 'updated_by' => $utente->id]);
                        $riunioni++;
                    }
                    foreach ($g['schede'] as $s) {
                        $modello = $s['modello'];
                        $proposta = PropostaDelPunto::perLaScheda($modello);
                        $punto = PuntoDellOrdineDelGiorno::create(['assemblea_id' => $riunione->id, 'numero' => (int) $riunione->punti()->max('numero') + 1,
                            'testo' => $proposta['testo'], 'materia' => $proposta['materia'], 'maggioranza' => $proposta['maggioranza'],
                            'amministrazione' => $proposta['amministrazione'], 'esercizio_id' => $proposta['esercizio_id'], 'importo' => $proposta['importo'],
                            'fonte' => FontePunto::A_MANO->value, 'chiave_fonte' => $proposta['chiave_fonte'], 'created_by' => $utente->id]);
                        foreach ($proposta['oggetti'] as [$tipo, $id, $ruolo]) {
                            OggettoDelPunto::create(['punto_id' => $punto->id, 'oggetto_tipo' => $tipo, 'oggetto_id' => $id, 'ruolo' => $ruolo]);
                        }
                        $delibera = Delibera::create(['condominio_id' => $condominio->id, 'assemblea_id' => $riunione->id, 'punto_id' => $punto->id,
                            'testo' => $proposta['testo'], 'esito' => EsitoDelibera::APPROVATA->value, 'deliberata_il' => $g['giorno'],
                            'importo' => $proposta['importo'], 'created_by' => $utente->id]);
                        $daCollegare[] = [$modello, $s['ruolo'], $delibera];
                        if ($s['oggetto_tipo'] === 'dispensa_sei_mesi') {
                            self::copiaIlVerbale($modello, $riunione, $utente);
                        }
                    }
                }

                return ['riunioni' => $riunioni, 'delibere' => count($daCollegare), 'schede' => count($daCollegare)];
            });
            // Fuori dal lotto: ogni scheda collegata ha la sua riga, il campo «Delibera».
            foreach ($daCollegare as [$modello, $ruolo, $delibera]) {
                CollegamentiDelleDelibere::collega($modello, $ruolo, $delibera, null, $utente);
            }

            return $esito;
        });
    }

    /**
     * Le schede con una data scritta a mano e senza il collegamento del loro ruolo.
     *
     * @return list<array{oggetto_tipo: string, oggetto_id: int, ruolo: string, nome: string, giorno: string, numero_verbale: ?string, sospetta: ?string, spuntata: bool, modello: Model}>
     */
    private static function schede(Condominio $condominio): array
    {
        $raccolte = DB::table('delibere_collegamenti')->where('condominio_id', $condominio->id)->get(['oggetto_tipo', 'oggetto_id', 'ruolo'])
            ->map(fn ($r) => $r->oggetto_tipo . ':' . $r->oggetto_id . ':' . $r->ruolo)->flip();
        $righe = [];
        $aggiungi = function (Model $m, string $tipo, mixed $giorno, ?string $numero, ?string $sospetta) use (&$righe, $raccolte) {
            $ruolo = self::RUOLI[$tipo]->value;
            if ($giorno === null || $giorno === '' || $raccolte->has($tipo . ':' . $m->getKey() . ':' . $ruolo)) {
                return;
            }
            $righe[] = ['oggetto_tipo' => $tipo, 'oggetto_id' => (int) $m->getKey(), 'ruolo' => $ruolo, 'nome' => SchedaDelCondominio::nomeDi($m),
                'giorno' => Carbon::parse($giorno instanceof \DateTimeInterface ? $giorno->format('Y-m-d') : (string) $giorno)->format('Y-m-d'),
                'numero_verbale' => $numero === null || trim($numero) === '' ? null : trim($numero), 'sospetta' => $sospetta, 'spuntata' => $sospetta === null,
                'modello' => $m];
        };
        foreach (PianoRate::query()->where('condominio_id', $condominio->id)->whereNotNull('data_delibera_assemblea')->orderBy('id')->get() as $p) {
            $giorno = Carbon::parse($p->data_delibera_assemblea)->format('Y-m-d');
            $approvato = $p->approvato_il === null ? null : Carbon::parse($p->approvato_il)->format('Y-m-d');
            $aggiungi($p, 'piano_rate', $giorno, $p->numero_verbale, $approvato === $giorno ? self::FRASE_SOSPETTA : null);
        }
        foreach (DispensaSeiMesi::query()->where('condominio_id', $condominio->id)->orderBy('id')->get() as $d) {
            $aggiungi($d, 'dispensa_sei_mesi', $d->data_delibera, $d->numero_verbale, null);
        }
        foreach (AccordoRientro::query()->where('condominio_id', $condominio->id)->where('autorizzato_assemblea', true)->whereNotNull('data_autorizzazione')
            ->orderBy('id')->get() as $a) {
            $aggiungi($a, 'accordo_rientro', $a->data_autorizzazione, $a->verbale_autorizzazione, null);
        }
        foreach (FatturaPassiva::query()->where('condominio_id', $condominio->id)->whereNotNull('dati_extra->log_legale_sopravvenienza->data_assemblea')
            ->orderBy('id')->get() as $f) {
            $aggiungi($f, 'fattura_passiva', ($f->dati_extra['log_legale_sopravvenienza'] ?? [])['data_assemblea'] ?? null, null, null);
        }
        // Una data dopo oggi ha il suo segno e non si spunta di serie: la delibera di un giorno che deve ancora venire non si sceglie.
        $oggi = \App\Helpers\DateHelper::oggiUtente();
        foreach ($righe as &$riga) {
            if ($riga['giorno'] > $oggi) {
                $riga['sospetta'] = self::FRASE_DOPO_OGGI;
                $riga['spuntata'] = false;
            }
        }
        unset($riga);

        return $righe;
    }

    private static function delTipo(string $tipo): string
    {
        return match ($tipo) {
            'piano_rate' => 'del piano',
            'dispensa_sei_mesi' => 'della dispensa',
            'accordo_rientro' => 'dell\'accordo',
            default => 'della spesa',
        };
    }

    /** «Tetto — rate» da «Piano «Tetto — rate»»: il nome della scheda senza la parola del tipo. @param array<string, mixed> $s */
    private static function nomeBreve(array $s): string
    {
        $m = $s['modello'] ?? null;

        return $s['oggetto_tipo'] === 'piano_rate' && $m !== null ? (string) $m->getAttribute('nome') : (string) $s['nome'];
    }

    /** Il file del verbale della dispensa, copiato come verbale della riunione se la riunione non l'ha ancora. */
    private static function copiaIlVerbale(DispensaSeiMesi $dispensa, Assemblea $riunione, User $utente): void
    {
        if ($riunione->verbale_documento_id !== null) {
            return;
        }
        $file = $dispensa->documenti()->orderBy('id')->first();
        $archivio = app(ArchivioDocumenti::class);
        $contenuto = $file === null ? null : $archivio->leggi($file->path);
        if ($contenuto === null) {
            return;
        }
        $estensione = pathinfo((string) $file->path, PATHINFO_EXTENSION) ?: 'pdf';
        $percorso = $archivio->salvaContenuto($contenuto, $estensione);
        DB::afterRollBack(fn () => $archivio->elimina($percorso));
        $documento = $riunione->documenti()->create(['name' => 'Verbale' . ($riunione->numero_verbale ? ' n. ' . $riunione->numero_verbale : '')
            . ' del ' . $riunione->data?->format('d/m/Y'), 'description' => 'Il verbale della dispensa dei sei mesi, raccolto con la data',
            'path' => $percorso, 'mime_type' => (string) $file->mime_type, 'file_size' => strlen($contenuto), 'created_by' => $utente->id,
            'is_published' => false, 'is_approved' => true]);
        $riunione->update(['verbale_documento_id' => $documento->id]);
    }
}
