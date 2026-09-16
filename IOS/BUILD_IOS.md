# Building the iOS App (.ipa)

This directory contains the independent iOS version of the SST Attendance application (`sst_attendance`).
The Android project in `Attendance/APK` remains untouched.

---

## Option 1: Build Free in Cloud via GitHub Actions (No Mac Required)

If you do not have a physical Mac, you can use GitHub's free macOS runners to build the `.ipa` file:

1. Push your repository to GitHub.
2. In `.github/workflows/build_ios.yml`:
```yaml
name: Build iOS IPA
on:
  workflow_dispatch:

jobs:
  build:
    runs-on: macos-14
    steps:
      - uses: actions/checkout@v4
      - uses: subosito/flutter-action@v2
        with:
          flutter-version: '3.24.x'
          channel: 'stable'
      
      - name: Install dependencies
        working-directory: Attendance/IOS
        run: flutter pub get

      - name: Build IPA (Unsigned)
        working-directory: Attendance/IOS
        run: flutter build ios --release --no-codesign

      - name: Package Payload as IPA
        working-directory: Attendance/IOS
        run: |
          mkdir -p Payload
          cp -r build/ios/iphoneos/Runner.app Payload/
          zip -r sst_attendance.ipa Payload
          
      - name: Upload IPA Artifact
        uses: actions/upload-artifact@v4
        with:
          name: sst_attendance_ios
          path: Attendance/IOS/sst_attendance.ipa
```
3. Go to **Actions** on GitHub, run the workflow, and download the ready-to-use `sst_attendance.ipa`.

---

## Option 2: Build on a Mac

If you have access to a Mac:

1. Open a terminal and navigate to this folder:
   ```bash
   cd Attendance/IOS
   flutter pub get
   ```
2. Build the unsigned `.ipa`:
   ```bash
   flutter build ipa --no-codesign
   ```
   Or to create a `.ipa` directly from the release bundle:
   ```bash
   flutter build ios --release --no-codesign
   cd build/ios/iphoneos
   mkdir Payload
   cp -r Runner.app Payload/
   zip -r sst_attendance.ipa Payload
   ```

---

## How to Install the .ipa on an iPhone

1. **Connect iPhone to Windows Laptop** with a USB cable.
2. Open **[Sideloadly](https://sideloadly.io/)**.
3. Drag & drop `sst_attendance.ipa` into Sideloadly.
4. Enter your regular Apple ID and click **Start**.
5. On the iPhone:
   - Go to **Settings** → **General** → **VPN & Device Management** → Tap your Apple ID → **Trust**.
   - (iOS 16+): Go to **Settings** → **Privacy & Security** → Enable **Developer Mode**.
