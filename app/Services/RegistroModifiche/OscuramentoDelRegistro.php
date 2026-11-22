<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\Anagrafica;
use App\Models\Fornitore;
use App\Models\RegistroModifica;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Oscura i suoi dati nel registro» (§10 del progetto, P6, P9, P14, decisione 518 (2), bozza scena 4): la richiesta di cancellazione di una persona, che il
 * registro non può togliere senza togliere la riga. Solo l'amministratore, con il perché; l'oscuramento è a sua volta una riga del registro e non si annulla.
 *
 * **Le righe**: quelle con la persona come oggetto o fra i soggetti (`registro_modifiche_soggetti`), **senza quelle annullate da un ripristino** (P14: il
 * contatore degli `id` riparte dalla copia, l'anagrafica 57 di dopo non è quella di prima) e senza le righe che non hanno più niente di suo.
 *
 * **Che cosa diventa «oscurato il 12/03/2027»** (P6):
 *  - nelle righe sue, i campi che la scheda dichiara **dati della persona** (`DATI_DELLA_PERSONA`: nome, recapiti, codice fiscale, documento, IBAN,
 *    indirizzo, note), quando la riga è della sua scheda o di una scheda legata solo a lei (la titolarità, il conto corrente, l'accordo): i due testi e i due
 *    valori grezzi;
 *  - in ogni altro testo (gli altri campi, il riepilogo, il perché) **solo i testi uguali ai suoi dati** (il nome, le email, il codice fiscale, i telefoni,
 *    anche quelli di prima letti dalle sue righe): «da Mario Rossi a oscurato il …», «Mario Rossi, oscurato il …». Importi, date, stati e le altre persone
 *    restano.
 * Restano anche chi, quando e su che cosa: l'intestazione della riga è nella catena delle impronte, e l'oscuramento cambia solo i valori (`campi`, `riepilogo`,
 * `perche`) con `oscurata_il` e `oscurata_da` = la riga «oscuramento», che elenca le righe e i campi toccati (`lotto.righe`, `lotto.campi`) e tiene, per
 * ognuna, l'impronta dei valori dopo l'oscuramento (`lotto.impronte`). L'impronta dei valori della riga non si ricalcola (è nella catena e nella firma):
 * «Verifica l'integrità» controlla che la riga «oscuramento» la nomini e che i valori di adesso tornino con l'impronta scritta per lei.
 *
 * **Le altre righe** (P9): una ricerca dei suoi dati fra le righe che non sono sue; l'amministratore le aggiunge se lo sceglie, e su quelle cambiano solo i
 * testi uguali ai suoi dati.
 *
 * È uno dei servizi che scrivono su righe già nate (con `DB::table`: il modello lo rifiuta).
 */
final class OscuramentoDelRegistro
{
    /** I tipi di persona che si oscurano: le schede delle persone (anagrafica) e dei fornitori, anche persone fisiche (P9). */
    public const TIPI = ['anagrafica' => Anagrafica::class, 'fornitore' => Fornitore::class];

    /** I suoi dati che si cercano nei testi: quelli che dicono chi è. */
    private const DATI_CHE_LA_DICONO = [
        'anagrafica' => ['nome', 'email', 'email_secondaria', 'pec', 'codice_fiscale', 'telefono', 'cellulare', 'numero_documento'],
        'fornitore' => ['ragione_sociale', 'partita_iva', 'codice_fiscale', 'email', 'pec', 'telefono', 'cellulare', 'fax', 'iban_principale'],
    ];

    /** Un testo più corto non si cerca (un «Ida» o un «333» troverebbero di tutto). */
    public const LUNGHEZZA_MINIMA = 5;

    /** Quante righe l'anteprima elenca (il conteggio è sempre intero). */
    public const MASSIMO_NELL_ELENCO = 200;

    /** Quante altre righe trovate con la ricerca, al più. */
    public const MASSIMO_ALTRE = 100;

    /**
     * L'anteprima della finestra: chi è, quante righe, dal … al …, le righe, e le altre righe che contengono i suoi dati.
     *
     * @return array<string, mixed>
     */
    public static function anteprima(string $tipo, int $id): array
    {
        SigilloDelRegistro::sigillaSenzaFermare();
        $persona = self::persona($tipo, $id);
        $oggi = self::oggi();
        // I ripristini che annullano righe, letti una volta per tutta la ricerca: la ricerca delle altre righe ne guarda una per volta, e su un registro
        // di anni sarebbe una lettura per ogni riga candidata.
        $annullamento = LetturaDelRegistro::annullamento();
        $testi = self::testiDellaPersona($tipo, $id, $persona['modello'], $annullamento);
        $sue = self::righeSue($tipo, $id, $testi, $oggi, $annullamento);
        $altre = self::altreRighe($tipo, $id, $testi, $oggi, array_keys($sue), $annullamento);

        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $giorni = collect($sue)->map(fn ($o) => $o['riga']->avvenuta_il->copy()->setTimezone($fuso));

        return [
            'soggetto' => ['tipo' => $tipo, 'id' => $id, 'nome' => $persona['nome'], 'parola' => $persona['parola'], 'condomini' => $persona['condomini'],
                'descrizione' => $persona['modello'] === null ? $persona['parola'] . ' che non c\'è più nella rubrica' : self::descrizione($tipo, $persona['modello'])],
            'righe' => [
                'quante' => count($sue),
                'dal' => $giorni->min()?->format('d/m/Y'),
                'al' => $giorni->max()?->format('d/m/Y'),
                'elenco' => array_values(array_map(fn ($o) => self::perLElenco($o['riga'], $o['toccati']),
                    array_slice($sue, 0, self::MASSIMO_NELL_ELENCO, true))),
            ],
            'altre' => [
                'quante' => count($altre),
                'elenco' => array_values(array_map(fn ($o) => self::perLElenco($o['riga'], $o['toccati']) + ['contiene' => $o['contiene']], $altre)),
            ],
            'oggi' => $oggi,
            'sostituzione' => self::sostituzione($oggi),
            // Un file di un ripristino rimasto ha le righe di prima, con i dati di prima della persona: l'oscuramento non lo raggiunge, e la finestra lo dice.
            'file_rimasti' => array_map(fn ($f) => 'C\'è un file del ripristino del ' . $f['del']->format('d/m/Y') . ' con le righe di prima: si toglie da solo il '
                . $f['si_toglie_il']->format('d/m/Y') . '.', app(\App\Services\Restore\RegistroNelRipristino::class)->fileRimasti()),
        ];
    }

    /**
     * Oscura i suoi dati nelle righe sue e nelle altre scelte dall'amministratore. Restituisce la riga «oscuramento».
     *
     * @param list<int> $altreScelte gli id delle altre righe trovate con la ricerca che l'amministratore ha scelto
     * @throws ValidationException se non c'è niente da oscurare
     */
    public static function oscura(string $tipo, int $id, string $perche, array $altreScelte = []): RegistroModifica
    {
        SigilloDelRegistro::sigillaSenzaFermare();

        return DB::transaction(function () use ($tipo, $id, $perche, $altreScelte) {
            $persona = self::persona($tipo, $id);
            $oggi = self::oggi();
            $annullamento = LetturaDelRegistro::annullamento();
            $testi = self::testiDellaPersona($tipo, $id, $persona['modello'], $annullamento);
            $sue = self::righeSue($tipo, $id, $testi, $oggi, $annullamento);
            $altre = $altreScelte === [] ? [] : array_intersect_key(self::altreRighe($tipo, $id, $testi, $oggi, array_keys($sue), $annullamento, PHP_INT_MAX),
                array_flip(array_map('intval', $altreScelte)));
            $tutte = $sue + $altre;
            if ($tutte === []) {
                throw ValidationException::withMessages(['perche' => 'Nel registro non ci sono righe con i suoi dati da oscurare.']);
            }
            ksort($tutte);

            $condomini = SigilloDelRegistro::condominiDelle(array_keys($tutte));
            $tuttiICondomini = [];
            foreach ($condomini as $perRiga) {
                $tuttiICondomini += $perRiga;
            }

            $quante = count($tutte);
            $oscuramento = RegistroModifiche::scrivi([
                'area' => 'persone',
                'oggetto_tipo' => $tipo,
                'oggetto_id' => $id,
                'oggetto_nome' => $persona['oggetto_nome'],
                'azione' => 'oscuramento',
                'riepilogo' => ($quante === 1 ? 'Oscurati i suoi dati in 1 riga del registro' : 'Oscurati i suoi dati in ' . $quante . ' righe del registro')
                    . ($altre === [] ? '.' : ', ' . count($altre) . ' scelte fra le righe di altre schede.'),
                'perche' => trim($perche),
                'lotto' => [
                    'righe' => array_keys($tutte),
                    'campi' => array_map(fn ($o) => $o['toccati'], $tutte),
                    // L'impronta dei valori di ogni riga dopo l'oscuramento: la verifica la confronta con i valori di adesso, così importi, date e altre
                    // persone, che restano, restano anche protetti. Sta nei valori di questa riga, quindi nella catena e nella sua firma.
                    'impronte' => array_map(fn ($o) => ImprontaDelRegistro::deiValori(['campi' => $o['campi'], 'riepilogo' => $o['riepilogo'],
                        'perche' => $o['perche'], 'rimando' => $o['riga']->rimando, 'lotto' => $o['riga']->lotto]), $tutte),
                    'scelte_dalla_ricerca' => array_keys($altre),
                    'oscurato_il' => $oggi,
                ],
                'condomini' => array_slice($tuttiICondomini, 0, 50, true),
            ]);

            $adesso = now()->utc()->format('Y-m-d H:i:s');
            foreach ($tutte as $rigaId => $o) {
                DB::table('registro_modifiche')->where('id', $rigaId)->update([
                    'campi' => $o['campi'] === null ? null : json_encode($o['campi']),
                    'riepilogo' => $o['riepilogo'],
                    'perche' => $o['perche'],
                    'oscurata_il' => $adesso,
                    'oscurata_da' => $oscuramento->id,
                ]);
            }

            return $oscuramento;
        });
    }

    /** «oscurato il 12/03/2027». */
    public static function sostituzione(string $giorno): string
    {
        return 'oscurato il ' . $giorno;
    }

    /**
     * La persona: il nome, la parola della scheda, i condomini, e la riga dell'intestazione dell'oscuramento («Anagrafica di Ida Romano»). Una persona
     * eliminata si oscura lo stesso, con il nome letto dalla sua ultima riga.
     *
     * @return array{modello: ?\Illuminate\Database\Eloquent\Model, nome: string, parola: string, condomini: string, oggetto_nome: string}
     */
    private static function persona(string $tipo, int $id): array
    {
        $classe = self::TIPI[$tipo] ?? null;
        abort_if($classe === null, 404);
        $modello = $classe::query()->find($id);
        $descrizione = CatalogoDelRegistro::descrizionePerTipo($tipo);
        $parola = (string) ($descrizione?->dichiarazione()['parola'] ?? ucfirst($tipo));
        if ($modello !== null) {
            $nome = (string) ($tipo === 'fornitore' ? $modello->getAttribute('ragione_sociale') : $modello->getAttribute('nome'));

            return ['modello' => $modello, 'nome' => $nome, 'parola' => $parola,
                'condomini' => implode(', ', $descrizione?->condomini($modello) ?? []),
                'oggetto_nome' => $descrizione?->nome($modello) ?? ($parola . ' ' . $nome)];
        }

        $ultima = RegistroModifica::query()->where('oggetto_tipo', $tipo)->where('oggetto_id', $id)->orderByDesc('id')->first();
        abort_if($ultima === null, 404);

        return ['modello' => null, 'nome' => $ultima->oggetto_nome, 'parola' => $parola, 'condomini' => '', 'oggetto_nome' => $ultima->oggetto_nome];
    }

    /**
     * La riga sotto il titolo della finestra (scelta del 19/11/2026): la compone il programma con ciò che sa, così non si chiede a chi oscura di
     * descrivere la persona, e il perché resta nel
     * campo obbligatorio. Per una persona: «Condòmina di Via Roma 12, Int. 7» se è titolare; «Ex condòmino: ha venduto l'Int. 11 il 15/01/2027»
     * dall'ultima titolarità chiusa (con il passaggio che l'ha chiusa); altrimenti i condomini a cui è collegata. Per un fornitore i condomini dove ha
     * fatture (decisione 557). Il genere delle parole si legge dal codice fiscale (il giorno di nascita più 40 per le donne); senza, il maschile.
     */
    private static function descrizione(string $tipo, \Illuminate\Database\Eloquent\Model $modello): string
    {
        $descrizione = CatalogoDelRegistro::descrizionePerTipo($tipo);
        if ($tipo === 'fornitore') {
            $condomini = array_values($descrizione?->condomini($modello) ?? []);

            return $condomini === [] ? 'Fornitore senza fatture nei condomini' : 'Fornitore di ' . self::elenco($condomini);
        }

        $donna = self::donna((string) $modello->getAttribute('codice_fiscale'));
        $oggi = Carbon::now()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d');
        $righe = \App\Models\TitolaritaImmobile::query()->with('immobile.condominio')->where('anagrafica_id', $modello->getKey())
            ->orderBy('data_inizio')->orderBy('id')->get();
        $attive = $righe->filter(fn ($r) => $r->attivo !== false && ($r->data_fine === null || $r->data_fine->format('Y-m-d') >= $oggi))->values();
        if ($attive->isNotEmpty()) {
            $prima = $attive->first();
            $ruolo = $prima->tipologia === 'inquilino' ? ($donna ? 'Inquilina' : 'Inquilino') : ($donna ? 'Condòmina' : 'Condòmino');
            $testo = $ruolo . ' di ' . ($prima->immobile?->condominio?->nome ?? 'un condominio') . ', ' . ($prima->immobile?->etichetta ?? 'un\'unità');
            $altre = $attive->count() - 1;

            return $testo . match (true) {
                $altre === 0 => '',
                $altre === 1 => ', e di un\'altra unità',
                default => ", e di altre {$altre} unità",
            };
        }

        $chiusa = $righe->filter(fn ($r) => $r->data_fine !== null)->sortByDesc(fn ($r) => $r->data_fine->format('Y-m-d') . sprintf('%012d', $r->id))->first();
        if ($chiusa !== null) {
            $ex = $chiusa->tipologia === 'inquilino' ? ($donna ? 'Ex inquilina' : 'Ex inquilino') : ($donna ? 'Ex condòmina' : 'Ex condòmino');
            $unita = (string) ($chiusa->immobile?->etichetta ?? '');
            $lUnita = $unita === '' ? 'l\'unità' : (preg_match('/^(Int\.|[aeiouAEIOU])/u', $unita) === 1 ? 'l\'' . $unita : 'l\'unità ' . $unita);
            $passaggio = $chiusa->passaggioCheLaChiude();
            $giorno = $passaggio?->decorrenza !== null ? Carbon::parse($passaggio->decorrenza)->format('d/m/Y') : $chiusa->data_fine->format('d/m/Y');

            return match ($passaggio?->tipo_passaggio) {
                'vendita' => "{$ex}: ha venduto {$lUnita} il {$giorno}",
                'successione' => "{$ex}: dal {$giorno} {$lUnita} è degli eredi",
                'fine_locazione' => "{$ex}: la locazione dell" . substr($lUnita, 1) . " è finita il {$giorno}",
                default => "{$ex}: titolare dell" . substr($lUnita, 1) . ' fino al ' . $chiusa->data_fine->format('d/m/Y'),
            };
        }

        $condomini = array_values($descrizione?->condomini($modello) ?? []);

        return $condomini === [] ? 'Persona senza condomini collegati' : 'Persona collegata a ' . self::elenco($condomini);
    }

    /** «Via Roma 12», «Via Roma 12 e Corso Italia 3», tre nomi con la virgola e la «e», e oltre tre «…, … , … e altri 2». @param list<string> $nomi */
    private static function elenco(array $nomi): string
    {
        if (count($nomi) > 3) {
            return implode(', ', array_slice($nomi, 0, 3)) . ' e altri ' . (count($nomi) - 3);
        }
        $ultimo = array_pop($nomi);

        return $nomi === [] ? (string) $ultimo : implode(', ', $nomi) . ' e ' . $ultimo;
    }

    /** Il codice fiscale dice se è una donna: il giorno di nascita (posizioni 10 e 11, con le lettere dell'omocodia) è più 40. */
    private static function donna(string $codiceFiscale): bool
    {
        $cf = strtoupper(trim($codiceFiscale));
        if (strlen($cf) !== 16) {
            return false;
        }
        $giorno = strtr(substr($cf, 9, 2), 'LMNPQRSTUV', '0123456789');

        return ctype_digit($giorno) && (int) $giorno > 40;
    }

    private static function oggi(): string
    {
        return Carbon::now()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y');
    }

    /**
     * I suoi dati come testi da cercare: quelli di adesso sulla scheda e quelli di prima e di dopo nelle righe della sua scheda (un'email cambiata si cerca
     * anche nella forma vecchia). Dal più lungo, così un testo che ne contiene un altro si sostituisce intero.
     *
     * @param list<array<string, mixed>> $annullamento i ripristini che annullano righe ({@see LetturaDelRegistro::annullamento()})
     * @return list<string>
     */
    private static function testiDellaPersona(string $tipo, int $id, ?\Illuminate\Database\Eloquent\Model $modello, array $annullamento): array
    {
        $chiavi = self::DATI_CHE_LA_DICONO[$tipo] ?? [];
        $testi = [];
        if ($modello !== null) {
            foreach ($chiavi as $chiave) {
                $testi[] = (string) $modello->getAttribute($chiave);
            }
        }
        $sue = RegistroModifica::query()->where('oggetto_tipo', $tipo)->where('oggetto_id', $id)->whereNotNull('campi')->get(['id', 'campi', 'sequenza']);
        foreach ($sue as $riga) {
            if (LetturaDelRegistro::eAnnullata($riga, $annullamento)) {
                continue;
            }
            foreach ($riga->campi ?? [] as $campo) {
                if (in_array($campo['chiave'] ?? null, $chiavi, true) && ! ($campo['segreto'] ?? false)) {
                    $testi[] = (string) ($campo['prima'] ?? '');
                    $testi[] = (string) ($campo['dopo'] ?? '');
                }
            }
        }
        $testi = array_values(array_unique(array_filter(array_map('trim', $testi),
            fn ($t) => mb_strlen($t) >= self::LUNGHEZZA_MINIMA && ! str_starts_with($t, 'oscurato il '))));
        usort($testi, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $testi;
    }

    /**
     * Le righe sue (oggetto o soggetto), senza le annullate e senza quelle che non hanno più niente di suo, con i valori oscurati.
     *
     * @param list<string> $testi
     * @return array<int, array{riga: RegistroModifica, campi: ?array, riepilogo: ?string, perche: ?string, toccati: list<string>}>
     */
    private static function righeSue(string $tipo, int $id, array $testi, string $oggi, array $annullamento): array
    {
        $righe = RegistroModifica::query()
            ->where(fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('oggetto_tipo', $tipo)->where('oggetto_id', $id))
                ->orWhereIn('id', DB::table('registro_modifiche_soggetti')->where('soggetto_tipo', $tipo)->where('soggetto_id', $id)->select('riga_id')))
            ->where('azione', '!=', 'oscuramento')
            ->orderBy('id')->get();

        return self::oscurate($righe, $tipo, $id, $testi, $oggi, true, $annullamento);
    }

    /**
     * Le altre righe che contengono un suo testo (P9): fra quelle che non sono sue, cercate nel riepilogo, nel perché e nei campi.
     *
     * @param list<string> $testi
     * @param list<int> $escluse
     * @return array<int, array{riga: RegistroModifica, campi: ?array, riepilogo: ?string, perche: ?string, toccati: list<string>, contiene: string}>
     */
    private static function altreRighe(string $tipo, int $id, array $testi, string $oggi, array $escluse, array $annullamento,
        int $quante = self::MASSIMO_ALTRE): array
    {
        if ($testi === []) {
            return [];
        }
        $query = RegistroModifica::query()->where('azione', '!=', 'oscuramento')
            ->where(function (Builder $q) use ($testi) {
                foreach ($testi as $testo) {
                    // Nel JSON dei campi un testo con lettere accentate può essere scritto con le sequenze di escape di json_encode (SQLite; MySQL
                    // le scrive per esteso): si cerca anche in quella forma. Niente caratteri di fuga: un `_` o un `%` del testo trovano qualcosa in
                    // più, e la conferma qui sotto, in PHP, tiene solo le righe che hanno davvero il testo.
                    foreach (array_unique([$testo, trim((string) json_encode($testo), '"')]) as $forma) {
                        $simile = '%' . $forma . '%';
                        $q->orWhere('riepilogo', 'like', $simile)->orWhere('perche', 'like', $simile)->orWhere('campi', 'like', $simile);
                    }
                }
            });
        if ($escluse !== []) {
            $query->whereNotIn('id', $escluse);
        }

        $trovate = [];
        foreach ($query->lazyByIdDesc(500) as $riga) {
            $o = self::oscurate(new Collection([$riga]), $tipo, $id, $testi, $oggi, false, $annullamento);
            if ($o === []) {
                continue;
            }
            $o = reset($o);
            $o['contiene'] = self::primoTestoContenuto($riga, $testi);
            $trovate[(int) $riga->id] = $o;
            if (count($trovate) >= $quante) {
                break;
            }
        }
        ksort($trovate);

        return $trovate;
    }

    /**
     * I valori oscurati delle righe, senza quelle che non cambiano (e senza le annullate da un ripristino).
     *
     * @param Collection<int, RegistroModifica> $righe
     * @param list<string> $testi
     * @return array<int, array{riga: RegistroModifica, campi: ?array, riepilogo: ?string, perche: ?string, toccati: list<string>}>
     */
    private static function oscurate(Collection $righe, string $tipo, int $id, array $testi, string $oggi, bool $sue, array $annullamento): array
    {
        $soggettiPer = $sue ? SigilloDelRegistro::soggettiDelle($righe->pluck('id')->map(fn ($v) => (int) $v)->all()) : [];
        $sostituto = self::sostituzione($oggi);
        $fatte = [];
        foreach ($righe as $riga) {
            if (LetturaDelRegistro::eAnnullata($riga, $annullamento)) {
                continue;
            }
            $datiInteri = [];
            if ($sue) {
                $soggetti = $soggettiPer[(int) $riga->id] ?? [];
                $suaScheda = $riga->oggetto_tipo === $tipo && (int) $riga->oggetto_id === $id;
                $soloLei = $soggetti !== [] && collect($soggetti)->every(fn ($s) => $s[0] === $tipo && (int) $s[1] === $id);
                if ($suaScheda || $soloLei) {
                    $datiInteri = CatalogoDelRegistro::descrizionePerTipo((string) $riga->oggetto_tipo)?->dichiarazione()['dati_della_persona'] ?? [];
                }
            }

            $toccati = [];
            $campi = $riga->campi;
            foreach ($campi ?? [] as $i => $campo) {
                if ($campo['segreto'] ?? false) {
                    continue;
                }
                $nuovo = $campo;
                if (in_array($campo['chiave'] ?? null, $datiInteri, true)) {
                    foreach (['prima', 'dopo'] as $lato) {
                        if ($campo[$lato] !== null && $campo[$lato] !== '' && $campo[$lato] !== $sostituto) {
                            $nuovo[$lato] = $sostituto;
                        }
                    }
                    $nuovo['valore_prima'] = null;
                    $nuovo['valore_dopo'] = null;
                } else {
                    foreach (['prima', 'dopo', 'valore_prima', 'valore_dopo'] as $lato) {
                        if (is_string($campo[$lato] ?? null)) {
                            $nuovo[$lato] = self::sostituisci($campo[$lato], $testi, $sostituto);
                        }
                    }
                }
                if ($nuovo !== $campo) {
                    $campi[$i] = $nuovo;
                    $toccati[] = (string) ($campo['chiave'] ?? $i);
                }
            }
            $riepilogo = $riga->riepilogo === null ? null : self::sostituisci($riga->riepilogo, $testi, $sostituto);
            $perche = $riga->perche === null ? null : self::sostituisci($riga->perche, $testi, $sostituto);
            if ($riepilogo !== $riga->riepilogo) {
                $toccati[] = 'riepilogo';
            }
            if ($perche !== $riga->perche) {
                $toccati[] = 'perche';
            }
            if ($toccati === []) {
                continue;
            }
            $fatte[(int) $riga->id] = ['riga' => $riga, 'campi' => $campi, 'riepilogo' => $riepilogo, 'perche' => $perche, 'toccati' => $toccati];
        }

        return $fatte;
    }

    /** Ogni suo testo nel testo dato diventa «oscurato il …», senza guardare le maiuscole (un'email scritta in un altro modo è la stessa). */
    private static function sostituisci(string $testo, array $testi, string $sostituto): string
    {
        foreach ($testi as $suo) {
            $testo = (string) preg_replace('/' . preg_quote($suo, '/') . '/iu', $sostituto, $testo);
        }

        return $testo;
    }

    /** Il primo dei suoi testi che la riga contiene, per dire all'amministratore perché la ricerca l'ha trovata. */
    private static function primoTestoContenuto(RegistroModifica $riga, array $testi): string
    {
        $tutto = mb_strtolower(implode(' ', [(string) $riga->riepilogo, (string) $riga->perche,
            ...collect($riga->campi ?? [])->flatMap(fn ($c) => [(string) ($c['prima'] ?? ''), (string) ($c['dopo'] ?? '')])->all()]));
        foreach ($testi as $suo) {
            if (str_contains($tutto, mb_strtolower($suo))) {
                return $suo;
            }
        }

        return '';
    }

    /** Una riga come la elenca la finestra: quando, su che cosa, che cosa è successo, che cosa si oscura. @param list<string> $toccati */
    private static function perLElenco(RegistroModifica $riga, array $toccati): array
    {
        $etichette = collect($riga->campi ?? [])->mapWithKeys(fn ($c) => [(string) ($c['chiave'] ?? '') => (string) ($c['etichetta'] ?? '')])->all();

        return [
            'id' => (int) $riga->id,
            'quando' => $riga->avvenuta_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y H:i'),
            'oggetto' => $riga->oggetto_nome,
            'azione' => $riga->azione,
            'chi' => $riga->autore_nome,
            'cosa' => array_values(array_map(fn ($t) => match ($t) {
                'riepilogo' => 'Riepilogo',
                'perche' => 'Perché',
                default => $etichette[$t] ?? $t,
            }, $toccati)),
        ];
    }
}
