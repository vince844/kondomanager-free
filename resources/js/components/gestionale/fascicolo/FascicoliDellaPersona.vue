<script setup lang="ts">
/**
 * Il fascicolo per il legale nella scheda «Corrispondenza» dell'estratto conto della persona, accanto al registro delle lettere: che cosa è, il pulsante che
 * apre la finestra, e i fascicoli già preparati (il giorno, chi, la cifra, e l'indice e il prospetto di quel giorno, conservati fra i documenti della persona:
 * si riaprono da qui, non sono nell'archivio dei documenti né nel portale).
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { FileText, FolderArchive } from 'lucide-vue-next';
import FascicoloLegaleDialog from './FascicoloLegaleDialog.vue';
import { euroTesto } from '@/lib/gestionale/debiti/rateScadute';
import { TITOLO } from '@/lib/gestionale/debiti/fascicoloLegale';

export interface FascicoloPreparato {
    quando: string;
    utente: string;
    cifra: number;
    mancanze: number;
    documenti: Array<{ id: number; nome: string; etichetta: string }>;
}

const props = defineProps<{ condominioId: number; persona: { id: number; nome: string }; fascicoli: FascicoloPreparato[] }>();

const { generateRoute } = usePermission();
const indirizzo = (documento: number) => route(generateRoute('gestionale.anagrafiche.fascicolo-legale.documento'),
    { condominio: props.condominioId, anagrafica: props.persona.id, documento });
const aperta = ref(false);
const dopo = () => router.reload({ only: ['fascicoli'] });
const mancanze = (n: number) => (n === 0 ? 'nessuna mancanza' : (n === 1 ? '1 mancanza' : `${n} mancanze`));
</script>

<template>
    <section class="mb-4 rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-4" data-testid="fascicoli-della-persona">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div class="min-w-0 space-y-0.5">
                <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-widest text-slate-500">
                    <FolderArchive class="h-4 w-4 text-slate-400" /> {{ TITOLO }}
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    L'indice, le rate non versate, gli estratti conto, le lettere con le prove e il rendiconto approvato, in un archivio. Di serie c'è tutto: togli tu
                    ciò che non serve.
                </p>
            </div>
            <Button size="sm" variant="outline" class="h-8 shrink-0 px-3 text-xs" data-testid="prepara-fascicolo" @click="aperta = true">
                Prepara il fascicolo per il legale
            </Button>
        </div>
        <ul v-if="fascicoli.length" class="mt-3 divide-y divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800" data-testid="fascicoli-preparati">
            <li v-for="(f, i) in fascicoli" :key="i" class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <p class="text-xs text-slate-700 dark:text-slate-200">
                    Preparato il {{ f.quando }}<template v-if="f.utente"> da {{ f.utente }}</template> · {{ euroTesto(f.cifra) }} · {{ mancanze(f.mancanze) }}
                </p>
                <div class="flex flex-wrap gap-x-3 gap-y-1">
                    <a v-for="d in f.documenti" :key="d.id" :href="indirizzo(d.id)" target="_blank" rel="noopener" :title="d.nome"
                        class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                        <FileText class="h-3.5 w-3.5" /> {{ d.etichetta }}
                    </a>
                </div>
            </li>
        </ul>
        <FascicoloLegaleDialog v-model:open="aperta" :condominio-id="condominioId" :persona="persona" @fatto="dopo" />
    </section>
</template>
