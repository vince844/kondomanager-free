<script setup lang="ts">
/**
 * La scheda di un'anagrafica — «Dettagli».
 *
 * ## ⚠️ Fino alla 1.11.0-beta.9 questa pagina non esisteva, e la rotta rispondeva bianco
 *
 * `AnagraficaController::show()` aveva il corpo vuoto (`//`): `Route::resource` registrava
 * comunque la rotta, che rispondeva **200 senza corpo**. Nessun errore, nessun log, pagina bianca.
 * E l'elenco della rubrica ci puntava già col nome della persona, quindi bastava cliccare un nome
 * per finirci dentro.
 *
 * È la famiglia del difetto arrivato dal forum nella beta.62 (`categorie.show` verso un metodo
 * inesistente, 500 in faccia), con una differenza che la rende peggiore: **un 500 lo si segnala,
 * una pagina bianca fa pensare che sia colpa della propria connessione.**
 *
 * ## La forma è quella della scheda del fornitore, di proposito
 *
 * Stessa intestazione, stesse schede in alto, stessi riquadri grigi con l'etichetta minuscola. Una
 * persona e una ditta sono due anagrafiche: che si leggano allo stesso modo è il punto.
 */
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AnagraficaLayout from '@/layouts/anagrafiche/AnagraficaLayout.vue';
import Alert from '@/components/Alert.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from '@/composables/permissions';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
  Building2, CalendarClock, Contact, FilePenLine, IdCard, Info, Mail, MapPin, Phone, ShieldCheck,
  UserCheck, UserX,
} from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { BreadcrumbItem } from '@/types';

interface UnitaCollegata {
  id: number;
  etichetta: string;
  condominio: string | null;
  tipologia: string | null;
  quota: number | string | null;
  attivo: boolean;
  data_inizio: string | null;
  data_fine: string | null;
}

const props = defineProps<{
  anagrafica: {
    id: number;
    nome: string;
    indirizzo: string | null;
    email: string | null;
    email_secondaria: string | null;
    pec: string | null;
    telefono: string | null;
    cellulare: string | null;
    codice_fiscale: string | null;
    condomini?: Array<{ id: number; nome: string }>;
    [chiave: string]: unknown;
  };
  immobili: UnitaCollegata[];
  documenti_count: number;
}>();

const { generatePath, generateRoute } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Anagrafiche', href: route(generateRoute('anagrafiche.index')) },
  { title: 'Dettaglio anagrafica', href: '#' },
];

const pageGuides = [
  {
    title: 'Recapiti e contatti',
    description: 'Indirizzo, telefoni e caselle di posta della persona. Il pulsante «Modifica» apre il modulo per aggiornarli.',
    icon: Contact,
    colorVariant: 'blue' as const,
  },
  {
    title: 'Stabili e unità',
    description: 'In quali condomìni compare e quali unità occupa, con il ruolo e la quota che il riparto usa davvero.',
    icon: Building2,
    colorVariant: 'emerald' as const,
  },
  {
    title: 'Documento d\'identità',
    description: 'Tipo, numero e scadenza del documento registrato: serve alle pratiche e ai verbali che lo richiedono.',
    icon: IdCard,
    colorVariant: 'amber' as const,
  },
];

/** Nessun recapito è un caso normale in rubrica, e va detto invece di lasciare un riquadro vuoto. */
const senzaRecapiti = computed(() =>
  !props.anagrafica.indirizzo && !props.anagrafica.telefono && !props.anagrafica.cellulare
  && !props.anagrafica.email && !props.anagrafica.email_secondaria && !props.anagrafica.pec,
);

const documento = computed(() => ({
  tipologia: (props.anagrafica.tipologia_documento as string | null) ?? null,
  numero: (props.anagrafica.numero_documento as string | null) ?? null,
  scadenza: (props.anagrafica.scadenza_documento as string | null) ?? null,
}));

const senzaDocumento = computed(() =>
  !documento.value.tipologia && !documento.value.numero && !documento.value.scadenza,
);

/** Il collegamento all'utente: dice se questa persona ha accesso all'area riservata. */
const utente = computed(() => (props.anagrafica.user as { id: number; email: string } | null) ?? null);

/**
 * Il sottotitolo è **generico e sempre lo stesso**.
 *
 * ⚠️ La prima stesura ci metteva il conteggio delle unità («2 unità»). Sotto il nome di una persona
 * quel numero è un dato di passaggio travestito da identità: cambia da solo quando qualcuno tocca
 * un'assegnazione, e non dice **niente** su chi si sta guardando. Le unità hanno già il loro
 * riquadro, dove stanno con il ruolo e la quota, cioè con quello che le rende leggibili.
 */
const sottotitolo = 'Anagrafica in rubrica';

/** Il ruolo sull'unità si legge come sta scritto sulla pivot, con la prima lettera maiuscola. */
function etichettaRuolo(tipologia: string | null): string {
  if (!tipologia) return '—';

  return tipologia.charAt(0).toUpperCase() + tipologia.slice(1).replace(/_/g, ' ');
}
</script>

<template>
  <AppLayout>
    <Head :title="`${anagrafica.nome} — anagrafica`" />

    <div class="px-6 py-8 space-y-4">
      <PageHeaderGuide
        :page-title="anagrafica.nome"
        :page-subtitle="sottotitolo"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="generatePath('anagrafiche')"
        back-text="Indietro"
      >
        <template #actions>
          <Link
            :href="route(generateRoute('anagrafiche.edit'), { anagrafica: anagrafica.id })"
            class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-sm font-medium text-white px-3 py-1.5 h-8 hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
          >
            <FilePenLine class="w-4 h-4" />
            <span>Modifica dati</span>
          </Link>
        </template>
      </PageHeaderGuide>

      <div v-if="flashMessage" class="py-3">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <div class="w-full">
        <AnagraficaLayout>
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 pb-16">
            <div class="lg:col-span-2 space-y-6">

              <!-- Recapiti -->
              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Recapiti e contatti</h2>
                </div>
                <div class="divide-y divide-slate-200/60 dark:divide-slate-700/40">
                  <div v-if="anagrafica.indirizzo" class="flex items-start gap-3 px-5 py-3.5">
                    <MapPin class="w-4 h-4 text-slate-400 mt-0.5 shrink-0" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ anagrafica.indirizzo }}</span>
                  </div>
                  <div v-if="anagrafica.telefono" class="flex items-center gap-3 px-5 py-3.5">
                    <Phone class="w-4 h-4 text-slate-400 shrink-0" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ anagrafica.telefono }}</span>
                    <span class="text-xs text-slate-400 ml-auto">Fisso</span>
                  </div>
                  <div v-if="anagrafica.cellulare" class="flex items-center gap-3 px-5 py-3.5">
                    <Phone class="w-4 h-4 text-slate-400 shrink-0" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ anagrafica.cellulare }}</span>
                    <span class="text-xs text-slate-400 ml-auto">Cellulare</span>
                  </div>
                  <div v-if="anagrafica.email" class="flex items-center gap-3 px-5 py-3.5">
                    <Mail class="w-4 h-4 text-slate-400 shrink-0" />
                    <a :href="`mailto:${anagrafica.email}`" class="text-sm text-slate-700 dark:text-slate-300 hover:text-primary transition-colors">{{ anagrafica.email }}</a>
                  </div>
                  <div v-if="anagrafica.email_secondaria" class="flex items-center gap-3 px-5 py-3.5">
                    <Mail class="w-4 h-4 text-slate-400 shrink-0" />
                    <a :href="`mailto:${anagrafica.email_secondaria}`" class="text-sm text-slate-700 dark:text-slate-300 hover:text-primary transition-colors">{{ anagrafica.email_secondaria }}</a>
                    <span class="text-xs text-slate-400 ml-auto">Secondaria</span>
                  </div>
                  <div v-if="anagrafica.pec" class="flex items-center gap-3 px-5 py-3.5">
                    <Mail class="w-4 h-4 text-amber-400 shrink-0" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ anagrafica.pec }}</span>
                    <span class="ml-auto text-[10px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 px-2 py-0.5 rounded-md">PEC</span>
                  </div>
                  <div v-if="senzaRecapiti" class="px-5 py-4 text-sm text-slate-400 italic">
                    Nessun recapito registrato.
                  </div>
                </div>
              </div>

              <!-- Unità immobiliari -->
              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Unità immobiliari</h2>
                </div>
                <div class="divide-y divide-slate-200/60 dark:divide-slate-700/40">
                  <div v-for="unita in immobili" :key="unita.id" class="flex items-center gap-3 px-5 py-3.5">
                    <Building2 class="w-4 h-4 text-slate-400 shrink-0" />
                    <div class="min-w-0">
                      <span class="block text-sm text-slate-800 dark:text-slate-200 truncate">{{ unita.etichetta }}</span>
                      <span v-if="unita.condominio" class="block text-xs text-slate-400 truncate">{{ unita.condominio }}</span>
                    </div>

                    <span class="ml-auto flex items-center gap-2 shrink-0">
                      <!--
                        ⚠️ La riga **non attiva** si segna in ambra, non si nasconde: un legame
                        chiuso spiega perché una persona compare in una stampa vecchia, e toglierlo
                        dalla vista renderebbe quella comparsa inspiegabile.
                      -->
                      <span
                        v-if="!unita.attivo"
                        class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                      >non attivo</span>
                      <span class="text-xs font-semibold text-slate-600 dark:text-slate-400">{{ etichettaRuolo(unita.tipologia) }}</span>
                      <span v-if="unita.quota !== null" class="text-xs text-slate-500 tabular-nums">{{ unita.quota }}%</span>
                    </span>
                  </div>

                  <div v-if="immobili.length === 0" class="px-5 py-4 text-sm text-slate-400 italic">
                    Non è collegata a nessuna unità.
                  </div>
                </div>
              </div>
            </div>

            <!-- Colonna destra -->
            <div class="space-y-6">

              <!-- Accesso all'area riservata -->
              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Area riservata</h2>
                </div>
                <div class="px-5 py-4">
                  <div v-if="utente" class="flex items-start gap-3">
                    <UserCheck class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0" />
                    <div class="min-w-0">
                      <span class="block text-sm text-slate-800 dark:text-slate-200">Ha un accesso</span>
                      <span class="block text-xs text-slate-500 truncate">{{ utente.email }}</span>
                    </div>
                  </div>
                  <div v-else class="flex items-start gap-3">
                    <UserX class="w-4 h-4 text-slate-400 mt-0.5 shrink-0" />
                    <div class="min-w-0">
                      <span class="flex items-center gap-1.5 text-sm text-slate-800 dark:text-slate-200">
                        Nessun accesso

                        <!--
                          Il percorso, **verificato sul codice il 31/08/2026** (e corretto: la
                          prima stesura di questo tooltip diceva il falso).
                          `UserService::createUser()` e `updateUser()` accettano un'anagrafica e la
                          collegano all'utente — `UserRepository::linkAnagrafica()` fa
                          `$anagrafica->user()->associate($user)` — quindi **questa scheda si lega a
                          un account dal modulo dell'utente**, scegliendola nel campo «Anagrafica».
                          La pagina «crea anagrafica» compare a chi si registra **solo** quando
                          l'anagrafica non ce l'ha: `CheckHasAnagrafica` reindirizza con
                          `!$user->anagrafica && !$user->hasRole(AMMINISTRATORE)`. Se gliel'hai
                          collegata tu, quella pagina non la vede e nessun doppione nasce.
                        -->
                        <TooltipProvider :delay-duration="150">
                          <Tooltip>
                            <TooltipTrigger as-child>
                              <button type="button" class="text-slate-400 hover:text-slate-600 transition-colors" aria-label="Come si dà l'accesso">
                                <Info class="w-3.5 h-3.5" />
                              </button>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">
                              <p class="text-xs leading-relaxed">
                                L'accesso si dà da <strong>Utenti</strong>: crea l'utente — o modificane uno che
                                esiste già — e nel campo «Anagrafica» scegli questa persona. Da quel momento la
                                scheda e l'account sono la stessa cosa, e questo riquadro si accende.
                              </p>
                              <p class="text-xs leading-relaxed mt-2">
                                Se invece inviti qualcuno per email <strong>senza collegargli un'anagrafica</strong>,
                                al primo accesso il programma gliela fa compilare a lui: nasce una scheda nuova,
                                separata da questa.
                              </p>
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                      </span>
                      <span class="block text-xs text-slate-500">Questa persona non entra nell'area riservata.</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Documento d'identità -->
              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Documento d'identità</h2>
                </div>
                <div v-if="!senzaDocumento" class="divide-y divide-slate-200/60 dark:divide-slate-700/40">
                  <div class="px-5 py-3.5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Tipo</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ documento.tipologia || '—' }}</span>
                  </div>
                  <div class="px-5 py-3.5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Numero</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ documento.numero || '—' }}</span>
                  </div>
                  <div class="flex items-center gap-2 px-5 py-3.5">
                    <CalendarClock class="w-4 h-4 text-slate-400 shrink-0" />
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ documento.scadenza || '—' }}</span>
                    <span class="text-xs text-slate-400 ml-auto">Scadenza</span>
                  </div>
                </div>
                <div v-else class="px-5 py-4 text-sm text-slate-400 italic">
                  Nessun documento registrato.
                </div>
              </div>

              <!-- Condomìni -->
              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Condomìni</h2>
                </div>
                <div class="px-5 py-4 flex flex-wrap gap-2">
                  <span
                    v-for="condominio in anagrafica.condomini ?? []"
                    :key="condominio.id"
                    class="inline-flex items-center gap-1.5 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 px-2 py-1 text-xs font-medium text-slate-700 dark:text-slate-300"
                  >
                    <ShieldCheck class="w-3 h-3 text-slate-400" />
                    {{ condominio.nome }}
                  </span>
                  <span v-if="(anagrafica.condomini ?? []).length === 0" class="text-sm text-slate-400 italic">
                    Non è associata a nessuno stabile.
                  </span>
                </div>
              </div>
            </div>
          </div>
        </AnagraficaLayout>
      </div>
    </div>
  </AppLayout>
</template>
