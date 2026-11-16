<script setup lang="ts">
/**
 * Il pulsante quadrato «⋯» di un compito dell'agenda nelle due Inbox, con le risposte: «Fatto, con una nota», «Non serve, con il motivo» (sul bonifico
 * segnalato: «Non serve: rifiuta la segnalazione»), «Ricordamelo il…» e, se ci sono risposte, «Il registro». È lo stesso menu del compito dei sei mesi. Le
 * voci aprono la loro finestra: niente parte da solo. Sul bonifico ancora in verifica «Fatto» è spento, con la frase che dice che cosa fare.
 */
import { computed } from 'vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { MoreHorizontal } from 'lucide-vue-next';
import { vociDelMenu, type CompitoConRisposte, type VoceDelCompito } from '@/lib/inbox/risposteDelCompito';

const props = withDefaults(defineProps<{
    compito: CompitoConRisposte;
    /** `w-8 h-8` nella casella dello studio, `w-7 h-7` nell'Inbox del condominio: come i pulsanti accanto. */
    grande?: boolean;
}>(), { grande: false });
const emit = defineEmits<{ (e: 'scegli', voce: VoceDelCompito): void }>();

const voci = computed(() => vociDelMenu(props.compito));
const risposte = computed(() => voci.value.filter((v) => v.tipo !== 'registro'));
const registro = computed(() => voci.value.find((v) => v.tipo === 'registro') ?? null);
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
                :data-testid="`voce-${v.tipo}`" @select="emit('scegli', v.tipo)">
                <span>{{ v.etichetta }}</span>
                <span v-if="v.spenta" class="text-xs font-normal leading-snug text-slate-500 dark:text-slate-400">{{ v.spenta }}</span>
            </DropdownMenuItem>
            <template v-if="registro">
                <DropdownMenuSeparator />
                <DropdownMenuItem class="text-xs" data-testid="voce-registro" @select="emit('scegli', 'registro')">{{ registro.etichetta }}</DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
