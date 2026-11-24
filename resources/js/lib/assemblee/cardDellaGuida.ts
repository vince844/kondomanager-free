// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le tre card della guida in testa all'archivio delle assemblee, come nelle altre pagine e nel registro delle modifiche: che cosa c'è nell'archivio, le
 * riunioni di carta registrate a mano, la raccolta delle date già scritte a mano nelle schede. E le tre di «Prepara l'assemblea»: che cosa prepari, le date
 * e la legge, che cosa succede dopo; accanto a ogni frase la riga di codice che la prova (la prova delle card controlla che ci sia).
 */
import { BookOpen, Vote, CalendarCheck, CalendarSearch, ClipboardList, FileSignature, Landmark, PenLine, Route, UserCheck, WifiOff } from 'lucide-vue-next';
import type { GuideItem } from '@/components/PageHeaderGuide.vue';

export function cardDellArchivio(): GuideItem[] {
    return [
        {
            title: 'Che cosa c\'è',
            description: 'Tutte le assemblee del condominio: quelle che prepari nel programma e quelle già tenute, con l\'ordine del giorno e le delibere. Da qui le pagine dei piani rate, delle fatture e delle dispense scelgono la delibera giusta.',
            icon: Landmark,
            colorVariant: 'blue',
        },
        {
            title: 'Le riunioni di carta',
            description: 'Un\'assemblea tenuta senza il programma, anche anni fa, si registra con «Registra una delibera a mano»: il giorno, che cosa ha deciso, l\'esito e il numero del verbale. Il verbale firmato lo carichi anche dopo: fino ad allora la riunione dice «Verbale da caricare».',
            icon: FileSignature,
            colorVariant: 'emerald',
        },
        {
            title: 'Le date scritte a mano',
            description: 'Le date di delibera già scritte nei piani rate, nelle dispense, negli accordi e nelle spese impreviste si raccolgono in riunioni registrate a mano. Niente cambia finché non confermi, e una data da controllare sul verbale non è spuntata.',
            icon: CalendarSearch,
            colorVariant: 'amber',
        },
    ];
}

/** Le tre card della guida in testa al registro dei verbali (decisione 657): il registro, la copia firmata con la pagina per il libro, le nomine. */
export function cardDelRegistro(): GuideItem[] {
    return [
        {
            title: 'Il registro',
            description: 'I verbali registrati, in ordine di data, ognuno con il suo numero. Il numero non si riusa: un verbale annullato resta nel registro, con il perché.',
            icon: BookOpen,
            colorVariant: 'blue',
        },
        {
            title: 'La copia firmata',
            description: 'Ogni verbale ha la sua copia firmata e la sua pagina per il libro di carta. «Stampa la pagina» la prepara, «Segna incollata nel libro» ricorda che l\'hai incollata.',
            icon: FileSignature,
            colorVariant: 'emerald',
        },
        {
            title: 'Le nomine',
            description: 'In «Nomina e revoca» c\'è chi è l\'amministratore e da quando. Una delibera di nomina propone la riga: la confermi tu, o la correggi.',
            icon: UserCheck,
            colorVariant: 'amber',
        },
    ];
}

/** Le tre card corte in testa alla pagina del verbale (decisione 659): è un foglio di lavoro, l'editor deve stare in alto. */
export function cardDelVerbale(): GuideItem[] {
    return [
        { title: 'Il testo', description: 'Si scrive qui e si salva da solo. I dati della sala li mette il programma.', icon: PenLine, colorVariant: 'blue' },
        { title: 'Senza rete', description: 'Questo computer tiene il testo finché la rete torna. Poi si manda da solo.', icon: WifiOff, colorVariant: 'amber' },
        { title: 'La registrazione', description: 'Il numero del registro e la copia firmata. Dopo, il testo non cambia.', icon: FileSignature, colorVariant: 'emerald' },
    ];
}

/** Le tre card corte in testa alla sala (decisione 660), come nel verbale: la sala è un foglio di lavoro, il contenuto deve stare in alto. */
export function cardDellaSala(): GuideItem[] {
    return [
        { title: 'Le presenze', description: 'Chi entra, esce, delega o si collega, con l\'ora. La sala conta da sola.', icon: UserCheck, colorVariant: 'blue' },
        { title: 'I voti', description: 'Ogni punto con la sua maggioranza: la sala dice se basta. L\'esito lo conferma il presidente.', icon: Vote, colorVariant: 'emerald' },
        { title: 'Senza rete', description: 'Questo computer tiene la sala finché la rete torna. Poi si manda da sola.', icon: WifiOff, colorVariant: 'amber' },
    ];
}

export function cardDellaPreparazione(): GuideItem[] {
    return [
        {
            title: 'Che cosa prepari qui',
            // prova: resources/js/components/assemblee/DateDellAssemblea.vue:125 «<span :class="etichetta">Dove</span>»
            // prova: resources/js/pages/gestionale/assemblee/Prepara.vue:292 «La tabella del valore»
            // prova: resources/js/pages/gestionale/assemblee/Prepara.vue:313 «data-testid="aggiungi-punto" @click="aggiungiUnPunto"»
            // prova: resources/js/pages/gestionale/assemblee/Prepara.vue:323 «Non spuntato: resta per la prossima assemblea.»
            description: 'Le date, dove si tiene, la tabella del valore e l\'ordine del giorno: i punti che il programma propone e quelli che scrivi tu, con «Aggiungi un punto». Ciò che non spunti resta per la prossima assemblea.',
            icon: ClipboardList,
            colorVariant: 'blue',
        },
        {
            title: 'Le date e la legge',
            // prova: resources/js/lib/assemblee/date.ts:15 «FRASE_STESSO_GIORNO = 'La seconda convocazione non può tenersi nello stesso giorno della prima»
            // prova: resources/js/lib/assemblee/date.ts:17 «FRASE_OLTRE_DIECI = 'La seconda convocazione va fissata non oltre dieci giorni dalla prima.'»
            // prova: resources/js/components/assemblee/DateDellAssemblea.vue:151 «Altre date di prosecuzione con lo stesso avviso»
            description: 'La seconda convocazione va in un altro giorno, dopo la prima e non oltre dieci giorni (art. 1136 c. 3 c.c.): il programma lo controlla mentre scrivi. Le date di prosecuzione stanno nello stesso avviso (art. 66 c. 5 disp. att.).',
            icon: CalendarCheck,
            colorVariant: 'amber',
        },
        {
            title: 'Dopo «Prepara l\'assemblea»',
            // prova: app/Services/Assemblee/PassiDellAssemblea.php:73 «resta in preparazione: niente parte ai condòmini.'»
            // prova: resources/js/pages/gestionale/assemblee/Prepara.vue:273 «Fino alla convocazione cambi tutto.»
            // prova: app/Http/Controllers/Assemblee/AssembleeController.php:130 «'scheda' => $fatto ? 'documenti' : 'date'»
            // prova: app/Services/Assemblee/PassiDellAssemblea.php:79 «quando il primo passo è fatto: le date valgono»
            // prova: resources/js/pages/gestionale/assemblee/Assemblea.vue:421 «data-testid="documenti-visti"»
            description: 'L\'assemblea resta in preparazione: niente parte ai condòmini, e date e punti si cambiano ancora. Poi la pagina dell\'assemblea apre «Documenti» se le date valgono e c\'è almeno un punto, altrimenti «Date e ordine del giorno»; «Convocazione» con «Avanti» dai documenti.',
            icon: Route,
            colorVariant: 'emerald',
        },
    ];
}
