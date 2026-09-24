#!/usr/bin/env bash
# =============================================================================
# mercator.sh — Gestion du stack Docker Mercator
# Usage : ./mercator.sh {start|stop|restart|status|logs|url}
# =============================================================================

set -euo pipefail

COMPOSE_FILE="$(dirname "$(realpath "$0")")/docker-compose.mercator.yml"
PROJECT_NAME="mercator"

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------

check_compose_file() {
    if [[ ! -f "$COMPOSE_FILE" ]]; then
        echo -e "${RED}[ERROR]${NC} Fichier introuvable : $COMPOSE_FILE"
        exit 1
    fi
}

compose() {
    docker compose -f "$COMPOSE_FILE" -p "$PROJECT_NAME" "$@"
}

print_header() {
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}  Mercator Docker Manager${NC}"
    echo -e "${CYAN}========================================${NC}"
}

# -----------------------------------------------------------------------------
# Commandes
# -----------------------------------------------------------------------------

cmd_start() {
    check_compose_file
    print_header
    echo -e "${YELLOW}» Démarrage du stack Mercator...${NC}"
    compose up -d --remove-orphans
    echo ""
    cmd_status
    echo ""
    cmd_url
}

cmd_stop() {
    check_compose_file
    print_header
    echo -e "${YELLOW}» Arrêt du stack Mercator...${NC}"
    # Utilise "stop" (et non "down") pour conserver les conteneurs tels quels ;
    # les données (base MariaDB, documents) sont de toute façon dans des volumes.
    compose stop
    echo -e "${GREEN}✔ Stack arrêté.${NC}"
}

cmd_restart() {
    cmd_stop
    echo ""
    cmd_start
}

cmd_down() {
    check_compose_file
    print_header
    echo -e "${YELLOW}» Suppression des conteneurs Mercator (les volumes sont conservés)...${NC}"
    compose down
    echo -e "${GREEN}✔ Conteneurs supprimés.${NC}"
}

cmd_update() {
    check_compose_file
    print_header
    echo -e "${YELLOW}» Mise à jour de Mercator (dernière image Docker)...${NC}"
    echo -e "${YELLOW}  Les conteneurs vont être recréés ; les migrations de base de${NC}"
    echo -e "${YELLOW}  données sont appliquées automatiquement au démarrage.${NC}"
    compose pull
    compose down
    compose up -d --remove-orphans --force-recreate
    echo ""
    cmd_status
    echo ""
    cmd_url
}

cmd_status() {
    check_compose_file
    echo -e "${CYAN}--- Conteneurs ---${NC}"

    local all_up=true

    # Délimiteur | pour éviter les ambiguïtés avec les espaces dans "Up 11 seconds"
    while IFS='|' read -r name state health; do
        # Normaliser : retirer les espaces en bordure
        name=$(echo "$name"   | xargs)
        state=$(echo "$state" | xargs)
        health=$(echo "$health" | xargs)

        # Icône selon state (docker compose : "running" / "exited" / "created"...)
        if [[ "$state" == "running" ]]; then
            case "$health" in
                healthy)   icon="${GREEN}●${NC}" ;;
                starting)  icon="${YELLOW}◐${NC}" ;;
                unhealthy) icon="${RED}●${NC}"; all_up=false ;;
                *)         icon="${GREEN}●${NC}" ;;  # pas de healthcheck défini = ok
            esac
        else
            icon="${RED}●${NC}"
            all_up=false
        fi

        health_str=""
        [[ -n "$health" ]] && health_str=" (${health})"

        printf "  %b  %-30s %s%s\n" "$icon" "$name" "$state" "$health_str"

    done < <(compose ps --format "{{.Name}}|{{.State}}|{{.Health}}" 2>/dev/null || true)

    echo ""
    if $all_up; then
        echo -e "  ${GREEN}✔ Stack opérationnel${NC}"
    else
        echo -e "  ${RED}✘ Un ou plusieurs conteneurs sont en erreur${NC}"
    fi
}

cmd_logs() {
    check_compose_file
    local service="${2:-}"
    if [[ -n "$service" ]]; then
        compose logs -f --tail=100 "$service"
    else
        compose logs -f --tail=100
    fi
}

cmd_url() {
    # Lire le port exposé depuis le compose file (port hôte de Mercator)
    local port
    port=$(grep -A2 'ports:' "$COMPOSE_FILE" \
        | grep -oP '(?<=- ")[0-9]+(?=:8080")' \
        | head -1 2>/dev/null || echo "")

    # Fallback si pas trouvé par grep (format sans guillemets)
    if [[ -z "$port" ]]; then
        port=$(grep -A2 'ports:' "$COMPOSE_FILE" \
            | grep -oP '[0-9]+(?=:8080)' \
            | head -1 2>/dev/null || echo "8000")
    fi

    echo -e "${CYAN}--- Accès ---${NC}"
    echo -e "  URL      : ${GREEN}http://localhost:${port}${NC}"
    echo -e "  API REST : ${GREEN}http://localhost:${port}/api${NC}"
    echo -e "  Login    : ${GREEN}admin@admin.com / password${NC} (données de démo)"
}

cmd_help() {
    print_header
    echo ""
    echo "Usage : $0 <commande> [options]"
    echo ""
    echo -e "Commandes disponibles :"
    echo -e "  ${GREEN}start${NC}           Démarrer le stack Mercator"
    echo -e "  ${GREEN}stop${NC}            Arrêter le stack Mercator (conteneurs conservés)"
    echo -e "  ${GREEN}restart${NC}         Redémarrer le stack Mercator"
    echo -e "  ${GREEN}status${NC}          Voir l'état des conteneurs"
    echo -e "  ${GREEN}logs${NC} [service]  Suivre les logs (optionnel : mercator | mercator-db)"
    echo -e "  ${GREEN}url${NC}             Afficher les URLs d'accès"
    echo -e "  ${GREEN}down${NC}            Supprimer les conteneurs (volumes conservés)"
    echo -e "  ${GREEN}update${NC}          Mettre à jour Mercator (tire la dernière image et recrée les conteneurs)"
    echo ""
    echo "Fichier compose : $COMPOSE_FILE"
}

# -----------------------------------------------------------------------------
# Dispatcher
# -----------------------------------------------------------------------------

COMMAND="${1:-help}"

case "$COMMAND" in
    start)   cmd_start ;;
    stop)    cmd_stop ;;
    restart) cmd_restart ;;
    status)  print_header; cmd_status ;;
    logs)    cmd_logs "$@" ;;
    url)     cmd_url ;;
    down)    cmd_down ;;
    update)  cmd_update ;;
    help|--help|-h) cmd_help ;;
    *)
        echo -e "${RED}[ERROR]${NC} Commande inconnue : '$COMMAND'"
        echo ""
        cmd_help
        exit 1
        ;;
esac