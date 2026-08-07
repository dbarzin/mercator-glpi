#!/usr/bin/env bash
# =============================================================================
# glpi.sh — Gestion du stack Docker GLPI
# Usage : ./glpi.sh {start|stop|restart|status|logs|url}
# =============================================================================

set -euo pipefail

COMPOSE_FILE="$(dirname "$(realpath "$0")")/docker-compose.glpi.yml"
PROJECT_NAME="glpi"

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
    echo -e "${CYAN}  GLPI Docker Manager${NC}"
    echo -e "${CYAN}========================================${NC}"
}

# -----------------------------------------------------------------------------
# Commandes
# -----------------------------------------------------------------------------

cmd_start() {
    check_compose_file
    print_header
    echo -e "${YELLOW}» Démarrage du stack GLPI...${NC}"
    compose up -d --remove-orphans
    echo ""
    cmd_status
    echo ""
    cmd_url
}

cmd_stop() {
    check_compose_file
    print_header
    echo -e "${YELLOW}» Arrêt du stack GLPI...${NC}"
    # Utilise "stop" (et non "down") pour préserver les conteneurs : le code
    # GLPI vit dans /var/www/html/glpi, hors des volumes persistants
    # (files/plugins/config). Un "down" le détruit et force un re-téléchargement
    # de la dernière version de GLPI au prochain démarrage, ce qui peut déclencher
    # une mise à jour non désirée et invalider la clé API. Voir `update`.
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
    echo -e "${YELLOW}» Suppression des conteneurs GLPI (les volumes sont conservés)...${NC}"
    compose down
    echo -e "${GREEN}✔ Conteneurs supprimés.${NC}"
}

cmd_update() {
    check_compose_file
    print_header
    echo -e "${YELLOW}» Mise à jour de GLPI (code + éventuellement image Docker)...${NC}"
    echo -e "${YELLOW}  Cela va détruire puis recréer les conteneurs : la dernière${NC}"
    echo -e "${YELLOW}  version de GLPI sera re-téléchargée. Une mise à jour de base${NC}"
    echo -e "${YELLOW}  de données peut être requise via l'interface web, et la clé${NC}"
    echo -e "${YELLOW}  API pourrait devoir être régénérée.${NC}"
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
    # Lire le port exposé depuis le compose file (port hôte de GLPI)
    local port
    port=$(grep -A2 'ports:' "$COMPOSE_FILE" \
        | grep -oP '(?<=- ")[0-9]+(?=:80")' \
        | head -1 2>/dev/null || echo "")

    # Fallback si pas trouvé par grep (format sans guillemets)
    if [[ -z "$port" ]]; then
        port=$(grep -A2 'ports:' "$COMPOSE_FILE" \
            | grep -oP '[0-9]+(?=:80)' \
            | head -1 2>/dev/null || echo "8080")
    fi

    echo -e "${CYAN}--- Accès ---${NC}"
    echo -e "  URL      : ${GREEN}http://localhost:${port}${NC}"
    echo -e "  API REST : ${GREEN}http://localhost:${port}/apirest.php${NC}"
    echo -e "  API Doc  : ${GREEN}http://localhost:${port}/apirest.php/initSession${NC}"
}

cmd_help() {
    print_header
    echo ""
    echo "Usage : $0 <commande> [options]"
    echo ""
    echo -e "Commandes disponibles :"
    echo -e "  ${GREEN}start${NC}           Démarrer le stack GLPI"
    echo -e "  ${GREEN}stop${NC}            Arrêter le stack GLPI (conteneurs conservés)"
    echo -e "  ${GREEN}restart${NC}         Redémarrer le stack GLPI"
    echo -e "  ${GREEN}status${NC}          Voir l'état des conteneurs"
    echo -e "  ${GREEN}logs${NC} [service]  Suivre les logs (optionnel : glpi | db)"
    echo -e "  ${GREEN}url${NC}             Afficher les URLs d'accès"
    echo -e "  ${GREEN}down${NC}            Supprimer les conteneurs (volumes conservés)"
    echo -e "  ${GREEN}update${NC}          Forcer la mise à jour de GLPI (re-télécharge la dernière version)"
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