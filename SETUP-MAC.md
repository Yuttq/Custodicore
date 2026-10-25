# Running CustodiCore on a MacBook (demo setup)

This guide sets up **everything on one MacBook**: the staff web app, the database, and the visitor mobile app running in an **Android emulator**. Follow the sections in order. Plan for about 1–2 hours the first time (most of it is downloads).

You need: the MacBook, internet, admin password for the Mac, and a USB drive or Google Drive to copy a few files from the main Windows PC.

---

## 0. On the main Windows PC first (files git does not carry)

Some things are not in GitHub on purpose. Copy these to a USB drive / Drive folder:

| Copy this | From (Windows PC) | Why |
|---|---|---|
| Fresh database dump | export with the command below | the demo data (accounts, visits, PDLs) |
| `Custodicore-Web/.env` | project folder | DB name, Gmail sender for verification emails, app key |
| `Custodicore-Web/storage/app` folder | project folder | uploaded IDs / documents the database points to |
| `Custodicore-Mobile/.env` | project folder | API URL + Google Sign-In client ID |
| `Custodicore-Mobile/android/app/debug.keystore` | project folder | Google Sign-In only works with this exact key |

Export a fresh database dump (XAMPP shell or PowerShell in the XAMPP `mysql\bin` folder):

```
mysqldump -u root custodicore > custodicore-demo.sql
```

Also make sure the latest code is pushed to GitHub (`git push`).

> `.env` files contain passwords. Share them privately (not in group chats), and delete them from the USB afterwards.

---

## 1. Install the tools on the Mac

Open **Terminal** (Cmd + Space, type "Terminal").

1. **Homebrew** (the Mac package installer). Paste the install command from https://brew.sh and follow the "Next steps" lines it prints at the end.
2. Then install PHP, Composer, Node.js, MySQL and Git:

   ```
   brew install php composer node mysql git
   brew services start mysql
   ```

3. **Android Studio**: download from https://developer.android.com/studio and install it. Open it once and click through the setup wizard (Standard install). This installs the Android SDK and Java.
4. Tell the Terminal where the Android SDK is. Run:

   ```
   echo 'export ANDROID_HOME=$HOME/Library/Android/sdk' >> ~/.zshrc
   echo 'export PATH=$PATH:$ANDROID_HOME/emulator:$ANDROID_HOME/platform-tools' >> ~/.zshrc
   source ~/.zshrc
   ```

Check everything installed: `php -v` (8.2 or newer), `composer -V`, `node -v`, `mysql --version`, `adb version`.

---

## 2. Get the code

```
cd ~/Documents
git clone https://github.com/Yuttq/Custodicore.git
cd Custodicore
```

---

## 3. Web app + database

```
cd ~/Documents/Custodicore/Custodicore-Web
```

1. Put the copied `.env` into this folder. Then open it and check these lines:

   ```
   DB_HOST=127.0.0.1
   DB_USERNAME=root
   DB_PASSWORD=
   ```

   (Homebrew MySQL's `root` user has **no password** by default.)

2. Create the database and import the dump (adjust the path to where you saved the .sql file):

   ```
   mysql -u root -e "CREATE DATABASE custodicore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root custodicore < ~/Downloads/custodicore-demo.sql
   ```

3. Copy the uploaded files: replace `Custodicore-Web/storage/app` with the `storage/app` folder you copied.

4. Install and prepare:

   ```
   composer install
   npm install
   npm run build
   php artisan migrate
   php artisan config:clear
   php artisan visitor-ids:move-to-private
   ```

   `migrate` should say "Nothing to migrate" or run a few new ones. **Never** run `migrate:fresh`; it wipes the database.

   Do **not** run `php artisan storage:link` — uploaded IDs and documents are private and nothing needs it. The last command is a dry run that changes nothing: if it lists files "to copy", the copied folder still has government IDs in the old public location. Move them with the steps in `Custodicore-Web/README.md` → "Moving old government ID files to private storage" (copy first, delete originals as a separate step).

5. Start the server (leave this Terminal window open during the demo):

   ```
   php artisan serve
   ```

6. Open **http://127.0.0.1:8000** in Safari/Chrome and log in with a staff account.

---

## 4. Mobile app (Android emulator)

### 4a. Create the emulator (one time)

In Android Studio: **More Actions → Virtual Device Manager → Create device** → pick **Pixel 6** → pick the recommended system image (API 34 or 35, it shows "arm64" on Apple-silicon Macs) → Download → Finish. Press ▶ to start it and wait for the home screen.

### 4b. Set up and run the app

Open a **second** Terminal window (the first one is running the web server):

```
cd ~/Documents/Custodicore/Custodicore-Mobile
```

1. Put the copied mobile `.env` into this folder. The API line must stay:

   ```
   EXPO_PUBLIC_API_URL=http://10.0.2.2:8000/api
   ```

   (`10.0.2.2` is how the Android emulator reaches the Mac it runs on. Don't change it to `localhost`.)

2. Install and build. The first build takes **10–20 minutes**:

   ```
   npm install
   npx expo prebuild --platform android
   cp ~/Downloads/debug.keystore android/app/debug.keystore
   npx expo run:android
   ```

   The app installs on the emulator and opens. The `cp` line puts back the Windows PC's signing key so **Google Sign-In** works; without it, email/password still works but Google Sign-In fails.

3. Later runs (app already installed): with the emulator open, just run

   ```
   npx expo start
   ```

   and press **a** to open it on the emulator.

---

## 5. Before the teacher arrives (checklist)

Start these in this order:

1. MySQL running: `brew services start mysql`
2. Web: Terminal 1 → `cd ~/Documents/Custodicore/Custodicore-Web` → `php artisan serve`
3. Emulator: open it from Android Studio's Device Manager
4. Mobile: Terminal 2 → `cd ~/Documents/Custodicore/Custodicore-Mobile` → `npx expo start` → press **a**

Then do one full practice run:

- [ ] Register a new visitor on the phone (fill in birthdate, upload ID)
- [ ] Verification email arrives (needs internet + the Gmail settings in the web `.env`)
- [ ] Approve the visitor on the web (Record Officer)
- [ ] Request a visit on the phone → approve it on the web
- [ ] QR code shows on the phone

Plug the MacBook into power and turn off sleep (System Settings → Lock Screen / Battery) so nothing stops mid-demo.

---

## 6. If something goes wrong

| Problem | Fix |
|---|---|
| Phone app says network error / can't reach server | Is `php artisan serve` still running? Is the mobile `.env` URL exactly `http://10.0.2.2:8000/api`? After changing `.env`, stop Expo (Ctrl+C) and run `npx expo start -c`. |
| Web shows "SQLSTATE … Access denied" or "Connection refused" | `brew services start mysql`, check `DB_USERNAME` / `DB_PASSWORD` in the web `.env`, then `php artisan config:clear`. |
| Web page loads but no styling | Run `npm run build` in `Custodicore-Web`. |
| Uploaded ID pictures don't show | Check `storage/app` was copied over (IDs live in `storage/app/private/visitor-ids`, older ones in `storage/app/public/visitor-ids`). Run `php artisan visitor-ids:move-to-private` to see counts; "missing on both disks" means the files weren't copied. `storage:link` is not needed. |
| Verification email never arrives | Check `MAIL_*` lines in the web `.env`, then `php artisan config:clear` and restart `php artisan serve`. Last resort: set `MAIL_MAILER=log` and read the code from `storage/logs/laravel.log`. |
| Google Sign-In fails ("DEVELOPER_ERROR") | The `debug.keystore` wasn't copied before building. Copy it into `android/app/` and run `npx expo run:android` again. |
| `npx expo run:android` says SDK location not found | Redo step 1.4, then close and reopen Terminal. |
| Emulator is very slow | Close other apps; in Device Manager, edit the device and give it 2–4 GB RAM. |
