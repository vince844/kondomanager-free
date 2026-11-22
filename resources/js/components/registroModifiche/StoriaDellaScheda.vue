<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Storia» di una scheda, tutto insieme: il pulsante dell'intestazione, il cassetto con le righe, la finestra di «Ripristina» (§8 del progetto della
 * tappa 1). Lo monta `PageHeaderGuide` quando la pagina gli dà la prop `storia`; le tre pagine senza `PageHeaderGuide` (l'utente, il ruolo, l'esercizio) lo
 * mettono nella loro intestazione. Il pulsante c'è solo per chi ha «Vede la storia delle schede»; il server controlla di nuovo, e rifiuta i ruoli del
 * portale (P18).
 *
 * **L'oscuramento.** Il cassetto emette `oscura` con `{ soggetto_tipo, soggetto_id, nome }` (solo per l'amministratore, nella «Storia» di una persona o
 * di un fornitore): la persona da oscurare va in `daOscurare` e si apre la finestra dell'oscuramento (§10). Quando la finestra dice che è fatto, il
 * cassetto si ricarica con una chiave nuova, perché le righe oscurate si leggano già senza i dati.
 */
import { computed, ref } from 'vue';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import PulsanteStoria from '@/components/registroModifiche/PulsanteStoria.vue';
import StoriaCassetto from '@/components/registroModifiche/StoriaCassetto.vue';
import FinestraOscuramento from '@/components/registroModifiche/FinestraOscuramento.vue';
import { STORIE_DELLO_STUDIO, type SoggettoDaOscurare } from '@/lib/registroModifiche/registro';

const props = defineProps<{ tipo: string; id?: number | null }>();

const { hasPermission, hasRole } = usePermission();
const visibile = computed(() => hasPermission([Permission.VIEW_STORIA_SCHEDE]) && !hasRole(['utente', 'fornitore'])
    && (!STORIE_DELLO_STUDIO.includes(props.tipo) || hasRole(['amministratore'])));
const aperto = ref(false);
const daOscurare = ref<SoggettoDaOscurare | null>(null);
const finestraOscuramento = ref(false);
const chiaveDellaStoria = ref(0);

function apriOscuramento(soggetto: SoggettoDaOscurare) {
    daOscurare.value = soggetto;
    finestraOscuramento.value = true;
}

function quandoSiChiude(aperta: boolean) {
    finestraOscuramento.value = aperta;
    if (!aperta) daOscurare.value = null;
}

defineExpose({ daOscurare });
</script>

<template>
    <template v-if="visibile">
        <PulsanteStoria @click="aperto = true" />
        <StoriaCassetto :key="chiaveDellaStoria" v-model:open="aperto" :tipo="props.tipo" :id="props.id ?? null" @oscura="apriOscuramento" />
        <FinestraOscuramento
            v-if="daOscurare"
            v-bind="daOscurare"
            :open="finestraOscuramento"
            @update:open="quandoSiChiude"
            @oscurato="chiaveDellaStoria++"
        />
    </template>
</template>
