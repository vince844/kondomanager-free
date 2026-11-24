<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della pagina «Il verbale», nel cassetto che si apre di lato con «Guida»: la discussione in sala, il verbale dal modello e le parti della sala, le
 * cifre che non tornano, il salvataggio e le versioni, senza rete e a computer spento, chi altro è dentro, la bozza, la copia firmata e il numero, la
 * trasmissione e i trenta giorni, la privacy. A schema, una cosa per riga. Accanto a ogni frase, nel sorgente, la riga di codice che la prova
 * (`prova: file:riga «testo»`): la prova della guida controlla che ci sia.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { AlertTriangle, FileSignature, FileText, Lock, MessageSquareText, Save, Send, Users, WifiOff, ChevronRight } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl" data-testid="guida-verbale">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-blue-100 p-2 text-blue-700 dark:bg-blue-900 dark:text-blue-300"><FileText class="h-6 w-6" /></div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: il verbale</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Il verbale si scrive nel programma: la discussione durante l'assemblea, il verbale intero dal modello, la copia firmata, la trasmissione.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><MessageSquareText class="h-4 w-4" /> La discussione in sala</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/DiscussioneDelPunto.vue:50 «Voti, nomi e millesimi li mette il programma: qui solo ciò che si è detto.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Sotto il voto di ogni punto della sala c'è «La discussione»: il segretario scrive ciò che si è detto. Voti, nomi e millesimi li mette il programma.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/DiscussioneDelPunto.vue:52 «La stessa discussione si legge, e si scrive, nella pagina» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>La stessa discussione si legge, e si scrive, nella pagina «Il verbale»: il segretario può tenerla su un altro computer.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/Verbale.vue:242 «data-testid="vai-a"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Vai a», in alto, porta alla sala, al registro dei verbali e all'assemblea.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/Verbale.vue:325 «Ogni punto è una parte a sé: due persone scrivono due punti diversi nello stesso momento.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Ogni punto è una parte a sé: due persone scrivono due punti diversi insieme.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/DichiarazioneDalVerbale.vue:66 «Va anche nella sala, sotto il voto. Niente dati delicati: il verbale lo leggono tutti.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Aggiungi una dichiarazione» la scrive con il nome di chi la fa, e va anche nella sala, sotto il voto (art. 1130 n. 7 c.c.).</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><FileText class="h-4 w-4" /> Il verbale dal modello</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/Verbale.vue:320 «data-testid="genera-il-verbale"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Chiusa la sala, «Genera il verbale dal modello» compone il verbale dal modello che scegli, con i dati della sala e le discussioni.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/GeneraDalModello.vue:58 «Propongo il modello del condominio, se c'è; se no l'ultimo usato dallo studio.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Propone il modello del condominio, se c'è; se no l'ultimo usato. I modelli si scrivono nelle impostazioni, «Modelli delle lettere».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/assemblee/verbale/editor.ts:32 «export const ETICHETTA_DATI_DELLA_SALA = 'Dati della sala';» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Le frasi con i numeri della sala sono segnate «Dati della sala»: si correggono come il resto del testo.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/GeneraDalModello.vue:63 «Il testo di adesso, con le tue correzioni» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Rigenera dal modello» tiene il testo di adesso fra le versioni: lo ritrovi con «Ripristina».</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><AlertTriangle class="h-4 w-4" /> Le cifre che non tornano</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/AvvisoDelleCifre.vue:33 «Hai cambiato una cifra nei dati della sala.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Se una cifra dei dati della sala non torna con i voti registrati, l'avviso lo dice con il punto.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/AvvisoDelleCifre.vue:36 «data-testid="rimetti"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Rimetti» rimette la cifra dei voti; «Vai al voto del punto» apre la sala, dove il voto si corregge fino alla registrazione.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/Verbale.vue:375 «'Una cifra non torna con i voti registrati'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Finché una cifra non torna, o i dati della sala sono cambiati dopo la generazione, «Stampa la bozza da firmare» chiede prima.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/AvvisoDelleCifre.vue:30 «Aggiorna le parti segnate» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Un voto cambiato in sala dopo la generazione: «Aggiorna le parti segnate» rifà solo le parti della sala.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Save class="h-4 w-4" /> Il salvataggio e le versioni</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/lib/senzaRete/testo.ts:27 «export const PAUSA_MS = 3000;» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il testo si salva da solo pochi secondi dopo che smetti di scrivere.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/senzaRete/testo.ts:30 «export const ALMENO_OGNI_MS = 30000;» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Mentre scrivi senza pause si salva almeno ogni 30 secondi.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/VersioniDelVerbale.vue:52 «Una ogni volta che smetti di scrivere per un po', e a ogni gesto importante.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Le versioni»: una ogni volta che smetti di scrivere per un po', e a ogni gesto importante (generato, stampato, registrato). La più recente è in alto.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/VersioniDelVerbale.vue:64 «data-testid="ripristina"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Ripristina» torna a una versione; anche il ripristino è una versione. Al telefono le versioni stanno in un cassetto.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><WifiOff class="h-4 w-4" /> Senza rete e a computer spento</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/lib/senzaRete/testo.ts:24 «export const SUL_DISCO_OGNI_MS = 300;» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Ogni lettera va sul disco del computer al più ogni 300 millesimi di secondo, non solo in memoria.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/senzaRete/verbale.ts:138 «'Non salvato: senza connessione. Lo tengo qui, nel browser.'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Senza rete la scritta dice «Non salvato: senza connessione»: il testo parte da solo quando la rete torna.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/senzaRete/verbale.ts:191 «`Ho ritrovato ${che}» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>A computer spento o con il browser chiuso, riaprendo la pagina il testo c'è, la pagina dice che l'ha ritrovato, e parte appena c'è la rete.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/senzaRete/useVerbaleSenzaRete.ts:388 «const primaDiChiudere» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Se chiudi la pagina con modifiche non ancora partite, il browser te lo chiede prima.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/GestiDelVerbale.php:165 «$aperta = $this->versioneAperta(» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Se due dispositivi scrivono la stessa parte, niente si sovrascrive: restano due versioni, e finché non scegli la parte è ferma.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/senzaRete/scrittore.ts:19 «export const CHIAVE_DELLA_SCHEDA» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Due schede dello stesso browser contano come due dispositivi: anche lì niente si sovrascrive.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/VersioniInAttesa.vue:62 «Il verbale n. {{ verbale.numero }} è la» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Un testo arrivato dopo la registrazione non si applica: resta da parte, «Arrivata dopo la registrazione».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/VersioniInAttesa.vue:76 «data-testid="metti-nel-punto"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Una discussione arrivata dopo la generazione non è nel testo: la metti nel suo punto con un clic, o «Lasciala fra le versioni».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/Verbale.vue:284 «data-testid="rimetti-nell-editor"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Un salvataggio che il server non accetta resta sul computer: «Rimettilo nell'editor».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/senzaRete/persistenza.ts:20 «export const SPAZIO_DI_UNA_FINESTRA_PRIVATA» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il testo resta sul computer finché non arriva al server: non usare la finestra privata, che lo cancella quando si chiude.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Users class="h-4 w-4" /> Chi altro è dentro</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/ChiScrive.vue:51 «Mentre scrivi tu, lo vede in sola lettura» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Scrive una persona alla volta: gli altri leggono il testo che cambia.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/ChiScrive.vue:47 «{{ esito && esito.esito === 'scaduta' ? 'Chiedi di nuovo' : 'Chiedi di scrivere' }}» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Chiedi di scrivere» aspetta 60 secondi la risposta di chi scrive.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/ChiScrive.vue:33 «Se lasci, salvo prima il tuo testo» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Chi lascia non perde niente: il suo testo si salva prima del passaggio.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><FileSignature class="h-4 w-4" /> La bozza, la copia firmata, il numero</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/StatoDelVerbale.vue:99 «data-testid="stampa-la-bozza"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Stampa la bozza da firmare»: falla firmare a presidente e segretario. Il programma non vede la firma.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/Verbale.vue:144 «async function primaArrivaTutto» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>La bozza, la generazione e la registrazione partono solo con il testo scritto qui arrivato tutto.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/RegistrazioneDelVerbale.php:278 «return 'Il testo è cambiato '» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Se il testo o i dati della sala cambiano dopo la bozza stampata, «Registra» si ferma: si ristampa e si fa firmare.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/RegistrazioneDelVerbale.php:316 «public const SENZA_LA_BOZZA» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Senza la bozza stampata, «Registra» chiede prima.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/RegistraIlVerbale.vue:135 «PDF, .p7m o immagine, fino a 10 MB: firmata da presidente e segretario.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Registra il verbale sottoscritto» prende la copia firmata: una scansione, o il PDF firmato digitalmente .p7m.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/RegistroDeiVerbali.php:170 «'Il registro va solo avanti: il prossimo è il '» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il numero è quello dopo l'ultimo del registro: mai uno già usato, né uno più basso.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/RegistroDeiVerbali.php:181 «'? Il registro va solo avanti: un numero saltato non torna.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Un numero più alto salta dei numeri, che non tornano: si conferma il salto.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/VerbaliDellAssemblea.php:40 «AmbitoAssemblea::GRUPPO => $assemblea->tabella_id !== null ? 'gruppo:'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il verbale dell'assemblea dei condòmini di un gruppo va in un registro suo, che parte dal n. 1, come quello della scala; il registro dei verbali del condominio non cambia.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/ComposizioneDelVerbale.php:671 «private static function delGruppo» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il suo verbale dice il gruppo nell'intestazione, e dopo la costituzione una frase sola con chi vota e su quanti: 8 partecipanti per 1.000,00 millesimi.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/SostituisciLaCopia.vue:74 «La copia di prima resta, fra le versioni della copia.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Sostituisci la copia», nel menu con i tre puntini dello stato, cambia il file, con il perché: il numero e il giorno della firma restano.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/StatoDelVerbale.vue:90 «data-testid="segna-incollata"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Registrato il verbale, sotto «La copia firmata» c'è «La pagina per il libro dei verbali di carta»: «Stampa la pagina», poi «Segna incollata nel libro».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/AnnullaLaRegistrazione.vue:63 «non torna: la registrazione giusta prende il numero dopo» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Annulla» chiede il perché: la riga resta «Annullato», e il numero non torna.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Send class="h-4 w-4" /> La trasmissione e i trenta giorni</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/TrasmissioneDelVerbale.vue:126 «'In videoconferenza o mista il verbale va a tutti i convocati.'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il verbale va agli assenti; in videoconferenza o mista, a tutti i convocati.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/TrasmissioneDelVerbale.vue:127 «chi non è mai entrato, e chi era fuori al voto di un punto, per quel punto» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Chi è assente lo propone il programma: chi non è mai entrato, e chi era fuori al voto di un punto, per quel punto.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/TrasmissioneDelVerbale.vue:172 «<span>Chi</span><span>Perché lo riceve</span>» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Ogni persona è una riga: chi, perché lo riceve, il mezzo, lo stato e «Può impugnare» con i punti e il giorno. Al telefono una scheda. «Registra la consegna» sta nel menu della riga.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/TogliDallaLista.vue:69 «La scelta resta nel registro delle modifiche, con il perché.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Lo decidi tu: «Togli dalla lista», nel menu con i tre puntini della riga, e «Aggiungi una persona» mostrano da quando corrono i suoi trenta giorni, e chiedono il perché.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/assemblee/verbale/pagina.ts:16 «export const CALCOLO_PRUDENTE = 'calcolato in modo prudente';» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>I trenta giorni (art. 1137 c. 2 c.c.): contrari e astenuti dalla delibera, assenti dalla consegna; calcolato in modo prudente.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Verbale/DestinatariDelVerbale.php:251 «public const GIORNI_DELLA_GIACENZA = 10;» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>La raccomandata in giacenza: dal ritiro, o dalla compiuta giacenza (dieci giorni dopo l'avviso) se non la ritira.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/TrasmissioneDelVerbale.vue:160 «la copia di prima: rimandala.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Dopo «Sostituisci la copia», chi aveva già la copia di prima la riceve di nuovo con «Rimanda la copia nuova».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/SegnaImpugnata.vue:51 «È un segno sulla delibera, con la data e l'atto: non ferma niente.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Segna impugnata» è un segno sulla delibera, con la data e l'atto: non ferma niente.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Lock class="h-4 w-4" /> La privacy</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/TrasmissioneDelVerbale.vue:128 «in busta o per PEC, mai in bacheca» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il verbale va per persona, in busta o per PEC: mai in bacheca.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/verbale/DichiarazioneDalVerbale.vue:66 «Niente dati delicati: il verbale lo leggono tutti.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Nelle dichiarazioni niente dati delicati: il verbale lo leggono tutti.</span></li>
                        </ul>
                    </section>
                    <section>
                        <h3 class="mb-3 text-lg font-bold text-slate-900 dark:text-white">Un caso</h3>
                        <p class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">Rossi Mario esce alle 19:30. Il punto 4 si vota alle 19:44: per quel punto è assente, riceve il
                            verbale, e il suo termine parte il giorno in cui gli arriva.</p>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
