#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
command -v flutter >/dev/null || { echo 'Flutter is not installed.' >&2; exit 1; }
API_BASE_URL="${API_BASE_URL:-http://192.168.130.157/ecommerce}"
flutter --version
flutter clean
flutter pub get
flutter analyze || true
flutter build apk --debug --dart-define=API_BASE_URL="$API_BASE_URL"
mkdir -p dist
cp build/app/outputs/flutter-apk/app-debug.apk dist/STOREAPP-debug.apk
sha256sum dist/STOREAPP-debug.apk | tee dist/SHA256SUMS.txt
echo "APK: dist/STOREAPP-debug.apk"
