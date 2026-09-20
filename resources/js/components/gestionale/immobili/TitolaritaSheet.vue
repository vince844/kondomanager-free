<script setup lang="ts">
/**
 * «Chi ha avuto questa unità»: lo storico della titolarità come frasi (§6.5 di
 * `docs/pertinenze_vendita_locazione.md`, livello 2).
 *
 * Righe raggruppate per tipo di diritto — proprietà, usufrutto, locazione — e scritte come le
 * leggerebbe una visura catastale storica: «dal 3 marzo 2019 al 30 aprile 2026 · 7 anni». Le frasi
 * arrivano già composte dal server (`StoricoTitolarita`): qui non si formatta una data. Niente
 * colonna «attivo», niente id, niente `data_inizio` in intestazione: il dettaglio tecnico sta in un
 * accordion che si apre solo se richiesto.
 *
 * Il trigger è il pulsante `History` di `AnagraficheList.vue`, lo stesso di `BudgetHistoryPopover`.
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Input } from '@/components/ui/input';
import BadgeRuolo from '@/components/gestionale/immobili/BadgeRuolo.vue';
import { History, FileSignature, ShieldCheck, Scale, LoaderCircle } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { StoricoTitolaritaDati, RigaStorico, PassaggioRegistrato } from '@/types/gestionale/passaggi';

const props = defineProps<{
  open: boolean;
  storico: StoricoTitolaritaDati;
  unita: string;
  /** Per la rotta della copia autentica (S6): senza, la sezione dei passaggi è in sola lettura. */
  condominioId?: number;
  immobileId?: number;
}>();
defineEmits(['update:open']);

const { generateRoute } = usePermission();

// --- Passaggi registrati (S6, voce 7) ---------------------------------------------------------------
const passaggi = computed<PassaggioRegistrato[]>(() => props.storico.subentri ?? []);

const TITOLI_PASSAGGIO: Record<string, string> = {
  vendita: 'Vendita o donazione',
  inizio_locazione: 'Inizio locazione',
  fine_locazione: 'Fine locazione',
  usufrutto: 'Usufrutto',
};

function titoloPassaggio(p: PassaggioRegistrato): string {
  const base = TITOLI_PASSAGGIO[p.tipo_passaggio] ?? p.tipo_passaggio;
  return p.sottotipo ? `${base} · ${p.sottotipo}` : base;
}

function partiPassaggio(p: PassaggioRegistrato): string {
  if (p.uscente && p.entrante) return `${p.uscente} → ${p.entrante}`;
  return p.uscente ?? p.entrante ?? '';
}

const CONGUAGLIO: Record<PassaggioRegistrato['conguaglio']['stato'], string> = {
  proposto: 'Conguaglio scritto nei saldi',
  rinunciato: 'Conguaglio: l\'amministratore ha rinunciato',
  annullato: 'Conguaglio annullato',
  nessuno: 'Nessun conguaglio',
};

// Annullare il conguaglio (S6, voce 8): le due righe insieme, con una nota che resta sul passaggio.
const annullaAperto = ref<number | null>(null);
const formAnnulla = useForm({ nota_annullamento_conguaglio: '' });

function annullaConguaglio(p: PassaggioRegistrato) {
  if (!props.condominioId || !props.immobileId) return;
  formAnnulla.delete(
    route(generateRoute('gestionale.immobili.passaggi.annulla-conguaglio'), { condominio: props.condominioId, immobile: props.immobileId, subentro: p.id }),
    { preserveScroll: true, onSuccess: () => { annullaAperto.value = null; formAnnulla.reset(); } },
  );
}

// La copia autentica arriva dopo il rogito: un campo data per passaggio, e il vademecum si ricalcola.
const copiaAperta = ref<number | null>(null);
const formCopia = useForm({ copia_autentica_il: '' });

function registraCopia(p: PassaggioRegistrato) {
  if (!props.condominioId || !props.immobileId) return;
  formCopia.patch(
    route(generateRoute('gestionale.immobili.passaggi.copia-autentica'), { condominio: props.condominioId, immobile: props.immobileId, subentro: p.id }),
    { preserveScroll: true, onSuccess: () => { copiaAperta.value = null; formCopia.reset(); } },
  );
}

const sottotitolo = computed(() => {
  const registrati = props.storico.subentri?.length ?? 0;
  const chiusi = props.storico.periodi_chiusi ?? props.storico.passaggi;
  if (registrati === 0 && chiusi === 0) return 'Nessun passaggio registrato: i titolari di oggi sono i primi che il programma conosce.';
  if (registrati === 0) return chiusi === 1 ? 'Un periodo chiuso, nessun passaggio registrato con «Registra passaggio».' : `${chiusi} periodi chiusi, nessun passaggio registrato con «Registra passaggio».`;
  return registrati === 1 ? 'Un passaggio registrato.' : `${registrati} passaggi registrati.`;
});

/** «●———» in corso, «●——●» chiuso, «○———» non ancora iniziato. */
function tratto(r: RigaStorico): string {
  if (r.futuro) return '○———';
  return r.in_corso || !r.data_fine ? '●———' : '●——●';
}

const TIPI: Record<string, string> = {
  vendita: 'vendita o donazione',
  inizio_locazione: 'inizio locazione',
  fine_locazione: 'fine locazione',
  usufrutto: 'usufrutto',
};
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="sm:max-w-xl overflow-y-auto w-full sm:w-[560px] p-0">
      <div class="px-6 py-8">
        <SheetHeader class="mb-6">
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-slate-100 text-slate-700 rounded-lg dark:bg-slate-800 dark:text-slate-300">
              <History class="w-5 h-5" />
            </div>
            <SheetTitle class="text-xl font-extrabold tracking-tight">Chi ha avuto questa unità</SheetTitle>
          </div>
          <SheetDescription class="text-sm text-slate-600 dark:text-slate-400">
            {{ unita }} · {{ sottotitolo }}
          </SheetDescription>
        </SheetHeader>

        <div v-if="!storico.gruppi.length" class="text-sm text-slate-500 italic">
          Nessun titolare registrato su questa unità.
        </div>

        <div v-else class="space-y-7">
          <!-- Passaggi registrati (S6): uno per passaggio, col vademecum «chi resta obbligato» ricalcolato dai fatti -->
          <section v-if="passaggi.length">
            <h3 class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3">Passaggi registrati</h3>
            <ol class="space-y-4">
              <li v-for="p in passaggi" :key="p.id" class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50/60 dark:bg-slate-800/40 p-4 space-y-3">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                  <p class="text-sm">
                    <span class="font-semibold text-slate-900 dark:text-slate-100">{{ titoloPassaggio(p) }}</span>
                    <span class="text-slate-500 dark:text-slate-400"> · dal {{ p.decorrenza_a_parole }}</span>
                  </p>
                  <span class="text-[11px] text-slate-400">registrato il {{ p.registrato_il }}</span>
                </div>
                <p class="text-[13px] text-slate-700 dark:text-slate-300">
                  {{ partiPassaggio(p) }}<template v-if="p.estremi_titolo"> · {{ p.estremi_titolo }}</template>
                  <template v-if="p.documento_url"> · <a :href="p.documento_url" class="text-indigo-600 dark:text-indigo-400 hover:underline">titolo (PDF)</a></template>
                </p>
                <p v-if="p.pertinenze.length" class="text-[11px] text-slate-500 dark:text-slate-400">Insieme a: {{ p.pertinenze.join(', ') }}.</p>

                <div class="text-[11px] text-slate-600 dark:text-slate-400 space-y-1.5">
                  <p class="flex items-start gap-1.5">
                    <Scale class="w-3 h-3 mt-0.5 shrink-0" />
                    <span>
                      {{ CONGUAGLIO[p.conguaglio.stato] }}<template v-if="p.conguaglio.stato === 'proposto'">: {{ p.conguaglio.importo_formattato }} <template v-if="p.conguaglio.importo < 0">a credito di chi entra, debito uguale a chi esce</template><template v-else>a chi entra, credito uguale a chi esce</template><template v-if="p.conguaglio.applicato">, già assorbito in un piano</template>.</template>
                      <template v-else-if="p.conguaglio.stato === 'rinunciato' && p.conguaglio.nota">: «{{ p.conguaglio.nota }}».</template>
                      <template v-else-if="p.conguaglio.stato === 'annullato'"> il {{ p.conguaglio.annullato_il }}<template v-if="p.conguaglio.nota_annullamento">: «{{ p.conguaglio.nota_annullamento }}»</template>.</template>
                    </span>
                  </p>
                  <!-- Le due righe si tolgono insieme, finché nessun piano le ha assorbite -->
                  <div v-if="p.conguaglio.stato === 'proposto' && !p.conguaglio.applicato && condominioId && immobileId" class="pl-[18px]">
                    <button v-if="annullaAperto !== p.id" type="button" @click="annullaAperto = p.id; formAnnulla.reset(); formAnnulla.clearErrors()" class="font-semibold text-rose-600 dark:text-rose-400 hover:underline">
                      Le parti hanno regolato diversamente: annulla il conguaglio…
                    </button>
                    <form v-else class="space-y-2" @submit.prevent="annullaConguaglio(p)">
                      <p class="text-slate-600 dark:text-slate-400">Toglie insieme le due righe dai saldi della gestione; le quote già emesse non cambiano. La nota resta sul passaggio.</p>
                      <Input v-model="formAnnulla.nota_annullamento_conguaglio" placeholder="Come hanno regolato il conguaglio (almeno dieci caratteri)…" class="h-8 text-xs" required minlength="10" />
                      <div class="flex items-center gap-2">
                        <button type="submit" :disabled="formAnnulla.processing || formAnnulla.nota_annullamento_conguaglio.trim().length < 10" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-rose-600 px-3 text-xs font-semibold text-white hover:bg-rose-700 disabled:opacity-50">
                          <LoaderCircle v-if="formAnnulla.processing" class="w-3 h-3 animate-spin" /> Annulla il conguaglio
                        </button>
                        <button type="button" @click="annullaAperto = null" class="text-xs text-slate-500 hover:underline">Lascia com'è</button>
                      </div>
                      <p v-if="formAnnulla.errors.nota_annullamento_conguaglio || (formAnnulla.errors as Record<string, string>).conguaglio" class="text-[11px] text-red-600 dark:text-red-400">{{ formAnnulla.errors.nota_annullamento_conguaglio || (formAnnulla.errors as Record<string, string>).conguaglio }}</p>
                    </form>
                  </div>
                </div>

                <div class="rounded-md border border-amber-200/70 dark:border-amber-900/40 bg-amber-50/70 dark:bg-amber-950/20 p-3">
                  <p class="text-[10px] font-bold uppercase tracking-widest text-amber-800 dark:text-amber-300 mb-1.5 flex items-center gap-1.5"><ShieldCheck class="w-3 h-3" /> Chi resta obbligato</p>
                  <ul class="space-y-1.5">
                    <li v-for="(f, i) in p.obbligati" :key="i" class="text-[12px] leading-relaxed text-amber-950 dark:text-amber-100">{{ f }}</li>
                  </ul>
                </div>

                <div v-if="p.copia_autentica_attesa && condominioId && immobileId" class="text-[12px]">
                  <button v-if="copiaAperta !== p.id" type="button" @click="copiaAperta = p.id; formCopia.reset(); formCopia.clearErrors()" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                    Ho ricevuto la copia autentica del titolo…
                  </button>
                  <form v-else class="flex flex-wrap items-center gap-2" @submit.prevent="registraCopia(p)">
                    <span class="text-slate-600 dark:text-slate-400">Ricevuta il</span>
                    <Input type="date" v-model="formCopia.copia_autentica_il" class="h-8 w-40 text-xs" required />
                    <button type="submit" :disabled="formCopia.processing || !formCopia.copia_autentica_il" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-indigo-600 px-3 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                      <LoaderCircle v-if="formCopia.processing" class="w-3 h-3 animate-spin" /> Registra
                    </button>
                    <button type="button" @click="copiaAperta = null" class="text-xs text-slate-500 hover:underline">Annulla</button>
                    <p v-if="formCopia.errors.copia_autentica_il" class="w-full text-[11px] text-red-600 dark:text-red-400">{{ formCopia.errors.copia_autentica_il }}</p>
                  </form>
                </div>
                <p v-else-if="p.copia_autentica_a_parole" class="text-[11px] text-slate-500 dark:text-slate-400">Copia autentica del titolo ricevuta il {{ p.copia_autentica_a_parole }}.</p>
              </li>
            </ol>
          </section>

          <section v-for="g in storico.gruppi" :key="g.diritto">
            <h3 class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3">{{ g.diritto }}</h3>
            <ol class="space-y-3">
              <li v-for="r in g.righe" :key="r.id" class="flex gap-3">
                <span
                  class="shrink-0 w-12 text-[13px] leading-6 select-none"
                  :class="r.in_corso ? 'text-emerald-600 dark:text-emerald-400' : r.futuro ? 'text-slate-300 dark:text-slate-600' : 'text-slate-400'"
                  aria-hidden="true"
                >{{ tratto(r) }}</span>
                <div class="min-w-0 flex-1">
                  <p class="text-sm leading-6">
                    <span class="font-semibold" :class="r.in_corso ? 'text-slate-900 dark:text-slate-100' : 'text-slate-600 dark:text-slate-400'">{{ r.anagrafica.nome ?? '—' }}</span>
                    <span class="text-slate-500 dark:text-slate-400 ml-1">{{ r.periodo }}</span>
                    <span v-if="r.durata && !r.futuro" class="text-slate-400"> · {{ r.durata }}</span>
                  </p>
                  <p class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                    <BadgeRuolo :ruolo="r.tipologia" taglia="sm" />
                    <span v-if="Number(r.quota) !== 100">quota {{ Number(r.quota).toLocaleString('it-IT', { maximumFractionDigits: 2 }) }} %</span>
                    <span v-if="!r.attivo" class="italic">riga disattivata</span>
                  </p>
                  <p v-if="r.subentro" class="flex items-start gap-1.5 text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-snug">
                    <FileSignature class="w-3 h-3 mt-0.5 shrink-0" />
                    <span>
                      {{ TIPI[r.subentro.tipo_passaggio] ?? r.subentro.tipo_passaggio }}<template v-if="r.subentro.estremi_titolo">, {{ r.subentro.estremi_titolo }}</template><template v-if="r.subentro.copia_autentica_il"> · copia autentica ricevuta il {{ r.subentro.copia_autentica_il }}</template>
                      <template v-if="r.subentro.documento_url"> · <a :href="r.subentro.documento_url" class="text-indigo-600 dark:text-indigo-400 hover:underline">titolo (PDF)</a></template>
                      <template v-if="r.subentro.nota_conguaglio"> · conguaglio regolato fra le parti: «{{ r.subentro.nota_conguaglio }}»</template>
                    </span>
                  </p>
                </div>
              </li>
            </ol>
          </section>

          <Accordion type="single" collapsible class="border-t border-dashed border-slate-200 dark:border-slate-700 pt-2">
            <AccordionItem value="tecnico" class="border-0">
              <AccordionTrigger class="text-[11px] font-semibold uppercase tracking-widest text-slate-400 hover:text-slate-600 py-2">
                Dettaglio tecnico
              </AccordionTrigger>
              <AccordionContent>
                <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                  <table class="w-full text-[11px]">
                    <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 uppercase tracking-wider">
                      <tr>
                        <th class="px-2 py-1.5 text-left">Riga</th>
                        <th class="px-2 py-1.5 text-left">Ruolo</th>
                        <th class="px-2 py-1.5 text-right">Quota</th>
                        <th class="px-2 py-1.5 text-left">Inizio</th>
                        <th class="px-2 py-1.5 text-left">Fine</th>
                        <th class="px-2 py-1.5 text-left">Attivo</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                      <tr v-for="r in storico.righe" :key="r.id" class="text-slate-700 dark:text-slate-300">
                        <td class="px-2 py-1.5 tabular-nums">{{ r.id }}</td>
                        <td class="px-2 py-1.5">{{ r.tipologia }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ r.quota }}</td>
                        <td class="px-2 py-1.5 tabular-nums">{{ r.data_inizio ?? '—' }}</td>
                        <td class="px-2 py-1.5 tabular-nums">{{ r.data_fine ?? '—' }}</td>
                        <td class="px-2 py-1.5">{{ r.attivo ? 'sì' : 'no' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p v-for="r in storico.righe.filter(x => x.note)" :key="'n' + r.id" class="text-[11px] text-slate-500 mt-2">
                  Riga {{ r.id }}: {{ r.note }}
                </p>
              </AccordionContent>
            </AccordionItem>
          </Accordion>
        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
