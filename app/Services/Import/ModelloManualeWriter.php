<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Il modello vuoto da compilare a mano — un file solo, quattro fogli più la copertina.
 *
 * Serve a chi dal vecchio gestionale non ha un export usabile: invece di rinunciare
 * all'importazione, compila questi elenchi e li ricarica dalla stessa schermata.
 *
 * ## Un file solo, e non cinque
 *
 * Deciso da Vincenzo: un file è **una cosa da scaricare e una da rimandare indietro**; con cinque
 * file l'amministratore ne rispedisce tre. Il vincolo che lo rende non banale è misurato:
 * `ImportUploadService::riconosci()` scorre tutti i fogli ma tiene **solo il migliore**, e
 * `import_files.report_type` è una colonna sola — un file ha **un** tipo. La strada non è quindi
 * «cinque fogli riconosciuti come cinque tipi» ma un tipo solo, con un parser che li legge tutti.
 *
 * ## Cosa NON c'è, ed è una scelta
 *
 * Non c'è il foglio dei capitoli di spesa. Nel modello entra ciò che il prodotto **non può
 * ricostruire da solo** — chi abita dove, chi possiede cosa, con quali millesimi, con quale
 * posizione aperta — mentre il preventivo è precisamente quello che l'amministratore sta per
 * decidere ex novo, e per cui esiste già una schermata sua. Vedi `modelli_import_manuale.md` §1-bis.
 *
 * ## Le righe di spiegazione, e perché stanno in testa
 *
 * ⚠️ **Ogni foglio porta due righe gialle sopra l'intestazione, e la loro posizione è vincolata da
 * due finestre diverse.** `HeaderDetector` cerca l'intestazione entro le prime dodici righe, quindi
 * lì c'è margine; ma `ReportRecognizer` cerca il **titolo** entro le prime tre, e il titolo è
 * l'ancora con cui questo file si distingue da un export di Danea. Per questo il titolo resta in
 * riga 1 della copertina e le spiegazioni cominciano dalla 2.
 *
 * La spiegazione che conta di più è ripetuta su tutti e tre i fogli che la usano: **la chiave
 * dell'unità va scritta identica**. È ciò che tiene insieme i quattro elenchi, e se non combacia
 * ogni riga fallisce la risoluzione.
 */
final class ModelloManualeWriter
{
    /**
     * Il titolo in riga 1 della copertina: è l'ancora del riconoscitore.
     *
     * Va tenuto in sincronia con `ReportType::ModelloManuale::titoloBanner()`. Un test lo
     * verifica, perché due stringhe uguali scritte in due posti divergono sempre.
     */
    public const TITOLO = 'Modello di importazione Kondomanager';

    /** La riga dell'intestazione su ogni foglio: titolo, due righe di guida, una vuota. */
    private const RIGA_INTESTAZIONE = 5;

    public function scriviSu(string $percorso): void
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $this->copertina($libro->createSheet());
        $this->unita($libro->createSheet());
        $this->persone($libro->createSheet());
        $this->tabelle($libro->createSheet());
        $this->saldi($libro->createSheet());

        $libro->setActiveSheetIndex(0);

        (new Xlsx($libro))->save($percorso);
    }

    private function copertina(Worksheet $f): void
    {
        $f->setTitle('0 copertina');

        $this->testa(
            $f,
            self::TITOLO,
            'Compila i cinque fogli di questo file e ricaricalo così com\'è. Niente entra in '
            .'Kondomanager finché non confermi: le prime schermate leggono soltanto.',
            'Comincia da qui: senza il condominio e le date dell\'esercizio gli altri fogli non '
            .'hanno dove atterrare.',
            'C',
        );

        $this->intestazione($f, ['campo', 'valore', 'a cosa serve'], 'C');

        $righe = [
            ['condominio', '', 'Obbligatorio. Il nome con cui lo conosci tu.'],
            ['codice_fiscale', '', 'Facoltativo, ma senza, due condomìni con lo stesso nome non si distinguono.'],
            ['indirizzo', '', 'Obbligatorio se il condominio non è già in Kondomanager: è dove partono le convocazioni.'],
            ['esercizio', '', 'L\'etichetta dell\'anno di gestione, per esempio 2024/2025.'],
            ['data_inizio', '', 'gg/mm/aaaa. Serve scriverla: da «2024/2025» non si capisce se parte a gennaio o a novembre.'],
            ['data_fine', '', 'gg/mm/aaaa.'],
        ];

        $this->righe($f, $righe, esempio: false);
        $this->larghezze($f, ['A' => 20, 'B' => 32, 'C' => 78]);
    }

    private function unita(Worksheet $f): void
    {
        $f->setTitle('1 unita');

        $this->testa(
            $f,
            'Foglio 1 · le unità immobiliari',
            'Una riga per ogni unità: appartamenti, box, cantine, negozi. La colonna «unita» è la '
            .'CHIAVE: scegli tu come chiamarla (B1/1, int. 3, A-2…), ma poi la ripeterai IDENTICA '
            .'negli altri tre fogli.',
            'Identica vuol dire proprio identica: «B1/1» e «B1/ 1» per noi sono due unità diverse. '
            .'Il modo più sicuro è scriverla qui e copiarla di là.',
            'F',
        );

        $this->intestazione($f, ['unita', 'palazzina', 'scala', 'interno', 'piano', 'tipo'], 'F');

        $this->righe($f, [
            ['B1/1', '1', 'A', '1', 'T', 'appartamento'],
            ['B1/2', '1', 'A', '2', '1', 'appartamento'],
        ]);

        $this->chiaveTestuale($f);
        $this->larghezze($f, ['A' => 18, 'B' => 12, 'C' => 10, 'D' => 10, 'E' => 10, 'F' => 18]);
    }

    private function persone(Worksheet $f): void
    {
        $f->setTitle('2 persone');

        $this->testa(
            $f,
            'Foglio 2 · le persone e chi possiede cosa',
            'Una riga per ogni TITOLARE, non per ogni unità: se un appartamento è di due '
            .'comproprietari, scrivi due righe con la stessa «unita». Nella colonna «unita» va la '
            .'stessa identica scritta del foglio «1 unita».',
            'L\'indirizzo è obbligatorio: Kondomanager non registra una persona senza. Se una '
            .'persona possiede tre unità, la scrivi tre volte — la riconosciamo dal codice fiscale, '
            .'o dal nome se non ce l\'ha.',
            'H',
        );

        $this->intestazione($f, ['unita', 'nome', 'codice_fiscale', 'indirizzo', 'ruolo', 'quota_pct', 'email', 'telefono'], 'H');

        $this->righe($f, [
            ['B1/1', 'ROSSI MARIO', 'RSSMRA80A01H501U', 'Via Roma 1, Roma', 'proprietario', 100, '', ''],
            ['B1/2', 'BIANCHI ANNA', '', 'Via Roma 2, Roma', 'proprietario', 60, '', ''],
            ['B1/2', 'VERDI LUCA', '', 'Via Roma 2, Roma', 'proprietario', 40, '', ''],
        ], nota: 'B1/2 compare due volte: due comproprietari al 60% e 40%. Ruoli ammessi: '
            .'proprietario, inquilino, usufruttuario, nuda_proprietario.');

        $this->chiaveTestuale($f);
        $this->larghezze($f, ['A' => 18, 'B' => 26, 'C' => 20, 'D' => 28, 'E' => 18, 'F' => 12, 'G' => 22, 'H' => 16]);
    }

    private function tabelle(Worksheet $f): void
    {
        $f->setTitle('3 tabelle');

        $this->testa(
            $f,
            'Foglio 3 · le tabelle millesimali',
            'Una colonna per ogni tabella: aggiungine quante ne servono a destra, e scrivi il nome '
            .'della tabella sia nella riga grigia «# tabella» sia nella riga nera di intestazione. '
            .'Nella colonna «unita» va la stessa identica scritta del foglio «1 unita».',
            'Cella vuota = quell\'unità non partecipa a quella tabella, ed è diverso da zero. In '
            .'fondo, la riga «# TOTALE DI CONTROLLO» serve a noi per accorgerci se una riga è '
            .'andata persa mentre copiavi.',
            'D',
        );

        // ⚠️ Il nome delle tabelle compare due volte, e non è ridondanza: la riga grigia sopravvive
        // anche se l'amministratore rinomina la riga nera, ed è l'unico posto in cui possiamo
        // scrivere metadati su colonne che non conosciamo in anticipo.
        $r = self::RIGA_INTESTAZIONE;
        $f->fromArray(['# tabella', 'PROPRIETA GENERALE', 'SCALE'], null, 'A'.$r);
        $f->getStyle('A'.$r.':D'.$r)->applyFromArray($this->stileMeta());

        $this->intestazione($f, ['unita', 'PROPRIETA GENERALE', 'SCALE'], 'D', $r + 1);

        $f->fromArray([['B1/1', 450.50, 500], ['B1/2', 549.50, 500]], null, 'A'.($r + 2));
        $f->getStyle('A'.($r + 2).':D'.($r + 3))->applyFromArray($this->stileEsempio());

        // ⚠️ **Questo foglio era l'unico senza la riga «cancellale».** Le sue due righe di
        // esempio non passano da `righe()`, che è il metodo che la scrive, quindi non ce l'aveva
        // — ed è anche l'unico segnale che `daSaltare()` riconosce. Chi cancellava gli esempi
        // degli altri tre fogli, qui non riceveva nessuna istruzione e li lasciava: entravano in
        // archivio **due tabelle millesimali inventate da noi**, con zero rilievi.
        $f->setCellValue('A'.($r + 4), '↑ righe di esempio: cancellale e metti le tue.');
        $f->getStyle('A'.($r + 4))->applyFromArray($this->stileEsempio());

        $f->fromArray(['# TOTALE DI CONTROLLO', 1000, 1000], null, 'A'.($r + 6));
        $f->getStyle('A'.($r + 6).':D'.($r + 6))->applyFromArray($this->stileMeta());

        // ⚠️ Solo la colonna A: le colonne dei millesimi devono restare numeriche.
        $this->chiaveTestuale($f);
        $this->larghezze($f, ['A' => 24, 'B' => 22, 'C' => 16, 'D' => 16]);
    }

    private function saldi(Worksheet $f): void
    {
        $f->setTitle('4 saldi');

        $this->testa(
            $f,
            'Foglio 4 · i saldi di apertura',
            'Le posizioni aperte con cui il condominio arriva in Kondomanager. Nella colonna '
            .'«unita» va la stessa identica scritta del foglio «1 unita». Il segno conta: POSITIVO '
            .'= deve al condominio, NEGATIVO = è in credito.',
            'Lascia «persona» vuota quando il debito segue la casa e non chi ci abitava (art. 63 '
            .'disp. att. c.c.). Più righe per la stessa unità vanno bene se sono di persone '
            .'diverse; due righe della STESSA persona le sommo in una posizione sola, unendo le '
            .'causali, perché in Kondomanager ognuno ha una posizione per esercizio.',
            'D',
        );

        $this->intestazione($f, ['unita', 'persona', 'importo', 'causale'], 'D');

        $this->righe($f, [
            ['B1/1', 'ROSSI MARIO', 120.50, 'conguaglio 2024/2025'],
            ['B1/2', '', -45.00, 'credito a favore dell\'unità'],
        ], nota: 'la seconda riga non ha persona: il credito resta sull\'unità.');

        $this->chiaveTestuale($f);
        $this->larghezze($f, ['A' => 18, 'B' => 26, 'C' => 14, 'D' => 36]);
    }

    /** Titolo in riga 1 e due righe di guida, gialle e a capo automatico. */
    private function testa(Worksheet $f, string $titolo, string $guida1, string $guida2, string $ultimaColonna): void
    {
        $f->setCellValue('A1', $titolo);
        $f->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $f->setCellValue('A2', $guida1);
        $f->setCellValue('A3', $guida2);
        $f->mergeCells('A2:'.$ultimaColonna.'2');
        $f->mergeCells('A3:'.$ultimaColonna.'3');
        $f->getStyle('A2:'.$ultimaColonna.'3')->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '374151']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF9C3']],
        ]);
        $f->getStyle('A2:'.$ultimaColonna.'3')->getAlignment()
            ->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $f->getRowDimension(2)->setRowHeight(30);
        $f->getRowDimension(3)->setRowHeight(30);
    }

    private function intestazione(Worksheet $f, array $colonne, string $ultima, ?int $riga = null): void
    {
        $riga ??= self::RIGA_INTESTAZIONE;

        $f->fromArray($colonne, null, 'A'.$riga);
        $f->getStyle('A'.$riga.':'.$ultima.$riga)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
        ]);
    }

    private function righe(Worksheet $f, array $righe, bool $esempio = true, ?string $nota = null): void
    {
        $prima = self::RIGA_INTESTAZIONE + 1;
        $f->fromArray($righe, null, 'A'.$prima);

        if (! $esempio) {
            return;
        }

        $ultima = $prima + count($righe) - 1;
        $f->getStyle('A'.$prima.':Z'.$ultima)->applyFromArray($this->stileEsempio());

        $f->setCellValue('A'.($ultima + 1), '↑ '.($nota ?? 'righe di esempio: cancellale e metti le tue.'));
        $f->getStyle('A'.($ultima + 1))->applyFromArray($this->stileEsempio());
    }

    /**
     * La colonna A resta **testo**, su ogni foglio che porta la sigla dell'unità.
     *
     * ⚠️ Stava solo sul foglio 1, ed è bastato a produrre un difetto intero: «016» scritto lì
     * restava «016», ma la stessa sigla battuta nei fogli 2, 3 e 4 — colonna in formato generale —
     * veniva salvata da Excel come il **numero 16**. Il parser confrontava poi «016» con «16» e
     * non li riconosceva: per ogni unità con lo zero davanti — la numerazione più comune di chi
     * arriva da Danea — sparivano titolari, millesimi e saldi, con un messaggio che diceva
     * «l'unità 16 non compare» su un foglio dove l'amministratore aveva scritto «016».
     *
     * La strada alternativa — togliere gli zeri iniziali nel confronto — sarebbe stata peggiore:
     * in un condominio «016» e «16» possono essere due unità diverse.
     */
    private function chiaveTestuale(Worksheet $f): void
    {
        $f->getStyle('A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    }

    private function larghezze(Worksheet $f, array $mappa): void
    {
        foreach ($mappa as $colonna => $larghezza) {
            $f->getColumnDimension($colonna)->setWidth($larghezza);
        }
    }

    private function stileMeta(): array
    {
        return [
            'font' => ['bold' => true, 'color' => ['rgb' => '6B7280']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
        ];
    }

    private function stileEsempio(): array
    {
        return ['font' => ['italic' => true, 'color' => ['rgb' => '9CA3AF']]];
    }
}
