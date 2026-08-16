<script setup lang="ts">
/**
 * Le pertinenze di un'unità — la scheda che risponde a «cosa c'è attaccato a questo appartamento».
 *
 * ## Sola lettura, e non è una mancanza
 *
 * Da qui non si collega e non si scollega. Il campo «Pertinenza di» sta nella scheda della
 * **pertinenza**, perché è la pertinenza che punta al principale: un box dichiara a quale
 * appartamento appartiene, non viceversa. Offrire qui un secondo punto di scrittura significherebbe
 * due percorsi per lo stesso dato, con due regole da tenere allineate — e in questo progetto una
 * regola applicata a metà è già costata una beta.
 *
 * Ogni riga porta però il collegamento alla scheda della pertinenza, che è dove il legame si
 * modifica: la strada c'è, passa dal posto giusto.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Home, Link2, ArrowRight, Info } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import AvvisoTitolariDivergenti from '@/components/gestionale/immobili/AvvisoTitolariDivergenti.vue';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  pertinenze: Immobile[];
}>();

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: '#' },
  { title: 'Pertinenze', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Cosa è collegato a questa unità',
    description: 'Box, cantine e posti auto dichiarati come pertinenze di questa unità immobiliare.',
    icon: Link2,
    colorVariant: 'blue' as const,
  },
  {
    title: 'Il collegamento è descrittivo',
    // ⚠️ La stessa frase che sta sotto il campo. Ripeterla qui non è ridondanza: questa è la
    // pagina che un amministratore apre credendo di trovare un effetto sui conti.
    description: 'Millesimi, riparto e rate di ogni pertinenza restano suoi: il legame non sposta importi.',
    icon: Info,
    colorVariant: 'amber' as const,
  },
]);

const urlPertinenza = (p: Immobile) =>
  route(generateRoute('gestionale.immobili.show'), { condominio: props.condominio.id, immobile: p.id });
</script>

<template>
  <Head title="Pertinenze dell'unità" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">
      <PageHeaderGuide
        page-title="Pertinenze"
        :page-subtitle="`Unità collegate a: ${props.immobile.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="route(generateRoute('gestionale.immobili.index'), { condominio: props.condominio.id })"
        back-text="Torna alle unità"
      />

      <ImmobileLayout>

        <!--
          Se questa unità è **essa stessa** una pertinenza, è la prima cosa da dire: chi apre la
          scheda di un box vuole sapere di chi è, non cosa gli è attaccato sotto.
        -->
        <!--
          L'avviso di titolari divergenti apre la pagina, prima di qualunque altra cosa: se c'è, è
          la notizia. Si mostra da sé o resta invisibile — la decisione sta dentro il componente.
        -->
        <AvvisoTitolariDivergenti
          v-if="props.immobile.pertinenza_di"
          class="mb-6"
          contesto="scheda"
          :nome-pertinenza="props.immobile.nome"
          :nome-principale="props.immobile.pertinenza_di.nome"
          :titolari-pertinenza="props.immobile.anagrafiche ?? []"
          :titolari-principale="props.immobile.pertinenza_di.anagrafiche ?? []"
        />

        <Card
          v-if="props.immobile.pertinenza_di || props.immobile.pertinenza_di_esterna"
          class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20 mb-6"
        >
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Questa unità è una pertinenza</CardTitle>
            <CardDescription>Appartiene a un'altra unità immobiliare.</CardDescription>
          </CardHeader>
          <CardContent>
            <Link
              v-if="props.immobile.pertinenza_di"
              :href="route(generateRoute('gestionale.immobili.show'), { condominio: props.condominio.id, immobile: props.immobile.pertinenza_di.id })"
              class="group inline-flex items-center gap-3"
            >
              <div class="p-2 bg-indigo-50 dark:bg-indigo-900/40 rounded-lg text-indigo-500 shrink-0">
                <Home class="w-4 h-4" />
              </div>
              <div class="flex flex-col">
                <span class="font-bold text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 transition-colors">
                  {{ props.immobile.pertinenza_di.nome }}
                </span>
                <span class="text-[10px] uppercase tracking-widest text-slate-400 flex items-center gap-1 group-hover:text-indigo-500">
                  Interno {{ props.immobile.pertinenza_di.interno ?? '—' }}
                  <ArrowRight class="w-3 h-3" />
                </span>
              </div>
            </Link>

            <!--
              Il caso Tognoli: il principale non è nel gestionale, quindi non è cliccabile. Si
              mostra il testo dichiarato dall'amministratore così com'è.
            -->
            <div v-else class="flex flex-col gap-1">
              <span class="font-medium text-slate-700 dark:text-slate-300">
                {{ props.immobile.pertinenza_di_esterna }}
              </span>
              <span class="text-xs text-slate-500 dark:text-slate-400">
                Unità fuori da questo condominio: non è gestita qui, quindi non c'è una scheda da aprire.
              </span>
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Pertinenze collegate</CardTitle>
            <CardDescription>
              Le unità che dichiarano questa come propria unità principale.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div v-if="props.pertinenze.length === 0" class="text-sm text-slate-500 dark:text-slate-400">
              Nessuna unità è collegata a questa.
              <!--
                Il vuoto dice **dove** si rimedia, non solo che è vuoto. Il legame si dichiara dalla
                scheda della pertinenza, e chi arriva qui cercando un pulsante «aggiungi» va
                accompagnato lì invece che lasciato davanti a una pagina muta.
              -->
              <span class="block mt-1 text-xs">
                Il collegamento si dichiara dalla scheda della pertinenza — apri il box o la cantina
                e scegli questa unità nel campo «Pertinenza di».
              </span>
            </div>

            <div v-else class="divide-y divide-dashed">
              <Link
                v-for="p in props.pertinenze"
                :key="p.id"
                :href="urlPertinenza(p)"
                class="group flex items-center gap-3 py-3 first:pt-0 last:pb-0"
              >
                <div class="p-2 bg-indigo-50 dark:bg-indigo-900/40 rounded-lg text-indigo-500 shrink-0">
                  <Home class="w-4 h-4" />
                </div>
                <div class="flex flex-col min-w-0">
                  <div class="flex items-center gap-2">
                    <span class="px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-tighter bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-md border border-indigo-100 dark:border-indigo-800">
                      {{ p.tipologia?.nome ?? 'U.I.' }}
                    </span>
                    <span class="font-bold text-slate-900 dark:text-slate-100 group-hover:text-indigo-600 transition-colors truncate">
                      {{ p.nome }}
                    </span>
                  </div>
                  <span class="text-[10px] uppercase tracking-widest text-slate-400 flex items-center gap-1 group-hover:text-indigo-500 mt-0.5">
                    Interno {{ p.interno ?? '—' }}
                    <ArrowRight class="w-3 h-3" />
                  </span>
                </div>
              </Link>
            </div>
          </CardContent>
        </Card>

      </ImmobileLayout>
    </div>
  </GestionaleLayout>
</template>
