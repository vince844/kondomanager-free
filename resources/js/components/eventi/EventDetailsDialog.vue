<script setup lang="ts">
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useEventStyling } from '@/composables/useEventStyling';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'; 
import { format, differenceInDays } from 'date-fns';
import { it } from 'date-fns/locale';
import { Building2, Wallet, Banknote, CalendarDays, AlertCircle, ArrowRight, CheckCircle, AlertTriangle, Clock, XCircle, TrendingDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3'; 

const props = defineProps<{ isOpen: boolean; evento: any; }>();
const emit = defineEmits(['close']);
const { getEventStyle } = useEventStyling();
const { euro } = useCurrencyFormatter(); 
const isProcessing = ref(false); 

const isAdmin = computed(() => props.evento?.meta?.type === 'emissione_rata');
const isCondomino = computed(() => props.evento?.meta?.type === 'scadenza_rata_condomino');

const hasFinancialDetails = computed(() => {
    if (isAdmin.value || isCondomino.value) return true;
    const meta = props.evento?.meta;
    return !!(meta?.numero_rata !== undefined || meta?.totale_rata || meta?.importo_originale || meta?.gestione);
});

// --- LOGICA V1.9: SPECCHIO FEDELE DEL DATABASE ---
// Prendiamo l'importo originale salvato nel meta dell'evento (es. 11248)
const importoOriginale = computed<number>(() => Number(props.evento?.meta?.importo_originale || props.evento?.meta?.totale_rata || 0));

// L'importo restante è quello che manca al saldo di QUESTA specifica rata
const importoRestante = computed<number>(() => props.evento?.meta?.importo_restante !== undefined ? Number(props.evento?.meta?.importo_restante) : importoOriginale.value);

const importoPagato = computed<number>(() => Number(props.evento?.meta?.importo_pagato || 0));

// Stati di pagamento
const isPaid = computed(() => props.evento?.meta?.status === 'paid'); 
const isReported = computed(() => props.evento?.meta?.status === 'reported');
const isRejected = computed(() => props.evento?.meta?.status === 'rejected');

// Gestione visiva: Un evento è "Credito" solo se il suo importo totale nel DB è negativo (es. Rata Zero con credito)
const isGeneratingCredit = computed(() => isCondomino.value && importoOriginale.value < -0.01);

// Nessun pagamento richiesto: importo restante è zero o meno (es. Saldo Iniziale con credito che azzera la rata)
const isNothingToPay = computed(() => isCondomino.value && !isGeneratingCredit.value && importoRestante.value <= 0.01 && !isPaid.value);

const isRataZero = computed(() => props.evento?.meta?.numero_rata === 0);

// Una rata è coperta solo se l'importo restante è zero ma non è ancora segnata come pagata (raro in V1.9, ma possibile)
const isFullyCoveredByCredit = computed(() => importoRestante.value === 0 && importoOriginale.value > 0 && !isPaid.value && isCondomino.value);

// --- CALCOLO SCADENZA ---
const daysDiff = computed(() => { 
    if (!props.evento?.start_time) return 0; 
    return differenceInDays(new Date(props.evento.start_time), new Date()); 
});

// Mostriamo l'allarme rosso solo se c'è effettivamente un debito residuo sulla rata
const isExpired = computed(() => 
    daysDiff.value < 0 && 
    !isGeneratingCredit.value && 
    !isPaid.value && 
    !isReported.value && 
    importoRestante.value > 0.01
);

const isEmitted = computed(() => {
    // NESSUN BYPASS: Leggiamo esattamente cosa dice il Database (il JSON meta)
    const val = props.evento?.meta?.is_emitted;
    
    // Accettiamo il "true" in tutti i formati che MySQL/Laravel potrebbero sputare fuori
    return val === true || val === 1 || val === '1' || val === 'true';
});

const formatDate = (dateStr: string) => { if(!dateStr) return ''; return format(new Date(dateStr), "d MMMM yyyy", { locale: it }); };

const reportPayment = () => {
    isProcessing.value = true;
    router.post(route('user.eventi.report_payment', props.evento.id), {}, {
        preserveScroll: true,
        onSuccess: () => { isProcessing.value = false; emit('close'); },
        onError: () => isProcessing.value = false
    });
};

interface ScontrinoItem {
    descrizione: string;
    quota_pura: number;
    saldo_applicato_diretto: number; 
    costo_netto: number;             
}

// --- SCONTRINO V1.9 (Nessuna cascata, solo lista voci) ---
const scontrinoData = computed<ScontrinoItem[]>(() => {
    const quote = props.evento.meta?.dettaglio_quote || [];

    return quote.map((q: any) => {
        const quotaSpesa = Number(q.componente_spesa ?? q.audit?.quota_pura ?? q.importo);
        const saldoDiretto = Number(q.componente_saldo ?? q.audit?.saldo_usato ?? 0);
        
        return {
            descrizione: q.descrizione || 'Quota ordinaria',
            quota_pura: quotaSpesa,
            saldo_applicato_diretto: saldoDiretto,
            costo_netto: quotaSpesa + saldoDiretto,
        };
    });
});

// LOGICA WALLET: Rileva se c'è un credito di Rata Zero (salvato nel meta come credito_rata_zero)
const creditoDisponibile = computed<number>(() => {
    // `credito_rata_zero` è il credito della rata 0 ancora DISPONIBILE, cioè al netto di
    // quanto ne è già stato speso: non il credito originario. È la stessa misura su cui il
    // motore decide quanto si può compensare.
    return Number(props.evento?.meta?.credito_rata_zero || 0);
});

// Calcoliamo se il credito copre tutto o solo una parte
const creditoCapiente = computed(() => creditoDisponibile.value >= importoRestante.value);
const differenzaDaPagare = computed(() => Math.max(0, importoRestante.value - creditoDisponibile.value));

// Nuovo metodo per segnalare il pagamento CON intenzione di usare il credito
const reportPaymentWithCredit = () => {
    isProcessing.value = true;
    // Inviando intent_usa_credito = true, il backend saprà che deve avvisare l'admin
    router.post(route('user.eventi.report_payment', props.evento.id), {
        intent_usa_credito: true,
        credito_richiesto: Math.min(creditoDisponibile.value, importoRestante.value)
    }, {
        preserveScroll: true,
        onSuccess: () => { isProcessing.value = false; emit('close'); },
        onError: () => { isProcessing.value = false; }
    });
};

</script>

<template>
    <Dialog :open="isOpen" @update:open="emit('close')">
        <DialogContent class="sm:max-w-5xl p-0 overflow-hidden rounded-xl border-none shadow-2xl bg-white dark:bg-slate-950 block gap-0">
            <div class="flex flex-col md:flex-row h-full min-h-[450px]">
                
                <div v-if="hasFinancialDetails" class="md:w-[45%] bg-slate-50 dark:bg-slate-900/50 p-8 flex flex-col gap-6 border-r border-slate-100 dark:border-slate-800 overflow-y-auto max-h-[80vh]">
                    
                    <div>
                        <div class="flex flex-row flex-wrap items-center gap-2 mb-6">
                            <Badge variant="outline" :class="[getEventStyle(evento).color, 'border-current bg-white dark:bg-slate-900 shadow-sm px-2 py-0.5 whitespace-nowrap']">
                                <component :is="getEventStyle(evento).icon" class="w-3.5 h-3.5 mr-1.5" /> {{ getEventStyle(evento).label }}
                            </Badge>
                        </div>
                        
                        <div class="mb-0">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Data scadenza</span>
                            <div class="flex items-center gap-2" :class="isExpired ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-slate-200'">
                                <CalendarDays class="w-5 h-5" :class="isExpired ? 'text-red-400' : 'text-slate-400'" />
                                <span class="text-lg font-medium capitalize">{{ formatDate(evento.start_time) }}</span>
                            </div>
                        </div>
                    </div>

                    <div>
                         <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1"> 
                             {{ isAdmin ? 'Totale emissione rata' : (isGeneratingCredit ? 'Credito di questa rata' : 'Totale da versare') }} 
                         </span>
                        
                        <span class="text-4xl font-bold tracking-tight block tabular-nums" 
                              :class="isGeneratingCredit ? 'text-blue-600 dark:text-blue-400' : (isFullyCoveredByCredit ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-900 dark:text-white')"> 
                            {{ euro(importoRestante, { forcePlus: false }) }} 
                        </span>

                        <div v-if="scontrinoData.length > 0" class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-800 space-y-6">
                            <div class="flex flex-col gap-2 mb-2">
                                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Dettaglio Rata Corrente</p>
                            </div>

                            <div v-for="(item, idx) in scontrinoData" :key="idx" class="relative group">
                                <div v-if="idx < scontrinoData.length - 1" class="absolute left-[11px] top-6 bottom-[-24px] w-px bg-slate-200 dark:bg-slate-700 z-0"></div>

                                <div class="flex items-start gap-3 relative z-10">
                                    <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border bg-white dark:bg-slate-800 shadow-sm border-slate-200 text-slate-500">
                                        <Building2 class="h-3 w-3" />
                                    </div>
                                    
                                    <div class="flex-1">
                                        <div class="font-bold text-xs text-slate-700 dark:text-slate-200 mb-2">{{ item.descrizione }}</div>
                                        
                                        <div class="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-700 rounded-lg p-2.5 space-y-1.5 text-xs shadow-sm">
                                            
                                            <div v-if="item.quota_pura !== 0 || item.saldo_applicato_diretto === 0" class="flex justify-between items-center text-slate-500 dark:text-slate-400">
                                                <span class="pl-3">Quota ordinaria:</span>
                                                <span class="font-mono">{{ euro(item.quota_pura) }}</span>
                                            </div>

                                            <div v-if="item.saldo_applicato_diretto !== 0" class="flex justify-between items-center" :class="item.saldo_applicato_diretto > 0 ? 'text-red-500 dark:text-red-400' : 'text-emerald-500 dark:text-emerald-400'">
                                                <span class="flex items-center gap-1.5">
                                                    <div class="w-1.5 h-1.5 rounded-full" :class="item.saldo_applicato_diretto > 0 ? 'bg-red-500' : 'bg-emerald-500'"></div>
                                                    {{ item.saldo_applicato_diretto > 0 ? 'Debito pregresso integrato:' : 'Credito pregresso applicato:' }}
                                                </span>
                                                <span class="font-mono font-medium">{{ euro(item.saldo_applicato_diretto, { forcePlus: item.saldo_applicato_diretto > 0 }) }}</span>
                                            </div>

                                            <div v-if="item.quota_pura !== 0 && item.saldo_applicato_diretto !== 0" class="border-t border-slate-100 dark:border-slate-700 pt-1.5 mt-1 flex justify-between items-center">
                                                <span class="text-xs font-bold uppercase text-slate-400">Totale unità:</span>
                                                <span class="font-mono font-bold" :class="item.costo_netto < -0.01 ? 'text-emerald-600 dark:text-emerald-400' : (item.costo_netto > 0.01 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white')">
                                                    {{ euro(item.costo_netto) }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div v-if="importoPagato > 0.01" class="mt-4 bg-slate-50/50 dark:bg-slate-900/50 border-t border-slate-200 dark:border-slate-800 -mx-2 px-3 py-3 rounded">
                                <div class="flex justify-between text-slate-500 dark:text-slate-400 mb-1 text-xs">
                                    <span>Totale rata calcolato</span>
                                    <span>{{ euro(importoOriginale) }}</span>
                                </div>
                                <div class="flex justify-between text-emerald-600 dark:text-emerald-500 font-medium pb-2 border-b border-slate-200 dark:border-slate-700 text-xs">
                                    <span class="flex items-center gap-1"><CheckCircle class="w-3 h-3" /> Già versato</span>
                                    <span>- {{ euro(importoPagato) }}</span>
                                </div>
                                <div class="flex justify-between items-center mt-2">
                                    <span class="font-bold text-sm text-slate-900 dark:text-white">Residuo da pagare</span>
                                    <span class="text-xl font-bold font-mono tracking-tight text-slate-900 dark:text-white"> 
                                        {{ euro(importoRestante, { forcePlus: false }) }} 
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div :class="hasFinancialDetails ? 'md:w-[55%]' : 'w-full'" class="p-6 flex flex-col relative overflow-y-auto max-h-[80vh]">
                    
                    <div v-if="!hasFinancialDetails" class="mb-4">
                        <div class="flex flex-wrap items-center gap-3 mb-4">
                            <Badge variant="outline" :class="[getEventStyle(evento).color, 'border-current bg-white dark:bg-slate-900 shadow-sm px-2.5 py-1 whitespace-nowrap']">
                                <component :is="getEventStyle(evento).icon" class="w-3.5 h-3.5 mr-1.5" /> {{ getEventStyle(evento).label }}
                            </Badge>
                            
                            <div class="flex items-center gap-2 text-slate-600 dark:text-slate-400">
                                <CalendarDays class="w-4 h-4" />
                                <span class="text-sm font-medium capitalize">{{ formatDate(evento.start_time) }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <h2 class="text-xl font-bold pr-10 leading-tight flex items-start gap-2" :class="[isExpired ? 'text-red-600 dark:text-red-500' : 'text-slate-900 dark:text-white', hasFinancialDetails ? 'mb-6' : 'mb-3']"> <AlertTriangle v-if="isExpired" class="w-6 h-6 shrink-0" /> {{ evento.title }} </h2>
                    
                    <div v-if="isRejected" class="mb-3 p-4 rounded-lg bg-red-50 border border-red-100"><div class="flex items-start gap-3"><XCircle class="w-5 h-5 text-red-600 shrink-0 mt-0.5" /><div><h4 class="font-bold text-red-700 text-sm">Pagamento rifiutato</h4><div class="bg-white p-2.5 rounded text-xs text-red-800 font-medium border border-red-200/50 italic mt-2"> "{{ evento.meta?.rejection_reason }}" </div><p class="text-xs text-red-500 mt-2">Verifica i dati e riprova.</p></div></div></div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
                        <div v-if="evento.meta?.condominio_nome" class="bg-slate-50 dark:bg-slate-900/50 border border-slate-100 dark:border-slate-800 p-3 rounded-lg min-w-0">
                            <span class="text-[10px] uppercase font-semibold text-slate-400 mb-1 flex items-center gap-1.5">
                                <Building2 class="w-3.5 h-3.5" /> Condominio
                            </span>
                            <p class="font-medium text-sm text-slate-900 dark:text-white truncate" :title="evento.meta.condominio_nome">
                                {{ evento.meta.condominio_nome }}
                            </p>
                        </div>
                        <div v-if="evento.meta?.gestione" class="bg-slate-50 dark:bg-slate-900/50 border border-slate-100 dark:border-slate-800 p-3 rounded-lg min-w-0">
                            <span class="text-[10px] uppercase font-semibold text-slate-400 mb-1 flex items-center gap-1.5">
                                <Wallet class="w-3.5 h-3.5" /> Gestione
                            </span>
                            <p class="font-medium text-sm text-slate-900 dark:text-white truncate" :title="evento.meta.gestione">
                                {{ evento.meta.gestione }}
                            </p>
                        </div>
                        <div v-if="evento.meta?.numero_rata !== undefined" class="bg-slate-50 dark:bg-slate-900/50 border border-slate-100 dark:border-slate-800 p-3 rounded-lg min-w-0">
                            <span class="text-[10px] uppercase font-semibold text-slate-400 mb-1 flex items-center gap-1.5">
                                <Banknote class="w-3.5 h-3.5" /> Rata
                            </span>
                            <p class="font-medium text-sm text-slate-900 dark:text-white truncate">
                                {{ evento.meta.numero_rata === 0 ? 'Saldo Iniziale' : 'Numero ' + evento.meta.numero_rata }}
                            </p>
                        </div>
                    </div>

                    <div v-if="isCondomino">

                        <div v-if="isPaid" class="mb-6 p-6 rounded-xl bg-emerald-50 border border-emerald-200 flex flex-col items-center justify-center text-center gap-3">
                            <div class="p-3 bg-emerald-100 rounded-full text-emerald-600 shadow-sm">
                                <CheckCircle class="w-8 h-8" />
                            </div>
                            <div>
                                <h4 class="text-lg font-bold text-emerald-800 mb-1">Rata Saldata</h4>
                                <p class="text-sm text-emerald-700 leading-relaxed">
                                    Il pagamento per questo documento è stato registrato e validato dall'amministratore. Non è richiesta alcuna azione.
                                </p>
                            </div>
                        </div>

                        <div v-else class="mb-6 space-y-4">
                            
                            <div v-if="evento.meta?.storico_crediti > 0.01 && !isRataZero" class="mb-3 p-4 rounded-lg bg-blue-50 border border-blue-200 flex items-start gap-3">
                                <div class="p-1.5 bg-blue-100 rounded-full text-blue-600 shrink-0 mt-0.5">
                                    <TrendingDown class="w-4 h-4" />
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-blue-800 mb-1">Hai un credito disponibile</h4>
                                    <p class="text-xs text-blue-700 leading-relaxed">
                                        Dalle gestioni o rate precedenti risulta un credito non utilizzato di <span class="font-bold">{{ euro(evento.meta.storico_crediti) }}</span>. 
                                        Puoi usarlo per compensare questa rata.
                                    </p>
                                </div>
                            </div>

                            <div v-if="evento.meta?.storico_arretrati > 0.01 && !isGeneratingCredit && !isRataZero" class="mb-3 p-4 rounded-lg bg-orange-50 border border-orange-200 flex items-start gap-3">
                                <div class="p-1.5 rounded-full text-orange-600 shrink-0 mt-0.5">
                                    <AlertTriangle class="w-4 h-4" />
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-orange-800 mb-1">Attenzione: rate precedenti insolute</h4>
                                    <p class="text-xs text-orange-700 leading-relaxed mb-2">
                                        Oltre a questa rata, risultano arretrati non saldati per un totale di <span class="font-bold">{{ euro(evento.meta.storico_arretrati) }}</span>.
                                    </p>
                                </div>
                            </div>

                            <div v-if="isGeneratingCredit" class="mb-3 p-4 rounded-lg bg-blue-50 border border-blue-200 flex items-start gap-3">
                                <div class="p-1.5 bg-blue-100 rounded-full text-blue-600 shrink-0 mt-0.5">
                                    <TrendingDown class="w-4 h-4" />
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-blue-800 mb-1">Credito a tuo favore</h4>
                                    <p class="text-xs text-blue-700 leading-relaxed mb-2">
                                        Questo documento certifica un credito a tuo favore di <span class="font-bold">{{ euro(Math.abs(importoRestante)) }}</span>. Non è richiesto alcun pagamento.
                                    </p>
                                </div>
                            </div>

                            <div v-else-if="isNothingToPay" class="mb-3 p-4 rounded-lg bg-emerald-50 border border-emerald-200 flex items-start gap-3">
                                <div class="p-1.5 bg-emerald-100 rounded-full text-emerald-600 shrink-0 mt-0.5">
                                    <CheckCircle class="w-4 h-4" />
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-emerald-800 mb-1">Nessun pagamento richiesto</h4>
                                    <p class="text-xs text-emerald-700 leading-relaxed">
                                        Questa voce non richiede alcun versamento. L'importo è stato completamente compensato dal credito applicato.
                                    </p>
                                </div>
                            </div>

                            <div v-else-if="isReported" class="mb-6">
                                <div class="p-4 rounded-lg bg-amber-50 border border-amber-200 mb-4 flex gap-3 items-start">
                                    <div class="p-1.5 bg-amber-100 rounded-full text-amber-600 shrink-0 mt-0.5">
                                    <Clock class="w-4 h-4" />
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-bold text-amber-800 mb-1">Pagamento in verifica</h4>
                                        <p class="text-xs text-amber-700 leading-relaxed">
                                            Hai segnalato di aver effettuato il pagamento. L'amministratore sta verificando l'incasso. Riceverai una notifica a conferma avvenuta.
                                        </p>
                                    </div>
                                </div>
                                <Button class="w-full h-12 bg-amber-100 text-amber-700 border border-amber-200 cursor-not-allowed rounded-lg font-medium shadow-none text-xs" disabled>
                                    In attesa di conferma...
                                </Button>
                            </div>

                            <div v-else>
                                <div class="p-4 rounded-lg bg-amber-50 border border-amber-200 mb-4">
                                    <div class="flex items-center justify-between">
                                        <span class="text-amber-800 flex items-center gap-2 font-semibold text-sm">
                                            <AlertCircle class="w-4 h-4" /> Totale rata corrente
                                        </span>
                                        <span class="font-bold text-xl text-amber-700">{{ euro(importoRestante) }}</span>
                                    </div>
                                </div>
                                
                                <div v-if="!isEmitted && !isRejected">
                                    <div class="p-3 rounded-lg bg-slate-100 border border-slate-200 mb-3 flex gap-3 items-start">
                                        <Clock class="w-4 h-4 mt-0.5 text-slate-500" />
                                        <div>
                                            <h4 class="font-bold text-slate-700 text-xs mb-0.5">Rata in attesa di emissione</h4>
                                            <p class="text-xs text-slate-500 leading-snug">
                                                L'amministratore non ha ancora abilitato i versamenti per questa scadenza. 
                                            </p>
                                        </div>
                                    </div>
                                    <Button class="w-full h-10 bg-slate-100 text-slate-400 border border-slate-200 cursor-not-allowed rounded-lg font-medium shadow-none text-xs" disabled>
                                        Pagamento non ancora attivo
                                    </Button>
                                </div>
                                <div v-else>
                                    
                                    <div v-if="creditoDisponibile > 0.01" class="mb-5">
                                        <div class="bg-blue-50/80 border border-blue-200 rounded-xl p-5 shadow-sm relative overflow-hidden">
                                            
                                            <div class="absolute right-0 bottom-0 opacity-[0.03] translate-x-1/4 translate-y-1/4 pointer-events-none">
                                                <Wallet class="w-32 h-32 text-blue-900" />
                                            </div>
                                            
                                            <div class="relative z-10">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <div class="p-1.5 bg-blue-100 text-blue-600 rounded-md">
                                                        <Wallet class="w-4 h-4" />
                                                    </div>
                                                    <h4 class="font-bold text-blue-900 text-sm">Il tuo Salvadanaio</h4>
                                                </div>
                                                
                                                <p class="text-blue-800 text-xs mb-4 leading-relaxed pr-6">
                                                    Hai un credito disponibile di <strong class="text-blue-900 font-mono text-sm">{{ euro(creditoDisponibile) }}</strong>. <br>
                                                    Vuoi usarlo per compensare questa rata?
                                                </p>

                                                <div v-if="!creditoCapiente" class="bg-white rounded-lg p-3 mb-4 border border-blue-100/50 shadow-sm text-xs">
                                                    <div class="flex justify-between text-slate-500 mb-1.5">
                                                        <span>Costo rata:</span>
                                                        <span class="font-mono">{{ euro(importoRestante) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-emerald-600 font-medium mb-2.5">
                                                        <span>Credito applicato:</span>
                                                        <span class="font-mono">-{{ euro(creditoDisponibile) }}</span>
                                                    </div>
                                                    <div class="flex justify-between text-blue-900 font-bold pt-2 border-t border-slate-100 items-center">
                                                        <span class="text-[10px] uppercase tracking-wider text-blue-500">Nuovo totale:</span>
                                                        <span class="text-sm font-mono tracking-tight">{{ euro(differenzaDaPagare) }}</span>
                                                    </div>
                                                </div>

                                                <div v-if="creditoCapiente">
                                                    <Button @click="reportPaymentWithCredit" :disabled="isProcessing" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium h-10 shadow-sm transition-all rounded-lg text-xs">
                                                        {{ isProcessing ? 'Elaborazione in corso...' : 'Sì, salda la rata con il credito' }}
                                                    </Button>
                                                </div>
                                                <div v-else>
                                                    <Button @click="reportPaymentWithCredit" :disabled="isProcessing" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium h-10 shadow-sm transition-all rounded-lg text-xs">
                                                        {{ isProcessing ? 'Elaborazione in corso...' : 'Ho pagato la differenza (' + euro(differenzaDaPagare) + ')' }}
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                        
                                    <div v-if="!(creditoDisponibile > 0.01 && creditoCapiente)">
                                        <div v-if="creditoDisponibile > 0.01" class="flex items-center justify-center my-3">
                                            <span class="text-[10px] uppercase font-bold text-slate-400 tracking-widest bg-slate-50 px-2 relative z-10">OPPURE</span>
                                            <div class="h-px bg-slate-200 absolute w-full left-0"></div>
                                        </div>

                                        <Button class="w-full h-12 text-white shadow-sm font-semibold rounded-lg" 
                                                :class="isRejected ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'"
                                                :disabled="isProcessing" 
                                                @click="reportPayment">
                                            {{ isProcessing ? 'Invio in corso...' : (isRejected ? 'Ho ri-effettuato il pagamento (Segnala di nuovo)' : 'Ho pagato l\'intera rata tramite bonifico') }}
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div> 
                    </div>

                    <div v-if="isAdmin && evento.meta?.action_url" class="mb-6">
                        <Button as-child class="w-full h-12 text-white font-semibold shadow-lg rounded-lg" :class="isExpired ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'"><a :href="evento.meta.action_url" class="flex items-center justify-center gap-2">{{ isExpired ? 'Emetti subito' : "Vai all'emissione" }}<ArrowRight class="w-4 h-4" /></a></Button>
                    </div>

                    <div v-if="evento.description && !(isCondomino && isPaid)" :class="hasFinancialDetails ? 'mt-3 pt-3 border-t border-slate-100 dark:border-slate-800' : ''">
                        <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed whitespace-pre-line">{{ evento.description }}</p>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>