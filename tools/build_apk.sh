#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
command -v flutter >/dev/null || { echo 'Flutter is not installed.' >&2; exit 1; }
: "${API_BASE_URL:?API_BASE_URL must be set explicitly}"
: "${APP_INSTANCE_KEY:?APP_INSTANCE_KEY must be set explicitly}"
flutter --version
flutter clean
flutter pub get
flutter analyze
flutter test
flutter build apk --debug \
  --dart-define=API_BASE_URL="$API_BASE_URL" \
  --dart-define=APP_INSTANCE_KEY="$APP_INSTANCE_KEY"
mkdir -p dist
cp build/app/outputs/flutter-apk/app-debug.apk dist/STOREAPP-debug.apk
sha256sum dist/STOREAPP-debug.apk | tee dist/SHA256SUMS.txt
echo "APK: dist/STOREAPP-debug.apk"
