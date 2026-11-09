<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Printer, Info, CheckCircle, Image as ImageIcon, Building2, LayoutTemplate, FileImage } from 'lucide-vue-next';

defineProps<{
    open: boolean;
}>();

defineEmits(['update:open']);
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0">
      <div class="px-6 py-8">
        <SheetHeader class="mb-8">
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-indigo-100 text-indigo-700 rounded-lg dark:bg-indigo-900 dark:text-indigo-300">
              <Printer class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: le stampe dello studio</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Lo studio in testa alle stampe, le carte dello studio e la carta intestata, la nota legale in fondo alle pagine e la firma.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Building2 class="w-5 h-5 text-indigo-500" /> Lo studio, in testa alle stampe
            </h3>
            <p class="mb-3">
              Se scrivi il nome dello studio, lo studio va in testa a tutte le stampe del programma, tranne il modello F24, che è dell'Agenzia delle Entrate.
              Sulla prima pagina, a sinistra, ci sono il logo e lo studio con i suoi dati; a destra il condominio con il nome, l'indirizzo e il codice fiscale:
              chi riceve il foglio legge chi lo scrive e di quale condominio è. Dalla seconda pagina c'è una riga sola, con lo studio e il condominio.
            </p>
            <p class="mb-3">
              Nelle lettere, nelle ricevute e negli avvisi di compensazione lo studio sta in alto a sinistra e sotto c'è «Amministratore del Condominio …», con
              la via, la città e il codice fiscale: a destra c'è il destinatario, con le righe allineate al margine destro. Senza il nome dello studio la
              testata è quella del solo condominio, con il nome alla misura di quello del destinatario.
            </p>
            <ul class="space-y-3 list-disc pl-5 mb-4">
              <li>
                <strong>I dati che scrivi</strong> vanno tutti in testa: l'indirizzo con il comune, la partita IVA e il codice fiscale, il telefono, l'email, la
                PEC e il sito. Un dato vuoto non si stampa.
              </li>
              <li>
                <strong>Il comune</strong> sta accanto all'indirizzo, e non si ripete se l'indirizzo lo dice già. È anche il luogo in testa alle lettere
                («Milano, 20/03/2026»): senza, il luogo è il comune del condominio.
              </li>
              <li>
                <strong>La partita IVA</strong> ha 11 cifre; <strong>il codice fiscale</strong> 11 cifre, o 16 lettere e cifre, e si salva in maiuscolo e
                senza spazi; <strong>il sito</strong> si scrive senza spazi.
              </li>
              <li>
                <strong>Il logo</strong>, in PNG o JPG, si stampa accanto al nome dello studio, alto al massimo 14 mm e largo al massimo 40 mm. Il programma lo
                salva riscritto, con il lato lungo al massimo di 1.000 pixel e con la sua trasparenza. Un'immagine di più di 4 milioni di pixel (più di
                2.000 × 2.000, per esempio) non si carica: riducila prima.
              </li>
            </ul>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
              <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
              <div>
                <h4 class="font-bold text-slate-900 dark:text-white mb-1">Lo spazio sulla prima pagina</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Con tutti i dati dello studio la prima pagina ha meno spazio per il testo: nelle lettere circa 27 mm. Se ti serve più spazio, lascia vuoti i
                  dati che non vuoi in testa.
                </p>
              </div>
            </div>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2 mt-6">
              <LayoutTemplate class="w-5 h-5 text-amber-500" /> Le carte dello studio
            </h3>
            <p class="mb-3">
              Una carta dice come esce la testata delle stampe: <strong>a campi</strong>, con lo studio e il condominio scritti dal programma, o con la
              <strong>carta intestata</strong> dello studio sotto le stampe verticali. In ogni carta scegli se c'è il logo e un colore per i titoli, i loro
              filetti e la testata; senza colore, ogni stampa ha i colori di sempre. Il testo e le cifre restano del colore di sempre, un colore troppo chiaro
              sul bianco si rifiuta, e il carattere resta quello di sempre.
            </p>
            <p class="mb-3">
              La «Carta dello studio» è la <strong>predefinita</strong>: la usano i documenti per cui non scegli un'altra carta. La predefinita non si elimina
              (per eliminarla, segna prima come predefinita un'altra carta), e nemmeno una carta che un gruppo di documenti usa.
            </p>
            <p class="mb-3">
              In <strong>«Quale carta per quale documento»</strong> scegli la carta per ognuno dei cinque gruppi (le lettere ai condòmini; le ricevute e gli
              avvisi di compensazione; il rendiconto e l'assemblea; gli estratti conto e i prospetti per la persona; i registri e gli elenchi dello studio), o
              «La stessa per tutti», e poi «Salva la scelta delle carte». Sotto ogni gruppo ci sono le sue stampe.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
              <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
              <div>
                <h4 class="font-bold text-slate-900 dark:text-white mb-1">Prova la carta</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Apre in una scheda nuova un PDF con dati d'esempio: una lettera e una pagina orizzontale con la testata della carta. Prova la carta salvata:
                  per vedere una modifica, salvala e poi aprila di nuovo.
                </p>
              </div>
            </div>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2 mt-6">
              <FileImage class="w-5 h-5 text-sky-500" /> La carta intestata
            </h3>
            <p class="mb-3">
              Carichi la carta intestata dello studio come <strong>PDF</strong> di una o due pagine A4 verticali (la prima pagina, e quella delle pagine
              seguenti), o come <strong>immagine A4 verticale in JPG</strong>, larga almeno 1.240 pixel. Il programma prova il PDF quando lo carichi, come se
              stampasse, e lo salva rifatto, con le sole pagine: una carta della tipografia con l'abbondanza va bene se il PDF segna il formato finito (lo fanno
              di solito i PDF per la tipografia): si taglia sul foglio A4. Un PDF protetto,
              o salvato in un formato che il programma non legge, si rifiuta: in quel caso carica la carta come immagine JPG. L'immagine si salva com'è, ma
              senza i dati della foto (il modello, la data, il luogo), che così non arrivano ai condòmini; una foto girata si rifiuta, se il server ha l'estensione
              «exif» di PHP: ruotala e salvala di nuovo.
            </p>
            <p class="mb-3">
              <strong>I margini</strong> dicono dove la carta lascia lo spazio al testo. Per una carta nuova il programma propone 45 mm in alto, 25 in basso,
              20 a sinistra e a destra; i lati arrivano a 50 mm, l'alto e il basso insieme a 150. Sulla carta intestata il programma non scrive lo studio, che è
              stampato sulla carta: scrive il condominio in cima allo spazio libero e il piede subito sopra il margine basso. Nelle lettere, nelle ricevute e
              negli avvisi, in alto a sinistra, c'è «Amministratore del Condominio …» con la via, la città e il codice fiscale, e il destinatario sta a
              destra, allineato al margine destro della carta.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3 mb-4">
              <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
              <div>
                <h4 class="font-bold text-slate-900 dark:text-white mb-1">Dopo il caricamento, prova la carta</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Apri la carta con «Prova la carta» e guarda dove cadono il condominio, il testo e il piede; se serve, cambia i margini. Se un gruppo usa già la
                  carta, le sue stampe escono già così.
                </p>
              </div>
            </div>
            <ul class="space-y-3 list-disc pl-5">
              <li>
                <strong>Le stampe orizzontali</strong> (i riparti, i registri, il riepilogo, gli elenchi) non hanno la carta intestata, che è verticale: hanno la
                testata a campi della stessa carta, con lo studio scritto dal programma (quindi con il nome dello studio) e con il logo se la carta dice «Con il
                logo».
              </li>
              <li>
                <strong>Nel fascicolo per l'assemblea</strong> la prima pagina della carta va al frontespizio; le parti verticali hanno la seconda.
              </li>
              <li>
                <strong>Se la carta non si legge più</strong> (il file manca, o il disco non risponde), le stampe escono a campi, e la pagina lo dice accanto
                alla carta.
              </li>
              <li>
                <strong>I collegamenti</strong> del PDF (il sito, l'email) non restano cliccabili: il testo stampato sulla carta sì.
              </li>
              <li>
                <strong>Tornare a campi</strong>: la carta tiene il file, per tornare alla carta intestata senza caricarlo di nuovo; con «Togli la carta
                intestata» il file si cancella quando salvi.
              </li>
            </ul>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2 mt-6">
              <Info class="w-5 h-5 text-blue-500" /> La nota legale, in fondo alle pagine
            </h3>
            <p class="mb-3">
              Il testo del campo «Nota Legale / Footer» va in fondo alle pagine delle stampe, sotto la data di emissione (le lettere, le ricevute e gli avvisi la
              hanno in testa) e con il numero di pagina; è lo stesso con ogni carta. Il frontespizio del fascicolo e il modello F24 non hanno il piede.
            </p>
            <ul class="space-y-3 list-disc pl-5 mb-4">
              <li>
                <strong>Andare a capo:</strong> premi Invio; la stampa tiene gli a capo.
              </li>
              <li>
                <strong>Più spazi di seguito:</strong> per distanziare due dati sulla stessa riga (la professione e la polizza, per esempio) puoi scrivere più
                spazi, e la stampa li tiene.
              </li>
            </ul>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 text-[13px] text-slate-700 dark:text-slate-300 mb-2 leading-relaxed">
              Professione esercitata ai sensi della legge 14 gennaio 2013, n. 4<br>
              Polizza RC n. 987654321
            </div>
            <p class="text-[13px] text-slate-600 dark:text-slate-400">
              La partita IVA e il codice fiscale dello studio hanno i loro campi e vanno in testa: non serve ripeterli qui.
            </p>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2 mt-6">
              <ImageIcon class="w-5 h-5 text-emerald-500" /> La firma
            </h3>
            <p class="mb-3">
              L'immagine della firma esce in fondo ai riparti e alla distinta delle spese, al prospetto delle rate, all'estratto conto e al prospetto degli
              oneri accessori, alla distinta del pagamento e al rapporto dell'importazione; nelle lettere di promemoria e di sollecito (nella diffida e nella
              messa in mora c'è lo spazio per firmare a mano); su ogni ricevuta e su ogni avviso di compensazione. I registri e le stampe del rendiconto non
              hanno la firma; il frontespizio del fascicolo ha lo spazio per firmare.
            </p>
            <p class="mb-3">
              Si carica in PNG o JPG; il programma la salva riscritta, con il lato lungo al massimo di 1.000 pixel e con la sua trasparenza.
            </p>
            <div class="grid gap-3 mb-4">
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Una firma rettangolare (circa 400 × 150 pixel)</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400">
                    Una foto quadrata e non ritagliata esce piccola: la stampa tiene la firma dentro la sua cornice, larga al massimo 180 pixel e alta 80 (nei riparti 120 e 42).
                  </p>
                </div>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Lo sfondo trasparente resta</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400">
                    Una firma in PNG senza sfondo resta senza sfondo: su una carta intestata colorata non ha un riquadro bianco intorno.
                  </p>
                </div>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Ritaglia i margini morti</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400">
                    Assicurati di ritagliare l'immagine in modo che l'inchiostro della firma tocchi quasi i bordi del file. Margini bianchi eccessivi intorno al
                    tratto faranno sembrare la firma molto piccola nel documento stampato.
                  </p>
                </div>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Una firma caricata con una versione più vecchia</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400">
                    Resta com'era: se è più grande di 1.000 pixel pesa su ogni PDF, e la pagina lo dice sotto la firma. Caricala di nuovo.
                  </p>
                </div>
              </div>
            </div>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
