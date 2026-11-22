<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoDelega;
use App\Helpers\DateHelper;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\PresenzaInSala;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mpdf\Output\Destination;

/**
 * Il foglio della sala (decisione 519 (7)): la sala sulla carta, da stampare il giorno prima, quando la rete o la corrente possono mancare. Dopo, «Registra
 * dal foglio della sala» riporta nel programma presenze e voti con le ore scritte a mano.
 *
 * Si legge dalla fotografia **provvisoria** ({@see FotografiaDellaSeduta::provvisoria()}): le teste del giorno della prossima seduta, con le unità e i
 * millesimi della tabella del valore. Per ogni testa la colonna «Presente o delega a» ha già le deleghe ricevute valide; le deleghe ricevute sono anche in
 * un elenco, con quelle rifiutate e il loro motivo. Per ogni punto le righe dei favorevoli, dei contrari e degli astenuti, il numero, i millesimi e l'ora
 * del voto; il rendiconto con le sue due votazioni, il punto senza voto con «Trattato». In testa le sedute dell'avviso, da segnare, e il numero legale di
 * ognuna; i conduttori dove un punto li chiama.
 *
 * **Compilato con quello che c'è** (a sala aperta, per esempio quando il portatile si scarica a metà): la fotografia della seduta aperta, e già scritti i segni
 * della sala fino a quell'ora (l'apertura, le sedute deserte, le presenze con le ore, le deleghe segnate in sala, i voti con i nomi e l'esito), distinti da
 * ciò che resta da scrivere a penna; in testa «Compilato fino alle 20:10». Poi «Registra dal foglio della sala» riporta solo ciò che è scritto a mano: un gesto
 * che chiede ciò che c'è già risponde «Era già così», e non fa due righe.
 *
 * Il foglio è un documento del condominio: la vista estende `pdf.base`, con il gruppo «Rendiconto e assemblea» e la testata comune.
 */
final class FoglioDellaSala
{
    /** Le righe vuote per le deleghe che arrivano in sala. */
    public const RIGHE_PER_LE_DELEGHE_IN_SALA = 4;

    public function __construct(
        private readonly FotografiaDellaSeduta $fotografie,
        private readonly PdfService $pdf,
    ) {}

    /** Si stampa dalla prima spedizione della convocazione fino alla chiusura. */
    public static function siStampa(Assemblea $assemblea): bool
    {
        return in_array($assemblea->stato, [StatoAssemblea::CONVOCATA, StatoAssemblea::IN_CORSO], true);
    }

    /** Il foglio con quello che c'è: solo con una seduta aperta. */
    public static function siStampaCompilato(Assemblea $assemblea): bool
    {
        return self::siStampa($assemblea) && StatiDellaSala::sedutaAperta($assemblea) !== null;
    }

    /** L'ora dell'ultima stampa, per «ristampa il foglio» e per «Registra dal foglio della sala»: una colonna del programma, senza riga e senza `updated_at`. */
    public function stampato(Assemblea $assemblea): void
    {
        DB::table('assemblee')->where('id', $assemblea->id)->update(['foglio_stampato_il' => now()]);
    }

    /** Il PDF del foglio: vuoto (il giorno prima), o compilato con quello che c'è (a sala aperta). */
    public function pdf(Assemblea $assemblea, bool $compilato = false): string
    {
        $dati = $this->dati($assemblea, $compilato);

        return $this->pdf->generate('pdf.gestionale.assemblea_foglio_sala', $dati + [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $assemblea->condominio,
            'titolo_stampa' => 'Foglio della sala · ' . $dati['assemblea'],
            'senza_firma' => true,
        ])->Output('', Destination::STRING_RETURN);
    }

    /**
     * Ciò che il foglio scrive.
     *
     * @return array{assemblea: string, titolo: string, sedute: list<array{nome: string, quando: string, dove: ?string}>, giorno_dei_titolari: string,
     *     totali: string, numero_legale: string, teste: list<array{nome: string, unita: string, millesimi: string, delega: ?string, nota: ?string}>,
     *     conduttori: list<array{nome: string, unita: string, frase: string}>, deleghe: list<array<string, ?string>>, punti: list<array<string, mixed>>,
     *     righe_deleghe_in_sala: int, stampato_il: string, avvisi: list<string>}
     */
    public function dati(Assemblea $assemblea, bool $compilato = false): array
    {
        $assemblea->loadMissing('condominio');
        $aperta = $compilato ? StatiDellaSala::sedutaAperta($assemblea) : null;
        $fotografia = $aperta?->fotografia ?? $this->fotografie->provvisoria($assemblea);
        $avviso = EmissioneDelleConvocazioni::avviso($assemblea, ['nome' => '', 'indirizzo' => '', 'unita' => [], 'punti' => null]);
        $persone = (array) $fotografia['persone'];
        $unita = (array) $fotografia['unita'];
        $deleghe = DelegaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->orderBy('ricevuta_il')->orderBy('id')->get();
        // La delega oltre il quinto ancora «da decidere» non vale finché il presidente non la decide: il foglio la scrive così, e non la conta.
        $daDecidere = fn (DelegaDellAssemblea $d) => (bool) ($d->dati['da_decidere'] ?? false);
        $scritte = $deleghe->filter(fn (DelegaDellAssemblea $d) => $d->stato === StatoDelega::VALIDA);
        $valide = $scritte->reject($daDecidere);

        $teste = [];
        foreach ((array) $fotografia['costituzione']['teste'] as $t) {
            $sue = $scritte->filter(fn (DelegaDellAssemblea $d) => in_array((int) $d->delegante_anagrafica_id, array_map('intval', (array) $t['persone']), true));
            $piuPersone = count((array) $t['persone']) > 1;
            $delega = $sue->map(fn (DelegaDellAssemblea $d) => ($piuPersone ? $d->delegante_nome . ': ' : '') . ($daDecidere($d) ? 'da decidere dal presidente: ' : '')
                . 'delega a ' . $d->delegato_nome . ($d->punti ? ' (' . self::puntiInParole($assemblea, (array) $d->punti) . ')' : '')
                . ($daDecidere($d) ? ', oltre il quinto' : ''))->values()->all();
            $teste[] = [
                'nome' => (string) $t['nome'],
                'unita' => implode(', ', array_map(fn ($id) => (string) ($unita[$id]['nome'] ?? ''), (array) $t['unita'])),
                'millesimi' => ContiDellaSala::millesimi((int) $t['millesimi']),
                'delega' => $delega === [] ? null : implode('; ', $delega),
                'nota' => self::notaDellUsufrutto((array) $t['unita'], $unita, $persone),
                'presenza' => $aperta === null ? null : self::presenzaDellaTesta($aperta, (array) $t['persone'], $valide),
            ];
        }
        usort($teste, fn ($x, $y) => strnatcasecmp($x['nome'], $y['nome']));

        $totaleTeste = (int) $fotografia['costituzione']['totale_teste'];
        $totaleValore = (int) $fotografia['costituzione']['totale_valore'];
        $prima = ContiDellaSala::costituzione('prima', $totaleTeste, $totaleValore, 0, 0);
        $seconda = ContiDellaSala::costituzione('seconda', $totaleTeste, $totaleValore, 0, 0);
        $giorno = CarbonImmutable::parse((string) $fotografia['giorno']);

        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $sedute = $assemblea->sedute()->get()->values();
        $deserte = $aperta === null ? [] : self::oreDelleDeserte($assemblea);
        $adesso = DateHelper::adessoUtente();

        return [
            'assemblea' => 'Assemblea ' . mb_strtolower($assemblea->nome()),
            'titolo' => TestiLettere::condominioConNome((string) $assemblea->condominio->nome),
            'compilato' => $aperta === null ? null : 'Compilato fino alle ' . $adesso->format('H:i') . ' del ' . $adesso->format('d/m/Y'),
            'apertura' => $aperta === null ? null : ['seduta' => (int) $aperta->numero > 1 ? 'Prosecuzione' : ucfirst(StatiDellaSala::convocazione($aperta)),
                'aperta' => $aperta->aperta_il->copy()->setTimezone($fuso)->format('H:i'), 'presidente' => (string) ($aperta->presidente['nome'] ?? ''),
                'segretario' => (string) ($aperta->segretario['nome'] ?? '')],
            'sedute' => array_map(fn ($s, $i) => ['nome' => (string) $s['nome'], 'quando' => (string) $s['quando'], 'dove' => $s['dove'] ?? null,
                'segnato' => $aperta === null ? null : self::segnoDellaSeduta($sedute[$i] ?? null, $deserte, $fuso)], (array) $avviso['sedute'], array_keys((array) $avviso['sedute'])),
            'giorno_dei_titolari' => $giorno->format('d/m/Y'),
            'totali' => ContiDellaSala::condomini($totaleTeste) . ' · ' . ContiDellaSala::millesimi($totaleValore) . ' millesimi',
            'numero_legale' => 'In prima convocazione servono ' . ContiDellaSala::condomini($prima['servono_teste']) . ' e '
                . ContiDellaSala::millesimiPerEccesso($prima['servono_valore']) . ' millesimi; in seconda ' . ContiDellaSala::condomini($seconda['servono_teste']) . ' e ' . ContiDellaSala::millesimiPerEccesso($seconda['servono_valore']) . ' millesimi.',
            'teste' => $teste,
            'conduttori' => $this->conduttori($assemblea, $fotografia),
            'deleghe' => $deleghe->map(fn (DelegaDellAssemblea $d) => [
                'chi' => $d->delegante_nome . ' a ' . $d->delegato_nome,
                'ricevuta' => $d->ricevuta_il?->format('d/m/Y'),
                'quinto' => $d->dati['quinto']['frase'] ?? null,
                'stato' => match (true) {
                    $daDecidere($d) && $d->stato === StatoDelega::VALIDA => 'da decidere dal presidente',
                    $d->stato === StatoDelega::VALIDA => 'valida',
                    $d->stato === StatoDelega::RIFIUTATA => 'rifiutata',
                    default => 'revocata',
                },
                'motivo' => $d->stato === StatoDelega::VALIDA && ! $daDecidere($d) ? null : $d->motivo,
            ])->values()->all(),
            'punti' => $assemblea->punti()->get()->map(function (PuntoDellOrdineDelGiorno $p) use ($fotografia, $aperta, $assemblea, $fuso) {
                $fatte = $aperta === null ? collect() : VotazioneDelPunto::query()->whereIn('seduta_id', $assemblea->sedute()->pluck('id'))->where('punto_id', $p->id)
                    ->orderBy('numero')->get()->keyBy('numero');
                $oggetti = $p->maggioranza === Maggioranza::SENZA_VOTO ? [] : ($p->materia === MateriaPunto::RENDICONTO ? ['Il rendiconto', 'Il riparto'] : [null]);

                return [
                    'numero' => (int) $p->numero,
                    'testo' => (string) $p->testo,
                    'come' => self::comeSiVota($p, $fotografia),
                    'trattato' => $p->maggioranza === Maggioranza::SENZA_VOTO && $fatte->has(1) ? 'Trattato alle ' . $fatte[1]->votata_il->copy()->setTimezone($fuso)->format('H:i') : null,
                    'votazioni' => array_map(fn ($oggetto, $i) => ['oggetto' => $oggetto, 'segnato' => $fatte->has($i + 1) ? self::votoSegnato($fatte[$i + 1], $fuso) : null],
                        $oggetti, array_keys($oggetti)),
                ];
            })->values()->all(),
            'righe_deleghe_in_sala' => self::RIGHE_PER_LE_DELEGHE_IN_SALA,
            'deleghe_in_sala' => $aperta === null ? [] : $valide->filter(fn (DelegaDellAssemblea $d) => $d->arrivata_come?->value === 'in_sala')->map(fn (DelegaDellAssemblea $d) => [
                'chi' => $d->delegante_nome, 'a_chi' => $d->delegato_nome, 'ora' => $d->segnata_il?->copy()->setTimezone($fuso)->format('H:i') ?? ''])->values()->all(),
            'stampato_il' => DateHelper::adessoUtente()->format('d/m/Y \a\l\l\e H:i'),
            'avvisi' => array_values((array) ($fotografia['avvisi'] ?? [])),
        ];
    }

    /**
     * Come è presente una testa nella seduta aperta, con le ore: di persona (o in collegamento), o per delega con il nome del delegato; null se non è mai
     * entrata. Più entrate e uscite si scrivono in fila («18:45 · 19:50»).
     *
     * @param list<int> $persone
     * @return ?array{chi: string, entrata: string, uscita: ?string}
     */
    private static function presenzaDellaTesta(SedutaDellAssemblea $seduta, array $persone, $valide): ?array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $persone = array_map('intval', $persone);
        $righe = PresenzaInSala::query()->where('seduta_id', $seduta->id)->whereIn('anagrafica_id', $persone)->orderBy('entrata_il')->orderBy('id')->get();
        $chi = null;
        if ($righe->isEmpty()) {
            $delega = $valide->first(fn (DelegaDellAssemblea $d) => in_array((int) $d->delegante_anagrafica_id, $persone, true));
            if ($delega !== null) {
                $righe = PresenzaInSala::query()->where('seduta_id', $seduta->id)->where('chiave', PresenzeDellaSala::chiaveDelDelegato($delega))
                    ->orderBy('entrata_il')->orderBy('id')->get();
                $chi = $delega->delegato_nome . ', per delega';
            }
        }
        if ($righe->isEmpty()) {
            return null;
        }
        $ora = fn ($x) => $x->copy()->setTimezone($fuso)->format('H:i');
        $uscite = $righe->filter(fn (PresenzaInSala $p) => $p->uscita_il !== null)->map(fn (PresenzaInSala $p) => $ora($p->uscita_il))->values()->all();

        return ['chi' => $chi ?? ($righe->last()->come?->value === 'in_collegamento' ? 'In collegamento' : 'Presente'),
            'entrata' => implode(' · ', $righe->map(fn (PresenzaInSala $p) => $ora($p->entrata_il))->all()), 'uscita' => $uscite === [] ? null : implode(' · ', $uscite)];
    }

    /** L'ora di ogni seduta segnata deserta, dal libro dei gesti. @return array<int, CarbonImmutable> */
    private static function oreDelleDeserte(Assemblea $assemblea): array
    {
        $ore = [];
        foreach (GestoDellaSala::query()->where('assemblea_id', $assemblea->id)->where('tipo', 'deserta')->where('esito', 'applicato')->get() as $g) {
            $ore[(int) ($g->dati['seduta_id'] ?? 0)] = CarbonImmutable::instance($g->segnato_il);
        }

        return $ore;
    }

    /** «deserta alle 8:40», «aperta alle 18:42», «sospesa alle 21:30»: ciò che la sala ha già segnato su una seduta; null se niente. @param array<int, CarbonImmutable> $deserte */
    private static function segnoDellaSeduta(?SedutaDellAssemblea $s, array $deserte, string $fuso): ?string
    {
        if ($s === null) {
            return null;
        }
        if ($s->esito?->value === 'deserta') {
            return 'deserta' . (isset($deserte[(int) $s->id]) ? ' alle ' . $deserte[(int) $s->id]->setTimezone($fuso)->format('H:i') : '');
        }
        if ($s->aperta_il === null) {
            return null;
        }

        return match ($s->esito?->value) {
            'sospesa' => 'sospesa alle ' . $s->chiusa_il->copy()->setTimezone($fuso)->format('H:i'),
            default => 'aperta alle ' . $s->aperta_il->copy()->setTimezone($fuso)->format('H:i'),
        };
    }

    /**
     * Un voto già segnato in sala: l'ora, favorevoli, contrari e astenuti con i nomi, quanti e i millesimi, chi è in conflitto, l'esito (confermato con l'ora, o
     * da confermare).
     *
     * @return array<string, mixed>
     */
    private static function votoSegnato(VotazioneDelPunto $v, string $fuso): array
    {
        $c = (array) $v->conteggi;
        $gruppo = fn (string $k) => ['nomi' => implode('; ', array_map(fn ($n) => (string) $n['nome'], (array) ($c[$k]['nomi'] ?? []))),
            'quanti' => (int) ($c[$k]['teste'] ?? 0), 'millesimi' => ContiDellaSala::millesimi((int) ($c[$k]['valore'] ?? 0))];

        return ['ora' => $v->votata_il->copy()->setTimezone($fuso)->format('H:i'), 'favorevoli' => $gruppo('favorevoli'), 'contrari' => $gruppo('contrari'),
            'astenuti' => $gruppo('astenuti'), 'conflitto' => implode('; ', $v->voti()->where('in_conflitto', true)->pluck('nome')->all()),
            'esito' => $v->confermata_il !== null && $v->esito !== null ? $v->esito->parola() . ', confermato alle ' . $v->confermata_il->copy()->setTimezone($fuso)->format('H:i')
                : 'da confermare'];
    }

    /** «la maggioranza degli intervenuti e almeno un terzo del valore · solo la scala B, tabella «Ascensore B»». */
    private static function comeSiVota(PuntoDellOrdineDelGiorno $p, array $fotografia): string
    {
        if ($p->maggioranza === Maggioranza::SENZA_VOTO) {
            return 'Senza voto: si segna «Trattato».';
        }
        $voce = collect((array) $fotografia['punti'])->firstWhere('punto_id', (int) $p->id);
        $pezzi = [$p->maggioranza?->parola() ?? 'Maggioranza da scegliere'];
        if (($voce['perimetro'] ?? null) !== null) {
            $pezzi[] = (string) $voce['perimetro'] . ': ' . ContiDellaSala::condomini((int) $voce['totale_teste']) . ', ' . ContiDellaSala::millesimi((int) $voce['totale_valore']) . ' millesimi'
                . (($voce['tabella']['nome'] ?? null) !== null ? ' della tabella «' . $voce['tabella']['nome'] . '»' : '');
        } elseif (($voce['tabella']['nome'] ?? null) !== null && ($voce['tabella']['id'] ?? null) !== ($fotografia['tabella']['id'] ?? null)) {
            $pezzi[] = 'tabella «' . $voce['tabella']['nome'] . '»';
        }

        return implode(' · ', $pezzi);
    }

    /**
     * L'usufrutto delle unità della testa, perché chi segna sappia chi vota: sui punti ordinari chi ha l'usufrutto, sugli straordinari chi ha la nuda
     * proprietà (art. 67 c. 6 e 7 disp. att. c.c.). Con più unità, ogni nota dice la sua.
     *
     * @param list<int|string> $idUnita
     * @param array<int|string, array<string, mixed>> $unita
     * @param array<int|string, array{nome: string}> $persone
     */
    private static function notaDellUsufrutto(array $idUnita, array $unita, array $persone): ?string
    {
        $note = [];
        foreach ($idUnita as $id) {
            $titolari = (array) ($unita[$id]['titolari'] ?? []);
            $nomi = fn (string $ruolo) => TesteDellaSala::elenco(array_values(array_map(fn ($t) => (string) ($persone[$t['anagrafica_id']]['nome'] ?? ''),
                array_filter($titolari, fn ($t) => $t['ruolo'] === $ruolo))));
            $usufrutto = $nomi('usufruttuario');
            $nuda = $nomi('nuda_proprietario');
            if ($usufrutto !== '' && $nuda !== '') {
                $note[] = (count($idUnita) > 1 ? ($unita[$id]['nome'] ?? '') . ': ' : '') . 'usufrutto di ' . $usufrutto . ', nuda proprietà di ' . $nuda
                    . ': sui punti ordinari vota ' . $usufrutto . ', sugli straordinari ' . $nuda;
            }
        }

        return $note === [] ? null : implode('. ', $note) . '.';
    }

    /**
     * I conduttori delle unità del perimetro, quando un punto li chiama: chi vota al posto del proprietario, chi interviene senza voto. Non sono teste della
     * costituzione.
     *
     * @return list<array{nome: string, unita: string, frase: string}>
     */
    private function conduttori(Assemblea $assemblea, array $fotografia): array
    {
        $punti = $assemblea->punti()->whereNotNull('conduttore')->get();
        if ($punti->isEmpty()) {
            return [];
        }
        $frasi = [];
        foreach ($punti as $p) {
            $frasi[$p->conduttore?->value === 'interviene' ? 'interviene senza voto' : 'vota al posto del proprietario'][] = (int) $p->numero;
        }
        $parole = [];
        foreach ($frasi as $cosa => $numeri) {
            $parole[] = $cosa . (count($numeri) === 1 ? ' al punto ' : ' ai punti ') . TesteDellaSala::elenco(array_map('strval', $numeri));
        }
        $righe = [];
        foreach ((array) $fotografia['unita'] as $u) {
            foreach ((array) $u['titolari'] as $t) {
                if ($t['ruolo'] === 'inquilino') {
                    $righe[] = ['nome' => (string) ($fotografia['persone'][$t['anagrafica_id']]['nome'] ?? ''), 'unita' => (string) $u['nome'],
                        'frase' => 'conduttore: ' . implode('; ', $parole)];
                }
            }
        }
        usort($righe, fn ($x, $y) => strnatcasecmp($x['nome'], $y['nome']));

        return $righe;
    }

    /** «punti 4 e 5». @param list<int> $punti gli id dei punti */
    private static function puntiInParole(Assemblea $assemblea, array $punti): string
    {
        $numeri = $assemblea->punti()->whereIn('id', $punti)->orderBy('numero')->pluck('numero')->map(fn ($n) => (string) $n)->all();

        return (count($numeri) === 1 ? 'punto ' : 'punti ') . TesteDellaSala::elenco($numeri);
    }
}
