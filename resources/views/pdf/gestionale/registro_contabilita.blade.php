{{--
  Registro di contabilità ex art. 1130, comma 1, n. 7 c.c. — la stampa.

  ⚠️ **Non estende `pdf.base`, ed è una scelta.** Le sette stampe che c'erano prima condividono
  quella carta intestata; questa è la prima delle tre stampe nuove che §7-quinquies di
  docs/registri_contabili.md assegna al sistema tipografico del fac-simile
  (docs/design/facsimile_rendiconto_forum.html) — carta intestata propria, fascia in testa,
  intestazioni in maiuscoletto spaziato, numeri in serif allineati a destra, totali a doppio
  filetto. Ereditare l'intestazione di `pdf.base` avrebbe fatto convivere due identità sullo
  stesso foglio.

  ⚠️ **Cosa è stato misurato in mPDF prima di scriverlo, non assunto** (§7-quinquies avvisa che
  mPDF non è un browser):
  - `display:grid` del fac-simile → riscritto in `<table>`. Non supportato.
  - `letter-spacing`, `border-bottom: 2px double`, sfondi di cella, `@page` header/footer: **sì**,
    verificati generando il PDF e guardandolo.
  - `font-variant-numeric: tabular-nums`: **inutile qui**, perché mPDF non riesce ad attivare le
    funzioni OpenType su questi caratteri (vedi `PdfService::configurazioneFont()`). Non serve
    comunque: gli importi sono in Fraunces, **le cui cifre sono già tabulari** — misurato
    incolonnando 111.111,11 e 888.888,88.
--}}
@php
    $filtriAttivi = [];
    if ($filtri['data_da'] && $filtri['data_a']) {
        $filtriAttivi[] = 'periodo dal '.$filtri['data_da'].' al '.$filtri['data_a'];
    } elseif ($filtri['data_da']) {
        $filtriAttivi[] = 'periodo dal '.$filtri['data_da'].' alla fine dell\'esercizio';
    } elseif ($filtri['data_a']) {
        $filtriAttivi[] = 'periodo dall\'inizio dell\'esercizio al '.$filtri['data_a'];
    }
    if ($filtri['search']) { $filtriAttivi[] = 'ricerca: «'.$filtri['search'].'»'; }
    $parziale = count($filtriAttivi) > 0;
    // ⚠️ Il saldo NON si ricalcola qui: lo porta il controller dal servizio, calcolato
    // sull'esercizio intero alla data di riferimento. La vista lo ricavava dall'ultima riga
    // mostrata, e con zero righe stampava «€ 0,00» su un conto a −370,56.
    $saldoFinale = $saldo_finale;
    $dataSaldo = $saldo_alla_data;
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Registro di contabilità – {{ $esercizio->nome }}</title>
<style>
    /* Palette del fac-simile, verbatim: ink, navy, blue, slate, gold, canvas, line, neg. */
    body { font-family: inter; font-size: 7pt; color: #26313F; }

    .fascia { background: #FAF4E2; border: 1px solid #E0D19C; color: #7A5A10;
              font-size: 7.5pt; padding: 6px 9px; margin-bottom: 12px; }
    .fascia b { letter-spacing: 1pt; text-transform: uppercase; }

    .testata { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0E2238; }
    .testata td { padding: 0 0 10px 0; vertical-align: top; border: 0; }
    .ente { font-family: fraunces; font-size: 15pt; color: #0E2238; }
    .att { font-size: 7pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #5A6B7B; }
    .rif { font-size: 7.5pt; color: #5A6B7B; }
    .doc { text-align: right; font-size: 7.5pt; color: #5A6B7B; }
    .doc b { color: #0E2238; }

    h1 { font-family: fraunces; font-size: 17pt; color: #0E2238; margin: 14px 0 1px; padding: 0; }
    .sotto { font-family: fraunces; font-size: 10pt; color: #2E5C86; margin: 0 0 10px; }

    /* `.dati` del fac-simile è una griglia CSS: qui è una tabella, perché mPDF non ha il grid. */
    .dati { width: 100%; border-collapse: collapse; border-top: 1px solid #D9DEE5;
            border-bottom: 1px solid #D9DEE5; margin-bottom: 14px; }
    .dati td { padding: 8px 10px 8px 0; border: 0; vertical-align: top; }
    .dati .et { font-size: 7pt; letter-spacing: 0.9pt; text-transform: uppercase; color: #5A6B7B; }
    .dati .vl { font-size: 9.5pt; color: #0E2238; font-family: fraunces; }

    table.registro { width: 100%; border-collapse: collapse; }
    table.registro th { font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase;
                        color: #5A6B7B; font-weight: bold; text-align: left;
                        border-bottom: 1px solid #0E2238; padding: 4px 5px; }
    /* ⚠️ **Una riga per movimento, e l'imbottitura è misurata.** Con 8pt, 5px di imbottitura e il
       protocollo su una riga propria, un esercizio da 5.000 movimenti usciva in **419 pagine** —
       dodici righe a pagina, cioè un registro che non si consulta. Misurato generando il PDF vero,
       non stimato. Qui il corpo scende a 7pt, l'imbottitura a 3px e il protocollo torna in linea
       con il tipo movimento. */
    table.registro td { padding: 3px 5px; border-bottom: 1px solid #D9DEE5; vertical-align: top; }
    .n { text-align: right; white-space: nowrap; font-family: fraunces; }
    th.n { font-family: inter; }
    .num { text-align: right; color: #5A6B7B; font-family: fraunces; white-space: nowrap; }
    .neg { color: #96302F; }
    .d { color: #5A6B7B; font-size: 6pt; }
    .rit { color: #8A6212; font-size: 6pt; }
    .badge { font-size: 5.8pt; font-weight: bold; letter-spacing: 0.5pt; text-transform: uppercase;
             padding: 1px 5px; background: #F4EAEA; color: #96302F; }

    table.registro tfoot td { border-top: 1.5px solid #0E2238; border-bottom: 2px double #0E2238;
                              font-weight: bold; color: #0E2238; padding: 7px; }

    .chiusura { margin-top: 4px; padding: 10px 12px; background: #F4F5F7;
                border-left: 3px solid #C9A227; font-size: 7.5pt; color: #26313F;
                margin-bottom: 14px; }
    .chiusura b { color: #0E2238; }

    .vuoto { padding: 20px; text-align: center; color: #5A6B7B; font-style: italic; }
</style>
</head>
<body>

{{-- Testatina di continuazione: dalla seconda pagina in poi. Senza `show-this-page`, mPDF la
     applica dalla pagina successiva — che è quello che serve, perché su questa c'è la carta
     intestata vera. --}}
<htmlpageheader name="registroTestatina">
    <table style="width: 100%; border-collapse: collapse; border-bottom: 1px solid #D9DEE5;">
        <tr>
            <td style="border: 0; padding: 0 0 5px 0; font-size: 7.5pt; color: #5A6B7B;">
                <b style="color: #0E2238;">{{ $condominio->nome }}</b> · Registro di contabilità
            </td>
            <td style="border: 0; padding: 0 0 5px 0; text-align: right; font-size: 7.5pt; color: #5A6B7B;">
                {{ $esercizio->nome }}@if($parziale) · estratto parziale @endif
                @if($mostra_annotazione) · copia di controllo @endif
            </td>
        </tr>
    </table>
</htmlpageheader>
<sethtmlpageheader name="registroTestatina" page="ALL" value="on" />

<htmlpagefooter name="registroPiede">
    <table style="width: 100%; border-collapse: collapse; border-top: 1px solid #D9DEE5;">
        <tr>
            <td style="border: 0; padding: 5px 0 0 0; font-size: 7pt; color: #5A6B7B;">
                Documento emesso il {{ ($data_emissione_stampe ?? now())->format('d/m/Y') }}
                @if(!empty($nota_legale_stampe))
                    <br>{!! nl2br(e($nota_legale_stampe)) !!}
                @endif
            </td>
            <td style="border: 0; padding: 5px 0 0 0; text-align: right; font-size: 7pt; color: #5A6B7B;">
                Pagina {PAGENO} di {nbpg}
            </td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="registroPiede" page="ALL" value="on" />

{{-- ⚠️ **Perché questa copia si dichiara, e l'altra no.** La decisione D9 di
     `docs/registri_contabili.md` tiene la data di annotazione FUORI dalla stampa destinata
     all'assemblea: «un registro che denuncia da solo i ritardi del suo autore, consegnato in
     assemblea, è un'arma contro l'amministratore, non uno strumento per lui». La stessa D9
     prevede però che «in stampa entra solo se l'amministratore lo chiede» — questa è quella
     copia, e deve dire di esserlo, o le due versioni dello stesso registro diventano
     indistinguibili una volta stampate. --}}
@if($mostra_annotazione)
<div class="fascia">
    <b>Copia di controllo</b> · questo esemplare riporta anche la data in cui ogni movimento è
    stato annotato e segnala quelli oltre i trenta giorni dell'art. 1130, comma 1, n. 7 c.c.
    <b style="letter-spacing: 0; text-transform: none;">Serve alla verifica interna</b>: la copia
    da portare in assemblea si stampa senza quella colonna.
</div>
@endif

{{-- La fascia del fac-simile dichiara la natura del documento. Qui serve a dire una cosa sola,
     ma quella conta: che non si sta guardando tutto. --}}
@if($parziale)
<div class="fascia">
    <b>Estratto parziale</b> · questo documento non contiene tutti i movimenti dell'esercizio:
    {{-- `text-transform: none` esplicito: `.fascia b` è maiuscoletto per l'etichetta «Estratto
         parziale», ma un pezzo di frase in maiuscolo — «PERIODO DAL 01/06/2026 AL FINE
         ESERCIZIO» — è la maiuscola fuori posto che questo progetto corregge da sempre. --}}
    sono stati applicati i filtri
    <b style="letter-spacing: 0; text-transform: none;">{{ implode(' · ', $filtriAttivi) }}</b>.
    I totali in fondo si riferiscono ai soli movimenti qui riportati; il numero d'operazione e il
    saldo progressivo restano invece quelli dell'esercizio intero.
</div>
@endif

<table class="testata">
    <tr>
        <td>
            <div class="ente">{{ $condominio->nome }}</div>
            <div class="att">Registro di contabilità</div>
            <div class="rif">
                {{ $condominio->indirizzo }}@if($condominio->codice_fiscale)<br>C.F. {{ $condominio->codice_fiscale }}@endif
            </div>
        </td>
        <td class="doc">
            <b>{{ $esercizio->nome }}</b><br>
            dal <b>{{ $esercizio->data_inizio->format('d/m/Y') }}</b> al <b>{{ $esercizio->data_fine->format('d/m/Y') }}</b><br>
            Stampato il <b>{{ now()->format('d/m/Y') }}</b>
        </td>
    </tr>
</table>

<h1>Registro di contabilità</h1>
<p class="sotto">Movimenti in entrata e in uscita — art. 1130, comma 1, n. 7 c.c.</p>

{{--
  ⚠️ **Questa nota non è cortesia: è la difesa del documento.** Il registro di contabilità è a
  tutti gli effetti una scrittura contabile, e le viene applicato l'art. 2219 c.c. — «senza spazi
  in bianco, senza interlinee e senza trasporti in margine», e soprattutto: se una cancellazione
  serve, «deve eseguirsi in modo che le parole cancellate siano leggibili». È esattamente la
  ragione per cui una riga stornata resta stampata e marcata invece di sparire (D7). Chi legge il
  foglio — un condòmino, un revisore, un CTU — deve poterlo capire dal foglio stesso.
--}}
<div class="chiusura">
    <b>Come leggere questo registro.</b>
    Contiene i soli movimenti di denaro reale su banca e contanti: una fattura registrata e non
    pagata non vi compare, e un accantonamento verso un fondo nemmeno, perché quel denaro resta
    sullo stesso conto corrente. <b>Per la stessa ragione il saldo di questo registro è al netto
    dei fondi accantonati</b> e non coincide con l'estratto conto: ne differisce di quanto è
    vincolato nei fondi. <b>Il saldo progressivo parte da zero all'inizio dell'esercizio</b>: è
    il saldo dei movimenti di quest'anno e non comprende la disponibilità riportata
    dall'esercizio precedente. Le operazioni sono numerate in ordine cronologico di
    effettuazione.
    @if($mostra_annotazione)
    Accanto a ogni data compare quella di annotazione, così che il termine di trenta giorni
    dell'art. 1130, comma 1, n. 7 c.c. sia verificabile riga per riga.
    @endif
    <b>Le operazioni stornate restano nel registro</b>, marcate come tali e seguite dalla scrittura
    che le annulla: una cancellazione che nasconde il cancellato sarebbe contraria all'art. 2219
    c.c., che le scritture contabili pretende leggibili anche dove sono state corrette.
</div>

<table class="dati">
    <tr>
        <td style="width: 20%;">
            <div class="et">Movimenti</div>
            <div class="vl">{{ number_format(count($righe), 0, ',', '.') }}</div>
        </td>
        <td style="width: 27%;">
            <div class="et">Totale entrate</div>
            <div class="vl">€ {{ number_format($totale_entrate / 100, 2, ',', '.') }}</div>
        </td>
        <td style="width: 27%;">
            <div class="et">Totale uscite</div>
            <div class="vl">€ {{ number_format($totale_uscite / 100, 2, ',', '.') }}</div>
        </td>
        <td style="width: 26%;">
            {{-- Lo spazio prima di `@if` è necessario: Blade non compila una direttiva
                 attaccata a un carattere di parola. Vedi la nota più sotto su `gg @endif`. --}}
            <div class="et">Saldo di cassa @if($dataSaldo)al {{ \Carbon\Carbon::parse($dataSaldo)->format('d/m/Y') }} @endif</div>
            <div class="vl {{ $saldoFinale < 0 ? 'neg' : '' }}">
                € {{ number_format($saldoFinale / 100, 2, ',', '.') }}
            </div>
        </td>
    </tr>
</table>

@if(count($saldi_per_cassa ?? []) > 1)
{{-- ⚠️ **Con più di una cassa il totale non basta, e in stampa pesa il doppio.** Questo è il
     foglio che finisce in assemblea: se dicesse solo «saldo 1,94» nasconderebbe che il conto
     corrente è scoperto di 370 euro proprio a chi ha il diritto di controllarlo. Con una cassa
     sola il blocco non compare: il totale È già il dettaglio. --}}
<table style="width: 62%; border-collapse: collapse; margin-bottom: 14px;">
    <thead>
        <tr>
            <th style="font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; font-weight: bold; text-align: left; border-bottom: 1px solid #0E2238; padding: 4px 5px;">Saldo per cassa</th>
            <th class="n" style="font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; font-weight: bold; border-bottom: 1px solid #0E2238; padding: 4px 5px; font-family: inter;">Movimenti</th>
            <th class="n" style="font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; font-weight: bold; border-bottom: 1px solid #0E2238; padding: 4px 5px; font-family: inter;">Entrate</th>
            <th class="n" style="font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; font-weight: bold; border-bottom: 1px solid #0E2238; padding: 4px 5px; font-family: inter;">Uscite</th>
            <th class="n" style="font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; font-weight: bold; border-bottom: 1px solid #0E2238; padding: 4px 5px; font-family: inter;">Saldo</th>
        </tr>
    </thead>
    <tbody>
        @foreach($saldi_per_cassa as $sc)
        <tr>
            <td style="padding: 3px 5px; border-bottom: 1px solid #D9DEE5;">{{ $sc['cassa'] }}</td>
            <td class="n" style="padding: 3px 5px; border-bottom: 1px solid #D9DEE5;">{{ $sc['movimenti'] }}</td>
            <td class="n" style="padding: 3px 5px; border-bottom: 1px solid #D9DEE5;">€ {{ number_format($sc['entrate'] / 100, 2, ',', '.') }}</td>
            <td class="n" style="padding: 3px 5px; border-bottom: 1px solid #D9DEE5;">€ {{ number_format($sc['uscite'] / 100, 2, ',', '.') }}</td>
            <td class="n {{ $sc['saldo'] < 0 ? 'neg' : '' }}" style="padding: 3px 5px; border-bottom: 1px solid #D9DEE5;">€ {{ number_format($sc['saldo'] / 100, 2, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{--
  ⚠️ **La tabella si spezza in blocchi da 300 righe, e il motivo è la memoria — misurato sul
  Libro Giornale nella beta.23.** mPDF tiene in memoria l'INTERA tabella per calcolare le
  larghezze delle colonne: con una tabella sola il picco cresce linearmente e la stampa muore di
  fatal error dentro mPDF (pagina bianca, nessuna eccezione, niente nei log). Il `<pagebreak />`
  è quello nativo: `page-break-before` su `<table>` mPDF lo ignora, verificato. Le larghezze sono
  dichiarate sui `<th>` perché ogni blocco è una tabella a sé e senza di quelle le colonne si
  disallineerebbero da un blocco all'altro.
--}}
@foreach(array_chunk($righe, 300) as $blocco)
@if(! $loop->first)<pagebreak />@endif
<table class="registro">
    <thead>
        <tr>
            <th class="n" style="width: 38px;">N.</th>
            <th style="width: 62px;">Data</th>
            @if($mostra_annotazione)
            <th style="width: 62px;">Annotato</th>
            @endif
            {{-- ⚠️ **Il protocollo ha una colonna sua, e la ragione è misurabile.** Messo in coda
                 alla descrizione le rubava lo spazio e mandava a capo ogni riga: 5.000 movimenti
                 uscivano in 227 pagine. In colonna propria — larghezza fissa, come si conviene a
                 un identificatore — la riga sta su una linea sola. È anche la forma della stampa
                 del Libro Giornale, che di protocollo ne ha una da sempre. --}}
            <th style="width: 78px;">Protocollo</th>
            <th>Descrizione</th>
            <th style="width: 92px;">Cassa</th>
            <th style="width: 128px;">Controparte</th>
            <th class="n" style="width: 72px;">Entrata</th>
            <th class="n" style="width: 72px;">Uscita</th>
            <th class="n" style="width: 78px;">Saldo</th>
        </tr>
    </thead>
    <tbody>
        {{--
          ⚠️ `Carbon::parse()` qui è sicuro solo perché lo è il chiamante: le date arrivano da
          `RegistroContabilitaService::registro()`, che le ha già normalizzate in 'Y-m-d' da
          colonne DATE non nullable. Non è una garanzia della vista.
        --}}
        @foreach($blocco as $riga)
        <tr>
            <td class="num">{{ $riga['numero'] }}</td>
            <td style="white-space: nowrap;">{{ \Carbon\Carbon::parse($riga['data'])->format('d/m/Y') }}</td>
            @if($mostra_annotazione)
            <td style="white-space: nowrap;" class="{{ $riga['oltre_trenta_giorni'] ? 'rit' : 'd' }}">
                {{ \Carbon\Carbon::parse($riga['data_annotazione'])->format('d/m/Y') }}
                {{-- ⚠️ Lo spazio prima di `@endif` è necessario, non estetica: Blade compila una
                     direttiva solo se non è preceduta da un carattere di parola, quindi
                     `gg@endif` resta testo e la vista non compila più. --}}
                @if($riga['oltre_trenta_giorni'])<br>oltre 30 gg @endif
            </td>
            @endif
            <td class="d">{{ $riga['protocollo'] }}</td>
            <td>
                {{ $riga['descrizione'] }}
                @if($riga['stornata'])
                    <span class="badge">stornata</span>
                @endif
{{-- ⚠️ **In stampa il tipo movimento non c'è, e non è una dimenticanza.** I dati minimi del
                     registro sono numero, data, importo, controparte e descrizione: il tipo movimento
                     non è fra questi ed è deducibile dalla causale. A schermo resta, come badge
                     colorato: lì lo spazio c'è e aiuta a scorrere. --}}
            </td>
            <td class="d">{{ $riga['cassa'] }}</td>
            <td>{{ $riga['controparte'] ?? '—' }}</td>
            <td class="n">{{ $riga['entrata'] !== null ? '€ '.number_format($riga['entrata'] / 100, 2, ',', '.') : '' }}</td>
            <td class="n">{{ $riga['uscita'] !== null ? '€ '.number_format($riga['uscita'] / 100, 2, ',', '.') : '' }}</td>
            <td class="n {{ $riga['saldo_progressivo'] < 0 ? 'neg' : '' }}">
                € {{ number_format($riga['saldo_progressivo'] / 100, 2, ',', '.') }}
            </td>
        </tr>
        @endforeach
    </tbody>
    {{-- Il totale una volta sola, in fondo all'ultimo blocco: ripeterlo a ogni blocco lo farebbe
         sembrare un subtotale di pagina, che non è. --}}
    @if($loop->last)
    <tfoot>
        <tr>
            {{-- ⚠️ **Il colspan segue la colonna «Annotato», che c'è solo nella copia di controllo.**
                 Le colonne sono dieci con quella e nove senza: un colspan fisso sballava di uno il
                 piede della copia d'assemblea. --}}
            <td colspan="{{ $mostra_annotazione ? 7 : 6 }}" style="text-align: right;">{{ $parziale ? 'Totale dei movimenti riportati' : 'Totale dell\'esercizio' }}</td>
            <td class="n">€ {{ number_format($totale_entrate / 100, 2, ',', '.') }}</td>
            <td class="n">€ {{ number_format($totale_uscite / 100, 2, ',', '.') }}</td>
            {{-- ⚠️ **Sotto «Saldo» non va niente, e non è una dimenticanza: un saldo progressivo
                 non si somma.** Sommare la colonna darebbe un numero privo di significato, e
                 ricopiarci il saldo dell'ultima riga — che è ciò che faceva — lo mette sotto
                 l'etichetta «Totale dell'esercizio», dove si legge come una somma che non è. Il
                 saldo finale è già dichiarato una volta, nella striscia dei dati in testa, con la
                 data a cui si riferisce: è lì che significa qualcosa. --}}
            <td class="n"></td>
        </tr>
    </tfoot>
    @endif
</table>
@endforeach

{{-- Fuori dal ciclo dei blocchi: con zero righe `array_chunk([], 300)` è vuoto e il `@foreach`
     non gira nemmeno una volta. --}}
@if(count($righe) === 0)
<table class="registro">
    <tr><td class="vuoto">Nessun movimento di cassa {{ $parziale ? 'nel periodo selezionato' : 'in questo esercizio' }}.</td></tr>
</table>
@endif


</body>
</html>
