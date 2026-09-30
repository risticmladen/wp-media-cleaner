# Čišćenje medija – jerkovic.hr

Alat za sigurno čišćenje `wp-content/uploads` na WordPress stranici (Enfold tema):

- **Faza A:** nekorištene cijele slike (orphani i slike u Media Library koje se nigdje ne koriste).
- **Faza B:** suvišne generirane veličine slika (thumbnail-i) koje stranica ne traži.
- **Faza C:** mu-plugin koji sprječava da se suvišne veličine opet generiraju.

Sve se radi kroz preglednik (nema CLI-ja), u malim serijama koje se same nastavljaju. Brisanje ide **prvo u karantenu** (može se vratiti).

## Što je u ovoj mapi

- `unused-media-cleanup.php` – glavna skripta (faze A i B).
- `umc-image-sizes.php` – mu-plugin (faza C).
- `known-unused.txt` – tvoja lista slika koje su sigurno nekorištene (provjera skenera, opcionalno).
- `live-files.txt` – slike koje živi HTML stvarno traži (zaštita za fazu B). Generira ga crawler.
- `missing-on-disk.txt` – stari popis datoteka koje su nedostajale u nepotpunom backupu (povijesno).
- `analysis/` – crawleri i pomoćne skripte za analizu (vidi dolje).

Izvan ove mape (u `~/Projects/`) ostale su sigurnosne kopije s lokalnog testiranja: `jerkovic-uploads-backup-20260930` i `jerkovic-db-before-test-20260930.sql.gz`.

## Kako skripta odlučuje

- **Faza A:** čita sve tekstualne stupce u bazi (`posts`, `postmeta`, `options`, `layerslider`, ...) i datoteke teme/CSS-a. Skuplja reference na slike (URL-ovi i ID-jevi). Slike grupira u "obitelji" (original + veličine + `-scaled` + `.webp`). Ako je išta iz obitelji referencirano, cijela obitelj ostaje.
  - **O (orphan):** datoteka nije u Media Library i nigdje se ne koristi.
  - **A (attachment):** u Media Library je, ali se nigdje ne koristi.
  - Konzervativni način: attachment vezan uz objavljenu stranicu/objavu smatra se korištenim. Strogi način to ignorira (više nekorištenih, veći rizik).
- **Faza B:** za svaki attachment čita metapodatke (`_wp_attachment_metadata`) i uklanja sve veličine čije ime **nije** u `keep_sizes`. Datoteke koje su referencirane po točnom URL-u (baza, tema, `live-files.txt`) ne dira. Metapodaci se ažuriraju, a uklonjeni unosi spremaju u `umc-work/meta-backup.tsv` (vraćaju se gumbom "Vrati sve").
- Zadržane veličine (`keep_sizes` u skripti): `full`, `thumbnail`, `square`, `medium`, `large`, `widget`, `portfolio`, `gallery`, `featured`, `masonry`, `shop_thumbnail`, `shop_catalog`, `shop_single`. Izbačene: `medium_large`, `1536x1536`, `2048x2048`, `featured_large`, `entry_with_sidebar`, `entry_without_sidebar`, `magazine`, `portfolio_small`, `extra_large` i sve stare (Fusion/Avada: `portfolio-one…five`, `portfolio-full` ...).

## PRIJE pokretanja

1. **Backup baze** na produkciji (phpMyAdmin → Export). Faza B mijenja metapodatke, a faza A (uz kvačicu) briše zapise iz Media Library.
2. **Backup uploads foldera** i provjera da je **kompletan**.
   - Upozorenje: FTP klijent može skratiti popis mape na oko 10.000 unosa. Mape s više datoteka (kod nas `2025/03` i `2025/05`) skini kao zip s servera (cPanel File Manager → Compress).
   - Provjera: za svaki `_wp_attached_file` iz baze mora postojati datoteka na disku (osim onih kojih nema ni na produkciji).
3. **Mirna produkcija.** Nemoj uređivati sadržaj dok traje sken i primjena (skenirani sadržaj mora odgovarati stanju prilikom primjene).
4. **Svježa `live-files.txt`** neposredno prije skena (vidi "Kako napraviti live-files.txt"). Ako nedostaje, skripta na izvješću pokazuje upozorenje i faza B je manje sigurna.
5. (Opcionalno) `known-unused.txt`: jedna slika po retku (ime datoteke, URL ili CSV redak). Izvješće javlja koliko ih je prepoznato kao nekorištene.
6. **Upload preko FTP-a** u korijen WordPressa (`public_html`, pored `wp-load.php`): `unused-media-cleanup.php`, `live-files.txt`, `known-unused.txt`.
7. Provjeri da se skripta otvara: `https://www.jerkovic.hr/unused-media-cleanup.php` treba preusmjeriti na prijavu (samo prijavljeni administrator smije dalje).
8. Preporuka: prvo probaj na lokalnoj kopiji (ddev, svježa baza + slike).

## POKRETANJE

1. Otvori `https://www.jerkovic.hr/unused-media-cleanup.php` kao administrator.
2. **Novi sken:** označi "Ignoriraj sadržaj u smeću" i "Analiziraj i veličine slika". Strogi način ne označavaj u prvom prolazu. Pričekaj da se stranica sama nastavlja.
3. **Provjeri izvješće prije primjene.** Na ovoj stranici (rujan 2026.) očekivane brojke bile su:
   - lista: **231 / 234** prepoznato (3 slike `Parter_FT_*` ostaju zbog pravila "vezano uz stranicu"),
   - nekorišteno: oko 3.262 datoteke / 165 MB (246 attachmenta),
   - veličine: oko 35,9 tisuća datoteka / 2,1 GB,
   - zaštićenih URL-ova oko 6.000, bez upozorenja o `live-files.txt`.
   Ako se brojke jako razlikuju, nemoj primjenjivati dok ne utvrdiš zašto.
4. **Primjena, redom**, sve u karantenu:
   - "Samo orphane (O)", pa provjeri stranicu,
   - "Sve nekorišteno (O + A)" uz kvačicu za brisanje zapisa iz Media Library,
   - "Ukloni veličine".
   Stranica prikazuje napredak i nastavlja se sama. Ne zatvaraj je do poruke "završeno".
5. Nakon svakog koraka provjeri stranicu: početna, nekoliko stranica s galerijama/portfeljem, "novosti", Media Library u adminu.

## POSLIJE pokretanja

1. **Stavi mu-plugin:**
   - U `wp-content/` napravi mapu `mu-plugins` (točno to ime, ako je nema), pa u nju uploadaj `umc-image-sizes.php`. Ne treba ga aktivirati.
   - Provjera: u adminu **Dodaci → Must-Use** mora se vidjeti "UMC Image Sizes".
   - Probni upload jedne slike u Media Library: trebalo bi nastati oko 13 datoteka (original + 12 veličina), a ne 22.
2. **Obriši `live-files.txt` i `known-unused.txt`** sa servera (javno su dohvatljivi; sadrže samo nazive slika).
3. **Razdoblje promatranja (7–14 dana):** kolege neka obrate pažnju na slike koje se ne učitavaju ili su nepotrebno velike (galerije, portfelj, "novosti", mobitel i široki monitori, Media Library).
4. Ako netko nešto primijeti: gumb **"Vrati sve iz karantene"** na početnoj stranici skripte vraća datoteke i unose u metapodacima. Zapisi u Media Library obrisani u fazi A vraćaju se samo iz backupa baze.
5. **Kad je sve u redu:** prije trajnog brisanja provjeri početnu i ključne stranice, pa na početnoj stranici skripte klikni **"Trajno obriši karantenu"**. Nakon toga vraćanja nema (osim iz backupa), pa svoje kopije zadrži još neko vrijeme.
6. **Obriši `unused-media-cleanup.php`** sa servera kad završiš.
7. Karantena zauzima prostor dok se ne obriše: `wp-content/umc-work/quarantine`. Ne briši je ručno preko FTP-a (sporo); koristi gumb. Mapa `umc-work` ima zabranu pristupa s weba.

## Kako napraviti live-files.txt

Crawler dohvaća (samo GET) objavljene stranice iz sitemapa i blog/arhive, pa bilježi sve URL-ove slika iz HTML-a (uključujući `srcset`).

```bash
cd ~/Projects/jerkovic-media-cleanup
python3 analysis/umc_crawl.py            # stranice iz sitemapa; piše /tmp/umc_crawl_files.json
python3 analysis/umc_crawl_archives.py   # blog, kategorije, pretraga; piše analysis/umc_crawl_archives_files.json
python3 - <<'EOF'
import json
a = set(json.load(open('/tmp/umc_crawl_files.json')))
b = set(json.load(open('analysis/umc_crawl_archives_files.json')))
open('live-files.txt', 'w').write('\n'.join(sorted(a | b)) + '\n')
print(len(a | b), 'URL-ova u live-files.txt')
EOF
```

Napomena: adresa produkcije (`https://www.jerkovic.hr`) upisana je u oba crawlera. Crawl traje nekoliko minuta (~0,3 s razmaka između zahtjeva).

## Test na lokalnoj kopiji (ddev)

Prije produkcije ima smisla isprobati sve na kopiji (APFS klon `uploads` napravi se trenutno: `cp -c -R wp-content/uploads ../jerkovic-uploads-backup-DATUM`). Test koji je napravljen ovaj put:

- Faza B: 35.909 datoteka u karantenu, 0 grešaka; nijedna datoteka iz `live-files.txt` nije izgubljena; nijedan unos u metapodacima ne pokazuje na uklonjenu datoteku.
- Vraćanje: datoteke i metapodaci identični izvornom stanju.
- Faza A: 3.262 datoteke u karantenu, 246 attachmenta obrisano iz baze.
- Provjera invarijanti: `analysis/umc_invariants.py` i `analysis/umc_compare_restore.py` (putanje unutra odnose se na lokalni projekt).

## Podešavanje (na vrhu skripte, funkcija `umc_cfg()`)

- `keep_sizes` – veličine koje se zadržavaju u fazi B.
- `candidate_ext` – vrste datoteka koje se smatraju kandidatima (zadano samo slike; `mp4`/`pdf` nisu).
- `protected_dirs` – mape u `uploads` koje se nikad ne diraju (`layerslider`, `dynamic_avia`, ...).
- `min_age_days` – preskoči datoteke mlađe od N dana (zadano 0).
- `time_budget` – sekundi rada po zahtjevu (zadano 20; skripta se prilagođava `max_execution_time`).

## Rješavanje problema

- **"Nevažeći sigurnosni token":** vrati se na početnu stranicu skripte i pokušaj ponovo (token istječe nakon nekoliko sati).
- **Skripta pokazuje praznu stranicu / 500:** provjeri PHP error log; skripta traži PHP 7.2+ i `wp-load.php` u istoj mapi.
- **Sken stoji:** otvori skriptu ponovo, pojavit će se gumb "Nastavi". Stanje je u `wp-content/umc-work/state.ser`.
- **Sliku nema nakon čišćenja:** javi adresu stranice. Uzrok je vjerojatno veličina koja se traži dinamički; vraćanje iz karantene je moguće, pa onda ta veličina ide u `keep_sizes`.
- **Backup preko FTP-a nije kompletan:** vidi "Prije pokretanja", točka 2 (ograničenje ~10.000 unosa po mapi).

## Sadržaj mape umc-work (na serveru, `wp-content/umc-work`)

- `state.ser` – stanje skena i primjene.
- `unused.tsv` – plan faze A (datoteka, bajtovi, klasa, ID-jevi).
- `sizes.tsv` – plan faze B (ID, ime veličine, datoteka, bajtovi).
- `meta-backup.tsv` – uklonjeni unosi u metapodacima (za vraćanje).
- `actions.log` – zapis svake premještene/obrisane datoteke.
- `quarantine/` – premještene datoteke, s istom strukturom kao `uploads`.
