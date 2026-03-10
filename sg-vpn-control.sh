#!/bin/bash

# sg-vpn-control: Secure wrapper for strongSwan swanctl
# This script is designed to be called by the web server (www-data) via sudo.

ACTION=$1
TUNNEL=$2

# Security: Ensure only valid actions are performed
VALID_ACTIONS=("status" "up" "down" "reload" "logs")
if [[ ! " ${VALID_ACTIONS[@]} " =~ " ${ACTION} " ]]; then
    echo "{\"status\":\"error\",\"message\":\"Invalid action: $ACTION\"}"
    exit 1
fi

# Security: Validate tunnel name format if provided (alphanumeric and underscores)
if [[ -n "$TUNNEL" && ! "$TUNNEL" =~ ^[a-zA-Z0-9_]+$ ]]; then
    echo "{\"status\":\"error\",\"message\":\"Invalid tunnel name format\"}"
    exit 1
fi

case $ACTION in
    status)
        OUTPUT=$(swanctl --list-sas 2>&1)
        echo "RAW_OUTPUT_START"
        echo "$OUTPUT"
        echo "RAW_OUTPUT_END"
        ;;
    up)
        if [[ -z "$TUNNEL" ]]; then
            echo "{\"status\":\"error\",\"message\":\"Tunnel name required for 'up' action\"}"
            exit 1
        fi
        OUTPUT=$(swanctl --initiate --child "$TUNNEL" 2>&1)
        if [[ $? -eq 0 ]]; then
            echo "{\"status\":\"success\",\"message\":\"Tunnel $TUNNEL initiated\",\"output\":\"$OUTPUT\"}"
        else
            echo "{\"status\":\"error\",\"message\":\"Failed to initiate $TUNNEL\",\"output\":\"$OUTPUT\"}"
        fi
        ;;
    down)
        if [[ -z "$TUNNEL" ]]; then
            echo "{\"status\":\"error\",\"message\":\"Tunnel name required for 'down' action\"}"
            exit 1
        fi
        OUTPUT=$(swanctl --terminate --child "$TUNNEL" 2>&1)
        if [[ $? -eq 0 ]]; then
            echo "{\"status\":\"success\",\"message\":\"Tunnel $TUNNEL terminated\",\"output\":\"$OUTPUT\"}"
        else
            echo "{\"status\":\"error\",\"message\":\"Failed to terminate $TUNNEL\",\"output\":\"$OUTPUT\"}"
        fi
        ;;
    reload)
        OUTPUT=$(swanctl --load-all 2>&1)
        if [[ $? -eq 0 ]]; then
            echo "{\"status\":\"success\",\"message\":\"Configuration reloaded\",\"output\":\"$OUTPUT\"}"
        else
            echo "{\"status\":\"error\",\"message\":\"Failed to reload configuration\",\"output\":\"$OUTPUT\"}"
        fi
        ;;
    logs)
        # Try once with a combined query
        OUTPUT=$(journalctl -u strongswan -u charon -u strongswan-starter -n 50 --no-pager 2>&1)
        
        # If that returned nothing or failed, try syslog grep
        if [[ -z "$OUTPUT" || "$OUTPUT" == *"-- No entries --"* ]]; then
             OUTPUT=$(grep -iE "charon|strongswan|ipsec" /var/log/syslog 2>&1 | tail -n 50)
        fi
        
        if [[ -z "$OUTPUT" ]]; then
             OUTPUT="No IPsec logs found in journalctl or syslog. Try running 'ls -l /var/log/syslog' to check permissions."
        fi
        
        echo "RAW_LOGS_START"
        echo "$OUTPUT"
        echo "RAW_LOGS_END"
        ;;
esac
