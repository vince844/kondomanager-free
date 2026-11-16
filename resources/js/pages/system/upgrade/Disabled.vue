<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    reason: String,
    message: String
});
</script>

<template>
    <Head title="Aggiornamenti automatici" />
    
    <div class="max-w-2xl mx-auto py-12 px-4">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-gray-100 mb-4">
                    <svg class="h-8 w-8 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                
                <h2 class="text-2xl font-bold text-gray-900 mb-2">
                    Aggiornamenti automatici non disponibili
                </h2>
                
                <p class="text-gray-600 mb-6">
                    {{ message }}
                </p>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-left">
                    <h3 class="font-semibold text-blue-900 mb-2">
                        Procedura di aggiornamento manuale
                    </h3>
                    <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                        <li>Scarica il file <strong>index.php</strong> dal sito ufficiale di Kondomanager.</li>
                        <li>Caricalo nella cartella principale del sito (via FTP o File Manager).</li>
                        <li>Visita: <code class="bg-blue-100 px-1 rounded">tuodominio.com/index.php</code></li>
                        <li>Il sistema completerà l'aggiornamento automaticamente.</li>
                    </ol>
                </div>
                
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <p class="text-sm text-gray-500">
                        Versione corrente: <strong class="text-gray-700">{{ $page.props.version }}</strong>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>