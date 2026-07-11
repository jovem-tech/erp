#!/usr/bin/env bash
# Coletor de Bancada Jovem Tech — build Linux.
#
# Roda diretamente NA MAQUINA DO CLIENTE (o computador sendo diagnosticado) —
# nao precisa de instalacao, so' copiar este arquivo pra uma pasta (por
# convencao, algo dentro de $HOME, ex. ~/JovemTechBenchCollector/) e executar.
# Le o inventario de hardware da propria maquina (sem privilegios de root) e:
#   1) grava um snapshot local em JSON, do lado do cliente (auditoria/debug);
#   2) se --pairing-code + --erp-base-url forem informados, ENVIA o snapshot
#      pela rede para o ERP (POST /api/v1/collector/snapshots), pareado ao
#      codigo gerado na tela de cadastro do equipamento.
#
# Uso tipico (o tecnico gera o codigo na tela do ERP e roda isto na maquina
# do cliente, na mesma rede local):
#   ./jovemtech-bench-collector.sh \
#       --pairing-code=ABCD1234 \
#       --erp-base-url=https://192.168.1.100:8443 \
#       --collector-token=<token>
#
# Uso local, so' para ler o proprio hardware sem enviar nada (debug):
#   ./jovemtech-bench-collector.sh --dry-run
#
# --no-prompt/--no-save-config existem so por compatibilidade com a mesma
# linha de comando do .exe do Windows — este script nunca pede confirmacao.
#
# Fallback de numero de serie (mesma regra documentada no coletor Windows):
# prioriza o serial da BIOS/placa-mae; a maioria das distros restringe a
# leitura de product_serial/board_serial ao root (0400), entao SEM sudo o
# script cai para o endereco MAC da primeira interface de rede fisica —
# mesma logica que o Windows usa quando a BIOS nao traz uma serie confiavel.

set -uo pipefail

COLLECTOR_HOME="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
SNAPSHOT_PATH="$COLLECTOR_HOME/last-snapshot.json"
DMI="/sys/class/dmi/id"

DRY_RUN=0
PAIRING_CODE=""
ERP_BASE_URL=""
COLLECTOR_TOKEN=""

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --no-prompt|--no-save-config) : ;;
        --pairing-code=*) PAIRING_CODE="${arg#*=}" ;;
        --erp-base-url=*) ERP_BASE_URL="${arg#*=}"; ERP_BASE_URL="${ERP_BASE_URL%/}" ;;
        --collector-token=*) COLLECTOR_TOKEN="${arg#*=}" ;;
        *) ;;
    esac
done

# --- helpers ------------------------------------------------------------

read_dmi() {
    local file="$DMI/$1"
    if [ -r "$file" ]; then
        cat "$file" 2>/dev/null | tr -d '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'
    fi
}

# Placeholders comuns de fabricantes quando o campo nao foi preenchido —
# tratados como vazio para nao poluir o cadastro com lixo tipo
# "To Be Filled By O.E.M.".
is_placeholder() {
    local value
    value="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
    case "$value" in
        ""|*"to be filled"*|"system manufacturer"|"system product name"|"default string"|"o.e.m"*|"not specified"|"none")
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

clean_dmi() {
    local value
    value="$(read_dmi "$1")"
    if is_placeholder "$value"; then
        printf ''
    else
        printf '%s' "$value"
    fi
}

json_escape() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="$(printf '%s' "$s" | tr '\n\t\r' '   ')"
    printf '%s' "$s"
}

# --- deteccao de hardware -------------------------------------------------

board_vendor="$(clean_dmi board_vendor)"
board_name="$(clean_dmi board_name)"
bios_vendor="$(clean_dmi bios_vendor)"
bios_version="$(clean_dmi bios_version)"
sys_vendor="$(clean_dmi sys_vendor)"
product_name="$(clean_dmi product_name)"
chassis_type_code="$(read_dmi chassis_type)"

motherboard=""
if [ -n "$board_vendor" ] || [ -n "$board_name" ]; then
    motherboard="$(printf '%s %s' "$board_vendor" "$board_name" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
fi

manufacturer="$sys_vendor"
model="$product_name"

# Numero de serie: BIOS/placa-mae primeiro (normalmente so root le isto);
# sem privilegio, cai para o MAC da primeira interface de rede fisica.
serial=""
serial_source=""
for field in product_serial board_serial chassis_serial; do
    candidate="$(clean_dmi "$field" 2>/dev/null)"
    if [ -n "$candidate" ]; then
        serial="$candidate"
        serial_source="bios"
        break
    fi
done

if [ -z "$serial" ]; then
    for iface_path in /sys/class/net/*; do
        iface="$(basename "$iface_path")"
        [ "$iface" = "lo" ] && continue
        mac="$(cat "$iface_path/address" 2>/dev/null || true)"
        if [ -n "$mac" ] && [ "$mac" != "00:00:00:00:00:00" ]; then
            serial="$mac"
            serial_source="mac_address"
            break
        fi
    done
fi

# CPU
cpu=""
if command -v lscpu >/dev/null 2>&1; then
    cpu="$(lscpu 2>/dev/null | awk -F: '/^Model name:/{gsub(/^[ \t]+/,"",$2); print $2; exit}')"
fi
if [ -z "$cpu" ] && [ -r /proc/cpuinfo ]; then
    cpu="$(awk -F: '/^model name/{gsub(/^[ \t]+/,"",$2); print $2; exit}' /proc/cpuinfo)"
fi

# RAM (GB, arredondado a 2 casas quando fizer sentido)
# LC_NUMERIC=C e' obrigatorio aqui: em locale pt_BR (ou qualquer locale que
# use virgula como separador decimal), awk formata "6.99" como "6,99" — o que
# quebra o JSON (numero com virgula nao e' JSON valido).
ram_gb=""
memory_summary=""
if [ -r /proc/meminfo ]; then
    mem_kb="$(awk '/^MemTotal:/{print $2; exit}' /proc/meminfo)"
    if [ -n "${mem_kb:-}" ]; then
        ram_gb="$(LC_NUMERIC=C awk -v kb="$mem_kb" 'BEGIN{printf "%.2f", kb/1024/1024}')"
        ram_gb="$(printf '%s' "$ram_gb" | sed 's/0*$//;s/\.$//')"
        memory_summary="${ram_gb} GB"
    fi
fi

# Armazenamento: nome + tamanho + modelo de cada disco fisico. TYPE=disk
# exclui loop devices (snap packages), roms e partições. Usa -P (pares
# chave="valor") porque MODEL costuma ter espaco ("ADATA SU650") e quebraria
# um parser por campos separados por espaco; -b da o tamanho em bytes puros
# (o texto formatado do lsblk tambem usa virgula decimal em locale pt_BR).
storage_summary=""
if command -v lsblk >/dev/null 2>&1; then
    while IFS= read -r line; do
        eval "$line"
        [ "${TYPE:-}" = "disk" ] || continue
        size_gb="$(LC_NUMERIC=C awk -v b="${SIZE:-0}" 'BEGIN{printf "%.0f", b/1000/1000/1000}')"
        entry="${NAME:-}"
        [ -n "${MODEL:-}" ] && entry="$entry ${MODEL}"
        entry="$entry (${size_gb}GB)"
        storage_summary="${storage_summary:+$storage_summary; }$entry"
    done < <(lsblk -dbn -P -o NAME,SIZE,MODEL,TYPE 2>/dev/null)
fi

# GPU: cada controladora de video listada pelo lspci
gpu=""
if command -v lspci >/dev/null 2>&1; then
    gpu="$(lspci 2>/dev/null \
        | grep -Ei 'vga compatible controller|3d controller|display controller' \
        | sed -E 's/^[0-9a-f:.]+ [A-Za-z0-9 ]+: //' \
        | paste -sd ';' - \
        | sed 's/;/; /g')"
fi

# Tipo de chassi (tabela SMBIOS) — strings escolhidas para casar com os
# substrings que EquipmentWorkflowService::mapChassisTypeToCaseType() procura.
chassis_type=""
device_type="desktop"
case "$chassis_type_code" in
    3) chassis_type="Desktop" ;;
    4) chassis_type="Low Profile Desktop" ;;
    5) chassis_type="Pizza Box" ;;
    6) chassis_type="Mini Tower" ;;
    7) chassis_type="Tower" ;;
    8) chassis_type="Portable"; device_type="notebook" ;;
    9) chassis_type="Laptop"; device_type="notebook" ;;
    10) chassis_type="Notebook"; device_type="notebook" ;;
    11) chassis_type="Hand Held"; device_type="notebook" ;;
    13) chassis_type="All In One" ;;
    14) chassis_type="Sub Notebook"; device_type="notebook" ;;
    15) chassis_type="Space Saving" ;;
    16) chassis_type="Lunch Box" ;;
    17) chassis_type="Main Server Chassis" ;;
    23) chassis_type="Rack Mount Chassis" ;;
    24) chassis_type="Sealed Case PC" ;;
    30) chassis_type="Tablet"; device_type="notebook" ;;
    31) chassis_type="Convertible"; device_type="notebook" ;;
    32) chassis_type="Detachable"; device_type="notebook" ;;
    35) chassis_type="Mini PC" ;;
    36) chassis_type="Stick PC" ;;
    *) chassis_type="" ;;
esac

collected_at_utc="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
hostname_value="$(hostname 2>/dev/null || echo '')"

# --- monta o objeto de hardware (reaproveitado no arquivo local e no envio) -

snapshot_json=$(cat <<EOF
{
    "motherboard": "$(json_escape "$motherboard")",
    "chipset": "",
    "cpu": "$(json_escape "$cpu")",
    "gpu": "$(json_escape "$gpu")",
    "storageSummary": "$(json_escape "$storage_summary")",
    "memorySummary": "$(json_escape "$memory_summary")",
    "ramGb": ${ram_gb:-null},
    "serialNumber": "$(json_escape "$serial")",
    "serialSource": "$(json_escape "$serial_source")",
    "manufacturer": "$(json_escape "$manufacturer")",
    "model": "$(json_escape "$model")",
    "deviceType": "$(json_escape "$device_type")",
    "chassisType": "$(json_escape "$chassis_type")",
    "biosVendor": "$(json_escape "$bios_vendor")",
    "biosVersion": "$(json_escape "$bios_version")"
}
EOF
)

# --- grava o snapshot local (sempre, mesmo quando envia pela rede) ---------

mkdir -p "$COLLECTOR_HOME"

cat > "$SNAPSHOT_PATH" <<EOF
{
  "collectedAtUtc": "$(json_escape "$collected_at_utc")",
  "collectedAtLocal": "$(json_escape "$(date +%Y-%m-%dT%H:%M:%S%z)")",
  "savedAtUtc": "$(json_escape "$collected_at_utc")",
  "savedAtLocal": "$(json_escape "$(date +%Y-%m-%dT%H:%M:%S%z)")",
  "agentVersion": "linux-x64-1.0",
  "hostname": "$(json_escape "$hostname_value")",
  "snapshot": ${snapshot_json}
}
EOF

echo "Snapshot local gravado em $SNAPSHOT_PATH"

# --- check-in pela rede: envia o snapshot para o ERP --------------------
#
# So' tenta enviar quando os 3 dados de conexao foram passados E nao esta em
# --dry-run (modo so'-leitura-local, pra debug na propria maquina).

if [ "$DRY_RUN" -eq 1 ]; then
    echo "Modo --dry-run: nada foi enviado pela rede."
    exit 0
fi

if [ -z "$PAIRING_CODE" ] || [ -z "$ERP_BASE_URL" ] || [ -z "$COLLECTOR_TOKEN" ]; then
    echo "Nenhum --pairing-code/--erp-base-url/--collector-token informado — apenas o snapshot local foi gravado."
    exit 0
fi

if ! command -v curl >/dev/null 2>&1; then
    echo "ERRO: curl nao encontrado neste sistema — nao e possivel enviar o check-in pela rede." >&2
    exit 1
fi

request_body=$(cat <<EOF
{
  "pairing_code": "$(json_escape "$PAIRING_CODE")",
  "snapshot": ${snapshot_json},
  "source": "linux-x64",
  "agent_version": "linux-x64-1.0",
  "hostname": "$(json_escape "$hostname_value")"
}
EOF
)

# -k (insecure): a rede local da assistencia normalmente usa certificado
# proprio/autoassinado no ERP — sem isto o curl recusa a conexao TLS.
http_status=$(curl -sk -o /tmp/jovemtech-collector-response.$$.json -w '%{http_code}' \
    -X POST "$ERP_BASE_URL/api/v1/collector/snapshots" \
    -H "Content-Type: application/json" \
    -H "X-Collector-Token: $COLLECTOR_TOKEN" \
    -d "$request_body")
response_body="$(cat /tmp/jovemtech-collector-response.$$.json 2>/dev/null || true)"
rm -f "/tmp/jovemtech-collector-response.$$.json"

if [ "$http_status" = "200" ] || [ "$http_status" = "201" ]; then
    echo "Check-in enviado com sucesso para $ERP_BASE_URL (codigo $PAIRING_CODE)."
    exit 0
fi

echo "ERRO: falha ao enviar o check-in (HTTP $http_status). Resposta: $response_body" >&2
exit 1
