<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;
use ZipArchive;

/**
 * Legge il file XLSX che ISTAT pubblica con l'elenco dei Comuni italiani.
 *
 * ## Perché questa classe esiste
 *
 * Fino alla beta.59 la conversione da XLSX al nostro formato era stata fatta **una volta sola, a
 * mano**, e la ricetta viveva in una conversazione. Il changelog prometteva a un amministratore una
 * via d'uscita — «aggiornalo tu con `--da`» — che in pratica non poteva percorrere, perché `--da`
 * accettava solo il nostro JSON già convertito.
 *
 * ## Le due trappole della fonte, misurate prima di scrivere una riga
 *
 * **1. La data della fonte non è il `last-modified`.** L'intestazione HTTP dice quando ISTAT ha
 * caricato il file (26/02/2026); il **nome del foglio** dice a quando l'elenco è aggiornato
 * («CODICI al 21_02_2026»). Cinque giorni di scarto — e il `last-modified` non è nemmeno stabile
 * fra client, perché `curl` e Guzzle ne ricevono due valori diversi a due secondi di distanza.
 *
 * **2. Aprire l'XLSX costa 142 MB**, cioè muore sull'hosting condiviso che è il parco installato di
 * questo prodotto. Ma leggerne **il nome del foglio** non richiede di aprirlo: un `.xlsx` è uno zip
 * e il nome sta in `xl/workbook.xml`. Misurato sul file vero, cinquanta giri dopo cinque di
 * riscaldamento: **0,13 ms e 2 MB**. È la ragione per cui il controllo periodico è gratis e la
 * conversione no — e per cui le due cose sono due metodi separati.
 *
 * ## Le colonne si cercano per intestazione, mai per posizione
 *
 * Allo stesso indirizzo ISTAT pubblica un CSV e un XLSX che **non sono lo stesso elenco**: 26 colonne
 * contro 27, e il codice catastale sta nella colonna **T** del CSV e nella **U** dell'XLSX. Un lettore scritto sulle
 * posizioni legge la colonna sbagliata **senza accorgersene**, perché un valore lo trova comunque.
 */
final class LettoreElencoIstat
{
    /** L'indirizzo da cui si scarica, scritto qui una volta sola. */
    public const URL = 'https://www.istat.it/storage/codici-unita-amministrative/Elenco-comuni-italiani.xlsx';

    /**
     * Le intestazioni che servono, con la chiave che avranno da noi.
     *
     * @var array<string, string>
     */
    private const COLONNE = [
        'nome'      => 'Denominazione in italiano',
        'altra'     => 'Denominazione altra lingua',
        'regione'   => 'Denominazione Regione',
        'provincia' => 'Denominazione dell\'Unità territoriale sovracomunale',
        'sigla'     => 'Sigla automobilistica',
        'codice'    => 'Codice Catastale',
    ];

    /**
     * La data a cui l'elenco è aggiornato, dichiarata da ISTAT nel nome del foglio.
     *
     * Non apre il foglio: legge lo zip. Vedi il docblock della classe per il perché e per la misura.
     */
    public static function dataDichiarata(string $percorso): string
    {
        return self::dataDa(self::nomeFoglio($percorso));
    }

    /** Il nome del foglio, letto dallo zip senza aprire il documento. */
    public static function nomeFoglio(string $percorso): string
    {
        if (! is_file($percorso) || ! is_readable($percorso)) {
            throw new RuntimeException("File non leggibile: {$percorso}");
        }

        $zip = new ZipArchive();

        if ($zip->open($percorso) !== true) {
            throw new RuntimeException("Non è un file XLSX: {$percorso}");
        }

        $xml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($xml === false || ! preg_match('/<sheet[^>]*name="([^"]+)"/', $xml, $m)) {
            throw new RuntimeException('Il file non contiene nessun foglio leggibile.');
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Dalla forma «CODICI al 21_02_2026» alla data ISO.
     *
     * Se il nome non porta una data si solleva invece di indovinare: una data sbagliata scritta
     * accanto all'elenco è peggio di un elenco senza data, perché chi la legge si fida.
     */
    public static function dataDa(string $nomeFoglio): string
    {
        if (! preg_match('/(\d{2})[_\-\/.](\d{2})[_\-\/.](\d{4})/', $nomeFoglio, $m)) {
            throw new RuntimeException(
                "Il nome del foglio non porta una data: «{$nomeFoglio}». ".
                'ISTAT la scrive nella forma «CODICI al 21_02_2026»: se ha cambiato convenzione, '.
                'va guardato a mano invece che indovinato.'
            );
        }

        return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
    }

    /**
     * Converte il foglio ISTAT nella forma che `kondomanager:aggiorna-comuni` sa leggere.
     *
     * ⚠️ **Questa è la parte cara**: legge il foglio per intero. Con il filtro sulle colonne il costo
     * scende sotto la metà dei 142 MB del caricamento grezzo, ma resta un'operazione da fare su una
     * macchina che ha memoria — la nostra, o quella di un amministratore che ha scelto di lanciarla.
     * **Non gira mai da sola**: nessuna installazione la esegue senza che qualcuno lo chieda.
     *
     * @return array{fonte: string, url: string, foglio: string, aggiornato_al: string, comuni: list<array<string, string|null>>}
     */
    public static function converti(string $percorso): array
    {
        $foglio = self::nomeFoglio($percorso);
        $data = self::dataDa($foglio);

        // ⚠️ **Due passate, e la prima serve a non tradire la promessa della classe.** La prima
        // stesura filtrava le colonne per **lettera fissa** (`G`, `H`, `K`, `L`, `O`, `U`) mentre il
        // docblock qui sopra dichiara che le colonne si cercano per intestazione: bastava una
        // colonna in più in testa al foglio — cosa che ISTAT ha già fatto una volta, visto che
        // l'XLSX ne ha una che il CSV non ha — e la conversione moriva con «il foglio non contiene
        // nessun comune», mandando a cercare nel file invece che nelle sei lettere.
        //
        // Adesso la prima passata legge **la sola riga 1** (costa quanto niente) e trova gli indici;
        // la seconda filtra su quelli.
        $indici = self::indiciDelleColonne(self::intestazioni($percorso));

        $lettere = array_map(
            fn (int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1),
            $indici
        );

        $lettore = IOFactory::createReader('Xlsx');
        $lettore->setReadDataOnly(true);
        // Il filtro tiene solo le colonne che servono: senza, si portano in memoria anche i codici
        // NUTS e le sei numerazioni storiche delle province, che non guarda nessuno.
        $lettore->setReadFilter(new class(array_values($lettere)) implements IReadFilter
        {
            public function __construct(private array $lettere) {}

            public function readCell(string $column, int $row, string $worksheetName = ''): bool
            {
                return $row === 1 || in_array($column, $this->lettere, true);
            }
        });

        $libro = $lettore->load($percorso);
        $righe = $libro->getActiveSheet()->toArray(null, false, false, false);
        $libro->disconnectWorksheets();
        unset($libro);

        $comuni = [];

        foreach (array_slice($righe, 1) as $riga) {
            $codice = trim((string) ($riga[$indici['codice']] ?? ''));

            if ($codice === '') {
                continue;
            }

            $nome = trim((string) ($riga[$indici['nome']] ?? ''));
            $altra = trim((string) ($riga[$indici['altra']] ?? ''));

            $comuni[] = [
                'codice'    => $codice,
                'nome'      => $nome,
                // Nulla quando ripete il nome: ISTAT la valorizza anche sui comuni non bilingui.
                'altra'     => ($altra !== '' && $altra !== $nome) ? $altra : null,
                'sigla'     => trim((string) ($riga[$indici['sigla']] ?? '')),
                'provincia' => trim((string) ($riga[$indici['provincia']] ?? '')),
                'regione'   => trim((string) ($riga[$indici['regione']] ?? '')),
            ];
        }

        if ($comuni === []) {
            throw new RuntimeException('Il foglio non contiene nessun comune.');
        }

        usort($comuni, fn (array $a, array $b) => strcmp($a['codice'], $b['codice']));

        return [
            'fonte'         => 'ISTAT — Codici statistici delle unità amministrative territoriali',
            'url'           => self::URL,
            'foglio'        => $foglio,
            'aggiornato_al' => $data,
            'comuni'        => $comuni,
        ];
    }

    /**
     * Le intestazioni del foglio, leggendo **solo la prima riga**.
     *
     * @return list<string|null>
     */
    private static function intestazioni(string $percorso): array
    {
        $lettore = IOFactory::createReader('Xlsx');
        $lettore->setReadDataOnly(true);
        $lettore->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $column, int $row, string $worksheetName = ''): bool
            {
                return $row === 1;
            }
        });

        $libro = $lettore->load($percorso);
        $righe = $libro->getActiveSheet()->toArray(null, false, false, false);
        $libro->disconnectWorksheets();
        unset($libro);

        return $righe[0] ?? [];
    }

    /**
     * Dalle intestazioni della prima riga agli indici delle colonne che ci servono.
     *
     * @param  list<string|null>  $intestazioni
     * @return array<string, int>
     */
    private static function indiciDelleColonne(array $intestazioni): array
    {
        $normalizza = fn (?string $v) => trim(preg_replace('/\s+/u', ' ', (string) $v));

        $indici = [];

        foreach (self::COLONNE as $chiave => $cercata) {
            foreach ($intestazioni as $i => $intestazione) {
                if (str_contains($normalizza($intestazione), $cercata)) {
                    $indici[$chiave] = $i;
                    break;
                }
            }

            if (! isset($indici[$chiave])) {
                throw new RuntimeException(
                    "Nel foglio manca la colonna «{$cercata}». ".
                    'Le colonne si cercano per intestazione e non per posizione, perché ISTAT le '.
                    'sposta: se ha cambiato anche i nomi, la conversione va rivista a mano.'
                );
            }
        }

        return $indici;
    }
}
