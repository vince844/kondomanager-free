{{--
  Le regole di pagina della testata dello studio: la testata `$prima` sulla prima pagina del PDF, la testata `$seguenti` sulle altre, il piede
  `$piede` su tutte. Con `@page :first` mPDF misura la prima pagina sulla testata intera e le seguenti sulla riga, e con il margine che si allunga
  sulla testata il corpo comincia sempre sotto. Il piede sta nella regola: una regola `@page` senza piede lo spegne dalla seconda pagina. Una
  stampa che comincia con la riga (le parti del fascicolo dopo il frontespizio) non scrive la regola della prima pagina.
--}}
@page { header: html_{{ $seguenti }}; footer: html_{{ $piede }}; }
@if($carta->primaPaginaIntera())
@page :first { header: html_{{ $prima }}; footer: html_{{ $piede }}; }
@endif
