<script setup lang="ts">
import { ref, computed } from "vue";
import { Head, useForm, Link } from "@inertiajs/vue3";
import GestionaleLayout from "@/layouts/GestionaleLayout.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import InputError from '@/components/InputError.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Plus, LoaderCircle, Trash2, Table as TableIcon, Info, Hash } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogFooter, AlertDialogCancel } from "@/components/ui/alert-dialog";
import vSelect from "vue-select";
import "vue-select/dist/vue-select.css";
import type { BreadcrumbItem } from "@/types";
import type { Tabella } from "@/types/gestionale/tabelle";
import type { Building } from "@/types/buildings";
import type { Millesimo } from "@/types/gestionale/millesimi";
import type { Immobile } from "@/types/gestionale/immobili";

const props = defineProps<{
  condominio: Building;
  tabella: Tabella;
  millesimi: Millesimo[];
  immobili: Immobile[];
}>()

const showNoImmobiliDialog = ref(false);
const alertMessage = ref("");

// Extract raw data from Proxy objects
const rawMillesimi = JSON.parse(JSON.stringify(props.millesimi));
const rawImmobili = JSON.parse(JSON.stringify(props.immobili));

const decimaliTabella = Math.max(0, props.tabella.numero_decimali ?? 2);

/**
 * Porta il valore digitato alla precisione della tabella, **quando si esce dalla casella**.
 *
 * ## Perché non una maschera vera
 *
 * Il primo tentativo usava `MoneyInput` (`v-money3`), che è quello che il gestionale adopera
 * per gli importi. Risolveva la virgola e limitava i decimali, ma con la convenzione dei campi
 * monetari: **le cifre scorrono da destra**. Digitando `200` compariva `2,00`, e per scrivere
 * duecento millesimi bisognava battere `20000`.
 *
 * Sugli importi ha senso — si digitano i centesimi — ma i millesimi sono numeri tondi, e
 * costringere a contare gli zeri è peggio del problema che la maschera risolveva. Verificato a
 * video prima di tenerla.
 *
 * ## Cosa fa questa, invece
 *
 * Mentre si digita non tocca niente: `333.3` a metà parola resta `333.3`. All'uscita dal campo
 * porta il valore ai decimali dichiarati dalla tabella, e basta.
 *
 * ## Perché il punto e non la virgola
 *
 * Un primo giro usava la virgola, che è il separatore italiano e quello del resto del
 * gestionale. Sbagliato **qui**, per due ragioni. La prima: su questa pagina ogni altro campo
 * numerico — ultima lettura, quota fissa, coefficiente di dispersione — è un input grezzo col
 * punto, quindi la virgola rendeva i millesimi l'eccezione invece della regola. La seconda, che
 * pesa di più: col punto **il valore che si vede è quello che si salva**. Nessuna conversione
 * al `submit`, nessun `transform`, e nessuna ambiguità fra separatore decimale e delle migliaia
 * — che è esattamente da dove sono usciti tutti i guai di questa funzione.
 *
 * Accetta comunque anche la virgola in ingresso: chi la batte per abitudine non deve essere
 * corretto da un messaggio d'errore, gli si normalizza il valore e basta.
 */
const normalizzaAllaPrecisione = (v: unknown): string => {
  const grezzo = String(v ?? "").trim();
  if (grezzo === "") return "";

  const n = Number(grezzo.replace(",", "."));
  if (!Number.isFinite(n)) return grezzo; // Testo non numerico: lo rifiuta il server, con il suo messaggio.

  return n.toFixed(decimaliTabella);
};

/**
 * Impedisce di **digitare** più decimali di quanti la tabella ne dichiari.
 *
 * Il normalizzatore all'uscita dal campo non basta: fino al `blur` la casella accetta
 * `500.345`, e l'amministratore vede a schermo un numero che il sistema poi cambia sotto i suoi
 * occhi. Meglio non farglielo scrivere: su una tabella a due decimali il terzo non entra.
 *
 * **Tronca, non arrotonda** — `500.345` resta `500.34`. Arrotondare mentre si digita
 * significherebbe che battere una cifra ne cambia un'altra già scritta, che a schermo si legge
 * come un errore del programma.
 *
 * Cosa lascia passare di proposito: il separatore da solo (`500.` è uno stato legittimo di chi
 * sta per scrivere i decimali) e la virgola, che viene raddrizzata all'uscita — chi la batte per
 * abitudine non va corretto con un errore.
 *
 * Con `numero_decimali` a zero il separatore non si può proprio scrivere.
 */
const limitaDecimaliDigitati = (valore: string): string => {
  // Solo cifre e separatori: lettere e simboli non entrano.
  let s = valore.replace(/[^\d.,]/g, "");

  // Un separatore solo: i successivi si ignorano invece di produrre `1.2.3`.
  const primo = s.search(/[.,]/);
  if (primo !== -1) {
    s = s.slice(0, primo + 1) + s.slice(primo + 1).replace(/[.,]/g, "");
  }

  if (decimaliTabella === 0) return s.replace(/[.,]/g, "");

  const parti = s.match(/^(\d*)([.,])(\d*)$/);
  if (!parti) return s;

  return parti[1] + parti[2] + parti[3].slice(0, decimaliTabella);
};

/**
 * Applica il limite e **riallinea la casella**.
 *
 * Il `v-model` da solo non basta: quando il valore filtrato coincide con quello che il modello
 * aveva già, Vue non ridisegna il campo e a schermo resterebbe la cifra di troppo appena
 * battuta, pur non essendo nel modello. Scrivere `el.value` a mano chiude quella finestra.
 */
const onInputValore = (evento: Event, quota: { valore: string }): void => {
  const el = evento.target as HTMLInputElement;
  const pulito = limitaDecimaliDigitati(el.value);

  if (el.value !== pulito) el.value = pulito;
  quota.valore = pulito;
};

// Form separato a seconda del tipo tabella
const form = useForm({
  quote: rawMillesimi.map((q: Millesimo) => {
    if (props.tabella.tipo === "acqua") {
      return {
        id: q.id as number | null,
        immobile: q.immobile as Immobile | null, 
        valore: normalizzaAllaPrecisione(q.valore),
        has_contatore: q.coefficienti?.has_contatore ?? false,
        ultima_lettura: q.coefficienti?.ultima_lettura ?? ""
      }
    }

    if (props.tabella.tipo === "riscaldamento") {
      return {
        id: q.id as number | null,
        immobile: q.immobile as Immobile | null, 
        valore: normalizzaAllaPrecisione(q.valore),
        coeff_dispersione: q.coefficienti?.coeff_dispersione ?? "",
        quota_fissa: q.coefficienti?.quota_fissa ?? "",
        quota_variabile: q.coefficienti?.quota_variabile ?? ""
      }
    }

    return {
      id: q.id as number | null,
      immobile: q.immobile as Immobile | null, 
      valore: normalizzaAllaPrecisione(q.valore)
    }
  }),
});

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Tabelle', href: generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id }) },
  { title: props.tabella.nome, href: '#' },
  { title: 'Quote', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Assegnazione Quote',
    description: "Associa ogni unità immobiliare al corrispondente valore millesimale.",
    icon: TableIcon,
    colorVariant: 'blue' as const
  },
  {
    title: 'Parametri Specifici',
    description: "Configura campi aggiuntivi per tabelle di tipo acqua o riscaldamento.",
    icon: Info,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Totale sempre a vista',
    // Il testo precedente diceva «verifica che la somma corrisponda al limite previsto», e
    // prometteva due cose che non esistevano: una verifica (che il gestionale non fa) e un
    // limite (che non c'è — il totale giusto dipende dalla tabella, e lo sa l'amministratore).
    // Dalla beta.48 il totale c'è davvero, e questa riga dice quello che fa: mostrarlo.
    description: "In fondo all'elenco trovi la somma dei valori inseriti, aggiornata mentre digiti.",
    icon: Hash,
    colorVariant: 'amber' as const
  }
]);

// Calcola immobili disponibili
const immobiliDisponibili = computed(() => {
  const usedIds = form.quote.map((q: any) => q.immobile?.id).filter(Boolean);
  return rawImmobili.filter((i: Immobile) => !usedIds.includes(i.id));
});

const addImmobile = () => {
  const maxRows = rawImmobili.length;

  if (form.quote.length >= maxRows) {
    alertMessage.value = "Hai già raggiunto il numero massimo di righe consentite.";
    showNoImmobiliDialog.value = true;
    return;
  }

  let nuovoImmobile: any = {};

  if (props.tabella.tipo === "acqua") {
    nuovoImmobile = {
      id: null,
      valore: "",
      immobile: null,
      has_contatore: false,
      ultima_lettura: ""
    };
  } else if (props.tabella.tipo === "riscaldamento") {
    nuovoImmobile = {
      id: null,
      valore: "",
      immobile: null,
      coeff_dispersione: "",
      quota_fissa: "",
      quota_variabile: ""
    };
  } else {
    nuovoImmobile = {
      id: null,
      valore: "",
      immobile: null
    };
  }

  form.quote = [...form.quote, nuovoImmobile];
};

const removeImmobile = (index: number | string) => {
  form.quote.splice(Number(index), 1);
};

// Funzione per generare placeholder dinamico in base al numero di decimali
const valorePlaceholder = (decimali: number) => {
  if (!decimali || decimali < 0) return "0";
  return "0." + "0".repeat(decimali);
};



/* ─────────────────────────────────────────────────────────────────────────────
 * Il totale a video mentre si digita.
 *
 * Fino alla beta.48 questa pagina non aveva **nessun** totale: si compilavano i millesimi riga
 * per riga e ci si accorgeva di un refuso al primo riparto, o mai — perché il motore normalizza
 * su `valore / sommaValori`, quindi una tabella che somma a 900 ripartisce comunque il 100%
 * della spesa facendola pagare agli altri, e nessun controllo contabile ha niente da segnalare.
 *
 * Il posto giusto per dirlo è questo: qui il refuso si corregge nel momento in cui lo si batte.
 *
 * ⚠️ **Il totale non giudica, e non è una svista.** Non c'è confronto con 1000 né con nessun
 * altro valore atteso: sui dati veri nove tabelle su quindici non sommano a 1000 e sono tutte
 * corrette — parziali, a parti uguali, o arrotondate dal tecnico e approvate così in assemblea.
 * Il numero che l'amministratore deve vedere lo sa lui; il gestionale glielo mostra e tace.
 *
 * *(Un confronto con un totale dichiarato per tabella è stato progettato e messo da parte
 * l'11/08/2026 — vedi `docs/validatore_coerenza_millesimi.md`.)*
 * ───────────────────────────────────────────────────────────────────────────── */

/** Accetta sia `1000.5` sia `1000,5`: il placeholder usa il punto, le tastiere italiane no. */
const parseValore = (v: unknown): number => {
  const n = parseFloat(String(v ?? "").replace(",", "."));
  return Number.isFinite(n) ? n : 0;
};

const decimali = computed(() => Math.max(0, props.tabella.numero_decimali ?? 2));

/** Le stesse righe che conta il motore: i valori a zero non partecipano. */
const totaleCorrente = computed(() => {
  const somma = form.quote.reduce((acc, q) => {
    const v = parseValore(q.valore);
    return v > 0 ? acc + v : acc;
  }, 0);

  // Arrotonda ai decimali della tabella: senza, 333,33 × 3 uscirebbe 999,9899999999999.
  return Number(somma.toFixed(decimali.value));
});

/** Formatta con i decimali della tabella, senza zeri inutili: 1000 e non 1000,00. */
/**
 * Il totale si scrive **come i valori che somma**: stessi decimali, stesso separatore, nessun
 * raggruppamento delle migliaia.
 *
 * Niente `Intl.NumberFormat`, che qui produrrebbe la virgola e reintrodurrebbe due convenzioni
 * nella stessa colonna. Il punto delle migliaia sarebbe pure peggio: `1.200,00` sotto una
 * colonna di `500.00` è la stessa ambiguità che ha fatto leggere `333.33333` come
 * `333.333,33`.
 */
const formattaValore = (n: number) => n.toFixed(decimali.value);

const submit = () => {
  // Nessuna conversione prima di partire: le caselle tengono già il numero nella forma che il
  // server valida (`numeric`, quindi punto decimale). È il vantaggio pratico di aver scelto il
  // punto — quello che si vede è quello che si salva.
  //
  // Una virgola battuta per abitudine viene normalizzata all'uscita dal campo, quindi non
  // arriva fin qui; e se il campo non viene mai lasciato, `numeric` la rifiuta con il suo
  // messaggio invece di far passare un valore ambiguo.
  form.put(
    route("admin.gestionale.tabelle.quote.update", {
      condominio: props.condominio.id,
      tabella: props.tabella.id,
    }),
    { preserveScroll: true }
  );
};

</script>

<template>
  <Head title="Millesimi tabella" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Associazione millesimi"
        :page-subtitle="`Gestione delle quote per la tabella: ${props.tabella.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id })"
        back-text="Torna alle tabelle"
      >
      </PageHeaderGuide>

      <form @submit.prevent="submit" class="space-y-6">
        
        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-0 flex flex-row items-center justify-between">
            <div class="space-y-1">
              <CardTitle class="text-base font-semibold">Elenco Unità Associate</CardTitle>
              <CardDescription>Specifica i valori millesimali per ogni unità immobiliare.</CardDescription>
            </div>
            <Button 
              type="button" 
              @click="addImmobile" 
              class="h-8 px-4 text-[10px] font-bold uppercase tracking-widest gap-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 shadow-sm"
            >
              <Plus class="w-3.5 h-3.5" />
              Aggiungi Immobile
            </Button>
          </CardHeader>
          <CardContent class="p-0">
            <div class="overflow-x-auto">
              <Table>
                
                <TableHeader class="bg-slate-50/50 dark:bg-slate-900/50">
                  <TableRow>
                    <TableHead class="w-[500px]">Immobile</TableHead>
                    <TableHead>{{ props.tabella.quota.charAt(0).toUpperCase() + props.tabella.quota.slice(1) }}</TableHead>

                    <!-- Acqua -->
                    <TableHead v-if="props.tabella.tipo === 'acqua'" class="text-center">Contatore?</TableHead>
                    <TableHead v-if="props.tabella.tipo === 'acqua'">Ultima lettura (m³)</TableHead>

                    <!-- Riscaldamento -->
                    <TableHead v-if="props.tabella.tipo === 'riscaldamento'">Quota fissa (%)</TableHead>
                    <TableHead v-if="props.tabella.tipo === 'riscaldamento'">Quota variabile (%)</TableHead>
                    <TableHead v-if="props.tabella.tipo === 'riscaldamento'">Coeff. dispersione</TableHead>

                    <TableHead class="text-center w-[80px]">Azioni</TableHead>
                  </TableRow>
                </TableHeader>

                <TableBody>
                  <TableRow v-for="(q, idx) in form.quote" :key="q.id ?? idx" class="border-b border-dashed last:border-0">

                    <!-- Immobile -->
                    <TableCell>
                      <!-- Mostra come testo se l'immobile è già associato (ha un ID) -->
                      <div v-if="q.id && q.immobile">
                        <div class="font-medium">{{ q.immobile?.nome ?? '—' }}</div>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400">
                          Palazzina: {{ q.immobile?.palazzina?.name ?? "—" }} |
                          Scala: {{ q.immobile?.scala?.name ?? "—" }} |
                          Interno: {{ q.immobile?.interno ?? "—" }} |
                          Piano: {{ q.immobile?.piano ?? "—" }} |
                          Sup: {{ q.immobile?.superficie ?? "—" }} m²
                        </div>
                      </div>
                      
                      <!-- Mostra dropdown per selezionare un nuovo immobile -->
                      <div v-else>
                        <v-select
                          class="w-full vs--wide-dropdown bg-white dark:bg-slate-950 text-sm"
                          :options="immobiliDisponibili"
                          v-model="q.immobile"
                          append-to-body
                          placeholder="Seleziona immobile"
                          :reduce="(i: Immobile) => i"
                          :value="q.immobile"
                          @input="(value: Immobile) => { q.immobile = value }"
                          label="nome"
                          :getOptionLabel="(option: Immobile) => option.nome"
                        >
                          <!-- Template per le opzioni nel dropdown -->
                          <template #option="option">
                            <div class="flex flex-col py-2">
                              <span class="font-medium">{{ option.nome }}</span>
                              <span class="text-xs text-gray-500 mt-1">
                                Palazzina: {{ option.palazzina?.name ?? "—" }} |
                                Scala: {{ option.scala?.name ?? "—" }} |
                                Interno: {{ option.interno ?? "—" }} |
                                Piano: {{ option.piano ?? "—" }} |
                                Sup: {{ option.superficie ?? "—" }} m²
                              </span>
                            </div>
                          </template>

                          <!-- Template per l'opzione selezionata -->
                          <template #selected-option="option">
                            <div v-if="option" class="flex flex-col">
                              <span class="font-medium">{{ option.nome }}</span>
                            </div>
                            <div v-else class="text-gray-400">Seleziona immobile</div>
                          </template>
                        </v-select>
                        <InputError :message="(form.errors as Record<string, string>)[`quote.${idx}.immobile.id`]" />
                      </div>
                    </TableCell>

                    <!-- Millesimi -->
                    <TableCell>
                      <Input
                        v-model="q.valore"
                        class="w-28 bg-white dark:bg-slate-950"
                        inputmode="decimal"
                        :placeholder="valorePlaceholder(props.tabella.numero_decimali)"
                        @input="onInputValore($event, q)"
                        @blur="q.valore = normalizzaAllaPrecisione(q.valore)"
                      />
                      <InputError :message="(form.errors as Record<string, string>)[`quote.${idx}.valore`]" />
                    </TableCell>

                    <!-- Solo acqua -->
                    <TableCell v-if="props.tabella.tipo === 'acqua'" class="text-center">
                      <input type="checkbox" v-model="q.has_contatore" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" />
                    </TableCell>
                    <TableCell v-if="props.tabella.tipo === 'acqua'">
                      <Input v-if="q.has_contatore" v-model="q.ultima_lettura" class="w-28 bg-white dark:bg-slate-950" placeholder="m³" />
                      <Input v-else class="w-28 bg-slate-100 text-gray-400" value="—" disabled />
                    </TableCell>

                    <!-- Solo riscaldamento -->
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                      <Input v-model="q.quota_fissa" class="w-28 bg-white dark:bg-slate-950" placeholder="%" />
                    </TableCell>
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                      <Input v-model="q.quota_variabile" class="w-28 bg-white dark:bg-slate-950" placeholder="%" />
                    </TableCell>
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                      <Input v-model="q.coeff_dispersione" class="w-28 bg-white dark:bg-slate-950" placeholder="Coeff." />
                    </TableCell>

                    <!-- Azioni -->
                    <TableCell class="text-center">
                      <Button size="icon" variant="ghost" @click="removeImmobile(idx)" class="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/50">
                        <Trash2 class="h-4 w-4" />
                      </Button>
                    </TableCell>
                  </TableRow>

                </TableBody>

                <!--
                  Il totale sta in un `<tfoot>` vero, non in una riga in fondo al corpo: è
                  un'informazione sulla tabella, non un'altra unità immobiliare, e il tag lo dice
                  anche a chi legge con uno screen reader.

                  Si somma sulle righe a schermo e non si chiede al server: deve muoversi mentre
                  si digita, o non serve a niente. E non blocca il salvataggio — una tabella si
                  compila una riga per volta, e le righe intermedie per definizione non tornano.
                -->
                <TableFooter v-if="form.quote.length">
                  <TableRow class="hover:bg-transparent">
                    <!-- Stessa struttura delle righe sopra: etichetta e sotto-riga di contesto. -->
                    <TableCell>
                      <div class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
                        Totale
                      </div>
                      <div class="text-[11px] text-slate-500 dark:text-slate-400 font-normal">
                        {{ form.quote.length }} {{ form.quote.length === 1 ? 'unità associata' : 'unità associate' }}
                      </div>
                    </TableCell>

                    <!--
                      Stessa geometria delle caselle della colonna (`h-9 w-28 rounded-md px-3`),
                      così il numero cade esattamente sotto i valori invece di galleggiare a
                      sinistra. Ma **non è un `<input>`**, ed è deliberato: il totale è calcolato,
                      non digitabile, e una casella disabilitata si legge come «potresti
                      modificarlo, ma adesso no». Il bordo tratteggiato e il fondo pieno dicono
                      «stessa colonna, altro mestiere».
                    -->
                    <TableCell>
                      <div
                        class="inline-flex h-9 w-28 items-center rounded-md border border-dashed border-slate-300 bg-slate-100/70 px-3 text-sm font-bold text-slate-900 tabular-nums dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-100"
                      >
                        {{ formattaValore(totaleCorrente) }}
                      </div>
                    </TableCell>

                    <TableCell v-if="props.tabella.tipo === 'acqua'"></TableCell>
                    <TableCell v-if="props.tabella.tipo === 'acqua'"></TableCell>
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'"></TableCell>
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'"></TableCell>
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'"></TableCell>
                    <TableCell></TableCell>
                  </TableRow>
                </TableFooter>

              </Table>
            </div>
          </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
          <Link
            :href="route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id })"
            class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-6 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
          >
            Annulla
          </Link>

          <Button 
            type="submit"
            :disabled="form.processing" 
            class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2"
          >
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            <Plus v-else class="h-3.5 w-3.5" />
            Salva Quote
          </Button>
        </div>

      </form>
    </div>
  </GestionaleLayout>

  <!-- Dialog se non ci sono immobili disponibili -->
  <AlertDialog v-model:open="showNoImmobiliDialog">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Attenzione</AlertDialogTitle>
        <AlertDialogDescription>
          {{ alertMessage }}
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Chiudi</AlertDialogCancel>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

</template>

<style src="vue-select/dist/vue-select.css"></style>
