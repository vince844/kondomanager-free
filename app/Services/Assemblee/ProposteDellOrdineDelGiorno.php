<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoMemo;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\NotaSintetica;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use App\Services\Gestionale\FattiDellaNota;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * L'ordine del giorno proposto (§5 del progetto della tappa 2, la scena 4 della bozza del memo, decisione 501): i gruppi «Di serie», «Da te», «Dal
 * programma», «Dalla nota sintetica». La card della dashboard del condominio usa la stessa lettura.
 *
 * **Quando si propone un sospeso** (§13, rilievo 9): se è ancora aperto (la fattura ancora in sforo, il piano non collegato, il rendiconto non approvato…) e
 * **nessun punto con la stessa `chiave_fonte` sta in un'assemblea in preparazione o convocata** dello stesso condominio; un'assemblea annullata, o tenuta fuori
 * dal programma senza delibera su quel punto, lo rimette fra le proposte. Un memo sulla stessa origine di un sospeso è un punto solo, con il testo del memo
 * (decisione 499 (3)). «Varie ed eventuali» si propone sempre, se l'assemblea non l'ha già.
 *
 * Ogni punto: `chiave_fonte`, `testo`, `testo_pubblico`, `frase` (la riga sotto), `materia`, `maggioranza` (proposta), `avviso`, `amministrazione`,
 * `origine` (il gruppo), `fonte`, `spuntato` (di serie sì: ciò che si toglie resta per la prossima; per un'assemblea di una palazzina o di una scala i
 * sospesi di tutto il condominio no), `memo_id`, `oggetti`, `esercizio_id`, `importo`.
 */
final class ProposteDellOrdineDelGiorno
{
    public const CHIAVE_VARIE = 'varie';

    public const GRUPPI = ['di_serie' => 'Di serie', 'da_te' => 'Da te', 'dal_programma' => 'Dal programma', 'dalla_nota' => 'Dalla nota sintetica'];

    /**
     * Con `$conLaNota` falso non si leggono i fatti della nota sintetica (il conguaglio di tutte le persone, i controlli del rendiconto): la card della
     * dashboard e quella dell'archivio contano i memo, i sospesi e le righe scritte nella nota, senza rifare quei conti a ogni apertura. «Prepara
     * l'assemblea» li legge tutti.
     *
     * @return list<array{chiave: string, titolo: string, punti: list<array<string, mixed>>}>
     */
    public static function per(Condominio $condominio, ?Assemblea $assemblea, bool $conLaNota = true): array
    {
        $occupate = self::chiaviOccupate($condominio, $assemblea);
        $gruppi = ['di_serie' => [], 'da_te' => [], 'dal_programma' => [], 'dalla_nota' => []];
        // Un'assemblea di una palazzina o di una scala: i sospesi di tutto il condominio si propongono, ma non spuntati (decide l'amministratore se sono
        // anche di quella parte); i memo sono già del suo perimetro, e «Varie ed eventuali» resta spuntata.
        $diUnaParte = $assemblea !== null && $assemblea->ambito !== null && $assemblea->ambito !== AmbitoAssemblea::CONDOMINIO;
        // R8 del seguito delle delibere: il sospeso che torna proposto dopo un rinvio dice «rinviato dal 15/12/2026».
        $rinvii = \App\Services\Assemblee\Seguito\StatoDiEsecuzione::rinvii($condominio);
        $aggiungi = function (string $gruppo, array $punto) use (&$gruppi, $occupate, $diUnaParte, $rinvii) {
            if (in_array($punto['chiave_fonte'], $occupate, true)) {
                return;
            }
            $materia = (string) $punto['materia'];
            $maggioranza = array_key_exists('maggioranza', $punto) ? $punto['maggioranza'] : MaggioranzePerMateria::proposta($materia);
            $gruppi[$gruppo][$punto['chiave_fonte']] = $punto + [
                'maggioranza' => $maggioranza,
                'avviso' => MaggioranzePerMateria::avviso($materia),
                'amministrazione' => MaggioranzePerMateria::amministrazione($materia, $maggioranza),
                'conduttore' => MaggioranzePerMateria::conduttore($materia),
                'origine' => $gruppo,
                'spuntato' => ! $diUnaParte || $gruppo === 'da_te' || $punto['chiave_fonte'] === self::CHIAVE_VARIE,
                'memo_id' => null,
                'oggetti' => [],
                'esercizio_id' => null,
                'importo' => null,
                'testo_pubblico' => $punto['testo'],
                'frase' => null,
                'rinviato_dal' => $rinvii[$punto['chiave_fonte']] ?? null,
            ];
        };

        foreach (self::diSerie($condominio, $assemblea) as $p) {
            $aggiungi('di_serie', $p);
        }
        foreach (self::dalProgramma($condominio) as $p) {
            $aggiungi('dal_programma', $p);
        }
        foreach (self::dallaNota($condominio, $conLaNota) as $p) {
            $aggiungi('dalla_nota', $p);
        }
        // I memo per ultimi: un memo sulla stessa origine di un sospeso prende il suo posto, con il suo testo (decisione 499 (3)).
        foreach (self::memo($condominio, $assemblea) as $p) {
            if (in_array($p['chiave_fonte'], $occupate, true)) {
                continue;
            }
            foreach (['di_serie', 'dal_programma', 'dalla_nota'] as $altro) {
                unset($gruppi[$altro][$p['chiave_fonte']]);
            }
            $aggiungi('da_te', $p);
        }

        $esito = [];
        foreach ($gruppi as $chiave => $punti) {
            $esito[] = ['chiave' => $chiave, 'titolo' => self::GRUPPI[$chiave], 'punti' => array_values($punti)];
        }

        return $esito;
    }

    /** Un sospeso con la sua chiave in un'assemblea in preparazione, convocata o in corso (la sala aperta) non si propone più altrove. */
    public const STATI_CHE_TENGONO_IL_PUNTO = ['in_preparazione', 'convocata', 'in_corso'];

    /**
     * Le chiavi già in un'assemblea in preparazione, convocata o in corso del condominio (e nell'assemblea che si sta preparando). «Varie ed eventuali» conta
     * solo nell'assemblea stessa.
     *
     * @return list<string>
     */
    public static function chiaviOccupate(Condominio $condominio, ?Assemblea $assemblea): array
    {
        $chiavi = DB::table('assemblee_punti')->join('assemblee', 'assemblee.id', '=', 'assemblee_punti.assemblea_id')
            ->where('assemblee.condominio_id', $condominio->id)
            ->whereIn('assemblee.stato', self::STATI_CHE_TENGONO_IL_PUNTO)
            ->whereNotNull('assemblee_punti.chiave_fonte')->where('assemblee_punti.chiave_fonte', '!=', self::CHIAVE_VARIE)
            ->pluck('assemblee_punti.chiave_fonte')->all();
        if ($assemblea !== null) {
            $chiavi = [...$chiavi, ...$assemblea->punti()->whereNotNull('chiave_fonte')->pluck('chiave_fonte')->all()];
        }

        return array_values(array_unique(array_map('strval', [...$chiavi, ...self::chiaviConUnaDeliberaViva($condominio)])));
    }

    /**
     * Le chiavi con un punto che ha una delibera viva (approvata o approvata con modifiche, non revocata e non annullata), in un'assemblea di qualunque
     * stato: il sospeso è stato deciso, e a dire la cosa da fare è il seguito della delibera (il compito del deficit resta aperto finché il piano non c'è).
     * Un rinvio o un esito respinto non occupano la chiave.
     *
     * @return list<string>
     */
    public static function chiaviConUnaDeliberaViva(Condominio $condominio): array
    {
        return DB::table('delibere')->join('assemblee_punti', 'assemblee_punti.id', '=', 'delibere.punto_id')
            ->where('delibere.condominio_id', $condominio->id)
            ->whereIn('delibere.esito', [EsitoDelibera::APPROVATA->value, EsitoDelibera::APPROVATA_CON_MODIFICHE->value])
            ->whereNull('delibere.revocata_il')->whereNull('delibere.annullata_il')
            ->whereNotNull('assemblee_punti.chiave_fonte')->where('assemblee_punti.chiave_fonte', '!=', self::CHIAVE_VARIE)
            ->distinct()->pluck('assemblee_punti.chiave_fonte')->map(fn ($c) => (string) $c)->all();
    }

    /**
     * Il punto che ha già quella chiave in un'assemblea in preparazione, convocata o in corso del condominio, con il numero e il giorno: «È già il punto 3 …».
     *
     * @return array{numero: int, data: ?string, assemblea_id: int}|null
     */
    public static function puntoConLaChiave(Condominio $condominio, string $chiave): ?array
    {
        $punto = DB::table('assemblee_punti')->join('assemblee', 'assemblee.id', '=', 'assemblee_punti.assemblea_id')
            ->where('assemblee.condominio_id', $condominio->id)->where('assemblee_punti.chiave_fonte', $chiave)
            ->whereIn('assemblee.stato', self::STATI_CHE_TENGONO_IL_PUNTO)
            ->orderByRaw('assemblee.data IS NULL')->orderBy('assemblee.data')->first(['assemblee_punti.numero', 'assemblee.data', 'assemblee.id']);

        return $punto === null ? null : ['numero' => (int) $punto->numero, 'data' => $punto->data, 'assemblea_id' => (int) $punto->id];
    }

    /**
     * La scheda è un sospeso che il programma propone da sé (lo sforo da ratificare, i lavori senza delibera o d'urgenza non riferiti, il rendiconto da
     * approvare, il deficit da ripianare, la dispensa dei sei mesi)? La stessa regola dei gruppi «Di serie» e «Dal programma», per una scheda sola: la
     * finestra del memo lo chiede senza ricalcolare tutto l'ordine del giorno.
     */
    public static function eUnSospeso(\Illuminate\Database\Eloquent\Model $scheda): bool
    {
        $tipo = SchedaDelCondominio::tipoDi($scheda);
        if (in_array($tipo, ['fattura_passiva', 'piano_rate', 'compito'], true) && self::haUnaDeliberaViva($scheda)) {
            return false;
        }
        if ($tipo === 'fattura_passiva') {
            /** @var FatturaPassiva $scheda */
            return RatificaDelloSforo::aspettaLaRatifica($scheda);
        }
        if ($tipo === 'piano_rate') {
            $straordinaria = DB::table('gestioni')->where('id', $scheda->getAttribute('gestione_id'))->value('tipo') === 'straordinaria';
            $urgenza = $scheda->getAttribute('tipo_autorizzazione') === 'urgenza';
            $collegato = DB::table('delibere_collegamenti')->where('oggetto_tipo', 'piano_rate')->where('oggetto_id', $scheda->getKey())
                ->whereIn('ruolo', ['delibera', 'riferimento'])->exists();

            return $straordinaria && ! $collegato && ($urgenza || $scheda->getAttribute('data_delibera_assemblea') === null);
        }
        if ($tipo === 'esercizio' || $tipo === 'rendiconto_versione') {
            $esercizio = $tipo === 'esercizio' ? (int) $scheda->getKey() : (int) $scheda->getAttribute('esercizio_id');
            $condominio = Condominio::query()->find($scheda->getAttribute('condominio_id'));
            try {
                $rendiconti = $condominio === null ? [] : app(SeiMesiPerAgire::class)->rendicontiDaPortareInAssemblea($condominio);
            } catch (Throwable) {
                return false;
            }
            foreach ($rendiconti as $r) {
                if ((int) $r['esercizio']['id'] === $esercizio && ($r['stato'] ?? null) !== 'approvata') {
                    return true;
                }
            }

            return false;
        }
        if ($tipo === 'compito') {
            return (($scheda->getAttribute('meta') ?? [])['type'] ?? null) === 'pianifica_ripianamento_deficit' && ! $scheda->getAttribute('is_completed');
        }
        if ($tipo === DispensaInAssemblea::TIPO) {
            $condominio = Condominio::query()->find($scheda->getAttribute('condominio_id'));

            return $condominio !== null && DispensaInAssemblea::eAperta($condominio, (int) $scheda->getKey());
        }

        return false;
    }

    /** La scheda ha la sua chiave su un punto con una delibera viva: non è più un sospeso da proporre ({@see chiaviConUnaDeliberaViva()}). */
    private static function haUnaDeliberaViva(\Illuminate\Database\Eloquent\Model $scheda): bool
    {
        // Il compito non ha la colonna del condominio: lo dice il collegamento.
        $condominioId = $scheda->getAttribute('condominio_id') ?? DB::table('condominio_evento')->where('evento_id', $scheda->getKey())->value('condominio_id');
        $condominio = $condominioId === null ? null : Condominio::query()->find($condominioId);
        if ($condominio === null) {
            return false;
        }
        try {
            $chiave = PropostaDelPunto::perLaScheda($scheda)['chiave_fonte'];
        } catch (Throwable) {
            return false;
        }

        return in_array($chiave, self::chiaviConUnaDeliberaViva($condominio), true);
    }

    /** Il rendiconto da approvare, il preventivo, «Varie ed eventuali». @return list<array<string, mixed>> */
    private static function diSerie(Condominio $condominio, ?Assemblea $assemblea): array
    {
        $punti = [];
        try {
            $rendiconti = app(SeiMesiPerAgire::class)->rendicontiDaPortareInAssemblea($condominio);
        } catch (Throwable $e) {
            Log::warning('Ordine del giorno proposto: i rendiconti non si sono letti', ['errore' => $e->getMessage()]);
            $rendiconti = [];
        }
        foreach ($rendiconti as $r) {
            if (($r['stato'] ?? null) === 'approvata') {
                continue;
            }
            $id = (int) $r['esercizio']['id'];
            $anno = PropostaDelPunto::anno($id);
            $punti[] = ['chiave_fonte' => 'rendiconto:' . $id, 'testo' => 'Approvazione del rendiconto ' . $anno . ' e dello stato di riparto',
                'materia' => MateriaPunto::RENDICONTO->value, 'fonte' => FontePunto::DI_SERIE->value, 'esercizio_id' => $id,
                'oggetti' => [['esercizio', $id, 'parla_di']],
                'frase' => ($r['stato'] ?? null) === 'presentata' ? 'Il rendiconto fermato va in allegato alla convocazione.'
                    : 'Il rendiconto non è ancora fermato: lo fermi prima di spedire.'];
        }
        $preventivi = DB::table('piani_rate')->join('gestioni', 'gestioni.id', '=', 'piani_rate.gestione_id')
            ->where('piani_rate.condominio_id', $condominio->id)->where('piani_rate.tipo', 'ordinario')->where('piani_rate.stato', 'bozza')
            ->where('gestioni.tipo', '!=', 'straordinaria')->whereNotNull('piani_rate.esercizio_id')
            ->orderBy('piani_rate.esercizio_id')->distinct()->pluck('piani_rate.esercizio_id');
        foreach ($preventivi as $esercizio) {
            $anno = PropostaDelPunto::anno((int) $esercizio);
            $fermato = DB::table('preventivo_versioni')->where('esercizio_id', $esercizio)->where('stato', 'presentata')->exists();
            $punti[] = ['chiave_fonte' => 'preventivo:' . $esercizio, 'testo' => 'Approvazione del preventivo ' . $anno . ' e del piano di riparto',
                'materia' => MateriaPunto::PREVENTIVO->value, 'fonte' => FontePunto::DI_SERIE->value, 'esercizio_id' => (int) $esercizio,
                'oggetti' => [['esercizio', (int) $esercizio, 'parla_di']],
                'frase' => $fermato ? 'Il preventivo fermato va in allegato alla convocazione.' : 'Il preventivo non è ancora fermato: lo fermi prima di spedire.'];
        }
        // R10 del seguito delle delibere: «Stato di esecuzione delle delibere precedenti», senza voto, con le aperte di sempre e le eseguite dall'ultima
        // ordinaria (decisione 622 (7)); l'elenco va nei dati del punto, per l'avviso e il verbale.
        $stato = \App\Services\Assemblee\Seguito\StatoDiEsecuzione::proposta($condominio, $assemblea);
        if ($stato !== null) {
            $punti[] = $stato;
        }
        $haLeVarie = $assemblea !== null && $assemblea->punti()->where('materia', MateriaPunto::VARIE->value)->exists();
        if (! $haLeVarie) {
            $punti[] = ['chiave_fonte' => self::CHIAVE_VARIE, 'testo' => 'Varie ed eventuali', 'materia' => MateriaPunto::VARIE->value,
                'fonte' => FontePunto::DI_SERIE->value];
        }

        return $punti;
    }

    /**
     * Lo sforo, i lavori senza delibera, i lavori d'urgenza non riferiti, il deficit da ripianare, la dispensa dei sei mesi ({@see DispensaInAssemblea}).
     *
     * @return list<array<string, mixed>>
     */
    private static function dalProgramma(Condominio $condominio): array
    {
        $punti = [];
        // Lo sforo (in attesa della ratifica) e la spesa urgente sbloccata per l'urgenza, senza il collegamento alla delibera (decisione 561).
        $collegate = DB::table('delibere_collegamenti')->where('condominio_id', $condominio->id)->where('oggetto_tipo', 'fattura_passiva')
            ->where('ruolo', 'ratifica')->pluck('oggetto_id')->map(fn ($id) => (int) $id)->all();
        $fatture = FatturaPassiva::query()->where('condominio_id', $condominio->id)
            ->where(fn ($q) => $q->where('stato_approvazione', 'sforo_motivato')
                ->orWhere(fn ($w) => $w->where('stato_approvazione', 'approvata')->whereNotNull('dati_extra->ratifica_assembleare')))
            ->orderBy('id')->get();
        foreach ($fatture as $fattura) {
            // Una ratifica fatta prima della 1.12 con la nota (senza la chiave `urgenza`) è ratificata: non è un sospeso.
            if (in_array((int) $fattura->id, $collegate, true)
                || ($fattura->stato_approvazione !== 'sforo_motivato' && ! RatificaDelloSforo::sbloccataPerLUrgenza($fattura))) {
                continue;
            }
            $p = PropostaDelPunto::perLaScheda($fattura);
            // La motivazione della spesa (quella dell'urgenza, o quella della registrazione) sotto il punto in «Prepara l'assemblea», nel testo per i
            // condòmini e nei dati del punto (l'avviso, la sala).
            $sotto = isset($p['dati']['motivazione']) ? ' ' . PropostaDelPunto::fraseDellaMotivazione($p['dati']['motivazione'], $p['dati']['motivazione_di']) : '';
            $punti[] = ['chiave_fonte' => $p['chiave_fonte'], 'testo' => $p['testo'], 'testo_pubblico' => $p['testo_pubblico'], 'materia' => $p['materia'],
                'fonte' => FontePunto::PROGRAMMA->value, 'oggetti' => $p['oggetti'], 'importo' => $p['importo'],
                // «Sbloccata per l'urgenza» finché non è pagata, «Pagata per l'urgenza» dopo (la stessa parola della pagina della fattura).
                'frase' => ($fattura->stato_approvazione === 'sforo_motivato' ? 'La spesa supera il preventivo: la ratifica è dell\'assemblea.'
                    : RatificaDelloSforo::parolaDellUrgenza($fattura) . ': va riferita alla prima assemblea (art. 1135 c. 2 c.c.).') . $sotto]
                + (isset($p['dati']) ? ['dati' => $p['dati']] : []);
        }
        // I piani della straordinaria senza la data della delibera e senza urgenza, e quelli d'urgenza non ancora riferiti all'assemblea.
        $conDelibera = DB::table('delibere_collegamenti')->where('condominio_id', $condominio->id)->where('oggetto_tipo', 'piano_rate')
            ->whereIn('ruolo', ['delibera', 'riferimento'])->pluck('oggetto_id')->map(fn ($id) => (int) $id)->all();
        $piani = \App\Models\Gestionale\PianoRate::query()->join('gestioni', 'gestioni.id', '=', 'piani_rate.gestione_id')
            ->where('piani_rate.condominio_id', $condominio->id)->where('gestioni.tipo', 'straordinaria')
            ->where(fn ($q) => $q->where('piani_rate.tipo_autorizzazione', 'urgenza')
                ->orWhere(fn ($w) => $w->whereNull('piani_rate.data_delibera_assemblea')
                    ->where(fn ($x) => $x->whereNull('piani_rate.tipo_autorizzazione')->orWhere('piani_rate.tipo_autorizzazione', '!=', 'urgenza'))))
            ->orderBy('piani_rate.id')->get(['piani_rate.*']);
        foreach ($piani as $piano) {
            if (in_array((int) $piano->id, $conDelibera, true)) {
                continue;
            }
            $p = PropostaDelPunto::perLaScheda($piano);
            $punti[] = ['chiave_fonte' => $p['chiave_fonte'], 'testo' => $p['testo'], 'testo_pubblico' => $p['testo_pubblico'], 'materia' => $p['materia'],
                'fonte' => FontePunto::PROGRAMMA->value, 'oggetti' => $p['oggetti'],
                'frase' => $piano->tipo_autorizzazione === 'urgenza' ? 'Lavori fatti d\'urgenza: si riferiscono alla prima assemblea.'
                    : 'Il piano non ha la data della delibera.'];
        }
        // Il deficit ereditato da ripianare: i compiti aperti.
        $compiti = \App\Models\Evento::query()->where('meta->type', 'pianifica_ripianamento_deficit')->where('is_completed', false)
            ->whereIn('id', DB::table('condominio_evento')->where('condominio_id', $condominio->id)->select('evento_id'))->orderBy('id')->get();
        foreach ($compiti as $compito) {
            $p = PropostaDelPunto::perLaScheda($compito);
            $punti[] = ['chiave_fonte' => 'compito:' . $compito->id, 'testo' => $p['testo'], 'materia' => $p['materia'], 'fonte' => FontePunto::PROGRAMMA->value,
                'oggetti' => [['compito', (int) $compito->id, 'origine']], 'frase' => 'Il deficit ereditato non è coperto da nessuna rata.'];
        }
        // La dispensa dei sei mesi (Coda 736): il compito dei sei mesi aperto, finché una delibera non decide sul punto.
        foreach (DispensaInAssemblea::proposte($condominio) as $p) {
            $punti[] = $p;
        }

        return $punti;
    }

    /**
     * «Da decidere in assemblea» della nota sintetica (Coda 658, D12): i fatti con il voto (la chiave del fatto) e le righe scritte dall'amministratore (la
     * chiave dal testo), per gli esercizi con il rendiconto da approvare. La materia e la maggioranza le sceglie l'amministratore: si propone «Ordinaria» solo
     * dove la materia la dice; una riga sul riparto resta senza proposta.
     *
     * @return list<array<string, mixed>>
     */
    private static function dallaNota(Condominio $condominio, bool $conIFatti = true): array
    {
        $punti = [];
        $esercizi = DB::table('note_sintetiche')->where('condominio_id', $condominio->id)->pluck('esercizio_id')->map(fn ($id) => (int) $id);
        foreach ($esercizi as $esercizioId) {
            $esercizio = Esercizio::query()->find($esercizioId);
            if ($esercizio === null || self::rendicontoApprovato($esercizioId)) {
                continue;
            }
            $contenuto = NotaSinteticaDelRendiconto::contenuto($esercizio);
            foreach ((array) ($contenuto['da_decidere'] ?? []) as $riga) {
                $testo = trim((string) $riga);
                if ($testo === '') {
                    continue;
                }
                $punti[] = ['chiave_fonte' => 'nota:' . $esercizioId . ':riga:' . sha1($testo), 'testo' => $testo, 'materia' => MateriaPunto::ALTRO->value,
                    'maggioranza' => null, 'fonte' => FontePunto::NOTA_SINTETICA->value, 'esercizio_id' => $esercizioId,
                    'frase' => 'Scritto nella nota sintetica del rendiconto ' . PropostaDelPunto::anno($esercizioId) . '.'];
            }
            foreach ($conIFatti ? self::fattiConIlVoto($condominio, $esercizio, $contenuto) : [] as $chiave => $voto) {
                $punti[] = ['chiave_fonte' => 'nota:' . $esercizioId . ':' . $chiave, 'testo' => $voto, 'materia' => MateriaPunto::ALTRO->value,
                    'maggioranza' => null, 'fonte' => FontePunto::NOTA_SINTETICA->value, 'esercizio_id' => $esercizioId,
                    'frase' => 'Dalla nota sintetica del rendiconto ' . PropostaDelPunto::anno($esercizioId) . '.'];
            }
        }

        return $punti;
    }

    /** I fatti della nota che chiedono un voto, con la chiave del fatto (`FattiDellaNota.php:118`); un fatto tolto dall'amministratore no. @return array<string, string> */
    private static function fattiConIlVoto(Condominio $condominio, Esercizio $esercizio, array $contenuto): array
    {
        try {
            $letture = app(NotaSinteticaDelRendiconto::class)->letture($condominio, $esercizio);
            $fatti = app(FattiDellaNota::class)->perEsercizio($condominio, $esercizio, $letture, DateHelper::oggiUtente())['fatti'] ?? [];
        } catch (Throwable $e) {
            Log::warning('Ordine del giorno proposto: i fatti della nota non si sono letti', ['esercizio' => $esercizio->id, 'errore' => $e->getMessage()]);

            return [];
        }
        $voti = [];
        foreach ($fatti as $f) {
            $voto = trim((string) ($f['voto'] ?? ''));
            $stato = $contenuto['fatti'][$f['chiave']]['stato'] ?? null;
            if ($voto !== '' && $stato !== 'tolto') {
                $voti[(string) $f['chiave']] = $voto;
            }
        }

        return $voti;
    }

    private static function rendicontoApprovato(int $esercizioId): bool
    {
        return DB::table('rendiconto_versioni')->where('esercizio_id', $esercizioId)->where('stato', 'approvata')->exists();
    }

    /**
     * I memo in attesa del perimetro, per la prossima o per questa assemblea. Un memo destinato a un'assemblea che non è più in preparazione (tenuta fuori,
     * annullata) vale «per la prossima»: altrimenti non lo proporrebbe più nessuna.
     *
     * @return list<array<string, mixed>>
     */
    private static function memo(Condominio $condominio, ?Assemblea $assemblea): array
    {
        $inPreparazione = Assemblea::query()->where('condominio_id', $condominio->id)->where('stato', StatoAssemblea::IN_PREPARAZIONE->value)->select('id');
        $query = MemoDellAssemblea::query()->where('condominio_id', $condominio->id)->where('stato', StatoMemo::IN_ATTESA->value)
            ->where(fn ($q) => $q->whereNull('assemblea_id')->orWhereNotIn('assemblea_id', $inPreparazione)
                ->when($assemblea !== null, fn ($w) => $w->orWhere('assemblea_id', $assemblea->id)))
            ->orderBy('created_at')->orderBy('id');
        if ($assemblea !== null && $assemblea->ambito !== AmbitoAssemblea::CONDOMINIO) {
            // Un'assemblea di una palazzina o di una scala: i memo di tutto il condominio e quelli del suo perimetro.
            $query->where(fn ($q) => $q->where('ambito', AmbitoAssemblea::CONDOMINIO->value)
                ->orWhere(fn ($w) => $w->where('ambito', $assemblea->ambito?->value)
                    ->where($assemblea->ambito === AmbitoAssemblea::SCALA ? 'scala_id' : 'palazzina_id',
                        $assemblea->ambito === AmbitoAssemblea::SCALA ? $assemblea->scala_id : $assemblea->palazzina_id)));
        }
        $punti = [];
        foreach ($query->get() as $memo) {
            $p = MemoriaDelCondominio::comeProposta($memo);
            $nonTrattato = $memo->punto_id === null ? null : DB::table('assemblee_punti')->join('assemblee', 'assemblee.id', '=', 'assemblee_punti.assemblea_id')
                ->where('assemblee_punti.id', $memo->punto_id)->value('assemblee.data');
            // Un punto rinviato dall'assemblea non è un punto non trattato: la frase lo dice.
            $rinviato = $nonTrattato !== null && DB::table('delibere')->where('punto_id', $memo->punto_id)->where('esito', 'rinviata')->exists();
            $riga = array_values(array_filter([
                'Memo del ' . $memo->created_at?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m'),
                $memo->origine_nome,
                $memo->nota !== null && trim($memo->nota) !== '' ? 'la tua nota: ' . trim($memo->nota) : null,
                $nonTrattato !== null ? ($rinviato ? 'rinviato il ' : 'non trattato il ') . \Illuminate\Support\Carbon::parse($nonTrattato)->format('d/m/Y') : null,
                // Il memo su una spesa oltre il preventivo porta la sua motivazione, come il punto del programma.
                isset($p['dati']['motivazione']) ? rtrim(PropostaDelPunto::fraseDellaMotivazione($p['dati']['motivazione'], $p['dati']['motivazione_di']), '.') : null,
            ]));
            $punti[] = $p + ['frase' => implode(' · ', $riga), 'oggetti' => $p['oggetti']];
        }

        return $punti;
    }
}
