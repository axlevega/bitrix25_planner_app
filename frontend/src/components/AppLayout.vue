<script setup>
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="layout">
    <nav class="nav">
      <div class="nav__main">
        <RouterLink to="/" class="nav__link">Дашборд</RouterLink>
        <RouterLink to="/planning-grid" class="nav__link">Планирование</RouterLink>
        <RouterLink to="/dashboard" class="nav__link">Загрузка</RouterLink>
      </div>
      <RouterLink to="/settings" class="nav__link nav__link--settings">Настройки</RouterLink>
    </nav>
    <main class="main">
      <router-view />
    </main>
  </div>
</template>

<style lang="scss" scoped>
.layout {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}
.nav {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  flex-wrap: wrap;
}
.nav__main {
  display: flex;
  gap: 0.5rem;
  margin-right: auto;
}
.nav__link {
  color: var(--color-primary);
  text-decoration: none;
  padding: 0.35rem 0.6rem;
  border-radius: 4px;
  font-weight: 500;
  &:hover {
    background: #edf2f7;
  }
  &.router-link-active {
    background: var(--color-primary);
    color: #fff;
  }
}
.nav__link--settings {
  margin-left: 0.5rem;
  border-left: 1px solid #e2e8f0;
  padding-left: 0.75rem;
}
.main {
  flex: 1;
  padding: 1rem;
  max-width: var(--max-width);
  margin: 0 auto;
  width: 100%;
}
</style>
