<script setup lang="ts">

import { ref, reactive, computed, watch } from "vue";
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import { useDateConverter } from '@/composables/useDateConverter';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import Heading from '@/components/Heading.vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Separator } from '@/components/ui/separator';
import { Checkbox } from '@/components/ui/checkbox';
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ModalSpostaSpesa from '@/components/gestionale/pianiRate/ModalSpostaSpesa.vue';
import BudgetHistoryPopover from '@/components/gestionale/pianiRate/BudgetHistoryPopover.vue';
import { BellRing, List, Layers, CheckCircle2, AlertCircle, Clock, History, AlertTriangle, Ban, PieChart, Coins, RotateCw, Info, Wallet, Lock, RotateCcw, XCircle, Trash2, ChevronDown, ChevronUp, ArrowRightLeft, Gavel } from "lucide-vue-next";
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import Alert from "@/components/Alert.vue";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import type { BreadcrumbItem } from '@/types';
import type { Building } from "@/types/buildings";
import type { Esercizio } from "@/types/gestionale/esercizi";
import type { Flash } from '@/types/flash';

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  pianoRate: any,
  quotePerAnagrafica: any[],
  quotePerImmobile: any[],
  ratePure: any[],
  needsMigration: boolean;
  sources: Array<any>;      
  destinations: Array<any>; 
  has_unpublished_rates?: boolean;
  copertura: {
      scoperto_count: number;
      orfani: Array<{ id: number; nome: string; importo: number }>;
  } | null; 
}>()

const { generatePath, generateRoute } = usePermission();
const { toItalian } = useDateConverter();
const { euro } = useCurrencyFormatter();

const switchState = ref(props.pianoRate.stato === 'approvato');
const isProcessingStatus = ref(false);
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const showApprovazioneModal = ref(false);

const formApprovazione = ref({
    data_delibera_assemblea: new Date().toISOString().split('T')[0],
    numero_verbale: '',
    nota_approvazione: '',
});

const orphanCheckboxes = reactive<Record<number, boolean>>({});

const itemToDelete = ref<{ id: number, nome: string, is_parent: boolean, importo: number } | null>(null);
const isDeleteItemModalOpen = ref(false);
const isCapitoliExpanded = ref(false);
const isReloadingCapitoli = ref(false);
const isSpostaSpesaOpen = ref(false); 

const isDisallineato = computed(() => {
    if (!props.pianoRate.capitoli || !aggregates.value) return false;
    const totaleVoci = props.pianoRate.capitoli.reduce((acc: number, cap: any) => acc + (cap.importo || 0), 0);
    if (aggregates.value.totaleTeorico === 0) return false;
    const saldiPregressi = (props.quotePerAnagrafica || []).reduce((sum, item) => {
        return sum + (item.saldo_iniziale || 0);
    }, 0);
    const totaleRatePuro = aggregates.value.totaleTeorico - saldiPregressi;
    return Math.abs(totaleVoci - totaleRatePuro) > 200;
});

const confirmDetachItem = (capitolo: any) => {
    if (aggregates.value.totaleVersato > 0) {
        showFeedback('Azione bloccata', 'Non puoi rimuovere voci se ci sono incassi registrati.', true);
        return;
    }
    const movimenti = props.pianoRate.budget_movements || [];
    const capId = Number(capitolo.id); 
    const isDestination = movimenti.some((m: any) => Number(m.destination_conto_id) === capId);
    const isSource = movimenti.some((m: any) => Number(m.source_conto_id) === capId);
    if (isDestination || isSource) {
        let msg = `Il capitolo "${capitolo.nome}" è vincolato. `;
        if (isDestination) msg += `Ha RICEVUTO fondi extra da altre voci. `;
        else msg += `Ha FINANZIATO altre voci (Sposta Spesa). `;
        msg += `Per mantenere la coerenza contabile, devi annullare questi movimenti (restituendo i fondi) prima di eliminare la voce.`;
        showFeedback('Voce bloccata da movimenti', msg, true);
        return; 
    }
    itemToDelete.value = capitolo;
    isDeleteItemModalOpen.value = true;
};

const toggleCapitoli = () => {
    if (!isCapitoliExpanded.value) {
        isReloadingCapitoli.value = true;
        router.reload({ 
            only: ['pianoRate'], 
            onFinish: () => {
                isReloadingCapitoli.value = true; 
                setTimeout(() => {
                    isCapitoliExpanded.value = true;
                    isReloadingCapitoli.value = false;
                }, 100);
            }
        });
    } else {
        isCapitoliExpanded.value = false;
    }
};

const executeDetachItem = () => {
    if (!itemToDelete.value) return;
    router.delete(route('admin.gestionale.piani-rate.capitoli.detach', {
        condominio: props.condominio.id,
        esercizio: props.esercizio.id,
        pianoRate: props.pianoRate.id,
        capitolo: itemToDelete.value.id
    }), {
        preserveScroll: true,
        onSuccess: () => {
            isDeleteItemModalOpen.value = false;
            itemToDelete.value = null;
            showFeedback('Voce rimossa', 'Il piano è stato ricalcolato senza la voce selezionata.', false);
        },
        onError: (errors) => {
            isDeleteItemModalOpen.value = false;
            const msg = page.props.flash.message?.message || Object.values(errors)[0] || "Errore durante la rimozione.";
            showFeedback('Impossibile rimuovere', msg, true);
        }
    });
};

const selectedOrphanIds = computed(() => {
    return Object.entries(orphanCheckboxes)
        .filter(([_, isChecked]) => isChecked)
        .map(([id, _]) => Number(id));
});

watch(() => props.pianoRate.stato, (newVal) => {
    switchState.value = newVal === 'approvato';
});

const feedbackDialog = ref({
    open: false,
    title: '',
    message: '',
    isError: false
});

const showFeedback = (title: string, message: string, isError: boolean = false) => {
    feedbackDialog.value = { open: true, title, message, isError };
    if (page.props.flash) {
        (page.props.flash as any).message = undefined;
    }
};

const isMigrationDialogOpen = ref(props.needsMigration);

const executeMigration = () => {
    router.post(route(generateRoute('gestionale.esercizi.piani-rate.regenerate'), { 
        condominio: props.condominio.id, 
        esercizio: props.esercizio.id,
        pianoRate: props.pianoRate.id 
    }), {}, { 
        preserveScroll: true,
        onSuccess: () => {
            isMigrationDialogOpen.value = false;
            showFeedback('Aggiornamento completato', 'Il piano rate è stato aggiornato alla nuova versione contabile (v1.8).', false);
        },
        onError: () => {
             isMigrationDialogOpen.value = false; 
             showFeedback('Attenzione', 'Impossibile aggiornare automaticamente il piano rate (probabilmente ci sono rate emesse). Il piano resterà nella versione precedente.', true);
        }
    });
};

const isAlertOpen = ref(false);
const rataToAnnullareId = ref<number | null>(null);
const isRecalculateAlertOpen = ref(false);

const toggleStatoPiano = (newValue: boolean) => {
    if (isProcessingStatus.value) return;
    if (!newValue) {
        eseguiCambioStato(false, {});
        return;
    }
    formApprovazione.value = {
        data_delibera_assemblea: new Date().toISOString().split('T')[0],
        numero_verbale: '',
        nota_approvazione: '',
    };
    showApprovazioneModal.value = true;
};

const confermaApprovazione = () => {
    if (!formApprovazione.value.data_delibera_assemblea) return;
    showApprovazioneModal.value = false;
    eseguiCambioStato(true, formApprovazione.value);
};

const annullaApprovazione = () => {
    showApprovazioneModal.value = false;
    switchState.value = props.pianoRate.stato === 'approvato'; 
};

const eseguiCambioStato = (newValue: boolean, extra: object) => {
    isProcessingStatus.value = true;
    router.put(
        route('admin.gestionale.piani-rate.update-stato', {
            condominio: props.condominio.id,
            esercizio: props.esercizio.id, 
            pianoRate: props.pianoRate.id,
        }),
        { approvato: newValue, ...extra },
        {
            preserveScroll: true,
            replace: true,
            onSuccess: () => {
                // Inertia v2: invalida la cache della pagina piano dei conti
                // in modo che il back button non serva dati stantii
                router.flushAll()
            },
            onError: (err) => {
                console.error("Errore cambio stato:", err);
                switchState.value = !newValue;
                showFeedback('Errore', 'Impossibile cambiare lo stato del piano.', true);
            },
            onFinish: () => { isProcessingStatus.value = false; }
        }
    );
};

const tab = ref<"anagrafica" | "immobile">("anagrafica");
const showOnlyCredits = ref(false);
const today = new Date();
today.setHours(0, 0, 0, 0);

const selectedRateIds = ref<number[]>([]);
const isEmissionModalOpen = ref(false);

const emettibili = computed(() => props.ratePure?.filter(r => !r.is_emessa) || []);
const isAllSelected = computed(() => emettibili.value.length > 0 && selectedRateIds.value.length === emettibili.value.length);

const toggleSelectAll = (checked: boolean) => {
    if (checked) selectedRateIds.value = emettibili.value.map(r => r.id);
    else selectedRateIds.value = [];
};

const toggleSelection = (id: number, checked: boolean) => {
  if (checked) {
      if (!selectedRateIds.value.includes(id)) selectedRateIds.value.push(id);
  } else {
      selectedRateIds.value = selectedRateIds.value.filter(itemId => itemId !== id);
  }
};

const formEmissione = useForm({
    rate_ids: [] as number[],
    data_emissione: new Date().toISOString().split('T')[0],
    descrizione_personalizzata: '',
    invia_notifiche: true,
});

const openEmissionModal = () => {
    if (selectedRateIds.value.length === 0) return;
    formEmissione.rate_ids = selectedRateIds.value;
    isEmissionModalOpen.value = true;
};

const submitEmissione = () => {
    formEmissione.post(route('admin.gestionale.piani-rate.emetti', { 
        condominio: props.condominio.id, 
        pianoRate: props.pianoRate.id 
    }), {
        onSuccess: () => {
            isEmissionModalOpen.value = false;
            selectedRateIds.value = [];
            showFeedback('Emissione completata', `Sono state emesse correttamente ${formEmissione.rate_ids.length} rate.`, false);
        },
        onError: (errors) => {
            console.error("Errore emissione:", errors);
            const flashError = page.props.flash.message?.type === 'error' ? page.props.flash.message.message : null;
            const msg = flashError || Object.values(errors)[0] || "Si è verificato un errore imprevisto.";
            showFeedback('Errore emissione', msg, true); 
        },
    });
};

const confirmAnnullamento = (rataId: number) => {
    rataToAnnullareId.value = rataId;
    isAlertOpen.value = true;
};

const executeAnnullamento = () => {
    if (!rataToAnnullareId.value) return;
    router.delete(route('admin.gestionale.piani-rate.annulla-emissione', {
        condominio: props.condominio.id,
        pianoRate: props.pianoRate.id,
        rata: rataToAnnullareId.value
    }), { 
        preserveScroll: true,
        onSuccess: (page) => {
            isAlertOpen.value = false;
            rataToAnnullareId.value = null;
            const flash = (page.props.flash as any).message;
            if (flash && flash.type === 'error') {
                showFeedback('Impossibile annullare', flash.message, true);
                return; 
            }
            showFeedback('Emissione annullata', 'La rata è tornata in stato di bozza.', false);
        },
        onError: (errors) => {
            isAlertOpen.value = false;
            const msg = Object.values(errors)[0] || 'Si è verificato un errore tecnico.';
            showFeedback('Errore di sistema', msg, true);
        }
    });
};

const confirmRecalculate = () => {
    const haRateEmesse = props.ratePure?.some(r => r.is_emessa);
    if (haRateEmesse) {
        showFeedback('Impossibile ricalcolare', 'Ci sono rate già emesse in contabilità. Per sicurezza, devi prima annullare le emissioni usando il tasto "Annulla" nella tabella.', true);
        return; 
    }
    for (const key in orphanCheckboxes) delete orphanCheckboxes[key];
    if (props.copertura?.orfani) {
        props.copertura.orfani.forEach(o => { orphanCheckboxes[Number(o.id)] = true; });
    }
    isRecalculateAlertOpen.value = true;
};

const executeRecalculate = () => {
    router.post(route(generateRoute('gestionale.esercizi.piani-rate.regenerate'), { 
        condominio: props.condominio.id, 
        esercizio: props.esercizio.id,
        pianoRate: props.pianoRate.id 
    }), { orphan_ids: selectedOrphanIds.value }, { 
        preserveScroll: true,
        onSuccess: () => {
            isRecalculateAlertOpen.value = false;
            showFeedback('Operazione Completata', 'Il piano rate è stato aggiornato.', false);
        }
    });
};

const getRataStyle = (rata: any) => {
  const scaduta = new Date(rata.scadenza) < new Date() && rata.stato === 'da_pagare';
  if (rata.stato === 'annullata') return { container: 'bg-gray-50 border-gray-200 text-gray-400 opacity-60', text: 'line-through decoration-gray-400', icon: Ban, label: 'Annullata' };
  if (rata.importo < 0 || rata.stato === 'credito') return { container: 'bg-blue-50 border-blue-200 text-blue-700', text: 'font-bold', icon: Coins, label: 'Credito' };
  if (rata.stato === 'pagata') return { container: 'bg-emerald-50 border-emerald-200 text-emerald-700', text: 'font-bold', icon: CheckCircle2, label: 'Saldata' };
  if (rata.stato === 'parzialmente_pagata') return { container: 'bg-amber-50 border-amber-300 text-amber-800 ring-1 ring-amber-100/50', text: 'font-bold', icon: PieChart, label: 'Parziale' };
  if (scaduta) return { container: 'bg-white border-red-300 text-red-700 shadow-sm', text: 'font-bold', icon: AlertCircle, label: 'Scaduta' };
  return { container: 'bg-white border-gray-200 text-gray-500 hover:border-gray-300', text: '', icon: Clock, label: 'In attesa' };
};

const getResiduoTooltip = (rata: any) => {
    if(rata.stato === 'pagata') return `Pagato il ${rata.data_pagamento ? toItalian(rata.data_pagamento) : '-'}`;
    if(rata.stato === 'parzialmente_pagata') {
        const pagato = rata.importo_pagato ?? 0; 
        const residuo = (rata.importo ?? 0) - pagato;
        return `Versati: ${euro(pagato)} | Restano: ${euro(residuo)}`;
    }
    return null;
}

const immobileDettagli = (immobile: any) => {
  if (!immobile) return "";
  const interno = immobile.interno ?? "-";
  const piano = immobile.piano ?? "-";
  return `Int. ${interno} • Piano ${piano}`;
};

const isVoceRicevente = (capitoloId: number) => {
    if (!props.pianoRate.budget_movements) return false;
    return props.pianoRate.budget_movements.some((m: any) => 
        Number(m.destination_conto_id) === Number(capitoloId)
    );
};

const isReady = computed(() => props.pianoRate?.numero_rate > 0 && (Array.isArray(props.quotePerAnagrafica) || Array.isArray(props.quotePerImmobile)));

const rateColumns = computed(() => {
  if (!isReady.value || !props.ratePure) return [];
  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  return props.ratePure.map(rataPura => {
    const numero = rataPura.numero_rata;
    const sample = Array.isArray(src) ? src.find((item: any) => item.rate?.some((r: any) => r.numero === numero)) : null;
    const scadenza = sample?.rate?.find((r: any) => r.numero === numero)?.scadenza;
    return { 
        numero, 
        scadenza: scadenza ? new Date(scadenza) : null,
        is_emessa: rataPura.is_emessa ?? false, 
        is_published: rataPura.is_published ?? true, 
        id: rataPura.id 
    };
  });
});

const dataWithMap = computed(() => {
  if (!isReady.value) return [];
  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  if (!Array.isArray(src)) return [];
  return src.map((item: any) => {
    const rate = item.rate || [];
    const rateMap = Object.fromEntries(rate.map((r: any) => [r.numero, r]));
    let scadute = 0; let versato = 0;
    rate.forEach((r: any) => {
      const importo = r.importo ?? 0;
      const scadenzaTime = new Date(r.scadenza).setHours(0, 0, 0, 0);
      const isScaduta = scadenzaTime <= today.getTime();
      if (r.stato === "pagata") versato += importo;
      else if (r.stato === "parzialmente_pagata") versato += (r.importo_pagato ?? 0);
      if (isScaduta && r.stato !== 'pagata' && r.stato !== 'annullata' && r.stato !== 'credito') {
        if(r.stato === 'parzialmente_pagata') scadute += (importo - (r.importo_pagato ?? 0));
        else scadute += importo;
      }
    });
    const creditiRiga = rate.filter((r: any) => r.importo < 0).reduce((sum: number, r: any) => sum + Math.abs(r.importo), 0);
    const totaleRate = rate.reduce((sum: number, r: any) => sum + (r.importo ?? 0), 0);
    const saldoNetto = totaleRate - versato; 
    return { ...item, rateMap, scaduteRiga: scadute, versatoRiga: versato, creditiRiga, totaleRate, totale: saldoNetto };
  });
});

const currentData = computed(() => {
  const data = dataWithMap.value;
  return showOnlyCredits.value ? data.filter((i: any) => i.creditiRiga > 0) : data;
});

const aggregates = computed(() => {
  if (!isReady.value) return { totaleGenerale: 0, totaliPerRata: {}, totaleRateScadute: 0, totaleVersato: 0, creditiTotali: 0, totaleTeorico: 0, daIncassareTotale: 0 };
  const src = tab.value === "anagrafica" ? props.quotePerAnagrafica : props.quotePerImmobile;
  const perRata: Record<number, number> = {};
  let scadute = 0; let versato = 0; let crediti = 0; let totaleTeorico = 0;
  (src || []).forEach((item: any) => {
    (item.rate || []).forEach((r: any) => {
      const importo = r.importo ?? 0;
      const scadenzaTime = new Date(r.scadenza).setHours(0, 0, 0, 0);
      const isScaduta = scadenzaTime <= today.getTime();
      perRata[r.numero] = (perRata[r.numero] || 0) + importo;
      totaleTeorico += importo;
      if (r.stato === "pagata") versato += importo;
      else if (r.stato === "parzialmente_pagata") versato += (r.importo_pagato ?? 0);
      if (isScaduta && r.stato !== 'pagata' && r.stato !== 'annullata' && r.stato !== 'credito') {
         if(r.stato === 'parzialmente_pagata') scadute += (importo - (r.importo_pagato ?? 0));
         else scadute += importo;
      }
      if (importo < 0) crediti += Math.abs(importo);
    });
  });
  const daIncassareTotale = totaleTeorico - versato;
  return { totaleGenerale: daIncassareTotale, totaliPerRata: perRata, totaleRateScadute: scadute, totaleVersato: versato, creditiTotali: crediti, totaleTeorico, daIncassareTotale };
});

const isRecalculateBlocked = computed(() => {
    const haIncassi = aggregates.value.totaleVersato > 0;
    const haEmissioni = props.ratePure?.some(r => r.is_emessa);
    return haIncassi || haEmissioni;
});

const recalculateBlockReason = computed(() => {
    if (aggregates.value.totaleVersato > 0) return "Disabilitato: annulla prima gli incassi registrati.";
    if (props.ratePure?.some(r => r.is_emessa)) return "Disabilitato: annulla prima le emissioni in contabilità.";
    return "";
});

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Piani Rate', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-rate', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: 'Dettaglio', href: '#' },
]);

interface DettaglioStats {
    spesa: number;
    saldo: number;
    totale_debiti: number;
    totale_crediti: number;
    saldo_netto: number;
    hasDebito: boolean;
    hasCredito: boolean;
}

const getRateStats = (dettaglioQuote: any[]): DettaglioStats => {
    if (!dettaglioQuote || !Array.isArray(dettaglioQuote)) {
        return { spesa: 0, saldo: 0, totale_debiti: 0, totale_crediti: 0, saldo_netto: 0, hasDebito: false, hasCredito: false };
    }
    return dettaglioQuote.reduce((acc: DettaglioStats, q: any) => {
        const s = q.componente_saldo || 0;
        const spesa = q.componente_spesa || 0;
        acc.spesa += spesa;
        acc.saldo += s;
        acc.totale_debiti += (s > 0 ? s : 0);
        acc.totale_crediti += (s < 0 ? s : 0);
        acc.saldo_netto += s;
        if (s > 0) acc.hasDebito = true;
        if (s < 0) acc.hasCredito = true;
        return acc;
    }, { spesa: 0, saldo: 0, totale_debiti: 0, totale_crediti: 0, saldo_netto: 0, hasDebito: false, hasCredito: false });
};

const executePublishSilent = () => {
    router.post(route(generateRoute('gestionale.piani-rate.publish-silent'), {
        condominio: props.condominio.id,
        esercizio: props.esercizio.id,
        pianoRate: props.pianoRate.id
    }), {}, {
        preserveScroll: true,
        onSuccess: () => {
            showFeedback('Pubblicazione completata', 'Le rate sono ora visibili ai condòmini e le notifiche sono state inviate.', false);
        }
    });
};

</script>

<template>   

  <Head title="Dettaglio piano rate" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4 bg-white">
        <section class="w-full space-y-6">

          <Heading 
            :title="`Piano rate: ${props.pianoRate.nome}`" 
            description="Situazione aggiornata delle rate e dei pagamenti."
          />

          <div 
              v-if="props.pianoRate.stato === 'approvato' && props.pianoRate.data_delibera_assemblea" 
              class="inline-flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg text-[11px] font-medium shadow-sm animate-in fade-in zoom-in duration-300"
          >
              <Gavel class="w-3.5 h-3.5" />
              <span>Delibera del <strong>{{ toItalian(props.pianoRate.data_delibera_assemblea) }}</strong></span>
              <span v-if="props.pianoRate.numero_verbale" class="pl-2 ml-1 border-l border-emerald-300 font-bold uppercase tracking-wider">
                  {{ props.pianoRate.numero_verbale }}
              </span>
          </div>

          <div v-if="flashMessage" class="animate-in fade-in slide-in-from-top-4 duration-300">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div v-if="!switchState" class="rounded-md bg-amber-50 p-4 border border-amber-200 shadow-sm animate-in fade-in slide-in-from-top-2 duration-300">
            <div class="flex">
              <div class="flex-shrink-0">
                <AlertTriangle class="h-5 w-5 text-amber-600" aria-hidden="true" />
              </div>
              <div class="ml-3 flex-1 md:flex md:justify-between">
                <p class="text-sm text-amber-800">
                  <strong>Stato Bozza:</strong> Il piano è attualmente modificabile e non ha generato movimenti contabili.
                  <span class="block sm:inline mt-1 sm:mt-0">Controlla i dati, poi passa allo stato <strong>Approvato</strong> per registrare la delibera ed emettere le rate.</span>
                </p>
              </div>
            </div>
          </div>

          <Tabs v-model="tab" class="space-y-6">

            <!-- ============================================================ -->
            <!-- ACTION BAR UNIFICATA                                         -->
            <!-- ============================================================ -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2 p-2 bg-gray-50/50 border rounded-lg w-full">

                <!-- TAB: Per anagrafica / Per immobile -->
                <TabsList class="grid w-full sm:w-[280px] grid-cols-2 bg-muted p-1 rounded-md shrink-0">
                    <TabsTrigger value="anagrafica">Per anagrafica</TabsTrigger>
                    <TabsTrigger value="immobile">Per immobile</TabsTrigger>
                </TabsList>

                <div class="w-px h-5 bg-gray-300 hidden sm:block shrink-0"></div>

                <!-- PULSANTI — scroll orizzontale su mobile -->
                <div class="flex items-center gap-2 overflow-x-auto w-full scrollbar-none pb-0.5">

                    <!-- SWITCH APPROVATO/BOZZA -->
                    <HoverCard :open-delay="200">
                        <HoverCardTrigger as-child>
                            <div class="flex items-center space-x-2 px-1 h-8 shrink-0">
                                <button 
                                    type="button" 
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2"
                                    :class="[
                                        switchState ? 'bg-emerald-600' : 'bg-gray-200',
                                        (isProcessingStatus || (switchState && props.ratePure.some(r => r.is_emessa))) ? 'opacity-50 cursor-not-allowed' : ''
                                    ]"
                                    :disabled="isProcessingStatus || (switchState && props.ratePure.some(r => r.is_emessa))"
                                    @click="toggleStatoPiano(!switchState)"
                                    role="switch"
                                    :aria-checked="switchState"
                                >
                                    <span class="sr-only">Cambia stato piano</span>
                                    <span 
                                        aria-hidden="true" 
                                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                        :class="switchState ? 'translate-x-5' : 'translate-x-0'"
                                    >
                                        <span class="absolute inset-0 flex h-full w-full items-center justify-center transition-opacity"
                                            :class="switchState ? 'opacity-0 duration-100 ease-out' : 'opacity-100 duration-200 ease-in'"
                                        >
                                            <svg class="h-3 w-3 text-gray-400" fill="none" viewBox="0 0 12 12"><path d="M4 8l2-2m0 0l2-2M6 6L4 4m2 2l2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        </span>
                                        <span class="absolute inset-0 flex h-full w-full items-center justify-center transition-opacity"
                                            :class="switchState ? 'opacity-100 duration-200 ease-in' : 'opacity-0 duration-100 ease-out'"
                                        >
                                            <svg class="h-3 w-3 text-emerald-600" fill="currentColor" viewBox="0 0 12 12"><path d="M3.707 5.293a1 1 0 00-1.414 1.414l1.414-1.414zM5 8l-.707.707a1 1 0 001.414 0L5 8zm4.707-3.293a1 1 0 00-1.414-1.414l1.414 1.414zm-7.414 2l2 2 1.414-1.414-2-2-1.414 1.414zm3.414 2l4-4-1.414-1.414-4 4 1.414 1.414z" /></svg>
                                        </span>
                                    </span>
                                </button>
                                <Label 
                                    class="text-sm font-medium whitespace-nowrap cursor-pointer select-none"
                                    :class="{'opacity-50 cursor-not-allowed': isProcessingStatus || (switchState && props.ratePure.some(r => r.is_emessa))}"
                                    @click="!(isProcessingStatus || (switchState && props.ratePure.some(r => r.is_emessa))) && toggleStatoPiano(!switchState)"
                                >
                                    {{ switchState ? 'Approvato' : 'Bozza' }}
                                </Label>
                            </div>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                            <div v-if="switchState && props.ratePure.some(r => r.is_emessa)" class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-amber-700">
                                    <Lock class="w-4 h-4 text-amber-600" /> Azione bloccata
                                </h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p>Non puoi tornare in stato <strong>Bozza</strong> perché ci sono rate già emesse in contabilità.</p>
                                    <p class="text-[11px] text-slate-500">Per sbloccare l'interruttore, annulla prima le emissioni usando il tasto con la freccia circolare nella tabella qui sotto.</p>
                                </div>
                            </div>
                            <div v-else-if="switchState" class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-emerald-700">
                                    <CheckCircle2 class="w-4 h-4 text-emerald-600" /> Stato: Approvato
                                </h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p>Il piano rate è protetto da modifiche e la delibera assembleare è stata registrata a sistema.</p>
                                    <p class="text-[11px] text-slate-500">Puoi procedere con l'emissione delle rate, oppure tornare in Bozza se devi modificare i capitoli (la delibera andrà registrata nuovamente).</p>
                                </div>
                            </div>
                            <div v-else class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-slate-700">
                                    <History class="w-4 h-4 text-slate-500" /> Stato: Bozza
                                </h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p>Il piano rate è in fase di costruzione. Le rate non sono ancora state generate nel Libro Giornale.</p>
                                    <p class="text-[11px] text-slate-500 font-medium">Clicca l'interruttore per inserire i dati della delibera e passare allo stato Approvato.</p>
                                </div>
                            </div>
                        </HoverCardContent>
                    </HoverCard>

                    <div class="w-px h-5 bg-gray-300 shrink-0"></div>

                    <!-- SELEZIONA TUTTE -->
                    <HoverCard v-if="switchState">
                        <HoverCardTrigger as-child>
                            <Button
                                variant="outline"
                                class="h-8 px-3 border-emerald-200 text-emerald-700 hover:bg-emerald-50 hover:text-emerald-800 bg-white shrink-0"
                                @click="toggleSelectAll(!isAllSelected)"
                            >
                                <CheckCircle2 v-if="!isAllSelected" class="w-4 h-4 sm:mr-2" />
                                <XCircle v-else class="w-4 h-4 sm:mr-2" />
                                <span class="hidden sm:inline">{{ isAllSelected ? 'Deseleziona' : 'Seleziona tutte' }}</span>
                            </Button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-emerald-800">
                                    <CheckCircle2 class="w-4 h-4 text-emerald-600" /> Selezione rate
                                </h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p v-if="!isAllSelected">Seleziona tutte le <strong>{{ emettibili.length }}</strong> rate non ancora emesse per procedere all'emissione in blocco.</p>
                                    <p v-else>Deseleziona tutte le rate selezionate.</p>
                                </div>
                            </div>
                        </HoverCardContent>
                    </HoverCard>

                    <!-- EMETTI -->
                    <HoverCard v-if="switchState">
                        <HoverCardTrigger as-child>
                            <span class="inline-block shrink-0" :tabindex="selectedRateIds.length === 0 ? 0 : -1">
                                <Button 
                                    :disabled="selectedRateIds.length === 0" 
                                    variant="default" 
                                    class="bg-emerald-600 hover:bg-emerald-700 h-8 px-3 border shadow-sm"
                                    :class="{'pointer-events-none': selectedRateIds.length === 0}"
                                    @click="openEmissionModal"
                                >
                                    <Wallet class="w-4 h-4 sm:mr-2" />
                                    <span class="hidden sm:inline">Emetti ({{ selectedRateIds.length }})</span>
                                    <span class="sm:hidden text-xs">{{ selectedRateIds.length }}</span>
                                </Button>
                            </span>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-emerald-800">
                                    <Wallet class="w-4 h-4 text-emerald-600" /> Emissione rate
                                </h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p v-if="selectedRateIds.length === 0">Usa <strong>Seleziona tutte</strong> o le spunte nella tabella per selezionare le rate da emettere.</p>
                                    <p v-else>Stai per emettere <strong>{{ selectedRateIds.length }}</strong> rate. Verranno generate le scritture contabili in Prima Nota.</p>
                                </div>
                            </div>
                        </HoverCardContent>
                    </HoverCard>

                    <HoverCard v-else>
                        <HoverCardTrigger as-child>
                            <span class="inline-block shrink-0" tabindex="0">
                                <Button disabled variant="secondary" class="h-8 px-3 opacity-70 pointer-events-none border shadow-sm">
                                    <Lock class="w-4 h-4 sm:mr-2" />
                                    <span class="hidden sm:inline">Approva per emettere</span>
                                </Button>
                            </span>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-slate-800">
                                    <Lock class="w-4 h-4 text-slate-500" /> Emissione bloccata
                                </h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p>Il piano rate è attualmente in stato <strong>Bozza</strong>.</p>
                                    <p>Usa l'interruttore a sinistra per passare allo stato <strong>Approvato</strong> inserendo i dati dell'assemblea.</p>
                                </div>
                            </div>
                        </HoverCardContent>
                    </HoverCard>

                    <!-- PUBBLICA NASCOSTE -->
                    <HoverCard v-if="props.has_unpublished_rates">
                        <HoverCardTrigger as-child>
                            <Button variant="outline" class="h-8 px-3 border-amber-300 text-amber-700 bg-amber-50 hover:bg-amber-100 hover:text-amber-800 shadow-sm transition-all shrink-0" @click="executePublishSilent">
                                <BellRing class="w-4 h-4 sm:mr-2" />
                                <span class="hidden sm:inline">Pubblica nascoste</span>
                            </Button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-amber-700"><BellRing class="w-4 h-4" /> Rate in Sospeso</h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p>Ci sono rate emesse contabilmente ma attualmente <strong>nascoste</strong> ai condòmini.</p>
                                    <p>Clicca questo pulsante per sbloccarne la visibilità nell'App e <strong>inviare le notifiche</strong> (Push/Email).</p>
                                </div>
                            </div>
                        </HoverCardContent>
                    </HoverCard>

                    <div class="w-px h-5 bg-gray-300 shrink-0"></div>

                    <!-- RICALCOLA / SINCRONIZZA -->
                    <HoverCard>
                        <HoverCardTrigger as-child>
                            <span class="inline-block shrink-0" :tabindex="isRecalculateBlocked ? 0 : -1">
                                <Button 
                                    @click="confirmRecalculate"
                                    :disabled="isRecalculateBlocked"
                                    class="inline-flex items-center justify-center gap-2 rounded-md border bg-white h-8 px-3 text-sm font-medium transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    :class="isRecalculateBlocked 
                                        ? 'border-gray-200 opacity-60 cursor-not-allowed bg-gray-50 text-gray-500' 
                                        : 'border-gray-200 text-gray-700 hover:bg-gray-50 hover:text-primary'"
                                >
                                    <RotateCw class="w-4 h-4" :class="{'text-amber-600': (copertura?.scoperto_count ?? 0) > 0 && !isRecalculateBlocked}" />
                                    <span class="hidden sm:inline" :class="{'text-amber-700 font-bold': (copertura?.scoperto_count ?? 0) > 0 && !isRecalculateBlocked}">
                                        {{ (copertura?.scoperto_count ?? 0) > 0 ? 'Sincronizza' : 'Ricalcola' }}
                                    </span>
                                </Button>
                            </span>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-slate-800">
                                    <RotateCw class="w-4 h-4" :class="isRecalculateBlocked ? 'text-slate-500' : 'text-primary'" /> 
                                    {{ (copertura?.scoperto_count ?? 0) > 0 ? 'Sincronizza e ricalcola' : 'Ricalcolo piano rate' }}
                                </h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p v-if="(copertura?.scoperto_count ?? 0) > 0">Include le nuove voci di spesa scoperte e rigenera le quote in base ai preventivi aggiornati.</p>
                                    <p v-else>Rigenera le quote del piano rate in base ai millesimi attuali e ai preventivi di spesa aggiornati.</p>
                                    <div v-if="(copertura?.scoperto_count ?? 0) > 0 && !isRecalculateBlocked" class="p-2 bg-amber-50 rounded-md border border-amber-200 text-amber-800 text-xs">
                                        <strong>Sincronizzazione necessaria:</strong> Ci sono voci di spesa scoperte che possono essere incluse in questo piano.
                                    </div>
                                    <p v-if="isRecalculateBlocked" class="text-red-500 font-medium text-xs mt-2 p-2 bg-red-50 border border-red-100 rounded-md flex items-start gap-1.5">
                                        <span>{{ recalculateBlockReason }}</span>
                                    </p>
                                </div>
                            </div>
                        </HoverCardContent>
                    </HoverCard>

                    <!-- SPOSTA SPESA -->
                    <HoverCard>
                        <HoverCardTrigger as-child>
                            <Button variant="outline" class="h-8 px-3 border-indigo-200 text-indigo-700 hover:bg-indigo-50 hover:text-indigo-800 bg-white shrink-0" @click="isSpostaSpesaOpen = true">
                                <ArrowRightLeft class="w-4 h-4 sm:mr-2" />
                                <span class="hidden sm:inline">Sposta spesa</span>
                            </Button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold flex items-center gap-2 text-indigo-800">
                                    <ArrowRightLeft class="w-4 h-4 text-indigo-500" /> Sposta spesa
                                </h4>
                                <div class="text-sm space-y-2 text-slate-600">
                                    <p>Trasferisci fondi da un capitolo di spesa all'altro all'interno di questo piano rate, o verso altri capitoli della gestione.</p>
                                    <p class="text-xs italic text-slate-500">Utile per compensare spese impreviste senza dover ricalcolare l'intero piano o emettere nuove rate.</p>
                                </div>
                            </div>
                        </HoverCardContent>
                    </HoverCard>

                    <div class="w-px h-5 bg-gray-300 shrink-0"></div>

                    <!-- LISTA -->
                    <Link 
                        :href="route(generateRoute('gestionale.esercizi.piani-rate.index'), { condominio: props.condominio.id, esercizio: props.esercizio.id })" 
                        class="inline-flex items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white h-8 px-3 hover:bg-primary/90 transition-colors shadow-sm whitespace-nowrap shrink-0"
                    >
                        <List class="w-4 h-4 sm:mr-1" />
                        <span class="hidden sm:inline">Lista</span>
                    </Link>

                </div>
            </div>
            <!-- ============================================================ -->

            <div v-if="isReady" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                <Card class="bg-white shadow-sm border"><CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-gray-400 tracking-wider">Totale Piano</CardTitle></CardHeader><CardContent class="p-4 pt-0 text-xl font-bold text-gray-900">{{ euro(aggregates.totaleTeorico) }}</CardContent></Card>
                <Card class="bg-red-50/40 shadow-sm border-red-100 relative group"><CardHeader class="p-4 pb-2 flex flex-row items-center justify-between space-y-0"><CardTitle class="text-xs uppercase text-red-400 tracking-wider">Da Incassare</CardTitle><TooltipProvider><Tooltip><TooltipTrigger><Info class="w-3 h-3 text-red-300 hover:text-red-500 transition-colors cursor-help" /></TooltipTrigger><TooltipContent><p class="text-xs">Include rate scadute e <strong>debiti pregressi</strong>.</p><p class="text-xs text-muted-foreground">Non sottrae i crediti.</p></TooltipContent></Tooltip></TooltipProvider></CardHeader><CardContent class="p-4 pt-0 text-xl font-bold text-red-600">{{ euro(aggregates.totaleRateScadute) }}</CardContent></Card>
                <Card class="bg-emerald-50/40 shadow-sm border-emerald-100"><CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-emerald-400 tracking-wider">Incassato</CardTitle></CardHeader><CardContent class="p-4 pt-0 text-xl font-bold text-emerald-600">{{ euro(aggregates.totaleVersato) }}</CardContent></Card>
                <Card class="bg-blue-50/40 shadow-sm border-blue-100"><CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-blue-400 tracking-wider">Crediti (Anticipi)</CardTitle></CardHeader><CardContent class="p-4 pt-0 text-xl font-bold text-blue-600">{{ aggregates.creditiTotali > 0 ? euro(aggregates.creditiTotali) : "—" }}</CardContent></Card>
                <Card class="bg-gray-50 shadow-sm border"><CardHeader class="p-4 pb-2"><CardTitle class="text-xs uppercase text-gray-500 tracking-wider">Saldo Netto</CardTitle></CardHeader><CardContent class="p-4 pt-0 text-xl font-bold" :class="aggregates.totaleGenerale > 0.01 ? 'text-red-600' : (aggregates.totaleGenerale < -0.01 ? 'text-blue-600' : 'text-emerald-600')">{{ euro(aggregates.totaleGenerale) }}</CardContent></Card>
            </div>

            <div v-if="isReady" class="mt-6 border rounded-lg bg-white shadow-sm transition-all duration-200">
                <button 
                    @click="toggleCapitoli" 
                    class="w-full flex justify-between items-center px-4 py-3 bg-gray-50 hover:bg-gray-100 transition-colors rounded-t-lg"
                    :class="{'rounded-b-lg': !isCapitoliExpanded}"
                >
                    <div class="flex items-center gap-2">
                        <div class="bg-white p-1.5 rounded-md border shadow-sm">
                            <Wallet class="w-4 h-4 text-emerald-600" />
                        </div>
                        <div class="text-left">
                            <h3 class="text-sm font-bold text-gray-700 flex items-center gap-2">
                                Copertura spese
                                <Badge v-if="isDisallineato" variant="destructive" class="text-[10px] py-0 px-1.5">
                                    <AlertTriangle class="w-3 h-3 mr-1" /> Disallineato: ricalcola!
                                </Badge>
                            </h3>
                            <p class="text-[10px] text-gray-500">
                                {{ props.pianoRate.capitoli?.length ?? 0 }} voci incluse • 
                                <span :class="{'text-red-600 font-bold': isDisallineato}">
                                    totale preventivo: {{ euro(props.pianoRate.totale_capitoli) }}
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-primary font-medium" v-if="!isCapitoliExpanded">
                            {{ isProcessingStatus ? 'Aggiornamento...' : 'Mostra dettagli' }}
                        </span>
                        <component :is="isCapitoliExpanded ? ChevronUp : ChevronDown" class="w-4 h-4 text-gray-400" />
                    </div>
                </button>
                
                <div v-if="isCapitoliExpanded" class="border-t divide-y divide-gray-100 max-h-60 overflow-y-auto bg-white">
                    <div v-if="props.pianoRate.capitoli?.length" class="divide-y divide-gray-100">
                        <div v-for="capitolo in props.pianoRate.capitoli" :key="capitolo.id" class="flex items-start justify-between px-4 py-3 hover:bg-slate-50 group transition-colors">
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-semibold text-gray-800">{{ capitolo.nome }}</span>
                                    <span v-if="capitolo.is_parent" class="text-[9px] bg-blue-50 text-blue-700 border border-blue-100 px-1.5 py-0.5 rounded-md font-bold uppercase tracking-wider flex items-center gap-1">
                                        <Layers class="w-3 h-3" /> Gruppo
                                    </span>
                                    <span v-if="isVoceRicevente(capitolo.id)" class="text-[9px] bg-amber-50 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-md font-bold uppercase tracking-wider flex items-center gap-1" title="Questa voce ha ricevuto budget da un altro capitolo">
                                        <ArrowRightLeft class="w-3 h-3" /> Integra
                                    </span>
                                    <TooltipProvider v-if="capitolo.is_frazionato && !isVoceRicevente(capitolo.id)">
                                        <Tooltip :delayDuration="300">
                                            <TooltipTrigger as-child>
                                                <span class="text-[9px] bg-indigo-50 text-indigo-700 border border-indigo-200 px-1.5 py-0.5 rounded-md font-bold uppercase tracking-wider flex items-center gap-1 cursor-help">
                                                    <PieChart class="w-3 h-3" /> Parziale
                                                </span>
                                            </TooltipTrigger>
                                            <TooltipContent class="bg-slate-900 text-white border-slate-800 text-xs">
                                                <p v-if="capitolo.is_parent">Questo gruppo è incluso parzialmente.</p>
                                                <p v-else>Importo ridotto rispetto al preventivo originale.</p>
                                                <p class="font-mono mt-1 opacity-80">Totale originale: {{ euro(capitolo.importo_originale) }}</p>
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                    <span v-if="!capitolo.is_parent && !capitolo.is_frazionato && !isVoceRicevente(capitolo.id)" class="text-[9px] bg-slate-100 text-slate-500 border border-slate-200 px-1.5 py-0.5 rounded-md font-bold uppercase tracking-wider flex items-center gap-1">
                                        <CheckCircle2 class="w-3 h-3" /> Standard
                                    </span>
                                </div>
                                <p v-if="capitolo.is_parent" class="text-[10px] text-gray-400 leading-tight max-w-md">Include: {{ capitolo.figli_names }}</p>
                                <p v-if="isVoceRicevente(capitolo.id)" class="text-[10px] text-amber-600 leading-tight flex items-center gap-1">
                                    Include fondi spostati da altre voci 
                                    <span class="inline-flex items-center gap-0.5 whitespace-nowrap opacity-80">(vedi storico <History class="w-2.5 h-2.5" />)</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-4">
                                <BudgetHistoryPopover 
                                    :capitolo-id="capitolo.id"
                                    :current-amount="capitolo.importo"
                                    :movements="props.pianoRate.budget_movements || []"
                                />
                                <span class="text-xs font-medium text-gray-700">{{ euro(capitolo.importo) }}</span>
                                <button 
                                    @click="confirmDetachItem(capitolo)"
                                    :disabled="switchState || aggregates.totaleVersato > 0"
                                    class="p-1.5 rounded-md text-gray-300 hover:text-red-600 transition-colors"
                                    :class="{ 'opacity-50 cursor-not-allowed': switchState || aggregates.totaleVersato > 0, 'hover:bg-red-50': !(switchState || aggregates.totaleVersato > 0) }"
                                    title="Rimuovi voce e ricalcola"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="p-6 text-center text-sm text-gray-400 italic">
                        Nessuna voce di spesa associata. Usa il tasto "Sincronizza" per aggiungerne.
                    </div>
                </div>
            </div>

            <TabsContent :value="tab" class="mt-0 space-y-6">
              <template v-if="isReady">
                <div class="overflow-x-auto border rounded-lg shadow-sm">
                  <table class="w-full text-sm border-collapse bg-white whitespace-nowrap min-w-[1000px]">
                    <thead class="sticky top-0 bg-white z-20 shadow-sm">
                      <tr class="border-b bg-gray-50/80 text-gray-500">
                        <th class="text-left px-6 py-3 sticky left-0 bg-gray-50 z-50 min-w-[250px] font-semibold uppercase text-xs tracking-wider shadow-[2px_0_5px_-2px_rgba(0,0,0,0.1)]">
                          {{ tab === "anagrafica" ? "Anagrafica" : "Immobile" }}
                        </th>
                        
                        <th v-for="col in rateColumns" :key="col.numero" class="text-center px-4 py-3 min-w-[100px] relative group/header">
                          <div class="flex items-center justify-center gap-2">
                              <div v-if="switchState && !col.is_emessa && col.id" class="z-10 cursor-pointer flex items-center justify-center">
                                <input 
                                    type="checkbox"
                                    :checked="selectedRateIds.includes(col.id)"
                                    @change="(e) => { 
                                        const checked = (e.target as HTMLInputElement).checked;
                                        toggleSelection(col.id, checked);
                                    }"
                                    class="h-4 w-4 cursor-pointer accent-emerald-600 z-50 relative"
                                />
                              </div>
                              <div class="flex flex-col items-center relative z-20 pointer-events-none">
                                  <div class="font-semibold text-gray-700 flex items-center gap-1">
                                       {{ col.numero === 0 ? 'Saldi Iniziali' : 'Rata ' + col.numero }}
                                       <TooltipProvider v-if="col.is_emessa" :delayDuration="100">
                                            <Tooltip>
                                                <TooltipTrigger class="pointer-events-auto cursor-help">
                                                    <Badge 
                                                        class="ml-1 h-2 w-2 p-0 rounded-full border border-white/50 shadow-sm"
                                                        :class="col.is_published ? 'bg-emerald-500' : 'bg-amber-400 animate-pulse'"
                                                    ></Badge>
                                                </TooltipTrigger>
                                                <TooltipContent class="pointer-events-none z-[100] text-xs">
                                                    <div v-if="col.is_published" class="flex flex-col">
                                                        <span class="font-bold text-emerald-500">Emessa e pubblicata</span>
                                                        <span class="text-slate-400">Visibile ai condòmini.</span>
                                                    </div>
                                                    <div v-else class="flex flex-col">
                                                        <span class="font-bold text-amber-500">Emessa ma nascosta</span>
                                                        <span class="text-slate-400">Non visibile ai condòmini. Usa il tasto "Pubblica".</span>
                                                    </div>
                                                </TooltipContent>
                                            </Tooltip>
                                       </TooltipProvider>
                                  </div>
                                  <div class="text-[10px] opacity-75 font-normal">{{ col.scadenza ? toItalian(col.scadenza) : "—" }}</div>
                              </div>
                              <TooltipProvider v-if="switchState && col.is_emessa" :delayDuration="150">
                                  <Tooltip>
                                      <TooltipTrigger as-child>
                                          <button 
                                            class="absolute top-1 right-1 opacity-0 group-hover/header:opacity-100 text-gray-400 hover:text-red-500 transition-colors z-40 outline-none"
                                            @click.stop="confirmAnnullamento(col.id)"
                                          >
                                            <RotateCcw class="w-3 h-3" />
                                          </button>
                                      </TooltipTrigger>
                                      <TooltipContent side="top" class="bg-slate-900 text-white text-xs border-slate-800">
                                          Annulla emissione
                                      </TooltipContent>
                                  </Tooltip>
                              </TooltipProvider>
                          </div>
                        </th>

                        <th class="text-right px-4 py-3 bg-red-50/20 text-red-600 border-l border-red-100 min-w-[100px]">Scadute</th>
                        <th class="text-right px-4 py-3 bg-emerald-50/20 text-emerald-600 min-w-[100px]">Versato</th>
                        <th class="text-right px-4 py-3 min-w-[100px]">Crediti</th>
                        <th class="text-right px-4 py-3 min-w-[100px] text-xs">Tot. Rate</th>
                        <th class="text-right px-6 py-3 sticky right-0 bg-gray-50 z-40 text-xs shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.1)]">Saldo</th>
                      </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                      <tr v-for="item in currentData" 
                          :key="tab === 'anagrafica' ? item.anagrafica.id : item.immobile.id"
                          class="hover:bg-gray-50 transition-colors group"
                      >
                        <td class="px-6 py-4 font-medium sticky left-0 bg-white group-hover:bg-gray-50 z-30 border-r border-gray-100 align-top shadow-[2px_0_5px_-2px_rgba(0,0,0,0.05)]">
                          <div v-if="tab === 'anagrafica'">
                            <Link 
                              :href="route('admin.gestionale.anagrafiche.estratto-conto', { 
                                  condominio: props.condominio.id, 
                                  anagrafica: item.anagrafica.id 
                              })"
                              class="font-semibold text-primary hover:text-primary/80 hover:underline cursor-pointer transition-colors block"
                              title="Vedi estratto conto e storico pagamenti"
                            >
                              {{ item.anagrafica.nome }}
                            </Link>
                            <div class="text-xs text-muted-foreground mt-0.5">{{ item.anagrafica.indirizzo }}</div>
                          </div>
                          <div v-else>
                            <div class="font-semibold text-gray-900">{{ item.immobile.nome }}</div>
                            <div class="text-xs text-muted-foreground mt-0.5">{{ immobileDettagli(item.immobile) }}</div>
                          </div>
                        </td>

                        <td v-for="col in rateColumns" :key="col.numero" class="px-3 py-2 text-center align-middle">
                          <template v-if="item.rateMap?.[col.numero]">
                            <TooltipProvider>
                                <Tooltip :delayDuration="200">
                                    <TooltipTrigger as-child>
                                        <div 
                                            :class="getRataStyle(item.rateMap[col.numero]).container"
                                            class="relative flex flex-col items-center justify-center rounded-lg border p-1.5 h-[56px] w-[80px] mx-auto transition-all cursor-help"
                                            :style="!col.is_emessa ? 'opacity: 0.75; border-style: dashed;' : ''" 
                                        >
                                            <div class="flex items-center gap-1" :class="getRataStyle(item.rateMap[col.numero]).text">
                                                <component :is="getRataStyle(item.rateMap[col.numero]).icon" v-if="getRataStyle(item.rateMap[col.numero]).icon" class="w-3 h-3 opacity-60" />
                                                <span class="text-xs">{{ euro(item.rateMap[col.numero].importo) }}</span>
                                            </div>
                                            <div class="mt-0.5 w-full flex justify-center">
                                                <div v-if="!col.is_emessa" class="text-[9px] font-bold text-gray-400 uppercase tracking-wide bg-gray-100 px-1.5 rounded-sm inline-block">
                                                    BOZZA
                                                </div>
                                                <div v-else class="text-[10px] opacity-75">
                                                    {{ toItalian(item.rateMap[col.numero].scadenza) }}
                                                </div>
                                            </div>
                                            <div v-if="item.rateMap[col.numero].stato === 'parzialmente_pagata'" class="absolute -top-1.5 right-0 bg-amber-100 text-[8px] px-1 rounded-sm text-amber-700 font-bold border border-amber-200 shadow-sm z-20">
                                                PARZ.
                                            </div>
                                            <div v-if="item.rateMap[col.numero].dettaglio_quote" 
                                                v-for="stats in [getRateStats(item.rateMap[col.numero].dettaglio_quote)]" 
                                                :key="'dots-' + col.numero" class="z-20"
                                            >
                                                <div v-if="stats.hasDebito && !stats.hasCredito" class="absolute -top-1 -right-1 rounded-full w-2.5 h-2.5 bg-red-500 shadow-sm ring-1 ring-white"></div>
                                                <div v-if="stats.hasCredito && !stats.hasDebito" class="absolute -top-1 -right-1 rounded-full w-2.5 h-2.5 bg-blue-500 shadow-sm ring-1 ring-white"></div>
                                                <div v-if="stats.hasDebito && stats.hasCredito" class="absolute -top-1 -right-1 flex gap-0.5">
                                                    <div class="rounded-full w-2.5 h-2.5 bg-blue-500 shadow-sm ring-1 ring-white"></div>
                                                    <div class="rounded-full w-2.5 h-2.5 bg-red-500 shadow-sm ring-1 ring-white"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </TooltipTrigger>
                                    <TooltipContent side="top" class="bg-slate-900 text-white border-slate-800 shadow-xl">
                                        <div class="text-xs text-center">
                                            <p class="font-bold mb-1">{{ getRataStyle(item.rateMap[col.numero]).label }}</p>
                                            <div v-if="item.rateMap[col.numero].dettaglio_quote && item.rateMap[col.numero].dettaglio_quote.length > 0" class="mb-2 pb-1.5 border-b border-white/20 text-left space-y-1">
                                                <div v-for="dettaglio in [getRateStats(item.rateMap[col.numero].dettaglio_quote)]" :key="'dettaglio-' + col.numero" class="space-y-1">
                                                    <div v-if="dettaglio.spesa !== 0" class="flex justify-between gap-4 text-slate-300">
                                                        <span>Quota ordinaria:</span>
                                                        <span class="font-mono">{{ euro(dettaglio.spesa) }}</span>
                                                    </div>
                                                    <template v-if="dettaglio.totale_debiti > 0 || dettaglio.totale_crediti < 0">
                                                        <div v-if="dettaglio.totale_debiti > 0" class="flex justify-between gap-4 text-red-300">
                                                            <span>Debiti pregressi:</span>
                                                            <span class="font-mono">{{ euro(dettaglio.totale_debiti) }}</span>
                                                        </div>
                                                        <div v-if="dettaglio.totale_crediti < 0" class="flex justify-between gap-4 text-blue-300">
                                                            <span>Crediti pregressi:</span>
                                                            <span class="font-mono">{{ euro(Math.abs(dettaglio.totale_crediti)) }}</span>
                                                        </div>
                                                        <div v-if="dettaglio.totale_debiti > 0 && dettaglio.totale_crediti < 0" class="text-[10px] opacity-80 mt-1 text-center font-medium pt-1">
                                                            <span v-if="dettaglio.saldo_netto === 0" class="text-emerald-300">Compensazione totale (0€)</span>
                                                            <span v-else-if="dettaglio.saldo_netto > 0" class="text-red-200">Residuo debito: {{ euro(dettaglio.saldo_netto) }}</span>
                                                            <span v-else class="text-blue-200">Residuo credito: {{ euro(Math.abs(dettaglio.saldo_netto)) }}</span>
                                                        </div>
                                                    </template>
                                                    <div v-if="dettaglio.spesa !== 0 && (dettaglio.totale_debiti > 0 || dettaglio.totale_crediti < 0)" class="flex justify-between gap-4 text-[10px] font-bold pt-1 border-t border-white/10 mt-1">
                                                        <span>Totale rata:</span>
                                                        <span class="font-mono">{{ euro(dettaglio.spesa + dettaglio.saldo_netto) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <p v-if="getResiduoTooltip(item.rateMap[col.numero])">{{ getResiduoTooltip(item.rateMap[col.numero]) }}</p>
                                            <p class="text-[10px] text-gray-400 mt-1">Scadenza: {{ toItalian(item.rateMap[col.numero].scadenza) }}</p>
                                        </div>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                          </template>
                          <span v-else class="text-gray-200 text-xs">—</span>
                        </td>

                        <td class="px-4 py-2 text-right text-amber-600 font-medium text-xs bg-red-50/10 border-l border-red-50">{{ euro(item.scaduteRiga) }}</td>
                        <td class="px-4 py-2 text-right text-emerald-600 font-medium text-xs bg-emerald-50/10 border-l border-emerald-50">{{ euro(item.versatoRiga) }}</td>
                        <td class="px-4 py-2 text-right text-blue-600 font-medium text-xs">{{ item.creditiRiga > 0 ? euro(item.creditiRiga) : "—" }}</td>
                        <td class="px-4 py-2 text-right font-medium text-xs text-gray-700">{{ euro(item.totaleRate) }}</td>
                        <td class="px-6 py-4 text-right font-bold sticky right-0 bg-white group-hover:bg-gray-50 z-30 border-l shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.05)]"
                            :class="{
                                'text-red-600': item.totale > 0.01,
                                'text-blue-600': item.totale < -0.01,
                                'text-emerald-600': Math.abs(item.totale) <= 0.01
                            }"
                        >
                          <div class="flex flex-col items-end">
                              <span>{{ euro(Math.abs(item.totale)) }}</span>
                              <span v-if="item.totale > 0.01" class="text-[9px] uppercase opacity-70">Deve</span>
                              <span v-else-if="item.totale < -0.01" class="text-[9px] uppercase opacity-70">Credito</span>
                              <span v-else class="text-[9px] uppercase opacity-70">Ok</span>
                          </div>
                        </td>
                      </tr>
                    </tbody>

                    <tfoot class="sticky bottom-0 bg-white z-40 shadow-[0_-2px_5px_rgba(0,0,0,0.05)]">
                      <tr class="border-t-2 border-muted bg-gray-50 font-bold text-gray-700">
                        <td class="px-6 py-3 sticky left-0 bg-gray-50 z-40 border-r shadow-[2px_0_5px_-2px_rgba(0,0,0,0.05)]">TOTALE</td>
                        <td v-for="col in rateColumns" :key="col.numero" class="text-center px-3 py-3">
                            {{ euro(aggregates.totaliPerRata[col.numero] ?? 0) }}
                        </td>
                        <td class="px-4 py-3 text-right text-amber-600 bg-red-50/20 border-l border-red-100">{{ euro(aggregates.totaleRateScadute) }}</td>
                        <td class="px-4 py-3 text-right text-emerald-600 bg-emerald-50/20 border-l border-emerald-100">{{ euro(aggregates.totaleVersato) }}</td>
                        <td class="px-4 py-3 text-right text-blue-600">{{ euro(aggregates.creditiTotali) }}</td>
                        <td class="px-4 py-3 text-right text-gray-700">{{ euro(aggregates.totaleTeorico) }}</td>
                        <td class="px-6 py-3 text-right sticky right-0 bg-gray-50 z-40 border-l shadow-[-2px_0_5px_-2px_rgba(0,0,0,0.05)]"
                            :class="{
                                'text-red-600': aggregates.totaleGenerale > 0.01,
                                'text-blue-600': aggregates.totaleGenerale < -0.01,
                                'text-emerald-600': Math.abs(aggregates.totaleGenerale) <= 0.01
                            }"
                        >
                          {{ euro(Math.abs(aggregates.totaleGenerale)) }}
                          <div class="text-[9px] font-normal opacity-75">
                              {{ aggregates.totaleGenerale > 0.01 ? 'DEBITO TOT.' : (aggregates.totaleGenerale < -0.01 ? 'CREDITO TOT.' : 'PAREGGIO') }}
                          </div>
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </template>

              <div v-else class="text-center py-12 text-muted-foreground bg-gray-50 rounded-lg border border-dashed">
                <p v-if="!props.pianoRate">Caricamento dati...</p>
                <p v-else>{{ showOnlyCredits ? "Nessun credito da rimborsare." : "Nessuna quota trovata." }}</p>
              </div>
            </TabsContent>

          </Tabs>

        </section>
      </div>
    </div>

    <!-- MODALE APPROVAZIONE -->
    <Teleport to="body">
        <div v-if="showApprovazioneModal" 
             class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-slate-200 dark:border-slate-800">
                <div class="bg-emerald-50 p-6 border-b border-emerald-100 flex items-start gap-4">
                    <div class="bg-emerald-100 p-2.5 rounded-xl shrink-0">
                        <Gavel class="w-6 h-6 text-emerald-600" />
                    </div>
                    <div>
                        <h3 class="font-black text-emerald-900 text-lg">Approvazione piano rate</h3>
                        <p class="text-xs text-emerald-700/70 mt-1">Registra i dati della delibera assembleare prima di rendere esecutivo il piano (Art. 1135 c.c.).</p>
                    </div>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-1.5">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Data delibera assembleare *</Label>
                        <Input type="date" v-model="formApprovazione.data_delibera_assemblea" class="h-10" />
                        <p class="text-[10px] text-slate-400 leading-tight">Data in cui l'assemblea ha approvato questo piano rate. Sarà riportata nell'audit trail contabile.</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">N. Verbale (opzionale)</Label>
                        <Input type="text" v-model="formApprovazione.numero_verbale" placeholder="Es. Verbale n. 3/2026" class="h-10" />
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Note o Riferimenti (opzionale)</Label>
                        <textarea 
                            v-model="formApprovazione.nota_approvazione"
                            rows="2"
                            placeholder="Es. Approvato con 8 voti favorevoli su 10 millesimi presenti..."
                            class="w-full border border-slate-200 rounded-xl p-3 text-sm bg-slate-50 outline-none resize-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-400 transition-all"
                        />
                    </div>
                    <div v-if="props.pianoRate.data_delibera_assemblea" class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-500 space-y-1 mt-2">
                        <p class="font-bold text-slate-700">Ultima delibera registrata:</p>
                        <p>Data: <span class="font-mono">{{ toItalian(props.pianoRate.data_delibera_assemblea) }}</span></p>
                        <p v-if="props.pianoRate.numero_verbale">Verbale: {{ props.pianoRate.numero_verbale }}</p>
                    </div>
                    <div class="flex gap-3 pt-2 mt-4">
                        <Button variant="outline" class="flex-1 h-11 rounded-xl font-bold" @click="annullaApprovazione">Annulla</Button>
                        <Button 
                            class="flex-1 h-11 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black"
                            :disabled="!formApprovazione.data_delibera_assemblea || isProcessingStatus"
                            @click="confermaApprovazione"
                        >
                            <span v-if="isProcessingStatus" class="flex items-center gap-2">
                                <RotateCw class="w-4 h-4 animate-spin" /> Registrazione...
                            </span>
                            <span v-else>Approva e registra</span>
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <ConfirmDialog 
        v-model="isEmissionModalOpen"
        title="Conferma emissione"
        confirm-text="Emetti ora"
        variant="default"
        :loading="formEmissione.processing"
        @confirm="submitEmissione"
    >
        <div class="grid gap-4 py-4">
            <DialogDescription>
                Stai per emettere <strong>{{ selectedRateIds.length }} rate</strong>.
            </DialogDescription>
            <div class="grid gap-2 mb-2">
                <Label>Data registrazione</Label>
                <Input type="date" v-model="formEmissione.data_emissione" />
            </div>
            <div class="grid gap-2 mb-3">
                <Label>Causale contabile (Opzionale)</Label>
                <Input type="text" v-model="formEmissione.descrizione_personalizzata" placeholder="Es. Emissione rata conguaglio..." />
                <p class="text-[10px] text-slate-500 leading-tight">Se lasciato vuoto, il sistema userà la dicitura standard (es. "Emissione Rata 1").</p>
            </div>
            <div class="flex items-start space-x-3 p-3 rounded-lg border bg-slate-50/50">
                <Checkbox id="invia-notifiche" v-model="formEmissione.invia_notifiche" class="mt-1" />
                <div class="grid gap-1.5 leading-none flex-1">
                    <label for="invia-notifiche" class="text-sm font-semibold leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer flex items-center gap-2">
                        Rendi visibile e invia notifiche
                        <HoverCard>
                            <HoverCardTrigger as-child>
                                <button type="button" class="cursor-pointer flex items-center" @click.stop>
                                    <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                                </button>
                            </HoverCardTrigger>
                            <HoverCardContent class="w-80 z-[100]">
                                <div class="space-y-3">
                                    <h4 class="text-sm font-semibold flex items-center gap-2 text-slate-800">
                                        <Info class="w-4 h-4 text-indigo-500" /> Emissione silenziosa
                                    </h4>
                                    <div class="text-sm space-y-2 text-slate-600">
                                        <p>Disabilita questa opzione se devi prima <strong>caricare manualmente dei pagamenti pregressi</strong> (es. allineamento da Excel).</p>
                                        <p>Le rate verranno generate contabilmente, ma i condòmini <strong>non riceveranno notifiche</strong> e non le vedranno nella loro area privata.</p>
                                        <Separator class="my-2"/>
                                        <div class="text-xs text-amber-600 italic font-medium">Ricordati di pubblicarle successivamente usando il tasto "Pubblica" in tabella.</div>
                                    </div>
                                </div>
                            </HoverCardContent>
                        </HoverCard>
                    </label>
                    <p class="text-xs text-muted-foreground">Se disattivato, i condòmini non vedranno la scadenza finché non la pubblichi manualmente.</p>
                </div>
            </div>
        </div>
    </ConfirmDialog>

    <ConfirmDialog 
        v-model="isAlertOpen"
        title="Sei assolutamente sicuro?"
        confirm-text="Sì, annulla emissione"
        cancel-text="Indietro"
        variant="destructive"
        @confirm="executeAnnullamento"
    >
        Stai per annullare l'emissione contabile di questa rata. Questa azione cancellerà la scrittura contabile associata.
        <br><br>
        <strong>Nota:</strong> Se sono già presenti incassi, l'operazione verrà bloccata.
    </ConfirmDialog>

    <Dialog :open="feedbackDialog.open" @update:open="feedbackDialog.open = $event">
        <DialogContent class="sm:max-w-[400px]">
            <div class="flex flex-col items-center justify-center text-center pt-4">
                <div v-if="!feedbackDialog.isError" class="h-12 w-12 rounded-full bg-emerald-100 flex items-center justify-center mb-4">
                    <CheckCircle2 class="h-8 w-8 text-emerald-600" />
                </div>
                <div v-else class="h-12 w-12 rounded-full bg-red-100 flex items-center justify-center mb-4">
                    <XCircle class="h-8 w-8 text-red-600" />
                </div>
                <DialogHeader>
                    <DialogTitle class="text-center w-full block" :class="feedbackDialog.isError ? 'text-red-700' : 'text-emerald-700'">
                        {{ feedbackDialog.title }}
                    </DialogTitle>
                    <DialogDescription class="pt-2 text-center w-full block">
                        {{ feedbackDialog.message }}
                    </DialogDescription>
                </DialogHeader>
            </div>
            <DialogFooter class="sm:justify-center mt-4">
                <Button type="button" class="w-full sm:w-auto min-w-[120px]" 
                    :class="feedbackDialog.isError ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'"
                    @click="feedbackDialog.open = false">
                    {{ feedbackDialog.isError ? 'Chiudi' : 'Ottimo, prosegui' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDialog 
        v-model="isMigrationDialogOpen"
        title="Aggiornamento necessario"
        confirm-text="Aggiorna piano rate ora"
        variant="default"
        @confirm="executeMigration"
    >
        <div class="flex flex-col gap-3 text-base text-gray-700">
            <div class="flex items-center gap-2 text-amber-600 font-bold">
                <AlertTriangle class="h-5 w-5" /> Attenzione
            </div>
            <p>Abbiamo rilevato che questo Piano Rate è stato generato con una versione precedente. Per garantire la <strong>tracciabilità contabile</strong> e abilitare l'emissione, è necessario rigenerare i calcoli.</p>
            <span class="text-xs text-gray-500 bg-gray-100 p-2 rounded block">Nessun importo verrà modificato, verranno solo aggiunti i dettagli per la trasparenza.</span>
        </div>
    </ConfirmDialog>
    
    <ConfirmDialog 
        v-model="isRecalculateAlertOpen"
        title="Manutenzione piano rate"
        :confirm-text="(selectedOrphanIds.length > 0) ? `Sincronizza (${selectedOrphanIds.length}) e ricalcola` : 'Ricalcola (Senza aggiunte)'"
        :variant="(selectedOrphanIds.length > 0) ? 'warning' : 'default'"
        @confirm="executeRecalculate"
    >
        <div v-if="(copertura?.scoperto_count ?? 0) > 0" class="space-y-4">
            <div class="bg-amber-50 p-3 border border-amber-200 rounded-lg text-amber-800 text-sm">
                <p class="font-bold flex items-center gap-2">
                    <AlertTriangle class="w-4 h-4" /> Attenzione: Voci scoperte rilevate
                </p>
                <p class="mt-1 mb-2">Seleziona le voci da includere in questo piano:</p>
                <div class="space-y-2 max-h-40 overflow-y-auto pr-1">
                    <div v-for="o in copertura?.orfani" :key="o.id" class="flex items-start space-x-2">
                        <Checkbox :id="`orphan-${o.id}`" v-model="orphanCheckboxes[o.id]" />
                        <label :for="`orphan-${o.id}`" class="text-xs font-medium leading-none cursor-pointer pt-0.5 select-none">
                            {{ o.nome }} <span class="font-mono text-amber-700">({{ euro(o.importo) }})</span>
                        </label>
                    </div>
                </div>
            </div>
            <p class="text-[11px] text-slate-500 italic">Se queste voci appartengono a un altro piano (es. Piano Scale), deseleziona la casella sopra.</p>
        </div>
        <div v-else>Il piano verrà ricalcolato in base ai millesimi attuali. Le rate non emesse saranno rigenerate.</div>
    </ConfirmDialog>

    <ConfirmDialog 
        v-model="isDeleteItemModalOpen"
        title="Rimuovere voce dal piano?"
        confirm-text="Sì, rimuovi e ricalcola"
        variant="destructive"
        @confirm="executeDetachItem"
    >
        <div v-if="itemToDelete?.is_parent" class="bg-amber-50 p-3 rounded-md border border-amber-200 text-amber-800 mb-3 mt-2">
            <p class="font-bold flex items-center gap-2 mb-1 text-xs uppercase">
                <AlertTriangle class="w-3 h-3" /> Attenzione: voce raggruppata
            </p>
            <p class="text-xs">
                Stai rimuovendo il gruppo <strong>{{ itemToDelete.nome }}</strong>.<br>
                Questa azione <strong>eliminerà anche tutti i sottoconti</strong> collegati.
            </p>
        </div>
        <div v-else class="text-sm text-gray-700">
            Stai per eliminare la voce di spesa:
            <div class="mt-2 p-2 bg-slate-50 border rounded font-medium flex justify-between items-center">
                <span>{{ itemToDelete?.nome }}</span>
                <span class="font-bold text-slate-900">{{ itemToDelete?.importo ? euro(itemToDelete.importo) : '' }}</span>
            </div>
        </div>
        <div class="mt-4 space-y-3">
            <div class="flex items-start gap-2 text-xs text-slate-600">
                <CheckCircle2 class="w-4 h-4 text-emerald-600 shrink-0" />
                <span>La voce tornerà tra gli "Orfani" e potrà essere riaggiunta in futuro.</span>
            </div>
            <div class="flex items-start gap-2 text-xs text-slate-600">
                <PieChart class="w-4 h-4 text-blue-600 shrink-0" />
                <span>Il <strong>totale del piano diminuirà</strong> dell'importo indicato.</span>
            </div>
            <div class="flex items-start gap-2 text-xs text-amber-700 bg-amber-50 p-2 rounded border border-amber-100">
                <RotateCw class="w-4 h-4 shrink-0 mt-0.5" />
                <span><strong>Importante:</strong> Tutte le rate dei condomini verranno ricalcolate immediatamente.</span>
            </div>
        </div>
    </ConfirmDialog>

    <ModalSpostaSpesa 
        v-model:show="isSpostaSpesaOpen"
        :piano-rate-id="props.pianoRate.id"
        :condominio-id="props.condominio.id"
        :sources="props.sources"
        :destinations="props.destinations"
        @success="showFeedback('Budget Spostato', 'Operazione completata con successo. Il residuo è stato aggiornato.', false)"
    />

  </GestionaleLayout>
</template>

<style scoped>
.overflow-x-auto::-webkit-scrollbar {
    height: 8px;
}
.overflow-x-auto::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}
.overflow-x-auto::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.overflow-x-auto::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Nasconde la scrollbar orizzontale della action bar su mobile */
.scrollbar-none {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.scrollbar-none::-webkit-scrollbar {
    display: none;
}

table {
  border-collapse: separate; 
  border-spacing: 0;
}

.sticky {
  background-clip: padding-box; 
}
</style>