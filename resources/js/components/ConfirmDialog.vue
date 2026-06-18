<script setup>
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { cn } from '@/lib/utils'

const props = defineProps({
  modelValue: {
    type: Boolean,
    required: true
  },
  title: {
    type: String,
    required: true
  },
  // [MODIFICA 1] Tolto 'required: true'. Default stringa vuota.
  // Serve per supportare l'uso dello slot senza passare questa prop.
  description: {
    type: String,
    default: ''
  },
  confirmText: {
    type: String,
    default: 'Continua'
  },
  cancelText: {
    type: String,
    default: 'Annulla'
  },
  loading: {
    type: Boolean,
    default: false
  },
  loadingText: {
    type: String,
    default: 'Attendi...'
  },
  // [NUOVA PROP] Blocca il tasto conferma senza mostrare spinner (es. selezione vuota)
  disabled: {
    type: Boolean,
    default: false
  },
  // [NUOVA PROP] Gestione stili: 'default' (Nero), 'destructive' (Rosso), 'warning' (Ambra)
  variant: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'destructive', 'warning'].includes(value)
  },
  // [NUOVA PROP] Larghezza modale: 'sm' (max-w-sm), 'md' (max-w-lg, default), 'lg' (max-w-2xl), 'xl' (max-w-3xl)
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value)
  }
})

const sizeClass = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-3xl',
}

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel'])

const confirm = () => {
  emit('confirm')
}

const cancel = () => {
  emit('cancel')
  emit('update:modelValue', false)
}

const onOpenChange = (open) => {
  emit('update:modelValue', open)
}
</script>

<template>
  <AlertDialog :open="modelValue" @update:open="onOpenChange">
    <AlertDialogContent :class="cn(sizeClass[size])">
      <AlertDialogHeader>
        <AlertDialogTitle :class="{
            'text-red-600': variant === 'destructive',
            'text-amber-600': variant === 'warning'
        }">
            {{ title }}
        </AlertDialogTitle>
        
        <AlertDialogDescription class="text-sm text-slate-600">
          <slot>
            {{ description }}
          </slot>
        </AlertDialogDescription>
      </AlertDialogHeader>
      
      <AlertDialogFooter>
        <AlertDialogCancel @click="cancel">{{ cancelText }}</AlertDialogCancel>
        
        <AlertDialogAction 
            :disabled="loading || disabled" 
            @click="confirm"
            :class="{
                'bg-red-600 hover:bg-red-700 focus:ring-red-600': variant === 'destructive',
                'bg-amber-600 hover:bg-amber-700 focus:ring-amber-600': variant === 'warning',
                // [FIX COLORE] Se default, non mettiamo classi background. 
                // Così usa lo stile base del componente (Nero/Primary).
                '': variant === 'default'
            }"
        >
          <span v-if="loading" class="flex items-center gap-2">
             <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
             </svg>
             {{ loadingText }}
          </span>
          <span v-else>{{ confirmText }}</span>
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>