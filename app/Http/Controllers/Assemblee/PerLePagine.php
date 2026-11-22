<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Enums\Assemblee\AChiMemo;
use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\ConduttorePunto;
use App\Enums\Assemblee\ConvocazioneSeduta;
use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\TipoAssemblea;
use App\Helpers\MoneyHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\MaggioranzePerMateria;
use App\Services\Assemblee\PassiDellAssemblea;
use App\Services\Assemblee\TabellaDiProprieta;
use Illuminate\Support\Facades\DB;

/**
 * I dati delle pagine delle assemblee (§3 P1, P2, P6 del progetto della tappa 2), letti dai modelli e dai servizi: l'intestazione, l'ordine del giorno con i
 * badge, i passi della strada ({@see PassiDellAssemblea}), i menu. Le date all'italiana, gli importi in centesimi (la pagina li scrive in euro).
 */
final class PerLePagine
{
    /** @return array<string, mixed> */
    public static function assemblea(Assemblea $a): array
    {
        $a->loadMissing(['sedute', 'tabella', 'palazzina', 'scala', 'verbale', 'riconvocataDa']);
        $condominio = $a->condominio;
        $sedute = $a->sedute->map(fn ($s) => [
            'id' => (int) $s->id,
            'convocazione' => $s->convocazione?->value,
            'numero' => (int) $s->numero,
            'giorno' => $s->fissata_il?->format('Y-m-d'),
            'ora' => $s->fissata_il?->format('H:i'),
            'luogo' => $s->luogo,
        ])->values()->all();
        $perimetro = self::perimetroDi($a->ambito?->value, $a->palazzina, $a->scala);
        $tabella = $a->tabella;
        $perimetroModello = match ($a->ambito) {
            AmbitoAssemblea::SCALA => $a->scala,
            AmbitoAssemblea::PALAZZINA => $a->palazzina,
            default => $condominio,
        };
        $proprieta = $perimetroModello === null ? null : TabellaDiProprieta::di($perimetroModello);

        return [
            'id' => (int) $a->id,
            'nome' => $a->nome(),
            'titolo' => self::titolo($a, $condominio),
            'sottotitolo' => self::sottotitolo($a),
            'stato' => $a->stato?->value,
            'stato_parola' => self::statoParola($a),
            'origine' => $a->origine?->value,
            'a_mano' => $a->eAMano(),
            'tenuta_fuori' => $a->stato === StatoAssemblea::REGISTRATA_A_MANO && ! $a->eAMano(),
            'in_preparazione' => $a->eInPreparazione(),
            // Il numero che la riunione di carta prenderebbe nel registro dei verbali («Tenuta su carta»): proposto e correggibile.
            'numero_verbale_proposto' => $a->eInPreparazione() ? app(\App\Services\Assemblee\Verbale\RegistroDeiVerbali::class)->prossimoNumero($condominio,
                \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::registroDi($a)) : null,
            'tipo' => $a->tipo?->value,
            'tipo_parola' => $a->tipo?->parola(),
            'ambito' => $a->ambito?->value ?? AmbitoAssemblea::CONDOMINIO->value,
            'palazzina_id' => $a->palazzina_id,
            'scala_id' => $a->scala_id,
            'perimetro' => $perimetro,
            'luogo' => $a->luogo,
            'modalita' => $a->modalita?->value ?? ModalitaAssemblea::PRESENZA->value,
            'piattaforma' => $a->piattaforma,
            'consenso_videoconferenza_il' => $a->consenso_videoconferenza_il?->format('Y-m-d'),
            'consenso_videoconferenza_modo' => $a->consenso_videoconferenza_modo,
            'data' => $a->data?->format('Y-m-d'),
            'sedute' => $sedute,
            'tabella' => $tabella === null || $perimetroModello === null ? null : [
                'id' => (int) $tabella->id,
                'nome' => $tabella->nome,
                'controllo' => TabellaDiProprieta::controllo($tabella, $perimetroModello),
            ],
            'tabella_di_proprieta' => $proprieta === null ? null : ['id' => (int) $proprieta->id, 'nome' => $proprieta->nome],
            'perimetro_tipo' => match ($a->ambito) {
                AmbitoAssemblea::SCALA => 'scala',
                AmbitoAssemblea::PALAZZINA => 'palazzina',
                default => 'condominio',
            },
            'perimetro_id' => (int) ($perimetroModello?->getKey() ?? $condominio->id),
            'numero_verbale' => $a->numero_verbale,
            'verbale' => $a->verbale === null ? null : [
                'id' => (int) $a->verbale->id,
                'nome' => $a->verbale->name,
                'peso' => (int) $a->verbale->file_size,
            ],
            'verbale_da_caricare' => $a->stato === StatoAssemblea::REGISTRATA_A_MANO && $a->verbale_documento_id === null,
            'riconvocata_da' => $a->riconvocataDa === null ? null : ['id' => (int) $a->riconvocataDa->id, 'nome' => $a->riconvocataDa->nome()],
            'annullata_motivo' => $a->annullata_motivo,
            'apri_la_sala' => self::apriLaSala($a),
        ];
    }

    /** «Assemblea ordinaria di Via Roma 12»; per la riunione a mano «Riunione del 12/03/2024». */
    public static function titolo(Assemblea $a, Condominio $condominio): string
    {
        if ($a->eAMano()) {
            return $a->nome();
        }

        return 'Assemblea ' . mb_strtolower((string) ($a->tipo?->parola() ?? TipoAssemblea::ORDINARIA->parola())) . ' di ' . $condominio->nome;
    }

    /** «Prima convocazione 14/12/2026 alle 8:00 · seconda 15/12/2026 alle 18:30 · sala condominiale, Via Roma 12». */
    public static function sottotitolo(Assemblea $a): string
    {
        if ($a->stato === StatoAssemblea::REGISTRATA_A_MANO) {
            $pezzi = [$a->eAMano() ? 'registrata a mano' : 'tenuta su carta'];
            if ($a->eAMano() && $a->tipo !== null) {
                $pezzi[] = mb_strtolower($a->tipo->parola());
            }
            if (! $a->eAMano() && $a->data !== null) {
                $pezzi[0] .= ' il ' . $a->data->format('d/m/Y');
            }
            if (trim((string) $a->numero_verbale) !== '') {
                $pezzi[] = 'verbale n. ' . trim((string) $a->numero_verbale);
            }

            return ucfirst(implode(' · ', $pezzi));
        }
        $pezzi = [];
        foreach ($a->sedute as $s) {
            $quando = $s->fissata_il?->format('d/m/Y') . ' alle ' . $s->fissata_il?->format('H:i');
            if ((int) $s->numero === 1) {
                $pezzi[] = ($s->convocazione === ConvocazioneSeduta::PRIMA ? 'Prima convocazione ' : 'seconda ') . $quando;
            } else {
                $pezzi[] = 'prosegue il ' . $quando;
            }
        }
        if (trim((string) $a->luogo) !== '') {
            $pezzi[] = mb_strtolower(mb_substr((string) $a->luogo, 0, 1)) . mb_substr((string) $a->luogo, 1);
        }

        return $pezzi === [] ? 'Le date non ci sono ancora: scrivile qui sotto.' : ucfirst(implode(' · ', $pezzi));
    }

    public static function statoParola(Assemblea $a): string
    {
        if ($a->stato === StatoAssemblea::REGISTRATA_A_MANO) {
            return $a->eAMano() ? 'Registrata a mano' : 'Tenuta su carta';
        }

        return (string) $a->stato?->parola();
    }

    /**
     * «Apri la sala»: spento fino al giorno della prima convocazione (decisione 513 (1)) e per un'assemblea non convocata; acceso dal giorno, con l'indirizzo
     * della sala; «Torna in sala» quando l'assemblea è in corso.
     *
     * @return array{attiva: bool, perche: string, url?: string, testo?: string}
     */
    public static function apriLaSala(Assemblea $a): array
    {
        $prima = $a->sedute->first(fn ($s) => $s->convocazione === ConvocazioneSeduta::PRIMA && (int) $s->numero === 1);
        if ($prima === null) {
            return ['attiva' => false, 'perche' => 'La sala si apre il giorno della prima convocazione: prima scrivi le date.'];
        }
        $url = route('admin.gestionale.assemblee.sala', [$a->condominio_id, $a->id]);
        if ($a->stato === StatoAssemblea::IN_CORSO) {
            return ['attiva' => true, 'perche' => 'L\'assemblea è in corso.', 'url' => $url, 'testo' => 'Torna in sala'];
        }
        if (in_array($a->stato, [StatoAssemblea::CHIUSA, StatoAssemblea::DESERTA], true)) {
            return ['attiva' => true, 'perche' => 'L\'assemblea è ' . mb_strtolower((string) $a->stato->parola()) . '.', 'url' => $url, 'testo' => 'La sala'];
        }
        $perche = 'La sala si apre il giorno della prima convocazione, il ' . $prima->fissata_il->format('d/m/Y') . '.';
        if ($a->stato !== StatoAssemblea::CONVOCATA) {
            return ['attiva' => false, 'perche' => $a->stato === StatoAssemblea::IN_PREPARAZIONE ? $perche . ' Prima si spedisce la convocazione.' : $perche];
        }
        if (\App\Helpers\DateHelper::oggiUtente() < $prima->fissata_il->format('Y-m-d')) {
            return ['attiva' => false, 'perche' => $perche];
        }

        return ['attiva' => true, 'perche' => 'Oggi è il giorno della prima convocazione.', 'url' => $url, 'testo' => 'Apri la sala'];
    }

    /** «Tutto il condominio», «Solo la palazzina A», «Solo la scala B». */
    public static function perimetroDi(?string $ambito, ?Palazzina $palazzina, ?Scala $scala): string
    {
        return match ($ambito) {
            AmbitoAssemblea::SCALA->value => 'Solo la scala ' . ($scala?->name ?? ''),
            AmbitoAssemblea::PALAZZINA->value => 'Solo la palazzina ' . ($palazzina?->name ?? ''),
            default => 'Tutto il condominio',
        };
    }

    /**
     * L'ordine del giorno, con i badge della bozza: la maggioranza, «Votano i conduttori», «Solo la scala B», «Tabella «Ascensore B»», «Memo»; per
     * un'assemblea tenuta fuori dal programma o una riunione a mano le delibere di ogni punto, con chi le usa.
     *
     * @return list<array<string, mixed>>
     */
    public static function ordine(Assemblea $a): array
    {
        $punti = $a->punti()->with(['delibere', 'memo'])->get();
        $scale = Scala::query()->whereIn('id', $punti->pluck('scala_id')->filter()->unique())->get()->keyBy('id');
        $palazzine = Palazzina::query()->whereIn('id', $punti->pluck('palazzina_id')->filter()->unique())->get()->keyBy('id');
        $tabelle = Tabella::query()->whereIn('id', $punti->pluck('tabella_id')->filter()->unique())->get()->keyBy('id');

        return $punti->map(function (PuntoDellOrdineDelGiorno $p) use ($a, $scale, $palazzine, $tabelle) {
            $diUnaParte = $p->ambito !== null && $p->ambito !== AmbitoAssemblea::CONDOMINIO;
            $tabella = $p->tabella_id !== null ? $tabelle[$p->tabella_id] ?? null : null;
            $perimetro = match ($p->ambito) {
                AmbitoAssemblea::SCALA => $scale[$p->scala_id] ?? null,
                AmbitoAssemblea::PALAZZINA => $palazzine[$p->palazzina_id] ?? null,
                default => null,
            };

            return [
                'id' => (int) $p->id,
                'numero' => (int) $p->numero,
                'testo' => $p->testo,
                'testo_pubblico' => $p->testo_pubblico,
                'materia' => $p->materia?->value,
                'materia_parola' => $p->materia?->parola(),
                'maggioranza' => $p->maggioranza?->value,
                'maggioranza_parola' => $p->maggioranza?->parola(),
                'maggioranza_libera' => $p->maggioranza_libera,
                'avviso' => $p->materia === null ? null : MaggioranzePerMateria::avviso($p->materia->value),
                'amministrazione' => $p->amministrazione?->value,
                'conduttore' => $p->conduttore?->value,
                'conduttore_parola' => match ($p->conduttore) {
                    ConduttorePunto::VOTA => 'Votano i conduttori',
                    ConduttorePunto::INTERVIENE => 'Intervengono i conduttori',
                    default => null,
                },
                'ambito' => $p->ambito?->value,
                'palazzina_id' => $p->palazzina_id,
                'scala_id' => $p->scala_id,
                'perimetro_parola' => $diUnaParte ? self::perimetroDi($p->ambito?->value, $palazzine[$p->palazzina_id] ?? null, $scale[$p->scala_id] ?? null) : null,
                'tabella_id' => $p->tabella_id,
                'tabella_nome' => $tabella?->nome,
                'tabella_controllo' => $tabella !== null && $perimetro !== null ? TabellaDiProprieta::controllo($tabella, $perimetro)['frase'] : null,
                'esercizio_id' => $p->esercizio_id,
                'importo' => $p->importo,
                // Per la modifica della destinazione d'uso (art. 1117-ter): i due contenuti che l'avviso deve dire. Gli altri dati del punto li scrive il programma;
                // il punto «Stato di esecuzione delle delibere precedenti» porta alla pagina il suo elenco, fermo al giorno in cui si prepara.
                'dati' => ['parti_comuni' => $p->dati['parti_comuni'] ?? null, 'nuova_destinazione' => $p->dati['nuova_destinazione'] ?? null,
                    // La delega all'amministratore (decisione 622 (3)): per che cosa e fino a quando; il tetto è l'importo.
                    'delega' => isset($p->dati['delega']) && is_array($p->dati['delega'])
                        ? ['ambito' => $p->dati['delega']['ambito'] ?? null, 'fino_al' => $p->dati['delega']['fino_al'] ?? null] : null]
                    + (isset($p->dati[\App\Services\Assemblee\Seguito\StatoDiEsecuzione::CHIAVE])
                        ? ['stato_esecuzione' => $p->dati[\App\Services\Assemblee\Seguito\StatoDiEsecuzione::CHIAVE], 'stampa_stato' => self::stampaDelloStato($a)] : []),
                'fonte' => $p->fonte?->value,
                'chiave_fonte' => $p->chiave_fonte,
                'da_memo' => $p->fonte === FontePunto::MEMO || $p->memo->isNotEmpty(),
                'delibere' => $p->delibere->map(fn (Delibera $d) => self::delibera($d))->values()->all(),
            ];
        })->values()->all();
    }

    /** Una delibera nella pagina della riunione: l'esito, la frase, chi la usa (R2). @return array<string, mixed> */
    public static function delibera(Delibera $d): array
    {
        $posti = CollegamentiDelleDelibere::posti($d);

        return [
            'id' => (int) $d->id,
            'testo' => $d->testo,
            'esito' => $d->esito?->value,
            'esito_parola' => $d->esito?->parola(),
            'deliberata_il' => $d->deliberata_il?->format('Y-m-d'),
            'frase' => DeliberaInParole::frase($d),
            'posti' => array_map(fn ($p) => ['nome' => $p['nome'], 'parola' => $p['parola'], 'data' => $p['data'], 'nota' => $p['nota']], $posti),
            'usata' => CollegamentiDelleDelibere::frasiDeiPosti($posti),
        ];
    }

    /**
     * I sei passi della strada della pagina dell'assemblea; nessuno per una riunione registrata a mano. `$seguito`: la lettura del seguito delle delibere
     * che la pagina fa già per il passo 6 aperto, così nella stessa richiesta si legge una volta sola.
     *
     * @param (\Closure(): array<string, mixed>)|null $seguito
     * @return list<array<string, mixed>>
     */
    public static function passi(Assemblea $a, ?\Closure $seguito = null): array
    {
        return app(PassiDellAssemblea::class)->per($a, $seguito);
    }

    /** La stampa «Lo stato di esecuzione delle delibere» del punto, quando la sua rotta c'è; altrimenti null. */
    private static function stampaDelloStato(Assemblea $a): ?string
    {
        return \Illuminate\Support\Facades\Route::has('admin.gestionale.assemblee.seguito.stampa')
            ? route('admin.gestionale.assemblee.seguito.stampa', ['condominio' => $a->condominio_id, 'assemblea' => $a->id]) : null;
    }

    /** La strada di «Prepara l'assemblea»: il primo passo aperto, gli altri chiusi. @return list<array<string, mixed>> */
    public static function passiDellaPreparazione(): array
    {
        return PassiDellAssemblea::dellaPreparazione();
    }

    /**
     * I menu delle pagine: tipo, perimetro, materie, maggioranze, esiti, modalità; le palazzine e le scale con le unità; le tabelle con le unità e la
     * proposta per una scala (quella che ha tutte e sole le sue unità, scena 2 della bozza delle scene della tappa 2) e il controllo per ogni perimetro.
     *
     * @return array<string, mixed>
     */
    public static function scelte(Condominio $c): array
    {
        $scelta = fn (array $parole) => array_map(fn ($v, $n) => ['valore' => $v, 'nome' => $n], array_keys($parole), $parole);
        $immobili = DB::table('immobili')->where('condominio_id', $c->id)->get(['id', 'palazzina_id', 'scala_id']);
        $palazzine = Palazzina::query()->where('condominio_id', $c->id)->orderBy('name')->get();
        $scale = Scala::query()->where('condominio_id', $c->id)->orderBy('name')->get();
        $tabelle = Tabella::query()->where('condominio_id', $c->id)->orderBy('nome')->get(['id', 'nome', 'scala_id', 'palazzina_id']);
        $quote = DB::table('quote_tabella')->whereIn('tabella_id', $tabelle->pluck('id'))->where('valore', '>', 0)->get(['tabella_id', 'immobile_id', 'valore'])
            ->groupBy('tabella_id');
        $unitaDi = [
            'condominio' => $immobili->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
        foreach ($palazzine as $p) {
            $unitaDi['palazzina:' . $p->id] = $immobili->where('palazzina_id', $p->id)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }
        foreach ($scale as $s) {
            $unitaDi['scala:' . $s->id] = $immobili->where('scala_id', $s->id)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }
        $tabelleScelte = $tabelle->map(function (Tabella $t) use ($quote, $unitaDi) {
            $sue = collect($quote[$t->id] ?? [])->mapWithKeys(fn ($q) => [(int) $q->immobile_id => (float) $q->valore]);
            $controlli = [];
            foreach ($unitaDi as $chiave => $unita) {
                $dentro = $sue->only($unita);
                $somma = (int) round($dentro->sum() * 100000);
                $controlli[$chiave] = [
                    'frase' => $dentro->count() . ' unità su ' . count($unita) . ' · ' . number_format($somma / 100000, 2, ',', '.') . ' millesimi',
                    'copre' => $dentro->count() === count($unita) && count($unita) > 0,
                    'esatta' => $dentro->count() === count($unita) && $sue->count() === count($unita) && count($unita) > 0,
                    // Per l'avviso della tabella parziale nella finestra del punto (decisione 640 (2)): le unità del perimetro con la quota, e quante sono.
                    'con_quota' => $dentro->count(),
                    'unita' => count($unita),
                ];
            }

            return ['id' => (int) $t->id, 'nome' => $t->nome, 'unita' => $sue->count(), 'controlli' => $controlli];
        })->values()->all();

        return [
            'tipi' => $scelta(TipoAssemblea::parole()),
            'perimetri' => $scelta(AmbitoAssemblea::parole()),
            'modalita' => $scelta(ModalitaAssemblea::parole()),
            'materie' => $scelta(MateriaPunto::parole()),
            'maggioranze' => $scelta(Maggioranza::parole()),
            'esiti' => $scelta(EsitoDelibera::parole()),
            'a_chi' => $scelta(AChiMemo::parole()),
            'unita' => count($unitaDi['condominio']),
            'palazzine' => $palazzine->map(fn (Palazzina $p) => ['id' => (int) $p->id, 'nome' => $p->name, 'unita' => count($unitaDi['palazzina:' . $p->id])])->values()->all(),
            'scale' => $scale->map(fn (Scala $s) => ['id' => (int) $s->id, 'nome' => $s->name, 'palazzina_id' => $s->palazzina_id,
                'unita' => count($unitaDi['scala:' . $s->id])])->values()->all(),
            'tabelle' => $tabelleScelte,
            'maggioranze_proposte' => collect(MateriaPunto::cases())->mapWithKeys(fn (MateriaPunto $m) => [$m->value => [
                'maggioranza' => MaggioranzePerMateria::proposta($m->value),
                'avviso' => MaggioranzePerMateria::avviso($m->value),
                'conduttore' => MaggioranzePerMateria::conduttore($m->value),
            ]])->all(),
        ];
    }

    /**
     * I collaboratori dello studio per «A un collaboratore»: attivi (non sospesi) e che vedono le assemblee, mai i ruoli del portale. I ruoli e i permessi
     * si leggono con l'elenco, non utente per utente.
     *
     * @return list<array{id: int, nome: string}>
     */
    public static function collaboratori(): array
    {
        return User::query()->whereNull('suspended_at')->with(['roles', 'permissions'])->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => \App\Services\Assemblee\AccessoAlleAssemblee::vede($u))
            ->map(fn (User $u) => ['id' => (int) $u->id, 'nome' => (string) $u->name])->values()->all();
    }

    /** Un importo in centesimi come si legge: «€ 1.800,00». */
    public static function euro(?int $centesimi): ?string
    {
        return $centesimi === null ? null : MoneyHelper::format($centesimi);
    }
}
