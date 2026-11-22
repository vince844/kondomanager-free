<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\StatoVerbale;
use App\Http\Controllers\Assemblee\PerLePagine;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;

/**
 * Il verbale come lo legge la pagina «Il verbale» (la prop `verbale`, la forma `PaginaDelVerbale` di `resources/js/types/verbale.ts`) e la sua lettura
 * periodica in JSON: le riunioni dell'assemblea con il loro verbale, il verbale scelto con le parti (la struttura, la revisione, l'impronta), i punti con la
 * riga della sala, le cifre, le versioni e quelle in attesa, chi scrive, il registro (il numero proposto, da dove parte), la trasmissione e i trenta giorni
 * dopo la registrazione, i modelli per «Genera», i permessi e le rotte.
 */
final class LetturaDelVerbale
{
    public function __construct(
        private readonly VersioniDelVerbale $versioni,
        private readonly CifreDellaSala $cifre,
        private readonly RegistroDeiVerbali $registro,
        private readonly DestinatariDelVerbale $destinatari,
        private readonly TerminiDellImpugnazione $termini,
        private readonly ChiScriveIlVerbale $chiScrive,
    ) {}

    /** @return array<string, mixed> */
    public function perLaPagina(Assemblea $a, ?int $verbaleId, ?User $utente, ?string $dispositivo = null): array
    {
        $a->loadMissing(['condominio', 'sedute']);
        $riunioni = VerbaliDellAssemblea::riunioni($a);
        $verbale = $verbaleId !== null ? VerbaliDellAssemblea::diQuestaAssemblea($a, $verbaleId) : $this->diAdesso($a, $riunioni);
        // La riunione che la pagina mostra: quella del verbale, o l'ultima aperta o chiusa quando nessuno ha ancora scritto (il verbale nasce con la prima
        // discussione), con i suoi punti e la riga della sala.
        $sedutaId = $verbale?->seduta_id ?? $riunioni->sortBy(fn ($s) => $s->aperta_il)->last()?->id;

        return [
            'assemblea' => ['id' => (int) $a->id, 'nome' => $a->nome(), 'titolo' => PerLePagine::titolo($a, $a->condominio), 'stato' => $a->stato?->value,
                'stato_parola' => PerLePagine::statoParola($a), 'modalita' => $a->modalita?->value, 'tipo' => $a->tipo?->value],
            'ora_del_server' => now()->utc()->toIso8601ZuluString(),
            'riunioni' => $riunioni->map(fn (SedutaDellAssemblea $s) => $this->riunione($s))->values()->all(),
            'seduta_id' => $sedutaId === null ? null : (int) $sedutaId,
            'punti' => $sedutaId === null ? [] : $this->punti($a, (int) $sedutaId),
            'verbale' => $verbale === null ? null : $this->verbale($verbale, $dispositivo),
            'modelli' => ModelliDelVerbale::perIlMenu($a->condominio),
            'puo' => ['gestire' => AccessoAlleAssemblee::gestisce($utente), 'registrare' => AccessoAlleAssemblee::registraIlVerbale($utente)],
            'rotte' => $this->rotte($a, $verbale),
        ];
    }

    /** Il verbale da mostrare: quello dell'ultima riunione aperta o chiusa. */
    private function diAdesso(Assemblea $a, $riunioni): ?VerbaleDellAssemblea
    {
        $ultima = $riunioni->sortBy(fn ($s) => $s->aperta_il)->last();

        return $ultima === null ? null : VerbaliDellAssemblea::dellaSeduta($ultima);
    }

    /** @return array<string, mixed> */
    private function riunione(SedutaDellAssemblea $s): array
    {
        $v = VerbaliDellAssemblea::dellaSeduta($s);

        return ['seduta_id' => (int) $s->id, 'giorno' => $s->fissata_il?->format('Y-m-d'), 'convocazione' => $s->convocazione?->value, 'numero' => (int) $s->numero,
            'aperta_il' => $s->aperta_il?->copy()->utc()->toIso8601ZuluString(), 'chiusa_il' => $s->chiusa_il?->copy()->utc()->toIso8601ZuluString(),
            'esito' => $s->esito?->value, 'verbale' => $v === null ? null : ['id' => (int) $v->id, 'stato' => $v->stato?->value, 'numero' => $v->numero]];
    }

    /** @return array<string, mixed> */
    public function verbale(VerbaleDellAssemblea $v, ?string $dispositivo): array
    {
        $v->loadMissing(['seduta', 'assemblea.condominio', 'documento', 'registratoDa:id,name', 'annullatoDa:id,name', 'sostituisce:id,numero']);
        $condominio = $v->assemblea->condominio;
        $parti = ParteDelVerbale::query()->where('verbale_id', $v->id)->get();
        $registrato = $v->stato === StatoVerbale::REGISTRATO;
        $cifre = $v->stato === StatoVerbale::BOZZA ? $this->cifre->controlla($v) : ['avvisi' => [], 'sala_cambiata' => null, 'a_mano' => []];
        $partenza = $this->registro->partenza($condominio, (string) $v->registro);
        $punti = $this->punti($v->assemblea, (int) $v->seduta_id);

        return [
            'id' => (int) $v->id,
            'seduta_id' => (int) $v->seduta_id,
            'stato' => $v->stato?->value,
            'stato_parola' => $v->stato?->parola(),
            'registro' => RegistroDeiVerbali::perimetro($condominio, (string) $v->registro) + ['partenza' => $partenza,
                'numero_proposto' => $registrato ? null : $this->registro->prossimoNumero($condominio, (string) $v->registro)],
            'numero' => $v->numero,
            'modello' => $v->modello_nome === null ? null : ['id' => $v->modello_id, 'nome' => $v->modello_nome],
            'generato_il' => $v->generato_il?->copy()->utc()->toIso8601ZuluString(),
            'sala_cambiata' => $cifre['sala_cambiata'],
            'cifre' => $cifre['avvisi'],
            'a_mano' => $cifre['a_mano'] ?? [],
            // La bozza stampata, e se il testo o la sala sono cambiati dopo («Cambiato dopo la stampa»: la copia firmata dice un'altra cosa).
            'stampata' => $v->stampata === null ? null : (array) $v->stampata + ['cambiata' => app(RegistrazioneDelVerbale::class)->cambiatoDopoLaStampa($v)],
            'sottoscritto_il' => $v->sottoscritto_il?->format('Y-m-d'),
            'registrato_il' => $v->registrato_il?->copy()->utc()->toIso8601ZuluString(),
            'registrato_da' => $v->registratoDa?->name,
            'copia' => $v->documento === null ? null : ['id' => (int) $v->documento->id, 'nome' => (string) $v->documento->name, 'peso' => (int) $v->documento->file_size,
                'url' => route('admin.gestionale.assemblee.verbale.copia.show', [$v->assemblea->condominio_id, $v->assemblea_id, $v->id]),
                // L'ultima sostituzione: chi ha l'invio partito prima ha la copia di prima («rimandala»).
                'sostituita_il' => TrasmissioneDelVerbale::copiaSostituitaIl($v)?->copy()->utc()->toIso8601ZuluString()],
            'annullato' => $v->annullato_il === null ? null : ['il' => $v->annullato_il->copy()->utc()->toIso8601ZuluString(), 'motivo' => $v->annullato_motivo,
                'da' => $v->annullatoDa?->name],
            'sostituisce' => $v->sostituisce?->numero,
            'nel_libro_il' => $v->nel_libro_il?->format('Y-m-d'),
            'parti' => $parti->mapWithKeys(fn (ParteDelVerbale $p) => [$p->parte => ['documento' => $p->documento, 'revisione' => (int) $p->revisione,
                'impronta' => StrutturaDelVerbale::impronta((array) $p->documento), 'caratteri' => StrutturaDelVerbale::caratteri((array) $p->documento),
                'dispositivo' => $p->dispositivo, 'scritta_il' => $p->scritta_il?->copy()->utc()->toIso8601ZuluString()]])->all(),
            'punti' => $punti,
            'versioni' => $this->versioni->elenco($v, 'intero'),
            'in_attesa' => VersioneDelVerbale::query()->where('verbale_id', $v->id)->where('in_attesa', true)->whereNull('scelta_il')->orderBy('id')->get()
                ->map(fn (VersioneDelVerbale $x) => VersioniDelVerbale::voce($x) + ['nome_della_parte' => VersioniDelVerbale::nomeDellaParte($v, $x->parte),
                    'documento' => $x->documento])->values()->all(),
            'chi_scrive' => $dispositivo === null ? null : collect(['intero', ...$parti->pluck('parte')->all()])->unique()
                ->mapWithKeys(fn ($parte) => [$parte => $this->chiScrive->stato($v, $parte, $dispositivo)])->all(),
            'trasmissione' => $registrato ? $this->destinatari->per($v) : null,
            'termini' => $registrato ? ['delibere' => $this->termini->perIlVerbale($v), 'per_tutti' => $this->termini->perTuttiIlVerbale($v)] : null,
        ];
    }

    /**
     * I punti di una riunione con la riga della sala e le dichiarazioni: le card della discussione, anche prima che il verbale nasca.
     *
     * @return list<array<string, mixed>>
     */
    private function punti(Assemblea $a, int $sedutaId): array
    {
        $lettura = app(\App\Services\Assemblee\Sala\LetturaDellaSala::class)->perIlVerbale($a);

        return collect($lettura['punti'])->map(fn ($p) => ['punto_id' => (int) $p['punto_id'], 'numero' => (int) $p['numero'], 'testo' => (string) $p['testo'],
            'parte' => 'punto:' . $p['punto_id'], 'votazioni' => collect($p['votazioni'])->where('seduta_id', $sedutaId)->map(fn ($x) => ['id' => (int) $x['id'],
                'esito' => $x['esito'], 'riga' => self::rigaDellaSala($x)])->values()->all(),
            'dichiarazioni' => collect(collect($lettura['sedute'])->firstWhere('id', $sedutaId)['dichiarazioni'] ?? [])
                ->where('punto_id', (int) $p['punto_id'])->values()->all()])->values()->all();
    }

    /**
     * Il passo 5 della pagina dell'assemblea, quando si apre: le riunioni con il loro verbale (una sospesa e la sua prosecuzione sono due righe), e le rotte.
     *
     * @return array<string, mixed>
     */
    public function perIlPasso(Assemblea $a): array
    {
        $a->loadMissing(['condominio', 'sedute']);
        $verbale = $this->diAdesso($a, VerbaliDellAssemblea::riunioni($a));

        return [
            'riunioni' => VerbaliDellAssemblea::riunioni($a)->sortBy(fn ($s) => $s->aperta_il)->map(fn (SedutaDellAssemblea $s) => $this->riunione($s)
                + ['url' => route('admin.gestionale.assemblee.verbale.pagina', [$a->condominio_id, $a->id])
                    . (($v = VerbaliDellAssemblea::dellaSeduta($s)) !== null ? '?verbale=' . $v->id : '')])->values()->all(),
            'rotte' => $this->rotte($a, $verbale),
        ];
    }

    /** «Approvato · 9 favorevoli per 506,40 millesimi, 2 contrari, 1 astenuto, 1 non ha votato». @param array<string, mixed> $x */
    public static function rigaDellaSala(array $x): string
    {
        if (($x['maggioranza'] ?? null) === 'senza_voto') {
            return 'Trattato senza voto';
        }
        $c = (array) ($x['conteggi'] ?? []);
        $n = fn (string $k) => (int) ($c[$k]['teste'] ?? 0);
        $pezzi = [$n('favorevoli') . ' favorevoli per ' . ParoleDelVerbale::millesimi((int) ($c['favorevoli']['valore'] ?? 0)) . ' millesimi',
            $n('contrari') === 1 ? '1 contrario' : $n('contrari') . ' contrari', $n('astenuti') === 1 ? '1 astenuto' : $n('astenuti') . ' astenuti'];
        if ($n('non_votato') > 0) {
            $pezzi[] = $n('non_votato') === 1 ? '1 non ha votato' : $n('non_votato') . ' non hanno votato';
        }

        return (\App\Enums\Assemblee\EsitoDelibera::parolaDi($x['esito'] ?? null) ?? 'Da confermare') . ' · ' . implode(', ', $pezzi);
    }

    /** @return array<string, ?string> */
    private function rotte(Assemblea $a, ?VerbaleDellAssemblea $v): array
    {
        $p = [$a->condominio_id, $a->id];
        $pv = $v === null ? null : [$a->condominio_id, $a->id, $v->id];

        return [
            'pagina' => route('admin.gestionale.assemblee.verbale.pagina', $p),
            'stato' => route('admin.gestionale.assemblee.verbale.stato', $p),
            'gesti' => route('admin.gestionale.assemblee.verbale.gesti', $p),
            'genera' => route('admin.gestionale.assemblee.verbale.genera', $p),
            'sala' => route('admin.gestionale.assemblee.sala', $p),
            'assemblea' => route('admin.gestionale.assemblee.show', $p),
            'registro' => route('admin.gestionale.assemblee.registro', [$a->condominio_id, 'registro' => VerbaliDellAssemblea::registroDi($a),
                ...($v === null ? ['da_assemblea' => $a->id] : ['da_verbale' => $v->id])]),
            'aggiorna_sala' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.aggiorna_sala', $pv),
            'bozza' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.bozza', $pv),
            'registra' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.registra', $pv),
            'copia' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.copia', $pv),
            'annulla' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.annulla', $pv),
            'libro' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.libro', $pv),
            'libro_pagina' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.libro.pagina', $pv),
            'scrivo' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.scrivo', $pv),
            'chiedi' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.chiedi', $pv),
            'rispondi' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.rispondi', $pv),
            'togli' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.destinatari.togli', $pv),
            'aggiungi' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.destinatari.aggiungi', $pv),
            'pec' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.pec', $pv),
            'lettere' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.lettere', $pv),
            'spedizione' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.spedizione', $pv),
            'prova' => $pv === null ? null : route('admin.gestionale.assemblee.verbale.prova', $pv),
        ];
    }
}
