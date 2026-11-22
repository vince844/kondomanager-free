<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida del seguito delle delibere (il passo 6 «Delibere eseguite» e la riunione tenuta su carta), nel cassetto che si apre di lato, a schema: «Che cosa
 * cambia nel gestionale» e il perché della conferma; ciò che si applica adesso e ciò che resta da fare; approvata con modifiche, respinta, rinviata, senza
 * voto; «Segna eseguita»; «Eseguita» e «Da eseguire»; i trenta giorni e «Resta la nullità»; impugnata, annullata, revocata, che non fermano niente. Ogni
 * frase porta, accanto nel sorgente, la riga di codice che la prova (`prova: file:riga «testo»`): la prova della guida controlla che ci sia.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CheckCircle2, ChevronRight, ClipboardList, FileSignature, Gavel, ListChecks, ShieldCheck, Trash2 } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl" data-testid="guida-delibere">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-blue-100 p-2 text-blue-700 dark:bg-blue-900 dark:text-blue-300"><Gavel class="h-6 w-6" /></div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: le delibere eseguite</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Dopo l'assemblea, che cosa cambia nei moduli e che cosa resta da fare. Il programma propone; niente cambia senza il tuo clic.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><ListChecks class="h-4 w-4" /> Che cosa cambia nel gestionale</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/PassiDellAssemblea.php:132 «'riga_se_chiuso'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il passo 6 si apre quando il verbale sottoscritto è registrato; prima ha il lucchetto.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/RiepilogoDelSeguito.vue:124 «Tutto spuntato di serie: togli ciò che non» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il riepilogo è tutto spuntato di serie: togli ciò che non vuoi adesso.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/SeguitoDelleDelibere.php:42 «R1, mai in silenzio» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Niente cambia in silenzio: i moduli cambiano solo con «Conferma».</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/SeguitoDelleDelibere.php:444 «una transazione per riga» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il programma ricalcola ogni riga alla conferma: una riga che non va non ferma le altre, e dice il perché.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/RiepilogoDelSeguito.vue:246 «data-testid="conto"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>In fondo il conto segue le spunte: cambi nei conti, cose da fare, comunicazioni da rileggere.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/RiepilogoDelSeguito.vue:140 «data-testid="resta-fuori"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Ciò che resta fuori sta nel riquadro arancio, con la differenza e «Apri il piano».</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><ClipboardList class="h-4 w-4" /> Adesso, o da fare</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/RigaDelSeguito.php:37 «public static function applica» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Si applica adesso»: la scheda cambia alla conferma, per la sua strada di sempre.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/RiepilogoDelSeguito.vue:221 «Con la data diventa un compito dell'Action inbox» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Che cosa resta da fare»: con la data diventa un compito dell'Action inbox; senza, resta nel passo e nella dashboard.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/RiepilogoDelSeguito.vue:224 «Un'altra cosa da fare</button>» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Un'altra cosa da fare» aggiunge ciò che il programma non vede, come la firma del contratto.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/Effetti/SulPunto.php:38 «public const AVVISO_DI_SERIE» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Avvisa i condòmini» è acceso di serie per le cose di tutti: riscaldamento, regolamento, lavori, innovazioni.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/Effetti/SulPunto.php:40 «MateriaPunto::SITO_INTERNET];» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Acceso anche per la videosorveglianza, il sito internet, i progetti del territorio e le innovazioni agevolate.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/Effetti/SulPunto.php:43 «public const AVVISO_MAI» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Non c'è dove porterebbe un nome: accordi, dispense, ratifiche, liti, tutela della destinazione d'uso, nomina.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/AvvisoAiCondomini.php:32 «public static function precompilata» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>La comunicazione nasce già scritta, come bozza: la rileggi e la pubblichi tu.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Gavel class="h-4 w-4" /> Gli esiti</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/RiepilogoDelSeguito.vue:181 «Che cosa ha cambiato l'assemblea</label>» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Approvata con modifiche: scrivi «Che cosa ha cambiato l'assemblea»; la scheda si corregge prima, poi «Applica».</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/TabellaDelleDelibere.vue:170 «data-testid="riproponi"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Respinta: nessuna conseguenza nei conti; scegli «Riproponi in assemblea» o «Chiudi con il motivo».</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/Effetti/SulPunto.php:63 «RigaDelSeguito::senzaSpunta($c, 'nota', 'rinviata', 'Rinviata'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Rinviata: il punto torna nella memoria del condominio, per la prossima assemblea.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/RiepilogoDelSeguito.vue:173 «Portalo alla prossima assemblea</button>» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Varie e punti senza voto: «Portalo alla prossima assemblea», come un tuo memo.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><CheckCircle2 class="h-4 w-4" /> Eseguita, o da eseguire</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/StatoDiEsecuzione.php:55 «'parola' => 'Da eseguire'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Da eseguire» finché una cosa è aperta; «Eseguita» quando ogni cosa è fatta o non serve più.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/FinestraDellaCosa.vue:160 «PDF o immagine, fino a 10 MB» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Segna eseguita» per ciò che fai fuori dal programma: il giorno, la nota, il documento se vuoi.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/CoseDaFare.php:37 «R5, la cosa si chiude da sola» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Ciò che si fa nel programma si chiude da solo: salvato il piano con la delibera, «Crea il piano straordinario» è fatta.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/lib/assemblee/seguito.ts:178 «'Solo per una riga tolta dal riepilogo'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Applica» serve solo per una riga tolta dal riepilogo.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/TabellaDelleDelibere.vue:168 «data-testid="ferma-il-preventivo"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Preventivo approvato con modifiche: «Ferma il preventivo» sta sulla riga della cosa da fare.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/Effetti/SuiLavori.php:46 «public const LEGGE_DEL_FONDO» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il fondo speciale dice quanto chiedono i piani sui lavori deliberati; non blocca niente.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><ShieldCheck class="h-4 w-4" /> I trenta giorni e i segni</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/SeguitoDelleDelibere.php:405 «'Passati per tutti dal '» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Trenta giorni»: il giorno da cui sono passati per tutti. Resta la nullità, che non ha termine.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/SeguitoDelleDelibere.php:394 «public static function fraseDeiTrentaGiorni» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Finché il verbale non è consegnato a tutti, la data non c'è.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/FinestraDelSegno.vue:154 «l'impugnazione non sospende l'esecuzione» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Segna impugnata»: il giorno, chi e l'atto. Non ferma niente (art. 1137 c.c.).</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/FinestraDelSegno.vue:68 «: le rivedi tu.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Segna annullata dal giudice»: il giorno e il provvedimento; le schede che la usano lo dicono.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/FinestraDelSegno.vue:170 «La delibera che la revoca</p>» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Segna revocata»: scegli la delibera successiva che la revoca.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/PassoDelleDelibere.vue:32 «const VERBALE_ANNULLATO = » --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il verbale annullato chiude il passo: le delibere applicate restano, e il passo dice le schede che le usano.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><FileSignature class="h-4 w-4" /> La riunione tenuta su carta</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/Seguito/SeguitoDelleDelibere.php:97 «if ($assemblea->stato === StatoAssemblea::REGISTRATA_A_MANO) {» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Il riepilogo si apre appena segnati gli esiti, nella pagina della riunione.</span></li>
                            <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/assemblee/Assemblea.vue:348 «data-testid="verbale-da-caricare-in-testa">Verbale da caricare» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>«Verbale da caricare» resta in testa finché non carichi il verbale firmato.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/DeliberaInParole.php:84 «return ' (verbale da caricare)';» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Nelle schede le delibere dicono «verbale da caricare» al posto del numero, che compare da solo.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/DeliberaInParole.php:82 «if (! $scritta && $assemblea !== null» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>I testi che restano scritti nelle schede dicono il giorno e il punto: lo sblocco del fondo, la regola, l'addebito, la gestione nata dalla delibera.</span></li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Trash2 class="h-4 w-4" /> «Elimina» con il motivo</h3>
                        <ul class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <li class="flex gap-2"><!-- prova: resources/js/components/assemblee/seguito/EliminaLaDelibera.vue:77 «Le cose da fare che se ne vanno con la delibera» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>La finestra elenca le cose da fare che se ne vanno con la delibera.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/DeliberaAMano.php:282 «Scrivi perché la elimini» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Nella finestra il motivo è sempre obbligatorio.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/DeliberaAMano.php:281 «if ($cose->isNotEmpty() && $motivo === '')» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Una delibera senza cose da fare si elimina senza motivo.</span></li>
                            <li class="flex gap-2"><!-- prova: app/Services/Assemblee/DeliberaAMano.php:322 «private static function fotografia» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Resta tutto nel diario dell'assemblea e nel registro delle modifiche, con chi e quando.</span></li>
                        </ul>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
