#!/bin/bash

UPSTREAM_URL="https://github.com/vince844/kondomanager-free.git"

set -e

# ============================
# Funções
# ============================

check_git_repo() {
  if ! git rev-parse --is-inside-work-tree > /dev/null 2>&1; then
    echo "❌ Este diretório não é um repositório Git."
    exit 1
  fi
}

ensure_upstream() {
  if ! git remote | grep -q upstream; then
    echo "➕ A adicionar remote upstream..."
    git remote add upstream "$UPSTREAM_URL"
  else
    echo "✔ Remote upstream já existe."
  fi
}

sync_main() {
  echo "🔄 A sincronizar main com upstream..."
  git checkout main
  git fetch upstream
  git pull upstream main
  git push origin main
  echo "✅ main sincronizado."
}

create_branch() {
  read -p "✍️  Nome do novo branch (ex: feature/luis-custom): " BRANCH_NAME

  if [[ -z "$BRANCH_NAME" ]]; then
    echo "❌ Nome do branch não pode estar vazio."
    return
  fi

  echo "🌱 A criar branch '$BRANCH_NAME' a partir de main..."
  git checkout -b "$BRANCH_NAME"
  echo "✅ Branch criado e ativo: $BRANCH_NAME"
}

merge_upstream_branch() {
  ensure_upstream

  CURRENT_BRANCH=$(git branch --show-current)

  if [[ -z "$CURRENT_BRANCH" ]]; then
    echo "❌ Não foi possível identificar o branch atual."
    exit 1
  fi

  read -p "🔀 Nome do branch do upstream a integrar (ex: feature/add_portuguese_translations): " UPSTREAM_BRANCH

  if [[ -z "$UPSTREAM_BRANCH" ]]; then
    echo "❌ Nome do branch não pode estar vazio."
    return
  fi

  echo "🔄 A atualizar referências do upstream..."
  git fetch upstream

  echo "📍 Branch atual: $CURRENT_BRANCH"
  git checkout "$CURRENT_BRANCH"

  echo "🔀 A fazer merge de upstream/$UPSTREAM_BRANCH..."
  git merge "upstream/$UPSTREAM_BRANCH" || {
    echo "⚠️ Conflitos detetados."
    echo "Resolve os conflitos e depois executa:"
    echo "   git add ."
    echo "   git commit"
    exit 1
  }

  echo "✅ Merge concluído com sucesso."
}

full_workflow() {
  ensure_upstream
  sync_main
  create_branch
}

# ============================
# Menu
# ============================

check_git_repo

while true; do
  echo
  echo "=============================="
  echo " Git Workflow Manager"
  echo "=============================="
  echo "1) Garantir remote upstream"
  echo "2) Sincronizar main com upstream"
  echo "3) Criar novo branch de trabalho"
  echo "4) Executar workflow completo"
  echo "5) Integrar branch do upstream no branch atual"
  echo "0) Sair"
  echo
  read -p "👉 Escolhe uma opção: " OPTION

  case $OPTION in
    1)
      ensure_upstream
      ;;
    2)
      sync_main
      ;;
    3)
      create_branch
      ;;
    4)
      full_workflow
      ;;
    5)
      merge_upstream_branch
      ;;
    0)
      echo "👋 A sair."
      exit 0
      ;;
    *)
      echo "❌ Opção inválida."
      ;;
  esac
done
