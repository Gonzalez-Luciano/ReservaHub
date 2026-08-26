#!/usr/bin/env bash
#
# Smoke de ReservaHub. Solo lectura: no modifica datos.
#
#   scripts/smoke.sh https://reservahub.example.dev
#
# Las credenciales de demo son públicas por diseño y pueden pasarse por
# entorno para probar además el login de la API:
#
#   SMOKE_EMAIL=owner@reservahub.test SMOKE_PASSWORD=password scripts/smoke.sh http://localhost:8280
#
set -uo pipefail

BASE_URL="${1:-}"

if [ -z "$BASE_URL" ]; then
    echo "uso: $0 <base-url>" >&2
    exit 2
fi

BASE_URL="${BASE_URL%/}"
failures=0

check() {
    local label="$1" path="$2" expected="$3"
    local actual

    actual=$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 15 "${BASE_URL}${path}" 2>/dev/null \
        || curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "${BASE_URL}${path}" 2>/dev/null)

    if [ "$actual" = "$expected" ]; then
        printf '  ok    %-28s %s\n' "$label" "$actual"
    else
        printf '  FALLA %-28s esperaba %s, obtuvo %s\n' "$label" "$expected" "$actual"
        failures=$((failures + 1))
    fi
}

echo "Smoke de ReservaHub contra ${BASE_URL}"
echo

echo "HTTP:"
check "health"            "/up"            200
check "portada"           "/"              200
check "listado negocios"  "/negocios"      200
check "guía de la demo"   "/como-funciona" 200
check "login"             "/login"         200

echo
echo "Assets compilados:"
asset=$(curl -fsS --max-time 15 "${BASE_URL}/" 2>/dev/null | grep -o '/build/assets/[^"]*\.js' | head -1)

if [ -z "$asset" ]; then
    echo "  FALLA build                        la portada no referencia ningún bundle de /build"
    failures=$((failures + 1))
else
    check "bundle" "$asset" 200
fi

echo
echo "Tiempo real:"
# 401 es la respuesta correcta: demuestra que el gateway enruta a Reverb y que
# Reverb rechaza lo que no viene firmado.
check "gateway de Reverb" "/apps/smoke/channels" 401

if [ -n "${SMOKE_EMAIL:-}" ] && [ -n "${SMOKE_PASSWORD:-}" ]; then
    echo
    echo "API:"
    token=$(curl -fsS --max-time 15 -X POST "${BASE_URL}/api/auth/login" \
        -H 'Accept: application/json' \
        -d "email=${SMOKE_EMAIL}&password=${SMOKE_PASSWORD}&device_name=smoke" 2>/dev/null \
        | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

    if [ -z "$token" ]; then
        echo "  FALLA login de API                 no devolvió token"
        failures=$((failures + 1))
    else
        echo "  ok    login de API                 token recibido"

        services=$(curl -fsS --max-time 15 -o /dev/null -w '%{http_code}' \
            -H "Authorization: Bearer ${token}" -H 'Accept: application/json' \
            "${BASE_URL}/api/services" 2>/dev/null)

        if [ "$services" = "200" ]; then
            echo "  ok    GET /api/services            200"
        else
            echo "  FALLA GET /api/services            esperaba 200, obtuvo ${services}"
            failures=$((failures + 1))
        fi
    fi
fi

echo
if [ "$failures" -eq 0 ]; then
    echo "SMOKE OK"
    exit 0
fi

echo "SMOKE CON ${failures} FALLA(S)"
exit 1
