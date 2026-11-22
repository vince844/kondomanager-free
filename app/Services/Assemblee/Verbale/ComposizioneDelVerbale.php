<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\ParteDelVerbale;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\User;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\Assemblee\Sala\VotazioniDellaSala;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Validation\ValidationException;

/**
 * La composizione del verbale (V2): **dal modello** (il testo del programma o uno dello studio, {@see ModelliDelVerbale}) e **dai dati della sala**
 * ({@see LetturaDellaSala::perIlVerbale()}), con le frasi della bozza approvata. Il modello dà ciò che viene prima e dopo i punti, con i campi riempiti; in
 * mezzo il programma scrive le parti «Dati della sala», ognuna con la sua chiave:
 *
 * - `convocazione:<assemblea>`: «L'assemblea è stata convocata con avvisi spediti dal 3 al 9 dicembre 2026, per raccomandata, PEC e a mano.»;
 * - `apertura:<seduta>`: quando chi aveva diritto non risulta convocato (art. 1136 c. 6 c.c.) il presidente ne dà atto e dispone di procedere, con il motivo;
 * - `costituzione:<seduta>`: «Sono presenti, di persona o per delega, 11 condòmini su 26, per 484,60 millesimi su 1.000, come dall'elenco in calce.», al
 *   primo voto (o alla chiusura se non si è votato);
 * - per ogni punto della riunione, dopo il titolo e la discussione scritta in sala (`punto:<id>`, testo libero): `voto:<votazione>` con gli intervenuti e la
 *   maggioranza con l'articolo, chi vota per l'usufrutto e perché, il conto della parte, favorevoli, contrari, astenuti e chi non ha votato con i nomi e i
 *   millesimi (chi è rappresentato e il suo delegato: «Gianni De Luca per delega a Giorgio Bianchi»), il conflitto d'interessi, le note del presidente,
 *   l'esito; `rinvio_punto:<punto>` per il punto non trattato; `dichiarazione:<id>` con il nome di chi la fa;
 * - in fondo: la chiusura del modello; per la riunione sospesa `sospensione:<seduta>` («… che prosegue giovedì 17 dicembre 2026 alle ore 18:30 … per i punti
 *   6, 7 e 8»); per quella aperta e rinviata dalla sala `rinvio:<seduta>`; la prosecuzione apre con `prosecuzione:<seduta>` al posto del testo d'apertura.
 *
 * L'impronta dei dati della sala letti ({@see improntaDiAdesso()}) va in `impronta_sala`: se cambia dopo (un voto corretto prima della registrazione), la pagina
 * dice «I dati della sala sono cambiati: aggiorna le parti segnate» ({@see aggiornaLeParti()}).
 */
final class ComposizioneDelVerbale
{
    public function __construct(private readonly LetturaDellaSala $lettura, private readonly VersioniDelVerbale $versioni) {}

    /**
     * Il verbale composto: la struttura nella forma canonica, l'impronta dei dati della sala, il modello usato.
     *
     * @param array{id: ?int, nome: string, prima: array<string, mixed>, dopo: array<string, mixed>} $modello
     * @return array{documento: array<string, mixed>, impronta_sala: string, modello: array{id: ?int, nome: string}}
     */
    public function componi(VerbaleDellAssemblea $verbale, array $modello): array
    {
        $dati = $this->dati($verbale);
        $blocchi = $this->blocchi($dati);
        $campi = $this->campi($dati);
        $contenuto = [];
        if ($dati['prosecuzione']) {
            $contenuto[] = $blocchi['prosecuzione:' . $dati['seduta']['id']];
        } else {
            array_push($contenuto, ...(array) (ModelliDelVerbale::riempi($modello['prima'], $campi)['content'] ?? []));
        }
        foreach (['convocazione:' . $dati['assemblea_id'], 'apertura:' . $dati['seduta']['id'], 'costituzione:' . $dati['seduta']['id']] as $chiave) {
            if (isset($blocchi[$chiave])) {
                $contenuto[] = $blocchi[$chiave];
            }
        }
        foreach ($dati['punti'] as $p) {
            $contenuto[] = StrutturaDelVerbale::titolo($p['numero'] . '. ' . trim((string) $p['testo']));
            foreach ((array) ($this->discussione($verbale, (int) $p['punto_id'])['content'] ?? []) as $blocco) {
                $contenuto[] = $blocco;
            }
            foreach ($this->chiaviDelPunto($p) as $chiave) {
                $contenuto[] = $blocchi[$chiave];
            }
        }
        foreach ($dati['dichiarazioni_della_seduta'] as $d) {
            $contenuto[] = $blocchi['dichiarazione:' . $d['id']];
        }
        $fine = $blocchi['sospensione:' . $dati['seduta']['id']] ?? $blocchi['rinvio:' . $dati['seduta']['id']] ?? null;
        if ($fine !== null) {
            $contenuto[] = $fine;
            $contenuto[] = StrutturaDelVerbale::paragrafo('Letto, approvato e sottoscritto.');
        } else {
            array_push($contenuto, ...(array) (ModelliDelVerbale::riempi($modello['dopo'], $campi)['content'] ?? []));
        }

        return [
            'documento' => StrutturaDelVerbale::valida(StrutturaDelVerbale::documento($contenuto)),
            'impronta_sala' => self::impronta($blocchi),
            'modello' => ['id' => $modello['id'], 'nome' => $modello['nome']],
        ];
    }

    /** Le parti «Dati della sala» rifatte adesso dai dati della sala: chiave => nodo. @return array<string, array<string, mixed>> */
    public function blocchiDiAdesso(VerbaleDellAssemblea $verbale): array
    {
        return $this->blocchi($this->dati($verbale));
    }

    /** L'impronta dei dati della sala di adesso, da confrontare con `impronta_sala`. */
    public function improntaDiAdesso(VerbaleDellAssemblea $verbale): string
    {
        return self::impronta($this->blocchiDiAdesso($verbale));
    }

    /**
     * «Genera il verbale dal modello» (e «Rigenera»): il testo intero composto adesso, la versione «generato dal modello «…»»; il testo di prima, se c'era,
     * resta fra le versioni. Il verbale è in bozza. Si genera quando la sala ha chiuso la riunione (tenuta, sospesa o rinviata), finché non è registrato.
     *
     * @param array{id: ?int, nome: string, prima: array<string, mixed>, dopo: array<string, mixed>} $modello
     */
    public function genera(VerbaleDellAssemblea $verbale, array $modello, User $utente): VerbaleDellAssemblea
    {
        $verbale->loadMissing('seduta');
        if ($verbale->seduta->esito === null) {
            throw ValidationException::withMessages(['verbale' => 'La riunione è ancora aperta: il verbale si genera quando la sala la chiude, la sospende o la rinvia.']);
        }
        if (! $verbale->siScrive()) {
            throw ValidationException::withMessages(['verbale' => 'Il verbale n. ' . $verbale->numero . ' è registrato: il testo è quello firmato.']);
        }
        $esito = $this->componi($verbale, $modello);
        $rigenera = $verbale->stato === StatoVerbale::BOZZA;
        $nome = $modello['nome'];
        $frase = ($rigenera ? 'rigenerato' : 'generato') . ' dal modello «' . $nome . '»';

        RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, fn ($lotto, $v) => $frase . ', versione ' . ($v['numero'] ?? 1)),
            function () use ($verbale, $esito, $utente, $rigenera, $frase) {
                $parte = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->lockForUpdate()->first();
                if ($rigenera && $parte !== null) {
                    $this->versioni->tieniIlTestoDiAdesso($verbale, $parte, 'il testo prima di rigenerare', $utente);
                }
                $parte = $this->versioni->scrivi($verbale, 'intero', null, $esito['documento'], 'programma', now(), $utente);
                $versione = $this->versioni->nuova($verbale, 'intero', $esito['documento'], $frase, 'programma', null, now(), $utente);
                $verbale->update(['stato' => StatoVerbale::BOZZA->value, 'modello_id' => $esito['modello']['id'], 'modello_nome' => $esito['modello']['nome'],
                    'impronta_sala' => $esito['impronta_sala'], 'generato_il' => now()]);

                return ['numero' => (int) $versione->numero, 'parte' => $parte];
            });

        return $verbale->refresh();
    }

    /**
     * «Aggiorna le parti segnate»: le parti «Dati della sala» del testo intero rifatte dai dati di adesso (un voto corretto prima della registrazione), in una
     * versione nuova; il resto del testo resta com'è. Una parte dei voti tolta dal testo torna al suo posto nel punto (dopo il titolo e la discussione, prima
     * delle altre parti del punto): l'avviso delle cifre lo promette.
     */
    public function aggiornaLeParti(VerbaleDellAssemblea $verbale, User $utente): VerbaleDellAssemblea
    {
        if ($verbale->stato !== StatoVerbale::BOZZA) {
            throw ValidationException::withMessages(['verbale' => 'Le parti con i dati della sala si aggiornano nella bozza del verbale.']);
        }
        $nuovi = $this->blocchiDiAdesso($verbale);
        RegistroModifiche::durante(GestiDelRegistro::delVerbale($verbale, fn ($lotto, $v) => 'parti con i dati della sala aggiornate, versione ' . ($v ?? '')),
            function () use ($verbale, $nuovi, $utente) {
                $parte = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->lockForUpdate()->firstOrFail();
                $documento = (array) $parte->documento;
                $documento['content'] = array_map(fn ($n) => ($n['type'] ?? null) === 'datiDellaSala' && isset($nuovi[$n['attrs']['chiave'] ?? ''])
                    ? $nuovi[$n['attrs']['chiave']] : $n, (array) ($documento['content'] ?? []));
                $documento['content'] = $this->rimettiLeParti($verbale, (array) $documento['content'], $nuovi);
                $documento = StrutturaDelVerbale::valida($documento);
                $this->versioni->tieniIlTestoDiAdesso($verbale, $parte, 'il testo prima di aggiornare le parti della sala', $utente);
                $this->versioni->scrivi($verbale, 'intero', null, $documento, 'programma', now(), $utente);
                $v = $this->versioni->nuova($verbale, 'intero', $documento, 'parti con i dati della sala aggiornate', 'programma', null, now(), $utente);
                $verbale->update(['impronta_sala' => self::impronta($nuovi)]);

                return (int) $v->numero;
            });

        return $verbale->refresh();
    }

    /**
     * «Mettila nel punto 4 del verbale»: la discussione arrivata dopo la generazione entra nel testo intero, al suo posto nel punto (dopo la discussione che
     * c'era, prima delle parti con i voti), e nella parte del punto (così «Rigenera» la ritrova). Il testo di prima resta fra le versioni.
     *
     * @param array<string, mixed> $discussione già valida
     */
    public function mettiLaDiscussione(VerbaleDellAssemblea $verbale, int $puntoId, array $discussione, User $utente, string $perche): void
    {
        $p = collect($this->dati($verbale)['punti'])->firstWhere('punto_id', $puntoId);
        $parte = ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'intero')->lockForUpdate()->firstOrFail();
        $contenuto = (array) (((array) $parte->documento)['content'] ?? []);
        $dove = $p === null ? null : $this->dopoLaDiscussione($contenuto, $p);
        if ($dove === null) {
            throw ValidationException::withMessages(['scelta' => 'Non trovo il punto nel testo del verbale (il titolo è cambiato a mano): copia la discussione dalla '
                . 'versione, o rigenera il verbale dal modello.']);
        }
        array_splice($contenuto, $dove, 0, array_values((array) ($discussione['content'] ?? [])));
        $documento = StrutturaDelVerbale::valida(StrutturaDelVerbale::documento($contenuto));
        $this->versioni->tieniIlTestoDiAdesso($verbale, $parte, 'il testo prima di mettere la discussione arrivata dopo', $utente);
        $this->versioni->scrivi($verbale, 'intero', null, $documento, 'programma', now(), $utente);
        $this->versioni->nuova($verbale, 'intero', $documento, mb_substr($perche, 0, 200), 'programma', null, now(), $utente);
        $this->versioni->scrivi($verbale, 'punto:' . $puntoId, $puntoId, $discussione, 'programma', now(), $utente);
    }

    /**
     * Le parti dei voti tolte dal testo, rimesse nel loro punto. @param list<array<string, mixed>> $contenuto @param array<string, array<string, mixed>> $nuovi
     * @return list<array<string, mixed>>
     */
    private function rimettiLeParti(VerbaleDellAssemblea $verbale, array $contenuto, array $nuovi): array
    {
        $nelTesto = StrutturaDelVerbale::blocchi(StrutturaDelVerbale::documento($contenuto));
        $mancano = array_values(array_filter(array_keys($nuovi), fn ($k) => str_starts_with($k, 'voto:') && ! isset($nelTesto[$k])));
        if ($mancano === []) {
            return $contenuto;
        }
        foreach ($this->dati($verbale)['punti'] as $p) {
            $chiavi = $this->chiaviDelPunto($p);
            foreach ($chiavi as $i => $chiave) {
                if (! in_array($chiave, $mancano, true)) {
                    continue;
                }
                $dove = $this->posizioneNelPunto($contenuto, $p, $chiavi, $i);
                if ($dove !== null) {
                    array_splice($contenuto, $dove, 0, [$nuovi[$chiave]]);
                }
            }
        }

        return array_values($contenuto);
    }

    /**
     * Dove va la parte `$chiavi[$i]` del punto: prima della prima parte del punto che viene dopo di lei e c'è, o dopo l'ultima che viene prima, o dopo il
     * titolo e la discussione del punto. Null se il punto non si trova nel testo.
     *
     * @param list<array<string, mixed>> $contenuto @param array<string, mixed> $p @param list<string> $chiavi
     */
    private function posizioneNelPunto(array $contenuto, array $p, array $chiavi, int $i): ?int
    {
        $posto = fn (string $k) => array_key_first(array_filter($contenuto, fn ($n) => ($n['type'] ?? null) === 'datiDellaSala' && ($n['attrs']['chiave'] ?? null) === $k));
        foreach (array_slice($chiavi, $i + 1) as $dopo) {
            if (($j = $posto($dopo)) !== null) {
                return $j;
            }
        }
        foreach (array_reverse(array_slice($chiavi, 0, $i)) as $prima) {
            if (($j = $posto($prima)) !== null) {
                return $j + 1;
            }
        }

        return $this->dopoLaDiscussione($contenuto, $p);
    }

    /**
     * La posizione dopo il titolo del punto e la sua discussione: prima della prima parte «Dati della sala» del punto, o, senza, dopo i paragrafi che seguono
     * il titolo. Null se né le parti né il titolo («4. …») sono nel testo.
     *
     * @param list<array<string, mixed>> $contenuto @param array<string, mixed> $p
     */
    private function dopoLaDiscussione(array $contenuto, array $p): ?int
    {
        $chiavi = $this->chiaviDelPunto($p);
        foreach ($contenuto as $j => $n) {
            if (($n['type'] ?? null) === 'datiDellaSala' && in_array($n['attrs']['chiave'] ?? null, $chiavi, true)) {
                return $j;
            }
        }
        $inizio = $p['numero'] . '. ';
        foreach ($contenuto as $j => $n) {
            if (($n['type'] ?? null) === 'heading' && str_starts_with(StrutturaDelVerbale::testo((array) $n), $inizio)) {
                $k = $j + 1;
                while (isset($contenuto[$k]) && ! in_array($contenuto[$k]['type'] ?? null, ['heading', 'datiDellaSala'], true)) {
                    $k++;
                }

                return $k;
            }
        }

        return null;
    }

    /** La discussione di un punto scritta in sala o nella pagina: la parte `punto:<id>`, o un documento vuoto. @return array<string, mixed> */
    public function discussione(VerbaleDellAssemblea $verbale, int $puntoId): array
    {
        return (array) (ParteDelVerbale::query()->where('verbale_id', $verbale->id)->where('parte', 'punto:' . $puntoId)->value('documento') ?? ['type' => 'doc']);
    }

    /**
     * I dati della riunione del verbale, dalla lettura per il verbale: la seduta, quella che viene prima (la prima deserta), i punti della riunione con le loro
     * votazioni e dichiarazioni, le dichiarazioni sulla seduta, se è una prosecuzione, i punti che restano dopo una sospensione.
     *
     * @return array<string, mixed>
     */
    public function dati(VerbaleDellAssemblea $verbale): array
    {
        $a = $verbale->assemblea()->firstOrFail();
        $lettura = $this->lettura->perIlVerbale($a);
        $sedute = collect($lettura['sedute']);
        $seduta = $sedute->firstWhere('id', (int) $verbale->seduta_id);
        $giorno = substr((string) $seduta['fissata_il'], 0, 10);
        $votatiAltrove = [];
        $punti = [];
        foreach ($lettura['punti'] as $p) {
            $qui = array_values(array_filter($p['votazioni'], fn ($v) => (int) $v['seduta_id'] === (int) $seduta['id']));
            if ($qui === [] && $p['votazioni'] !== []) {
                $votatiAltrove[] = (int) $p['numero'];
            }
            $rinviataQui = $qui === [] && $p['votazioni'] === [] && collect($p['delibere'])->contains(fn ($d) => $d['esito'] === EsitoDelibera::RINVIATA->value
                && $d['deliberata_il'] === $giorno && (int) $d['votazione'] === 1);
            if ($qui !== [] || $rinviataQui) {
                $punti[] = $p + ['votazioni_qui' => $qui, 'rinviato_qui' => $rinviataQui,
                    'dichiarazioni' => array_values(array_filter($seduta['dichiarazioni'], fn ($d) => (int) $d['punto_id'] === (int) $p['punto_id']))];
            }
        }
        $trattati = array_map(fn ($p) => (int) $p['numero'], $punti);
        $restano = array_values(array_map(fn ($p) => (int) $p['numero'], array_filter($lettura['punti'], fn ($p) => ! in_array((int) $p['numero'], $trattati, true)
            && ! in_array((int) $p['numero'], $votatiAltrove, true) && ! collect($p['delibere'])->contains(fn ($d) => $d['deliberata_il'] !== null
            && $d['deliberata_il'] < $giorno))));
        // I punti da trattare in questa riunione: quelli senza un voto in una seduta di prima (la prosecuzione li dice nella sua prima frase).
        $primaDiQuesta = $sedute->filter(fn ($x) => $x['aperta_il'] !== null && (string) $x['aperta_il'] < (string) $seduta['aperta_il'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $daTrattare = array_values(array_map(fn ($p) => (int) $p['numero'], array_filter($lettura['punti'], fn ($p) => ! collect($p['votazioni'])
            ->contains(fn ($v) => in_array((int) $v['seduta_id'], $primaDiQuesta, true)))));
        $prima = (int) $seduta['numero'] > 1
            ? $sedute->first(fn ($s) => $s['convocazione'] === $seduta['convocazione'] && (int) $s['numero'] === (int) $seduta['numero'] - 1)
            : ($seduta['convocazione'] === 'seconda' ? $sedute->first(fn ($s) => $s['convocazione'] === 'prima' && (int) $s['numero'] === 1) : null);

        return [
            'assemblea_id' => (int) $a->id,
            'assemblea' => $lettura['assemblea'],
            'convocazione' => $lettura['convocazione'],
            'seduta' => $seduta,
            'prima' => $prima,
            'prosecuzione' => (int) $seduta['numero'] > 1,
            'punti' => $punti,
            'restano' => $restano,
            'da_trattare' => $daTrattare,
            'dichiarazioni_della_seduta' => array_values(array_filter($seduta['dichiarazioni'], fn ($d) => $d['punto_id'] === null)),
            'annullata_motivo' => $a->annullata_motivo,
        ];
    }

    /** I campi del modello riempiti dalla riunione. @param array<string, mixed> $dati @return array<string, string> */
    public function campi(array $dati): array
    {
        $s = $dati['seduta'];
        $condominio = TestiLettere::condominioConNome((string) $dati['assemblea']['condominio']) . ($dati['assemblea']['perimetro'] !== null
            ? ', ' . $dati['assemblea']['perimetro'] : '');
        $convocazione = $s['convocazione'] === 'seconda' ? 'seconda convocazione' . (($dati['prima']['esito'] ?? null) === 'deserta'
            ? ', essendo andata deserta la prima del ' . ParoleDelVerbale::giorno(substr((string) $dati['prima']['fissata_il'], 0, 10)) : '') : 'prima convocazione';

        $valori = array_map(fn ($v) => $v === '' ? '—' : $v, [
            '[condominio]' => $condominio,
            '[tipo]' => (string) $dati['assemblea']['tipo'],
            '[giorno]' => ParoleDelVerbale::giorno(substr((string) $s['fissata_il'], 0, 10)),
            '[ora]' => ParoleDelVerbale::ora($s['aperta_il']),
            '[luogo]' => self::luogo($dati['assemblea'], $s['luogo'] ?? null),
            '[convocazione]' => $convocazione,
            '[presidente]' => self::nomeDiMezzaFrase((string) ($s['presidente']['nome'] ?? '')),
            '[segretario]' => self::nomeDiMezzaFrase((string) ($s['segretario']['nome'] ?? '')),
            '[ora_di_chiusura]' => ParoleDelVerbale::ora($s['chiusa_il']),
        ]);
        // «… in seconda convocazione, essendo andata deserta la prima del 14 dicembre 2026, l'assemblea …»: l'inciso chiude con la virgola quando la frase
        // continua (il campo seguito da uno spazio), non davanti a un segno. `strtr` prova prima la chiave più lunga.
        if (str_contains($convocazione, ', essendo')) {
            $valori['[convocazione] '] = $convocazione . ', ';
        }

        return $valori;
    }

    /** «Sala condominiale, Via Roma 12»; in videoconferenza la piattaforma; mista tutti e due. @param array<string, mixed> $assemblea */
    private static function luogo(array $assemblea, ?string $luogo): string
    {
        $luogo = trim((string) $luogo);
        $piattaforma = trim((string) ($assemblea['piattaforma'] ?? ''));

        return match ($assemblea['modalita'] ?? 'presenza') {
            'videoconferenza' => 'la piattaforma ' . ($piattaforma !== '' ? '«' . $piattaforma . '»' : 'indicata nell\'avviso') . ', in videoconferenza',
            'mista' => $luogo . ' e in videoconferenza sulla piattaforma ' . ($piattaforma !== '' ? '«' . $piattaforma . '»' : 'indicata nell\'avviso'),
            default => $luogo,
        };
    }

    /** Le chiavi delle parti «Dati della sala» di un punto, nell'ordine: i voti, il rinvio, le dichiarazioni. @param array<string, mixed> $p @return list<string> */
    private function chiaviDelPunto(array $p): array
    {
        $chiavi = array_map(fn ($v) => 'voto:' . $v['id'], $p['votazioni_qui']);
        if ($p['rinviato_qui']) {
            $chiavi[] = 'rinvio_punto:' . $p['punto_id'];
        }

        return [...$chiavi, ...array_map(fn ($d) => 'dichiarazione:' . $d['id'], $p['dichiarazioni'])];
    }

    /** Tutte le parti «Dati della sala» della riunione. @param array<string, mixed> $dati @return array<string, array<string, mixed>> */
    private function blocchi(array $dati): array
    {
        $s = $dati['seduta'];
        $b = [];
        $frase = $this->fraseDellaConvocazione($dati['convocazione']);
        if ($frase !== null && ! $dati['prosecuzione']) {
            $b['convocazione:' . $dati['assemblea_id']] = StrutturaDelVerbale::datiDellaSala('convocazione:' . $dati['assemblea_id'], [$frase]);
        }
        $apertura = (array) ($s['apertura'] ?? []);
        $problemi = array_values(array_filter(array_column((array) ($apertura['problemi'] ?? []), 'frase')));
        $rifiutate = array_values(array_filter((array) ($apertura['deleghe_rifiutate'] ?? [])));
        if (($apertura['scelta'] ?? null) === 'apri' && $problemi !== [] || $rifiutate !== []) {
            $frasi = [];
            if ($problemi !== []) {
                $frasi[] = 'Il presidente dà atto di quanto segue: ' . implode(' ', $problemi) . ' Dispone di procedere' . (filled($apertura['motivo'] ?? null)
                    ? ', con il motivo: ' . rtrim((string) $apertura['motivo'], '.') . '.' : '.');
            }
            foreach ($rifiutate as $r) {
                $frasi[] = (string) $r;
            }
            $b['apertura:' . $s['id']] = StrutturaDelVerbale::datiDellaSala('apertura:' . $s['id'], $frasi);
        }
        $c = $s['costituzione'] ?? null;
        if ($c !== null) {
            $presenti = ParoleDelVerbale::condomini((int) $c['teste']) . ' su ' . (int) $c['totale_teste'] . ', per ' . ParoleDelVerbale::millesimi((int) $c['valore'])
                . ' millesimi su ' . ParoleDelVerbale::totale((int) $c['totale_valore']) . ', come dall\'elenco in calce.';
            // I presenti sono quelli alla costituzione (il primo momento in cui i conti bastano, con l'ora); se l'assemblea non risulta mai costituita, quelli
            // al primo voto o alla chiusura, e la frase lo dice: il verbale non si contraddice sui presenti.
            $frasi = ($c['costituita'] ?? false)
                ? ['Alle ore ' . ParoleDelVerbale::ora($c['al']) . ' l\'assemblea è regolarmente costituita.', 'Sono presenti, di persona o per delega, ' . $presenti]
                : ['L\'assemblea non risulta costituita. Alle ore ' . ParoleDelVerbale::ora($c['al']) . ' sono presenti, di persona o per delega, ' . $presenti];
            // I contitolari entrati con chi rappresenta la loro unità, presenti senza voto (rilievo C53): il verbale li nomina tutti.
            array_push($frasi, ...self::presentiSenzaVoto((array) ($s['presenze'] ?? []), $this->fotografiaDellaSeduta((int) $s['id'])));
            $b['costituzione:' . $s['id']] = StrutturaDelVerbale::datiDellaSala('costituzione:' . $s['id'], $frasi);
        }
        foreach ($dati['punti'] as $p) {
            foreach ($p['votazioni_qui'] as $v) {
                $b['voto:' . $v['id']] = StrutturaDelVerbale::datiDellaSala('voto:' . $v['id'], $this->frasiDelVoto($v));
            }
            if ($p['rinviato_qui']) {
                $b['rinvio_punto:' . $p['punto_id']] = StrutturaDelVerbale::datiDellaSala('rinvio_punto:' . $p['punto_id'], ['Il punto non è trattato: è rinviato.']);
            }
            foreach ($p['dichiarazioni'] as $d) {
                $b['dichiarazione:' . $d['id']] = self::dichiarazione($d);
            }
        }
        foreach ($dati['dichiarazioni_della_seduta'] as $d) {
            $b['dichiarazione:' . $d['id']] = self::dichiarazione($d);
        }
        $luogo = self::luogo($dati['assemblea'], $s['luogo'] ?? null);
        if (($s['esito'] ?? null) === 'sospesa' && $s['prosegue'] !== null) {
            $b['sospensione:' . $s['id']] = StrutturaDelVerbale::datiDellaSala('sospensione:' . $s['id'], ['Alle ore ' . ParoleDelVerbale::ora($s['chiusa_il'])
                . ' il presidente sospende l\'assemblea, che prosegue ' . ParoleDelVerbale::giornoConLaSettimana(substr((string) $s['prosegue']['fissata_il'], 0, 10))
                . ' alle ore ' . substr((string) $s['prosegue']['fissata_il'], 11, 5) . ', presso ' . $luogo . ', come indicato nell\'avviso di convocazione'
                . ($dati['restano'] !== [] ? ', per ' . ParoleDelVerbale::punti($dati['restano']) : '') . '.']);
        }
        if (($s['esito'] ?? null) === 'rinviata') {
            $motivo = trim((string) preg_replace('/^Rinviata dalla sala:\s*/u', '', (string) $dati['annullata_motivo']));
            $nuova = $dati['assemblea']['riconvocata'] ?? null;
            $b['rinvio:' . $s['id']] = StrutturaDelVerbale::datiDellaSala('rinvio:' . $s['id'], array_values(array_filter([
                'Alle ore ' . ParoleDelVerbale::ora($s['chiusa_il']) . ' il presidente rinvia l\'assemblea' . ($motivo !== '' ? ': ' . rtrim($motivo, '.') . '.' : '.'),
                ($nuova['data'] ?? null) !== null ? 'L\'assemblea è riconvocata per il ' . ParoleDelVerbale::giorno(\Carbon\Carbon::parse($nuova['data'])) . '.' : null,
            ])));
        }
        if ($dati['prosecuzione']) {
            $prima = $dati['prima'];
            $trattati = $dati['da_trattare'];
            $b['prosecuzione:' . $s['id']] = StrutturaDelVerbale::datiDellaSala('prosecuzione:' . $s['id'], [
                'Il giorno ' . ParoleDelVerbale::giorno(substr((string) $s['fissata_il'], 0, 10)) . ', alle ore ' . ParoleDelVerbale::ora($s['aperta_il']) . ', presso '
                . $luogo . ', prosegue l\'assemblea ' . $dati['assemblea']['tipo'] . ' del ' . ParoleDelVerbale::giorno(substr((string) ($prima['fissata_il'] ?? ''), 0, 10))
                . ' dei condòmini del ' . TestiLettere::condominioConNome((string) $dati['assemblea']['condominio'])
                . ', sospesa alle ore ' . ParoleDelVerbale::ora($prima['chiusa_il'] ?? null) . ($trattati !== [] ? ', per ' . ParoleDelVerbale::punti($trattati)
                    . ' dell\'ordine del giorno' : '') . '.',
                'Assume la presidenza ' . (self::nomeDiMezzaFrase((string) ($s['presidente']['nome'] ?? '')) ?: '—') . '; funge da segretario '
                    . (self::nomeDiMezzaFrase((string) ($s['segretario']['nome'] ?? '')) ?: '—') . '.',
            ]);
        }

        return $b;
    }

    /**
     * Il nome di chi presiede o fa da segretario dentro una frase: «L'amministratore» (la scelta di serie della sala, scritta con la maiuscola come voce del
     * menu) a metà frase è «l'amministratore»; il nome di una persona resta com'è.
     */
    private static function nomeDiMezzaFrase(string $nome): string
    {
        return $nome === 'L\'amministratore' ? 'l\'amministratore' : $nome;
    }

    /** «L'assemblea è stata convocata con avvisi spediti dal 3 al 9 dicembre 2026, per raccomandata, PEC e a mano.» @param array<string, mixed> $c */
    private function fraseDellaConvocazione(array $c): ?string
    {
        if (($c['dal'] ?? null) === null) {
            return null;
        }
        $dal = (string) $c['dal'];
        $al = (string) $c['al'];
        $quando = $dal === $al ? 'il ' . ParoleDelVerbale::giorno($dal)
            : (substr($dal, 0, 7) === substr($al, 0, 7) ? 'dal ' . (int) substr($dal, 8, 2) . ' al ' . ParoleDelVerbale::giorno($al)
                : 'dal ' . ParoleDelVerbale::giorno($dal) . ' al ' . ParoleDelVerbale::giorno($al));
        $parole = ['raccomandata' => 'raccomandata', 'pec' => 'PEC', 'a_mano' => 'a mano', 'fax' => 'fax'];
        $mezzi = array_values(array_filter(array_map(fn ($m) => $parole[$m] ?? null, array_keys(array_filter((array) $c['mezzi'])))));

        return 'L\'assemblea è stata convocata con avvisi spediti ' . $quando . ($mezzi !== [] ? ', per ' . ParoleDelVerbale::elenco($mezzi) : '') . '.';
    }

    /** Le frasi del voto di un punto. @param array<string, mixed> $v @return list<string> */
    private function frasiDelVoto(array $v): array
    {
        if (($v['maggioranza'] ?? null) === Maggioranza::SENZA_VOTO->value) {
            return ['Il punto è trattato senza voto.'];
        }
        $i = (array) $v['intervenuti'];
        $c = (array) ($v['conteggi'] ?? []);
        $su = match ($v['oggetto'] ?? null) {
            'rendiconto' => 'Sul rendiconto: ',
            'riparto' => 'Sul riparto: ',
            default => '',
        };
        $regola = VotazioniDellaSala::regolaDellaMaggioranza($v['maggioranza'] ?? null, (string) ($i['convocazione'] ?? 'seconda'), (int) ($i['totale_teste'] ?? 0),
            (int) ($i['totale_valore'] ?? 0));
        $frasi = [$su . 'Si vota alle ore ' . ParoleDelVerbale::ora($v['votata_il']) . ': intervengono ' . ParoleDelVerbale::condomini((int) ($i['numero'] ?? 0))
            . ' per ' . ParoleDelVerbale::millesimi((int) ($i['valore'] ?? 0)) . ' millesimi; '
            . ($regola ?? ($v['maggioranza'] === Maggioranza::LIBERA->value ? 'la maggioranza la decide il presidente' : 'la maggioranza non è scelta: decide il presidente')) . '.'];
        foreach ((array) ($v['scelte']['usufruttuari'] ?? []) as $u) {
            if (filled($u['frase'] ?? null)) {
                $frasi[] = (string) $u['frase'];
            }
        }
        if (filled($i['perimetro']['avviso'] ?? null)) {
            $frasi[] = (string) $i['perimetro']['avviso'];
        }
        $teste = collect((array) ($i['teste'] ?? []))->keyBy('chiave');
        $voti = collect((array) $v['voti']);
        $persone = $this->personeDellaSeduta((int) ($v['seduta_id'] ?? 0));
        $nome = function (array $voto) use ($teste, $persone) {
            $testa = (string) ($teste[$voto['testa']]['nome'] ?? $voto['nome']);
            // I nomi della testa dalle sue persone, mai dalla stringa: «Ferro e Acciaio S.r.l.» è una persona sola.
            $nomi = array_map(fn ($id) => $persone[(string) $id]['nome'] ?? null, (array) ($teste[$voto['testa']]['persone'] ?? []));
            $nomi = $nomi !== [] && ! in_array(null, $nomi, true) ? array_map('strval', $nomi) : null;

            return match ($voto['ruolo'] ?? null) {
                'delegato' => $testa . ' per delega a ' . $voto['nome'],
                'rappresentante' => $testa !== $voto['nome'] ? self::rappresentante($testa, (string) $voto['nome'], $nomi) : $testa,
                default => $testa,
            } . ' ' . ParoleDelVerbale::millesimi((int) $voto['millesimi']);
        };
        $discordeAstenuto = fn (array $x) => ($x['voto'] ?? null) === 'discorde' ? (($x['decisione'] ?? null) ?? 'astenuto') : ($x['voto'] ?? null);
        foreach (['favorevole' => 'Favorevoli', 'contrario' => 'Contrari', 'astenuto' => 'Astenuti'] as $voto => $parola) {
            $suoi = $voti->filter(fn ($x) => $discordeAstenuto($x) === $voto)->values();
            $chiave = ['favorevole' => 'favorevoli', 'contrario' => 'contrari', 'astenuto' => 'astenuti'][$voto];
            $valore = (int) ($c[$chiave]['valore'] ?? $suoi->sum('millesimi'));
            $frasi[] = $suoi->isEmpty() ? $parola . ': nessuno.'
                : $parola . ': ' . ParoleDelVerbale::condomini($suoi->count()) . ' per ' . ParoleDelVerbale::millesimi($valore) . ' millesimi ('
                    . implode(', ', $suoi->map(fn ($x) => $nome($x))->all()) . ').';
        }
        $senza = (array) ($c['non_votato'] ?? []);
        if ((int) ($senza['teste'] ?? 0) > 0) {
            $frasi[] = 'Non hanno votato: ' . ParoleDelVerbale::condomini((int) $senza['teste']) . ' per ' . ParoleDelVerbale::millesimi((int) $senza['valore'])
                . ' millesimi (' . implode(', ', array_map(fn ($x) => $x['nome'] . ' ' . ParoleDelVerbale::millesimi((int) $x['millesimi']), (array) $senza['nomi'])) . ').';
        }
        foreach ($voti->filter(fn ($x) => (bool) ($x['in_conflitto'] ?? false)) as $x) {
            $chi = (string) ($teste[$x['testa']]['nome'] ?? $x['nome']);
            $frasi[] = $chi . ' dichiara di essere in conflitto d\'interessi' . (filled($x['nota_conflitto'] ?? null) ? ': ' . rtrim((string) $x['nota_conflitto'], '.') . '.' : '.');
        }
        if (($v['conflitto_determinante'] ?? false) && filled($c['conflitto'] ?? null)) {
            $frasi[] = (string) $c['conflitto'];
        }
        if (filled($v['nota'] ?? null)) {
            $frasi[] = 'Il presidente annota: ' . rtrim((string) $v['nota'], '.') . '.';
        }
        if (filled($v['nota_non_votato'] ?? null)) {
            $frasi[] = 'Il presidente annota su chi non ha votato: ' . rtrim((string) $v['nota_non_votato'], '.') . '.';
        }
        $frasi[] = match ($v['esito'] ?? null) {
            EsitoDelibera::APPROVATA->value => 'La proposta è approvata.',
            EsitoDelibera::APPROVATA_CON_MODIFICHE->value => 'La proposta è approvata con modifiche.',
            EsitoDelibera::RESPINTA->value => 'La proposta è respinta.',
            EsitoDelibera::RINVIATA->value => 'Il punto è rinviato.',
            default => 'Il voto non è confermato.',
        };
        if (filled($v['motivo'] ?? null)) {
            $frasi[] = 'Il presidente conferma l\'esito con il motivo: ' . rtrim((string) $v['motivo'], '.') . '.';
        }

        return $frasi;
    }

    /**
     * I comproprietari di un'unità sono una testa con un solo rappresentante (art. 67 c. 2 disp. att. c.c.): il verbale scrive chi c'era, con accanto gli
     * altri comproprietari, «Russo Marco, rappresentante anche di Russo Anna». I nomi sono quelli delle persone della testa (`$nomiDellaTesta`, dalla
     * fotografia della seduta): spezzare il nome della testa sulla «e» dividerebbe «Ferro e Acciaio S.r.l.» in due. Se il nome di chi vota non è fra quelli
     * della testa, resta la frase di prima.
     *
     * @param ?list<string> $nomiDellaTesta
     */
    public static function rappresentante(string $testa, string $chi, ?array $nomiDellaTesta = null): string
    {
        $nomi = $nomiDellaTesta !== null ? array_values(array_map('trim', $nomiDellaTesta))
            : array_values(array_filter(array_map('trim', preg_split('/,\s*|\s+e\s+/u', $testa) ?: [])));
        if (! in_array($chi, $nomi, true)) {
            return $testa . ', per i quali vota ' . $chi;
        }
        $altri = array_values(array_filter($nomi, fn ($n) => $n !== $chi));

        return $altri === [] ? $chi : $chi . ', rappresentante anche di ' . ParoleDelVerbale::elenco($altri);
    }

    /**
     * I contitolari presenti senza voto (art. 67 c. 2 disp. att. c.c., rilievo C53): per ogni ingresso di chi rappresenta una testa con altri della stessa
     * testa, «Russo Marco, che rappresenta l'Int. 5, e Russo Anna, contitolare, presente senza voto.». Una frase per testa, anche se è entrata due volte.
     *
     * @param list<array<string, mixed>> $presenze
     * @param array<string, mixed> $fotografia
     * @return list<string>
     */
    public static function presentiSenzaVoto(array $presenze, array $fotografia): array
    {
        $frasi = [];
        foreach ($presenze as $p) {
            $insieme = array_values((array) ($p['insieme'] ?? []));
            if ($insieme === []) {
                continue;
            }
            $unita = array_map(fn ($u) => (string) ($fotografia['unita'][$u]['nome'] ?? $fotografia['unita'][(string) $u]['nome'] ?? ''), (array) ($p['rappresenta'] ?? []));
            $unita = array_values(array_filter($unita));
            $altri = ParoleDelVerbale::elenco(array_map(fn ($x) => (string) $x['nome'], $insieme));
            $frasi[] = trim((string) $p['nome']) . ($unita !== [] ? ', che rappresenta l\'' . ParoleDelVerbale::elenco($unita) . ',' : '') . ' e ' . $altri
                . (count($insieme) === 1 ? ', contitolare, presente senza voto.' : ', contitolari, presenti senza voto.');
        }

        return array_values(array_unique($frasi));
    }

    /** La fotografia della seduta, letta una volta. @return array<string, mixed> */
    private function fotografiaDellaSeduta(int $seduta): array
    {
        return $this->fotografieLette[$seduta] ??= (array) (\App\Models\Assemblee\SedutaDellAssemblea::query()->whereKey($seduta)->first()?->fotografia ?? []);
    }

    /** @var array<int, array<string, mixed>> le fotografie delle sedute, lette una volta */
    private array $fotografieLette = [];

    /** @var array<int, array<string, array<string, mixed>>> le persone della fotografia di ogni seduta, lette una volta */
    private array $personeLette = [];

    /** Le persone della fotografia della seduta: id => {nome, …}. @return array<string, array<string, mixed>> */
    private function personeDellaSeduta(int $seduta): array
    {
        return $this->personeLette[$seduta] ??= (array) (((array) (\App\Models\Assemblee\SedutaDellAssemblea::query()->whereKey($seduta)->first()?->fotografia ?? []))['persone'] ?? []);
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private static function dichiarazione(array $d): array
    {
        return StrutturaDelVerbale::datiDellaSala('dichiarazione:' . $d['id'], [trim((string) $d['nome']) . ' chiede che sia messo a verbale: «'
            . trim((string) $d['testo']) . '»']);
    }

    /** L'impronta delle parti «Dati della sala»: chiave => testo semplice, in JSON. @param array<string, array<string, mixed>> $blocchi */
    private static function impronta(array $blocchi): string
    {
        ksort($blocchi);

        return hash('sha256', (string) json_encode(array_map(fn ($n) => StrutturaDelVerbale::testo($n), $blocchi), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
