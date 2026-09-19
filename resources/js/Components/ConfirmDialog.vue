<script setup>
import Modal from './Modal.vue'

defineProps({
  show: Boolean,
  title: { type: String, default: 'Are you sure?' },
  message: String,
  confirmText: { type: String, default: 'Delete' },
  processing: Boolean,
})
const emit = defineEmits(['close', 'confirm'])
</script>

<template>
  <Modal :show="show" :title="title" max-width="max-w-md" @close="emit('close')">
    <p class="text-sm text-slate-600 dark:text-slate-300">{{ message }}</p>

    <template #footer>
      <button class="btn-ghost flex-1 sm:flex-none" @click="emit('close')">Cancel</button>
      <button class="btn-danger flex-1 sm:flex-none" :disabled="processing" @click="emit('confirm')">
        {{ processing ? 'Working…' : confirmText }}
      </button>
    </template>
  </Modal>
</template>
