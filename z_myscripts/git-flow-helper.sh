#!/bin/bash

# -----------------------------
# Git Flow Helper
# -----------------------------

set -e

MAIN_BRANCH="main"
VALID_BRANCH_REGEX="^(feature|fix|hotfix|release|chore)\/[a-z0-9\-\.]+$"

clear

echo "----------------------------------------"
echo " Git Flow Helper"
echo "----------------------------------------"

# 1. Validar repositório Git
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Erro: isto não é um repositório Git."
  exit 1
fi

# 2. Branch atual
CURRENT_BRANCH=$(git branch --show-current)
echo "Branch atual: $CURRENT_BRANCH"

# 3. Bloquear main
if [ "$CURRENT_BRANCH" = "$MAIN_BRANCH" ]; then
  echo ""
  echo "❌ Commits diretos no '$MAIN_BRANCH' não são permitidos."
  echo "Cria um branch antes de continuar."
  exit 1
fi

# 4. Validar naming do branch (com opção de criação automática)
if [[ ! "$CURRENT_BRANCH" =~ $VALID_BRANCH_REGEX ]]; then
  echo ""
  echo "⚠️  Naming de branch inválido."
  echo ""
  echo "Convenção suportada:"
  echo "  feature/descricao-curta"
  echo "  fix/descricao-curta"
  echo "  hotfix/descricao-curta"
  echo "  release/vX.Y.Z"
  echo "  chore/tarefa-tecnica"
  echo ""
  echo "Branch atual: $CURRENT_BRANCH"
  echo ""

  read -p "Queres criar agora um novo branch com a nomenclatura correta? (y/n): " CREATE_BRANCH

  if [ "$CREATE_BRANCH" != "y" ]; then
    echo "Operação abortada."
    exit 1
  fi

  echo ""
  echo "Tipo de branch:"
  echo "1) feature"
  echo "2) fix"
  echo "3) hotfix"
  echo "4) release"
  echo "5) chore"
  read -p "Escolhe uma opção (1-5): " BRANCH_TYPE_OPTION

  case $BRANCH_TYPE_OPTION in
    1) BRANCH_TYPE="feature" ;;
    2) BRANCH_TYPE="fix" ;;
    3) BRANCH_TYPE="hotfix" ;;
    4) BRANCH_TYPE="release" ;;
    5) BRANCH_TYPE="chore" ;;
    *) echo "Opção inválida."; exit 1 ;;
  esac

  read -p "Descrição curta do branch (ex: docker-support): " BRANCH_DESC

  if [ -z "$BRANCH_DESC" ]; then
    echo "Descrição do branch não pode estar vazia."
    exit 1
  fi

  NEW_BRANCH="$BRANCH_TYPE/$BRANCH_DESC"

  echo ""
  echo "A criar e mudar para o branch: $NEW_BRANCH"
  git checkout -b "$NEW_BRANCH"

  CURRENT_BRANCH="$NEW_BRANCH"
fi

# 5. Verificar alterações não commitadas
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo ""
  echo "⚠️  Existem alterações não commitadas:"
  git status --short
  echo ""
  echo "O que pretendes fazer?"
  echo "1) Fazer stash das alterações"
  echo "2) Continuar para commit agora"
  echo "3) Abortar"
  read -p "Escolhe uma opção (1-3): " DIRTY_OPTION

  case $DIRTY_OPTION in
    1)
      git stash push -m "auto-stash before rebase"
      STASHED=true
      ;;
    2)
      STASHED=false
      ;;
    3)
      echo "Operação abortada."
      exit 0
      ;;
    *)
      echo "Opção inválida."
      exit 1
      ;;
  esac
fi

# 6. Rebase com main
echo ""
echo "A sincronizar com $MAIN_BRANCH..."
git fetch origin
git rebase origin/$MAIN_BRANCH

# 7. Reaplicar stash (se existir)
if [ "${STASHED:-false}" = true ]; then
  echo ""
  echo "A reaplicar stash..."
  git stash pop
fi

# 8. Verificar se há alterações para commit
if git diff --quiet && git diff --cached --quiet; then
  echo ""
  echo "Nenhuma alteração para commit."
  exit 0
fi

git status

# 9. Staging
read -p "Adicionar todas as alterações ao commit? (y/n): " ADD_ALL
if [ "$ADD_ALL" = "y" ]; then
  git add .
else
  echo "Adiciona os ficheiros manualmente e volta a correr o script."
  exit 0
fi

# 10. Tipo de commit
echo ""
echo "Tipo de commit:"
echo "1) feat"
echo "2) fix"
echo "3) refactor"
echo "4) docs"
echo "5) test"
echo "6) chore"
read -p "Escolhe uma opção (1-6): " TYPE_OPTION

case $TYPE_OPTION in
  1) TYPE="feat" ;;
  2) TYPE="fix" ;;
  3) TYPE="refactor" ;;
  4) TYPE="docs" ;;
  5) TYPE="test" ;;
  6) TYPE="chore" ;;
  *) echo "Opção inválida."; exit 1 ;;
esac

# 11. Mensagem de commit
read -p "Descrição curta do commit (em inglês): " MESSAGE

if [ -z "$MESSAGE" ]; then
  echo "Mensagem de commit não pode estar vazia."
  exit 1
fi

COMMIT_MSG="$TYPE: $MESSAGE"

git commit -m "$COMMIT_MSG"

# 12. Push
read -p "Fazer push para o remoto agora? (y/n): " DO_PUSH
if [ "$DO_PUSH" = "y" ]; then
  git push origin "$CURRENT_BRANCH"
  echo "Push concluído."
else
  echo "Push ignorado."
fi

echo ""
echo "----------------------------------------"
echo "Fluxo Git concluído com sucesso."
echo "----------------------------------------"
