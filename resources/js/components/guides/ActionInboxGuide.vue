<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida dell'Action inbox, nella finestra che si apre di lato: che cosa raccoglie la pagina, le sei card e la regola di «scaduto», i due grafici,
 * l'elenco con le schede, la ricerca e l'ordine, la prossima azione e le risposte, i sei mesi per agire, «Dove si accumulano». Accanto a ogni frase, nel
 * sorgente, la riga di codice che la prova (`prova: file:riga «testo»`): la prova della guida controlla che ci sia.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChartBar, ChevronRight, Hourglass, Inbox, LayoutGrid, ListChecks, MapPin, MousePointerClick } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-action-inbox">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-indigo-100 text-indigo-700 rounded-lg dark:bg-indigo-900 dark:text-indigo-300">
                            <Inbox class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Action inbox</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        I compiti aperti dello studio in una pagina: che cosa aspetta, da quanto, dove, e la prossima azione di ognuno.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Inbox class="w-4 h-4" /> A che cosa serve</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/TipiDeiCompiti.php:36 «'emissione_rata' => ['nome' => 'Emissione rate'» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>L'Action inbox raccoglie i compiti aperti di tutti i condomini: le rate da emettere e da incassare, le fatture da pagare, i bonifici segnalati dal portale, le segnalazioni di guasto, le scadenze.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/dashboard/ActionInbox.vue:199 «@scegli="(id) => vai({ condominio_id: id === null ? null : Number(id) })"» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Il condominio che scegli in alto vale per <strong>tutta la pagina</strong>: le card, i grafici, l'elenco, i sei mesi e «Dove si accumulano».</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/dashboard/ActionInbox.vue:119 «router.get(route('admin.inbox'), parametri(nuovi), {» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Ogni scelta (una card, una barra, una scheda, la ricerca, l'ordine) resta nell'indirizzo della pagina: lo puoi salvare fra i preferiti o mandare a un collaboratore.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><LayoutGrid class="w-4 h-4" /> Le sei card</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/FasceDeiCompiti.php:47 «'prossimi_7' => [0, 8],» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Scaduti» conta i compiti con il giorno prima di oggi, nel tuo giorno e non nell'ora del server: <strong>un compito di oggi non è scaduto</strong>, è fra i «Prossimi 7 giorni», e la riga dice oggi.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/lib/inbox/actionInbox.ts:113 «return c.entro_30 > 0 ? `poi» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Sotto «Scaduti» c'è il giorno del più vecchio; sotto «Prossimi 7 giorni» quanti compiti arrivano poi entro 30 giorni.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/FasceDeiCompiti.php:40 «public const A_PARTE = [self::SEGNALAZIONE => self::SEGNALAZIONI, self::BONIFICO => self::BONIFICI, self::MODERARE => self::DA_MODERARE];» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Bonifici segnalati» sono i pagamenti che i condòmini segnalano dal portale, da verificare: stanno a parte, né fra gli scaduti né fra quelli in arrivo.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/CompitoDellaSegnalazione.php:46 «public const APERTE = ['aperta', 'in lavorazione'];» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Segnalazioni» sono i guasti aperti o in lavorazione; sotto il numero, quanti sono urgenti. Ogni segnalazione ha il suo compito, che si chiude quando chiudi la segnalazione e torna se la riapri.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/FasceDeiCompiti.php:40 «public const A_PARTE = [self::SEGNALAZIONE => self::SEGNALAZIONI, self::BONIFICO => self::BONIFICI, self::MODERARE => self::DA_MODERARE];» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Una segnalazione non ha una scadenza, e non scade: non è fra gli scaduti né fra quelli in arrivo. La riga dice quando è stata aperta, e da quanti giorni in grigio; l'urgenza la dice il cartellino della priorità.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/lib/inbox/actionInbox.ts:443 «if (carica.inCorso) return `calcolo» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Sei mesi» conta le righe da seguire adesso e i rendiconti da portare in assemblea; si completa mentre i condomini si calcolano, uno alla volta. Un clic apre il cassetto.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/CompitoDaModerare.php:57 «public const TIPO = 'da_moderare';» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Da moderare» conta ciò che aspetta un'approvazione: i commenti e gli allegati di una segnalazione o di una comunicazione, e le comunicazioni, i documenti e gli eventi dell'agenda da approvare. Ogni elemento ha un compito solo, che dice i numeri veri (per esempio 2 commenti in attesa) e si chiude da solo quando non resta niente in attesa; non ha una scadenza, e non conta fra gli scaduti.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/CompitoDaModerare.php:104 «public static function azioniCheSaFare(?User $utente): array» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Un compito da moderare lo vede, e lo chiude, solo chi può fare la sua prossima azione: per esempio chi può approvare i commenti di quel tipo, se il compito dice di moderare i commenti.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/dashboard/ActionInbox.vue:128 «const alterna = » --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Un clic su una card filtra l'elenco; un altro clic sulla stessa card toglie il filtro.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ChartBar class="w-4 h-4" /> I due grafici</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/lib/inbox/actionInbox.ts:283 «const base: Record<string, string> = { oltre_90: colori.rosso» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Da quanto aspettano»: rosse le barre dei compiti scaduti, indaco quelle dei compiti in arrivo, con il numero sopra ogni barra.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/lib/inbox/actionInbox.ts:435 «return `${verbo} nel grafico:» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Il grafico conta i compiti con una scadenza: le segnalazioni aperte, i bonifici da verificare, ciò che è da moderare e i compiti fra più di 30 giorni non ci sono, e una riga sotto lo dice con il loro numero.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/RiepilogoDellInbox.php:92 «->sortBy([['valore', 'desc'], ['etichetta', 'asc']])» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Per tipo»: una barra per tipo di compito, i tipi con più compiti prima. Al più 8 barre: con più tipi, sotto il grafico un collegamento apre la finestra con tutti, e un clic su un tipo filtra come una barra.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/dashboard/ActionInbox.vue:130 «const scegliFascia = » --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Un clic su una barra filtra l'elenco; un altro clic sulla stessa barra toglie il filtro.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> L'elenco dei compiti</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/lib/inbox/actionInbox.ts:59 «{ chiave: 'in_arrivo', etichetta: 'In arrivo', quando: 'in_arrivo' },» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Le schede «Tutti», «Scaduti» e «In arrivo» hanno accanto il loro numero.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/dashboard/ActionInbox.vue:256 «data-testid="filtro-le-mie"» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Accanto alle schede, <strong>«Le mie»</strong> lascia solo i compiti delle segnalazioni assegnate a te, con il loro numero; sotto il titolo di ogni segnalazione c'è chi la segue: «assegnata a te», il nome, o «non assegnata».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/ElencoDellInbox.php:205 «$quante = $numero <= self::TUTTE_FINO_A ? $numero : self::MOSTRATE_OLTRE;» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Con «Tutti» ogni gruppo mostra fino a 8 compiti; oltre, i primi 5 e quanti ne restano: un clic lì apre la scheda del gruppo.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/ElencoDellInbox.php:36 «public const PER_PAGINA = 15;» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Nelle schede i compiti arrivano 15 alla volta, con «Mostra altri» in fondo (15, o quanti ne restano) e quanti ne vedi su quanti sono.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/ElencoDellInbox.php:242 «->orWhereHas('anagrafiche'» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>La ricerca trova un compito dal titolo, dalla descrizione, dal nome del condominio o della persona.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/lib/inbox/actionInbox.ts:66 «{ valore: 'condominio', etichetta: 'Per condominio' },» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>L'ordine: «I più vecchi prima», «I più recenti prima» o «Per condominio».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/FasceDeiCompiti.php:85 «public const GRUPPI = ['scaduti', 'segnalazioni', 'bonifici', 'da_moderare', 'avanti'];» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>In ogni ordine i gruppi restano questi, uno dopo l'altro: «Scaduti», «Segnalazioni aperte», «Bonifici da verificare», «Da moderare», «Più avanti».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/ElencoDellInbox.php:227 «WHEN 'urgente' THEN 0» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>A pari giorno le segnalazioni urgenti vengono prima, e la riga ha il cartellino della priorità, urgente o alta.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/TipiDeiCompiti.php:267 «'pagamento_fornitore' => $insieme(» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Il titolo è quello del compito senza il nome del condominio, che sta nella sua colonna; sotto, la rata con la cifra, la fattura o il bonifico.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><MousePointerClick class="w-4 h-4" /> La prossima azione</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/TipiDeiCompiti.php:36 «'azione' => 'Emetti la rata'» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Ogni compito ha il pulsante della sua prossima azione, che porta alla pagina giusta: «Emetti la rata», «Registra gli incassi», «Paga la fattura», «Assegna le quote».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/TipiDeiCompiti.php:86 «public const AZIONE_DA_APPROVARE = 'Approva o rifiuta';» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Una segnalazione che aspetta la tua approvazione ha «Approva o rifiuta»; approvata, «Apri la segnalazione».</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/inbox/RigaDelCompito.vue:96 «@click="emit('rispondi', 'fatto')"» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Le ritenute da versare hanno «Prepara l'F24», che apre la pagina delle ritenute e dell'F24 del condominio. Dove il compito non ha una pagina sua (una scadenza generica) il pulsante è «Segna come fatto»: apre la stessa finestra di «Fatto» del menu.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/lib/inbox/risposteDelCompito.ts:65 «? [{ tipo: 'ricordamelo', etichetta: 'Ricordamelo il…' }]» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Il menu «⋯» ha «Fatto, con una nota», «Non serve, con il motivo» e «Ricordamelo il…». Per una segnalazione solo «Ricordamelo il…»: il compito si chiude quando chiudi la segnalazione.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/lib/inbox/risposteDelCompito.ts:55 «const VOCI_DELLE_DELIBERE» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Delibere da eseguire» nasce dalle cose da fare dopo un'assemblea, quando hanno una data: il pulsante è l'azione della cosa, e il menu ha «Segna eseguita, con una nota», «Ricordamelo il…» e «Non serve più, con il motivo». «Delibere respinte» chiede una scelta: «Chiudi con il motivo», o «Riproponi in assemblea» dalla pagina dell'assemblea.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/RispostePromemoria.php:100 «public const TITOLO_DEL_PROMEMORIA_DELLA_SEGNALAZIONE» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Ricordamelo il…» su una segnalazione scrive nell'agenda il promemoria «Riprendi una segnalazione», con il nome del condominio e senza l'oggetto: l'agenda la legge anche chi non apre le segnalazioni, e la segnalazione si legge aprendola.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Http/Controllers/Dashboard/ActionInboxController.php:117 «Gate::authorize('chiudiCompito', Evento::class);» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Rispondere a un compito chiede il permesso «Modifica scadenza in agenda»; senza, vedi i compiti e le loro pagine.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/CompitoDellaSegnalazione.php:65 «if ($utente->checkPermissionTo(Permission::VIEW_SEGNALAZIONI->value)) {» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Le segnalazioni, con il loro titolo e il loro numero, le vede chi le può aprire: con «Visualizza segnalazioni» tutte, private comprese; con «Visualizza proprie segnalazioni» quelle che ha scritto.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Hourglass class="w-4 h-4" /> Sei mesi per agire</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/lib/gestionale/debiti/seiMesiDelloStudio.ts:108 «export const righeAdesso» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Nella colonna di destra le righe da seguire adesso (la data dei sei mesi passata o entro 60 giorni) e i rendiconti da portare in assemblea, con l'azione del caso. Fino a 8 in tutto si vedono tutti; oltre, 5 in tutto, e sotto quanti mancano, che aprono il cassetto.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/seiMesi/SeiMesiColonna.vue:138 «Apri l'elenco <PanelRightOpen» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Apri l'elenco» apre il cassetto, chiuso di serie, con le sezioni «Da seguire adesso», «Rendiconto in assemblea» e «Più avanti».</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/seiMesi/RigaDelloStudio.vue:68 «Apri le persone <ArrowRight» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>In ogni riga «Apri le persone» porta al riquadro del condominio, aperto sulla riga, dove scegli la prossima azione di ogni persona; la freccia mostra le persone, che portano all'estratto conto.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><MapPin class="w-4 h-4" /> Dove si accumulano</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/RiepilogoDellInbox.php:27 «public const DOVE = 5;» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>I cinque condomini con più compiti aperti; un clic sceglie il condominio per tutta la pagina.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/RiepilogoDellInbox.php:30 «public const GENERALE = 'Generale';» --><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>«Generale» raccoglie i compiti che non hanno un condominio.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
