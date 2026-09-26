<script setup lang="ts">
/**
 * I titolari di un'unità: **solo quelli di oggi** in tabella, lo storico in un pannello a parte
 * (§6.5 di `docs/pertinenze_vendita_locazione.md`, livello 1 e 2).
 *
 * Fino alla 1.11.0-beta.30 la tabella mostrava tutte le righe, chiuse comprese, con la data di fine
 * in ambra. Un periodo chiuso nella stessa tabella degli attivi è il modo più veloce per intestare una
 * rata a chi ha venduto: da qui in poi la riga «Oggi, 18 settembre 2026 · 1 proprietario · 1 inquilino»
 * dice a quale data si sta guardando — ed è il posto che, quando il riparto avrà una data di
 * riferimento, servirà a cambiarla — e «Storico (N passaggi)» apre «Chi ha avuto questa unità».
 *
 * Il pulsante primario è **«Registra passaggio»**, con le quattro voci (§6.3): è il verbo che
 * conserva la storia. «Associa soggetto» resta, secondario, per la prima associazione di un'unità
 * vuota o per un comproprietario che si aggiunge.
 */
import { computed, ref } from "vue";
import { Head, usePage, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import DataTable from '@/components/gestionale/immobili/anagrafiche/DataTable.vue';
import { createColumns } from '@/components/gestionale/immobili/anagrafiche/columns'
import TitolaritaSheet from '@/components/gestionale/immobili/TitolaritaSheet.vue';
import Alert from "@/components/Alert.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { usePermission } from "@/composables/permissions";
import { UsersRound, ArrowRightLeft, PieChart, UserPlus, List, History, CalendarCheck, ChevronDown, Home, KeyRound, KeySquare, Landmark, CheckCircle2, Scale, ContactRound, Printer } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';
import type { StoricoTitolaritaDati, TipoPassaggio } from '@/types/gestionale/passaggi';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  storico: StoricoTitolaritaDati;
  /** `Y-m-d`: il giorno a cui la tabella si riferisce. Oggi, finché il riparto non avrà una data sua. */
  oggi: string;
  /** B3a: il prospetto degli oneri accessori, solo se l'unità ha o ha avuto un inquilino; `null` altrimenti. */
  prospettoOneri?: { url: string; esercizi: { id: number; nome: string }[]; corrente: number | null } | null;
}>();

// Il prospetto si apre in una scheda nuova, come le altre stampe; l'esercizio va in query.
const apriProspetto = (esercizioId: number) => {
  if (!props.prospettoOneri) return;
  window.open(`${props.prospettoOneri.url}?esercizio=${esercizioId}`, '_blank');
};

const { generatePath, generateRoute } = usePermission();

interface PassaggioRegistrato {
  subentro_id: number; frase: string; coppie: number; conguaglio: string | null; rinuncia: boolean;
  /** Decisione 25 (B3a): la coppia rovesciata (le bozze passate coprono più dei giorni di chi entra) e le bozze passate. */
  conguaglio_rovesciato?: boolean; riassegnate?: number; riassegnate_frase?: string | null;
  documento: string | null; promemoria: string | null; avvisi: string[]; entrante: string | null;
  azioni: { estratto_conto: string | null; anagrafe: string | null };
}
const page = usePage<{ flash: { message?: Flash; passaggio_registrato?: PassaggioRegistrato | null } }>();
// Dopo «Registra passaggio» l'avviso verde è quello dedicato (§6.4), con le due azioni: il flash generico tace.
const passaggioRegistrato = computed(() => page.props.flash.passaggio_registrato ?? null);
const flashMessage = computed(() => (passaggioRegistrato.value ? undefined : page.props.flash.message));
const promemoriaAParole = computed(() => passaggioRegistrato.value?.promemoria
  ? new Date(passaggioRegistrato.value.promemoria + 'T00:00:00').toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' })
  : null);
const storicoAperto = ref(false);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'Anagrafiche', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Chi risponde verso il condominio',
    description: "Proprietari, nudi proprietari e usufruttuari: i titolari di un diritto reale sull'unità, a cui il riparto intesta le quote.",
    icon: UsersRound,
    colorVariant: 'blue' as const
  },
  {
    title: 'Quote di competenza',
    description: "La percentuale interna fra soggetti dello stesso ruolo: due coniugi al 50 %. Non sono millesimi.",
    icon: PieChart,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Passaggi e storico',
    description: "Vendita, locazione, usufrutto si registrano con «Registra passaggio»: il periodo di chi esce si chiude, la storia resta.",
    icon: ArrowRightLeft,
    colorVariant: 'amber' as const
  }
]);

// --- «Oggi, 18 settembre 2026 · 1 proprietario · 1 inquilino» ------------------------------------

const oggiAParole = computed(() =>
  new Date(props.oggi + 'T00:00:00').toLocaleDateString('it-IT', { day: 'numeric', month: 'long', year: 'numeric' }),
);

const ETICHETTE: Record<string, [string, string]> = {
  proprietario: ['proprietario', 'proprietari'],
  nuda_proprietario: ['nudo proprietario', 'nudi proprietari'],
  usufruttuario: ['usufruttuario', 'usufruttuari'],
  inquilino: ['inquilino', 'inquilini'],
};
const ORDINE = ['proprietario', 'nuda_proprietario', 'usufruttuario', 'inquilino'];

const conteggio = computed(() => {
  const n: Record<string, number> = {};
  for (const a of props.immobile.anagrafiche ?? []) n[a.pivot.tipologia] = (n[a.pivot.tipologia] ?? 0) + 1;
  return ORDINE.filter(r => n[r]).map(r => `${n[r]} ${ETICHETTE[r][n[r] === 1 ? 0 : 1]}`);
});

// --- «Registra passaggio»: le quattro voci -------------------------------------------------------

const VOCI: { id: TipoPassaggio; titolo: string; sotto: string; icona: any }[] = [
  { id: 'vendita', titolo: 'Vendita o donazione', sotto: 'cambia il proprietario', icona: Home },
  { id: 'inizio_locazione', titolo: 'Inizio locazione', sotto: 'entra un inquilino', icona: KeyRound },
  { id: 'fine_locazione', titolo: 'Fine locazione', sotto: "esce l'inquilino", icona: KeySquare },
  { id: 'usufrutto', titolo: 'Usufrutto', sotto: 'costituzione o estinzione', icona: Landmark },
];
function urlPassaggio(tipo: TipoPassaggio) {
  return route(generateRoute('gestionale.immobili.passaggi.create'), { condominio: props.condominio.id, immobile: props.immobile.id, tipo });
}
</script>

<template>
  <Head title="Elenco anagrafiche immobile" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        page-title="Titolari dell'unità"
        :page-subtitle="`Chi risponde e chi occupa: ${props.immobile.nome} (Int. ${props.immobile.interno})`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
      >
        <template #actions>
          <div class="flex items-center gap-2">
            <Link
              as="button"
              :href="generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id })"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 px-4 text-sm font-medium text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
              <List class="w-3.5 h-3.5" />
              <span>Immobili</span>
            </Link>

            <Link
              :href="route(generateRoute('gestionale.immobili.anagrafiche.create'), { condominio: props.condominio.id, immobile: props.immobile.id })"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 px-4 text-sm font-medium text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
              title="Prima associazione di un'unità vuota, o un comproprietario che si aggiunge"
            >
              <UserPlus class="w-3.5 h-3.5" />
              <span>Associa soggetto</span>
            </Link>

            <!-- B3a: il prospetto degli oneri accessori — per unità, per esercizio; solo se c'è stato un inquilino.
                 L'etichetta si vede da 2xl in su, come «Stampe PDF» nei piani rate: con quattro pulsanti e un nome di
                 condominio lungo la riga usciva dal bordo destro già a 1280 px (Fase 5 della beta.34). Sotto, il
                 titolo del menu dice che cosa si sta aprendo. -->
            <DropdownMenu v-if="props.prospettoOneri && props.prospettoOneri.esercizi.length">
              <DropdownMenuTrigger as-child>
                <button
                  type="button"
                  class="inline-flex h-8 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 px-3 2xl:px-4 text-sm font-medium text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                  title="Prospetto oneri accessori: le spese che il riparto pone a carico dell'inquilino, per conduttore e per giorni di conduzione"
                  aria-label="Prospetto oneri accessori"
                >
                  <Printer class="w-3.5 h-3.5" />
                  <span class="hidden 2xl:inline">Prospetto oneri accessori</span>
                  <ChevronDown class="w-3 h-3 opacity-70" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" class="w-64">
                <DropdownMenuLabel class="text-[10px] uppercase tracking-widest text-slate-400">Oneri accessori: quale esercizio?</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem v-for="e in props.prospettoOneri.esercizi" :key="e.id" class="cursor-pointer" @click="apriProspetto(e.id)">
                  <span class="text-sm">{{ e.nome }}</span>
                  <span v-if="e.id === props.prospettoOneri.corrente" class="ml-auto text-[10px] text-slate-400">corrente</span>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <!-- Il verbo che conserva: primario, con la prima domanda già dentro. -->
            <DropdownMenu>
              <DropdownMenuTrigger as-child>
                <button
                  type="button"
                  class="inline-flex h-8 items-center justify-center gap-2 rounded-md shadow px-4 bg-primary text-[10px] font-bold uppercase tracking-widest text-primary-foreground hover:bg-primary/90 transition-colors"
                >
                  <ArrowRightLeft class="w-3.5 h-3.5" />
                  <span>Registra passaggio</span>
                  <ChevronDown class="w-3 h-3 opacity-70" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" class="w-72">
                <DropdownMenuLabel class="text-[10px] uppercase tracking-widest text-slate-400">Che cosa è successo?</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem v-for="v in VOCI" :key="v.id" as-child>
                  <Link :href="urlPassaggio(v.id)" class="flex items-start gap-3 py-2 cursor-pointer">
                    <component :is="v.icona" class="w-4 h-4 mt-0.5 text-slate-400 shrink-0" />
                    <span class="flex flex-col">
                      <span class="text-sm font-medium">{{ v.titolo }}</span>
                      <span class="text-[11px] text-slate-500">{{ v.sotto }}</span>
                    </span>
                  </Link>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </template>
      </PageHeaderGuide>

      <ImmobileLayout>

        <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>

        <!-- §6.4: l'avviso verde dopo la registrazione, con due azioni al posto del ritorno all'elenco.
             Niente «Scarica l'attestazione» (stampa della v1.16) e niente «situazione debitoria dell'unità»
             (oggi è un endpoint JSON, non una pagina): l'estratto conto di chi entra mostra il conguaglio. -->
        <div v-if="passaggioRegistrato" class="py-3">
          <div class="rounded-lg border border-emerald-200 bg-emerald-50 dark:border-emerald-800/60 dark:bg-emerald-900/10 p-4 space-y-3">
            <div class="flex items-start gap-3">
              <CheckCircle2 class="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
              <div class="min-w-0 space-y-1">
                <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-200">Passaggio registrato.</p>
                <p class="text-sm text-emerald-900/90 dark:text-emerald-200/90">{{ passaggioRegistrato.frase }}</p>
                <p v-if="passaggioRegistrato.coppie > 0" class="text-[13px] text-emerald-900/80 dark:text-emerald-200/80">
                  Conguaglio scritto in saldi: {{ passaggioRegistrato.coppie }} {{ passaggioRegistrato.coppie === 1 ? 'coppia' : 'coppie' }} a somma zero, {{ passaggioRegistrato.conguaglio }} a {{ passaggioRegistrato.conguaglio_rovesciato ? 'credito' : 'debito' }} di chi entra. Il prossimo piano rate le assorbe.
                </p>
                <p v-else-if="passaggioRegistrato.rinuncia" class="text-[13px] text-emerald-900/80 dark:text-emerald-200/80">Nessuna riga in saldi: hai indicato che il conguaglio è regolato fra le parti. La ragione è nel passaggio.</p>
                <!-- Dopo la catena coppie/rinuncia, non in mezzo: un v-if fra i due legava il v-else-if a sé (Fase 1-bis, R7). -->
                <p v-if="passaggioRegistrato.riassegnate_frase" class="text-[13px] text-emerald-900/80 dark:text-emerald-200/80">{{ passaggioRegistrato.riassegnate_frase }}</p>
                <p v-if="passaggioRegistrato.documento" class="text-[13px] text-emerald-900/80 dark:text-emerald-200/80">Allegato fra i documenti dell'unità: «{{ passaggioRegistrato.documento }}» (solo per l'amministratore).</p>
                <p v-if="promemoriaAParole" class="text-[13px] text-emerald-900/80 dark:text-emerald-200/80">Promemoria in agenda per il {{ promemoriaAParole }}.</p>
                <p v-for="(a, i) in passaggioRegistrato.avvisi" :key="i" class="text-[13px] text-amber-800 dark:text-amber-300">{{ a }}</p>
              </div>
            </div>
            <div class="flex flex-wrap gap-2 pl-8">
              <a v-if="passaggioRegistrato.azioni.estratto_conto" :href="passaggioRegistrato.azioni.estratto_conto" class="inline-flex h-8 items-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-emerald-300 dark:border-emerald-700 px-3 text-sm font-medium text-emerald-900 dark:text-emerald-200 shadow-sm hover:bg-emerald-100/60 dark:hover:bg-emerald-900/30">
                <Scale class="w-3.5 h-3.5" /> Estratto conto di {{ passaggioRegistrato.entrante ?? 'chi entra' }}
              </a>
              <a v-if="passaggioRegistrato.azioni.anagrafe" :href="passaggioRegistrato.azioni.anagrafe" class="inline-flex h-8 items-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-emerald-300 dark:border-emerald-700 px-3 text-sm font-medium text-emerald-900 dark:text-emerald-200 shadow-sm hover:bg-emerald-100/60 dark:hover:bg-emerald-900/30">
                <ContactRound class="w-3.5 h-3.5" /> Aggiorna l'anagrafe condominiale <span class="text-[11px] font-normal text-emerald-700 dark:text-emerald-400">(art. 1130 n. 6 c.c.)</span>
              </a>
            </div>
          </div>
        </div>

        <div class="container mx-auto p-0 space-y-3 mt-2">

          <!-- La data a cui la tabella si riferisce, e lo storico -->
          <div class="flex flex-wrap items-center justify-between gap-3 px-1">
            <p class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
              <CalendarCheck class="w-4 h-4 text-slate-400" />
              <span><span class="font-semibold">Oggi, {{ oggiAParole }}</span>
                <template v-if="conteggio.length"> · {{ conteggio.join(' · ') }}</template>
                <template v-else> · <span class="italic text-slate-500">nessun titolare in corso</span></template>
              </span>
            </p>
            <!-- Un pulsante vero, come «Associa soggetto»: un testo grigio in un angolo non lo vedeva nessuno
                 (Checkpoint 1, 19/09/2026). -->
            <button
              v-if="storico.passaggi > 0 || (storico.subentri?.length ?? 0) > 0 || storico.righe.length > (immobile.anagrafiche?.length ?? 0)"
              type="button"
              @click="storicoAperto = true"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 px-3 text-sm font-medium text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
              title="Chi ha avuto questa unità"
            >
              <History class="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400" />
              <span>Storico<template v-if="storico.passaggi"> · {{ storico.passaggi }} {{ storico.passaggi === 1 ? 'passaggio' : 'passaggi' }}</template></span>
            </button>
          </div>

          <DataTable
            :columns="createColumns(props.condominio, props.immobile)"
            :data="props.immobile.anagrafiche"
          />
        </div>

      </ImmobileLayout>

    </div>
  </GestionaleLayout>

  <TitolaritaSheet v-model:open="storicoAperto" :storico="storico" :unita="`${immobile.nome} (Int. ${immobile.interno})`" :condominio-id="props.condominio.id" :immobile-id="props.immobile.id" />
</template>
