<?php

namespace App\Services\Subentro;

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
        $subentri = Subentro::where('immobile_id', $immobile->id)->get();

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

        // Un passaggio annullato (beta.37) resta in elenco ma non si conta: il pulsante dice quanti passaggi valgono.
        $attivi = count(array_filter($passaggiRegistrati, fn (array $p) => ! $p['annullato']));

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
     * @return list<array<string, mixed>>
     */
    private function passaggiRegistrati(Immobile $immobile): array
    {
        // Anche gli annullati (beta.37): restano nello storico, annullati, e sono l'unico posto dove si vedono.
        $annullamento = app(\App\Actions\Subentro\AnnullaPassaggioAction::class);

        return Subentro::conAnnullati()->with(['uscente', 'entrante', 'pertinenze' => fn ($q) => $q->conAnnullati(), 'pertinenze.immobile', 'saldi', 'pertinenze.saldi', 'condominio', 'immobile', 'annullatoDa:id,name'])
            ->where('immobile_id', $immobile->id)
            ->whereNull('subentro_padre_id')
            ->orderByDesc('decorrenza')->orderByDesc('id')
            ->get()
            ->map(function (Subentro $s) use ($annullamento) {
                $saldi = $s->saldi->merge($s->pertinenze->flatMap->saldi);
                $motivo = $s->annullato() ? null : $annullamento->motivoBlocco($s);
                $conguaglio = match (true) {
                    $s->conguaglioAnnullato() => 'annullato',
                    $s->conguaglioRinunciato() => 'rinunciato',
                    $saldi->isNotEmpty() => 'proposto',
                    default => 'nessuno',
                };

                return [
                    'id' => $s->id,
                    'tipo_passaggio' => $s->tipo_passaggio,
                    'sottotipo' => $this->sottotipo($s),
                    'decorrenza' => $s->decorrenza?->toDateString(),
                    'decorrenza_a_parole' => $s->decorrenza ? $this->data($s->decorrenza) : null,
                    'registrato_il' => $s->created_at ? $this->giornoUtente($s->created_at) : null,
                    // Dal registro se la persona non c'è più: un passaggio annullato non impedisce di cancellarla.
                    'uscente' => $s->uscente?->nome ?? ($s->registro['nomi']['uscente'] ?? null),
                    'entrante' => $s->entrante?->nome ?? ($s->registro['nomi']['entrante'] ?? null),
                    'estremi_titolo' => $s->estremi_titolo,
                    'copia_autentica_il' => $s->copia_autentica_il?->toDateString(),
                    'copia_autentica_a_parole' => $s->copia_autentica_il ? $this->data($s->copia_autentica_il) : null,
                    'copia_autentica_attesa' => $s->tipo_passaggio === 'vendita' && $s->copia_autentica_il === null,
                    'documento_url' => $s->documento_id ? route('admin.documenti.download', ['documento' => $s->documento_id]) : null,
                    'pertinenze' => $s->pertinenze->map(fn (Subentro $p) => $p->immobile?->nome)->filter()->values()->all(),
                    'conguaglio' => [
                        'stato' => $conguaglio,
                        // La riga di chi entra, col suo segno (verifica S6, R14): di norma un debito (positivo), ma con
                        // una quota pura a credito chi entra riceve un credito e la coppia è rovesciata. `scriviCoppie` usa
                        // lo stesso entrante sul padre e sulle pertinenze figlie, quindi la somma è la sua.
                        'importo' => $aEntrante = (int) $saldi->where('anagrafica_id', (int) $s->anagrafica_entrante_id)->sum('saldo_iniziale'),
                        'importo_formattato' => \App\Helpers\MoneyHelper::format(abs($aEntrante)),
                        'applicato' => $saldi->contains(fn ($x) => (bool) $x->is_applicato),
                        'nota' => $s->nota_conguaglio,
                        'nota_annullamento' => $s->nota_annullamento_conguaglio,
                        'annullato_il' => $s->conguaglio_annullato_il ? $this->giornoUtente($s->conguaglio_annullato_il) : null,
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
                ];
            })->values()->all();
    }

    private function riga(TitolaritaImmobile $t, CarbonImmutable $oggi, $subentri): array
    {
        $dal = $t->data_inizio ? CarbonImmutable::instance($t->data_inizio) : null;
        $al = $t->data_fine ? CarbonImmutable::instance($t->data_fine) : null;
        $inCorso = $t->inCorsoIl($oggi);
        $futuro = $dal !== null && $dal->gt($oggi);

        $comeUscente = $subentri->first(fn ($s) => (int) $s->riga_uscente_id === (int) $t->id);
        $comeEntrante = $subentri->first(fn ($s) => (int) $s->riga_entrante_id === (int) $t->id);
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
                'documento_url' => $subentro->documento_id ? route('admin.documenti.download', ['documento' => $subentro->documento_id]) : null,
                'nota_conguaglio' => $subentro->nota_conguaglio,
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
        $voci = array_map(fn (array $v) => sprintf('%s (%s, %s): prima %s, dopo %s', $v['conto'] ?? '?', $v['tabella'] ?? '?', $v['gestione'] ?? '?', VociDaSpostare::coefficientiAParole($v['prima'] ?? []), VociDaSpostare::coefficientiAParole($v['dopo'] ?? [])),
            $registro['voci_spostate'] ?? []);

        return ['scelta' => $scelta, 'voci' => $voci, 'testo' => sprintf('Dal %s all\'usufruttuario (art. 1004 c.c.), la proposta di legge: %s', $dal, match (count($voci)) {
            0 => 'nessuna voce spostata.',
            1 => 'questa voce è passata dal «Proprietario» all\'«Usufruttuario».',
            default => 'queste voci sono passate dal «Proprietario» all\'«Usufruttuario».',
        })];
    }

    /**
     * La forma del passaggio, per la sezione dei passaggi e per le righe che ha toccato: costituzione o estinzione
     * dell'usufrutto (dalla `tipologia` di chi entra), nella vendita la riserva d'usufrutto (dal registro, beta.38).
     */
    private function sottotipo(Subentro $s): ?string
    {
        return $s->tipo_passaggio === 'usufrutto' ? ($s->tipologia === 'proprietario' ? 'estinzione' : 'costituzione') : ($s->riservaUsufrutto() ? Subentro::RISERVA_USUFRUTTO : null);
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
}
