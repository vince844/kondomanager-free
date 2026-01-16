<script setup lang="ts">
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useEventStyling } from '@/composables/useEventStyling';
import { format, differenceInDays } from 'date-fns';
import { it } from 'date-fns/locale';
import { Building2, Wallet, Users, Banknote, CalendarDays, FileText, AlertCircle, ArrowRight, CheckCircle, AlertTriangle, CreditCard, Info, Clock } from 'lucide-vue-next'; // Aggiunto Clock
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3'; // Import router per la chiamata

const props = defineProps<{
    isOpen: boolean;
    evento: any;
}>();

const emit = defineEmits(['close']);
const { getEventStyle } = useEventStyling();
const isProcessing = ref(false); // Stato caricamento pulsante

const isAdmin = computed(() => props.evento?.meta?.type === 'emissione_rata');
const isCondomino = computed(() => props.evento?.meta?.type === 'scadenza_rata_condomino');

// ... (Resto delle computed esistenti: isCredit, daysDiff, isExpired, formatting...) ...
const isCredit = computed(() => isCondomino.value && (props.evento?.meta?.importo_restante < 0));
const daysDiff = computed(() => { if (!props.evento?.start_time) return 0; return differenceInDays(new Date(props.evento.start_time), new Date()); });
const isExpired = computed(() => !isCredit.value && daysDiff.value < 0);
const formatDate = (dateStr: string) => { if(!dateStr) return ''; return format(new Date(dateStr), "d MMMM yyyy", { locale: it }); };
const formatMoney = (amount: number) => { return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(amount / 100); };

// Funzione per inviare la segnalazione
const reportPayment = () => {
    isProcessing.value = true;
    router.post(route('user.eventi.report_payment', props.evento.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            isProcessing.value = false;
            // Il dialog si aggiornerà da solo grazie a Inertia che ricarica i dati
            emit('close'); 
        },
        onError: () => isProcessing.value = false
    });
};
</script>

<template>
    <Dialog :open="isOpen" @update:open="emit('close')">
        <DialogContent class="sm:max-w-3xl p-0 overflow-hidden rounded-xl border-none shadow-2xl bg-white dark:bg-slate-950 block gap-0">
            <div class="flex flex-col md:flex-row h-full min-h-[400px]">
                
                <div class="md:w-[35%] bg-slate-50 dark:bg-slate-900/50 p-6 flex flex-col justify-between border-r border-slate-100 dark:border-slate-800">
                    
                    <div>
                        <div class="flex flex-row flex-wrap items-center gap-2 mb-6">
                            <Badge variant="outline" :class="[getEventStyle(evento).color, 'border-current bg-white dark:bg-slate-900 shadow-sm px-2 py-0.5 whitespace-nowrap']">
                                <component :is="getEventStyle(evento).icon" class="w-3.5 h-3.5 mr-1.5" />
                                {{ getEventStyle(evento).label }}
                            </Badge>
                            <Badge v-if="isExpired && !getEventStyle(evento).label.toLowerCase().includes('scaduto')" variant="destructive" class="bg-red-100 text-red-700 border-red-200 px-2 py-0.5 whitespace-nowrap">
                                <AlertTriangle class="w-3.5 h-3.5 mr-1" /> Scaduto
                            </Badge>
                            <Badge v-if="isCredit" class="bg-blue-100 text-blue-700 border-blue-200 hover:bg-blue-200 px-2 py-0.5 whitespace-nowrap">
                                <Info class="w-3.5 h-3.5 mr-1" /> A Credito
                            </Badge>
                        </div>
                        
                        <div class="mb-8">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">Data Riferimento</span>
                            <div class="flex items-center gap-2" :class="isExpired ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-slate-200'">
                                <CalendarDays class="w-5 h-5" :class="isExpired ? 'text-red-400' : 'text-slate-400'" />
                                <span class="text-lg font-medium capitalize">{{ formatDate(evento.start_time) }}</span>
                            </div>
                            <span v-if="isExpired" class="text-xs text-red-500 font-medium mt-1 block">Ritardo di {{ Math.abs(daysDiff) }} giorni</span>
                        </div>

                        <div v-if="evento.meta?.totale_rata || evento.meta?.importo_originale">
                             <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block mb-1">{{ isAdmin ? 'Totale Emissione' : (isCredit ? 'Importo a Credito' : 'Importo Rata') }}</span>
                            <span class="text-4xl font-bold tracking-tight block tabular-nums" :class="isCredit ? 'text-blue-600 dark:text-blue-400' : 'text-slate-900 dark:text-white'">
                                {{ formatMoney(Math.abs(evento.meta.totale_rata || evento.meta.importo_originale)) }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-8">
                         <div v-if="isAdmin && evento.meta?.action_url">
                            <Button as-child class="w-full h-12 text-white font-semibold shadow-lg transition-all rounded-lg" :class="isExpired ? 'bg-red-600 hover:bg-red-700 shadow-red-900/20' : 'bg-blue-600 hover:bg-blue-700 shadow-blue-900/20'">
                                <a :href="evento.meta.action_url" class="flex items-center justify-center gap-2">{{ isExpired ? 'Emetti Subito' : "Vai all'Emissione" }}<ArrowRight class="w-4 h-4" /></a>
                            </Button>
                        </div>

                        <div v-else-if="isCondomino" class="flex flex-col gap-3">
                            
                            <div v-if="evento.meta?.status === 'reported'" class="text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 p-3 rounded-lg border border-amber-100 dark:border-amber-800 text-center leading-relaxed">
                                <span class="font-semibold block mb-1 flex items-center justify-center gap-1"><Clock class="w-3 h-3"/> In attesa di verifica</span>
                                Hai segnalato il pagamento. L'amministratore verificherà l'incasso a breve.
                            </div>

                            <div v-else-if="!isCredit && evento.meta?.status !== 'paid'" class="flex flex-col gap-3">
                                <Button 
                                    variant="outline" 
                                    class="w-full border-slate-300 text-slate-600 hover:bg-slate-50 rounded-lg"
                                    :disabled="isProcessing"
                                    @click="reportPayment"
                                >
                                    {{ isProcessing ? 'Invio in corso...' : 'Ho già effettuato il pagamento' }}
                                </Button>
                            </div>

                            <div v-else-if="isCredit" class="text-xs text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-100 dark:border-blue-800 text-center leading-relaxed">
                                <span class="font-semibold block mb-1">Nessun pagamento necessario.</span>
                                Questo importo a credito verrà scalato automaticamente dalle rate successive.
                            </div>

                            <div v-else-if="evento.meta?.status === 'paid'" class="text-xs text-emerald-600 dark:text-emerald-400 text-center leading-relaxed">
                                Pagamento registrato e confermato il {{ formatDate(evento.updated_at) }}.
                            </div>

                        </div>
                    </div>
                </div>

                <div class="md:w-[65%] p-6 flex flex-col relative"> 
                    <h2 class="text-2xl font-bold pr-10 mb-6 leading-tight flex items-start gap-2" :class="isExpired ? 'text-red-600 dark:text-red-500' : 'text-slate-900 dark:text-white'">
                        <AlertTriangle v-if="isExpired" class="w-7 h-7 shrink-0" />
                        {{ evento.title }}
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 mb-6">
                        <div v-if="evento.meta?.condominio_nome" class="group"><span class="text-xs text-slate-500 mb-1 flex items-center gap-1.5"><Building2 class="w-3.5 h-3.5" /> Condominio</span><p class="font-medium text-slate-900 dark:text-white truncate">{{ evento.meta.condominio_nome }}</p></div>
                        <div v-if="evento.meta?.gestione" class="group"><span class="text-xs text-slate-500 mb-1 flex items-center gap-1.5"><Wallet class="w-3.5 h-3.5" /> Gestione</span><p class="font-medium text-slate-900 dark:text-white truncate">{{ evento.meta.gestione }}</p></div>
                        <div v-if="evento.meta?.piano_nome" class="group"><span class="text-xs text-slate-500 mb-1 flex items-center gap-1.5"><FileText class="w-3.5 h-3.5" /> Piano Rate</span><p class="font-medium text-slate-900 dark:text-white truncate">{{ evento.meta.piano_nome }}</p></div>
                        <div v-if="evento.meta?.numero_rata" class="group"><span class="text-xs text-slate-500 mb-1 flex items-center gap-1.5"><Banknote class="w-3.5 h-3.5" /> Rata</span><p class="font-medium text-slate-900 dark:text-white">Numero {{ evento.meta.numero_rata }}</p></div>
                    </div>

                    <div v-if="isCondomino && evento.meta?.status === 'reported'" class="mb-6 flex items-center justify-between p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50">
                        <span class="text-amber-700 dark:text-amber-500 flex items-center gap-2 font-semibold text-sm"><Clock class="w-4 h-4" /> Verifica in corso</span>
                        <span class="font-bold text-lg text-amber-700 dark:text-amber-500">{{ formatMoney(evento.meta.importo_restante) }}</span>
                    </div>

                    <div v-if="isCondomino && !isCredit && evento.meta?.status !== 'reported' && (evento.meta?.status === 'partial' || evento.meta?.status === 'pending')" class="mb-6 flex items-center justify-between p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50">
                        <span class="text-amber-700 dark:text-amber-500 flex items-center gap-2 font-semibold text-sm"><AlertCircle class="w-4 h-4" /> Resta da Pagare</span>
                        <span class="font-bold text-lg text-amber-700 dark:text-amber-500">{{ formatMoney(evento.meta.importo_restante) }}</span>
                    </div>

                    <div v-if="isCondomino && isCredit" class="mb-6 flex items-center justify-between p-3 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800/50">
                        <span class="text-blue-700 dark:text-blue-500 flex items-center gap-2 font-semibold text-sm"><Info class="w-4 h-4" /> Credito Disponibile</span>
                        <span class="font-bold text-lg text-blue-700 dark:text-blue-500">{{ formatMoney(Math.abs(evento.meta.importo_restante)) }}</span>
                    </div>

                    <div v-if="isCondomino && evento.meta?.status === 'paid'" class="mb-6 flex items-center justify-between p-3 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/50">
                        <span class="text-emerald-700 dark:text-emerald-500 flex items-center gap-2 font-semibold text-sm"><CheckCircle class="w-4 h-4" /> Pagato</span>
                        <span class="font-bold text-lg text-emerald-700 dark:text-emerald-500">{{ formatMoney(evento.meta.importo_pagato || evento.meta.importo_originale) }}</span>
                    </div>

                    <div class="mt-auto pt-6 border-t border-slate-100 dark:border-slate-800">
                        <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed whitespace-pre-line">{{ evento.description }}</p>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>