{{--
    Facsimile del modello F24 Ordinario — «Modello di pagamento unificato».

    Ricalca il modulo pubblicato dall'Agenzia delle Entrate (MOD. F24 – 2013 – EURO): stesse
    sezioni, stesso ordine, stessi nomi dei campi. Non riproduce il logo dell'Agenzia, che è un
    segno distintivo altrui e non è nostro da stampare: quel che rende il foglio utilizzabile
    è la struttura dei campi, non l'intestazione grafica.

    Compiliamo la sola SEZIONE ERARIO. Le altre restano come sul modulo prestampato — vuote e
    presenti: toglierle farebbe un foglio più pulito e un modello diverso da quello che chi lo
    riceve si aspetta di avere in mano.

    La sezione «Estremi del versamento» porta scritto sopra, sul modulo vero, che va compilata
    a cura di banca/poste/agente della riscossione: resta vuota anche qui.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Modello F24 — {{ $delega->protocollo ?: 'delega '.$delega->id }}</title>
    <style>
        @page { margin: 6mm 7mm; }

        body {
            font-family: dejavusans, sans-serif;
            font-size: 6pt;
            color: #1c2b36;
        }

        table { border-collapse: collapse; width: 100%; }
        td { vertical-align: top; }

        /*
            Le fasce azzurre che dividono il modulo, con il titolo di sezione in negativo.

            Le misure di questo foglio sono strette per necessità, non per gusto: il modulo
            ministeriale ha nove fasce, trentadue righe di caselle e quattro sezioni che non
            compiliamo ma che ci sono comunque. Ogni decimo di millimetro tolto qui è ciò che
            tiene una copia su una pagina sola — e un F24 stampato su due fogli non è un F24.
        */
        .fascia {
            background-color: #3aa9d6;
            color: #ffffff;
            font-weight: bold;
            font-size: 6.5pt;
            padding: 1mm 1.5mm;
            letter-spacing: 0.3pt;
        }

        /* L'etichetta stampata sopra o accanto a una casella: piccola, mai in grassetto. */
        .eti { font-size: 4.6pt; color: #24506b; padding: 0 0 0.2mm 0.4mm; }
        .eti-forte { font-size: 6.2pt; font-weight: bold; color: #14364a; }

        /* Le caselle da riempire: filetto azzurro sottile, come sul modulo. */
        .box {
            border: 0.2mm solid #7cc6e2;
            height: 4mm;
            padding: 0.4mm 1mm;
            font-size: 7.5pt;
            color: #0f2733;
        }
        .box-alto { height: 5mm; }

        /* La griglia a pettine del codice fiscale: una cella per carattere. */
        .pettine td {
            border: 0.2mm solid #7cc6e2;
            width: 4.6mm;
            height: 4mm;
            text-align: center;
            font-size: 8pt;
            font-family: dejavusansmono, monospace;
        }

        .imp-euro { text-align: right; font-size: 7.5pt; font-family: dejavusansmono, monospace; }
        .imp-cent { text-align: left; font-size: 7.5pt; font-family: dejavusansmono, monospace; }
        .virgola { text-align: center; font-weight: bold; font-size: 8pt; padding: 0; width: 2mm; }

        .totale { font-weight: bold; font-size: 7pt; text-align: right; padding-right: 1.5mm; }
        .saldo-eti { font-weight: bold; font-size: 6.5pt; text-align: right; padding-right: 1.5mm; }

        .sp { height: 1.2mm; }
        .piede {
            text-align: center;
            font-size: 6pt;
            font-weight: bold;
            color: #14364a;
            padding-top: 1.5mm;
        }
    </style>
</head>
<body>

@foreach($copie as $indiceCopia => $copia)
    @if($indiceCopia > 0)<pagebreak />@endif

    {{-- ── Testata ──────────────────────────────────────────────────────── --}}
    <table>
        <tr>
            <td style="width: 38%; padding-top: 2mm;">
                <div style="font-size: 11pt; font-weight: bold; color: #14364a; line-height: 1.15;">
                    MODELLO DI PAGAMENTO<br>UNIFICATO
                </div>
            </td>
            <td style="width: 47%;">
                <table>
                    <tr>
                        <td style="width: 34%; text-align: right; padding-right: 1.5mm;" class="eti">DELEGA IRREVOCABILE A:</td>
                        <td class="box box-alto"></td>
                    </tr>
                    <tr><td colspan="2" class="sp"></td></tr>
                    <tr>
                        <td style="text-align: right; padding-right: 1.5mm;" class="eti">AGENZIA</td>
                        <td style="padding: 0;">
                            <table>
                                <tr>
                                    <td class="box" style="width: 62%;"></td>
                                    <td style="width: 4%;"></td>
                                    <td class="box" style="width: 34%;"></td>
                                </tr>
                                <tr>
                                    <td></td><td></td>
                                    <td class="eti" style="text-align: center;">PROV.</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="eti" style="text-align: center;">PER L'ACCREDITO ALLA TESORERIA COMPETENTE</td>
                    </tr>
                </table>
            </td>
            <td style="width: 15%; text-align: right;">
                <table style="width: 100%;">
                    <tr>
                        <td style="border: 0.3mm solid #3aa9d6; text-align: center; padding: 1mm 0;">
                            <span style="font-size: 6pt;">Mod. </span><span style="font-size: 10pt; font-weight: bold; color: #14364a;">F24</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Contribuente ─────────────────────────────────────────────────── --}}
    <div class="fascia" style="margin-top: 1mm;">CONTRIBUENTE</div>

    <table style="margin-top: 0.8mm;">
        <tr>
            <td style="width: 21%; padding-top: 1mm;" class="eti-forte">CODICE FISCALE</td>
            <td style="width: 52%;">
                <table class="pettine">
                    <tr>
                        @foreach($quadro['contribuente']['codice_fiscale'] as $carattere)
                            <td>{{ $carattere }}</td>
                        @endforeach
                    </tr>
                </table>
            </td>
            <td style="width: 27%;">
                <table>
                    <tr>
                        <td class="eti" style="width: 78%; text-align: right; padding-right: 1.5mm;">
                            barrare in caso di anno d'imposta<br>non coincidente con anno solare
                        </td>
                        <td class="box" style="width: 22%;"></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 0.8mm;">
        <tr>
            <td style="width: 21%; padding-top: 1.6mm;" class="eti-forte">DATI ANAGRAFICI</td>
            <td style="width: 57%;">
                <div class="eti">cognome, denominazione o ragione sociale</div>
                <table><tr><td class="box">{{ $quadro['contribuente']['denominazione'] }}</td></tr></table>
            </td>
            <td style="width: 22%;">
                <div class="eti">nome</div>
                <table><tr><td class="box"></td></tr></table>
            </td>
        </tr>
    </table>

    {{--
        Data e comune di nascita, sesso: campi di una persona fisica. Un condominio non li ha,
        e restano vuoti per costruzione — non per un dato che ci manca.
    --}}
    <table style="margin-top: 0.8mm;">
        <tr>
            <td style="width: 21%;"></td>
            <td style="width: 22%;">
                <div class="eti">data di nascita</div>
                <table>
                    <tr>
                        <td class="box" style="width: 33%;"></td>
                        <td class="box" style="width: 33%;"></td>
                        <td class="box" style="width: 34%;"></td>
                    </tr>
                    <tr>
                        <td class="eti" style="text-align: center;">giorno</td>
                        <td class="eti" style="text-align: center;">mese</td>
                        <td class="eti" style="text-align: center;">anno</td>
                    </tr>
                </table>
            </td>
            <td style="width: 7%;">
                <div class="eti">sesso (M o F)</div>
                <table><tr><td class="box"></td></tr></table>
            </td>
            <td style="width: 42%;">
                <div class="eti">comune (o Stato estero) di nascita</div>
                <table><tr><td class="box"></td></tr></table>
            </td>
            <td style="width: 8%;">
                <div class="eti">prov.</div>
                <table><tr><td class="box"></td></tr></table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 0.8mm;">
        <tr>
            <td style="width: 21%; padding-top: 1.6mm;" class="eti-forte">DOMICILIO FISCALE</td>
            <td style="width: 34%;">
                <div class="eti">comune</div>
                <table><tr><td class="box">{{ $quadro['contribuente']['comune'] }}</td></tr></table>
            </td>
            <td style="width: 7%;">
                <div class="eti">prov.</div>
                <table><tr><td class="box" style="text-align: center;">{{ $quadro['contribuente']['provincia'] }}</td></tr></table>
            </td>
            <td style="width: 38%;">
                <div class="eti">via e numero civico</div>
                <table><tr><td class="box">{{ $quadro['contribuente']['via'] }}</td></tr></table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 0.8mm;">
        <tr>
            <td class="eti" style="width: 21%; padding-top: 1mm; font-size: 5.2pt;">
                <strong>CODICE FISCALE</strong> del coobbligato, erede,<br>genitore, tutore o curatore fallimentare
            </td>
            <td style="width: 52%;">
                <table class="pettine">
                    <tr>
                        @for($i = 0; $i < $casellePettine; $i++)<td></td>@endfor
                    </tr>
                </table>
            </td>
            <td style="width: 27%;">
                <table>
                    <tr>
                        <td class="eti" style="width: 74%; text-align: right; padding-right: 1.5mm;">codice identificativo</td>
                        <td style="padding: 0; width: 26%;">
                            <table class="pettine"><tr><td></td><td></td></tr></table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Sezione Erario: l'unica che compiliamo ────────────────────────── --}}
    <div class="fascia" style="margin-top: 1mm;">SEZIONE ERARIO</div>

    <table style="margin-top: 0.8mm;">
        <tr>
            <td style="width: 24%;"></td>
            <td class="eti" style="width: 11%; text-align: center;">codice tributo</td>
            <td class="eti" style="width: 12%; text-align: center;">rateazione/regione/<br>prov./mese rif.</td>
            <td class="eti" style="width: 9%; text-align: center;">anno di<br>riferimento</td>
            <td class="eti" style="width: 16%; text-align: center;">importi a debito versati</td>
            <td class="eti" style="width: 16%; text-align: center;">importi a credito compensati</td>
            {{-- La striscia di destra, dove il modulo tiene i saldi di sezione. --}}
            <td style="width: 12%;"></td>
        </tr>

        @foreach($quadro['erario'] as $indice => $riga)
            <tr>
                <td style="padding-left: 1mm;">
                    @if($indice === 0)<span class="eti-forte">IMPOSTE DIRETTE – IVA</span>@endif
                    @if($indice === 1)<span class="eti-forte">RITENUTE ALLA FONTE</span>@endif
                    @if($indice === 2)<span class="eti-forte">ALTRI TRIBUTI ED INTERESSI</span>@endif
                </td>
                <td class="box" style="text-align: center; font-family: dejavusansmono, monospace;">{{ $riga['codice_tributo'] }}</td>
                <td class="box" style="text-align: center; font-family: dejavusansmono, monospace;">{{ $riga['rateazione'] }}</td>
                <td class="box" style="text-align: center; font-family: dejavusansmono, monospace;">{{ $riga['anno'] }}</td>
                <td style="padding: 0;">
                    <table>
                        <tr>
                            <td class="box imp-euro" style="width: 76%; border-right: none;">{{ $riga['debito']['euro'] ?? '' }}</td>
                            <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                            <td class="box imp-cent" style="width: 16%; border-left: none;">{{ $riga['debito']['centesimi'] ?? '' }}</td>
                        </tr>
                    </table>
                </td>
                <td style="padding: 0;">
                    <table>
                        <tr>
                            <td class="box" style="width: 76%; border-right: none;"></td>
                            <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                            <td class="box" style="width: 16%; border-left: none;"></td>
                        </tr>
                    </table>
                </td>
                <td></td>
            </tr>
        @endforeach

    </table>

    {{--
        La riga dei totali sta su una tabella sua: sul modulo i tre riquadri — TOTALE A,
        TOTALE B e il saldo — non sono incolonnati con la griglia sopra, e forzarli dentro le
        stesse colonne li avrebbe sfalsati rispetto all'originale.

        TOTALE B resta vuoto, e non a zero: senza compensazioni non c'è nulla da dichiarare in
        quella colonna, e uno «0,00» scritto lì racconterebbe una compensazione da zero euro.
        Il saldo (A-B) coincide quindi con il totale a debito.
    --}}
    <table style="margin-top: 1mm;">
        <tr>
            <td style="width: 29%; padding: 0;">
                <table>
                    <tr>
                        <td style="width: 46%;">
                            <div class="eti">codice ufficio</div>
                            <table><tr><td class="box"></td></tr></table>
                        </td>
                        <td style="width: 6%;"></td>
                        <td style="width: 48%;">
                            <div class="eti">codice atto</div>
                            <table><tr><td class="box"></td></tr></table>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="totale" style="width: 8%; padding-top: 3mm;">TOTALE&nbsp;&nbsp;A</td>
            <td style="width: 16%; padding-top: 3mm;">
                <table>
                    <tr>
                        <td class="box imp-euro" style="width: 74%; border-right: none;">{{ $quadro['totale_a']['euro'] }}</td>
                        <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                        <td class="box imp-cent" style="width: 18%; border-left: none;">{{ $quadro['totale_a']['centesimi'] }}</td>
                    </tr>
                </table>
            </td>
            <td class="totale" style="width: 3%; padding-top: 3mm;">B</td>
            <td style="width: 15%; padding-top: 3mm;">
                <table>
                    <tr>
                        <td class="box" style="width: 74%; border-right: none;"></td>
                        <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                        <td class="box" style="width: 18%; border-left: none;"></td>
                    </tr>
                </table>
            </td>
            <td class="saldo-eti" style="width: 13%; padding-top: 3mm;">+/–&nbsp; SALDO&nbsp; (A-B)</td>
            <td style="width: 16%; padding-top: 3mm;">
                <table>
                    <tr>
                        <td class="box imp-euro" style="width: 74%; border-right: none;">{{ $quadro['saldo']['euro'] }}</td>
                        <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                        <td class="box imp-cent" style="width: 18%; border-left: none;">{{ $quadro['saldo']['centesimi'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Le sezioni che un condominio non usa ──────────────────────────── --}}
    @include('pdf.gestionale._f24_sezione_vuota', [
        'titolo' => 'SEZIONE INPS',
        'colonne' => ['codice sede', 'causale contributo', 'matricola INPS/codice INPS/filiale azienda', 'periodo di riferimento: da mm/aaaa   a mm/aaaa'],
        'righe' => 4,
        'lettereTotale' => ['C', 'D'],
        'lettereSaldo' => 'SALDO  (C-D)',
    ])

    @include('pdf.gestionale._f24_sezione_vuota', [
        'titolo' => 'SEZIONE REGIONI',
        'colonne' => ['codice regione', 'codice tributo', 'rateazione/mese rif.', 'anno di riferimento'],
        'righe' => 4,
        'lettereTotale' => ['E', 'F'],
        'lettereSaldo' => 'SALDO  (E-F)',
    ])

    @include('pdf.gestionale._f24_sezione_vuota', [
        'titolo' => 'SEZIONE IMU E ALTRI TRIBUTI LOCALI',
        'colonne' => ['codice ente/codice comune', 'codice tributo', 'rateazione/mese rif.', 'anno di riferimento'],
        'righe' => 4,
        'lettereTotale' => ['G', 'H'],
        'lettereSaldo' => 'SALDO  (G-H)',
    ])

    @include('pdf.gestionale._f24_sezione_vuota', [
        'titolo' => 'SEZIONE ALTRI ENTI PREVIDENZIALI E ASSICURATIVI',
        'colonne' => ['codice sede', 'codice ditta', 'c.c.', 'numero di riferimento', 'causale'],
        'righe' => 3,
        'lettereTotale' => ['I', 'L'],
        'lettereSaldo' => 'SALDO  (I-L)',
    ])

    {{-- ── Firma e saldo finale ──────────────────────────────────────────── --}}
    <table style="margin-top: 1mm;">
        <tr>
            <td class="fascia" style="width: 58%;">FIRMA</td>
            <td style="width: 2%;"></td>
            <td class="fascia" style="width: 40%;">SALDO FINALE</td>
        </tr>
        <tr>
            <td style="padding-top: 0.8mm;">
                <table><tr><td class="box" style="height: 6mm;"></td></tr></table>
            </td>
            <td></td>
            <td style="padding-top: 0.8mm;">
                <table>
                    <tr>
                        <td class="totale" style="width: 20%; padding-top: 2.5mm;">EURO</td>
                        <td class="box imp-euro" style="width: 60%; border-right: none;">{{ $quadro['saldo']['euro'] }}</td>
                        <td class="box virgola" style="border-left: none; border-right: none;">,</td>
                        <td class="box imp-cent" style="width: 14%; border-left: none;">{{ $quadro['saldo']['centesimi'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Estremi del versamento: li scrive lo sportello, non noi ───────── --}}
    <table style="margin-top: 1mm;">
        <tr>
            <td class="fascia">
                ESTREMI DEL VERSAMENTO
                <span style="font-weight: normal; font-size: 5pt;">(DA COMPILARE A CURA DI BANCA/POSTE/AGENTE DELLA RISCOSSIONE)</span>
            </td>
        </tr>
    </table>

    <table style="margin-top: 0.8mm;">
        <tr>
            <td style="width: 26%;">
                <div class="eti" style="text-align: center;">DATA</div>
                <table>
                    <tr>
                        <td class="box" style="width: 33%;"></td>
                        <td class="box" style="width: 33%;"></td>
                        <td class="box" style="width: 34%;"></td>
                    </tr>
                    <tr>
                        <td class="eti" style="text-align: center;">giorno</td>
                        <td class="eti" style="text-align: center;">mese</td>
                        <td class="eti" style="text-align: center;">anno</td>
                    </tr>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 34%;">
                <div class="eti" style="text-align: center;">CODICE BANCA/POSTE/AGENTE DELLA RISCOSSIONE</div>
                <table>
                    <tr>
                        <td class="box" style="width: 50%;"></td>
                        <td class="box" style="width: 50%;"></td>
                    </tr>
                    <tr>
                        <td class="eti" style="text-align: center;">AZIENDA</td>
                        <td class="eti" style="text-align: center;">CAB/SPORTELLO</td>
                    </tr>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 36%;">
                <table>
                    <tr>
                        <td class="eti" style="width: 74%;">Pagamento effettuato con assegno</td>
                        <td class="box" style="width: 8%;"></td>
                        <td class="eti" style="width: 18%; padding-left: 1mm;">bancario/<br>postale</td>
                    </tr>
                    <tr>
                        <td style="padding: 0.6mm 0;">
                            <table>
                                <tr>
                                    <td class="eti" style="width: 14%;">n.ro</td>
                                    <td class="box" style="width: 86%;"></td>
                                </tr>
                            </table>
                        </td>
                        <td class="box"></td>
                        <td class="eti" style="padding-left: 1mm;">circolare/<br>vaglia postale</td>
                    </tr>
                    <tr>
                        <td colspan="3">
                            <table>
                                <tr>
                                    <td class="eti" style="width: 30%;">tratto / emesso su</td>
                                    <td class="box" style="width: 34%;"></td>
                                    <td class="box" style="width: 18%;"></td>
                                    <td class="box" style="width: 18%;"></td>
                                </tr>
                                <tr>
                                    <td></td><td></td>
                                    <td class="eti" style="text-align: center;">cod. ABI</td>
                                    <td class="eti" style="text-align: center;">CAB</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{--
        L'autorizzazione all'addebito in conto sta sulla sola prima copia, com'è sul modulo
        ministeriale: è la copia che resta alla banca, ed è lì che l'ordine di addebito serve.
        L'IBAN non lo scriviamo noi — è una scelta di pagamento che il contribuente fa allo
        sportello, e il gestionale non sa quale conto userà davvero.
    --}}
    @if($copia['iban'])
        <table style="margin-top: 1mm;">
            <tr>
                <td style="width: 22%; padding-top: 1mm;" class="eti">Autorizzo addebito su<br>conto corrente codice IBAN</td>
                <td style="width: 46%;">
                    <table class="pettine">
                        <tr>
                            <td style="font-weight: bold;">I</td>
                            <td style="font-weight: bold;">T</td>
                            @for($i = 0; $i < 12; $i++)<td></td>@endfor
                        </tr>
                    </table>
                </td>
                <td style="width: 8%;"></td>
                <td style="width: 24%; padding-top: 2mm;">
                    <table>
                        <tr>
                            <td class="eti" style="width: 22%;">firma</td>
                            <td style="border-bottom: 0.2mm solid #1c2b36; width: 78%;">&nbsp;</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    @endif

    <div class="piede">{{ $copia['titolo'] }}</div>
@endforeach

</body>
</html>
