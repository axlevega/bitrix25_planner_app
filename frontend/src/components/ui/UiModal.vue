<script setup>
defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: '' },
})
defineEmits(['close'])
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="ui-modal-overlay" @click.self="$emit('close')">
      <div class="ui-modal-panel">
        <h3 v-if="title" class="ui-modal__title">{{ title }}</h3>
        <div class="ui-modal__body">
          <slot />
        </div>
        <div v-if="$slots.actions" class="ui-modal__actions">
          <slot name="actions" />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style lang="scss" scoped>
.ui-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 1rem;
}
.ui-modal-panel {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
  max-width: 90vw;
  max-height: 90vh;
  overflow: auto;
  padding: 1.25rem;
}
.ui-modal__title {
  margin: 0 0 1rem;
  font-size: 1.2rem;
}
.ui-modal__body {
  margin-bottom: 1rem;
}
.ui-modal__actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  padding-top: 0.75rem;
  border-top: 1px solid #e2e8f0;
}
</style>
