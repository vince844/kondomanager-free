<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\StatoVerbale;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Condominio;
use App\Models\User;
use App\Services\Assemblee\Seguito\Effetti\DellaDelega;
use App\Services\Assemblee\Seguito\Effetti\Effetto;
use App\Services\Assemblee\Seguito\Effetti\SuiLavori;
use App\Services\Assemblee\Seguito\Effetti\SulFondo;
use App\Services\Assemblee\Seguito\Effetti\SullAccordo;
use App\Services\Assemblee\Seguito\Effetti\SullaDispensa;
use App\Services\Assemblee\Seguito\Effetti\SullaRatifica;
use App\Services\Assemblee\Seguito\Effetti\SulleRegole;
use App\Services\Assemblee\Seguito\Effetti\SulPreventivo;
use App\Services\Assemblee\Seguito\Effetti\SulPunto;
use App\Services\Assemblee\Seguito\Effetti\SulRendiconto;
use App\Services\Assemblee\Verbale\TerminiDellImpugnazione;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * «Che cosa cambia nel gestionale» (decisione 500 (3)) e il seguito delle delibere (decisioni 522, 523): il riepilogo spuntato di serie che l'amministratore
 * conferma, la conferma, «Applica» di una riga tolta, il passo 6 «Delibere eseguite».
 *
 * - **R1, mai in silenzio**: la registrazione del verbale non cambia nessuna scheda; cambia **solo «Conferma»**. Il riepilogo si apre con il verbale
 *   registrato del giorno della delibera (V1); l'assemblea tenuta su carta lo apre appena segnati gli esiti (decisione 622 (4)).
 * - **R2, il server ricalcola**: la conferma rimanda le chiavi spuntate, e il server applica solo le righe che **lui** calcola e che sono nella lista; **una
 *   transazione per riga**, con l'esito per riga: una riga che non va non ferma le altre. Rifatta, la conferma non rifà niente: una riga è applicata se il
 *   collegamento c'è (o la cosa da fare esiste). **La conferma applica solo le righe delle delibere ancora da confermare** (la prosecuzione del 20/12 non
 *   applica la riga tolta il 16/12): per le altre c'è «Applica», e nel riepilogo una riga non applicata di una delibera confermata non è spuntata. La
 *   conferma e «Applica» bloccano l'assemblea: due richieste insieme (un doppio clic su una rete lenta) si mettono in fila.
 * - **Il verbale annullato dopo la conferma** chiude il passo con la frase {@see VERBALE_ANNULLATO}; le delibere confermate restano nella tabella con le
 *   cose da fare aperte ({@see VERBALE_ANNULLATO_CHIUDE_IL_PASSO}).
 * - **R3**: ogni scrittura passa dal servizio del modulo; questa cartella non scrive le tabelle dei moduli e i collegamenti li scrive solo
 *   `CollegamentiDelleDelibere`.
 */
final class SeguitoDelleDelibere
{
    /** La frase del passo quando il verbale del giorno è annullato dopo la conferma: niente si disfa da solo. */
    public const VERBALE_ANNULLATO = 'Il verbale è annullato: le delibere applicate restano.';

    /**
     * Il verbale annullato chiude il passo, con la frase, e le cose da fare restano nella tabella. È la risposta proposta, in attesa di Vincenzo: con `false`
     * il passo resta aperto sulla tabella, con la stessa frase in testa.
     */
    public const VERBALE_ANNULLATO_CHIUDE_IL_PASSO = true;

    /** @return list<Effetto> le catene, nell'ordine delle righe */
    public static function effetti(): array
    {
        return [new SulRendiconto(), new SulPreventivo(), new SullaRatifica(), new SuiLavori(), new SulFondo(), new DellaDelega(), new SullaDispensa(),
            new SullAccordo(), new SulleRegole(), new SulPunto()];
    }

    /** La catena che applica una riga `applica`. */
    private static function effettoDi(string $catena): ?Effetto
    {
        return match ($catena) {
            'rendiconto' => new SulRendiconto(),
            SulPreventivo::VERSIONE, SulPreventivo::PIANO => new SulPreventivo(),
            'ratifica', 'riferimento' => new SullaRatifica(),
            'lavori' => new SuiLavori(),
            'fondo' => new SulFondo(),
            'dispensa' => new SullaDispensa(),
            'accordo' => new SullAccordo(),
            default => null,
        };
    }

    /**
     * Si apre il riepilogo di questa delibera? Con il verbale registrato del suo giorno (V1), o nell'assemblea tenuta su carta (o registrata a mano) appena
     * c'è l'esito.
     */
    public static function siApre(Delibera $delibera, ?Assemblea $assemblea = null): bool
    {
        $assemblea ??= $delibera->assemblea;
        if ($assemblea === null || $assemblea->stato === StatoAssemblea::ANNULLATA) {
            return false;
        }
        if ($assemblea->stato === StatoAssemblea::REGISTRATA_A_MANO) {
            return true;
        }

        return VerbaliDellAssemblea::numeroDelGiorno((int) $assemblea->id, $delibera->deliberata_il?->format('Y-m-d')) !== null;
    }

    /**
     * Il riepilogo dell'assemblea, ricalcolato a ogni apertura.
     *
     * @return array<string, mixed>
     */
    public static function riepilogo(Assemblea $assemblea): array
    {
        $delibere = Delibera::query()->where('assemblea_id', $assemblea->id)->with(['punto.oggetti', 'assemblea'])->get()
            ->sortBy(fn (Delibera $d) => [(int) ($d->punto?->numero ?? 0), (int) $d->votazione, (int) $d->id])->values();
        $aperte = $delibere->filter(fn (Delibera $d) => self::siApre($d, $assemblea))->values();
        $punti = [];
        $conto = ['cambi' => 0, 'cose' => 0, 'comunicazioni' => 0, 'deleghe' => 0, 'punti' => 0, 'fuori' => 0, 'respinte' => 0];
        foreach ($aperte as $d) {
            $c = ContestoDellaDelibera::per($d);
            $righe = self::righeDi($c);
            if ($d->seguito_confermato_il !== null) {
                // Confermata: una riga non applicata è stata tolta, o è nata dopo; si applica con «Applica», mai di serie con un'altra conferma.
                $righe = array_map(fn (array $r) => $r['applicata'] ? $r : ['spuntata' => false] + $r, $righe);
            }
            foreach ($righe as $r) {
                if ($r['conto'] !== null && ($r['spuntata'] || in_array($r['tipo'], ['delega', 'memo', 'fuori'], true))) {
                    $conto[$r['conto']]++;
                }
            }
            $punti[] = self::punto($c, $righe);
        }
        $suCarta = $assemblea->stato === StatoAssemblea::REGISTRATA_A_MANO;
        $confermate = $aperte->filter(fn (Delibera $d) => $d->seguito_confermato_il !== null);

        return [
            'aperto' => $aperte->isNotEmpty(),
            'perche_chiuso' => $aperte->isNotEmpty() ? null : ($delibere->isEmpty() ? 'L\'assemblea non ha delibere.' : 'Si apre con il verbale registrato.'),
            'su_carta' => $suCarta,
            'verbale_da_caricare' => $suCarta && $assemblea->verbale_documento_id === null,
            'confermato_il' => $confermate->isEmpty() ? null : $confermate->max(fn (Delibera $d) => $d->seguito_confermato_il)?->toIso8601String(),
            'da_confermare' => $aperte->filter(fn (Delibera $d) => $d->seguito_confermato_il === null)->count(),
            'punti' => $punti,
            'conto' => $conto,
            'frase_del_conto' => self::fraseDelConto($conto),
        ];
    }

    /**
     * La conferma (R2). `$chiavi`: le righe spuntate; `$cose`: per chiave di una cosa proposta la data e la persona (`entro_il`, `a_chi`, `assegnato_a`);
     * `$altre`: le cose scritte («Firma con Serramenti Marino», `delibera_id`, `testo`, `entro_il`, `a_chi`, `assegnato_a`); `$modifiche`: per delibera
     * approvata con modifiche, «che cosa ha cambiato l'assemblea». Rende l'esito per riga.
     *
     * @param list<string> $chiavi
     * @param array<string, array<string, mixed>> $cose
     * @param list<array<string, mixed>> $altre
     * @param array<int|string, string> $modifiche
     * @return array{esiti: array<string, array{esito: string, frase: string}>, conto: string}
     */
    public static function conferma(Assemblea $assemblea, array $chiavi, array $cose, array $altre, array $modifiche, User $utente): array
    {
        return DB::transaction(function () use ($assemblea, $chiavi, $cose, $altre, $modifiche, $utente) {
            self::blocca($assemblea);
            $riepilogo = self::riepilogo($assemblea);
            if (! $riepilogo['aperto']) {
                throw ValidationException::withMessages(['seguito' => (string) $riepilogo['perche_chiuso']]);
            }
            $esiti = self::applicaLeRighe($assemblea, $riepilogo, array_values(array_unique(array_map('strval', $chiavi))), $cose, $utente, true);
            $delibere = collect($riepilogo['punti'])->pluck('delibera_id')->map(fn ($id) => (int) $id)->all();
            $daConfermare = collect($riepilogo['punti'])->reject(fn ($p) => $p['confermata'])->pluck('delibera_id')->map(fn ($id) => (int) $id)->all();
            foreach ($altre as $i => $a) {
                $id = (int) ($a['delibera_id'] ?? 0);
                if (! in_array($id, $delibere, true)) {
                    $esiti['altra:' . $i] = ['esito' => 'sconosciuta', 'frase' => 'La delibera non è di questa assemblea.'];
                    continue;
                }
                if (! in_array($id, $daConfermare, true)) {
                    $esiti['altra:' . $i] = ['esito' => 'non_applicata', 'frase' => self::GIA_CONFERMATA_LA_COSA];
                    continue;
                }
                try {
                    $cosa = DB::transaction(fn () => CoseDaFare::crea(Delibera::query()->findOrFail($id), TipoCosaDaFare::FUORI_DAL_PROGRAMMA,
                        (string) ($a['testo'] ?? ''), self::giorno($a['entro_il'] ?? null), $a['a_chi'] ?? null,
                        isset($a['assegnato_a']) ? (int) $a['assegnato_a'] : null, $utente));
                    $esiti['altra:' . $i] = ['esito' => 'applicata', 'frase' => 'Cosa da fare: ' . $cosa->testo . '.'];
                } catch (ValidationException $e) {
                    $esiti['altra:' . $i] = ['esito' => 'non_applicata', 'frase' => collect($e->errors())->flatten()->first() ?? 'Non applicata.'];
                }
            }
            $confermate = 0;
            foreach (Delibera::query()->whereIn('id', $daConfermare)->get() as $d) {
                $testo = trim((string) ($modifiche[$d->id] ?? $modifiche[(string) $d->id] ?? ''));
                if ($testo !== '' && $d->esito === \App\Enums\Assemblee\EsitoDelibera::APPROVATA_CON_MODIFICHE) {
                    // «Che cosa ha cambiato l'assemblea»: un gesto sulla delibera, con il testo fra i suoi dati della persona.
                    RegistroModifiche::durante(GestiDelRegistro::modificheDellAssemblea($d),
                        fn () => $d->update(['dati' => ['modifiche' => $testo] + (array) ($d->dati ?? [])]));
                }
                $d->forceFill(['seguito_confermato_il' => now(), 'seguito_confermato_da' => $utente->id])->saveQuietly();
                $confermate++;
            }
            $conto = self::contoDegliEsiti($esiti);
            $nonApplicate = collect($esiti)->filter(fn ($e) => $e['esito'] === 'non_applicata')->map(fn ($e, $k) => $e['frase'])->values()->all();
            // Una conferma rifatta che non applica niente e non conferma niente non scrive una riga «0 cambi applicati, 0 cose da fare».
            if ($confermate > 0 || collect($esiti)->contains(fn ($e) => $e['esito'] === 'applicata')) {
                RegistroModifiche::gesto(GestiDelRegistro::seguitoConfermato($assemblea, $conto, $nonApplicate));
            }

            return ['esiti' => $esiti, 'conto' => $conto];
        });
    }

    /** La riga di una delibera già confermata: la conferma non la applica (la prosecuzione del 20/12 non applica la riga tolta il 16/12). */
    public const GIA_CONFERMATA = 'La delibera è già confermata: questa riga si applica con «Applica» nel passo 6.';

    /** La cosa scritta su una delibera già confermata: si scrive con «Un'altra cosa da fare». */
    public const GIA_CONFERMATA_LA_COSA = 'La delibera è già confermata: la cosa si scrive con «Un\'altra cosa da fare» nel passo 6.';

    /** Blocca l'assemblea fino alla fine della transazione: due conferme insieme si mettono in fila, e la seconda vede ciò che la prima ha fatto. */
    private static function blocca(Assemblea $assemblea): void
    {
        Assemblea::query()->whereKey($assemblea->id)->lockForUpdate()->first();
    }

    /**
     * «Applica» di una riga tolta dal riepilogo (R2): la stessa strada della conferma, per una riga.
     *
     * @param array<string, mixed> $cosa la data e la persona, per una cosa da fare
     * @return array{esito: string, frase: string}
     */
    public static function applica(Assemblea $assemblea, string $chiave, array $cosa, User $utente): array
    {
        return DB::transaction(function () use ($assemblea, $chiave, $cosa, $utente) {
            self::blocca($assemblea);
            $riepilogo = self::riepilogo($assemblea);
            $esiti = self::applicaLeRighe($assemblea, $riepilogo, [$chiave], [$chiave => $cosa], $utente, false);
            $esito = $esiti[$chiave];
            if ($esito['esito'] === 'applicata') {
                RegistroModifiche::gesto(GestiDelRegistro::seguitoConfermato($assemblea, '1 riga applicata dopo: ' . $esito['frase'], []));
            }

            return $esito;
        });
    }

    /**
     * Il passo 6 «Delibere eseguite» e la tabella delle delibere (P1): il riepilogo finché non è confermato, poi per ogni delibera l'esito, il seguito, le
     * cose da fare, i segni, i trenta giorni; in testa «Da eseguire» e «Trenta giorni».
     *
     * @return array<string, mixed>
     */
    public static function perLaPagina(Assemblea $assemblea, ?User $utente): array
    {
        $riepilogo = self::riepilogo($assemblea);
        $termini = app(TerminiDellImpugnazione::class);
        $tabella = [];
        $daEseguire = [];
        $perTutti = [];
        $manca = false;
        $nessuno = true;
        $indice = collect($riepilogo['punti'])->keyBy('delibera_id');
        $divisi = self::votatiDueVolte($assemblea);
        foreach (Delibera::query()->where('assemblea_id', $assemblea->id)->with(['punto', 'assemblea', 'revocataDa.punto'])->get()
            ->sortBy(fn (Delibera $d) => [(int) ($d->punto?->numero ?? 0), (int) $d->votazione, (int) $d->id]) as $d) {
            $annullato = ! self::siApre($d, $assemblea) && self::verbaleAnnullato($d, $assemblea);
            if (! self::siApre($d, $assemblea) && ! $annullato) {
                continue;
            }
            $seguito = StatoDiEsecuzione::seguito($d);
            $cose = CoseDaFare::perLaDelibera($d);
            foreach ($cose as $c) {
                if ($c['stato'] === StatoCosaDaFare::DA_FARE->value) {
                    $daEseguire[] = $c + ['punto' => $d->punto?->numero];
                }
            }
            $t = $termini->perLaDelibera($d);
            self::contaITermini($t, $manca, $perTutti, $nessuno);
            $tabella[] = [
                'delibera_id' => (int) $d->id,
                'punto' => $d->punto?->numero,
                'oggetto' => in_array((int) $d->punto_id, $divisi, true) ? self::oggettoDi($d) : null,
                // Il punto, per «Ferma il preventivo» sulla riga della cosa «Correggi il preventivo e fermalo di nuovo».
                'punto_id' => $d->punto_id === null ? null : (int) $d->punto_id,
                'testo' => (string) ($d->punto?->testo ?? $d->testo),
                'esito' => $d->esito?->value,
                'esito_parola' => $d->esito?->parola(),
                'seguito' => $seguito,
                'cose' => $cose,
                'righe_da_applicare' => collect($indice[(int) $d->id]['righe'] ?? [])
                    ->filter(fn ($r) => ($r['si_applica'] && ! $r['applicata']) || ($r['dati']['aspetta'] ?? false))->values()->all(),
                'verbale_annullato' => $annullato,
                // Il memo delle varie (o della comunicazione) già salvato: la riga dice «Nella memoria del condominio» invece di «Portalo alla prossima assemblea».
                'memo_salvato' => collect($indice[(int) $d->id]['righe'] ?? [])->contains(fn ($r) => $r['tipo'] === 'memo' && ($r['applicata'] ?? false)),
                'segni' => SegniDellaDelibera::frasi($d),
                'fondo_speciale' => SuiLavori::fondoSpeciale($d),
                'trenta_giorni' => ($t['senza_termine'] ?? false) ? self::senzaTermine($d)
                    : self::fraseDeiTrentaGiorni($t['per_tutti'] ?? null, $t === null || $t['per_tutti'] === null, (bool) ($t['nessuno'] ?? false)),
                // Chi può impugnare (i contrari, gli astenuti, gli assenti), per il menu «Da chi» di «Segna impugnata»: la pagina è dello studio.
                'chi_puo_impugnare' => array_values(array_map(fn ($p) => ['nome' => (string) $p['nome'], 'perche' => (string) $p['perche']], $t['chi'] ?? [])),
                'posti' => \App\Services\Assemblee\CollegamentiDelleDelibere::posti($d),
            ];
        }
        $giornoPerTutti = $manca || $perTutti === [] ? null : max($perTutti);

        return [
            'riepilogo' => $riepilogo,
            'tabella' => $tabella,
            'da_eseguire' => $daEseguire,
            // Solo punti rinviati o senza voto: niente da impugnare. Altrimenti contano le delibere approvate o respinte, le sole che hanno un termine.
            'trenta_giorni' => $perTutti === [] && ! $manca && $tabella !== [] ? ['per_tutti' => null, 'dal' => null, 'frase' => self::NIENTE_DA_IMPUGNARE]
                : self::fraseDeiTrentaGiorni($giornoPerTutti, $manca || $perTutti === [], $nessuno && $perTutti !== [], count($perTutti) > 1),
            'passo' => self::perIlPasso($assemblea, $riepilogo, $tabella),
            'puo' => ['gestire' => \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($utente)],
        ];
    }

    /**
     * Il passo 6 nella strada (P1): chiuso fino al verbale registrato; «adesso» con il riepilogo da confermare o le delibere da eseguire; «fatto» quando ogni
     * delibera è eseguita, o le sue cose da fare non servono più. La riga: «8 su 9 · 1 da eseguire».
     *
     * @param array<string, mixed>|null $riepilogo
     * @param list<array<string, mixed>>|null $tabella
     * @return array{stato: string, riga: string, perche: ?string, piede: string}
     */
    public static function perIlPasso(Assemblea $assemblea, ?array $riepilogo = null, ?array $tabella = null): array
    {
        $riepilogo ??= self::riepilogo($assemblea);
        $annullato = Delibera::query()->where('assemblea_id', $assemblea->id)->whereNotNull('seguito_confermato_il')->get()
            ->contains(fn (Delibera $d) => ! self::siApre($d, $assemblea) && self::verbaleAnnullato($d, $assemblea));
        if (! $riepilogo['aperto'] && (! $annullato || self::VERBALE_ANNULLATO_CHIUDE_IL_PASSO)) {
            return $annullato
                ? ['stato' => 'chiuso', 'riga' => 'il verbale è annullato', 'perche' => self::VERBALE_ANNULLATO,
                    'piede' => 'Le cose da fare aperte restano nella tabella: chiudile con «Segna eseguita» o «Non serve più».']
                : ['stato' => 'chiuso', 'riga' => 'si apre con il verbale registrato', 'perche' => 'si apre con il verbale registrato',
                    'piede' => 'Il passo si apre quando il verbale sottoscritto è registrato.'];
        }
        $tabella ??= self::perLaPagina($assemblea, null)['tabella'];
        $conSeguito = array_values(array_filter($tabella, fn ($r) => in_array($r['seguito']['stato'], ['da_confermare', 'da_eseguire', 'eseguita', 'risposta_data'], true)));
        $eseguite = count(array_filter($conSeguito, fn ($r) => in_array($r['seguito']['stato'], ['eseguita', 'risposta_data'], true)));
        $daEseguire = count(array_filter($conSeguito, fn ($r) => $r['seguito']['stato'] === 'da_eseguire'));
        if ($riepilogo['da_confermare'] > 0) {
            return ['stato' => 'adesso', 'riga' => 'da confermare: «Che cosa cambia nel gestionale»', 'perche' => null,
                'piede' => 'Niente cambia nei moduli finché non premi «Conferma».'];
        }
        $riga = $eseguite . ' su ' . count($conSeguito) . ($daEseguire > 0 ? ' · ' . $daEseguire . ' da eseguire' : '');

        return ['stato' => $daEseguire > 0 ? 'adesso' : 'fatto', 'riga' => $riga, 'perche' => $annullato ? self::VERBALE_ANNULLATO : null,
            'piede' => 'Il passo è fatto quando ogni delibera è eseguita, o le sue cose da fare non servono più.'];
    }

    /**
     * La delibera aveva il riepilogo aperto dal verbale del suo giorno, e quel verbale è stato annullato dopo la conferma: la delibera è confermata, il giorno
     * non ha più un verbale registrato e ne ha uno annullato.
     */
    private static function verbaleAnnullato(Delibera $delibera, Assemblea $assemblea): bool
    {
        if ($delibera->seguito_confermato_il === null || $assemblea->stato === StatoAssemblea::REGISTRATA_A_MANO || $delibera->deliberata_il === null) {
            return false;
        }
        $sedute = \App\Models\Assemblee\SedutaDellAssemblea::query()->where('assemblea_id', $assemblea->id)
            ->whereDate('fissata_il', $delibera->deliberata_il->format('Y-m-d'))->pluck('id');

        return $sedute->isNotEmpty() && VerbaleDellAssemblea::query()->whereIn('seduta_id', $sedute)->where('stato', StatoVerbale::ANNULLATO->value)->exists();
    }

    /**
     * La card «Delibere da eseguire» della dashboard del condominio: le cose da fare aperte, con la data, la persona e l'azione. Vuota: la card non c'è.
     * Chi vede soltanto le assemblee (§9, «Vede» legge e basta) non riceve l'azione, che gli risponderebbe 403 o aprirebbe una pagina vuota: al suo posto
     * «Apri la delibera», il passo 6 con la delibera in evidenza.
     *
     * @return list<array<string, mixed>>
     */
    public static function perLaDashboard(Condominio $condominio, ?User $utente = null): array
    {
        $gestisce = \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($utente);

        return CosaDaFareDellaDelibera::query()->where('condominio_id', $condominio->id)->where('stato', StatoCosaDaFare::DA_FARE->value)
            ->where('tipo', '!=', TipoCosaDaFare::DOPO_IL_RIFIUTO->value)->with(['delibera.punto', 'delibera.assemblea', 'assegnatoA', 'fattaDa', 'documento'])
            ->orderByRaw('entro_il is null')->orderBy('entro_il')->orderBy('id')->get()
            ->map(function (CosaDaFareDellaDelibera $c) use ($gestisce) {
                $riga = CoseDaFare::riga($c) + ['delibera' => \App\Services\Assemblee\DeliberaInParole::breve($c->delibera),
                    'assemblea_id' => (int) $c->delibera->assemblea_id];
                if (! $gestisce) {
                    $riga['azione'] = ['testo' => 'Apri la delibera', 'url' => route('admin.gestionale.assemblee.show', ['condominio' => $c->condominio_id,
                        'assemblea' => $c->delibera->assemblea_id, 'scheda' => 'delibere', 'delibera' => $c->delibera_id], false)];
                }

                return $riga;
            })->values()->all();
    }

    /**
     * «Passati per tutti dal 22/01/2027. Resta la nullità.», «La data non c'è finché il verbale non è consegnato a tutti. Resta la nullità.», o, quando nessuno
     * può impugnare (tutti presenti e favorevoli), «Nessuno può impugnarla: tutti presenti e favorevoli. Resta la nullità.» con il giorno della delibera.
     *
     * @return array{per_tutti: ?string, dal: ?string, frase: string}
     */
    public static function fraseDeiTrentaGiorni(?string $perTutti, bool $manca, bool $nessuno = false, bool $piu = false): array
    {
        if ($manca || $perTutti === null) {
            return ['per_tutti' => null, 'dal' => null, 'frase' => 'La data non c\'è finché il verbale non è consegnato a tutti. Resta la nullità.'];
        }
        $dal = Carbon::parse($perTutti)->addDay()->format('Y-m-d');
        if ($nessuno) {
            return ['per_tutti' => $perTutti, 'dal' => $perTutti, 'frase' => ($piu ? 'Nessuno può impugnarle' : 'Nessuno può impugnarla')
                . ': tutti presenti e favorevoli. Resta la nullità.'];
        }

        return ['per_tutti' => $perTutti, 'dal' => $dal, 'frase' => 'Passati per tutti dal ' . Carbon::parse($dal)->format('d/m/Y') . '. Resta la nullità.'];
    }

    /** @return list<array<string, mixed>> */
    private static function righeDi(ContestoDellaDelibera $c): array
    {
        $righe = [];
        foreach (self::effetti() as $effetto) {
            foreach ($effetto->righe($c) as $r) {
                $righe[] = $r;
            }
        }

        return $righe;
    }

    /** @param list<array<string, mixed>> $righe @return array<string, mixed> */
    private static function punto(ContestoDellaDelibera $c, array $righe): array
    {
        $d = $c->delibera;

        return [
            'delibera_id' => (int) $d->id,
            'punto_id' => $c->punto === null ? null : (int) $c->punto->id,
            'numero' => $c->punto?->numero,
            'testo' => (string) ($c->punto?->testo ?? $d->testo),
            'votazione' => (int) $d->votazione,
            'oggetto' => $d->oggetto,
            'esito' => $d->esito?->value,
            'esito_parola' => $d->esito?->parola(),
            'confermata' => $d->seguito_confermato_il !== null,
            'modifiche' => ((array) ($d->dati ?? []))['modifiche'] ?? null,
            'righe' => $righe,
            'cose' => CoseDaFare::perLaDelibera($d),
            'seguito' => StatoDiEsecuzione::seguito($d),
        ];
    }

    /**
     * Applica le righe spuntate che il server calcola: una transazione per riga, l'esito per riga. Una chiave che il server non conosce non fa niente.
     *
     * @param array<string, mixed> $riepilogo
     * @param list<string> $chiavi
     * @param array<string, array<string, mixed>> $cose
     * @return array<string, array{esito: string, frase: string}>
     */
    private static function applicaLeRighe(Assemblea $assemblea, array $riepilogo, array $chiavi, array $cose, User $utente, bool $soloDaConfermare): array
    {
        $righe = [];
        $confermate = [];
        foreach ($riepilogo['punti'] as $p) {
            foreach ($p['righe'] as $r) {
                $righe[$r['chiave']] = $r;
            }
            if ($p['confermata']) {
                $confermate[(int) $p['delibera_id']] = true;
            }
        }
        $esiti = [];
        foreach ($chiavi as $chiave) {
            $riga = $righe[$chiave] ?? null;
            if ($riga === null) {
                $esiti[$chiave] = ['esito' => 'sconosciuta', 'frase' => 'Questa riga non è fra quelle del riepilogo: ricarica la pagina.'];
                continue;
            }
            if ($riga['applicata']) {
                $esiti[$chiave] = ['esito' => 'gia_applicata', 'frase' => 'Già applicata.'];
                continue;
            }
            if (! $riga['si_applica']) {
                $esiti[$chiave] = ['esito' => 'non_applicata', 'frase' => (string) ($riga['perche_no'] ?? 'Questa riga non si applica.')];
                continue;
            }
            if ($soloDaConfermare && isset($confermate[(int) $riga['delibera_id']])) {
                $esiti[$chiave] = ['esito' => 'non_applicata', 'frase' => self::GIA_CONFERMATA];
                continue;
            }
            try {
                $frase = DB::transaction(fn () => self::applicaLaRiga($riga, $cose[$chiave] ?? [], $utente));
                $esiti[$chiave] = ['esito' => 'applicata', 'frase' => $frase];
            } catch (ValidationException $e) {
                $esiti[$chiave] = ['esito' => 'non_applicata', 'frase' => (string) (collect($e->errors())->flatten()->first() ?? 'Non applicata.')];
            } catch (HttpExceptionInterface $e) {
                $esiti[$chiave] = ['esito' => 'non_applicata', 'frase' => $e->getMessage() !== '' ? $e->getMessage() : 'Non applicata.'];
            }
        }

        return $esiti;
    }

    /** @param array<string, mixed> $riga @param array<string, mixed> $cosa */
    private static function applicaLaRiga(array $riga, array $cosa, User $utente): string
    {
        $delibera = Delibera::query()->findOrFail((int) $riga['delibera_id']);
        $c = ContestoDellaDelibera::per($delibera);
        if ($riga['tipo'] === 'cosa') {
            $tipo = TipoCosaDaFare::from((string) $riga['cosa']['tipo']);
            $testo = trim((string) ($cosa['testo'] ?? '')) !== '' && $tipo !== TipoCosaDaFare::DOPO_IL_RIFIUTO ? (string) $cosa['testo'] : (string) $riga['cosa']['testo'];
            // Il piano da rimettere come il preventivo approvato resta legato alla cosa: il compito lo apre.
            $oggetto = $tipo === TipoCosaDaFare::RIMETTI_PIANO && isset($riga['scheda']['tipo'], $riga['scheda']['id'])
                ? \App\Services\Assemblee\SchedaDelCondominio::cerca($c->assemblea->condominio()->firstOrFail(), (string) $riga['scheda']['tipo'], (int) $riga['scheda']['id'])
                : null;
            $creata = CoseDaFare::crea($delibera, $tipo, $testo, self::giorno($cosa['entro_il'] ?? null), $cosa['a_chi'] ?? null,
                isset($cosa['assegnato_a']) ? (int) $cosa['assegnato_a'] : null, $utente, $oggetto);

            return 'Cosa da fare: ' . $creata->testo . ($creata->entro_il !== null ? ', entro il ' . $creata->entro_il->format('d/m/Y') : '') . '.';
        }
        $effetto = self::effettoDi((string) $riga['catena']) ?? throw ValidationException::withMessages(['riga' => 'Questa riga non si applica.']);

        return $effetto->applica($c, $riga, $utente);
    }

    private static function giorno(mixed $valore): ?string
    {
        return is_string($valore) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valore) === 1 ? $valore : null;
    }

    /** «3 cambi nei conti · 2 cose da fare · 1 comunicazione da rileggere · 1 delega in vigore · 1 punto per la prossima assemblea». @param array<string, int> $conto */
    public static function fraseDelConto(array $conto): string
    {
        $parti = [];
        $parti[] = $conto['cambi'] === 1 ? '1 cambio nei conti' : $conto['cambi'] . ' cambi nei conti';
        if ($conto['fuori'] > 0) {
            $parti[] = $conto['fuori'] === 1 ? '1 resta fuori' : $conto['fuori'] . ' restano fuori';
        }
        $parti[] = $conto['cose'] === 1 ? '1 cosa da fare' : $conto['cose'] . ' cose da fare';
        if ($conto['comunicazioni'] > 0) {
            $parti[] = $conto['comunicazioni'] === 1 ? '1 comunicazione da rileggere' : $conto['comunicazioni'] . ' comunicazioni da rileggere';
        }
        if ($conto['respinte'] > 0) {
            $parti[] = $conto['respinte'] === 1 ? '1 respinta da decidere' : $conto['respinte'] . ' respinte da decidere';
        }
        if ($conto['deleghe'] > 0) {
            $parti[] = $conto['deleghe'] === 1 ? '1 delega in vigore' : $conto['deleghe'] . ' deleghe in vigore';
        }
        if ($conto['punti'] > 0) {
            $parti[] = $conto['punti'] === 1 ? '1 punto per la prossima assemblea' : $conto['punti'] . ' punti per la prossima assemblea';
        }

        return implode(' · ', $parti);
    }

    /** «3 cambi applicati, 2 cose da fare, 1 comunicazione da rileggere», per la riga del registro. @param array<string, array{esito: string, frase: string}> $esiti */
    private static function contoDegliEsiti(array $esiti): string
    {
        $applicate = collect($esiti)->filter(fn ($e) => $e['esito'] === 'applicata');
        $cose = $applicate->filter(fn ($e, $k) => str_contains((string) $k, ':cosa:') || str_starts_with((string) $k, 'altra:'))->count();
        $avvisi = $applicate->filter(fn ($e, $k) => str_ends_with((string) $k, ':cosa:' . TipoCosaDaFare::AVVISA_CONDOMINI->value))->count();
        $cambi = $applicate->count() - $cose;
        $cose -= $avvisi;
        $non = collect($esiti)->filter(fn ($e) => $e['esito'] === 'non_applicata')->count();
        $parti = [($cambi === 1 ? '1 cambio applicato' : $cambi . ' cambi applicati'), ($cose === 1 ? '1 cosa da fare' : $cose . ' cose da fare')];
        if ($avvisi > 0) {
            $parti[] = $avvisi === 1 ? '1 comunicazione da rileggere' : $avvisi . ' comunicazioni da rileggere';
        }
        if ($non > 0) {
            $parti[] = $non === 1 ? '1 riga non applicata' : $non . ' righe non applicate';
        }

        return implode(', ', $parti);
    }

    /** Il verbale registrato del giorno della delibera, per «Verbale n. 42 registrato il 16/12/2026». */
    public static function verbaleDelGiorno(Delibera $delibera): ?VerbaleDellAssemblea
    {
        $numero = VerbaliDellAssemblea::numeroDelGiorno((int) $delibera->assemblea_id, $delibera->deliberata_il?->format('Y-m-d'));

        return $numero === null ? null : VerbaleDellAssemblea::query()->where('assemblea_id', $delibera->assemblea_id)->where('numero', $numero)
            ->where('stato', StatoVerbale::REGISTRATO->value)->first();
    }

    /** La card «Trenta giorni» quando nessun punto è approvato o respinto: solo rinvii e punti trattati senza voto (Coda 750 B1). */
    public const NIENTE_DA_IMPUGNARE = 'Niente da impugnare: nessun punto è approvato o respinto.';

    /**
     * Una delibera nel conto della card «Trenta giorni» (Coda 750 B1): il punto rinviato o trattato senza voto non ha termine e non conta; senza il verbale
     * registrato (o con una consegna che manca) la data non c'è; altrimenti il suo ultimo giorno.
     *
     * @param ?array<string, mixed> $t
     * @param list<string> $perTutti
     */
    private static function contaITermini(?array $t, bool &$manca, array &$perTutti, bool &$nessuno): void
    {
        if ($t['senza_termine'] ?? false) {
            return;
        }
        if ($t === null || $t['per_tutti'] === null) {
            $manca = true;
        } else {
            $perTutti[] = $t['per_tutti'];
        }
        $nessuno = $nessuno && $t !== null && ($t['nessuno'] ?? false);
    }

    /** La riga di un punto senza termine: «Niente da impugnare: rinviato.», «… trattato senza voto.». @return array{per_tutti: null, dal: null, frase: string} */
    private static function senzaTermine(Delibera $d): array
    {
        return ['per_tutti' => null, 'dal' => null, 'frase' => 'Niente da impugnare' . match ($d->esito) {
            \App\Enums\Assemblee\EsitoDelibera::RINVIATA => ': rinviato.',
            \App\Enums\Assemblee\EsitoDelibera::SENZA_VOTO => ': trattato senza voto.',
            default => '.',
        }];
    }

    /** I punti dell'assemblea votati due volte (il rendiconto e il riparto, Coda 750 B6): hanno una delibera della seconda votazione. @return list<int> */
    private static function votatiDueVolte(Assemblea $assemblea): array
    {
        return Delibera::query()->where('assemblea_id', $assemblea->id)->where('votazione', 2)->whereNotNull('punto_id')->pluck('punto_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** L'oggetto di una delle due votazioni di un punto: `rendiconto` o `riparto`; la seconda, senza oggetto scritto, è il riparto. */
    private static function oggettoDi(Delibera $d): string
    {
        return in_array($d->oggetto, ['rendiconto', 'riparto'], true) ? (string) $d->oggetto : ((int) $d->votazione === 2 ? 'riparto' : 'rendiconto');
    }
}
