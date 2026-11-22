<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il registro delle modifiche del condominio (scena 2 della bozza, §14 del progetto della tappa 1): la voce in fondo al sottomenu della struttura. Chi ha
 * cambiato che cosa e quando, in questo condominio: le tre card, i filtri senza ricaricare, la tabella con le pagine, «Verifica l'integrità» e «Stampa»
 * con la sua finestra (il periodo dal … al …, con «L'esercizio» dalle date dell'esercizio del condominio).
 *
 * Accanto a «Stampa» «Sospendi la cancellazione» (decisione 550); nella card «Conservazione» la durata e, se c'è, la sospensione: quando la sospensione
 * cambia, la card si ricarica con una chiave nuova.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import PulsantiDelRegistro from '@/components/registroModifiche/PulsantiDelRegistro.vue';
import RegistroDelleModifiche from '@/components/registroModifiche/RegistroDelleModifiche.vue';
import ConservazioneDelRegistro from '@/components/registroModifiche/ConservazioneDelRegistro.vue';
import RegistroModificheGuide from '@/components/guides/RegistroModificheGuide.vue';
import { cardDelRegistro } from '@/lib/registroModifiche/cardDellaGuida';
import { usePermission } from '@/composables/permissions';
import type { Building } from '@/types/buildings';
import type { FiltriDelRegistro, PaginaDelleRighe } from '@/lib/registroModifiche/registro';
import { periodoDellEsercizio, type EsercizioDellaStampa, type StampaDelRegistro } from '@/lib/registroModifiche/stampa';

interface Scelta { valore: string; nome: string }
const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: any; esercizi: EsercizioDellaStampa[];
    righe: PaginaDelleRighe;
    riepilogo: { mese: { nome: string; modifiche: number; frase: string }; integrita: { stato: 'mai' | 'integro' | 'non_torna'; parola: string; frase: string } };
    scelte: { chi: Scelta[]; aree: Scelta[]; azioni: Scelta[]; mesi: Scelta[]; condomini: Scelta[] };
    filtri: FiltriDelRegistro;
    dello_studio: boolean;
}>();

const { generatePath, generateRoute } = usePermission();
const mostraGuida = ref(false);
const chiaveDellaConservazione = ref(0);

/** La sospensione e la sua fine sono righe del registro: la card la ridice, e le righe e il conto del mese si rileggono senza ricaricare la pagina. */
function quandoCambiaLaSospensione() {
    chiaveDellaConservazione.value++;
    router.reload({ only: ['righe', 'riepilogo'] });
}

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Struttura', href: generatePath('gestionale/:condominio/struttura', { condominio: props.condominio.id }) },
    { title: 'Registro delle modifiche' },
]);

/** La stampa: la finestra parte dai filtri della pagina, chiede il conto e apre il PDF; «L'esercizio» con le date dell'esercizio del condominio. */
const stampa = computed<StampaDelRegistro>(() => ({
    indirizzo: route(generateRoute('gestionale.registro-modifiche.stampa'), { condominio: props.condominio.id }),
    conto: route(generateRoute('gestionale.registro-modifiche.stampa.conto'), { condominio: props.condominio.id }),
    filtri: props.filtri,
    scelte: props.scelte,
    esercizio: periodoDellEsercizio(props.esercizio),
    esercizi: props.esercizi ?? [],
    delloStudio: false,
    luogo: props.condominio.nome,
}));
</script>

<template>
    <Head title="Registro delle modifiche" />
    <GestionaleLayout>
        <div class="space-y-4 px-6 py-8">
            <PageHeaderGuide
                page-title="Registro delle modifiche"
                page-subtitle="Chi ha cambiato che cosa e quando, in questo condominio. Nessuno lo può modificare o cancellare."
                :guides="cardDelRegistro(false)"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :condominio="props.condominio"
                :condomini="props.condomini"
                has-text-guide
                text-guide-title="Guida"
                una-fila
                @open-text-guide="mostraGuida = true"
            >
                <template #actions>
                    <!-- «Stampa» con la sua finestra, e il menu «Registro» (decisione 570): la verifica e la sospensione del condominio. -->
                    <PulsantiDelRegistro
                        :stampa="stampa"
                        :condominio-id="props.condominio.id"
                        :condominio-nome="props.condominio.nome"
                        @cambiata="quandoCambiaLaSospensione"
                    />
                </template>
            </PageHeaderGuide>

            <div class="w-full">
                <StrutturaLayout>
                    <RegistroDelleModifiche :righe="props.righe" :riepilogo="props.riepilogo" :scelte="props.scelte" :filtri="props.filtri" :dello-studio="false">
                        <template #conservazione>
                            <ConservazioneDelRegistro :key="chiaveDellaConservazione" forma="riquadro" :condominio-id="props.condominio.id" />
                        </template>
                    </RegistroDelleModifiche>
                </StrutturaLayout>
            </div>
        </div>
        <RegistroModificheGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
