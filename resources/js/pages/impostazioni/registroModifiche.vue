<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il registro delle modifiche dello studio (§14 del progetto della tappa 1; la bozza: «In Impostazioni la stessa pagina per tutti i condomini dello studio,
 * con il condominio fra i filtri»): la card in Impostazioni. Le righe di tutti i condomini e quelle dello studio (utenti, ruoli, impostazioni).
 *
 * Qui si sceglie per quanto tempo si tengono le righe (scena 4 della bozza, decisione 518 (1)): la carta sotto la tabella; la card «Conservazione» la
 * ridice, e si ricarica con una chiave nuova quando la durata cambia o si toglie la sospensione di un condominio eliminato.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import PulsantiDelRegistro from '@/components/registroModifiche/PulsantiDelRegistro.vue';
import RegistroDelleModifiche from '@/components/registroModifiche/RegistroDelleModifiche.vue';
import ConservazioneDelRegistro from '@/components/registroModifiche/ConservazioneDelRegistro.vue';
import RegistroModificheGuide from '@/components/guides/RegistroModificheGuide.vue';
import { cardDelRegistro } from '@/lib/registroModifiche/cardDellaGuida';
import type { BreadcrumbItem } from '@/types';
import type { FiltriDelRegistro, PaginaDelleRighe } from '@/lib/registroModifiche/registro';
import type { StampaDelRegistro } from '@/lib/registroModifiche/stampa';

interface Scelta { valore: string; nome: string }
const props = defineProps<{
    righe: PaginaDelleRighe;
    riepilogo: { mese: { nome: string; modifiche: number; frase: string }; integrita: { stato: 'mai' | 'integro' | 'non_torna'; parola: string; frase: string } };
    scelte: { chi: Scelta[]; aree: Scelta[]; azioni: Scelta[]; mesi: Scelta[]; condomini: Scelta[] };
    filtri: FiltriDelRegistro;
    dello_studio: boolean;
}>();

const mostraGuida = ref(false);
const chiaveDellaConservazione = ref(0);
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Impostazioni', href: '/impostazioni' },
    { title: 'Registro delle modifiche', href: '/impostazioni/registro-modifiche' },
];

/** La stampa: la finestra parte dai filtri della pagina, con il condominio fra i filtri e senza «L'esercizio» (lo studio non ne ha uno). */
const stampa = computed<StampaDelRegistro>(() => ({
    indirizzo: route('impostazioni.registro-modifiche.stampa'),
    conto: route('impostazioni.registro-modifiche.stampa.conto'),
    filtri: props.filtri,
    scelte: props.scelte,
    esercizio: null,
    delloStudio: true,
    luogo: 'Tutti i condomini e lo studio',
}));
</script>

<template>
    <Head title="Registro delle modifiche" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 px-4 py-6">
            <PageHeaderGuide
                page-title="Registro delle modifiche"
                page-subtitle="Chi ha cambiato che cosa e quando, in tutti i condomini e nello studio. Nessuno lo può modificare o cancellare."
                :guides="cardDelRegistro(true)"
                :video-url="null"
                has-text-guide
                text-guide-title="Guida"
                una-fila
                @open-text-guide="mostraGuida = true"
            >
                <template #actions>
                    <PulsantiDelRegistro :stampa="stampa" />
                </template>
            </PageHeaderGuide>

            <RegistroDelleModifiche :righe="props.righe" :riepilogo="props.riepilogo" :scelte="props.scelte" :filtri="props.filtri" :dello-studio="true">
                <template #conservazione>
                    <ConservazioneDelRegistro :key="chiaveDellaConservazione" forma="riquadro" />
                </template>
            </RegistroDelleModifiche>

            <ConservazioneDelRegistro @cambiata="chiaveDellaConservazione++" />
        </div>
        <RegistroModificheGuide v-model:open="mostraGuida" />
    </AppLayout>
</template>
