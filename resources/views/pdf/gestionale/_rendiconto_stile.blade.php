{{--
  Lo stile comune delle stampe del rendiconto sulla carta del riepilogo e della situazione patrimoniale (§ 7-quinquies di docs/registri_contabili.md:
  Inter e Fraunces, la palette del fac-simile): il conguaglio per persona, il consuntivo per tabella, il foglio della persona, le posizioni dei
  condòmini, i crediti per anzianità. Ogni stampa aggiunge le sue regole in un secondo `<style>`.

  I titoli, i loro filetti e la testata hanno il colore della carta dello studio; senza un colore, quello del fac-simile. Con la carta intestata (le
  stampe verticali) nelle parti del fascicolo la testatina e il piede li dà la regola `@page`: mPDF la misura già sulla prima pagina, mentre una
  testatina accesa nel corpo arriverebbe a pagina cominciata.
--}}
@php
    $coloreDelloStile = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#0E2238');
    $conCartaNelloStile = isset($carta) && $carta->cartaIntestata() !== null;
@endphp
<style>
    body { font-family: inter; font-size: 7.5pt; color: #26313F; }
    .testata { width: 100%; border-collapse: collapse; border-bottom: 2px solid {{ $coloreDelloStile }}; }
    .testata td { padding: 0 0 10px 0; vertical-align: top; border: 0; }
    .ente { font-family: fraunces; font-size: 15pt; color: {{ $coloreDelloStile }}; }
    .att { font-size: 7pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #5A6B7B; }
    .rif { font-size: 7.5pt; color: #5A6B7B; }
    .doc { text-align: right; font-size: 7.5pt; color: #5A6B7B; }
    .doc b { color: #0E2238; }
    {{-- Il grassetto della testata: dentro una cella mPDF non applica «.doc b» (la regola con un antenato), solo la classe sola. --}}
    .forte { color: #0E2238; }
    h1 { font-family: fraunces; font-size: 17pt; color: {{ $coloreDelloStile }}; margin: 14px 0 1px; padding: 0; }
    .sotto { font-family: fraunces; font-size: 10pt; color: {{ \App\Services\PDF\Carta::coloreDi($carta ?? null, '#2E5C86') }}; margin: 0 0 10px; }
    h2 { font-family: fraunces; font-size: 11pt; color: {{ $coloreDelloStile }}; margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid {{ $coloreDelloStile }}; }
    h2 .sec { font-family: inter; font-size: 7pt; letter-spacing: 0.8pt; color: #5A6B7B; margin-right: 6px; }
    {{-- Il titolo di una sezione corta, dentro la sua tabella che non si spezza: lo stesso aspetto del titolo, e non resta mai da solo in fondo a
         una pagina. Dentro una cella mPDF applica solo le regole con una classe sola: il numero ha la sua. --}}
    table.p td.titolo { font-family: fraunces; font-size: 11pt; font-weight: normal; color: {{ $coloreDelloStile }}; border-bottom: 1px solid {{ $coloreDelloStile }}; padding: 14px 0 3px 0; }
    .sec-cella { font-family: inter; font-size: 7pt; letter-spacing: 0.8pt; color: #5A6B7B; }
    .dati { width: 100%; border-collapse: collapse; border-top: 1px solid #D9DEE5; border-bottom: 1px solid #D9DEE5; margin: 8px 0 4px; }
    .dati td { padding: 7px 10px 7px 0; border: 0; vertical-align: top; }
    {{-- Le cifre in testa: dentro una cella mPDF non applica le regole con un antenato («.dati .vl»), solo quelle con una classe sola. --}}
    .dato-et { font-size: 6.5pt; letter-spacing: 0.9pt; text-transform: uppercase; color: #5A6B7B; }
    .dato-vl { font-size: 10pt; color: #0E2238; font-family: fraunces; }
    .dato-sub { font-size: 7pt; color: #5A6B7B; }
    table.p { width: 100%; border-collapse: collapse; }
    table.p th { font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; font-weight: bold; text-align: left; vertical-align: bottom; border-bottom: 1px solid #0E2238; padding: 4px 5px; }
    table.p th.n { font-family: inter; text-align: right; }
    table.p td { padding: 3px 5px; border-bottom: 1px solid #D9DEE5; vertical-align: top; }
    table.p tr.tot td { border-top: 1.5px solid #0E2238; border-bottom: 2px double #0E2238; font-weight: bold; color: #0E2238; padding: 5px; }
    {{-- La riga senza altezza che dà le larghezze alla tabella in fondo, quella che non si spezza. Una tabella che non si spezza ha anche
         `autosize="1"`: senza, quando non sta nel resto della pagina mPDF la rimpicciolisce fino a farcela stare (fino a 1,4 volte), invece di
         portarla alla pagina dopo. --}}
    table.p td.larga { padding: 0; border: 0; height: 0; font-size: 1pt; }
    .n { text-align: right; white-space: nowrap; font-family: fraunces; }
    {{-- Una cifra con la sua nota sotto: senza `nowrap`, che varrebbe anche per la nota e la terrebbe su una riga (mPDF rimpicciolirebbe la tabella);
         la cifra sta su una riga perché la colonna è larga quanto la cifra più lunga. --}}
    .nn { text-align: right; font-family: fraunces; }
    .d { color: #5A6B7B; font-size: 7pt; font-weight: normal; font-family: inter; }
    .chiusura { margin-top: 6px; padding: 9px 12px; background: #F4F5F7; border-left: 3px solid #C9A227; font-size: 8pt; color: #26313F; }
    .chiusura b { color: #0E2238; }
    .eq { font-size: 8pt; color: #5A6B7B; margin: 4px 0 0; }
    .eq b { color: #0E2238; font-family: fraunces; }
    .riservato { font-size: 7pt; color: #5A6B7B; margin-top: 10px; border-top: 1px solid #D9DEE5; padding-top: 4px; }
@if($conCartaNelloStile && ! $carta->primaPaginaIntera())
    @page { header: html_rcTestatina; footer: html_rcPiede; }
@endif
</style>
