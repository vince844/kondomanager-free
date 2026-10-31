<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({
    actionUrl: String, // http://tuosito.com/index.php
    token: String,     // Token di sicurezza
    version: String
});

const launchForm = ref(null);

onMounted(() => {
    // Submit automatico dopo breve delay per UX
    setTimeout(() => {
        if (launchForm.value) {
            launchForm.value.submit();
        }
    }, 1500);
});
</script>

<template>
    <div class="min-h-screen flex flex-col items-center justify-center bg-slate-900 text-white p-4">
        <div class="w-full max-w-md text-center space-y-8">
            
            <div class="relative w-24 h-24 mx-auto">
                <div class="absolute inset-0 border-4 border-slate-700 rounded-full"></div>
                <div class="absolute inset-0 border-4 border-blue-500 rounded-full border-t-transparent animate-spin"></div>
                <div class="absolute inset-0 flex items-center justify-center">
                    <span class="text-2xl">🚀</span>
                </div>
            </div>

            <div class="space-y-2">
                <h2 class="text-2xl font-bold">Avvio Aggiornamento v{{ version }}</h2>
                <p class="text-slate-400">
                    Stiamo trasferendo il controllo all'installer sicuro.<br>
                    L'aggiornamento richiede qualche minuto: non chiudere la pagina.
                </p>
            </div>

            <form ref="launchForm" :action="actionUrl" method="POST" class="hidden">
                <input type="hidden" name="token" :value="token">
                <input type="hidden" name="mode" value="update">
            </form>

        </div>
    </div>
</template>