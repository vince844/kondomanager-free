<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Subentro;
use App\Models\ImportBatch;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\Ripristino\RipristinoDiUnCampo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Il registro del condominio e quello dello studio (§14 del progetto della tappa 1, scena 2 della bozza): i filtri dall'indirizzo, le righe per la tabella,
 * le scelte dei menu, le tre card, la stampa. Le due pagine sono la stessa: quella dello studio ha in più il condominio fra i filtri.
 */
final class RegistroPerLaPagina
{
    /** Le aree con le parole della pagina, nell'ordine dei filtri. */
    public const AREE = [
        'persone' => 'Persone e fornitori', 'condominio' => 'Condominio e unità', 'millesimi' => 'Tabelle e millesimi', 'piani_rate' => 'Piani rate e gestioni',
        'contabilita' => 'Contabilità', 'comunicazioni' => 'Comunicazioni', 'segnalazioni' => 'Segnalazioni', 'documenti' => 'Documenti',
        'impostazioni' => 'Impostazioni', 'utenti' => 'Utenti e ruoli', 'agenda' => 'Agenda e compiti', 'assemblee' => 'Assemblee',
    ];

    /** Le azioni con le parole della pagina. */
    public const AZIONI = [
        'creata' => 'Creata', 'modificata' => 'Modificata', 'eliminata' => 'Eliminata', 'collegata' => 'Collegata', 'scollegata' => 'Scollegata',
        'ripristinata' => 'Ripristinata', 'gesto' => 'Un\'operazione', 'atto_utenza' => 'Un atto sulle utenze', 'oscuramento' => 'Oscuramento',
        'ripristino_copia' => 'Ripristino di una copia', 'conservazione' => 'Conservazione',
    ];

    public const MESI = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    /** «Tutti i mesi» nel filtro del periodo. */
    public const TUTTI = 'tutti';

    /**
     * I filtri dall'indirizzo, controllati: un valore fuori dalle scelte si ignora. Il periodo di serie è il mese di oggi, come la bozza.
     *
     * @return array{chi: ?string, area: ?string, azione: ?string, mese: string, cerca: ?string, condominio: ?int}
     */
    public static function filtri(Request $request, bool $delloStudio): array
    {
        $chi = (string) $request->query('chi', '');
        $mese = (string) $request->query('mese', '');

        return [
            'chi' => $chi === Autore::PROGRAMMA || ctype_digit($chi) ? $chi : null,
            'area' => array_key_exists((string) $request->query('area'), self::AREE) ? (string) $request->query('area') : null,
            'azione' => array_key_exists((string) $request->query('azione'), self::AZIONI) ? (string) $request->query('azione') : null,
            'mese' => $mese === self::TUTTI || preg_match('/^\d{4}-\d{2}$/', $mese) ? $mese : self::meseDiOggi(),
            'cerca' => trim((string) $request->query('cerca', '')) !== '' ? mb_substr(trim((string) $request->query('cerca')), 0, 100) : null,
            'condominio' => $delloStudio && ctype_digit((string) $request->query('condominio', '')) ? (int) $request->query('condominio') : null,
        ];
    }

    public static function meseDiOggi(): string
    {
        return Carbon::now((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m');
    }

    /** «Dicembre 2026», o «Tutti i mesi». */
    public static function nomeDelMese(string $mese): string
    {
        if ($mese === self::TUTTI) {
            return 'Tutti i mesi';
        }
        [$anno, $m] = explode('-', $mese);

        return ucfirst(self::MESI[(int) $m - 1]) . ' ' . $anno;
    }

    /**
     * Le righe per pagina che la tabella offre («Righe per pagina», come nelle altre tabelle): la prima è quella di serie, {@see LetturaDelRegistro::PER_PAGINA}.
     */
    public const RIGHE_PER_PAGINA = [20, 50, 100];

    /** La frase sotto il campo «Al» quando il giorno viene prima di quello di «Dal»: la stessa della finestra. */
    public const AL_PRIMA_DI_DAL = '«Al» viene prima di «Dal»: scegli un giorno uguale o successivo.';

    /** Le righe per pagina chieste nell'indirizzo (`per_page`), fra le tre offerte; un altro numero torna a quelle di serie. */
    public static function righePerPagina(Request $request): int
    {
        $chieste = (int) $request->query('per_page', 0);

        return in_array($chieste, self::RIGHE_PER_PAGINA, true) ? $chieste : LetturaDelRegistro::PER_PAGINA;
    }

    /**
     * I filtri della stampa e del suo conto: quelli della pagina ({@see filtri()}) e, se c'è, il periodo dal … al … della finestra (`dal` e `al`, giorni di
     * Roma `aaaa-mm-gg`). Con il periodo il mese non conta; senza, vale il mese come nella pagina. Un giorno che non esiste, o «Al» prima di «Dal», è un
     * errore sotto il suo campo.
     *
     * @return array{chi: ?string, area: ?string, azione: ?string, mese: string, cerca: ?string, condominio: ?int, dal: ?string, al: ?string}
     *
     * @throws ValidationException
     */
    public static function filtriDellaStampa(Request $request, bool $delloStudio): array
    {
        $giorno = ['nullable', 'string', 'date_format:Y-m-d'];
        $periodo = Validator::make(['dal' => $request->query('dal'), 'al' => $request->query('al')], ['dal' => $giorno, 'al' => $giorno],
            ['date_format' => 'Il giorno non è valido.', 'string' => 'Il giorno non è valido.'])->validate();
        $dal = $periodo['dal'] ?? null;
        $al = $periodo['al'] ?? null;
        if ($dal !== null && $al !== null && $al < $dal) {
            throw ValidationException::withMessages(['al' => self::AL_PRIMA_DI_DAL]);
        }
        $filtri = self::filtri($request, $delloStudio);

        return [...$filtri, 'mese' => $dal !== null || $al !== null ? self::TUTTI : $filtri['mese'], 'dal' => $dal, 'al' => $al];
    }

    /** Il periodo in testa alla stampa: «dal 01/01/2026 al 31/12/2026», «dal 01/01/2026», «fino al 31/12/2026». */
    public static function nomeDelPeriodo(?string $dal, ?string $al): string
    {
        $giorno = fn (string $g) => Carbon::createFromFormat('Y-m-d', $g)->format('d/m/Y');

        return match (true) {
            $dal !== null && $al !== null => 'dal ' . $giorno($dal) . ' al ' . $giorno($al),
            $dal !== null => 'dal ' . $giorno($dal),
            $al !== null => 'fino al ' . $giorno($al),
            default => 'Tutti i mesi',
        };
    }

    /** @return Builder<RegistroModifica> */
    public static function query(?int $condominioId, array $filtri): Builder
    {
        return LetturaDelRegistro::delCondominio($condominioId, array_filter([
            'chi' => $filtri['chi'], 'area' => $filtri['area'], 'azione' => $filtri['azione'],
            'mese' => $filtri['mese'] === self::TUTTI ? null : $filtri['mese'], 'cerca' => $filtri['cerca'], 'condominio' => $filtri['condominio'] ?? null,
            // Il periodo dal … al … della stampa, quando c'è ({@see filtriDellaStampa()}).
            'dal' => $filtri['dal'] ?? null, 'al' => $filtri['al'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Le righe per la tabella e per la stampa, con i condomini e «Apri» letti **per tipo, una volta**: senza, la stampa di 5.000 righe faceva due query
     * per riga prima di mPDF.
     *
     * @param iterable<RegistroModifica> $modelli
     * @param list<array<string, mixed>>|null $annullamento i ripristini che annullano righe ({@see LetturaDelRegistro::annullamento()}), o `null` per leggerli
     * @return list<array<string, mixed>>
     */
    public static function righe(iterable $modelli, ?array $annullamento = null): array
    {
        $modelli = collect($modelli)->values();
        $annullamento ??= LetturaDelRegistro::annullamento();
        $condomini = SigilloDelRegistro::condominiDelle($modelli->pluck('id')->map(fn ($id) => (int) $id)->all());
        $aperti = self::apriTutte($modelli->filter(fn (RegistroModifica $r) => self::conApri($r)));
        $firmate = FirmaDelRegistro::delleRighe($modelli, $condomini);

        return $modelli->map(function (RegistroModifica $r) use ($annullamento, $condomini, $aperti, $firmate) {
            $suoi = $condomini[(int) $r->id] ?? [];
            ksort($suoi);

            return self::riga($r, $annullamento, ['condomini' => $suoi, 'apri' => $aperti[(int) $r->id] ?? null, 'firmata' => $firmate[(int) $r->id] ?? false]);
        })->all();
    }

    /**
     * Una riga per la tabella e per la stampa: la riga della lettura (`perLaPagina`) con la «Storia» o «Apri», le parole, e `fuori_dal_programma` per una
     * riga senza la firma del programma (il badge «Aggiunta fuori dal programma»). Con `$letti` (da {@see righe()}) i condomini, «Apri» e la firma non si
     * rileggono.
     *
     * @param array{condomini: array<int, string>, apri: ?string, firmata: bool}|null $letti
     * @return array<string, mixed>
     */
    public static function riga(RegistroModifica $riga, ?array $annullamento, ?array $letti = null): array
    {
        $r = LetturaDelRegistro::perLaPagina($riga, $annullamento);
        $scheda = $r['scheda'];
        // «Apri» per la contabilità (la bozza: la fattura si apre, non si ripristina) e per un gesto (il piano generato, la comunicazione pubblicata dal
        // programma); «Storia» per le altre righe di una scheda. Senza una pagina da aprire, la «Storia».
        $apri = self::conApri($riga) ? ($letti === null ? self::apri($riga) : $letti['apri']) : null;
        $r['apri'] = $apri;
        $r['storia'] = $apri === null && $scheda['tipo'] !== null && SchedeDellaStoria::esiste((string) $scheda['tipo'])
            && ($scheda['id'] !== null || ! SchedeDellaStoria::conId((string) $scheda['tipo'])) ? ['tipo' => $scheda['tipo'], 'id' => $scheda['id']] : null;
        $r['azione_parola'] = self::AZIONI[$riga->azione] ?? $riga->azione;
        $r['area_parola'] = self::AREE[$riga->area] ?? $riga->area;
        $r['condomini'] = array_values($letti === null ? $riga->condomini() : $letti['condomini']);
        $r['nota'] = RipristinoDiUnCampo::notaDellArea($riga);
        $r['cambiato'] = self::cheCosaECambiato($r);
        $r['fuori_dal_programma'] = ! ($letti === null ? (FirmaDelRegistro::delleRighe([$riga])[(int) $riga->id] ?? false) : $letti['firmata']);

        return $r;
    }

    /** Se la riga ha «Apri» invece della «Storia»: la contabilità e i gesti. */
    private static function conApri(RegistroModifica $riga): bool
    {
        return $riga->area === 'contabilita' || $riga->azione === 'gesto';
    }

    /**
     * «Che cosa è cambiato» in una riga di testo, per la tabella e per la stampa: un dato «Telefono: 333 → 347», più dati «Email e PEC (2 dati)», un gesto
     * il suo riepilogo.
     */
    public static function cheCosaECambiato(array $r): string
    {
        $campi = $r['campi'] ?? [];
        // La cosa da fare dopo una delibera dice quale cosa e che cosa è successo («Crea il piano straordinario: fatta il 21/11/2026»), non «Stato e Fatta il».
        if ($r['azione'] === 'modificata' && ($r['oggetto']['tipo'] ?? null) === \App\Services\RegistroModifiche\Schede\CosaDaFareNelRegistro::TIPO
            && ($r['riepilogo'] ?? '') !== '') {
            return (string) $r['riepilogo'];
        }
        if ($r['azione'] === 'modificata' || $r['azione'] === 'ripristinata') {
            if (count($campi) === 1) {
                $c = $campi[0];

                return $c['etichetta'] . ': ' . ($c['prima'] ?? 'vuoto') . ' → ' . ($c['dopo'] ?? 'vuoto');
            }
            if (count($campi) > 1) {
                $nomi = array_map(fn ($c) => (string) $c['etichetta'], $campi);
                $testa = count($nomi) <= 3 ? implode(', ', array_slice($nomi, 0, -1)) . ' e ' . end($nomi)
                    : implode(', ', array_slice($nomi, 0, 2)) . ' e altri ' . (count($nomi) - 2);

                return $testa . ' (' . count($nomi) . ' dati)';
            }
        }
        $azione = self::AZIONI[$r['azione']] ?? '';
        if ($r['riepilogo'] !== null && $r['riepilogo'] !== '') {
            return in_array($r['azione'], ['creata', 'eliminata', 'collegata', 'scollegata'], true) ? $azione . ' · ' . $r['riepilogo'] : $r['riepilogo'];
        }
        if (count($campi) > 0) {
            return $azione . ' · ' . implode(', ', array_map(fn ($c) => $c['etichetta'] . ': ' . ($c['prima'] ?? 'vuoto') . ' → ' . ($c['dopo'] ?? 'vuoto'), $campi));
        }

        return $azione;
    }

    /** «Apri»: la pagina della cosa a cui la riga rimanda (la fattura, la scrittura, il piano, il passaggio, l'importazione), se c'è ancora. */
    public static function apri(RegistroModifica $riga): ?string
    {
        return self::apriTutte([$riga])[(int) $riga->id] ?? null;
    }

    /**
     * «Apri» di tante righe: le cose a cui rimandano si leggono per tipo, una query per tipo.
     *
     * @param iterable<RegistroModifica> $righe
     * @return array<int, ?string> riga => indirizzo
     */
    public static function apriTutte(iterable $righe): array
    {
        $bersagli = [];
        $righePerId = [];
        foreach ($righe as $riga) {
            $righePerId[(int) $riga->id] = $riga;
            $rimando = $riga->rimando;
            [$tipo, $id] = $rimando !== null && ! empty($rimando['id']) ? [(string) $rimando['tipo'], (int) $rimando['id']]
                : [$riga->oggetto_tipo, $riga->oggetto_id];
            if ($id !== null) {
                $bersagli[(int) $riga->id] = [$tipo, (int) $id];
            }
        }
        $perTipo = [];
        foreach ($bersagli as [$tipo, $id]) {
            $perTipo[$tipo][$id] = $id;
        }
        $lette = fn (string $tipo, \Closure $leggi) => isset($perTipo[$tipo]) ? $leggi(array_values($perTipo[$tipo])) : collect();
        $fatture = $lette('fattura_passiva', fn ($ids) => FatturaPassiva::query()->whereIn('id', $ids)->get(['id', 'condominio_id'])->keyBy('id'));
        $scritture = $lette('scrittura_contabile', fn ($ids) => ScritturaContabile::query()->whereIn('id', $ids)->get(['id', 'condominio_id'])->keyBy('id'));
        $piani = $lette('piano_rate', fn ($ids) => PianoRate::query()->whereIn('id', $ids)->get(['id', 'condominio_id', 'esercizio_id'])->keyBy('id'));
        $passaggi = $lette('passaggio', fn ($ids) => Subentro::query()->withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'condominio_id', 'immobile_id'])->keyBy('id'));
        $lotti = $lette('importazione', fn ($ids) => ImportBatch::query()->whereIn('id', $ids)->get(['id', 'uuid'])->keyBy('id'));
        $condomini = $lette('condominio', fn ($ids) => Condominio::query()->whereIn('id', $ids)->pluck('id')->flip());
        // Le assemblee (Coda 679): la delibera, il punto e la seduta aprono la pagina della loro assemblea, la delibera sul suo punto. La rotta nasce con le
        // pagine delle assemblee: finché non c'è, niente «Apri».
        $assemblee = $lette('assemblea', fn ($ids) => \App\Models\Assemblee\Assemblea::query()->whereIn('id', $ids)->get(['id', 'condominio_id'])->keyBy('id'));
        $delibere = $lette('delibera', fn ($ids) => \App\Models\Assemblee\Delibera::query()->whereIn('delibere.id', $ids)
            ->get(['id', 'condominio_id', 'assemblea_id', 'punto_id'])->keyBy('id'));
        $punti = $lette('punto', fn ($ids) => \App\Models\Assemblee\PuntoDellOrdineDelGiorno::query()->join('assemblee', 'assemblee.id', '=', 'assemblee_punti.assemblea_id')
            ->whereIn('assemblee_punti.id', $ids)->get(['assemblee_punti.id', 'assemblee_punti.assemblea_id', 'assemblee.condominio_id'])->keyBy('id'));
        $sedute = $lette('seduta', fn ($ids) => \App\Models\Assemblee\SedutaDellAssemblea::query()->join('assemblee', 'assemblee.id', '=', 'assemblee_sedute.assemblea_id')
            ->whereIn('assemblee_sedute.id', $ids)->get(['assemblee_sedute.id', 'assemblee_sedute.assemblea_id', 'assemblee.condominio_id'])->keyBy('id'));
        $paginaDellAssemblea = \Illuminate\Support\Facades\Route::has('admin.gestionale.assemblee.show');
        $assemblea = fn (?int $condominio, ?int $id, array $piu = []) => $paginaDellAssemblea && $condominio !== null && $id !== null
            ? route('admin.gestionale.assemblee.show', [$condominio, $id, ...$piu]) : null;

        $aperti = [];
        foreach ($bersagli as $rigaId => [$tipo, $id]) {
            try {
                $aperti[$rigaId] = match ($tipo) {
                    'fattura_passiva' => ($f = $fatture->get($id)) ? route('admin.gestionale.fatture.show', [$f->condominio_id, $f->id]) : null,
                    'scrittura_contabile' => ($c = $scritture->get($id)) ? route('admin.gestionale.scritture.show', [$c->condominio_id, $c->id]) : null,
                    'piano_rate' => ($p = $piani->get($id)) && $p->esercizio_id
                        ? route('admin.gestionale.esercizi.piani-rate.show', [$p->condominio_id, $p->esercizio_id, $p->id]) : null,
                    'passaggio' => ($s = $passaggi->get($id)) ? route('admin.gestionale.immobili.show', [$s->condominio_id, $s->immobile_id]) : null,
                    'importazione' => ($b = $lotti->get($id)) ? route('import.esito', $b->uuid) : null,
                    'comunicazione' => route('admin.comunicazioni.show', $id),
                    'segnalazione' => route('admin.segnalazioni.show', $id),
                    'condominio' => $condomini->has($id) ? route('admin.gestionale.struttura.index', $id) : null,
                    // Una riga sulle date o sul luogo (la seduta, i campi dell'assemblea che si scrivono nel passo 1) apre «Date e ordine del giorno».
                    'assemblea' => ($a = $assemblee->get($id)) ? $assemblea((int) $a->condominio_id, (int) $a->id,
                        self::sulleDate($righePerId[$rigaId] ?? null) ? ['scheda' => 'date'] : []) : null,
                    'delibera' => ($d = $delibere->get($id)) ? $assemblea((int) $d->condominio_id, (int) $d->assemblea_id, ['punto' => (int) $d->punto_id]) : null,
                    'punto' => ($pu = $punti->get($id)) ? $assemblea((int) $pu->condominio_id, (int) $pu->assemblea_id, ['punto' => (int) $pu->id]) : null,
                    'seduta' => ($se = $sedute->get($id)) ? $assemblea((int) $se->condominio_id, (int) $se->assemblea_id, ['scheda' => 'date']) : null,
                    default => null,
                };
            } catch (Throwable) {
                $aperti[$rigaId] = null;
            }
        }

        return $aperti;
    }

    /** I campi dell'assemblea che si scrivono nel passo «Date e ordine del giorno». */
    private const CAMPI_DELLE_DATE = ['data', 'luogo', 'modalita', 'piattaforma', 'consenso_videoconferenza_il', 'consenso_videoconferenza_modo', 'tabella_id', 'tipo',
        'ambito', 'palazzina_id', 'scala_id'];

    /** La riga dell'assemblea cambia un campo del passo delle date (o le sue sedute). */
    private static function sulleDate(?RegistroModifica $riga): bool
    {
        if ($riga === null || $riga->oggetto_tipo !== 'assemblea') {
            return false;
        }

        return collect((array) $riga->campi)->contains(fn ($c) => in_array($c['chiave'] ?? null, self::CAMPI_DELLE_DATE, true) || str_starts_with((string) ($c['chiave'] ?? ''), 'sedute'));
    }

    /** Le scelte dei menu: chi (le persone che hanno scritto righe qui e «Il programma»), le aree, le azioni, i mesi, i condomini. @return array<string, mixed> */
    public static function scelte(?int $condominioId, bool $delloStudio): array
    {
        $righe = DB::table('registro_modifiche')
            ->when($condominioId !== null, fn ($q) => $q->whereIn('id', DB::table('registro_modifiche_condomini')->where('condominio_id', $condominioId)->select('riga_id')));
        $persone = (clone $righe)->where('autore_tipo', Autore::PERSONA)->whereNotNull('autore_id')
            ->select('autore_id', DB::raw('MAX(autore_nome) as nome'))->groupBy('autore_id')->orderBy('nome')->get()
            ->map(fn ($p) => ['valore' => (string) $p->autore_id, 'nome' => (string) $p->nome])->all();
        $limiti = (clone $righe)->selectRaw('MIN(avvenuta_il) as prima, MAX(avvenuta_il) as ultima')->first();

        return [
            'chi' => [['valore' => '', 'nome' => 'Tutti'], ...$persone, ['valore' => Autore::PROGRAMMA, 'nome' => 'Il programma']],
            'aree' => [['valore' => '', 'nome' => 'Tutto'], ...array_map(fn ($k, $v) => ['valore' => $k, 'nome' => $v], array_keys(self::AREE), self::AREE)],
            'azioni' => [['valore' => '', 'nome' => 'Tutto'], ...array_map(fn ($k, $v) => ['valore' => $k, 'nome' => $v], array_keys(self::AZIONI), self::AZIONI)],
            'mesi' => self::mesi($limiti?->prima, $limiti?->ultima),
            'condomini' => $delloStudio ? [['valore' => '', 'nome' => 'Tutti i condomini'], ...Condominio::query()->orderBy('nome')->get(['id', 'nome'])
                ->map(fn ($c) => ['valore' => (string) $c->id, 'nome' => (string) $c->nome])->all()] : [],
        ];
    }

    /** I mesi del filtro, dal più recente: dal primo mese con una riga a oggi, più «Tutti i mesi». @return list<array{valore: string, nome: string}> */
    public static function mesi(?string $prima, ?string $ultima): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $oggi = Carbon::now($fuso)->startOfMonth();
        $dal = $prima === null ? $oggi->copy() : Carbon::parse($prima, 'UTC')->setTimezone($fuso)->startOfMonth();
        $al = $ultima === null ? $oggi->copy() : Carbon::parse($ultima, 'UTC')->setTimezone($fuso)->startOfMonth();
        $al = $al->greaterThan($oggi) ? $al : $oggi;
        $mesi = [];
        for ($m = $al->copy(); $m->greaterThanOrEqualTo($dal) && count($mesi) < 240; $m->subMonth()) {
            $mesi[] = ['valore' => $m->format('Y-m'), 'nome' => self::nomeDelMese($m->format('Y-m'))];
        }

        return [...$mesi, ['valore' => self::TUTTI, 'nome' => 'Tutti i mesi']];
    }

    /**
     * Le tre card in alto (standard 4, la bozza): il mese con quante modifiche e da quante persone; l'integrità con l'ultima verifica; la conservazione la
     * carica da sé il suo componente nella pagina.
     *
     * @return array<string, mixed>
     */
    public static function riepilogo(?int $condominioId, array $filtri): array
    {
        $delMese = self::query($condominioId, ['chi' => null, 'area' => null, 'azione' => null, 'mese' => $filtri['mese'], 'cerca' => null,
            'condominio' => $filtri['condominio'] ?? null]);
        $quante = (clone $delMese)->count();
        $persone = (clone $delMese)->reorder()->where('autore_tipo', Autore::PERSONA)->distinct()->count('autore_id');
        $programma = (clone $delMese)->reorder()->where('autore_tipo', Autore::PROGRAMMA)->exists();

        return [
            'mese' => ['nome' => self::nomeDelMese($filtri['mese']), 'modifiche' => $quante, 'frase' => self::fraseDiChi($persone, $programma)],
            'integrita' => self::integrita(VerificaDelRegistro::ultima()),
        ];
    }

    /** «da 2 persone e dal programma». */
    public static function fraseDiChi(int $persone, bool $programma): string
    {
        if ($persone === 0) {
            return $programma ? 'dal programma' : 'nessuna modifica';
        }
        $testo = 'da ' . ($persone === 1 ? '1 persona' : $persone . ' persone');

        return $programma ? $testo . ' e dal programma' : $testo;
    }

    /** La card «Integrità»: «Integro», «4.812 righe dal 01/11/2026 · verificato il 16/12 alle 10:10». @return array<string, mixed> */
    public static function integrita(?array $ultima): array
    {
        if ($ultima === null) {
            return ['stato' => 'mai', 'parola' => 'Non ancora verificato', 'frase' => '«Verifica l\'integrità» rilegge la catena delle righe.'];
        }
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $il = Carbon::parse($ultima['verificata_il'])->setTimezone($fuso);
        $dal = $ultima['dal'] !== null ? ' dal ' . Carbon::parse($ultima['dal'])->setTimezone($fuso)->format('d/m/Y') : '';
        $righe = number_format((int) $ultima['righe'], 0, ',', '.') . ((int) $ultima['righe'] === 1 ? ' riga' : ' righe');
        $quando = 'verificato il ' . $il->format('d/m') . ' alle ' . $il->format('H:i');

        return $ultima['integro']
            ? ['stato' => 'integro', 'parola' => 'Integro', 'frase' => $righe . $dal . ' · ' . $quando]
            : ['stato' => 'non_torna', 'parola' => (int) $ultima['righe_che_non_tornano'] === 1 ? 'Una riga non torna'
                : $ultima['righe_che_non_tornano'] . ' righe non tornano', 'frase' => $righe . $dal . ' · ' . $quando];
    }
}
