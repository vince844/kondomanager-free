<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from "@/components/Alert.vue";
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { 
    Wallet, Plus, Trash2, AlertTriangle, 
    Building2, Calendar, Info, Euro,
    ShieldAlert, Scale, ReceiptText
} from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from "@/composables/permissions";
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import type { Flash } from '@/types/flash';
import type { Fornitore } from '@/types/fornitori';
import type { BreadcrumbItem } from '@/types';

interface Debito {
    id: number;
    condominio: string;
    esercizio: string;
    descrizione: string;
    importo: number; // In centesimi dal controller
    data_inserimento: string;
}

interface Condominio {
    id: number;
    nome: string;
}

const props = defineProps<{
    fornitore: Fornitore;
    debiti: Debito[];
    condomini: Condominio[];
}>();

const { euro } = useCurrencyFormatter();
const { generatePath, generateRoute } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const showAddModal = ref(false);

// Form per l'inserimento del debito (Il sistema trasformerà l'importo in negativo nel backend)
const form = useForm({
    condominio_id: null as number | null,
    descrizione: '',
    importo: 0, 
});

const totalDebito = computed(() => {
    return props.debiti.reduce((acc, d) => acc + d.importo, 0);
});

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Fornitori', href: route(generateRoute('fornitori.index')) },
  { title: props.fornitore.ragione_sociale, href: generatePath('fornitori/:fornitore', { fornitore: props.fornitore.id }) },
  { title: 'Situazione Debitoria', href: '#' }
];

const pageGuides = [
  {
    title: 'Double Lock Engine',
    description: 'I debiti inseriti qui alimentano il sistema di copertura patrimoniale durante la registrazione delle fatture.',
    icon: ShieldAlert,
    colorVariant: 'amber' as const
  },
  {
    title: 'Conformità Legale',
    description: 'Ogni pendenza deve corrispondere a quanto dichiarato nello Stato Patrimoniale di apertura del condominio.',
    icon: Scale,
    colorVariant: 'blue' as const
  },
  {
    title: 'Gestione Morosità',
    description: 'Il sistema incrocia questi dati con i saldi dei condòmini per identificare chi non sta coprendo il debito.',
    icon: ReceiptText,
    colorVariant: 'emerald' as const
  }
];

const submit = () => {
    form.post(route('admin.fornitori.situazione-debitoria.store', props.fornitore.id), {
        onSuccess: () => {
            showAddModal.value = false;
            form.reset();
        },
    });
};

const deleteDebito = (id: number) => {
    if (confirm('Rimuovere questa pendenza? L\'operazione inciderà sulla quadratura del bilancio di apertura.')) {
        form.delete(route('admin.fornitori.situazione-debitoria.destroy', [props.fornitore.id, id]));
    }
};
</script>

<template>
  <AppLayout>
    <Head title="Situazione Debitoria Fornitore" />

    <div class="px-6 py-8 space-y-4">
      <div v-if="flashMessage" class="mb-6">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <PageHeaderGuide
        :page-title="`Debiti Pregressi: ${fornitore.ragione_sociale}`"
        :page-subtitle="`Gestione pendenze ereditate da esercizi precedenti · Esposizione Totale: ${euro(totalDebito)}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="generatePath('fornitori/:fornitore', { fornitore: fornitore.id })"
        back-text="Torna ai dettagli"
      >
        <template #actions>
          <Button @click="showAddModal = true" class="gap-2 bg-slate-800 hover:bg-slate-700">
            <Plus class="w-4 h-4" /> Aggiungi Pendenza
          </Button>
        </template>
      </PageHeaderGuide>

      <div class="w-full">
        <FornitoreLayout>
          
          <div class="grid grid-cols-1 gap-6 pb-16">
            
            <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
              <div class="px-5 py-3.5 border-b border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50 flex justify-between items-center">
                <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500">Pendenze Registrate</h2>
                <Badge variant="outline" class="text-[10px] font-black">{{ debiti.length }} Record</Badge>
              </div>

              <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                  <thead>
                    <tr class="border-b border-slate-100 dark:border-slate-800 text-[10px] font-black uppercase tracking-wider text-slate-400">
                      <th class="px-6 py-4">Condominio</th>
                      <th class="px-6 py-4">Esercizio</th>
                      <th class="px-6 py-4">Descrizione / Titolo Debito</th>
                      <th class="px-6 py-4 text-right">Importo Lordo</th>
                      <th class="px-6 py-4 text-center">Azioni</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="debito in debiti" :key="debito.id" class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                      <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                          <Building2 class="w-3.5 h-3.5 text-slate-400" />
                          <span class="text-sm font-bold text-slate-700 dark:text-slate-300">{{ debito.condominio }}</span>
                        </div>
                      </td>
                      <td class="px-6 py-4">
                        <span class="text-xs font-medium text-slate-500 bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded">{{ debito.esercizio }}</span>
                      </td>
                      <td class="px-6 py-4">
                        <div class="flex flex-col">
                          <span class="text-sm text-slate-600 dark:text-slate-400 font-medium">{{ debito.descrizione }}</span>
                          <span class="text-[10px] text-slate-400">Caricato il {{ debito.data_inserimento }}</span>
                        </div>
                      </td>
                      <td class="px-6 py-4 text-right font-black text-rose-600 dark:text-rose-400">
                        {{ euro(debito.importo) }}
                      </td>
                      <td class="px-6 py-4 text-center">
                        <Button variant="ghost" size="icon" @click="deleteDebito(debito.id)" class="opacity-0 group-hover:opacity-100 transition-opacity text-slate-400 hover:text-rose-600">
                          <Trash2 class="w-4 h-4" />
                        </Button>
                      </td>
                    </tr>
                    <tr v-if="debiti.length === 0">
                      <td colspan="5" class="px-6 py-16 text-center">
                        <div class="max-w-xs mx-auto flex flex-col items-center gap-3">
                          <div class="w-12 h-12 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                            <Info class="w-6 h-6 text-slate-300" />
                          </div>
                          <p class="text-sm text-slate-400 italic leading-relaxed">Nessuna pendenza pregressa trovata per questo fornitore nei condomini gestiti.</p>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/30 border-t border-slate-100 dark:border-slate-800 flex justify-end items-center gap-4">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Totale Esposizione Fornitore:</span>
                <span class="text-xl font-black text-slate-900 dark:text-white">{{ euro(totalDebito) }}</span>
              </div>
            </div>

            <div class="p-5 bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/50 rounded-xl flex items-start gap-4">
              <AlertTriangle class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
              <div class="space-y-1">
                <h4 class="text-sm font-bold text-amber-900 dark:text-amber-200">Avviso sulla Quadratura Contabile</h4>
                <p class="text-xs text-amber-800/80 dark:text-amber-400/80 leading-relaxed">
                  L'inserimento di un debito pregresso influisce sullo Stato Patrimoniale. Assicurati che l'importo sia supportato da una delibera o da un verbale di passaggio consegne. Il sistema utilizzerà questi record per validare il "Double Lock" durante la registrazione delle fatture passive del 2026.
                </p>
              </div>
            </div>

          </div>

        </FornitoreLayout>
      </div>
    </div>

    <div v-if="showAddModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
      <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-slate-200 dark:border-slate-800 animate-in fade-in zoom-in duration-200">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
          <div class="flex items-center gap-3">
            <div class="p-2 bg-amber-100 dark:bg-amber-900/40 rounded-lg">
              <Wallet class="w-5 h-5 text-amber-600" />
            </div>
            <div>
              <h3 class="font-bold text-lg">Carica Debito Pregresso</h3>
              <p class="text-[10px] text-slate-500 uppercase font-bold tracking-wider">Apertura Stato Patrimoniale</p>
            </div>
          </div>
        </div>
        
        <form @submit.prevent="submit" class="p-6 space-y-6">
          <div class="space-y-1.5">
            <Label class="text-[11px] font-black uppercase text-slate-500">Condominio di riferimento</Label>
            <v-select 
              v-model="form.condominio_id" 
              :options="condomini" 
              label="nome" 
              :reduce="(c: Condominio) => c.id" 
              placeholder="Cerca condominio..." 
              class="style-chooser"
            />
          </div>

          <div class="space-y-1.5">
            <Label class="text-[11px] font-black uppercase text-slate-500">Descrizione Pendenza</Label>
            <Input v-model="form.descrizione" placeholder="Es: Saldo fatture riscaldamento 2025" class="h-10" />
          </div>

          <div class="space-y-1.5">
            <Label class="text-[11px] font-black uppercase text-slate-500">Importo Lordo (€)</Label>
            <div class="relative">
              <Euro class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
              <Input type="number" step="0.01" v-model="form.importo" class="pl-10 font-black text-xl h-12" placeholder="0,00" />
            </div>
            <p class="text-[9px] text-slate-400 italic">Inserisci l'importo dovuto. Il sistema lo caricherà come passività.</p>
          </div>

          <div class="flex gap-3 pt-4">
            <Button type="button" variant="outline" class="flex-1 h-11 font-bold" @click="showAddModal = false">Annulla</Button>
            <Button type="submit" class="flex-1 h-11 font-black bg-slate-900 hover:bg-slate-800" :disabled="form.processing || !form.condominio_id || !form.importo">
              Registra Debito
            </Button>
          </div>
        </form>
      </div>
    </div>

  </AppLayout>
</template>

<style>
.style-chooser .vs__dropdown-toggle {
    border-radius: 0.5rem;
    border: 1px solid #e2e8f0;
    min-height: 40px;
}
.dark .style-chooser .vs__dropdown-toggle {
    background: #0f172a;
    border-color: #1e293b;
    color: white;
}
.dark .style-chooser .vs__selected {
    color: white;
}
.dark .style-chooser .vs__search {
    color: white;
}
</style>