<script setup lang="ts">
import { AlertTriangle, Wallet, Zap, ArrowRight, CheckCircle2 } from 'lucide-vue-next';

interface Suggestion {
    type: string;
    title: string;
    description: string;
    action_data?: any;
}

// Struttura aggiornata: separa esplicitamente Gap Cassa e Sforo Budget.
// - sforoBudget > 0 → problema di Competenza → blocca il submit con Modal Override
// - gapCassa > 0    → problema di Liquidità  → avvisa ma lascia procedere
interface AdvisorData {
    status:        'ok' | 'warning' | 'critical';
    gapCassa:      number;   // centesimi
    sforoBudget:   number;   // centesimi
    alertMessages: string[];
    suggestions:   Suggestion[];
}

defineProps<{ advisorData: AdvisorData | null }>();
defineEmits(['executeAction']);
</script>

<template>
    <div
        v-if="advisorData"
        class="mb-6 rounded-xl border border-l-4 shadow-sm relative overflow-hidden transition-all duration-300"
        :class="{
            'bg-red-50 border-red-200 border-l-red-500':             advisorData.status === 'critical',
            'bg-amber-50 border-amber-200 border-l-amber-500':       advisorData.status === 'warning',
            'bg-emerald-50 border-emerald-200 border-l-emerald-500': advisorData.status === 'ok',
        }"
    >
        <div class="p-4">

            <!-- OK: tutto a posto -->
            <div v-if="advisorData.status === 'ok'" class="flex items-center gap-3">
                <div class="bg-emerald-100 p-2 rounded-full text-emerald-600">
                    <CheckCircle2 class="w-5 h-5" />
                </div>
                <h3 class="font-bold text-emerald-800 text-sm">Copertura Finanziaria e Budget OK</h3>
            </div>

            <!-- Warning / Critical: uno o più problemi rilevati -->
            <div v-else>
                <div class="flex items-start gap-3 mb-4">
                    <div
                        class="p-2 rounded-full shrink-0"
                        :class="advisorData.status === 'critical'
                            ? 'bg-red-100 text-red-600'
                            : 'bg-amber-100 text-amber-600'"
                    >
                        <Zap class="w-5 h-5" />
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800 text-sm">Advisor: Azioni Richieste</h3>
                        <ul class="list-disc pl-4 mt-2 text-xs space-y-1.5">
                            <li
                                v-for="(msg, i) in advisorData.alertMessages"
                                :key="i"
                                class="font-medium"
                                :class="msg.includes('Budget') ? 'text-red-600' : 'text-slate-700'"
                            >
                                {{ msg }}
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Suggerimenti azione (solo per gap cassa) -->
                <div v-if="advisorData.suggestions.length" class="space-y-2 pl-12">
                    <div
                        v-for="(suggestion, idx) in advisorData.suggestions"
                        :key="idx"
                        class="bg-white/80 border p-3 rounded-lg hover:shadow-md cursor-pointer flex justify-between items-center"
                        @click="$emit('executeAction', suggestion)"
                    >
                        <div class="flex gap-3 items-center">
                            <Wallet v-if="suggestion.type === 'giroconto_fondo'" class="w-4 h-4 text-indigo-500" />
                            <AlertTriangle v-else class="w-4 h-4 text-orange-500" />
                            <div>
                                <span class="block text-xs font-bold">{{ suggestion.title }}</span>
                                <span class="block text-[10px] text-slate-500">{{ suggestion.description }}</span>
                            </div>
                        </div>
                        <ArrowRight class="w-4 h-4 text-indigo-400" />
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>