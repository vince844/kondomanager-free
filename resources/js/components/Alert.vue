<script setup lang="ts">
import { Alert, AlertDescription } from '@/components/ui/alert'
import { usePage} from "@inertiajs/vue3";
import { ref, onMounted } from 'vue';
import type { Flash } from '@/types/flash';

defineProps<Flash>()

const showNotification = ref(false); 

const hideFlash = () => {
  showNotification.value = false
	usePage().props.flash = { message: "" }; 
};

onMounted(() => {
  showNotification.value = true;
  setTimeout(() => hideFlash(), 3000);

});

</script>

<template>
  <Alert 
    v-if="showNotification" 
    :class="{'bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative': type === 'success',
            'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative': type === 'error',
            'bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative': type === 'warning',
            'bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative': type === 'info' 
            }"
    >
    <AlertDescription class="font-medium">
      {{ message }}
    </AlertDescription>
  </Alert>
</template>