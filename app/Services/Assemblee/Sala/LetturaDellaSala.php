<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\EsitoDelGesto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Helpers\DateHelper;
use App\Http\Controllers\Assemblee\PerLePagine;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\DichiarazioneAVerbale;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Assemblee\VotoInSala;
use App\Models\ImpostazioniCondominio;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Settings\AssembleeSettings;

/**
 * La sala come la leggono le pagine e i documenti, dallo stato del server.
 *
 * - {@see perLaPagina()}: la prop `sala` della pagina della sala (e della lettura periodica), la forma `SalaDellAssemblea` di
 *   `resources/js/types/assemblee.ts`: le sedute, la fotografia (della seduta aperta, o provvisoria), le teste con chi le porta, la barra, le presenze con
 *   le loro versioni, le deleghe con il quinto, i punti con i loro voti, le dichiarazioni, il controllo dell'apertura, le scelte aperte fra due versioni, le
 *   impostazioni (ferme all'apertura), il foglio della sala (quando è stato stampato), il verbale della seduta aperta con la discussione di ogni punto, le
 *   rotte (anche delle stampe, dell'operaio senza rete e della pagina del verbale), l'ora del server. A sala chiusa, l'ultima seduta com'era: le presenze e
 *   la discussione di ogni punto scritta in sala (decisione 660).
 * - {@see perIlVerbale()}: ciò che il verbale e il seguito delle delibere leggono della sala, per ogni seduta e per ogni punto: le presenze con l'ora, i
 *   voti con i nomi e i millesimi, gli esiti confermati, le delibere, i conflitti, le dichiarazioni, l'impronta della fotografia; e le deleghe. Per il verbale
 *   anche: l'assemblea (il tipo, il luogo, la modalità, il perimetro), la convocazione (i giorni della spedizione e i mezzi), per ogni seduta il luogo, la
 *   costituzione (le teste e i millesimi presenti al primo voto, o alla chiusura) con chi non è mai entrato, la seduta in cui prosegue la sospesa e l'assemblea
 *   riconvocata della rinviata; per ogni punto la materia, la maggioranza e il perimetro.
 */
final class LetturaDellaSala
{
    public function __construct(
        private readonly FotografiaDellaSeduta $fotografie,
        private readonly ControlloDellApertura $controllo,
    ) {}

    /** @return array<string, mixed> */
    public function perLaPagina(Assemblea $a, ?User $utente): array
    {
        $a->loadMissing('condominio');
        $sedute = $a->sedute()->get();
        $aperta = StatiDellaSala::sedutaAperta($a);
        $ultima = $aperta ?? $sedute->filter(fn (SedutaDellAssemblea $s) => $s->aperta_il !== null)->sortByDesc('aperta_il')->first();
        $fotografia = $ultima?->fotografia ?? ($a->stato === StatoAssemblea::IN_PREPARAZIONE ? null : $this->fotografie->provvisoria($a));
        $presenti = $aperta !== null ? PresenzeDellaSala::presenti($aperta) : ['persone' => [], 'rappresenta' => []];
        $teste = [];
        // Prima di «Apri l'assemblea» la card delle persone è l'elenco dei convocati, con il mezzo della convocazione (rilievo C48).
        $mezzi = \App\Models\Assemblee\ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->get(['anagrafica_id', 'mezzo'])
            ->mapWithKeys(fn ($c) => [(int) $c->anagrafica_id => $c->mezzo?->value])->filter()->all();
        foreach ((array) ($fotografia['costituzione']['teste'] ?? []) as $t) {
            $chi = PresenzeDellaSala::chiPorta($t, $presenti);
            $come = $chi === null ? null : ($chi['ruolo'] === 'delegato' ? 'per_delega' : 'di_persona');
            $teste[] = $t + ['presente' => $chi === null ? null : ($come === 'per_delega' ? $chi['nome'] . ', per delega' : $chi['nome']), 'come' => $come,
                'convocato_con' => self::convocatoCon((array) $t['persone'], $mezzi)];
        }
        $conteggio = PresenzeDellaSala::conteggio((array) ($fotografia['costituzione']['teste'] ?? []), $presenti);
        $barra = $fotografia === null ? null : ContiDellaSala::costituzione((string) $fotografia['convocazione'], (int) $fotografia['costituzione']['totale_teste'],
            (int) $fotografia['costituzione']['totale_valore'], $conteggio['teste'], $conteggio['valore']) + ['presenti' => array_keys($presenti['persone'])];
        $daAprire = $aperta === null ? $this->daAprire($a, $sedute) : null;

        return [
            'assemblea' => [
                'id' => (int) $a->id, 'nome' => $a->nome(), 'titolo' => PerLePagine::titolo($a, $a->condominio), 'stato' => $a->stato?->value,
                'stato_parola' => PerLePagine::statoParola($a), 'modalita' => $a->modalita?->value, 'piattaforma' => $a->piattaforma,
                'consenso_videoconferenza_il' => $a->consenso_videoconferenza_il?->format('Y-m-d'), 'luogo' => $a->luogo,
            ],
            'ora_del_server' => now()->utc()->toIso8601ZuluString(),
            'sedute' => $sedute->map(fn (SedutaDellAssemblea $s) => self::seduta($s))->values()->all(),
            'seduta_id' => $aperta?->id === null ? null : (int) $aperta->id,
            'da_aprire' => $daAprire,
            'fotografia' => $fotografia,
            'impronta' => $ultima?->impronta_fotografia,
            'teste' => $teste,
            'barra' => $barra,
            'chiusura' => $aperta === null && $fotografia !== null ? self::chiusura($ultima, $fotografia) : null,
            // A sala chiusa la pagina si legge com'era (decisione 660): le presenze dell'ultima seduta, con le ore di entrata e di uscita, senza mescolarle a quelle
            // della sala aperta (`presenze`, che a sala chiusa resta vuota); e la discussione di ogni punto scritta in sala, che il verbale riporta (la prova a
            // video del 22/11/2026: la sala chiusa non la mostrava, e sembrava persa).
            'ultima_seduta' => $aperta !== null || $ultima === null ? null : ['seduta_id' => (int) $ultima->id,
                'presenze' => PresenzaInSala::query()->where('seduta_id', $ultima->id)->orderBy('entrata_il')->orderBy('id')->get()
                    ->map(fn (PresenzaInSala $p) => self::presenza($p))->values()->all(),
                'discussioni' => self::discussioniDelleRiunioni($sedute)],
            'presenze' => $aperta === null ? [] : PresenzaInSala::query()->where('seduta_id', $aperta->id)->orderBy('entrata_il')->orderBy('id')->get()
                ->map(fn (PresenzaInSala $p) => self::presenza($p))->values()->all(),
            'versioni' => $aperta === null ? [] : PresenzeDellaSala::versioni((int) $aperta->id),
            'deleghe' => DelegaDellAssemblea::query()->where('assemblea_id', $a->id)->orderBy('id')->get()->map(fn (DelegaDellAssemblea $d) => self::delega($d, (int) $a->condominio_id))->values()->all(),
            'punti' => $this->punti($a, $aperta),
            'dichiarazioni' => DichiarazioneAVerbale::query()->whereIn('seduta_id', $sedute->pluck('id'))->orderBy('id')->get()
                ->map(fn (DichiarazioneAVerbale $d) => ['id' => (int) $d->id, 'seduta_id' => (int) $d->seduta_id, 'punto_id' => $d->punto_id, 'anagrafica_id' => $d->anagrafica_id,
                    'nome' => $d->nome, 'testo' => $d->testo])->values()->all(),
            // La fotografia provvisoria è già quella della seduta da aprire: il controllo non la rilegge (la lettura periodica passa ogni dieci secondi).
            'controllo' => $daAprire !== null && $daAprire['si_apre'] && $fotografia !== null
                ? $this->controllo->controlla($a, $sedute->firstWhere('id', $daAprire['seduta_id']) ?? SedutaDellAssemblea::query()->findOrFail($daAprire['seduta_id']),
                    $ultima === null ? $fotografia : null) : null,
            // I testi del verbale in conflitto hanno le loro due versioni nel verbale: qui solo i gesti della sala.
            'conflitti' => GestoDellaSala::query()->where('assemblea_id', $a->id)->where('esito', EsitoDelGesto::IN_CONFLITTO->value)->whereNull('scelto_il')
                ->where('tipo', '!=', \App\Services\Assemblee\Verbale\GestiDelVerbale::NEL_LIBRO)->orderBy('id')->get()
                ->map(fn (GestoDellaSala $x) => ['gesto' => $x->gesto, 'tipo' => $x->tipo, 'dispositivo' => $x->dispositivo, 'questa' => $x->conflitto['questa'] ?? null,
                    'quella' => $x->conflitto['quella'] ?? null, 'su' => $x->conflitto['su'] ?? null, 'segnato_il' => $x->segnato_il?->toIso8601ZuluString(),
                    'quella_dispositivo' => $x->conflitto['quella_dispositivo'] ?? null, 'quella_segnato_il' => $x->conflitto['quella_segnato_il'] ?? null])->values()->all(),
            'impostazioni' => ImpostazioniDellaSala::perLaSala((int) $a->condominio_id, $aperta?->fotografia),
            'foglio' => self::foglio($a),
            'verbale' => self::verbaleDellaSala($aperta),
            'puo_gestire' => AccessoAlleAssemblee::gestisce($utente),
            'rotte' => [
                'gesti' => route('admin.gestionale.assemblee.sala.gesti', [$a->condominio_id, $a->id]),
                'stato' => route('admin.gestionale.assemblee.sala.stato', [$a->condominio_id, $a->id]),
                'pagina' => route('admin.gestionale.assemblee.sala', [$a->condominio_id, $a->id]),
                'assemblea' => route('admin.gestionale.assemblee.show', [$a->condominio_id, $a->id]),
                'deleghe' => route('admin.gestionale.assemblee.deleghe.store', [$a->condominio_id, $a->id]),
                'foglio' => route('admin.gestionale.assemblee.sala.foglio', [$a->condominio_id, $a->id]),
                'foglio_compilato' => $aperta === null ? null : route('admin.gestionale.assemblee.sala.foglio', [$a->condominio_id, $a->id, 'compilato' => 1]),
                'prospetto' => route('admin.gestionale.assemblee.sala.prospetto', [$a->condominio_id, $a->id]),
                'senza_rete' => route('admin.gestionale.assemblee.sala.senza_rete', [$a->condominio_id, $a->id]),
                'verbale' => route('admin.gestionale.assemblee.verbale.pagina', [$a->condominio_id, $a->id, 'da' => 'sala']),
                'registro' => route('admin.gestionale.assemblee.registro', [$a->condominio_id, 'registro' => \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::registroDi($a),
                    'da_assemblea' => $a->id]),
            ],
        ];
    }

    /**
     * Com'è finita la sala in una riga, con le parole della barra della costituzione: «Chiusa alle 08:00 · costituita in prima convocazione con 3 su 4». Null
     * se nessuna seduta è stata chiusa dopo una riunione tenuta.
     */
    public static function comEFinita(Assemblea $a): ?string
    {
        $ultima = $a->sedute()->get()->filter(fn (SedutaDellAssemblea $s) => $s->aperta_il !== null)->sortByDesc('aperta_il')->first();
        $c = $ultima?->fotografia === null ? null : self::chiusura($ultima, (array) $ultima->fotografia);
        if ($c === null) {
            return null;
        }

        return 'Chiusa alle ' . $c['alle'] . ' · ' . ($c['costituita'] ? 'costituita' : 'non costituita') . ' in ' . ($c['convocazione'] === 'seconda' ? 'seconda' : 'prima')
            . ' convocazione con ' . $c['teste'] . ' su ' . $c['totale_teste'];
    }

    /**
     * Com'è finita la sala, a sala chiusa: l'ora della chiusura (a Roma), la convocazione, se l'assemblea era costituita e i presenti un attimo prima della
     * chiusura (chi c'era, che la chiusura fa uscire tutti). Null finché la seduta non è chiusa.
     *
     * @param array<string, mixed> $fotografia
     * @return ?array{alle: string, convocazione: string, costituita: bool, teste: int, totale_teste: int, valore: int, totale_valore: int}
     */
    private static function chiusura(?SedutaDellAssemblea $seduta, array $fotografia): ?array
    {
        if ($seduta?->chiusa_il === null || $seduta->esito !== \App\Enums\Assemblee\EsitoSeduta::TENUTA) {
            return null;
        }
        // La costituzione vera della seduta (la stessa del verbale): i presenti al momento in cui l'assemblea si costituisce, non quelli «di adesso», che a sala
        // chiusa sono usciti tutti con la chiusura (decisione 660).
        $c = self::costituzioneDellaSeduta($seduta, VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->get());
        if ($c === null) {
            return null;
        }

        return ['alle' => $seduta->chiusa_il->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i'),
            'convocazione' => (string) $fotografia['convocazione'], 'costituita' => (bool) $c['costituita'], 'teste' => (int) $c['teste'], 'totale_teste' => (int) $c['totale_teste'],
            'valore' => (int) $c['valore'], 'totale_valore' => (int) $c['totale_valore']];
    }

    /**
     * Il verbale della seduta aperta, per la discussione sotto il voto di ogni punto: l'id (nullo finché nessuno scrive: il primo testo manda `seduta_id` e il
     * verbale nasce), lo stato e, per ogni punto già scritto, il testo con la sua revisione, che è la `base` del prossimo gesto `testo`.
     *
     * @return ?array{seduta_id: int, id: ?int, stato: ?string, discussioni: array<string, array{parte: string, documento: array<string, mixed>, revisione: int, impronta: string}>}
     */
    private static function verbaleDellaSala(?SedutaDellAssemblea $aperta): ?array
    {
        if ($aperta === null) {
            return null;
        }
        $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::dellaSeduta($aperta);

        return ['seduta_id' => (int) $aperta->id, 'id' => $verbale?->id === null ? null : (int) $verbale->id, 'stato' => $verbale?->stato?->value,
            'discussioni' => self::discussioniDelVerbale($verbale)];
    }

    /**
     * Le discussioni scritte di un verbale, per punto (`punto:<id>` → l'id del punto): il testo con la sua revisione e l'impronta.
     *
     * @return array<string, array{parte: string, documento: array<string, mixed>, revisione: int, impronta: string}>
     */
    private static function discussioniDelVerbale(?\App\Models\Assemblee\VerbaleDellAssemblea $verbale): array
    {
        $discussioni = [];
        if ($verbale !== null) {
            foreach (\App\Models\Assemblee\ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'like', 'punto:%')->orderBy('id')->get() as $p) {
                $discussioni[substr((string) $p->parte, 6)] = ['parte' => (string) $p->parte, 'documento' => (array) $p->documento, 'revisione' => (int) $p->revisione,
                    'impronta' => \App\Services\Assemblee\Verbale\StrutturaDelVerbale::impronta((array) $p->documento)];
            }
        }

        return $discussioni;
    }

    /**
     * A sala chiusa, le discussioni scritte in sala di tutte le riunioni tenute, per punto: un punto trattato in una riunione e proseguito in un'altra ha il
     * testo dell'ultima che lo ha scritto.
     *
     * @param \Illuminate\Support\Collection<int, SedutaDellAssemblea> $sedute
     * @return array<string, array{parte: string, documento: array<string, mixed>, revisione: int, impronta: string}>
     */
    private static function discussioniDelleRiunioni($sedute): array
    {
        $discussioni = [];
        foreach ($sedute->filter(fn (SedutaDellAssemblea $s) => $s->aperta_il !== null)->sortBy('aperta_il') as $s) {
            $discussioni = array_replace($discussioni, self::discussioniDelVerbale(\App\Services\Assemblee\Verbale\VerbaliDellAssemblea::dellaSeduta($s)));
        }

        return $discussioni;
    }

    /**
     * Ciò che il verbale (e il seguito delle delibere) leggono della sala: per ogni seduta l'apertura, la chiusura, l'esito, presidente e segretario, la scelta
     * dell'apertura, l'impronta della fotografia, le presenze con l'ora e le dichiarazioni; per ogni punto le votazioni con gli intervenuti, i voti con i nomi
     * e i millesimi, i conflitti, l'esito proposto e quello confermato, e la delibera.
     *
     * @return array<string, mixed>
     */
    public function perIlVerbale(Assemblea $a): array
    {
        $sedute = $a->sedute()->get();
        $votazioni = VotazioneDelPunto::query()->whereIn('seduta_id', $sedute->pluck('id'))->with(['voti', 'delibera'])->orderBy('id')->get()->groupBy('punto_id');

        $a->loadMissing(['condominio', 'scala', 'palazzina']);
        $tutteLeVotazioni = $votazioni->flatten(1);

        return [
            'assemblea_id' => (int) $a->id,
            'stato' => $a->stato?->value,
            'assemblea' => [
                'tipo' => $a->tipo?->value, 'modalita' => $a->modalita?->value, 'luogo' => $a->luogo, 'piattaforma' => $a->piattaforma,
                'ambito' => $a->ambito?->value, 'perimetro' => match ($a->ambito?->value) {
                    'scala' => $a->scala?->name !== null ? 'scala ' . $a->scala->name : null,
                    'palazzina' => $a->palazzina?->name !== null ? 'palazzina ' . $a->palazzina->name : null,
                    default => null,
                },
                'condominio' => (string) $a->condominio?->nome,
                'riconvocata' => \App\Models\Assemblee\Assemblea::query()->where('riconvocata_da_id', $a->id)->orderBy('id')->first()?->only(['id', 'data']),
            ],
            'convocazione' => self::spedizioneDellaConvocazione($a),
            'sedute' => $sedute->map(fn (SedutaDellAssemblea $s) => self::seduta($s) + [
                'fissata_il' => $s->fissata_il?->format('Y-m-d H:i'),
                'luogo' => filled($s->luogo) ? $s->luogo : $a->luogo,
                'apertura' => $s->apertura,
                'impronta_fotografia' => $s->impronta_fotografia,
                'costituzione' => self::costituzioneDellaSeduta($s, $tutteLeVotazioni->where('seduta_id', $s->id)),
                'presenze' => $s->presenze()->get()->map(fn (PresenzaInSala $p) => self::presenza($p))->values()->all(),
                'dichiarazioni' => $s->dichiarazioni()->get()->map(fn (DichiarazioneAVerbale $d) => ['id' => (int) $d->id, 'punto_id' => $d->punto_id,
                    'anagrafica_id' => $d->anagrafica_id, 'nome' => $d->nome, 'testo' => $d->testo])->values()->all(),
                'prosegue' => self::prosecuzione($s, $sedute),
            ])->values()->all(),
            'punti' => $a->punti()->get()->map(fn ($p) => [
                'punto_id' => (int) $p->id,
                'numero' => (int) $p->numero,
                'testo' => $p->testo,
                'materia' => $p->materia?->value,
                'maggioranza' => $p->maggioranza?->value,
                'ambito' => $p->ambito?->value,
                'votazioni' => ($votazioni[$p->id] ?? collect())->map(fn (VotazioneDelPunto $v) => self::votazione($v))->values()->all(),
                'delibere' => $p->delibere()->get()->map(fn ($d) => ['id' => (int) $d->id, 'esito' => $d->esito?->value, 'testo' => $d->testo,
                    'deliberata_il' => $d->deliberata_il?->format('Y-m-d'), 'votazione' => (int) $d->votazione, 'oggetto' => $d->oggetto])->values()->all(),
            ])->values()->all(),
            // Le deleghe dell'assemblea, per il verbale: chi ha delegato chi, per quali punti, come è arrivata, lo stato con il motivo (le rifiutate, le
            // revocate, le decisioni del presidente sulla delega oltre il quinto).
            'deleghe' => DelegaDellAssemblea::query()->where('assemblea_id', $a->id)->orderBy('id')->get()->map(fn (DelegaDellAssemblea $d) => [
                'delegante' => (string) $d->delegante_nome, 'delegato' => (string) $d->delegato_nome, 'punti' => $d->punti, 'arrivata_come' => $d->arrivata_come?->value,
                'ricevuta_il' => $d->ricevuta_il?->format('Y-m-d'), 'segnata_il' => $d->segnata_il?->toIso8601ZuluString(), 'stato' => $d->stato?->value,
                'da_decidere' => (bool) ($d->dati['da_decidere'] ?? false), 'decisa_il' => $d->dati['decisa_il'] ?? null, 'motivo' => $d->motivo,
                'revocata_il' => $d->revocata_il?->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }

    /**
     * La costituzione della seduta per il verbale: le teste e i millesimi presenti (di persona o per delega) **al momento in cui l'assemblea si costituisce**
     * (il primo in cui i conti bastano, a partire dall'apertura; `costituita` vero, con l'ora in `al`); se non risulta mai costituita, al primo voto o alla
     * chiusura se non si è votato (`costituita` falso), sulla fotografia dell'apertura; con chi non è mai entrato (le teste della costituzione mai presenti). Null per una seduta non aperta.
     *
     * @param \Illuminate\Support\Collection<int, VotazioneDelPunto> $votazioni
     * @return ?array<string, mixed>
     */
    public static function costituzioneDellaSeduta(SedutaDellAssemblea $s, $votazioni): ?array
    {
        $fotografia = $s->fotografia;
        if ($s->aperta_il === null || ! is_array($fotografia)) {
            return null;
        }
        $primo = $votazioni->sortBy('votata_il')->first();
        $teste = (array) ($fotografia['costituzione']['teste'] ?? []);
        $totaleTeste = (int) ($fotografia['costituzione']['totale_teste'] ?? 0);
        $totaleValore = (int) ($fotografia['costituzione']['totale_valore'] ?? 0);
        $convocazione = (string) ($fotografia['convocazione'] ?? '');
        // Dal foglio della sala le ore si scrivono al minuto: ciò che è segnato nello stesso minuto vale insieme (una presenza alle 08:00 è presente
        // all'apertura delle 08:00, qualunque sia l'ordine in cui è stata scritta).
        $momento = fn (\DateTimeInterface $t) => $s->dal_foglio ? \Illuminate\Support\Carbon::instance($t)->endOfMinute() : $t;
        // La costituzione è il primo momento in cui i conti bastano (le entrate, a partire dall'apertura); se non risulta mai costituita, i presenti al
        // primo voto o alla chiusura (la frase del verbale lo dice).
        $fine = $s->chiusa_il ?? now();
        $momenti = PresenzaInSala::query()->where('seduta_id', $s->id)->where('entrata_il', '>=', $s->aperta_il)->orderBy('entrata_il')->pluck('entrata_il')
            ->prepend($s->aperta_il)->filter(fn ($t) => $t->lte($fine))->unique(fn ($t) => $t->toIso8601ZuluString())->values();
        $costituitaAlle = null;
        foreach ($momenti as $t) {
            $c = PresenzeDellaSala::conteggio($teste, PresenzeDellaSala::presenti($s, $momento($t), null, [], true));
            if (ContiDellaSala::costituzione($convocazione, $totaleTeste, $totaleValore, $c['teste'], $c['valore'])['costituita']) {
                $costituitaAlle = $t;
                break;
            }
        }
        $al = $costituitaAlle ?? $primo?->votata_il ?? $s->chiusa_il ?? now();
        $conto = PresenzeDellaSala::conteggio($teste, PresenzeDellaSala::presenti($s, $momento($al), null, [], true));
        $mai = [];
        $righe = PresenzaInSala::query()->where('seduta_id', $s->id)->get();
        $entrati = $righe->pluck('anagrafica_id')->filter()->map(fn ($id) => (int) $id)->all();
        $perDelega = DelegaDellAssemblea::query()->where('assemblea_id', $s->assemblea_id)->where('stato', 'valida')->get()
            ->filter(fn (DelegaDellAssemblea $d) => $righe->contains('chiave', PresenzeDellaSala::chiaveDelDelegato($d)))
            ->pluck('delegante_anagrafica_id')->filter()->map(fn ($id) => (int) $id)->all();
        foreach ($teste as $t) {
            $persone = array_map('intval', (array) $t['persone']);
            if (array_intersect($persone, [...$entrati, ...$perDelega]) === []) {
                $mai[] = ['chiave' => (string) $t['chiave'], 'nome' => (string) $t['nome'], 'millesimi' => (int) $t['millesimi']];
            }
        }

        return ['al' => \Carbon\Carbon::instance($al)->utc()->toIso8601ZuluString(), 'al_primo_voto' => $costituitaAlle === null && $primo !== null,
            'costituita' => $costituitaAlle !== null, 'teste' => $conto['teste'],
            'valore' => $conto['valore'], 'totale_teste' => $totaleTeste, 'totale_valore' => $totaleValore, 'convocazione' => $convocazione,
            'mai_entrati' => $mai];
    }

    /** La seduta in cui prosegue una seduta sospesa: la prossima della stessa convocazione. @return ?array{id: int, fissata_il: string} */
    private static function prosecuzione(SedutaDellAssemblea $s, $sedute): ?array
    {
        if ($s->esito?->value !== 'sospesa') {
            return null;
        }
        $dopo = $sedute->first(fn (SedutaDellAssemblea $x) => $x->convocazione === $s->convocazione && (int) $x->numero > (int) $s->numero);

        return $dopo === null ? null : ['id' => (int) $dopo->id, 'fissata_il' => $dopo->fissata_il->format('Y-m-d H:i')];
    }

    /**
     * La spedizione della convocazione, per la frase del verbale: il primo e l'ultimo giorno in cui un avviso è partito (la PEC il giorno dell'invio, la carta il
     * giorno della spedizione o della consegna registrata), e quanti per mezzo.
     *
     * @return array{dal: ?string, al: ?string, mezzi: array<string, int>}
     */
    public static function spedizioneDellaConvocazione(Assemblea $a): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $giorni = [];
        $mezzi = [];
        $invii = \App\Models\Gestionale\RegistroInvio::query()->where('documentabile_type', $a->getMorphClass())->where('documentabile_id', $a->id)
            ->where('tipo', \App\Models\Gestionale\RegistroInvio::TIPO_CONVOCAZIONE)
            ->whereIn('stato', [\App\Models\Gestionale\RegistroInvio::STATO_INVIATA, \App\Models\Gestionale\RegistroInvio::STATO_SPEDITA])->get();
        foreach ($invii as $r) {
            $mezzo = (string) ($r->dati['mezzo'] ?? ($r->canale === 'pec' ? 'pec' : ''));
            $giorno = $r->canale === 'pec' ? $r->emesso_il?->copy()->setTimezone($fuso)->format('Y-m-d') : ($r->dati['spedizione']['data'] ?? $r->ricevuta_il?->format('Y-m-d'));
            if ($giorno !== null) {
                $giorni[] = (string) $giorno;
            }
            if ($mezzo !== '') {
                $mezzi[$mezzo] = ($mezzi[$mezzo] ?? 0) + 1;
            }
        }
        sort($giorni);

        return ['dal' => $giorni[0] ?? null, 'al' => $giorni === [] ? null : end($giorni), 'mezzi' => $mezzi];
    }

    /** La seduta da aprire adesso: la prossima senza esito, e se si apre oggi. @return ?array{seduta_id: int, si_apre: bool, perche: ?string} */
    private function daAprire(Assemblea $a, $sedute): ?array
    {
        if (! in_array($a->stato, [StatoAssemblea::CONVOCATA, StatoAssemblea::IN_CORSO], true)) {
            return null;
        }
        $prossima = $sedute->first(fn (SedutaDellAssemblea $s) => $s->aperta_il === null && $s->esito === null);
        if ($prossima === null) {
            return null;
        }
        $oggi = DateHelper::oggiUtente();
        $giorno = $prossima->fissata_il->format('Y-m-d');

        return ['seduta_id' => (int) $prossima->id, 'convocazione' => $prossima->convocazione?->value, 'numero' => (int) $prossima->numero, 'si_apre' => $oggi >= $giorno,
            'perche' => $oggi >= $giorno ? null : 'La sala si apre il giorno della seduta, il ' . $prossima->fissata_il->format('d/m/Y') . '.'];
    }

    /**
     * I punti con i loro voti e l'esito; per il punto di una parte del condominio (la sola scala, con la sua tabella), con la seduta aperta, il conto della
     * parte adesso: la riga della barra, che avvisa e non blocca.
     *
     * @return list<array<string, mixed>>
     */
    private function punti(Assemblea $a, ?SedutaDellAssemblea $aperta): array
    {
        $votazioni = VotazioneDelPunto::query()->whereIn('seduta_id', $a->sedute()->pluck('id'))->with('voti')->orderBy('id')->get()->groupBy('punto_id');
        $conIlPerimetro = collect((array) ($aperta?->fotografia['punti'] ?? []))->filter(fn ($v) => ($v['perimetro'] ?? null) !== null)->pluck('punto_id')
            ->map(fn ($id) => (int) $id)->all();

        return $a->punti()->with('delibere')->get()->map(fn ($p) => [
            'perimetro' => in_array((int) $p->id, $conIlPerimetro, true) ? VotazioniDellaSala::intervenuti($aperta, $p, [], now())['perimetro'] : null,
            'id' => (int) $p->id, 'numero' => (int) $p->numero, 'testo' => $p->testo, 'materia' => $p->materia?->value, 'maggioranza' => $p->maggioranza?->value,
            'maggioranza_parola' => $p->maggioranza?->parola(), 'maggioranza_libera' => $p->maggioranza_libera, 'amministrazione' => $p->amministrazione?->value,
            'conduttore' => $p->conduttore?->value, 'ambito' => $p->ambito?->value,
            // Il motivo della spesa (decisione 581): chi riferisce in sala lo legge sotto il titolo, come l'avviso.
            'motivazione' => \App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni::notaDellaMotivazione((array) $p->dati),
            'votazioni' => ($votazioni[$p->id] ?? collect())->map(fn (VotazioneDelPunto $v) => self::votazione($v))->values()->all(),
            'esito' => $p->delibere->sortBy('votazione')->last()?->esito?->value,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public static function seduta(SedutaDellAssemblea $s): array
    {
        return ['id' => (int) $s->id, 'convocazione' => $s->convocazione?->value, 'numero' => (int) $s->numero, 'giorno' => $s->fissata_il?->format('Y-m-d'),
            'ora' => $s->fissata_il?->format('H:i'), 'aperta_il' => $s->aperta_il?->toIso8601ZuluString(), 'chiusa_il' => $s->chiusa_il?->toIso8601ZuluString(),
            'esito' => $s->esito?->value, 'presidente' => $s->presidente, 'segretario' => $s->segretario, 'dal_foglio' => (bool) $s->dal_foglio,
            'apertura_da_decidere' => ($s->apertura['scelta'] ?? null) === StatiDellaSala::DA_DECIDERE
                ? array_values(array_column((array) ($s->apertura['problemi'] ?? []), 'frase')) : null];
    }

    /** @return array<string, mixed> */
    /**
     * Con che cosa è convocata una testa, per la card delle persone prima dell'apertura: «raccomandata», «PEC», «a mano»; i comproprietari con un avviso
     * ciascuno con lo stesso mezzo «raccomandata, una a testa», con mezzi diversi «raccomandata e PEC». Null se nessuno della testa ha un avviso fissato.
     *
     * @param list<int|string> $persone
     * @param array<int, string> $mezzi anagrafica => mezzo
     */
    public static function convocatoCon(array $persone, array $mezzi): ?string
    {
        $parola = fn (string $m) => ['pec' => 'PEC', 'raccomandata' => 'raccomandata', 'a_mano' => 'a mano', 'fax' => 'fax'][$m] ?? $m;
        $suoi = array_values(array_filter(array_map(fn ($p) => $mezzi[(int) $p] ?? null, $persone)));
        if ($suoi === []) {
            return null;
        }
        $parole = array_values(array_unique(array_map($parola, $suoi)));
        if (count($suoi) > 1 && count($parole) === 1) {
            return $parole[0] . ', una a testa';
        }

        return TesteDellaSala::elenco($parole);
    }

    public static function presenza(PresenzaInSala $p): array
    {
        return ['id' => (int) $p->id, 'chiave' => $p->chiave, 'anagrafica_id' => $p->anagrafica_id, 'nome' => $p->nome, 'come' => $p->come?->value,
            'entrata_il' => $p->entrata_il?->toIso8601ZuluString(), 'uscita_il' => $p->uscita_il?->toIso8601ZuluString(), 'caduto' => (bool) $p->caduto,
            'rappresenta' => $p->rappresenta, 'motivo_collegamento' => $p->motivo_collegamento, 'insieme' => $p->insieme];
    }

    /**
     * Il foglio della sala: quando è stato stampato l'ultima volta, e se va ristampato perché una delega è cambiata dopo (segnata, revocata, rifiutata).
     *
     * @return array{stampato_il: ?string, da_ristampare: bool}
     */
    public static function foglio(Assemblea $a): array
    {
        $stampato = $a->foglio_stampato_il;

        return ['stampato_il' => $stampato?->copy()->utc()->toIso8601ZuluString(), 'da_ristampare' => $stampato !== null
            && DelegaDellAssemblea::query()->where('assemblea_id', $a->id)->where('updated_at', '>', $stampato)->exists()];
    }

    /** @return array<string, mixed> */
    public static function delega(DelegaDellAssemblea $d, ?int $condominioId = null): array
    {
        return ['id' => (int) $d->id, 'delegante_anagrafica_id' => $d->delegante_anagrafica_id, 'delegante_nome' => $d->delegante_nome,
            'delegato_anagrafica_id' => $d->delegato_anagrafica_id, 'delegato_nome' => $d->delegato_nome, 'chiave_delegato' => PresenzeDellaSala::chiaveDelDelegato($d),
            'unita' => $d->unita, 'punti' => $d->punti, 'arrivata_come' => $d->arrivata_come?->value, 'arrivata_parola' => $d->arrivata_come?->parola(),
            'ricevuta_il' => $d->ricevuta_il?->format('Y-m-d'), 'stato' => $d->stato?->value, 'motivo' => $d->motivo,
            'quinto' => $d->dati['quinto']['frase'] ?? null, 'da_decidere' => (bool) ($d->dati['da_decidere'] ?? false), 'documento_id' => $d->documento_id,
            'scansione' => $d->documento_id === null ? null : route('admin.gestionale.assemblee.deleghe.scansione', [$condominioId ?? $d->assemblea?->condominio_id, $d->assemblea_id, $d->id])];
    }

    /** @return array<string, mixed> */
    public static function votazione(VotazioneDelPunto $v): array
    {
        return ['id' => (int) $v->id, 'seduta_id' => (int) $v->seduta_id, 'punto_id' => (int) $v->punto_id, 'numero' => (int) $v->numero, 'oggetto' => $v->oggetto,
            'maggioranza' => $v->maggioranza?->value, 'votata_il' => $v->votata_il?->toIso8601ZuluString(), 'versione' => (int) $v->versione,
            'intervenuti' => $v->intervenuti, 'scelte' => $v->scelte, 'conteggi' => $v->conteggi, 'esito_proposto' => $v->esito_proposto?->value,
            'esito' => $v->esito?->value, 'motivo' => $v->motivo, 'confermata_il' => $v->confermata_il?->toIso8601ZuluString(),
            'conflitto_determinante' => (bool) $v->conflitto_determinante, 'delibera_id' => $v->delibera_id,
            'nota' => $v->nota, 'nota_non_votato' => $v->nota_non_votato,
            'voti' => $v->voti->map(fn (VotoInSala $x) => ['testa' => $x->testa, 'anagrafica_id' => $x->anagrafica_id, 'nome' => $x->nome, 'ruolo' => $x->ruolo?->value,
                'millesimi' => (int) $x->millesimi, 'voto' => $x->voto?->value, 'decisione' => $x->decisione?->value, 'in_conflitto' => (bool) $x->in_conflitto,
                'nota_conflitto' => $x->nota_conflitto, 'versione' => (int) $x->versione])->values()->all()];
    }
}
