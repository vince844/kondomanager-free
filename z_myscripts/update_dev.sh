#!/bin/bash

# ==================================================
# KondoManager – DEV Helper Script (Docker-safe)
# ==================================================

set -e

# ==================================================
# VERIFICAÇÕES INICIAIS
# ==================================================

command -v docker >/dev/null 2>&1 || {
  echo "❌ Docker não encontrado. Aborta."
  exit 1
}

# ==================================================
# CONFIGURAÇÃO GERAL
# ==================================================

# Containers
APP_CONTAINER_DEV="kondomanager_dev_app"
MYSQL_CONTAINER_DEV="kondomanager_dev_db"
MYSQL_CONTAINER_PROD="kondomanager_db"

# Bases de dados
DB_DEV="kondomanager_dev"
DB_PROD="kondomanager"

# Utilizadores / passwords MySQL
DB_USER_DEV="kondomanager"
DB_USER_PROD="kondomanager"
MYSQL_PASSWORD_DEV="kondomanager"
MYSQL_PASSWORD_PROD="kondomanager"

# Backups
BACKUP_DIR="db_backups"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")

mkdir -p "$BACKUP_DIR"

# ==================================================
# FUNÇÕES AUXILIARES
# ==================================================

exec_app_dev() {
  docker exec -it "$APP_CONTAINER_DEV" "$@"
}

# ==================================================
# FUNÇÕES BASE DE DADOS
# ==================================================

backup_dev_db() {
  echo "▶ A criar backup da base DEV ($DB_DEV)..."

  BACKUP_FILE="$BACKUP_DIR/dev_backup_$TIMESTAMP.sql"

  docker exec "$MYSQL_CONTAINER_DEV" \
    mysqldump -u"$DB_USER_DEV" -p"$MYSQL_PASSWORD_DEV" "$DB_DEV" \
    > "$BACKUP_FILE"

  echo "✅ Backup DEV criado:"
  echo "   $BACKUP_FILE"
}

dump_prod_db() {
  echo "▶ A criar dump da base PRODUÇÃO ($DB_PROD)..."

  PROD_DUMP_FILE="$BACKUP_DIR/prod_dump_$TIMESTAMP.sql"

  docker exec "$MYSQL_CONTAINER_PROD" \
    mysqldump -u"$DB_USER_PROD" -p"$MYSQL_PASSWORD_PROD" "$DB_PROD" \
    > "$PROD_DUMP_FILE"

  echo "✅ Dump PRODUÇÃO criado:"
  echo "   $PROD_DUMP_FILE"

  echo "$PROD_DUMP_FILE"
}

restore_dev_db() {
  DUMP_FILE="$1"

  if [[ ! -f "$DUMP_FILE" ]]; then
    echo "❌ Dump não encontrado: $DUMP_FILE"
    exit 1
  fi

  echo "⚠️  Esta operação irá APAGAR os dados atuais de DEV ($DB_DEV)"
  read -p "Confirmar restauro? (s/N): " CONFIRM
  [[ "$CONFIRM" != "s" && "$CONFIRM" != "S" ]] && exit 0

  backup_dev_db

  echo "▶ A restaurar dump em DEV..."
  docker exec -i "$MYSQL_CONTAINER_DEV" \
    mysql -u"$DB_USER_DEV" -p"$MYSQL_PASSWORD_DEV" "$DB_DEV" \
    < "$DUMP_FILE"

  echo "✅ Restauro DEV concluído."
}

# ==================================================
# MENU
# ==================================================

clear
echo "================================================"
echo "  KondoManager – DEV Helper (Docker)"
echo "================================================"
echo ""
echo "Escolhe a ação a executar:"
echo ""
echo "1) Build Frontend (Vite – DEV build)"
echo "2) Iniciar Frontend DEV (Vite dev server)"
echo "3) Limpar cache Laravel (DEV)"
echo "4) Frontend + Backend (build completo DEV)"
echo "5) Reiniciar container APP DEV"
echo "6) Build + Cache Clear + Restart (DEV)"
echo "7) Backup base de dados DEV"
echo "8) Restaurar dump em DEV (com backup automático)"
echo "9) Dump PRODUÇÃO → Restaurar DEV (com backup automático)"
echo "0) Sair"
echo ""
read -p "Opção: " OPTION

echo ""

case $OPTION in
  1)
    echo "▶ Build frontend (Vite – DEV)"
    exec_app_dev npm run build
    ;;

  2)
    echo "▶ Iniciar Vite DEV (hot reload)"
    echo "ℹ️  Ctrl+C para parar"
    exec_app_dev npm run dev
    ;;

  3)
    echo "▶ Limpar cache Laravel (DEV)..."
    exec_app_dev php artisan optimize:clear
    ;;

  4)
    echo "▶ Build frontend (DEV)..."
    exec_app_dev npm run build
    echo ""
    echo "▶ Limpar cache Laravel (DEV)..."
    exec_app_dev php artisan optimize:clear
    ;;

  5)
    echo "▶ Reiniciar container APP DEV..."
    docker restart "$APP_CONTAINER_DEV"
    ;;

  6)
    echo "▶ Build frontend (DEV)..."
    exec_app_dev npm run build
    echo ""
    echo "▶ Limpar cache Laravel (DEV)..."
    exec_app_dev php artisan optimize:clear
    echo ""
    echo "▶ Reiniciar container APP DEV..."
    docker restart "$APP_CONTAINER_DEV"
    ;;

  7)
    backup_dev_db
    ;;

  8)
    read -p "✍️  Caminho para o dump a restaurar: " DUMP_PATH
    restore_dev_db "$DUMP_PATH"
    ;;

  9)
    echo "⚠️  Dump PRODUÇÃO → DEV (operação completa)"
    read -p "Confirmar? (s/N): " CONFIRM
    [[ "$CONFIRM" != "s" && "$CONFIRM" != "S" ]] && exit 0

    PROD_DUMP=$(dump_prod_db)
    restore_dev_db "$PROD_DUMP"
    ;;

  0)
    echo "A sair."
    exit 0
    ;;

  *)
    echo "❌ Opção inválida."
    ;;
esac

echo ""
echo "✅ Operação concluída."
echo "💡 Se algo não atualizar no browser: Ctrl + F5"
echo ""
