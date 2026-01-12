#!/bin/bash

# Worker Management Script
# Manages the job queue worker process with start, stop, restart, status, and monitor capabilities

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKER_CMD="php index.php cli/worker/run"
LOG_FILE="${SCRIPT_DIR}/logs/worker.log"
PID_FILE=""
HEARTBEAT_FILE=""
MONITOR_PID_FILE="${SCRIPT_DIR}/.worker_monitor.pid"
MONITOR_INTERVAL=5  # seconds to check if worker is alive
POLL_INTERVAL=""     # optional poll interval override

# Get PID and heartbeat file paths from config
# Default to a reasonable path if we can't parse the config
get_worker_files() {
    # Try to get storage_path from config
    if [ -f "${SCRIPT_DIR}/application/config/editor.php" ]; then
        STORAGE_PATH=$(grep "storage_path" "${SCRIPT_DIR}/application/config/editor.php" | head -1 | sed -n "s/.*['\"]\([^'\"]*\)['\"].*/\1/p")
        if [ -n "$STORAGE_PATH" ]; then
            PID_FILE="${STORAGE_PATH%/}/tmp/worker.pid"
            HEARTBEAT_FILE="${STORAGE_PATH%/}/tmp/worker.heartbeat"
        fi
    fi
    
    # Fallback to default if not found
    if [ -z "$PID_FILE" ]; then
        PID_FILE="${SCRIPT_DIR}/application/storage/tmp/worker.pid"
        HEARTBEAT_FILE="${SCRIPT_DIR}/application/storage/tmp/worker.heartbeat"
    fi
}

# Ensure log directory exists
ensure_log_dir() {
    LOG_DIR=$(dirname "$LOG_FILE")
    if [ ! -d "$LOG_DIR" ]; then
        mkdir -p "$LOG_DIR"
    fi
}

# Check if worker is running
is_worker_running() {
    if [ ! -f "$PID_FILE" ]; then
        return 1
    fi
    
    # Read PID from file
    WORKER_PID=$(cat "$PID_FILE" 2>/dev/null | sed -n 's/.*"pid"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' || echo "")
    
    if [ -z "$WORKER_PID" ]; then
        return 1
    fi
    
    # Check if process is actually running
    if ps -p "$WORKER_PID" > /dev/null 2>&1; then
        return 0
    else
        # PID file exists but process is dead, clean it up
        rm -f "$PID_FILE" "$HEARTBEAT_FILE" 2>/dev/null
        return 1
    fi
}

# Get worker status
get_worker_status() {
    if is_worker_running; then
        WORKER_PID=$(cat "$PID_FILE" 2>/dev/null | sed -n 's/.*"pid"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' || echo "")
        if [ -f "$HEARTBEAT_FILE" ]; then
            HEARTBEAT_TIME=$(cat "$HEARTBEAT_FILE" 2>/dev/null | sed -n 's/.*"timestamp"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' || echo "")
            if [ -n "$HEARTBEAT_TIME" ]; then
                CURRENT_TIME=$(date +%s)
                AGE=$((CURRENT_TIME - HEARTBEAT_TIME))
                echo "Running (PID: $WORKER_PID, Last heartbeat: ${AGE}s ago)"
            else
                echo "Running (PID: $WORKER_PID)"
            fi
        else
            echo "Running (PID: $WORKER_PID)"
        fi
    else
        echo "Stopped"
    fi
}

# Start the worker
start_worker() {
    if is_worker_running; then
        echo -e "${YELLOW}Worker is already running${NC}"
        get_worker_status
        return 1
    fi
    
    echo -e "${BLUE}Starting worker...${NC}"
    ensure_log_dir
    
    # Build command with optional poll interval
    CMD="$WORKER_CMD"
    if [ -n "$POLL_INTERVAL" ]; then
        CMD="$CMD --poll-interval=$POLL_INTERVAL"
    fi
    
    # Start worker in background and redirect output to log file
    cd "$SCRIPT_DIR"
    nohup $CMD >> "$LOG_FILE" 2>&1 &
    
    # Wait a moment to see if it starts successfully
    sleep 2
    
    if is_worker_running; then
        echo -e "${GREEN}Worker started successfully${NC}"
        get_worker_status
        echo "Logs: $LOG_FILE"
        return 0
    else
        echo -e "${RED}Failed to start worker${NC}"
        echo "Check logs: $LOG_FILE"
        return 1
    fi
}

# Stop the worker
stop_worker() {
    if ! is_worker_running; then
        echo -e "${YELLOW}Worker is not running${NC}"
        return 1
    fi
    
    WORKER_PID=$(cat "$PID_FILE" 2>/dev/null | sed -n 's/.*"pid"[[:space:]]*:[[:space:]]*\([0-9]*\).*/\1/p' || echo "")
    
    if [ -z "$WORKER_PID" ]; then
        echo -e "${YELLOW}Could not read worker PID${NC}"
        rm -f "$PID_FILE" "$HEARTBEAT_FILE" 2>/dev/null
        return 1
    fi
    
    echo -e "${BLUE}Stopping worker (PID: $WORKER_PID)...${NC}"
    
    # Try graceful shutdown with SIGTERM
    kill -TERM "$WORKER_PID" 2>/dev/null
    
    # Wait up to 10 seconds for graceful shutdown
    for i in {1..10}; do
        if ! ps -p "$WORKER_PID" > /dev/null 2>&1; then
            echo -e "${GREEN}Worker stopped gracefully${NC}"
            rm -f "$PID_FILE" "$HEARTBEAT_FILE" 2>/dev/null
            return 0
        fi
        sleep 1
    done
    
    # If still running, force kill
    if ps -p "$WORKER_PID" > /dev/null 2>&1; then
        echo -e "${YELLOW}Worker did not stop gracefully, forcing shutdown...${NC}"
        kill -KILL "$WORKER_PID" 2>/dev/null
        sleep 1
        rm -f "$PID_FILE" "$HEARTBEAT_FILE" 2>/dev/null
        echo -e "${GREEN}Worker stopped${NC}"
    fi
    
    return 0
}

# Restart the worker
restart_worker() {
    echo -e "${BLUE}Restarting worker...${NC}"
    stop_worker
    sleep 1
    start_worker
}

# Show worker status
show_status() {
    echo -e "${BLUE}Worker Status:${NC}"
    STATUS=$(get_worker_status)
    if is_worker_running; then
        echo -e "${GREEN}$STATUS${NC}"
        
        # Show additional info from PID file
        if [ -f "$PID_FILE" ]; then
            echo ""
            echo "Details from PID file:"
            cat "$PID_FILE" | sed 's/^/  /'
        fi
        
        # Show heartbeat info
        if [ -f "$HEARTBEAT_FILE" ]; then
            echo ""
            echo "Heartbeat info:"
            cat "$HEARTBEAT_FILE" | sed 's/^/  /'
        fi
    else
        echo -e "${RED}$STATUS${NC}"
    fi
    
    # Check if monitor is running
    if [ -f "$MONITOR_PID_FILE" ]; then
        MONITOR_PID=$(cat "$MONITOR_PID_FILE" 2>/dev/null)
        if [ -n "$MONITOR_PID" ] && ps -p "$MONITOR_PID" > /dev/null 2>&1; then
            echo ""
            echo -e "${GREEN}Monitor: Running (PID: $MONITOR_PID)${NC}"
        else
            rm -f "$MONITOR_PID_FILE" 2>/dev/null
        fi
    fi
}

# Monitor and auto-restart worker
monitor_worker() {
    echo -e "${BLUE}Starting worker monitor (auto-restart enabled)${NC}"
    echo "Monitor will check every $MONITOR_INTERVAL seconds"
    echo "Press Ctrl+C to stop monitoring"
    echo ""
    
    # Check if monitor is already running
    if [ -f "$MONITOR_PID_FILE" ]; then
        MONITOR_PID=$(cat "$MONITOR_PID_FILE" 2>/dev/null)
        if [ -n "$MONITOR_PID" ] && ps -p "$MONITOR_PID" > /dev/null 2>&1; then
            echo -e "${YELLOW}Monitor is already running (PID: $MONITOR_PID)${NC}"
            return 1
        fi
    fi
    
    # Start monitor in background
    (
        echo $$ > "$MONITOR_PID_FILE"
        
        while true; do
            if ! is_worker_running; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Worker is not running, restarting..."
                start_worker
            fi
            sleep "$MONITOR_INTERVAL"
        done
    ) &
    
    MONITOR_PID=$!
    echo $$ > "$MONITOR_PID_FILE"
    echo -e "${GREEN}Monitor started (PID: $MONITOR_PID)${NC}"
    echo "Monitor PID file: $MONITOR_PID_FILE"
    
    # Wait for monitor process
    wait $MONITOR_PID
}

# Stop monitor
stop_monitor() {
    if [ ! -f "$MONITOR_PID_FILE" ]; then
        echo -e "${YELLOW}Monitor is not running${NC}"
        return 1
    fi
    
    MONITOR_PID=$(cat "$MONITOR_PID_FILE" 2>/dev/null)
    
    if [ -z "$MONITOR_PID" ]; then
        echo -e "${YELLOW}Could not read monitor PID${NC}"
        rm -f "$MONITOR_PID_FILE" 2>/dev/null
        return 1
    fi
    
    if ! ps -p "$MONITOR_PID" > /dev/null 2>&1; then
        echo -e "${YELLOW}Monitor is not running${NC}"
        rm -f "$MONITOR_PID_FILE" 2>/dev/null
        return 1
    fi
    
    echo -e "${BLUE}Stopping monitor (PID: $MONITOR_PID)...${NC}"
    kill "$MONITOR_PID" 2>/dev/null
    sleep 1
    
    if ! ps -p "$MONITOR_PID" > /dev/null 2>&1; then
        echo -e "${GREEN}Monitor stopped${NC}"
        rm -f "$MONITOR_PID_FILE" 2>/dev/null
    else
        echo -e "${YELLOW}Monitor did not stop, forcing shutdown...${NC}"
        kill -KILL "$MONITOR_PID" 2>/dev/null
        rm -f "$MONITOR_PID_FILE" 2>/dev/null
        echo -e "${GREEN}Monitor stopped${NC}"
    fi
}

# Show logs
show_logs() {
    if [ ! -f "$LOG_FILE" ]; then
        echo -e "${YELLOW}Log file does not exist: $LOG_FILE${NC}"
        return 1
    fi
    
    if [ "$1" = "-f" ] || [ "$1" = "--follow" ]; then
        tail -f "$LOG_FILE"
    else
        tail -n 50 "$LOG_FILE"
    fi
}

# Show usage
show_usage() {
    echo "Usage: $0 {start|stop|restart|status|monitor|stop-monitor|logs} [options]"
    echo ""
    echo "Commands:"
    echo "  start         Start the worker"
    echo "  stop          Stop the worker"
    echo "  restart       Restart the worker"
    echo "  status        Show worker status"
    echo "  monitor       Start monitoring (auto-restart if worker stops)"
    echo "  stop-monitor  Stop the monitor"
    echo "  logs          Show recent logs (use -f or --follow to tail)"
    echo ""
    echo "Options:"
    echo "  --poll-interval=N    Set poll interval in seconds (for start/restart)"
    echo ""
    echo "Examples:"
    echo "  $0 start"
    echo "  $0 start --poll-interval=10"
    echo "  $0 monitor"
    echo "  $0 logs -f"
}

# Main script
main() {
    # Initialize worker file paths
    get_worker_files
    
    # Parse arguments
    case "${1:-}" in
        start)
            # Check for poll-interval option
            if [[ "$2" == --poll-interval=* ]]; then
                POLL_INTERVAL="${2#--poll-interval=}"
            fi
            start_worker
            ;;
        stop)
            stop_worker
            ;;
        restart)
            # Check for poll-interval option
            if [[ "$2" == --poll-interval=* ]]; then
                POLL_INTERVAL="${2#--poll-interval=}"
            fi
            restart_worker
            ;;
        status)
            show_status
            ;;
        monitor)
            monitor_worker
            ;;
        stop-monitor)
            stop_monitor
            ;;
        logs)
            show_logs "$2"
            ;;
        *)
            show_usage
            exit 1
            ;;
    esac
}

# Run main function
main "$@"
