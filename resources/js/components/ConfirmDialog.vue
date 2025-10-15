<!-- components/ConfirmDialog.vue -->
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

defineProps({
  modelValue: {
    type: Boolean,
    required: true
  },
  title: {
    type: String,
    required: true
  },
  description: {
    type: String,
    required: true
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
    default: 'Eliminazione...'
  }
})

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel'])

const confirm = () => {
  emit('confirm')
}

const cancel = () => {
  emit('cancel')
  emit('update:modelValue', false)
}

// Handle the open state change from the AlertDialog
const onOpenChange = (open) => {
  emit('update:modelValue', open)
}
</script>

<template>
  <AlertDialog :open="modelValue" @update:open="onOpenChange">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>{{ title }}</AlertDialogTitle>
        <AlertDialogDescription>
          {{ description }}
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click="cancel">{{ cancelText }}</AlertDialogCancel>
        <AlertDialogAction :disabled="loading" @click="confirm">
          <span v-if="loading">{{ loadingText }}</span>
          <span v-else>{{ confirmText }}</span>
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>