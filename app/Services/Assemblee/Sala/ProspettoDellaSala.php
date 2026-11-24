<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\ComePresente;
use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\EsitoDelGesto;
use App\Enums\Assemblee\EsitoSeduta;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\StatoDelega;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use Carbon\CarbonImmutable;
use Mpdf\Output\Destination;

/**
 * «Le presenze e i voti»: il prospetto della sala con i nomi e i millesimi, il documento che il verbale richiama (un foglio separato non richiamato non
 * vale: Cass. 558/2026). Si legge da ciò che la sala ha registrato ({@see LetturaDellaSala::perIlVerbale()}), seduta per seduta:
 *
 * - l'apertura (o la prima convocazione deserta, con l'ora in cui si è segnata), presidente e segretario, la scelta del presidente all'apertura, se la seduta
 *   è registrata dal foglio della sala, la chiusura o la sospensione;
 * - le presenze con come (in sala, in collegamento con il motivo), entrata e uscita, il collegamento caduto, chi rappresenta una comproprietà, chi porta una
 *   delega;
 * - per ogni votazione l'ora, la maggioranza, gli intervenuti, favorevoli, contrari, astenuti e chi non ha votato **con i nomi e i millesimi**, i conflitti
 *   con la nota, le scelte del presidente sull'usufrutto, l'esito proposto e quello confermato, il motivo, le note del presidente sul voto e su chi non ha
 *   votato; i punti trattati senza voto;
 * - le dichiarazioni a verbale;
 * - l'impronta della fotografia di ogni seduta e **l'impronta del prospetto** (SHA-256 dei dati stampati, senza l'ora della stampa): due stampe con gli
 *   stessi dati hanno la stessa impronta, così il verbale richiama questo prospetto e non un altro.
 *
 * È un documento del condominio: la vista estende `pdf.base`, con il gruppo «Rendiconto e assemblea» e la testata comune.
 */
final class ProspettoDellaSala
{
    public function __construct(
        private readonly LetturaDellaSala $lettura,
        private readonly PdfService $pdf,
    ) {}

    /**
     * La scelta del presidente all'apertura (art. 1136 c. 6 c.c.): «Aperta con la scelta del presidente: …», o, per la seduta aperta senza rete con il
     * controllo cambiato, quando l'ha decisa dopo; «da decidere» finché non decide.
     *
     * @param array<string, mixed> $apertura
     */
    private static function sceltaDellApertura(array $apertura, ?string $apertaIl): ?string
    {
        $problemi = implode(' ', array_column((array) ($apertura['problemi'] ?? []), 'frase'));
        $motivo = filled($apertura['motivo'] ?? null) ? ' Motivo: ' . $apertura['motivo'] : '';
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $ora = fn (?string $iso) => $iso === null ? '' : CarbonImmutable::parse($iso)->setTimezone($fuso)->format('H:i');

        return match (true) {
            ($apertura['scelta'] ?? null) === StatiDellaSala::DA_DECIDERE => 'Aperta alle ' . $ora($apertaIl) . ' senza la scelta, il controllo era cambiato senza '
                . 'rete: decide il presidente. ' . $problemi,
            ($apertura['scelta'] ?? null) === 'apri' && filled($apertura['decisa_il'] ?? null) => 'Aperta alle ' . $ora($apertaIl) . ' senza la scelta, il '
                . 'controllo era cambiato senza rete; il presidente ha deciso alle ' . $ora((string) $apertura['decisa_il']) . ': ' . $problemi . $motivo,
            ($apertura['scelta'] ?? null) === 'apri' => 'Aperta con la scelta del presidente: ' . $problemi . $motivo,
            default => null,
        };
    }

    /** Si stampa da quando una seduta si è aperta o la prima convocazione è segnata deserta. */
    public static function siStampa(Assemblea $assemblea): bool
    {
        return $assemblea->sedute()->where(fn ($q) => $q->whereNotNull('aperta_il')->orWhereNotNull('esito'))->exists();
    }

    /** Il PDF del prospetto. */
    public function pdf(Assemblea $assemblea): string
    {
        $dati = $this->dati($assemblea);

        return $this->pdf->generate('pdf.gestionale.assemblea_presenze_voti', $dati + [
            'gruppo_carta' => GruppoCarta::RENDICONTO_E_ASSEMBLEA,
            'condominio' => $assemblea->condominio,
            'titolo_stampa' => 'Le presenze e i voti · ' . $dati['assemblea'],
            'senza_firma' => true,
        ])->Output('', Destination::STRING_RETURN);
    }

    /**
     * Ciò che il prospetto scrive, con la sua impronta.
     *
     * @return array{assemblea: string, titolo: string, sedute: list<array<string, mixed>>, senza_esito_in_sala: list<array{punto: string, esito: string}>,
     *     impronte: list<array{seduta: string, impronta: string}>, impronta: string}
     */
    public function dati(Assemblea $assemblea): array
    {
        return $this->calcola($assemblea)['dati'];
    }

    /**
     * Le presenze e i voti di una seduta sola, come li scrive il prospetto: l'elenco in calce del verbale di quella riunione (un verbale per riunione,
     * decisione 595 (1)), con l'impronta della fotografia della seduta. Null se la seduta non si è aperta.
     *
     * @return ?array<string, mixed>
     */
    public function dellaSeduta(Assemblea $assemblea, int $sedutaId): ?array
    {
        $calcolo = $this->calcola($assemblea);
        $i = array_search($sedutaId, $calcolo['sedute_id'], true);
        if ($i === false) {
            return null;
        }
        $impronta = collect($calcolo['impronte_per_seduta'])->firstWhere('seduta_id', $sedutaId)['impronta'] ?? null;

        return $calcolo['dati']['sedute'][$i] + ['impronta_fotografia' => $impronta];
    }

    /**
     * Il prospetto con, accanto, la seduta di ogni voce (fuori dai dati stampati: l'impronta del prospetto resta quella di prima).
     *
     * @return array{dati: array<string, mixed>, sedute_id: list<int>, impronte_per_seduta: list<array{seduta_id: int, impronta: string}>}
     */
    private function calcola(Assemblea $assemblea): array
    {
        $assemblea->loadMissing('condominio');
        $verbale = $this->lettura->perIlVerbale($assemblea);
        $sedute = $assemblea->sedute()->get()->keyBy('id');
        $punti = collect($verbale['punti'])->keyBy('punto_id');
        $avviso = EmissioneDelleConvocazioni::avviso($assemblea, ['nome' => '', 'indirizzo' => '', 'unita' => [], 'punti' => null]);
        $quando = array_values(array_map(fn ($s) => (string) $s['quando'], (array) $avviso['sedute']));
        $deleghe = DelegaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('stato', StatoDelega::VALIDA->value)->orderBy('id')->get();
        $deserte = $this->oreDelleDeserte($assemblea);

        $perSeduta = [];
        $sedutaDi = [];
        $impronte = [];
        $improntaDi = [];
        $votati = [];
        foreach (array_values($verbale['sedute']) as $i => $s) {
            /** @var SedutaDellAssemblea $seduta */
            $seduta = $sedute[$s['id']];
            if ($s['aperta_il'] === null && $s['esito'] === null) {
                continue;
            }
            $fotografia = (array) ($seduta->fotografia ?? []);
            $votazioni = [];
            foreach ($verbale['punti'] as $p) {
                foreach ($p['votazioni'] as $v) {
                    if ((int) $v['seduta_id'] === (int) $s['id']) {
                        $votazioni[] = $this->votazione($p, $v, $fotografia);
                        $votati[(int) $p['punto_id']] = true;
                    }
                }
            }
            $perSeduta[] = [
                'nome' => self::nomeDellaSeduta($s),
                'quando' => $quando[$i] ?? self::giorno($s['giorno'] . ' ' . $s['ora']),
                'stato' => $this->statoDellaSeduta($s, $deserte),
                'presidente' => $s['presidente']['nome'] ?? null,
                'segretario' => $s['segretario']['nome'] ?? null,
                'scelta' => self::sceltaDellApertura((array) ($s['apertura'] ?? []), $s['aperta_il']),
                'deleghe_rifiutate' => array_values((array) ($s['apertura']['deleghe_rifiutate'] ?? [])),
                'dal_foglio' => (bool) $s['dal_foglio'],
                'costituzione' => $fotografia === [] ? null : ContiDellaSala::condomini((int) $fotografia['costituzione']['totale_teste']) . ' · '
                    . ContiDellaSala::millesimi((int) $fotografia['costituzione']['totale_valore']) . ' millesimi',
                'presenze' => $this->presenze((array) $s['presenze'], $deleghe),
                'votazioni' => $votazioni,
                'dichiarazioni' => array_values(array_map(fn ($d) => ['chi' => (string) $d['nome'], 'testo' => (string) $d['testo'],
                    'punto' => $d['punto_id'] !== null && isset($punti[$d['punto_id']]) ? 'al punto ' . $punti[$d['punto_id']]['numero'] : 'sull\'assemblea'],
                    (array) $s['dichiarazioni'])),
            ];
            $sedutaDi[] = (int) $s['id'];
            if (filled($s['impronta_fotografia'])) {
                $impronte[] = ['seduta' => self::nomeDellaSeduta($s) . ', ' . ($quando[$i] ?? ''), 'impronta' => (string) $s['impronta_fotografia']];
                $improntaDi[] = ['seduta_id' => (int) $s['id'], 'impronta' => (string) $s['impronta_fotografia']];
            }
        }

        $senzaEsito = [];
        foreach ($verbale['punti'] as $p) {
            if (! isset($votati[(int) $p['punto_id']])) {
                $esito = collect($p['delibere'])->last()['esito'] ?? null;
                $senzaEsito[] = ['punto' => $p['numero'] . '. ' . $p['testo'], 'esito' => $esito !== null ? (string) EsitoDelibera::from($esito)->parola() : 'Senza esito'];
            }
        }

        $dati = [
            'assemblea' => 'Assemblea ' . mb_strtolower($assemblea->nome()),
            'titolo' => TestiLettere::condominioConNome((string) $assemblea->condominio->nome),
            'sedute' => $perSeduta,
            'senza_esito_in_sala' => $senzaEsito,
            'impronte' => $impronte,
        ];

        $dati += ['impronta' => hash('sha256', (string) json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];

        return ['dati' => $dati, 'sedute_id' => $sedutaDi, 'impronte_per_seduta' => $improntaDi];
    }

    /** @param array<string, mixed> $s */
    private static function nomeDellaSeduta(array $s): string
    {
        return (int) $s['numero'] > 1 ? 'Prosecuzione' : ($s['convocazione'] === 'prima' ? 'Prima convocazione' : 'Seconda convocazione');
    }

    /**
     * «deserta, segnata alle 8:40»; «aperta alle 8:05 · deserta, segnata alle 8:40» (dopo l'appello); «aperta alle 18:42 · chiusa alle 20:40»; «aperta alle
     * 18:42 · sospesa alle 21:30»; «aperta alle 18:42 · in corso».
     *
     * @param array<string, mixed> $s
     * @param array<int, string> $deserte
     */
    private function statoDellaSeduta(array $s, array $deserte): string
    {
        if ($s['esito'] === EsitoSeduta::DESERTA->value) {
            // La prima aperta, con l'appello, e poi segnata deserta prima di votare: l'apertura e l'appello restano.
            return ($s['aperta_il'] !== null ? 'aperta alle ' . self::ora($s['aperta_il']) . ' · ' : '') . 'deserta'
                . (isset($deserte[(int) $s['id']]) ? ', segnata alle ' . $deserte[(int) $s['id']] : '');
        }
        $parti = ['aperta alle ' . self::ora($s['aperta_il'])];
        $parti[] = match ($s['esito']) {
            EsitoSeduta::TENUTA->value => 'chiusa alle ' . self::ora($s['chiusa_il']),
            EsitoSeduta::SOSPESA->value => 'sospesa alle ' . self::ora($s['chiusa_il']),
            EsitoSeduta::RINVIATA->value => 'rinviata',
            default => 'in corso',
        };

        return implode(' · ', $parti);
    }

    /** L'ora in cui ogni prima convocazione è stata segnata deserta, dal libro dei gesti. @return array<int, string> */
    private function oreDelleDeserte(Assemblea $assemblea): array
    {
        $ore = [];
        foreach (GestoDellaSala::query()->where('assemblea_id', $assemblea->id)->where('tipo', 'deserta')->where('esito', EsitoDelGesto::APPLICATO->value)
            ->orderBy('id')->get() as $g) {
            $ore[(int) ($g->dati['seduta_id'] ?? 0)] = self::ora($g->segnato_il?->toIso8601ZuluString());
        }

        return $ore;
    }

    /**
     * Le presenze della seduta, nell'ordine di entrata: chi, come, entrata, uscita, e la nota (il motivo del collegamento, il collegamento caduto, chi
     * rappresenta la comproprietà, le deleghe che porta).
     *
     * @param list<array<string, mixed>> $presenze
     * @return list<array{nome: string, come: string, entrata: string, uscita: string, nota: ?string}>
     */
    private function presenze(array $presenze, $deleghe): array
    {
        $righe = [];
        foreach ($presenze as $p) {
            $note = [];
            $porta = $deleghe->filter(fn (DelegaDellAssemblea $d) => PresenzeDellaSala::chiaveDelDelegato($d) === $p['chiave'])->pluck('delegante_nome')->all();
            if ($porta !== []) {
                $note[] = 'per delega di ' . TesteDellaSala::elenco(array_values($porta));
            }
            if (filled($p['rappresenta'] ?? null)) {
                $note[] = 'rappresenta la comproprietà';
            }
            // I contitolari entrati insieme, presenti senza voto (rilievo C53).
            if (filled($p['insieme'] ?? null)) {
                $note[] = 'con ' . TesteDellaSala::elenco(array_map(fn ($x) => (string) $x['nome'], (array) $p['insieme']))
                    . ', in comproprietà, ' . (count((array) $p['insieme']) === 1 ? 'presente senza voto' : 'presenti senza voto');
            }
            if (filled($p['motivo_collegamento'] ?? null)) {
                $note[] = 'motivo del collegamento: ' . $p['motivo_collegamento'];
            }
            if ($p['caduto'] ?? false) {
                $note[] = 'collegamento caduto';
            }
            $righe[] = ['nome' => (string) $p['nome'], 'come' => ComePresente::tryFrom((string) $p['come'])?->parola() ?? (string) $p['come'],
                'entrata' => self::ora($p['entrata_il']), 'uscita' => $p['uscita_il'] === null ? '—' : self::ora($p['uscita_il']),
                'nota' => $note === [] ? null : implode('; ', $note)];
        }

        return $righe;
    }

    /**
     * Una votazione come la scrive il prospetto.
     *
     * @param array<string, mixed> $punto
     * @param array<string, mixed> $v
     * @param array<string, mixed> $fotografia
     * @return array<string, mixed>
     */
    private function votazione(array $punto, array $v, array $fotografia): array
    {
        $titolo = $punto['numero'] . '. ' . $punto['testo'] . ($v['oggetto'] !== null ? ' (il ' . $v['oggetto'] . ')' : '');
        if ($v['maggioranza'] === Maggioranza::SENZA_VOTO->value) {
            return ['titolo' => $titolo, 'trattato' => 'Trattato alle ' . self::ora($v['votata_il']) . ', senza voto.', 'gruppi' => [], 'righe' => []];
        }
        $i = (array) ($v['intervenuti'] ?? []);
        $c = (array) ($v['conteggi'] ?? []);
        $gruppi = [];
        foreach (['favorevoli' => 'Favorevoli', 'contrari' => 'Contrari', 'astenuti' => 'Astenuti', 'non_votato' => 'Senza voto'] as $chiave => $nome) {
            $g = (array) ($c[$chiave] ?? []);
            if ($chiave === 'non_votato' && (int) ($g['teste'] ?? 0) === 0) {
                continue;
            }
            $gruppi[] = ['nome' => $nome, 'quanti' => (int) ($g['teste'] ?? 0) . ' · ' . ContiDellaSala::millesimi((int) ($g['valore'] ?? 0)),
                'nomi' => implode('; ', array_map(fn ($n) => $n['nome'] . ' ' . ContiDellaSala::millesimi((int) $n['millesimi']), (array) ($g['nomi'] ?? [])))];
        }
        $righe = [];
        $maggioranza = Maggioranza::tryFrom((string) $v['maggioranza'])?->parola();
        $righe[] = 'Votato alle ' . self::ora($v['votata_il']) . ($maggioranza !== null ? ' · ' . $maggioranza : '') . ' · intervenuti ' . (int) ($i['numero'] ?? 0)
            . ' su ' . (int) ($i['totale_teste'] ?? 0) . ', ' . ContiDellaSala::millesimi((int) ($i['valore'] ?? 0)) . ' su '
            . ContiDellaSala::millesimi((int) ($i['totale_valore'] ?? 0)) . ' millesimi' . (filled($i['perimetro']['nome'] ?? null) ? ' (' . $i['perimetro']['nome'] . ')' : '');
        foreach ((array) ($v['scelte']['usufruttuari'] ?? []) as $u) {
            $righe[] = (string) $u['frase'] . ' (scelta del presidente, art. 67 c. 7 disp. att. c.c.).';
        }
        $conflitti = array_values(array_filter((array) $v['voti'], fn ($x) => (bool) $x['in_conflitto']));
        foreach ($conflitti as $x) {
            $righe[] = 'In conflitto d\'interessi: ' . $x['nome'] . ' ' . ContiDellaSala::millesimi((int) $x['millesimi']) . (filled($x['nota_conflitto']) ? ', ' . $x['nota_conflitto'] : '') . '.';
        }
        if (filled($c['conflitto'] ?? null)) {
            $righe[] = (string) $c['conflitto'];
        }
        $proposto = $v['esito_proposto'] !== null ? EsitoDelibera::from($v['esito_proposto'])->parola() : 'decide il presidente';
        $righe[] = 'Esito proposto: ' . $proposto . ($v['esito'] !== null ? ' · Esito confermato: ' . EsitoDelibera::from($v['esito'])->parola()
            . ' alle ' . self::ora($v['confermata_il']) : ' · non ancora confermato');
        if (filled($v['motivo'] ?? null)) {
            $righe[] = 'Motivo del presidente: ' . $v['motivo'];
        }
        if (filled($v['nota'] ?? null)) {
            $righe[] = 'Nota del presidente sul voto: ' . $v['nota'];
        }
        if (filled($v['nota_non_votato'] ?? null)) {
            $righe[] = 'Nota del presidente su chi non ha votato: ' . $v['nota_non_votato'];
        }

        return ['titolo' => $titolo, 'trattato' => null, 'gruppi' => $gruppi, 'righe' => $righe];
    }

    /** L'ora di Roma di un istante del server: «18:42». */
    private static function ora(?string $istante): string
    {
        return $istante === null ? '—' : CarbonImmutable::parse($istante)->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i');
    }

    private static function giorno(string $quando): string
    {
        return CarbonImmutable::parse($quando)->format('d/m/Y \a\l\l\e H:i');
    }
}
