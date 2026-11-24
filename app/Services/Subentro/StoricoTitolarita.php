<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Subentro;

use App\Actions\Subentro\AnnullaConguaglioAction;
use App\Helpers\DateHelper;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use Carbon\CarbonImmutable;

/**
 * «Chi ha avuto questa unità»: lo storico della titolarità come **frasi**, non come righe di database
 * (§6.5 di `docs/pertinenze_vendita_locazione.md`, livello 2).
 *
 * Righe raggruppate per tipo di diritto — proprietà, usufrutto, locazione — e dentro ogni gruppo dalla
 * più recente; date a parole, durata calcolata, mai «01/05/2026 → 31/12/9999», nessuna colonna
 * «attivo». Il dettaglio tecnico (id, valori grezzi) il pannello lo tiene in un accordion. È il
 * tracciato della visura catastale storica, che l'amministratore sa già leggere.
 *
 * Il conteggio dei passaggi è il numero dei periodi chiusi: ogni `data_fine` è un passaggio, anche
 * quello registrato prima che esistesse `subentri` (S5). Quando la riga è agganciata a un passaggio
 * registrato, si aggiungono titolo e copia autentica.
 */
class StoricoTitolarita
{
    private const DIRITTI = [
        'proprietario' => 'Proprietà',
        'nuda_proprietario' => 'Proprietà',
        'usufruttuario' => 'Usufrutto',
        'inquilino' => 'Locazione',
    ];

    private const ORDINE_DIRITTI = ['Proprietà' => 0, 'Usufrutto' => 1, 'Locazione' => 2, 'Altro' => 3];

    public function __construct(private readonly FrasiObbligati $frasiObbligati = new FrasiObbligati())
    {
    }

    /** @return array{passaggi: int, righe: list<array<string, mixed>>, gruppi: list<array{diritto: string, righe: list<array<string, mixed>>}>, subentri: list<array<string, mixed>>} */
    public function perImmobile(Immobile $immobile): array
    {
        $oggi = DateHelper::oggiUtenteImmutable();
        $titolarita = $immobile->titolarita()->with('anagrafica')->get();
        $subentri = Subentro::with('padre')->where('immobile_id', $immobile->id)->get();

        $righe = $titolarita
            ->map(fn (TitolaritaImmobile $t) => $this->riga($t, $oggi, $subentri))
            ->sortBy([
                fn ($a, $b) => (self::ORDINE_DIRITTI[$a['diritto']] ?? 9) <=> (self::ORDINE_DIRITTI[$b['diritto']] ?? 9),
                fn ($a, $b) => strcmp($b['data_inizio'] ?? '', $a['data_inizio'] ?? ''),
            ])
            ->values();

        $gruppi = $righe->groupBy('diritto')
            ->map(fn ($r, $diritto) => ['diritto' => $diritto, 'righe' => $r->values()->all()])
            ->values()
            ->all();

        // I passaggi si contano dai fatti registrati (Fase 1-bis, S8-33): le righe chiuse sono un ripiego per lo
        // storico importato prima della beta.31, dove la riga chiusa è l'unica traccia. Un inizio locazione non
        // chiude nessuna riga ed è un passaggio; un'estinzione con due nudi chiude tre righe ed è un passaggio solo.
        $passaggiRegistrati = $this->passaggiRegistrati($immobile);
        $periodiChiusi = $titolarita->whereNotNull('data_fine')->count();

        // Un passaggio annullato (beta.37) resta in elenco ma non si conta: il pulsante dice quanti passaggi valgono. I passaggi registrati insieme
        // (decisione 705) sono una voce sola e si contano uno per persona: sono i passaggi che il programma ha scritto, come dice il badge.
        $attivi = (int) array_sum(array_map(fn (array $p) => $p['annullato'] ? 0 : (! empty($p['insieme']) ? count($p['insieme']['passaggi']) : 1), $passaggiRegistrati));

        return [
            'passaggi' => $attivi > 0 ? $attivi : $periodiChiusi,
            'periodi_chiusi' => $periodiChiusi,
            'righe' => $righe->all(),
            'gruppi' => $gruppi,
            'subentri' => $passaggiRegistrati,
        ];
    }

    /**
     * I passaggi registrati sull'unità (S6, voce 7), **uno per passaggio** e non per riga: il vademecum «chi
     * resta obbligato» ricalcolato dai fatti (`FrasiObbligati::daSubentro`), lo stato del conguaglio e la
     * copia autentica da poter registrare dopo. Solo i passaggi padre: quelli delle pertinenze sono figli e
     * compaiono come elenco dentro il loro padre. Dal più recente.
     *
     * Decisione 705 (1.12.0-beta.14): i passaggi registrati insieme (più persone che escono con lo stesso atto) sono **una voce sola**
     * ({@see insiemiInUnaVoce()}), al posto del primo; annullati insieme, restano insieme.
     *
     * @return list<array<string, mixed>>
     */
    private function passaggiRegistrati(Immobile $immobile): array
    {
        // Anche gli annullati (beta.37): restano nello storico, annullati, e sono l'unico posto dove si vedono.
        $annullamento = app(\App\Actions\Subentro\AnnullaPassaggioAction::class);

        $passaggi = Subentro::conAnnullati()->with(['uscente', 'entrante', 'pertinenze' => fn ($q) => $q->conAnnullati(), 'pertinenze.immobile', 'saldi', 'pertinenze.saldi', 'condominio', 'immobile', 'annullatoDa:id,name'])
            ->where('immobile_id', $immobile->id)
            ->whereNull('subentro_padre_id')
            ->orderByDesc('decorrenza')->orderByDesc('id')
            ->get();

        $voci = $passaggi
            ->map(function (Subentro $s) use ($annullamento) {
                $saldi = $s->saldi->merge($s->pertinenze->flatMap->saldi);
                // 1.11.0-beta.44: le righe dell'arretrato di una successione stanno con quelle del conguaglio, ma sono un'altra cosa.
                $righeArretrato = $saldi->whereIn('id', $s->saldiDellArretrato());
                $saldi = $saldi->whereNotIn('id', $s->saldiDellArretrato());
                $motivo = $s->annullato() ? null : $annullamento->motivoBlocco($s);
                $conguaglio = match (true) {
                    $s->conguaglioAnnullato() => 'annullato',
                    // Decisione 72 (1.11.0-beta.48): prima di «rinunciato», che è vero anche per lui. Dalla .50 anche la locazione
                    // (decisione 77): la frase dell'annullamento dice di quale passaggio è (`Subentro::conguaglioInParole`).
                    $s->conguaglioNonScritto() => 'non_scritto',
                    $s->conguaglioRinunciato() => 'rinunciato',
                    $saldi->isNotEmpty() => 'proposto',
                    default => 'nessuno',
                };
                // «Annulla il conguaglio» si offre dove la rotta lo accetta: non con l'arretrato agli eredi del conguaglio stesso, e non se una successione ha preso fra le sue
                // fonti una riga di questa coppia (`AnnullaConguaglioAction`, la stessa regola): lì lo storico dice la strada al posto del comando, e non ha avvisi da seguire.
                // La ricerca legge le successioni del condominio, e si fa solo dove il comando c'è.
                $letto = $conguaglio === 'proposto' && ! $s->annullato() && ! $s->arretratoAgliEredi() ? AnnullaConguaglioAction::successioneCheLeggeLaCoppia($s) : null;
                $siAnnulla = ! $s->arretratoAgliEredi() && $letto === null;

                return [
                    'id' => $s->id,
                    'tipo_passaggio' => $s->tipo_passaggio,
                    'sottotipo' => $this->sottotipo($s),
                    'decorrenza' => $s->decorrenza?->toDateString(),
                    'decorrenza_a_parole' => $s->decorrenza ? $this->data($s->decorrenza) : null,
                    'registrato_il' => $s->created_at ? $this->giornoUtente($s->created_at) : null,
                    // Dal registro se la persona non c'è più: un passaggio annullato non impedisce di cancellarla.
                    'uscente' => $s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? null),
                    // Nella successione chi entra sono tutti gli eredi, nell'accrescimento tutti gli usufruttuari che ricevono, con la quota se
                    // sono più d'uno.
                    'entrante' => $s->destinatari() !== [] ? $this->destinatari($s) : ($s->entrante?->nome ?? ($s->registro['nomi']['entrante'] ?? null)),
                    'accrescimento' => $s->conAccrescimento(),
                    'estremi_titolo' => $s->estremi_titolo,
                    'copia_autentica_il' => $s->copia_autentica_il?->toDateString(),
                    'copia_autentica_a_parole' => $s->copia_autentica_il ? $this->data($s->copia_autentica_il) : null,
                    'copia_autentica_attesa' => $s->tipo_passaggio === 'vendita' && $s->copia_autentica_il === null,
                    'documento_url' => $this->urlDelTitolo($s),
                    'pertinenze' => $s->pertinenze->map(fn (Subentro $p) => $p->immobile?->nome)->filter()->values()->all(),
                    'conguaglio' => [
                        'stato' => $conguaglio,
                        // La riga di chi entra, col suo segno (verifica S6, R14): di norma un debito (positivo), ma con
                        // una quota pura a credito chi entra riceve un credito e la coppia è rovesciata. `scriviCoppie` usa
                        // lo stesso entrante sul padre e sulle pertinenze figlie. Dalla 1.11.0-beta.44 la somma di tutti quelli
                        // che entrano (gli eredi, i nudi che tornano pieni): prima contava solo chi entra scritto sul passaggio.
                        'importo' => $aEntrante = (int) $saldi->where('anagrafica_id', '!=', (int) $s->anagrafica_uscente_id)->sum('saldo_iniziale'),
                        'importo_formattato' => \App\Helpers\MoneyHelper::format(abs($aEntrante)),
                        // Fase 5 della 1.11.0-beta.44: con più persone che entrano (gli eredi, i nudi che tornano pieni) la somma non è il
                        // debito di nessuno — un erede di riferimento può essere a credito e un altro a debito —: la riga di ciascuno.
                        // R11 della Fase 1-bis della .50: alla fine senza un nuovo inquilino nessuno entra, e la riga si dice con il nome anche se è una.
                        'per_entrante' => $this->conguaglioPerEntrante($saldi, (int) $s->anagrafica_uscente_id, $s->aChiVannoIGiorni() !== 'a chi entra i suoi giorni'),
                        'applicato' => $saldi->contains(fn ($x) => (bool) $x->is_applicato),
                        'nota' => $s->nota_conguaglio,
                        // Coda 245: con «Non scriverlo» alla fine di una locazione le cifre che le parti si regolano fra loro (il registro le ha in `regolato_fuori`).
                        'fra_le_parti' => $s->lasciatoFraLeParti(),
                        'nota_annullamento' => $s->nota_annullamento_conguaglio,
                        'annullato_il' => $s->conguaglio_annullato_il ? $this->giornoUtente($s->conguaglio_annullato_il) : null,
                        // Con l'arretrato agli eredi la coppia e l'arretrato fanno un conto solo (`AnnullaConguaglioAction`).
                        'annullabile' => $siAnnulla,
                        // Perché non si annulla, e la strada, dove la scheda non la sa da sé (una successione ha letto la coppia): null altrimenti.
                        'motivo' => $letto === null ? null : AnnullaConguaglioAction::fraseDellaSuccessione($letto),
                        // Decisione 83.2 (1.11.0-beta.50): prima di confermare, le fermate dei passaggi dopo su questa coppia e il Wallet da rivedere.
                        'avvisi' => $conguaglio === 'proposto' && ! $s->annullato() && $siAnnulla ? FermataDopoScrivi::avvisiDelConguaglio($s) : [],
                    ],
                    'arretrato' => $s->successione() ? $this->arretrato($s, $righeArretrato) : null,
                    // Decisione 77 (3), 1.11.0-beta.50: la fermata dopo «Scrivi» e quanto ne resta dopo l'inizio di una locazione, dal registro.
                    // Hanno chiesto la spunta: la scelta lascia traccia (condizione 2 di `decide_l_amministratore.md`).
                    'fermate' => $s->annullato() ? [] : [
                        // Decisione 82.2: con la parte passata dopo, con il passaggio di chi l'aveva ricevuta. Decisione 83.2: con il conguaglio
                        // della fine annullato la fermata non vale più, e si dice.
                        ...array_map(fn (array $f) => FermataDopoScrivi::fineSenzaConguaglio($f) ? FermataDopoScrivi::fraseStoricoAnnullata($f)
                            : FermataDopoScrivi::fraseStorico($f, FermataDopoScrivi::passateDopo($s, $f), FermataDopoScrivi::tornateDopo($s, $f)), $s->registro['fermata_dopo_scrivi'] ?? []),
                        ...array_map(fn (array $f) => FermataDopoScrivi::fineSenzaConguaglio($f) ? FermataDopoScrivi::fraseStoricoRidottaAnnullata($f)
                            : FermataDopoScrivi::fraseStoricoRidotta($f), $s->registro['fermata_ridotta'] ?? []),
                        // Coda 254 (1.11.0-beta.51): la parte che è risalita alla fine di un conduttore.
                        ...array_map(fn (array $f) => FermataDopoScrivi::fineSenzaConguaglio($f) ? FermataDopoScrivi::fraseStoricoRisaleAnnullata($f)
                            : FermataDopoScrivi::fraseStoricoRisale($f), $s->registro['fermata_risale'] ?? []),
                        // R12 della Fase 1-bis: anche la fermata del coinquilino (decisione 79, punto 2), che ha chiesto la spunta.
                        ...array_map([FermataDopoScrivi::class, 'fraseStoricoCoinquilino'], $s->registro['fermata_coinquilino'] ?? []),
                        // Coda 245 (decisione 95.6 b): ciò che la regola del piano cambierebbe e la coppia di questo passaggio non ha scritto.
                        ...array_map(fn (array $r) => RegolaDelPiano::fraseStoricoDelResto($r, $s), $s->registro['resto_del_piano'] ?? []),
                    ],
                    'obbligati' => $s->annullato() ? [] : $this->frasiObbligati->daSubentro($s),
                    // Rilievo A2 della Fase 1-bis della beta.41: la scelta sull'ordinaria, dal registro. Decide i conguagli dei
                    // passaggi dopo, quindi resta visibile anche quando il passaggio non è più annullabile.
                    'ordinaria' => $this->ordinaria($s),
                    'nota' => $s->nota,
                    'annullato' => $s->annullato(),
                    'annullato_il' => $s->annullato_il ? $this->giornoUtente($s->annullato_il) : null,
                    // Chi l'ha annullato (decisione 27.4): dal registro se l'utente non c'è più (la chiave è `nullOnDelete`).
                    'annullato_da' => $s->annullato() ? ($s->annullatoDa?->name ?? ($s->registro['nomi']['annullato_da'] ?? 'un utente non più presente')) : null,
                    'nota_annullamento' => $s->nota_annullamento,
                    // Si può annullare? Stessa regola del server (`AnnullaPassaggioAction::motivoBlocco`), letta senza scrivere.
                    'annullabile' => [
                        'si' => ! $s->annullato() && $motivo === null,
                        'motivo' => $s->annullato() ? null : $motivo,
                        'avvisi' => ! $s->annullato() && $motivo === null ? $annullamento->avvisi($s) : [],
                        // Che cosa torna come prima: solo ciò che questo passaggio ha toccato, detto prima di confermare.
                        'effetti' => ! $s->annullato() && $motivo === null ? $annullamento->effetti($s) : [],
                    ],
                    // Decisione 705: le parti dei passaggi registrati insieme (`insiemiInUnaVoce`); nullo per un passaggio da solo.
                    'insieme' => null,
                ];
            })->values()->all();

        return $this->insiemiInUnaVoce($passaggi, $voci);
    }

    /**
     * Decisione 705: le voci dei passaggi registrati insieme diventano una, al posto della prima che si incontra (sono tutte con la stessa data e con
     * gli id di seguito). Un passaggio da solo resta com'è.
     *
     * @param \Illuminate\Support\Collection<int, Subentro> $passaggi nell'ordine delle voci
     * @param list<array<string, mixed>> $voci
     * @return list<array<string, mixed>>
     */
    private function insiemiInUnaVoce(\Illuminate\Support\Collection $passaggi, array $voci): array
    {
        $perId = collect($voci)->keyBy('id');
        $esito = [];
        $fatti = [];
        foreach ($passaggi->values() as $i => $s) {
            if ($s->insieme_id === null) {
                $esito[] = $voci[$i];

                continue;
            }
            if (isset($fatti[(int) $s->insieme_id])) {
                continue;
            }
            $fatti[(int) $s->insieme_id] = true;
            $membri = $passaggi->where('insieme_id', $s->insieme_id)->sortBy('id')->values();
            $esito[] = $membri->count() > 1 ? $this->voceDellInsieme($membri, $perId) : $voci[$i];
        }

        return $esito;
    }

    /**
     * Decisione 705: la voce dei passaggi registrati insieme. I campi di oggi restano quelli del primo, per i lettori che non conoscono l'insieme, con
     * quattro cose dell'insieme: la solidarietà con tutte le persone che escono, le pertinenze di tutti, le fermate vuote (sono di ciascuna persona, nella
     * sua parte), e `annullabile` dell'insieme (si
     * annullano tutti insieme: la stessa regola dell'azione, {@see \App\Actions\Subentro\AnnullaPassaggioAction::motivoBlocco()}, che per un passaggio
     * dell'insieme dice il motivo dell'insieme). In `insieme`:
     * - `passaggi`, una parte per persona, nell'ordine in cui sono stati scritti: la voce di oggi di quel passaggio (il suo `id`, `uscente`, il suo
     *   `conguaglio` con «Annulla il conguaglio», le sue `fermate`, le sue `pertinenze`…), con la `quota` della riga di chi esce e senza `annullabile`,
     *   che è dell'insieme. La copia autentica è una sola, sul primo (la voce);
     * - `quota_entrante`, la quota di chi entra dopo tutti i passaggi (il terzo fratello che aveva già il 33,33 % ha il 100 %).
     *
     * @param \Illuminate\Support\Collection<int, Subentro> $membri
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $perId le voci, per id
     * @return array<string, mixed>
     */
    private function voceDellInsieme(\Illuminate\Support\Collection $membri, \Illuminate\Support\Collection $perId): array
    {
        $primo = $membri->first();
        $parti = $membri->map(function (Subentro $m) use ($perId) {
            $parte = $perId->get($m->id);
            unset($parte['annullabile'], $parte['insieme']);

            return ['quota' => $this->quotaDiChiEsce($m)] + $parte;
        })->values()->all();

        $voce = $perId->get($primo->id);
        $voce['pertinenze'] = array_values(array_unique(array_merge(...array_map(fn (array $p) => $p['pertinenze'], $parti))));
        // Le fermate dopo «Scrivi» sono della persona (la parte di Ugo, la parte di Anna): stanno solo nella sua parte, e la pagina le dice sotto il suo nome.
        // In cima, unite, si leggevano due volte e senza il nome.
        $voce['fermate'] = [];
        if (! $primo->annullato()) {
            $voce['obbligati'] = $this->frasiObbligati->daSubentro($primo, $this->nomiDiChiEsce($membri));
        }
        $voce['insieme'] = ['passaggi' => $parti, 'quota_entrante' => $this->quotaDiChiEntra($membri->last())];

        return $voce;
    }

    /** La quota della riga di chi esce, in percentuale: quella che il passaggio ha portato tutta. */
    private function quotaDiChiEsce(Subentro $s): ?float
    {
        $quota = $s->riga_uscente_id === null ? null : TitolaritaImmobile::query()->whereKey($s->riga_uscente_id)->value('quota');

        return $quota === null ? null : round((float) $quota, 2);
    }

    /**
     * La quota di chi entra dopo l'ultimo passaggio dell'insieme: dal suo registro (la riga che ha aperto o sommato, con il «dopo»), anche quando
     * l'annullamento l'ha tolta; dalla riga, se il registro non la dice.
     */
    private function quotaDiChiEntra(Subentro $ultimo): ?float
    {
        $op = collect($ultimo->registro['righe'] ?? [])->first(fn ($op) => (int) ($op['id'] ?? 0) === (int) $ultimo->riga_entrante_id && isset($op['dopo']['quota']));
        $quota = $op['dopo']['quota'] ?? ($ultimo->riga_entrante_id === null ? null : TitolaritaImmobile::query()->whereKey($ultimo->riga_entrante_id)->value('quota'));

        return $quota === null ? null : round((float) $quota, 2);
    }

    /**
     * Le persone che escono con l'insieme, nell'ordine in cui sono stati scritti i passaggi, per la frase della solidarietà e della liberazione
     * (`FrasiObbligati::daSubentro`): chi entra risponde in solido con tutte, e le frasi sono al plurale (rilievo 5 dello scettico della tappa 7).
     *
     * @param \Illuminate\Support\Collection<int, Subentro> $membri
     * @return list<string>
     */
    private function nomiDiChiEsce(\Illuminate\Support\Collection $membri): array
    {
        return $membri->map(fn (Subentro $m) => $m->uscente?->nome ?? ($m->registro['nomi']['uscente'] ?? null))->filter()->unique()->values()->all();
    }

    private function riga(TitolaritaImmobile $t, CarbonImmutable $oggi, $subentri): array
    {
        $dal = $t->data_inizio ? CarbonImmutable::instance($t->data_inizio) : null;
        $al = $t->data_fine ? CarbonImmutable::instance($t->data_fine) : null;
        $inCorso = $t->inCorsoIl($oggi);
        $futuro = $dal !== null && $dal->gt($oggi);

        $comeUscente = $subentri->first(fn ($s) => (int) $s->riga_uscente_id === (int) $t->id);
        // Anche la riga di un erede che il passaggio non nomina come chi entra (1.11.0-beta.44).
        $comeEntrante = $subentri->first(fn ($s) => (int) $s->riga_entrante_id === (int) $t->id || in_array((int) $t->id, array_column($s->destinatari(), 'riga_id'), true));
        // La riga di continuazione (S5: il proprietario rimasto nudo, il comproprietario passato al 100 %) non è
        // agganciata a `subentri`: il suo passaggio è quello con la stessa persona come uscente, alla sua decorrenza.
        $comeContinuazione = $comeUscente === null && $comeEntrante === null && $dal !== null
            ? $subentri->first(fn ($s) => (int) $s->anagrafica_uscente_id === (int) $t->anagrafica_id && $s->decorrenza?->toDateString() === $dal->toDateString())
            : null;
        $subentro = $comeUscente ?? $comeEntrante ?? $comeContinuazione;

        return [
            'id' => $t->id,
            'anagrafica' => [
                'id' => $t->anagrafica?->id,
                'nome' => $t->anagrafica?->nome,
                'codice_fiscale' => $t->anagrafica?->codice_fiscale,
            ],
            'tipologia' => $t->tipologia,
            'diritto' => self::DIRITTI[$t->tipologia] ?? 'Altro',
            'quota' => $t->quota,
            'attivo' => (bool) $t->attivo,
            'data_inizio' => $dal?->toDateString(),
            'data_fine' => $al?->toDateString(),
            'in_corso' => $inCorso,
            'futuro' => $futuro,
            'periodo' => $this->periodoAParole($dal, $al, $futuro),
            'durata' => $this->durata($dal, $al, $oggi),
            'note' => $t->note,
            'subentro' => $subentro ? [
                'tipo_passaggio' => $subentro->tipo_passaggio,
                // Testi T7 della beta.38: la riga si chiama come il suo passaggio («Vendita o donazione con riserva
                // d'usufrutto»), con lo stesso sottotipo della sezione dei passaggi.
                'sottotipo' => $this->sottotipo($subentro),
                'decorrenza' => $subentro->decorrenza?->toDateString(),
                'estremi_titolo' => $subentro->estremi_titolo,
                'copia_autentica_il' => $subentro->copia_autentica_il ? $this->data($subentro->copia_autentica_il) : null,
                'ruolo_nel_passaggio' => $comeUscente ? 'uscente' : ($comeEntrante ? 'entrante' : 'continuazione'),
                // S5: il PDF del titolo (solo per l'amministratore) e la rinuncia al conguaglio, se c'è stata.
                'documento_url' => $this->urlDelTitolo($subentro),
                // Rilievo R4 della Fase 1-bis della .48: la scelta e la nota stanno sul passaggio padre, e la riga di una pertinenza
                // (il box) le legge da lì, come quella dell'unità.
                'nota_conguaglio' => ($subentro->padre ?? $subentro)->nota_conguaglio,
                // Decisione 72: «Non scriverlo» può non avere nota, e la scheda non può leggerlo dalla nota.
                'conguaglio_non_scritto' => ($subentro->padre ?? $subentro)->conguaglioNonScritto(),
            ] : null,
        ];
    }

    /**
     * Decisioni 31.5–31.7: chi paga l'ordinaria dal giorno dell'atto, come il passaggio l'ha scritto nel registro. `null` se
     * il registro non ne parla — vendita piena, locazione, passaggi anteriori alla beta.41: allora la scelta non c'era, e non
     * si deduce «la legge» dall'assenza.
     *
     * @return array{scelta: string, testo: string, voci: list<string>}|null
     */
    private function ordinaria(Subentro $s): ?array
    {
        $registro = $s->registro ?? [];
        if (! array_key_exists('ordinaria_dopo_atto', $registro)) {
            return null;
        }
        $scelta = (string) $registro['ordinaria_dopo_atto'];
        $dal = $s->decorrenza ? $this->data($s->decorrenza) : null;

        if (! empty($registro['ordinaria_ereditata_da'])) {
            // Rilievo D4: l'estinzione chiude la scelta dell'usufrutto che si estingue; la si dice con il passaggio da cui viene.
            $origine = Subentro::conAnnullati()->find((int) $registro['ordinaria_ereditata_da']);

            return ['scelta' => $scelta, 'voci' => [], 'testo' => sprintf('Come dice ogni voce, la scelta del passaggio%s da cui era nato l\'usufrutto: nel conguaglio le voci sul «Proprietario» erano già del nudo proprietario.',
                $origine?->decorrenza ? ' del ' . $this->data($origine->decorrenza) : '')];
        }
        if ($scelta === Subentro::ORDINARIA_COME_LA_VOCE) {
            return ['scelta' => $scelta, 'voci' => [], 'testo' => sprintf('Dal %s come dice ogni voce, scelta alla registrazione: le voci sul «Proprietario» %s, le altre %s. Le voci non sono state toccate.',
                $dal, $s->riservaUsufrutto() ? 'passano a chi ha comprato la nuda proprietà' : 'restano al nudo proprietario', $s->riservaUsufrutto() ? 'restano all\'usufruttuario' : 'vanno all\'usufruttuario')];
        }
        $spostate = $registro['voci_spostate'] ?? [];
        $voci = array_map(fn (array $v) => sprintf('%s: prima %s, dopo %s', VociDaSpostare::etichetta($v), VociDaSpostare::coefficientiAParole($v['prima'] ?? []), VociDaSpostare::coefficientiAParole($v['dopo'] ?? [])),
            $spostate);
        // Se a passare sono solo entrate l'art. 1004 c.c., che riguarda le spese, non si cita, e la frase non dice la regola di legge.
        $soloEntrate = $spostate !== [] && array_filter($spostate, fn (array $v) => ($v['tipo'] ?? null) !== 'entrata') === [];

        return ['scelta' => $scelta, 'voci' => $voci, 'testo' => sprintf('Dal %s all\'usufruttuario%s: %s', $dal, $soloEntrate ? '' : ', secondo la regola di legge (art. 1004 c.c.)', match (count($voci)) {
            0 => 'nessuna voce spostata.',
            1 => ($soloEntrate ? 'questa entrata è passata' : 'questa voce è passata') . ' dal «Proprietario» all\'«Usufruttuario».',
            default => ($soloEntrate ? 'queste entrate sono passate' : 'queste voci sono passate') . ' dal «Proprietario» all\'«Usufruttuario».',
        })];
    }

    /**
     * La forma del passaggio, per la sezione dei passaggi e per le righe che ha toccato: costituzione o estinzione
     * dell'usufrutto (dalla `tipologia` di chi entra), nella vendita la riserva d'usufrutto (dal registro, beta.38).
     */
    private function sottotipo(Subentro $s): ?string
    {
        return match (true) {
            $s->tipo_passaggio === 'usufrutto' => $s->tipologia === 'proprietario' ? 'estinzione' : 'costituzione',
            $s->riservaUsufrutto() => Subentro::RISERVA_USUFRUTTO,
            $s->legato() => Subentro::LEGATO,
            default => null,
        };
    }

    /** Chi ha ricevuto la quota (gli eredi, gli usufruttuari dell'accrescimento) in parole: il nome se è uno solo, altrimenti ognuno con la sua quota. */
    private function destinatari(Subentro $s): ?string
    {
        $nomi = \App\Models\Anagrafica::whereIn('id', $s->entranti())->pluck('nome', 'id');
        $eredi = array_map(fn ($e) => ['nome' => $nomi[$e['anagrafica_id']] ?? ($s->registro['nomi']['eredi'][(string) $e['anagrafica_id']] ?? '?'), 'quota' => $e['quota']], $s->destinatari());
        if (count($eredi) <= 1) {
            return $eredi[0]['nome'] ?? null;
        }
        $parti = array_map(fn ($e) => sprintf('%s (%s %%)', $e['nome'], rtrim(rtrim(number_format((float) $e['quota'], 2, ',', '.'), '0'), ',')), $eredi);
        $ultimo = array_pop($parti);

        return implode(', ', $parti) . ' e ' . $ultimo;
    }

    /**
     * L'arretrato del defunto come il passaggio l'ha scritto (decisione 65, 2): agli eredi, con la parte di ciascuno dalle righe di
     * `saldi`; o a suo nome, con la cifra del registro.
     *
     * @param \Illuminate\Support\Collection<int, \App\Models\Saldo> $righe
     * @return array{scelta: ?string, frase: ?string}
     */
    /**
     * Le righe del conguaglio di ciascuno di quelli che entrano, quando sono più d'uno: «€ 316,71 a credito di Anna», «€ 322,19 a
     * debito di Bruno». Con uno solo, vuoto: basta la cifra di sempre.
     *
     * @return list<string>
     */
    private function conguaglioPerEntrante(\Illuminate\Support\Collection $saldi, int $uscenteId, bool $ancheUna = false): array
    {
        $per = $saldi->where('anagrafica_id', '!=', $uscenteId)->groupBy('anagrafica_id')
            ->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->filter(fn (int $v) => $v !== 0);
        if ($per->count() < ($ancheUna ? 1 : 2)) {
            return [];
        }
        $nomi = \App\Models\Anagrafica::whereIn('id', $per->keys()->all())->pluck('nome', 'id');

        return $per->map(fn (int $v, $id) => sprintf('%s %s %s', \App\Helpers\MoneyHelper::format(abs($v)), $v < 0 ? 'a credito di' : 'a debito di', $nomi[$id] ?? 'questa persona'))->values()->all();
    }

    private function arretrato(Subentro $s, \Illuminate\Support\Collection $righe): array
    {
        $esito = $this->arretratoDeiSaldi($s, $righe);
        $altre = $this->scelteDellArretrato($s);
        if (($interessi = $this->interessiDelDefunto($s)) !== null) {
            $altre[] = $interessi;
        }
        if ($altre !== []) {
            $esito['frase'] = trim(($esito['frase'] ?? '') . ' ' . implode(' ', $altre));
        }

        return $esito;
    }

    /**
     * Decisione 483: gli interessi del defunto dopo il decesso, con tutte e due le strade dell'arretrato. Dalla colonna del passaggio: niente per un passaggio
     * annullato, che l'ha tolta, né per una successione senza la scelta.
     */
    private function interessiDelDefunto(Subentro $s): ?string
    {
        if ($s->interessi_del_defunto === null || $s->decorrenza === null) {
            return null;
        }
        $defunto = $s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? 'questa persona');
        $decesso = CarbonImmutable::parse($s->decorrenza->format('Y-m-d'));

        $frase = $s->interessi_del_defunto === Subentro::INTERESSI_AL_DECESSO
            // Coda 709: con l'arretrato a nome del defunto nessuno prende il debito, e lo storico dice che dopo il decesso non ha interessi; con l'arretrato
            // agli eredi li contano gli eredi (la frase non cambia).
            ? sprintf('Gli interessi di %s si fermano al decesso: la sua proposta degli interessi conta i giorni fino al %s%s', $defunto, $decesso->subDay()->format('d/m/Y'),
                $s->arretratoAgliEredi() ? '.' : ': dopo il decesso nessuno conta interessi su questo debito.')
            : sprintf('Gli interessi di %s continuano a suo nome dopo il decesso: la sua proposta degli interessi conta i giorni anche dopo il %s.', $defunto, $decesso->format('d/m/Y'));
        // D6 della lente del denaro della 1-bis della beta.12: la successione registrata prima della scelta non la ha nel suo registro (la scrive la registrazione,
        // `RegistraSubentroAction`); il valore della colonna l'ha messo la migrazione 170100, e lo storico lo dice.
        if (! array_key_exists('interessi_del_defunto', (array) ($s->capo()->registro['arretrato'] ?? []))) {
            $frase .= ' Non è una scelta fatta registrando la successione: è il valore di serie che l\'aggiornamento del programma ha dato alle successioni registrate prima.';
        }
        // Decisione 494 (2): con l'arretrato agli eredi, le scelte sugli interessi degli eredi: quelle del condominio, o cambiate per questa successione. Dalla
        // seconda prova della beta.12 la successione tiene solo le scelte cambiate, e lo storico dice quali; le altre seguono il condominio.
        if ($s->subentro_padre_id === null && $s->arretratoAgliEredi()) {
            $dette = is_array($s->interessi_eredi) ? \App\Services\Gestionale\Accessori\RegoleDegliEredi::dette($s->interessi_eredi) : [];
            $tutte = count($dette) === count(\App\Services\Gestionale\Accessori\RegoleDegliEredi::SCELTE);
            $frase .= match (true) {
                $dette === [] => ' Gli interessi degli eredi seguono le regole del condominio, in «Recupero dei crediti».',
                // Una successione registrata prima, con le otto scelte fissate tutte.
                $tutte => ' Gli interessi degli eredi hanno le scelte di questa successione, tutte e otto: ' . implode(', ', $dette) . '.',
                default => ' Gli interessi degli eredi hanno le scelte cambiate per questa successione: ' . implode(', ', $dette)
                    . '. Le altre seguono le regole del condominio, in «Recupero dei crediti».',
            };
        }

        return $frase;
    }

    /**
     * Le scelte dell'arretrato che il registro del passaggio tiene oltre ai saldi: l'accordo di rientro del defunto chiuso dalla successione, in regola
     * fino al decesso o come una decadenza (decisione 454); l'accessorio con un pagamento segnalato rimasto al defunto, o passato comunque agli eredi
     * (decisione 448). Una frase per ognuno; nessuna per i passaggi registrati prima che il registro le tenesse.
     *
     * @return list<string>
     */
    private function scelteDellArretrato(Subentro $s): array
    {
        $arretrato = (array) ($s->registro['arretrato'] ?? []);
        if (($arretrato['scelta'] ?? null) !== Subentro::ARRETRATO_AGLI_EREDI) {
            return [];
        }
        $defunto = $s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? 'questa persona');
        $frasi = [];
        foreach ((array) ($arretrato['restati_al_defunto'] ?? []) as $r) {
            $frasi[] = sprintf('«%s» (%s) è rimasto a nome di %s: aveva un pagamento segnalato dal portale, da verificare.', (string) ($r['causale'] ?? 'Addebito'),
                \App\Helpers\MoneyHelper::format((int) ($r['aperto'] ?? 0)), $defunto);
        }
        // Decisione 454: l'accordo di rientro del defunto chiuso dalla successione, e che cosa vale per i suoi interessi.
        $accordi = array_values(array_filter((array) ($arretrato['accordi'] ?? []), fn ($r) => isset($r['scelta'])));
        if ($accordi !== []) {
            $date = \App\Models\Gestionale\AccordoRientro::whereIn('id', array_map(fn ($r) => (int) $r['id'], $accordi))->pluck('data_accordo', 'id');
            foreach ($accordi as $r) {
                $del = isset($date[(int) $r['id']]) ? ' del ' . CarbonImmutable::parse($date[(int) $r['id']])->format('d/m/Y') : '';
                // Coda 682: l'annullamento ha riaperto l'accordo, e lo storico non lo dice più chiuso.
                $frasi[] = $s->annullato()
                    ? sprintf('L\'accordo di rientro di %s%s si era chiuso per la successione; l\'annullamento l\'ha riaperto.', $defunto, $del)
                    : sprintf('L\'accordo di rientro di %s%s si è chiuso per la successione: per gli interessi %s.', $defunto, $del,
                        $r['scelta'] === \App\Models\Gestionale\AccordoRientro::SUCCESSIONE_DECADENZA ? 'vale come una decadenza' : 'resta in regola fino al decesso');
            }
        }
        $segnalati = array_values(array_filter((array) ($arretrato['accessori'] ?? []), fn ($r) => (bool) ($r['segnalato'] ?? false)));
        if ($segnalati !== []) {
            $rate = \App\Models\Gestionale\RataQuote::with('rata:id,descrizione')->whereIn('id', array_map(fn ($r) => (int) $r['quota_id'], $segnalati))->get()->keyBy('id');
            foreach ($segnalati as $r) {
                $nomeRata = trim((string) ($rate[(int) $r['quota_id']]?->rata?->descrizione ?? 'Addebito'));
                // Coda 682: con il passaggio annullato l'accessorio è tornato al defunto.
                $frasi[] = $s->annullato()
                    ? sprintf('«%s» (%s) era passato agli eredi con un pagamento segnalato dal portale: l\'annullamento l\'ha riportato a nome di %s.', $nomeRata,
                        \App\Helpers\MoneyHelper::format((int) ($r['aperto'] ?? 0)), $defunto)
                    : sprintf('«%s» (%s) è passato agli eredi con un pagamento segnalato dal portale ancora da verificare: l\'ha scelto chi ha registrato il passaggio.',
                        $nomeRata, \App\Helpers\MoneyHelper::format((int) ($r['aperto'] ?? 0)));
            }
        }

        return $frasi;
    }

    private function arretratoDeiSaldi(Subentro $s, \Illuminate\Support\Collection $righe): array
    {
        $scelta = $s->registro['arretrato']['scelta'] ?? null;
        $defunto = $s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? 'questa persona');
        // Coda 682: un passaggio annullato ha tolto le sue righe dai saldi, e i saldi di oggi non dicono più com'era l'arretrato: la frase viene dal registro,
        // con la cifra che la successione aveva passato (le successioni registrate prima del totale dicono il fatto senza la cifra).
        if ($s->annullato() && $scelta === Subentro::ARRETRATO_AGLI_EREDI) {
            $totale = $s->registro['arretrato']['totale'] ?? null;
            // Con un totale negativo la frase comincia già con «Credito di …»: la cifra senza «a credito», per non dirlo due volte.
            $cifra = $totale === null ? '' : \App\Helpers\MoneyHelper::format(abs((int) $totale)) . ', ';

            return ['scelta' => $scelta, 'frase' => sprintf('%s di %s agli eredi: %spassato con la successione; l\'annullamento ha tolto le sue righe dai saldi.',
                $totale !== null && (int) $totale < 0 ? 'Credito' : 'Arretrato', $defunto, $cifra)];
        }
        if ($scelta === Subentro::ARRETRATO_AGLI_EREDI) {
            $perErede = $righe->where('anagrafica_id', '!=', (int) $s->anagrafica_uscente_id)->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->filter();
            // Rilievo X9 della Fase 1-bis: le righe che non hanno trovato un esercizio su cui scriversi sono rimaste a nome del defunto.
            $nonScritto = (int) ($s->registro['arretrato']['non_scritto'] ?? 0);
            $coda = $nonScritto === 0 ? '' : sprintf('%s%s non sono stati scritti nei saldi, perché mancava un esercizio su cui scriverli: restano a nome di %s.',
                \App\Helpers\MoneyHelper::format(abs($nonScritto)), $nonScritto < 0 ? ' a credito' : '', $defunto);
            if ($perErede->isEmpty()) {
                return ['scelta' => $scelta, 'frase' => $nonScritto === 0
                    ? sprintf('Arretrato di %s agli eredi: niente da passare, il giorno della registrazione la sua posizione era in pari.', $defunto)
                    : sprintf('Arretrato di %s agli eredi: %s', $defunto, $coda)];
            }
            $nomi = \App\Models\Anagrafica::whereIn('id', $perErede->keys())->pluck('nome', 'id');
            // Rilievo R5 della Fase 1-bis della .48: «ad» davanti ai nomi che cominciano per A.
            $parti = $perErede->map(fn (int $c, $id) => $c < 0
                ? sprintf('%s a credito di %s', \App\Helpers\MoneyHelper::format(abs($c)), $nomi[$id] ?? '?')
                : sprintf('%s %s', \App\Helpers\MoneyHelper::format($c), $this->frasiObbligati->a((string) ($nomi[$id] ?? '?'))))->values()->all();

            // Rilievo L1 della Fase 1-bis: un credito del defunto non è un debito dell'eredità, e non cita l'art. 754 c.c.
            return ['scelta' => $scelta, 'frase' => ($perErede->sum() < 0
                    ? sprintf('Credito di %s passato agli eredi per quota, con righe nei saldi della gestione: %s.', $defunto, implode(', ', $parti))
                    : sprintf('Arretrato di %s passato agli eredi per quota (art. 754 c.c.), con righe nei saldi della gestione: %s.', $defunto, implode(', ', $parti)))
                . ($coda === '' ? '' : ' Altri ' . $coda)];
        }
        $resta = (int) ($s->registro['arretrato']['resta'] ?? 0);

        return ['scelta' => $scelta, 'frase' => $resta === 0 ? null : sprintf('Arretrato di %s lasciato a suo nome («eredi di %s»): %s%s.', $defunto, $defunto, \App\Helpers\MoneyHelper::format(abs($resta)), $resta < 0 ? ' a credito' : '')];
    }

    private function periodoAParole(?CarbonImmutable $dal, ?CarbonImmutable $al, bool $futuro): string
    {
        if ($dal === null && $al === null) {
            return 'senza date';
        }
        if ($al === null) {
            return $futuro ? sprintf('dal %s (non ancora iniziato)', $this->data($dal)) : sprintf('dal %s · in corso', $this->data($dal));
        }
        if ($dal === null) {
            return sprintf('fino al %s', $this->data($al));
        }

        return sprintf('dal %s al %s', $this->data($dal), $this->data($al));
    }

    /** «7 anni», «8 mesi», «20 giorni»: la grandezza più grande che non sia zero, e mai i giorni di un decennio. */
    private function durata(?CarbonImmutable $dal, ?CarbonImmutable $al, CarbonImmutable $oggi): ?string
    {
        if ($dal === null) {
            return null;
        }
        $fine = $al ?? $oggi;
        if ($fine->lt($dal)) {
            return null;
        }
        // Estremi inclusi: dal 1° gennaio al 31 dicembre è un anno, non 364 giorni.
        $fine = $fine->addDay();

        // Carbon 3 restituisce frazioni: si tronca, un periodo di 7 anni e due mesi è «7 anni».
        $anni = (int) floor($dal->diffInYears($fine));
        if ($anni >= 1) {
            return $anni === 1 ? '1 anno' : "{$anni} anni";
        }
        $mesi = (int) floor($dal->diffInMonths($fine));
        if ($mesi >= 1) {
            return $mesi === 1 ? '1 mese' : "{$mesi} mesi";
        }
        $giorni = (int) floor($dal->diffInDays($fine));

        return $giorni === 1 ? '1 giorno' : "{$giorni} giorni";
    }

    private function data(CarbonImmutable|\DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->locale('it')->translatedFormat('j F Y');
    }

    /**
     * Un istante registrato dal server (in UTC) come giorno dell'utente: fra mezzanotte e le due, a Roma, è già il giorno
     * dopo (lo stesso fuso di `DateHelper::oggiUtente`). Le date senza ora — decorrenza, copia autentica — restano `data()`.
     */
    private function giornoUtente(\DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->setTimezone(config('app.user_timezone', 'Europe/Rome'))->locale('it')->translatedFormat('j F Y');
    }

    /**
     * Il PDF del titolo allegato a «Registra passaggio» è un documento dell'unità del passaggio
     * (`RegistraSubentroAction::salvaDocumento`): si scarica dalla scheda dell'unità, perché le rotte dell'archivio lavorano
     * solo sui documenti dell'archivio.
     */
    private function urlDelTitolo(Subentro $subentro): ?string
    {
        return $subentro->documento_id
            ? route('admin.gestionale.immobili.documenti.download', [
                'condominio' => $subentro->condominio_id,
                'immobile'   => $subentro->immobile_id,
                'documento'  => $subentro->documento_id,
            ])
            : null;
    }
}
