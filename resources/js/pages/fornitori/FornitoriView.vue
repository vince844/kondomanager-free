<script setup lang="ts">
import { computed } from "vue";
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { List, Pencil, ShieldCheck, ShieldOff } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Fornitore } from '@/types/fornitori';

const props = defineProps<{
  fornitore: Fornitore;
}>()

const { generatePath } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const indirizzoCompleto = computed(() => {
  const parts = [];
  
  if (props.fornitore.indirizzo) parts.push(props.fornitore.indirizzo);
  if (props.fornitore.comune) parts.push(props.fornitore.comune);
  if (props.fornitore.provincia) parts.push(`(${props.fornitore.provincia})`);
  if (props.fornitore.cap) parts.push(props.fornitore.cap);
  
  return parts.length > 0 ? parts.join(' ') : 'Nessun indirizzo registrato per questo fornitore';
});

</script>

<template>
  <AppLayout>
    <Head title="Dettagli fornitore" />

    <FornitoreLayout>

      <div v-if="flashMessage" class="py-3">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <!-- Action buttons -->
      <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
        <Link
          as="button"
          :href="generatePath('fornitori/:fornitore/edit', { fornitore: props.fornitore.id })"
          class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
        >
          <Pencil class="w-4 h-4" />
          <span>Modifica</span>
        </Link>

        <Link
          as="button"
          :href="generatePath('fornitori')"
          class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
        >
          <List class="w-4 h-4" />
          <span>Fornitori</span>
        </Link>
      </div>

      <div class="container mx-auto p-0">
        <div class="bg-card mb-6 grid grid-cols-1 md:grid-cols-2 gap-6 rounded-lg border p-6 text-sm mt-4">

          <!-- Left block -->
          <div class="space-y-6 pr-6 border-r">
            <div class="border-b pb-2 mb-8">
              <h3 class="text-lg font-bold">{{fornitore.ragione_sociale}}</h3>
              <p class="text-muted-foreground text-sm ">
                {{indirizzoCompleto }} 
              </p>
            </div>
    
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Column 1 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="font-semibold w-24">Categoria:</span>
                  <div class="inline-flex items-center rounded-md border px-2.5 py-0.5 font-medium text-xs">
                    {{ fornitore.categoria?.name ?? 'Nessuna categoria' }}
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="font-semibold w-24">Partita IVA:</span>
                  <div>{{ fornitore.partita_iva ?? '-' }}</div> 
                </div>

                <div class="flex items-center gap-2">
                  <span class="font-semibold w-24">Codice fiscale:</span>
                  <div>{{ fornitore.codice_fiscale ?? '-'}}</div> 
                </div>
              </div>

              <!-- Column 2 -->
              <div class="space-y-3">
                <div class="flex items-center">
                  <span class="font-semibold w-24">Telefono:</span>
                  <div>{{ fornitore.telefono }} - {{ fornitore.cellulare }}</div>
                </div>

                <div class="flex items-center">
                  <span class="font-semibold w-24">Email:</span>
                  <div>{{ fornitore.email ?? '-' }}</div> 
                </div>

                <div class="flex items-center">
                  <span class="font-semibold w-24">Sito web:</span>
                  <div>{{ fornitore.sito_web ?? '-' }}</div> 
                </div>
              </div>
            </div>
          </div>

          <!-- Right block -->
          <div class="space-y-6">
            <div class="border-b pb-2 mb-8">
              <h3 class="text-lg font-bold">Dati societari</h3>
              <p class="text-muted-foreground text-sm ">
                Di seguito i dati societari registrati per questo fornitore.
              </p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2">
              <!-- Column 3 -->
              <div class="space-y-3">
                <div class="flex items-center gap-2">
                  <span class="font-semibold w-30">Numero CCIAA:</span>
                  <div>{{ fornitore.iscrizione_cciaa ?? '-' }}</div> 
                </div>

                <div class="flex items-center gap-2">
                  <span class="font-semibold w-30">Codice ATECO:</span>
                  <div>{{ fornitore.codice_ateco ?? '-'}}</div>
                </div>

                <div class="flex items-center gap-2">
                  <span class="font-semibold w-30">Numero ordine:</span>
                  <div>{{fornitore.numero_ordine ?? '-' }}</div>
                </div>
              </div>

              <!-- Column 4 -->
              <div class="space-y-3">

                <div class="flex items-center">
                  <span class="font-semibold w-40">Data iscrizione CCIAA:</span>
                  <div>{{fornitore.data_iscrizione_cciaa }}</div>
                </div>

                <!-- Certificazione ISO con icona e colori -->
                <div class="flex items-center">
                  <span class="font-semibold w-35">Certificazione ISO:</span>
                  <div v-if="fornitore.certificazione_iso" class="inline-flex items-center gap-2 rounded-md border border-green-100 bg-green-50/50 px-2.5 py-0.5 text-xs font-medium text-green-800">
                    <ShieldCheck class="w-3.5 h-3.5" />
                    <span>Certificato</span>
                  </div>
                  <div v-else class="inline-flex items-center gap-2 rounded-md border border-red-100 bg-red-50/50 px-2.5 py-0.5 text-xs font-medium text-red-800">
                    <ShieldOff class="w-3.5 h-3.5" />
                    <span>Non certificato</span>
                  </div>
                </div>

                <div class="flex items-center">
                  <span class="font-semibold w-35">Capitale sociale:</span>
                  <div>{{fornitore.capitale_sociale }}</div>
                </div>

                <!-- Aggiungi altri campi se necessario -->
       
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-card mb-2 rounded-lg border p-6 text-sm">
        <!-- Notes Section -->
        <div class="border-b pb-2 mb-4">
          <span class="text-lg font-bold">Note registrate</span>
        </div>

        <div class="text-sm text-gray-700"> 
          {{ fornitore.note ? fornitore.note : 'Nessuna nota inserita per questo fornitore.' }}
        </div>
      </div>

    </FornitoreLayout>
  </AppLayout>
</template>