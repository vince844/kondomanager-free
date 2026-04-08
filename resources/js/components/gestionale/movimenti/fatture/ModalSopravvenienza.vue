<script setup lang="ts">

import { ref, watch } from 'vue';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Scale, Zap, Info } from 'lucide-vue-next';
import vSelect from 'vue-select';
import { useTabelle } from '@/composables/useTabelle';

const props = defineProps<{
    show: boolean;
    fornitoreNome: string;
    condominioId: number;
    // --- NUOVA PROP PER DEFINIRE IL COMPORTAMENTO ---
    mode?: 'corrente' | 'pregressa'; 
}>();

const emit = defineEmits<{
    (e: 'update:show', value: boolean): void;
    (e: 'confirm', payload: any): void;
}>();

const { tabelle, isLoading: isLoadingTabelle, fetchTabelle } = useTabelle();

const datiSopravvenienza = ref({
    nome_voce: '',
    autorizzazione: 'urgenza',
    data_assemblea: '',
    note: '',
    tabella_millesimale_id: null as number | null,
    percentuale_proprietario: 100,
    percentuale_inquilino: 0,
    percentuale_usufruttuario: 0,
});

const onDropdownTabelleOpen = () => {
    if (tabelle.value.length === 0) {
        fetchTabelle(props.condominioId);
    }
};

watch(() => props.show, (isOpen) => {
    if (isOpen) {
        // Rinominiamo la voce di default in base al contesto
        const prefisso = props.mode === 'corrente' ? 'Imprevisto' : 'Integrazione debito pregresso';
        
        datiSopravvenienza.value = {
            nome_voce: `${prefisso} - ${props.fornitoreNome}`,
            autorizzazione: 'urgenza',
            data_assemblea: '',
            note: '',
            tabella_millesimale_id: null,
            percentuale_proprietario: 100,
            percentuale_inquilino: 0,
            percentuale_usufruttuario: 0,
        };
    }
});

const handleCancel = () => {
    emit('update:show', false);
};

const handleConfirm = () => {
    if (datiSopravvenienza.value.nome_voce.length < 5 || !datiSopravvenienza.value.tabella_millesimale_id) return;
    emit('confirm', { ...datiSopravvenienza.value });
    emit('update:show', false);
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-200 dark:border-slate-800">
                
                <div class="p-7 border-b flex items-start gap-4" 
                     :class="mode === 'corrente' ? 'bg-amber-50 dark:bg-amber-950/30 border-amber-100 dark:border-amber-900/30' : 'bg-slate-50 dark:bg-slate-900/50 border-slate-100 dark:border-slate-800/50'">
                    
                    <div class="p-2.5 rounded-xl shrink-0"
                         :class="mode === 'corrente' ? 'bg-amber-100 dark:bg-amber-900/50' : 'bg-slate-200 dark:bg-slate-800'">
                        <Zap v-if="mode === 'corrente'" class="w-6 h-6 text-amber-600" />
                        <Scale v-else class="w-6 h-6 text-slate-600 dark:text-slate-400" />
                    </div>
                    
                    <div>
                        <h3 class="font-black text-lg" :class="mode === 'corrente' ? 'text-amber-900 dark:text-amber-100' : 'text-slate-800 dark:text-slate-100'">
                            {{ mode === 'corrente' ? 'Nuova Spesa Imprevista' : 'Integrazione Debito Pregresso' }}
                        </h3>
                        <p class="text-xs mt-1" :class="mode === 'corrente' ? 'text-amber-700/70 dark:text-amber-400/70' : 'text-slate-500'">
                            {{ mode === 'corrente' 
                               ? 'Stai creando un cassetto per una spesa fuori preventivo.' 
                               : 'Stai convertendo un vecchio debito in una spesa da riscuotere.' }}
                        </p>
                    </div>
                </div>

                <div class="mx-7 mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 rounded-xl flex gap-3">
                    <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                    <div class="text-[11px] text-blue-800 dark:text-blue-200 leading-relaxed">
                        <p class="font-bold mb-1 text-blue-900 dark:text-blue-100">Cosa succede ora?</p>
                        <p v-if="mode === 'corrente'">
                            Il sistema creerà una voce "Sopravvenienza" per isolare questa spesa dal preventivo ordinario. 
                            <strong>Nota:</strong> il salvataggio non genera rate. Dovrai emettere un <strong>Piano rate straordinario</strong> in seguito per riscuotere l'importo.
                        </p>
                        <p v-else>
                            Stai creando il capitolo di spesa necessario per ricaricare un debito degli anni passati. 
                            Potrai chiedere le quote ai condòmini creando un <strong>Piano rate straordinario</strong> dedicato a questa voce.
                        </p>
                    </div>
                </div>

                <div class="p-7 space-y-5">
                    
                    <div class="space-y-1.5">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Nome Nuova Voce di Spesa *</Label>
                        <Input v-model="datiSopravvenienza.nome_voce" class="h-10 text-sm font-semibold" />
                    </div>

                    <div v-if="mode === 'pregressa'" class="space-y-3">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Autorizzazione Legale *</Label>
                        
                        <label class="flex items-start gap-3 p-3 border rounded-xl cursor-pointer transition-colors" 
                               :class="datiSopravvenienza.autorizzazione === 'urgenza' ? 'border-amber-400 bg-amber-50/50 dark:bg-amber-900/10' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="radio" v-model="datiSopravvenienza.autorizzazione" value="urgenza" class="mt-1 w-4 h-4 text-amber-600 focus:ring-amber-500" />
                            <div>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">Intervento d'Urgenza (Art. 1135 c.c.)</p>
                                <p class="text-[10px] text-slate-500 mt-0.5">Rischio decreto. Da ratificare alla prossima assemblea.</p>
                            </div>
                        </label>
                        
                        <label class="flex items-start gap-3 p-3 border rounded-xl cursor-pointer transition-colors" 
                               :class="datiSopravvenienza.autorizzazione === 'assemblea' ? 'border-emerald-400 bg-emerald-50/50 dark:bg-emerald-900/10' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="radio" v-model="datiSopravvenienza.autorizzazione" value="assemblea" class="mt-1 w-4 h-4 text-emerald-600 focus:ring-emerald-500" />
                            <div class="w-full">
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">Delibera Assembleare</p>
                                <Input v-if="datiSopravvenienza.autorizzazione === 'assemblea'" type="date" v-model="datiSopravvenienza.data_assemblea" class="h-8 text-xs w-full mt-2" />
                            </div>
                        </label>
                    </div>

                    <div class="space-y-1.5 border-t border-slate-100 dark:border-slate-800 pt-4">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Regola di Ripartizione *</Label>
                        <v-select
                            :options="tabelle"
                            label="nome"
                            v-model="datiSopravvenienza.tabella_millesimale_id"
                            placeholder="Seleziona tabella millesimale..."
                            :reduce="(t: any) => t.id"
                            @open="onDropdownTabelleOpen"
                            :loading="isLoadingTabelle"
                            class="style-chooser text-sm"
                        />
                    </div>
                    
                    <div class="grid grid-cols-3 gap-3 bg-slate-50 dark:bg-slate-800/50 p-3 rounded-xl border border-slate-100 dark:border-slate-700">
                        <div>
                            <Label class="text-[10px] text-slate-500 font-bold uppercase block mb-1">Proprietario %</Label>
                            <Input type="number" v-model="datiSopravvenienza.percentuale_proprietario" class="h-8 text-sm" />
                        </div>
                        <div>
                            <Label class="text-[10px] text-slate-500 font-bold uppercase block mb-1">Inquilino %</Label>
                            <Input type="number" v-model="datiSopravvenienza.percentuale_inquilino" class="h-8 text-sm" />
                        </div>
                        <div>
                            <Label class="text-[10px] text-slate-500 font-bold uppercase block mb-1">Usufruttuario %</Label>
                            <Input type="number" v-model="datiSopravvenienza.percentuale_usufruttuario" class="h-8 text-sm" />
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Note (Opzionale)</Label>
                        <textarea v-model="datiSopravvenienza.note" rows="2" class="w-full border border-slate-200 dark:border-slate-700 rounded-xl p-3 text-sm bg-slate-50 dark:bg-slate-800 outline-none resize-none focus:ring-2 focus:border-slate-400 transition-all" />
                    </div>

                    <div class="flex gap-3 pt-2">
                        <Button variant="outline" class="flex-1 h-11 rounded-xl font-bold" @click="handleCancel">Annulla</Button>
                        <Button class="flex-1 h-11 rounded-xl text-white font-black transition-colors" 
                                :class="mode === 'corrente' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600'"
                                :disabled="datiSopravvenienza.nome_voce.length < 5 || !datiSopravvenienza.tabella_millesimale_id" 
                                @click="handleConfirm">
                            Crea e Continua
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>