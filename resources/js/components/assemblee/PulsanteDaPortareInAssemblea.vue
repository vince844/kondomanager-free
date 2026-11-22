<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il pulsante «Da portare in assemblea» (§3 P8; scena 1 della bozza approvata con la decisione 501): sta dove nasce qualcosa da decidere (la ratifica, la
 * fattura, il piano in bozza o d'urgenza, la segnalazione, l'accordo da autorizzare, lo sblocco del fondo, il menu dei compiti, la testata del condominio) e
 * apre la finestra veloce con il punto proposto dal contesto. Lo vede solo chi gestisce le assemblee. Salvato il memo, un messaggio lo dice.
 */
import { computed, ref } from 'vue';
import { CalendarPlus } from 'lucide-vue-next';
import FinestraDelMemo from '@/components/assemblee/FinestraDelMemo.vue';
import { useToast } from '@/components/ui/toast';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';

const props = withDefaults(defineProps<{
    condominioId: number;
    origineTipo?: string | null;
    origineId?: number | null;
    testo?: string | null;
    /** `pulsante` come gli altri della finestra; `riga` per una voce di menu o un pulsante piccolo; `pieno` a tutta larghezza (il telefono). */
    forma?: 'pulsante' | 'riga' | 'pieno';
    etichetta?: string;
}>(), { origineTipo: null, origineId: null, testo: null, forma: 'pulsante', etichetta: 'Da portare in assemblea' });
const emit = defineEmits<{ (e: 'salvato', frase: string): void }>();

const { hasPermission } = usePermission();
const { toast } = useToast();
const gestisce = computed(() => hasPermission([Permission.MANAGE_ASSEMBLEE]));
const aperta = ref(false);

function salvato(frase: string) {
    toast({ title: 'Da portare in assemblea', description: frase });
    emit('salvato', frase);
}

/** Apre la finestra: la usa chi mette il pulsante in un menu. */
function apri() {
    aperta.value = true;
}
defineExpose({ apri });

const classi = computed(() => ({
    pulsante: 'inline-flex h-9 items-center justify-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800',
    riga: 'inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800',
    pieno: 'inline-flex h-10 w-full items-center justify-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200',
}[props.forma]));
</script>

<template>
    <template v-if="gestisce">
        <button type="button" :class="classi" data-testid="da-portare-in-assemblea" @click="aperta = true">
            <CalendarPlus class="h-4 w-4" /> {{ etichetta }}
        </button>
        <FinestraDelMemo v-model:open="aperta" :condominio-id="condominioId" :origine-tipo="origineTipo" :origine-id="origineId" :testo="testo" @salvato="salvato" />
    </template>
</template>
