# ADR-0009: `caller` we wpisie i `route` w nagłówku, format `v: 2`

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-23 |
| **Dotyczy** | Format paczki, [spec 02 §1–§2](../spec/02-format-paczki.md#1-nagłówek), źródło SQL, [spec 01 §2](../spec/01-zbieranie-danych.md#2-źródło-sql) |

## Kontekst

Wpis mówi, jakie zapytanie poszło do bazy, ale nie skąd w kodzie. Spec 02 §6 wykluczała to wprost zdaniem „Miejsce w kodzie, ślad wywołań i treść odpowiedzi z bazy nie są częścią wpisu”. Osoba diagnozująca produkcję ([spec 00 §5](../spec/00-przeglad-i-zakres.md#5-użytkownicy)) szuka wtedy wywołania po tekście zapytania, a ten sam `query` pada z wielu miejsc.

Nagłówek niósł akcję wejściową w trzech polach: `module`, `controller`, `action`. Odbiorca sklejał je z powrotem w ścieżkę, którą Yii ma jako `Action::getUniqueId()`.

Pomiar w YQM-27 ([`roadmap.md`](../plan/roadmap.md), tabela ryzyk), PHP 8.1 i 8.4, MySQL i PostgreSQL:
- pierwsza ramka aplikacji, licząc od domknięcia strażnika w `Recorder::record()`, stoi na pozycji 7–24, a przy zapytaniach ładujących schemat tabeli do 29;
- 200 wywołań `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30)` kosztuje 0,08–0,3 ms, czyli wariant pierwszy z [`04-kontekst-wpisu.md`](../plan/04-kontekst-wpisu.md);
- pomiar po przeglądzie kodu YQM-27..29: lista `GridView` z `with()` zagnieżdżonym na dwa poziomy stawia pierwszą ramkę aplikacji na pozycji 31, a z `via()` na 32, na MySQL i PostgreSQL; każdy kolejny poziom `with()` dodaje 5 ramek. Przy stosie 80 ramek 200 wywołań kosztuje 0,20–0,23 ms przy granicy 30, 0,38–0,46 ms przy 64 i 0,50–0,58 ms bez granicy (PHP 8.1 i 8.4);
- przed kontrolerem skrypt wejściowy `index.php` jest jedyną ramką spoza vendor i stoi na pozycji 23–25 albo 33–37;
- źródło MongoDB (YQM-38, `yii2-mongodb` 3.0.4, ext 2.5.2) liczy od domknięcia strażnika w metodzie zdarzenia końca `mongodb\Recorder`: pierwsza ramka aplikacji stoi na 7 (`Collection::insert()`, `getMore` z `batch()`), 10 (`find()->one()`), 11 (`find` z `batch()`), 22 (`count` i `find` z `ActiveDataProvider` w `GridView`), a z `with()` na dwa poziomy na 27 i 32. Każdy poziom `with()` dodaje 5 ramek, jak w SQL, więc granica 64 obowiązuje dla obu źródeł.

Pakiet nie ma jeszcze wydania (brak tagów w repozytorium), więc zmiana publicznych pól `QueryBatch` nie łamie opublikowanego API.

## Decyzja

Wpis dostaje pole `caller`: najwyżej trzy ramki aplikacji z pierwszych 64 ramek śladu, jako `ścieżka:linia` względem korzenia projektu, czyli katalogu pakietu głównego Composera. Ramka aplikacji leży pod korzeniem projektu, poza katalogiem vendor Composera, poza `src/` pakietu i nie jest skryptem wejściowym. Nagłówek dostaje `route` zamiast `module`, `controller` i `action`. Obie zmiany wchodzą razem jako `v: 2`. Zdanie ze spec 02 §6 cytowane wyżej przestaje obowiązywać.

## Konsekwencje

**Pozytywne:** wpis wskazuje miejsce wywołania bez zmiany kodu aplikacji. Koszt mieści się w budżecie z [spec 00 §6](../spec/00-przeglad-i-zakres.md#6-kryteria-sukcesu) bez flagi konfiguracji. `route` da się porównać wprost z trasą w logach Yii.

**Negatywne:**
- Ścieżki względne ujawniają układ katalogów aplikacji. Nazwy plików są kodem aplikacji, jak nazwy tabel w `query`.
- `[]` nie odróżnia „brak ramki aplikacji w granicach 64” od „zapytanie wystawił framework”. Ścieżka, na której Yii kładzie ponad 60 ramek między zapytaniem a kodem aplikacji, np. `with()` zagnieżdżone na więcej niż osiem poziomów w liście `GridView`, daje `[]`, choć kod aplikacji ją wywołał.
- Zapytanie wystawione w kodzie najwyższego poziomu samego skryptu wejściowego dostaje `[]`, bo ta ramka jest wykluczona.
- Adapter czytający `$batch->module`, `->controller` albo `->action` przestaje działać. Przed wydaniem to koszt zerowy, po wydaniu byłby zmianą łamiącą.
- Pakiet wymaga Composera 2.1 (`composer-runtime-api` `^2.1`), bo korzeń projektu bierze z `Composer\InstalledVersions::getRootPackage()`, a klucz `install_path` pakiet główny ma dopiero od Composera 2.1.

**Wymagania:** katalog vendor, korzeń projektu, `src/` pakietu i skrypt wejściowy liczone raz na proces; ramki porównywane prefiksami, bez `realpath()` na każdą ramkę. Skrypt wejściowy to pierwszy plik z `get_included_files()`, który nie jest plikiem `auto_prepend_file`: kolejność obu zależy od SAPI, bo CLI wpisuje skrypt główny przed plikiem prepend. Korzeń projektu z `InstalledVersions::getRootPackage()`, nie z katalogu nad vendor, bo `vendor-dir` może leżeć głębiej, np. `lib/vendor`. Ślad brany w `Recorder::record()` wewnątrz strażnika i po sprawdzeniu, że kolektor przyjmuje wpisy.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Pełny ślad wywołań we wpisie | Kilkadziesiąt ramek na wpis. Już 200 zatrzymanych surowych śladów przy `N` = 30 to 1,3–3,0 MB według pomiaru YQM-27, ponad budżet 2 MB ze spec 03 §4; pełny ślad waży więcej |
| `caller` za flagą konfiguracji | Pomiar dał wariant pierwszy. Flaga dokłada stan `caller: null` i kod bez potrzeby |
| `N` = 30, podłoga 29 z YQM-27 plus jedna ramka | Lista `GridView` z `with()` na dwa poziomy stawia pierwszą ramkę aplikacji na 31–32, więc zwykły widok listy dawał `[]`. Zapas jednej ramki znikał też przy każdej łatce Yii, która dokłada ramkę |
| Bez granicy (`N` = 0) | Koszt rośnie z głębokością stosu bez górnej granicy, a rekurencja w kodzie aplikacji albo w szablonach daje stosy setek ramek. Ramki za 64 nie pojawiły się w żadnym zmierzonym przypadku |
| Konfigurowalne `N` | `N` wynika z głębokości stosu Yii, nie z aplikacji. Za małe po cichu daje `[]`, więc ustawienie byłoby pułapką |
| Ścieżki bezwzględne | Wynoszą do paczki układ katalogów serwera i nazwę użytkownika, wbrew gwarancji ze spec 02 §4 |
| Skrypt wejściowy jako ramka aplikacji | Jest na dnie każdego stosu i nie mówi, skąd zapytanie. W pomiarze ta sama ścieżka przed kontrolerem dawała raz `[index.php:24]`, raz `[]` |
| Katalog vendor z aliasu `@vendor` | Alias zależy od `vendorPath` w konfiguracji aplikacji. Źle ustawiony daje po cichu `[]` w każdym wpisie, co YQM-27 pokazał na aplikacji testowej |
| Korzeń projektu jako katalog nad vendor | Przy `"vendor-dir": "lib/vendor"` korzeń wypada na `lib/`, a kod aplikacji poza nim daje `[]` |
| `caller` i `route` jako dwa osobne podbicia `v` | Między commitami `v: 2` znaczyłoby dwie różne rzeczy, a wiersze z obu okien trafiają do jednego pliku JSON Lines |

## Kiedy wrócić do tej decyzji

- Gdy aktualizacja Yii pogłębi stos i pierwsza ramka aplikacji najgłębszej zmierzonej ścieżki (lista z `with()` na dwa poziomy, dziś 31–32) przejdzie za pozycję 63; test ścieżek aplikacyjnych w `tests/Integration/Yii/` to wykrywa.
- Gdy źródło MongoDB zacznie brać ślad w innym miejscu niż metoda zdarzenia końca `mongodb\Recorder`: `N` trzeba zmierzyć od nowa.
- Gdy Composer zmieni API `InstalledVersions` albo pakiet będzie instalowany bez Composera.
- Przy pierwszym wydaniu: od niego każda zmiana pól nagłówka jest zmianą łamiącą.
