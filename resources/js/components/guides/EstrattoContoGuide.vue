<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida dell'estratto conto di una persona, nella finestra che si apre di lato dal pulsante «Guida» dell'intestazione: il menu «Azioni», le sei card
 * e da dove viene ogni cifra, gli addebiti personali (che cosa sono, le tre porte, come entrano nel libro, quando non si annullano), il credito, gli
 * accordi di rientro, i versamenti per conto di altri, chi risponde in solido, le lettere e la corrispondenza, la tabella dei movimenti con la sua
 * legenda, la stampa. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Ban, Banknote, ChevronRight, Coins, Handshake, LayoutGrid, Mail, Printer, Receipt, ShieldCheck, Table2, Wallet, Zap } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-estratto-conto">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Wallet class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: estratto conto</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        La posizione di una persona in questo condominio: le cifre e da dove vengono, gli addebiti a lei sola, il credito, le lettere, i
                        movimenti e la stampa.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Zap class="w-4 h-4" /> Il menu «Azioni»</h3>
                        <p class="mb-3">«Azioni», in alto, raccoglie quello che si fa da questa pagina, in tre gruppi.</p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Lettere.</strong> «Prepara una lettera» apre la finestra delle lettere per questa persona sola: promemoria, sollecito, diffida, messa in mora. «Rate scadute» apre l'elenco delle rate scadute con questa persona sola.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Addebiti.</strong> «Nuovo addebito» apre la finestra per un contributo o per una spesa di una sola unità già fatturata; «Spese del ritardo» quella delle spese per un pagamento in ritardo. Le stesse due finestre si aprono dalla card «Addebiti personali», con «Nuovo» e «Spese del ritardo», e dall'elenco degli addebiti.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Stampe.</strong> «Estratto conto» apre il PDF: è descritto in fondo, in «La stampa».</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><LayoutGrid class="w-4 h-4" /> Le sei card</h3>
                        <p class="mb-3">
                            Un saldo sopra zero è un debito della persona, sotto zero un credito. Le prime cinque cifre le calcola il programma; la sesta è la
                            somma dell'elenco degli addebiti personali, più sotto.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Saldo iniziale.</strong> Il debito («A DEBITO», in rosso) o il credito («A CREDITO», in verde) della persona all'inizio dell'esercizio: i suoi saldi dell'esercizio che nessun piano rate ha ancora preso, più il riporto da esercizi precedenti che i piani rate dell'esercizio hanno messo nelle sue rate. È la prima riga della tabella, «Saldo iniziale esercizio».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Totale addebiti.</strong> La colonna «Addebiti» della tabella: le rate emesse, gli addebiti personali, i rimborsi del credito e il credito preso per pagare una rata. Di una rata dei piani dell'esercizio conta la spesa: il riporto da esercizi precedenti sta già nel saldo iniziale. Le righe in avere di un'emissione (la quota delle entrate dell'anno, un credito riportato) riducono gli addebiti della loro rata.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Totale versato.</strong> La colonna «Pagamenti» della tabella, senza le righe dell'emissione: gli incassi e i pagamenti con il credito.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un incasso stornato e il suo storno restano nella tabella, con il bollino «STORNATA», ma non nei due totali: sul saldo pesano zero.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Saldo finale.</strong> Il saldo iniziale più gli addebiti meno il versato: è il saldo dell'ultima riga della tabella. «DA VERSARE» in rosso, «A CREDITO» in verde, «PAREGGIO» a zero.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Credito disponibile.</strong> Il netto della persona sulle rate emesse (e sulla rata 0), in tutte le sue unità, gestione per gestione: il suo credito meno il suo debito. Se resta credito, la cifra è il credito; se resta debito, la cifra dice quanto ha «da versare». Il credito di un'altra persona non toglie mai dal suo debito. Sotto, in piccolo, il credito lordo delle sue quote, anche di rate non ancora emesse; poi una frase dice quale rata il credito copre.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Credito e debito su gestioni diverse.</strong> Una riga per gestione, separate: per esempio € 200,00 da versare sui lavori straordinari e € 120,00 di credito sull'ordinaria, non un solo «da versare» di € 80,00. Il credito di una gestione copre le rate della stessa gestione. Dentro la stessa gestione resta il netto. Il pregresso della rata 0 sta nella gestione del suo piano.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Addebiti personali.</strong> La somma degli addebiti a questa persona sola in questo condominio, e quanti sono: la stessa lista dell'elenco «Addebiti personali» più sotto. Sono già dentro «Totale addebiti», perché ogni addebito è emesso quando lo registri: per questo la card di «Totale addebiti» dice «Rate emesse e addebiti».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Unità immobiliari.</strong> Accanto alle card, le unità della persona in questo condominio, con la tipologia e la quota, e in testa quante sono. La card ne mostra al massimo due; con più unità, «e altre N unità» le apre tutte in una finestra.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Receipt class="w-4 h-4" /> Gli addebiti personali</h3>
                        <p class="mb-3">
                            Un addebito personale è una somma chiesta a una persona sola, fuori dal riparto: una sua scadenza, come una rata. Ogni addebito è una
                            rata del piano «Addebiti individuali» della sua gestione e del suo esercizio. Il piano nasce al primo addebito, approvato senza
                            delibera, e ogni addebito è emesso subito. Per questo «Rate scadute», le lettere, l'incasso e la ricevuta lo leggono come una rata.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span><strong>Il contributo.</strong> In «Nuovo addebito», «Un contributo»: una cifra su una delle unità della persona, con la causale, la gestione, la data dell'addebito e la scadenza. La data dell'addebito è quella della sua scrittura, dentro l'esercizio scelto; la scadenza è il giorno entro cui la persona lo versa.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span><strong>La spesa di una sola unità già fatturata.</strong> In «Nuovo addebito», «Una spesa di una sola unità già fatturata»: scegli la riga della fattura con l'unità. L'addebito si scrive nella gestione e nell'esercizio della fattura. La spesa esce da quelle anticipate per l'unità: il conguaglio la conta chiesta, una volta sola. Se lo studio ha fatturato una spesa per il ritardo (un sollecito, un legale), scegli «Una spesa per il ritardo (sollecito, legale)».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span><strong>Le spese del ritardo.</strong> «Spese del ritardo»: una spesa per il ritardo pagata dal condominio senza una riga sull'unità. Scegli «Che cos'è» (le spese di sollecito, le spese legali, un'altra spesa) e «Su che cosa si fonda», con il riferimento; poi la cifra, la causale, la scadenza, la gestione e la voce che riceve il denaro quando è versato. Gli avvisi dicono dove una sentenza ha visto le cose in un altro modo: non bloccano niente. In «Rate scadute» la stessa finestra chiede la spesa a più persone insieme.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span><strong>Gli interessi di mora.</strong> Si propongono solo in «Rate scadute», con «Proponi gli interessi» nel riquadro dei suggerimenti, e il tasso viene dalle regole del recupero dei crediti. Niente si registra senza il tuo clic. Registrati, sono un addebito come gli altri: nell'elenco la loro riga dice «Interessi di mora».</span></li>
                            </ul>
                        </div>

                        <h4 class="font-semibold text-slate-900 dark:text-white mt-5 mb-2">Le tre porte di «Nuovo addebito»</h4>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span><strong>Questa pagina</strong>, per un contributo o una spesa già fatturata: il menu «Azioni», la card «Addebiti personali» e l'elenco.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span><strong>Il piano rate</strong>, nel riquadro delle quote non assegnate: in «Da addebitare a una persona», «Addebita a una persona» apre la finestra con una riga per voce e quanto resta da addebitare.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span><strong>La scheda «Conguaglio» del rendiconto</strong>, in «Le uscite che nessuno paga»: «Addebita a una persona» per quello che resta delle spese non attribuite.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span>Dal piano e dal conguaglio il programma propone chi paga, con il perché: si propone chi è o è stato proprietario, nudo proprietario o usufruttuario dell'unità, mai l'inquilino. La scelta è tua.</span></li>
                            </ul>
                        </div>

                        <h4 class="font-semibold text-slate-900 dark:text-white mt-5 mb-2">Nel libro dei movimenti</h4>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span>Registrato, l'addebito scrive un'emissione con la causale che comincia con «Addebito:». In dare «Crediti verso Condomini», con la persona, l'unità e la rata; in avere «Gestione Rate» per un contributo o una spesa già fatturata, come le rate.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span>Gli interessi e le spese del ritardo senza fattura vanno in avere su «Accessori da incassare»: diventano del condominio quando la persona li versa, e allora l'incasso porta la parte versata sulla voce che hai scelto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-amber-500 shrink-0 mt-0.5" /><span>Nella tabella qui sotto l'addebito è una riga con l'icona dell'emissione, e il saldo sale della sua cifra. Si incassa come una rata, dalla pagina degli incassi, anche in parte.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Ban class="w-4 h-4" /> Quando un addebito non si annulla</h3>
                        <p class="mb-3">
                            Un addebito non si riscrive: si annulla, e se serve se ne registra un altro. «Annulla», nella sua riga dell'elenco, chiede conferma:
                            l'addebito si toglie dal giornale e la sua scadenza non c'è più. Senza addebiti l'elenco non c'è: le due finestre si aprono dalla card e dal menu.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span><strong>Con un incasso, o un credito usato.</strong> Prima storni l'incasso dalla pagina degli incassi, poi annulli l'addebito. Un incasso già stornato non lo ferma più.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span><strong>In un accordo di rientro</strong>, in corso o chiuso, che lo riconosce: annullarlo toglierebbe la sua riga dall'accordo, e un accordo non si cancella.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span><strong>Con l'esercizio chiuso.</strong> L'addebito resta nei conti del suo esercizio, come le altre scritture di quell'anno.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span><strong>Passato agli eredi</strong> con una successione: per annullarlo, prima annulli il passaggio.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Al posto di «Annulla» la riga dice il perché, con le stesse parole.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Coins class="w-4 h-4" /> Il credito: «Compensa» e «Rimborsa il credito»</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Compensa»</strong>, accanto alla frase del credito, c'è quando il credito copre una rata aperta: apre la pagina dell'incasso con questa persona e quella rata già scelte: lì registri la compensazione.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Rimborsa il credito»</strong> c'è quando una quota della persona ha un credito: è denaro che esce dalla cassa a fronte di quella quota. Scegli il «Credito da rimborsare», la «Cassa» (le banche e le casse contanti attive, con il loro saldo), la «Data del rimborso» e l'«Importo», al massimo il credito di quella quota; la nota è facoltativa. La quota si chiude per l'importo rimborsato; le rate emesse non cambiano. Se la persona ha ancora da versare, il programma chiede «Rimborsi lo stesso?»: il credito potrebbe andare prima sulle sue rate, e decidi tu.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il rimborso è una riga della tabella, con «Storna il rimborso»: lo storno rimette il denaro in cassa e il credito sulla quota, e il rimborso resta nel giornale come annullato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il credito di un passaggio di proprietà ancora nei saldi della gestione non è ancora una quota: la card lo dice, e diventa rimborsabile quando il prossimo piano rate lo assorbe.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Handshake class="w-4 h-4" /> Gli accordi di rientro</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il riquadro «Accordi di rientro» c'è quando la persona ne ha almeno uno in questo condominio, anche chiuso, anche quando non ha più rate scadute.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per ogni accordo: la data e il «fino al», quanto è riconosciuto e quanto ne resta oggi, quanto è versato oltre il capitale riconosciuto (va a credito della persona), l'autorizzazione dell'assemblea, il calendario con le rate versate o no, la regola di decadenza e i suoi segnali, la chiusura o la decadenza registrata.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Apri l'accordo» apre la stessa finestra di «Rate scadute».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Gli interessi e le spese entrano in un accordo come rate loro, dopo che li hai registrati. Un addebito che un accordo riconosce non si annulla.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Banknote class="w-4 h-4" /> I versamenti per conto di altri</h3>
                        <p>
                            Il riquadro «Versamenti per conto di altri» elenca le rate di altre persone pagate da questa, con la data, la cifra, per conto di chi
                            e il protocollo. Chiudono le rate di quelle persone e non cambiano il saldo di questa: per questo non sono righe della tabella. Un
                            versamento stornato resta, con «stornato».
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ShieldCheck class="w-4 h-4" /> Chi risponde in solido</h3>
                        <p>
                            Dopo una vendita registrata, chi compra risponde in solido con chi vende per i contributi dell'anno in corso e di quello precedente
                            (art. 63 co. 4 disp. att. c.c.). Nell'estratto conto di chi è entrato, il riquadro «Chi risponde in solido» dice, unità per unità,
                            quanto risulta oggi non pagato a nome di chi è uscito, al netto di quello che il conguaglio della vendita ha già messo a debito di chi
                            compra. È una nota calcolata dai passaggi registrati: non è una quota e non è un sollecito. Le rate di chi è uscito restano sue.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Mail class="w-4 h-4" /> Le lettere e la corrispondenza</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La card «Lettere», sotto le unità, dice l'ultima lettera a questa persona in questo condominio, con il testo della colonna «Ultima lettera» di «Rate scadute», in due righe al massimo: il testo intero è nella scheda «Corrispondenza (N)». Una lettera ancora in coda, o non partita, la dice sotto, dopo «poi:», in una riga. Senza lettere: «Nessuna lettera a questa persona in questo condominio.»</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Prepara una lettera», nella card e nel menu «Azioni», apre la finestra delle lettere per questa persona sola.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Accanto a «Prepara una lettera», quando la corrispondenza ha almeno una voce, un collegamento che comincia con «Corrispondenza:» dice che cosa c'è, per esempio 3 ricevute, o 1 lettera e 2 ricevute: così una card senza lettere e con delle ricevute non si contraddice. Il collegamento apre la scheda «Corrispondenza (N)»: le lettere, le ricevute, gli avvisi di compensazione e le ricevute segnate «Non serve comunicarla», dalla più recente, con i filtri per tipo e per periodo, dieci per pagina. N è il numero delle voci dell'elenco.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>In cima alla scheda «Corrispondenza», «Prepara il fascicolo per il legale» apre la finestra del fascicolo: un archivio .zip con l'indice, le rate non versate, gli estratti conto degli esercizi dei piani, le lettere conservate con le prove, il rendiconto approvato con il verbale, l'accordo di rientro con la sua stampa, la scansione firmata e la decadenza o la chiusura. Di serie c'è tutto: togli un esercizio, una rata o una parte, e l'indice dice solo ciò che resta, con «Che cosa manca»; dove si aggiunge lo dice la finestra, non l'indice, che va al legale. Se basta per agire lo valuta il legale. Ogni fascicolo resta scritto nel registro della persona: l'indice e le rate non versate di quel giorno si riaprono da qui.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section data-testid="guida-corrispondenza-pec">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Mail class="w-4 h-4" /> La lettera per PEC nella corrispondenza</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Services/Posta/ConsegnaDellaPec.php:50 «self::provata($r) => 'Consegnata',» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sulla riga di una lettera per PEC lo stato è un badge: «Partita» (il gestore l'ha accettata), «Consegnata» (con la ricevuta del gestore), «Non partita» (con il perché).</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Posta/ConsegnaDellaPec.php:51 «self::nonConsegnata($r) => 'Non consegnata',» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Non consegnata»: l'avviso di mancata consegna del gestore, trascinato come la ricevuta. La lettera non conta come arrivata, e il termine non corre.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/lettere/RegistroLettere.vue:298 «Stampala per la posta</Button>» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Non partita o non consegnata, la riga offre «Manda di nuovo per PEC, con le cifre di oggi» e «Stampala per la posta»: la lettera rifatta in un PDF, poi registri la spedizione.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Posta/ConsegnaDellaPec.php:80 «si è fermata mentre la mandava» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«In partenza» da più di un quarto d'ora: si è fermata mentre la mandava, e il programma non sa se il gestore l'ha presa. Guarda nella casella dello studio fra le PEC inviate: se non c'è, mandala di nuovo.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/lettere/RegistroLettere.vue:21 «Carica la ricevuta» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Trascina qui la ricevuta di consegna (.eml)»: il file che salvi dalla casella PEC. Al telefono, e se non trascini, «Carica la ricevuta».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Posta/RicevutaDellInvio.php:81 «subMinutes(self::TOLLERANZA_IN_MINUTI)» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il programma legge il giorno e l'ora e controlla che sia la consegna di quella PEC, a quell'indirizzo, non dopo oggi né prima dell'invio (anche dell'ora, con cinque minuti di tolleranza): se no, lo dice e niente si scrive.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/lettere/RegistroLettere.vue:318 «Il giorno e l'ora sono quelli dell'invio: confermali o correggili.» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se il file non si legge (una ricevuta in PDF): «Il giorno e l'ora sono quelli dell'invio: confermali o correggili.» Il file resta come prova, e la riga dice «scritta a mano, con la prova».</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/lettere/RegistroLettere.vue:372 «prova della consegna · non si toglie» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La ricevuta resta fra le prove: si scarica, e non si toglie («ricevuta di consegna · non si toglie»); la prova della consegna scritta a mano dice «prova della consegna · non si toglie». Un'altra ricevuta è una correzione: scrivi perché, salvo quella del gestore che conferma la consegna scritta a mano lo stesso giorno.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/lettere/RegistroLettere.vue:339 «r.canale !== 'pec' && r.stato !== 'in_coda'» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Allega la prova» c'è sulle lettere su carta e per email; sulla PEC la prova è la ricevuta, nella sua zona.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/lib/gestionale/lettere/lettere.ts:128 «dalla consegna del ${giorno(t.consegna)}» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con la consegna il termine «N giorni dal ricevimento» è contato da lì: «Entro il 18/12/2026: 15 giorni dal ricevimento, contati dalla consegna del 03/12/2026.»</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/lettere/RegistroLettere.vue:23 «Manda di nuovo per PEC, con le cifre di oggi» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una PEC non partita, non consegnata o ferma in partenza si rifà con «Manda di nuovo per PEC, con le cifre di oggi».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Lettere/EmissioneLettere.php:1328 «$dati + ['tipo_indirizzo' => 'pec'] + ($conLaCarta ? ['pec_e_carta' => true] : []));» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con «PEC e carta» la lettera ha due righe: la PEC, con la sua ricevuta, e la stampa da spedire, con lo stesso PDF.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Table2 class="w-4 h-4" /> La tabella dei movimenti</h3>
                        <p class="mb-3">
                            «Movimenti contabili» è la scheda che si apre per prima. La prima riga è «Saldo iniziale esercizio», al primo giorno dell'esercizio;
                            poi i movimenti della persona in questo condominio, in ordine di data. Nello stesso giorno gli addebiti vengono prima dei pagamenti;
                            uno storno fatto lo stesso giorno del movimento che annulla viene dopo di lui.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Data»</strong>: la data del movimento. Se il movimento è stato inserito un altro giorno, sotto c'è «Registrata il» con quel giorno: solo nella pagina, non nella stampa. Sotto ancora il protocollo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Descrizione»</strong>: la causale, la gestione, la rata con la sua scadenza, l'unità e il bollino dello stato della rata.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Addebiti»</strong> e <strong>«Pagamenti»</strong>: il dare e l'avere della persona. <strong>«Saldo»</strong>: il saldo dopo la riga, in rosso un debito, in verde un credito.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La «i» accanto a un addebito mostra il saldo prima, il movimento e il saldo dopo. Di una rata con un debito o un credito da esercizi precedenti mostra anche la quota della spesa, il recupero del debito o lo sconto del credito, e quanto si paga per quella quota.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>La legenda</strong>, in testa alla tabella, spiega le icone del tipo («Emissione», «Incasso», «Storno», «Compensazione», «Rimborso») e i colori dello stato («Saldata», «Parziale», «Non pagata», «Credito»).</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il bollino accanto a una rata è uno di questi: «PAGATA», «PARZIALE», «NON PAGATA», «COMPENSATA», «CREDITO». «STORNATA» segna un incasso o una compensazione stornati, e le righe del loro storno: restano nella tabella, fuori dai totali.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Printer class="w-4 h-4" /> La stampa</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Estratto conto», nel menu «Azioni», apre il PDF in un'altra scheda del browser.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il PDF ha l'esercizio e il giorno della stampa, la persona con il codice fiscale e l'email, le sue unità; le quattro cifre (saldo iniziale, totale addebiti, totale versato, saldo finale); il credito disponibile quando c'è, con la rata che copre; le note di chi risponde in solido e i versamenti per conto di altri; la tabella dei movimenti, con i totali in fondo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Gli addebiti personali ci sono come righe della tabella. Non ci sono la data di registrazione, l'elenco degli addebiti, gli accordi e le lettere.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
