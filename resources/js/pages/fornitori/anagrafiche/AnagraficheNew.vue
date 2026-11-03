<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Info, UserPlus, ShieldCheck } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import vSelect from "vue-select";
import type { Fornitore } from '@/types/fornitori';
import type { Anagrafica } from '@/types/anagrafiche';
import type { DropdownType } from '@/types/dropdown';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  fornitore: Fornitore;
  anagrafiche: Anagrafica[]
  ruoli: { id: string; label: string }[]
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Fornitori', href: route(generateRoute('fornitori.index')) },
  { title: props.fornitore.ragione_sociale, href: generatePath('fornitori/:fornitore', { fornitore: props.fornitore.id }) },
  { title: 'Rappresentanti', href: generatePath('fornitori/:fornitore/anagrafiche', { fornitore: props.fornitore.id }) },
  { title: 'Associa rappresentante', href: '#' }
];

const pageGuides = computed(() => [
  {
    title: 'Anagrafiche di Riferimento',
    description: 'Aggiungi rappresentanti al fornitore per tenere traccia dei contatti.',
    icon: UserPlus,
    colorVariant: 'blue' as const
  },
  {
    title: 'Assegnazione Ruoli',
    description: 'Definisci il ruolo di ogni rappresentante per chiarire le responsabilità (es. Amministrativo, Tecnico).',
    icon: ShieldCheck,
    colorVariant: 'emerald' as const
  }
]);

// I ruoli arrivano dal server (`RuoloRappresentanteFornitore`): erano scritti a mano qui **e**
// dentro `CreateFornitoreAnagraficaRequest`, e la beta.7 stava per aggiungerne una terza copia
// sulla creazione del fornitore.
const ruoli = computed(() => props.ruoli);

const form = useForm({
  anagrafica_id: '',
  ruolo: ''
});

const submit = () => {
    form.post(route(...generateRoute('fornitori.anagrafiche.store', { fornitore: props.fornitore.id})), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>
  <Head title="Associa anagrafica fornitore" />

  <AppLayout>
    <div class="px-6 py-8 space-y-6">
      
      <PageHeaderGuide
        page-title="Associa rappresentante"
        :page-subtitle="`Aggiungi un nuovo contatto per il fornitore ${props.fornitore.ragione_sociale}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="generatePath('fornitori/:fornitore/anagrafiche', { fornitore: props.fornitore.id })"
        back-text="Torna all'elenco"
      />

      <div class="w-full">
        <FornitoreLayout>

          <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                <CardHeader class="pb-3 border-b border-dashed mb-4">
                    <CardTitle class="text-base font-semibold">Dati Associazione</CardTitle>
                    <CardDescription>Seleziona l'anagrafica e assegna un ruolo specifico.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-6">

                        <div class="sm:col-span-3">
                            <div class="flex items-center min-h-[24px] gap-2 pb-2">
                                <Label for="anagrafica">Anagrafica</Label>

                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="text-slate-400 hover:text-primary outline-none">
                                            <Info class="w-4 h-4" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                                        <h4 class="text-sm font-bold uppercase mb-2">Associare un rappresentante</h4>
                                        <!-- ⚠️ Qui c'era scritto che l'anagrafica collegata a un utente «potrà accedere al
                                             portale online per visualizzare i dati associati a questo fornitore».
                                             Verificato il 30/08/2026: quel portale **non esiste**. Il ruolo `fornitore`
                                             è a database con undici permessi, `anagrafiche.user_id` esiste, ma non c'è
                                             nessun utente con quel ruolo, nessuna strada per crearne uno e nessuna area
                                             riservata. Il testo prometteva una funzione non costruita: ora dice quello
                                             che la schermata fa davvero. -->
                                        <p class="text-xs text-slate-500 leading-relaxed">Puoi associare un'anagrafica al fornitore dichiarandone il ruolo, così si sa a chi rivolgersi e per cosa. La colonna «Accesso login» dell'elenco dice se quella persona ha già un utente del gestionale.</p>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>

                            <v-select
                              class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                              :options="anagrafiche"
                              v-model="form.anagrafica_id"
                              :reduce="(d: Anagrafica) => d.id"
                              label="nome"
                              placeholder="Seleziona anagrafica..."
                            >
                              <template #option="{ nome, indirizzo }">
                                <div class="flex flex-col py-1">
                                  <span class="font-bold text-sm">{{ nome }}</span>
                                  <span class="text-[11px] text-slate-400 italic">{{ indirizzo }}</span>
                                </div>
                              </template>
                              <template #selected-option="{ nome, indirizzo }">
                                <div class="flex items-center gap-2">
                                  <span class="font-medium">{{ nome }}</span>
                                  <span class="text-gray-500 text-sm">– {{ indirizzo }}</span>
                                </div>
                              </template>
                            </v-select>

                            <InputError :message="form.errors.anagrafica_id" class="mt-1" />
                        </div>

                        <div class="sm:col-span-3">
                            <div class="flex items-center min-h-[24px] pb-2">
                                <Label for="ruolo">Ruolo</Label>
                            </div>

                            <v-select
                                class="w-full premium-select bg-white dark:bg-slate-950 mt-1"
                                :options="ruoli"
                                v-model="form.ruolo"
                                label="label"
                                :reduce="(d: DropdownType) => d.id"
                                placeholder="Seleziona ruolo..."
                            />
                            
                            <InputError :message="form.errors.ruolo" class="mt-1" />
                        </div>
                        
                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Link
                    :href="generatePath('fornitori/:fornitore/anagrafiche', { fornitore: props.fornitore.id })"
                    class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
                >
                    Annulla
                </Link>

                <Button 
                    type="submit"
                    :disabled="form.processing" 
                    class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
                >
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    <Plus v-else class="h-4 w-4" />
                    Associa rappresentante
                </Button>
            </div>

          </form>

        </FornitoreLayout>
      </div>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>