#!/usr/bin/env python3
from pathlib import Path
import shutil, subprocess, re, datetime, os, urllib.request

root = Path.cwd()
if not (root / 'pubspec.yaml').exists() or not (root / 'android').is_dir():
    raise SystemExit('Run this script from the STOREAPP repository root.')

required_dirs = ['binding','controller','core','data','address','orders','screeen','widget']
missing = [d for d in required_dirs if not (root/d).is_dir()]
if missing:
    raise SystemExit('Missing uploaded source folders: ' + ', '.join(missing))

stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
backup = root / f'.storeapp-repair-backup-{stamp}'
backup.mkdir()
for rel in ['pubspec.yaml','android/app/build.gradle','android/app/src/main/AndroidManifest.xml','.gitignore']:
    p=root/rel
    if p.exists():
        dst=backup/rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(p,dst)

print('1/6 Reconstructing lib/ from the folders currently uploaded to STOREAPP...')
lib = root/'lib'
(lib/'view').mkdir(parents=True, exist_ok=True)
map_dirs = {
    'binding':'lib/binding',
    'controller':'lib/controller',
    'core':'lib/core',
    'data':'lib/data',
    'address':'lib/view/address',
    'orders':'lib/view/orders',
    'screeen':'lib/view/screeen',
    'widget':'lib/view/widget',
}
for src_rel,dst_rel in map_dirs.items():
    src=root/src_rel; dst=root/dst_rel
    if dst.exists(): shutil.rmtree(dst)
    shutil.copytree(src,dst)
if (root/'test_view.dart').exists():
    shutil.copy2(root/'test_view.dart', lib/'view/test_view.dart')

print('2/6 Restoring missing Flutter entry/config files from the matching upstream commit...')
base='https://raw.githubusercontent.com/oiu85/Ecommerce-flutter-app-Pharmacy/89deb070f60d1f8fa6ebc0540836419542de33c4/lib/'
for name in ['main.dart','routes.dart','linkapi.dart','binding.dart','test.dart']:
    with urllib.request.urlopen(base+name, timeout=30) as r:
        (lib/name).write_bytes(r.read())

print('3/6 Correcting known path/config issues...')
p=lib/'routes.dart'
s=p.read_text(encoding='utf-8')
s=s.replace("import '../../view/screeen/auth/login.dart';", "import 'package:ecommerce_app/view/screeen/auth/login.dart';")
p.write_text(s,encoding='utf-8')

p=lib/'linkapi.dart'
s=p.read_text(encoding='utf-8')
s,n=re.subn(r'static const String server\s*=\s*"http://192\.168\.130\.157/ecommerce";',
'''static const String server = String.fromEnvironment(
    "API_BASE_URL",
    defaultValue: "http://192.168.130.157/ecommerce",
  );''',s,count=1)
if n != 1:
    raise SystemExit('Could not safely patch lib/linkapi.dart')
p.write_text(s,encoding='utf-8')

p=root/'pubspec.yaml'
s=p.read_text(encoding='utf-8').replace('version: 1.0.0+1','version: 1.0.1+2',1)
p.write_text(s,encoding='utf-8')

print('4/6 Restoring missing Gradle wrapper files when Flutter is available...')
flutter=shutil.which('flutter')
if flutter and (not (root/'android/gradlew').exists() or not (root/'android/gradle/wrapper/gradle-wrapper.jar').exists()):
    import tempfile
    with tempfile.TemporaryDirectory() as td:
        seed=Path(td)/'wrapper_seed'
        subprocess.run([flutter,'create','--no-pub','--platforms=android','--project-name','wrapper_seed',str(seed)],check=True,stdout=subprocess.DEVNULL)
        shutil.copy2(seed/'android/gradlew',root/'android/gradlew')
        shutil.copy2(seed/'android/gradlew.bat',root/'android/gradlew.bat')
        (root/'android/gradle/wrapper').mkdir(parents=True,exist_ok=True)
        shutil.copy2(seed/'android/gradle/wrapper/gradle-wrapper.jar',root/'android/gradle/wrapper/gradle-wrapper.jar')
        os.chmod(root/'android/gradlew',0o755)
elif not flutter:
    print('WARNING: Flutter is not installed here; wrapper restoration deferred to build environment.')

print('5/6 Writing APK smoke-build workflow...')
wf=root/'.github/workflows/android-smoke.yml'
wf.parent.mkdir(parents=True,exist_ok=True)
wf.write_text('''name: Android APK smoke build

on:
  workflow_dispatch:

jobs:
  android:
    runs-on: ubuntu-latest
    timeout-minutes: 45
    steps:
      - uses: actions/checkout@v6
      - uses: actions/setup-java@v5
        with:
          distribution: temurin
          java-version: "17"
      - uses: subosito/flutter-action@v2
        with:
          channel: stable
          cache: true
      - name: Restore Gradle wrapper if missing
        shell: bash
        run: |
          set -euo pipefail
          if [ ! -f android/gradlew ] || [ ! -f android/gradle/wrapper/gradle-wrapper.jar ]; then
            tmp="$(mktemp -d)"
            flutter create --no-pub --platforms=android --project-name wrapper_seed "$tmp/wrapper_seed"
            cp "$tmp/wrapper_seed/android/gradlew" android/gradlew
            cp "$tmp/wrapper_seed/android/gradlew.bat" android/gradlew.bat
            cp "$tmp/wrapper_seed/android/gradle/wrapper/gradle-wrapper.jar" android/gradle/wrapper/gradle-wrapper.jar
            chmod +x android/gradlew
          fi
      - run: flutter pub get
      - name: Analyze
        run: flutter analyze
        continue-on-error: true
      - name: Build installable universal debug APK
        run: flutter build apk --debug
      - uses: actions/upload-artifact@v6
        with:
          name: STOREAPP-debug-apk
          path: build/app/outputs/flutter-apk/app-debug.apk
          retention-days: 14
''',encoding='utf-8')

print('6/6 Structural checks...')
for rel in ['lib/main.dart','lib/routes.dart','lib/linkapi.dart','lib/controller','lib/core','lib/data','lib/view/screeen','lib/view/widget']:
    if not (root/rel).exists(): raise SystemExit('Repair failed: '+rel+' missing')
print('STOREAPP structure repaired successfully.')
print('Backup:', backup)
print('Next: bash tools/build_apk.sh')
