<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il pulsante quadrato «⋯» di un compito dell'agenda nelle due Inbox, con le risposte: «Fatto, con una nota», «Non serve, con il motivo» (sul bonifico
 * segnalato: «Non serve: rifiuta la segnalazione»), «Ricordamelo il…» e, se ci sono risposte, «Il registro». È lo stesso menu del compito dei sei mesi. Le
 * voci aprono la loro finestra: niente parte da solo. Sul bonifico ancora in verifica «Fatto» è spento, con la frase che dice che cosa fare.
 *
 * Le assemblee (1.12.0-beta.13, P8 e P10): in fondo a ogni compito di un condominio «Portalo in assemblea», che apre la finestra del memo con il compito
 * come origine; il compito del memo ha «Apri …» (la scheda da cui nasce), «Ricordamelo il…» e «Non serve più», senza «Fatto».
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import FinestraDelMemo from '@/components/assemblee/FinestraDelMemo.vue';
import { useToast } from '@/components/ui/toast';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { MoreHorizontal } from 'lucide-vue-next';
import { eUnMemoDellAssemblea, vociDelMenu, type CompitoConRisposte, type VoceDelCompito } from '@/lib/inbox/risposteDelCompito';

const props = withDefaults(defineProps<{
    compito: CompitoConRisposte;
    /** `w-8 h-8` nella casella dello studio, `w-7 h-7` nell'Inbox del condominio: come i pulsanti accanto. */
    grande?: boolean;
}>(), { grande: false });
const emit = defineEmits<{ (e: 'scegli', voce: VoceDelCompito): void }>();

const voci = computed(() => vociDelMenu(props.compito));
const risposte = computed(() => voci.value.filter((v) => v.tipo !== 'registro'));
const registro = computed(() => voci.value.find((v) => v.tipo === 'registro') ?? null);

// «Portalo in assemblea»: per chi gestisce le assemblee, sui compiti di un condominio (non sul compito del memo, che lo è già).
const { hasPermission } = usePermission?.() ?? { hasPermission: () => false };
const { toast } = useToast();
const portalo = computed(() => !!props.compito.condominio_id && !eUnMemoDellAssemblea(props.compito)
    && typeof hasPermission === 'function' && hasPermission([Permission.MANAGE_ASSEMBLEE]));
const finestraDelMemo = ref(false);

function scegli(v: VoceDelCompito) {
    if (v === 'apri' && props.compito.context?.action_url) {
        router.visit(props.compito.context.action_url);
        return;
    }
    emit('scegli', v);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button type="button" title="Le risposte al compito" aria-label="Le risposte al compito" data-testid="menu-del-compito"
                class="inline-flex shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 shadow-sm transition-all hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:hover:text-slate-200"
                :class="grande ? 'w-8 h-8' : 'w-7 h-7'">
                <MoreHorizontal class="w-4 h-4" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-60">
            <DropdownMenuItem v-for="v in risposte" :key="v.tipo" class="text-xs" :class="{ 'flex-col items-start': v.spenta }" :disabled="!!v.spenta"
                :data-testid="`voce-${v.tipo}`" @select="scegli(v.tipo)">
                <span>{{ v.etichetta }}</span>
                <span v-if="v.spenta" class="text-xs font-normal leading-snug text-slate-500 dark:text-slate-400">{{ v.spenta }}</span>
            </DropdownMenuItem>
            <template v-if="registro">
                <DropdownMenuSeparator />
                <DropdownMenuItem class="text-xs" data-testid="voce-registro" @select="emit('scegli', 'registro')">{{ registro.etichetta }}</DropdownMenuItem>
            </template>
            <template v-if="portalo">
                <DropdownMenuSeparator />
                <DropdownMenuItem class="text-xs" data-testid="voce-assemblea" @select="finestraDelMemo = true">Portalo in assemblea</DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
    <FinestraDelMemo v-if="portalo" v-model:open="finestraDelMemo" :condominio-id="compito.condominio_id!" origine-tipo="compito" :origine-id="compito.id"
        @salvato="(frase: string) => toast({ title: 'Da portare in assemblea', description: frase })" />
</template>
