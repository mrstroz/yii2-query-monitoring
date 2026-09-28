# E5. Konsola i joby

**Cel:** paczki z `php yii ...` i z każdej próby joba, porcjowane po limitach, z `seq`, metadanymi joba i wykluczeniami tras.

**Koniec etapu:** długie zadanie z 1200 krótkimi zapytaniami poniżej limitu rozmiaru i czasu daje trzy paczki z `seq` 1, 2, 3 i wspólnym `id`, a krótkie zadanie daje jedną paczkę przy zakończeniu. Worker `yii2-queue` z wykluczonym listenerem daje tylko paczki jobów, po jednej serii na próbę. Obowiązkowe scenariusze odbioru: dokładnie 500 operacji wywołuje adapter, gdy proces nadal działa; podział po `maxBatchBytes` z bieżącym wpisem w następnej paczce; podział po 30 sekundach sprawdzany przy kolejnej operacji; reszta wysłana w shutdown; bezczynny proces bez wysyłki; pliki adaptera plikowego utworzone przez użytkownika serwera WWW są zapisywalne przez użytkownika konsoli i odwrotnie; rotacja przy plikach obu użytkowników nie niszczy kopii archiwalnych i nie zostawia bieżącego pliku rosnącego bez ograniczeń.

**Zależności zewnętrzne:** brak. `yiisoft/yii2-queue` tylko w `require-dev` i `suggest`.

## Zadania

- [x] (^) **YQM-46** Konteksty, API joba, wykluczenia i adapter plikowy między użytkownikami opisane w ADR i spec
      Spec: [01 §5](../spec/01-zbieranie-danych.md#5-konsola-i-joby) · ADR: [0012](../adr/0012-konteksty-http-console-job.md)
      Gotowe, gdy: ADR-0012 i ADR-0013 są przyjęte, ADR-0007 ma status „Zastąpiony przez ADR-0012”, a każde zachowanie z zadań YQM-47..55 ma zdanie w spec 00–03.
      Zmienia też ADR-0003, ADR-0005, spec 02 §1, §3, §5, spec 03 §2, §3, brief i roadmapę.

- [x] (^) **YQM-47** Stos kontekstów między źródłami a paczką, bez zmiany zachowania HTTP
      Spec: [01 §5.1](../spec/01-zbieranie-danych.md#51-konteksty) · ADR: [0012](../adr/0012-konteksty-http-console-job.md) · Zależy od: YQM-46
      Gotowe, gdy: cały dotychczasowy zestaw testów przechodzi, a zmienione testy dotyczą tylko sygnatur `createSources()`, `createCollector()` i blokady rekurencji przeniesionej do stosu.
      Recordery SQL i MongoDB trzymają dziś jeden kolektor na proces (`src/sql/Source.php:65`, `src/mongodb/Source.php:100`), więc nowy kontekst wymaga warstwy, którą pytają przy każdym wpisie.

- [x] (=) **YQM-48** Porcjowanie kontekstu konsoli
      Spec: [01 §5.2](../spec/01-zbieranie-danych.md#52-porcjowanie), [02 §5](../spec/02-format-paczki.md#5-limity) · Zależy od: YQM-47
      Gotowe, gdy: `ConsoleBatchingTest` przechodzi na MySQL i PostgreSQL.
      Scenariusze: 1200 zapytań daje 500/500/200 z `seq` 1–3 i wspólnym `id`, a 500. zapytanie wywołuje adapter przed końcem procesu; krótka komenda daje jedną paczkę; podział po bajtach z bieżącym wpisem w następnej paczce; za duży wpis w `dropped` wskazanej paczki; podział po czasie przy kolejnej operacji; bezczynność bez wysyłki; wyjątek adaptera przy paczce 2 zostawia lukę w `seq` i jeden `Yii::error`.

- [x] (=) **YQM-49** Format `v: 3` z typem `job` i metadanymi joba w nagłówku
      Spec: [02 §1](../spec/02-format-paczki.md#1-nagłówek) · ADR: [0012](../adr/0012-konteksty-http-console-job.md) · Zależy od: YQM-47
      Gotowe, gdy: testy `QueryBatch` i kolektora pokazują klucze w kolejności z spec, obcięcie metadanych do 255 bajtów i nagłówek z `job` liczony w `maxBatchBytes`.

- [x] (^) **YQM-50** `beginJob()` i `endJob()` z uchwytem dla jobów wykonywanych po kolei
      Spec: [01 §5.3](../spec/01-zbieranie-danych.md#53-granice-joba), [01 §5.5](../spec/01-zbieranie-danych.md#55-finalizacja-procesu) · Zależy od: YQM-48, YQM-49
      Gotowe, gdy: `JobContextTest` przechodzi na MySQL i PostgreSQL, z MongoDB.
      Scenariusze: dwa kolejne joby bez mieszania; duży job z wieloma paczkami; zakończenie po sukcesie i po wyjątku; ponowienie z nowym `id`; podwójne i obojętne `endJob()`; shutdown z otwartym jobem bez podwójnej wysyłki; uchwyt obojętny bez bootstrapu i przy `enabled: false`; wpisy SQL i MongoDB w nowym kontekście po zmianie paczki i joba.

- [x] (=) **YQM-51** Zagnieżdżenie jobów i limit głębokości stosu
      Spec: [01 §5.1](../spec/01-zbieranie-danych.md#51-konteksty) · Zależy od: YQM-50
      Gotowe, gdy: testy zagnieżdżenia w `JobContextTest` przechodzą: job w żądaniu, komendzie i jobie, powrót rodzica, `endJob()` rodzica przed dzieckiem, 16. `beginJob()` obojętny.
      Limit chroni pamięć workera, którego konsument nie zamyka jobów w `finally`.

- [x] (=) **YQM-52** Wykluczenia tras według typu kontekstu
      Spec: [01 §5.4](../spec/01-zbieranie-danych.md#54-wykluczenia-tras) · ADR: [0013](../adr/0013-wykluczenia-tras-per-typ-kontekstu.md) · Zależy od: YQM-50
      Gotowe, gdy: `ExcludedRoutesTest` przechodzi: wykluczone żądanie z zapytaniem z bootstrapu nie daje paczki, a wykluczony listener daje tylko paczki jobów.

- [x] (=) **YQM-53** Behavior dla `yii2-queue`
      Spec: [01 §5.3](../spec/01-zbieranie-danych.md#53-granice-joba) · ADR: [0012](../adr/0012-konteksty-http-console-job.md) · Zależy od: YQM-50, YQM-52
      Gotowe, gdy: prawdziwa kolejka z driverem `file` w `queue/run` z `--isolate=0` i `--isolate=1` daje paczkę joba po sukcesie, dwie próby z różnym `id` po błędzie z ponowieniem, paczkę joba oznaczonego `handled` i paczkę joba zakończonego `exit()` w procesie potomnym.
      Dolna granica `yiisoft/yii2-queue` 2.3.7 potwierdzona biegiem `--prefer-lowest` 2026-09-28 (791 testów). `tests/consumer/` sprawdza instalację bez niej.

- [x] (=) **YQM-54** Adapter plikowy dla użytkownika serwera WWW i konsoli
      Spec: [03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) · ADR: [0005](../adr/0005-adapter-plikowy-z-blokada-i-utrata-paczki.md) · Zależy od: YQM-46
      Gotowe, gdy: `FileAdapterSharedTest` przechodzi, a `tests/multiuser/run.sh` z dwoma użytkownikami we wspólnej grupie zapisuje i rotuje bez błędu uprawnień, a przy katalogu bez zapisu dla grupy kopie zostają całe i plik nie rośnie ponad `maxSize` i jedną paczkę.
      `FileAdapterSharedTest` sprawdza to w `composer test` bez roota przez katalog `0555`. Skrypt działa poza PHPUnit, jak `tests/consumer/`, i ma krok w CI.

- [x] (=) **YQM-55** README: komenda, listener, `yii2-queue`, własny konsument
      Spec: [01 §5](../spec/01-zbieranie-danych.md#5-konsola-i-joby) · Zależy od: YQM-50, YQM-51, YQM-52, YQM-53, YQM-54
      Gotowe, gdy: `ReadmeExampleTest` wykonuje przykłady konfiguracji długiej komendy, wykluczonego listenera z behavior i konsumenta z `try/finally`.
      README tłumaczy ponowienia, zagnieżdżenie, limit 16 poziomów, bezczynność i ograniczenia shutdown.

- [x] (=) **YQM-56** Odbiór etapu na macierzy
      Spec: [01 §5](../spec/01-zbieranie-danych.md#5-konsola-i-joby) · Zależy od: YQM-46, YQM-47, YQM-48, YQM-49, YQM-50, YQM-51, YQM-52, YQM-53, YQM-54, YQM-55
      Gotowe, gdy: `composer test`, `composer stan` i `composer cs` przechodzą na PHP 8.1 i 8.4 z MySQL i PostgreSQL, a `tests/multiuser/` i `tests/consumer/` bez `yii2-queue` i MongoDB przechodzą.
