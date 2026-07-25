<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import ModalLiquiditaGiaVersato from '@/components/gestionale/contributi/ModalLiquiditaGiaVersato.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import type { Building } from '@/types/buildings';
import {
  Wallet, Scale, ArrowRight, Sparkles, CheckCircle2,
  AlertTriangle, Info, LoaderCircle, Building2,
} from 'lucide-vue-next';

interface Riga {
  immobile_id: number;
  nome: string;
  interno: string | null;
  millesimi: number;
  quota_lorda: number;   // centesimi
  gia_versato: number;   // centesimi
}

interface CassaOpzione {
  id: number;
  nome: string;
  tipo: string;
  saldo_cents: number;
  is_utilizzabile_per_imprevisti: boolean;
}

const props = defineProps<{
  condominio: Building;
  voce: { id: number; nome: string; importo_cents: number; gestione: string | null };
  righe: Riga[];
  natura: 'fondo_vincolato' | 'avanzo';
  descrizione: string | null;
  // true quando questa voce ha ripartizioni per soggetto non standard
  // (proprietario/inquilino) o unità senza un proprietario attivo: la "Quota
  // dovuta" qui sotto è una stima sui soli millesimi, il motore di riparto reale
  // al momento della generazione del piano rate può calcolare un valore diverso.
  stima_semplificata: boolean;
  // true quando la voce ha una ripartizione per soggetto (proprietario/inquilino):
  // il "già versato" è per unità, non per soggetto — un versamento fatto da uno
  // solo dei due finisce comunque per scontare anche l'altro (D8, docs/fondo_
  // accantonato_e_quadratura_sp.md). Non bloccato: solo segnalato.
  ripartizione_mista: boolean;
  // "Dove sono questi soldi?" (D8-bis). null finché non ancora dichiarato per
  // questa voce: in tal caso la modale si apre alla prima dichiarazione con
  // già-versato > 0 e non viene più richiesta sui salvataggi successivi.
  liquidita_stato: 'registrata_in_cassa' | 'gia_speso_acconto' | null;
  cassa_id: number | null;
  casse: CassaOpzione[];
}>();

const { euro } = useCurrencyFormatter({ fromCents: true });
const { generatePath } = usePermission();
const { toast } = useToast();

// ── Stato ──────────────────────────────────────────────────────────────────
// Gli importi si editano in EURO (stringa), si inviano in centesimi.
const versato = ref<Record<number, string>>(
  Object.fromEntries(props.righe.map(r => [r.immobile_id, (r.gia_versato / 100).toFixed(2)]))
);

const natura = ref(props.natura);
// Riparte da quanto già salvato: senza questo, ogni ri-salvataggio che non
// tocca il campo lo azzererebbe (il backend sostituisce, non fa merge).
const descrizione = ref(props.descrizione ?? '');
const totaleRaccolto = ref('');
const salvando = ref(false);

// ── "Dove sono questi soldi?" (D8-bis) ──────────────────────────────────────
// Una volta dichiarato (qui o in un salvataggio precedente), va RIMANDATO ad
// ogni resave: il backend sostituisce integralmente le righe di questa voce,
// quindi se non lo reinviamo la dichiarazione andrebbe persa silenziosamente
// e il salvataggio successivo la richiederebbe di nuovo da capo.
const liquiditaStato = ref(props.liquidita_stato);
const cassaIdSelezionata = ref(props.cassa_id);
// Solo per la primissima dichiarazione: non è persistita sulla riga, serve
// solo a comporre il promemoria Inbox — non va rimandata sui resave.
const notaAccontoTransiente = ref('');
const mostraModaleLiquidita = ref(false);

// Formato italiano, identico al resto del gestionale: senza queste opzioni v-money3
// usa i suoi default inglesi (decimale ".", migliaia ",") e `parse` sotto leggerebbe
// "1,500.00" come 1,50 — un errore di mille volte sull'importo.
const moneyOptions = ref({
  prefix: '',
  suffix: '',
  thousands: '.',
  decimal: ',',
  precision: 2,
  allowBlank: true,
  masked: true,
  disableNegative: true,
});

const parse = (v: string | number | undefined): number => {
  if (v === undefined || v === null || v === '') return 0;
  if (typeof v === 'number') return v;
  const s = String(v).trim();
  const n = s.includes(',')
    ? Number(s.replace(/\./g, '').replace(',', '.'))
    : Number(s);
  return Number.isFinite(n) ? n : 0;
};

const cents = (v: string | number | undefined) => Math.round(parse(v) * 100);

// ── Il gesto che fa risparmiare tempo ──────────────────────────────────────
// L'accantonamento si raccoglie quasi sempre per millesimi: l'amministratore
// digita il totale una volta sola e il sistema lo ripartisce, penny-perfect.
const millesimiTotali = computed(() => props.righe.reduce((a, r) => a + r.millesimi, 0));

const ripartisciPerMillesimi = () => {
  const totale = cents(totaleRaccolto.value);
  if (totale <= 0 || millesimiTotali.value <= 0) return;

  let assegnato = 0;
  props.righe.forEach((r, i) => {
    const quota = i === props.righe.length - 1
      ? totale - assegnato                                     // l'ultimo assorbe il resto
      : Math.floor((totale * r.millesimi) / millesimiTotali.value);
    assegnato += quota;
    versato.value[r.immobile_id] = (quota / 100).toFixed(2);
  });
};

const azzera = () => {
  props.righe.forEach(r => { versato.value[r.immobile_id] = '0.00'; });
};

// ── Effetto sul riparto, calcolato mentre si digita ─────────────────────────
const righeCalcolate = computed(() =>
  props.righe.map(r => {
    const versatoC = cents(versato.value[r.immobile_id]);
    const residuo = Math.max(0, r.quota_lorda - versatoC);
    return {
      ...r,
      versato_cents: versatoC,
      residuo,
      eccedenza: Math.max(0, versatoC - r.quota_lorda),
    };
  })
);

const totali = computed(() => {
  const c = righeCalcolate.value;
  return {
    lordo:     c.reduce((a, r) => a + r.quota_lorda, 0),
    versato:   c.reduce((a, r) => a + r.versato_cents, 0),
    residuo:   c.reduce((a, r) => a + r.residuo, 0),
    eccedenza: c.reduce((a, r) => a + r.eccedenza, 0),
  };
});

const scarto = computed(() => cents(totaleRaccolto.value) - totali.value.versato);
const haScarto = computed(() => cents(totaleRaccolto.value) > 0 && scarto.value !== 0);

// ── Salvataggio ────────────────────────────────────────────────────────────
// La domanda "dove sono questi soldi?" va posta una volta sola, alla prima
// dichiarazione di un già versato > 0 per questa voce: né prima (non c'è
// ancora nulla da localizzare), né sui resave successivi (il backend la
// ignorerebbe comunque — vedi ContributoVersatoController::update()).
const richiedeDichiarazioneLiquidita = computed(() =>
  liquiditaStato.value === null && totali.value.versato > 0
);

const salva = () => {
  if (richiedeDichiarazioneLiquidita.value) {
    mostraModaleLiquidita.value = true;
    return;
  }
  eseguiSalvataggio();
};

const onConfirmLiquidita = (payload: {
  liquiditaStato: 'registrata_in_cassa' | 'gia_speso_acconto';
  cassaId: number | null;
  notaAcconto: string;
}) => {
  liquiditaStato.value = payload.liquiditaStato;
  cassaIdSelezionata.value = payload.cassaId;
  notaAccontoTransiente.value = payload.notaAcconto;
  mostraModaleLiquidita.value = false;
  eseguiSalvataggio();
};

const eseguiSalvataggio = () => {
  salvando.value = true;
  router.put(
    generatePath('gestionale/:condominio/contributi/:conto', {
      condominio: props.condominio.id,
      conto: props.voce.id,
    }),
    {
      natura: natura.value,
      descrizione: descrizione.value || null,
      righe: props.righe.map(r => ({
        immobile_id: r.immobile_id,
        gia_versato: cents(versato.value[r.immobile_id]),
      })),
      liquidita_stato: liquiditaStato.value,
      cassa_id: liquiditaStato.value === 'registrata_in_cassa' ? cassaIdSelezionata.value : null,
      nota_acconto: notaAccontoTransiente.value || null,
    },
    {
      preserveScroll: true,
      onSuccess: () => {
        // Consumata: non deve essere rimandata su un eventuale prossimo resave.
        notaAccontoTransiente.value = '';
        toast({ title: 'Salvato', description: 'Contributi già versati aggiornati.', variant: 'default' });
      },
      // Senza questo handler un 422 (validazione fallita, es. un immobile non
      // di questo condominio) non dava alcun segnale: il pulsante si
      // riabilitava e la pagina restava lì, senza dire perché nulla è cambiato.
      onError: (errors) => {
        const primo = Object.values(errors)[0];
        toast({
          title: 'Salvataggio non riuscito',
          description: typeof primo === 'string' ? primo : 'Controlla i dati inseriti e riprova.',
          variant: 'destructive',
        });
        // Se l'errore riguarda proprio la dichiarazione (es. cassa_id), la
        // dichiarazione locale non era in realtà completa: la si azzera così
        // il prossimo tentativo di salvataggio riapre la modale invece di
        // ripetere silenziosamente lo stesso invio incompleto.
        if (errors.cassa_id || errors.nota_acconto) {
          liquiditaStato.value = null;
        }
      },
      onFinish: () => { salvando.value = false; },
    }
  );
};

const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Struttura', href: '#' },
  { title: 'Già versato', href: '#' },
]);

const guide = [
  {
    title: 'A cosa serve',
    description: 'Registra quanto ogni unità ha già versato per questa spesa, tipicamente un accantonamento raccolto prima di passare a Kondomanager.',
    icon: Wallet,
    colorVariant: 'emerald' as const,
  },
  {
    title: 'Effetto immediato',
    description: 'Il riparto chiederà a ciascuno solo il residuo. Senza questo dato, la spesa verrebbe richiesta una seconda volta.',
    icon: Scale,
    colorVariant: 'blue' as const,
  },
  {
    title: 'Compilazione rapida',
    description: 'Se conosci solo il totale raccolto, digitalo una volta sola: lo distribuiamo per millesimi, penny-perfect. Ogni riga resta comunque correggibile a mano.',
    icon: Sparkles,
    colorVariant: 'amber' as const,
  },
];
</script>

<template>
  <Head :title="`Già versato — ${voce.nome}`" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Quanto è già stato versato"
        :page-subtitle="`Voce di spesa: ${voce.nome}`"
        :guides="guide"
        :breadcrumbs="headerBreadcrumbs"
        :condominio="condominio"
      />

      <div class="w-full">
        <StrutturaLayout>
          <div class="container mx-auto p-0 mt-4 space-y-6">

      <!-- ═══ 1. NATURA — è una qualificazione giuridica, non un'etichetta ═══ -->
      <div class="bg-white border rounded-2xl p-6">
        <div class="flex items-start gap-2 mb-4">
          <span class="text-[10px] font-black uppercase tracking-widest text-slate-500">
            1. Che cosa sono questi versamenti?
          </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label
            class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all"
            :class="natura === 'fondo_vincolato'
              ? 'bg-emerald-50 border-emerald-400 ring-1 ring-emerald-400 shadow-sm'
              : 'border-slate-200 hover:bg-slate-50'">
            <div class="flex items-start justify-between mb-2">
              <span class="font-bold text-sm"
                :class="natura === 'fondo_vincolato' ? 'text-emerald-800' : 'text-slate-700'">
                Fondo deliberato
              </span>
              <input type="radio" v-model="natura" value="fondo_vincolato"
                class="w-4 h-4 mt-0.5 text-emerald-600 focus:ring-emerald-600" />
            </div>
            <p class="text-[11px] leading-relaxed"
              :class="natura === 'fondo_vincolato' ? 'text-emerald-700/80' : 'text-slate-500'">
              L'assemblea ha costituito un fondo per quest'opera (art. 1135 c.c.).
              Le somme hanno un <strong>vincolo di destinazione</strong>: non possono
              essere usate per altro senza una nuova delibera.
            </p>
          </label>

          <label
            class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all"
            :class="natura === 'avanzo'
              ? 'bg-blue-50 border-blue-400 ring-1 ring-blue-400 shadow-sm'
              : 'border-slate-200 hover:bg-slate-50'">
            <div class="flex items-start justify-between mb-2">
              <span class="font-bold text-sm"
                :class="natura === 'avanzo' ? 'text-blue-800' : 'text-slate-700'">
                Rate già riscosse
              </span>
              <input type="radio" v-model="natura" value="avanzo"
                class="w-4 h-4 mt-0.5 text-blue-600 focus:ring-blue-600" />
            </div>
            <p class="text-[11px] leading-relaxed"
              :class="natura === 'avanzo' ? 'text-blue-700/80' : 'text-slate-500'">
              Somme incassate dai condòmini ma non ancora spese, <strong>senza un fondo
              deliberato</strong>. Restano conguagliabili a fine gestione.
            </p>
          </label>
        </div>

        <div class="mt-4 flex gap-2.5 p-3 bg-slate-50 border border-slate-100 rounded-xl">
          <Info class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" />
          <p class="text-[11px] text-slate-500 leading-relaxed">
            La distinzione la stabilisce la <strong>delibera</strong>, non il software:
            attribuire un vincolo che l'assemblea non ha deliberato — o ignorarne uno
            esistente — è un problema legale, non contabile.
          </p>
        </div>

        <div v-if="ripartizione_mista"
          class="mt-3 flex gap-2.5 p-3 bg-amber-50 border border-amber-200 rounded-xl">
          <AlertTriangle class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
          <p class="text-[11px] text-amber-800 leading-relaxed">
            Questa voce ha una <strong>ripartizione per soggetto</strong> (proprietario/
            inquilino). Il già versato è registrato per unità, non per soggetto: viene
            sottratto dal totale dell'immobile <strong>prima</strong> di dividerlo fra
            proprietario e inquilino. Se il versamento riguarda solo uno dei due,
            verifica a mano il piano rate generato — il software non lo distingue.
          </p>
        </div>
      </div>

      <!-- ═══ 2. IL GESTO CHE FA RISPARMIARE TEMPO ═══ -->
      <div class="bg-gradient-to-br from-indigo-50 to-white border border-indigo-100 rounded-2xl p-6">
        <div class="flex items-center gap-2 mb-1">
          <Sparkles class="w-4 h-4 text-indigo-500" />
          <span class="text-[10px] font-black uppercase tracking-widest text-indigo-600">
            2. Compilazione rapida
          </span>
        </div>
        <p class="text-xs text-slate-500 mb-4">
          L'accantonamento si raccoglie quasi sempre per millesimi: digita il totale una
          volta sola e lo distribuiamo noi, al centesimo.
        </p>

        <div class="flex flex-col sm:flex-row gap-3 sm:items-end">
          <div class="flex-1 max-w-xs">
            <Label class="text-[11px] font-bold text-slate-600">Totale già raccolto</Label>
            <div class="relative mt-1">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold">€</span>
              <MoneyInput v-model="totaleRaccolto" :money-options="moneyOptions"
                class="pl-7 h-10 font-bold" />
            </div>
          </div>
          <Button type="button" class="h-10 gap-2" @click="ripartisciPerMillesimi">
            <Scale class="w-4 h-4" /> Ripartisci per millesimi
          </Button>
          <Button type="button" variant="outline" class="h-10" @click="azzera">
            Azzera
          </Button>
        </div>

        <div v-if="haScarto"
          class="mt-3 flex items-center gap-2 text-[11px] font-bold px-3 py-2 rounded-lg"
          :class="scarto > 0 ? 'text-amber-700 bg-amber-50 border border-amber-200'
                             : 'text-rose-700 bg-rose-50 border border-rose-200'">
          <AlertTriangle class="w-3.5 h-3.5" />
          <span v-if="scarto > 0">
            Mancano {{ euro(scarto) }} rispetto al totale dichiarato.
          </span>
          <span v-else>
            Hai distribuito {{ euro(-scarto) }} in più rispetto al totale dichiarato.
          </span>
        </div>
      </div>

      <!-- ═══ 3. TABELLA CON EFFETTO IN TEMPO REALE ═══ -->
      <div class="bg-white border rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b bg-slate-50/60 flex items-center justify-between">
          <div>
            <span class="text-[10px] font-black uppercase tracking-widest text-slate-500">
              3. Dettaglio per unità
            </span>
            <p class="text-xs text-slate-400 mt-0.5">
              Spesa totale {{ euro(voce.importo_cents) }} — puoi correggere i singoli importi
            </p>
          </div>
        </div>

        <div v-if="stima_semplificata"
          class="mx-6 mt-4 flex items-start gap-2 text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5">
          <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" />
          <span>
            Questa voce ha ripartizioni per soggetto (proprietario/inquilino) o unità
            senza un proprietario attivo: la <strong>quota dovuta</strong> qui sotto è
            una stima sui soli millesimi. Il piano rate reale, generato dal motore di
            riparto, può calcolare un importo diverso per le unità coinvolte — il
            "già versato" resta comunque corretto e verrà applicato regolarmente.
          </span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-[10px] uppercase tracking-wide text-slate-400 border-b">
                <th class="text-left px-6 py-3 font-bold">Unità</th>
                <th class="text-right px-3 py-3 font-bold">Millesimi</th>
                <th class="text-right px-3 py-3 font-bold">Quota dovuta</th>
                <th class="text-right px-3 py-3 font-bold">Già versato</th>
                <th class="text-right px-6 py-3 font-bold bg-emerald-50/60 text-emerald-700">
                  Da chiedere ora
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="r in righeCalcolate" :key="r.immobile_id" class="hover:bg-slate-50/60">
                <td class="px-6 py-2.5">
                  <div class="flex items-center gap-2">
                    <Building2 class="w-3.5 h-3.5 text-slate-300" />
                    <div>
                      <div class="font-semibold text-slate-800 text-xs">{{ r.nome }}</div>
                      <div v-if="r.interno" class="text-[10px] text-slate-400">Int. {{ r.interno }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-3 py-2.5 text-right text-xs text-slate-500 tabular-nums">
                  {{ r.millesimi }}
                </td>
                <td class="px-3 py-2.5 text-right text-xs text-slate-600 tabular-nums">
                  {{ euro(r.quota_lorda) }}
                </td>
                <td class="px-3 py-2.5">
                  <div class="relative w-32 ml-auto">
                    <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-[10px] font-bold">€</span>
                    <MoneyInput v-model="versato[r.immobile_id]" :money-options="moneyOptions"
                      class="pl-5 h-8 text-right text-xs font-semibold" />
                  </div>
                </td>
                <td class="px-6 py-2.5 text-right bg-emerald-50/40">
                  <span class="font-bold text-sm tabular-nums"
                    :class="r.residuo === 0 ? 'text-emerald-600' : 'text-slate-800'">
                    {{ euro(r.residuo) }}
                  </span>
                  <div v-if="r.eccedenza > 0" class="text-[10px] text-amber-600 font-semibold mt-0.5">
                    +{{ euro(r.eccedenza) }} in eccesso
                  </div>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-slate-200 bg-slate-50 font-bold text-xs">
                <td class="px-6 py-3">Totali</td>
                <td class="px-3 py-3 text-right tabular-nums text-slate-500">{{ millesimiTotali.toFixed(2) }}</td>
                <td class="px-3 py-3 text-right tabular-nums">{{ euro(totali.lordo) }}</td>
                <td class="px-3 py-3 text-right tabular-nums text-indigo-700">{{ euro(totali.versato) }}</td>
                <td class="px-6 py-3 text-right tabular-nums text-emerald-700 bg-emerald-50">
                  {{ euro(totali.residuo) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- ═══ 4. IL PRIMA/DOPO — la conseguenza, prima di salvare ═══ -->
      <div class="bg-slate-900 rounded-2xl p-6 text-white relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.07]"
          style="background-image:linear-gradient(rgba(255,255,255,.5) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.5) 1px,transparent 1px);background-size:28px 28px"></div>

        <div class="relative z-10">
          <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">
            Effetto sul prossimo piano rate
          </span>

          <div class="mt-4 flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
            <div class="flex-1 bg-white/5 border border-white/10 rounded-xl p-4">
              <div class="text-[10px] uppercase tracking-wide text-slate-400 font-bold">Senza questo dato</div>
              <div class="text-2xl font-black mt-1 tabular-nums text-rose-300">{{ euro(totali.lordo) }}</div>
              <div class="text-[11px] text-slate-400 mt-1">richiesti ai condòmini</div>
            </div>

            <ArrowRight class="w-6 h-6 text-slate-500 mx-auto shrink-0 rotate-90 sm:rotate-0" />

            <div class="flex-1 bg-emerald-500/10 border border-emerald-400/30 rounded-xl p-4">
              <div class="text-[10px] uppercase tracking-wide text-emerald-300/80 font-bold">Con il già versato</div>
              <div class="text-2xl font-black mt-1 tabular-nums text-emerald-300">{{ euro(totali.residuo) }}</div>
              <div class="text-[11px] text-emerald-200/60 mt-1">solo il residuo dovuto</div>
            </div>
          </div>

          <div v-if="totali.versato > 0"
            class="mt-4 flex items-center gap-2 text-[11px] text-slate-300">
            <CheckCircle2 class="w-4 h-4 text-emerald-400 shrink-0" />
            <span>
              <strong class="text-white">{{ euro(totali.versato) }}</strong> già versati non
              verranno richiesti una seconda volta.
            </span>
          </div>

          <div v-if="totali.eccedenza > 0"
            class="mt-2 flex items-center gap-2 text-[11px] text-amber-300">
            <AlertTriangle class="w-4 h-4 shrink-0" />
            <span>
              <strong>{{ euro(totali.eccedenza) }}</strong> versati in eccesso: da restituire
              o conguagliare, non vengono persi.
            </span>
          </div>
        </div>
      </div>

      <!-- ═══ 5. NOTA + SALVA ═══ -->
      <div class="bg-white border rounded-2xl p-6 flex flex-col sm:flex-row gap-4 sm:items-end">
        <div class="flex-1">
          <Label class="text-[11px] font-bold text-slate-600">
            Nota (facoltativa) — es. estremi della delibera
          </Label>
          <Input v-model="descrizione" class="mt-1 h-10 text-sm"
            placeholder="Delibera assembleare del 12/05/2025" />
        </div>
        <Button class="h-11 px-8 font-bold gap-2" :disabled="salvando" @click="salva">
          <LoaderCircle v-if="salvando" class="w-4 h-4 animate-spin" />
          <CheckCircle2 v-else class="w-4 h-4" />
          Salva
        </Button>
      </div>

          </div>
        </StrutturaLayout>
      </div>

    </div>

    <ModalLiquiditaGiaVersato
      :show="mostraModaleLiquidita"
      :totale-gia-versato="totali.versato"
      :casse="casse"
      :natura="natura"
      :is-processing="salvando"
      @update:show="mostraModaleLiquidita = $event"
      @confirm="onConfirmLiquidita"
    />
  </GestionaleLayout>
</template>
