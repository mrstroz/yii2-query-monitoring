# E3. Kontekst wpisu i limity

**Cel:** wpis ma mówić nie tylko, jakie zapytanie poszło do bazy, ale i skąd zostało wywołane, a nagłówek ma nieść akcję wejściową w jednym polu. Limity długości `query` i rozmiaru paczki dostają wartości z pomiaru zamiast wartości początkowych.

**Koniec etapu:** paczka z aplikacji testowej ma `v: 2`, w nagłówku `route` zamiast `module`, `controller` i `action`, a w każdym wpisie pole `caller`: ścieżki `plik:linia` kodu aplikacji tam, gdzie ramka aplikacji mieści się w granicach `N`, a `[]` w pozostałych; `maxQueryLength` ma wartość 8192, `maxBatchBytes` i `maxEntries` mają wartości z pomiaru, a koszt śladu wywołań jest zmierzony i zapisany.

**Zależności zewnętrzne:** wartości `maxBatchBytes` i `maxEntries` pochodzą z pomiaru na rzeczywistym ruchu, poza tym repozytorium. YQM-30 czeka na ten pomiar.

## Zadania

- [x] (=) **YQM-27** Pomiar kosztu śladu wywołań i głębokości stosu
      Spec: [00 §6](../spec/00-przeglad-i-zakres.md#6-kryteria-sukcesu)
      Gotowe, gdy: w tabeli ryzyk w [`roadmap.md`](roadmap.md) stoi wynik pomiaru na PHP 8.1 i 8.4, obejmujący koszt `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, N)` na pojedyncze wywołanie w mikrosekundach i dla 200 wywołań w jednym żądaniu, przyrost `memory_get_peak_usage(true)`, oraz pozycję pierwszej ramki aplikacji na czterech ścieżkach z [ustaleń](#ustalenia-do-yqm-27) — mierzony przy `N` użytym w pomiarze, a gdy podłoga wypadła głębiej, także przy podłodze — a wynik czasowy wskazuje jeden z trzech wariantów.
      Zadanie tylko mierzy i zapisuje liczby; decyzję o kształcie `caller` podejmuje YQM-28. To jest wejście do testu wydajności z E6, a nie jego wykonanie — scenariusza z [spec 03 §4](../spec/03-adaptery-wyjsciowe.md#4-test-wydajności) to zadanie nie uruchamia i nie buduje jego stanowiska, więc próg 5% mediany nie jest tu kryterium. Mikropomiar w testsuite `Yii`, bo wymaga bazy i aplikacji, a `Process` biegnie w poleceniu bez baz z [`tests/README.md`](../../tests/README.md), gdzie `failOnSkipped` by go wywrócił. Ścieżki, definicja ramki aplikacji i warianty rozstrzygnięcia stoją w [ustaleniach](#ustalenia-do-yqm-27) pod listą zadań.

- [x] (^) **YQM-28** Format `v: 2`: `caller` we wpisie i `route` w nagłówku
      Spec: [02](../spec/02-format-paczki.md) · ADR: 0009, powstaje w tym zadaniu ([rejestr](../adr/README.md)) · Zależy od: YQM-27
      Gotowe, gdy: paczka z aplikacji testowej ma `v: 2`, `route` równe `uniqueId` akcji wejściowej zamiast `module`, `controller` i `action`, oraz pole `caller` w każdym wpisie. Pomiar YQM-27 dał wariant pierwszy, więc flagi nie ma.
      Jedno zadanie, bo `v` nazywa jeden kontrakt: rozbicie na dwa commity daje `v: 2` znaczące dwie różne rzeczy, a wiersze z obu okien trafiają do jednego pliku JSON Lines i nie da się ich rozróżnić ([spec 02](../spec/02-format-paczki.md), zdanie otwierające).

  W zakresie zadania:

  - **ADR-0009** odwraca wykluczenie z [spec 02 §6](../spec/02-format-paczki.md#6-poza-zakresem) i nazywa w treści zdanie, które przestaje obowiązywać. Samo §6 też zostaje poprawione — inaczej specyfikacja przeczy polu, które właśnie dodała.
  - **`caller`:** najwyżej trzy ramki, ścieżki względne wobec katalogu głównego projektu, `plik:linia`, bez argumentów funkcji. `N` dobrane z pomiaru pozycji pierwszej ramki aplikacji z YQM-27, zapisane razem z powodem. Ścieżka bezwzględna wynosi do paczki układ katalogów serwera i nazwę użytkownika, co zderza się z gwarancją z końca [spec 02 §4](../spec/02-format-paczki.md#4-normalizacja).
  - **`caller: []` pozostaje stanem możliwym** i oznacza „nie znaleziono ramki aplikacji w granicach `N`”, a nie dowód, że kod aplikacji jej nie ma. Podłoga jest oszacowaniem ze zmierzonej próby ścieżek, a nie ograniczeniem głębokości stosu: `findWith`, `BatchQueryResult`, zachowania i domknięcia w `Connection::cache()` mogą być głębsze niż to, co mierzy YQM-27. Pole nie odróżnia obu stanów, bo rozróżnienie wymaga przejścia stosu do końca, czyli kosztu, który `N` ogranicza.
  - **`caller: null` nie powstał:** był przewidziany tylko dla trzeciego wariantu, z flagą wyłączającą zbieranie. Pomiar dał pierwszy, więc `caller` jest zawsze listą.
  - **Ramka skryptu wejściowego** (`web/index.php`, `yii`) nie mówi nic o miejscu zapytania. ADR-0009 ją wyklucza, bo w pomiarze YQM-27 ta sama ścieżka przed kontrolerem dawała raz `[index.php:24]`, raz `[]`.
  - **`route`:** reguła `null` przed routingiem przechodzi z trzech starych pól jeden do jednego. Dla `type: console` `route` niesie `uniqueId` akcji konsolowej, co dotyczy [spec 01 §5](../spec/01-zbieranie-danych.md#5-zadanie-konsolowe) i późniejszego E5.
  - **Rachunek bajtów nagłówka** z ostatniego akapitu [spec 02 §5](../spec/02-format-paczki.md#5-limity) przeliczony dla nowego zestawu pól. Zasada, że pierwszy wpis przekraczający limit kończy przyjmowanie, zostaje bez zmian.
  - `"v":1` znika z przykładu w [spec 02 §3](../spec/02-format-paczki.md#3-przykład) i z asercji w `tests/`.

- [ ] (=) **YQM-29** `maxQueryLength` 8192
      Spec: [01 §1](../spec/01-zbieranie-danych.md#1-komponent-i-konfiguracja) · [00 §9](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie) · Zależy od: YQM-28
      Gotowe, gdy: wartość początkowa `maxQueryLength` to `8192`, a pozycja 3 w [spec 00 §9](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie) jest rozdzielona na trzy człony — `maxQueryLength` (rozstrzygnięty tutaj), `maxBatchBytes` i `maxEntries` (rozstrzyga pomiar na rzeczywistym ruchu, YQM-30), rotacja plików (nadal E6).
      2 KB obcina zapytania, które w rzeczywistej aplikacji mają kilka kilobajtów, a obcięty `query` nie daje się odtworzyć. W zakresie: zdanie „Wpis z `query` obciętym do `maxQueryLength` mieści się w `maxBatchBytes` przy wartościach początkowych” z [spec 02 §5](../spec/02-format-paczki.md#5-limity) zostaje prawdziwe: wpis z `query` długości 8192 daje paczkę około 8,5 KB wobec 256 KB. Zmienia się liczba takich wpisów w paczce — około 30 zamiast około 110 — więc przy długich zapytaniach `maxBatchBytes` kończy przyjmowanie dużo wcześniej niż `maxEntries`. To wejście do YQM-30, razem z bajtami `caller`, a nie powód do ruszania limitu paczki tutaj. Zdanie „Etap rozstrzyga otwartą kwestię 3” w [`07-wydajnosc-i-odbior.md`](07-wydajnosc-i-odbior.md) po rozdzieleniu wiersza dotyczy już tylko członu o rotacji i też wymaga poprawki.

- [ ] (=) **YQM-30** `maxBatchBytes` i `maxEntries` z pomiaru na rzeczywistym ruchu
      Spec: [01 §1](../spec/01-zbieranie-danych.md#1-komponent-i-konfiguracja) · [03 §4](../spec/03-adaptery-wyjsciowe.md#4-test-wydajności) · Zależy od: YQM-29
      **Blokada:** człon `maxBatchBytes` i `maxEntries` pozycji 3 w [spec 00 §9](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie), założony przez YQM-29. Rozstrzyga go pomiar rozmiaru paczki na rzeczywistym ruchu, po dodaniu `caller`.
      Gotowe, gdy: `maxBatchBytes` i `maxEntries` mają wartości początkowe z tego pomiaru, a paczka wypełniona do obu tych limitów wpisami z `caller` daje w jednym procesie przyrost `memory_get_peak_usage(true)` poniżej 2 MB z [spec 03 §4](../spec/03-adaptery-wyjsciowe.md#4-test-wydajności).
      Oba limity idą przez ten sam licznik i to samo `full`, więc dobranie jednego bez drugiego jest połową pomiaru. Szczyt pamięci przy pełnej paczce jest własnością jednego procesu, nie współbieżności, więc mierzy się go bez stanowiska z E6. Jeśli pomiar pokaże, że trzy ramki `caller` wypychają paczkę ponad rozsądny limit, to tutaj obniża się liczbę zapisywanych ramek — `N` z YQM-28 zostaje bez zmian, bo to osobna liczba o osobnym powodzie.

## Ustalenia do YQM-27

**Ramka aplikacji** (definicja pomiaru; w paczce obowiązuje [spec 02 §2](../spec/02-format-paczki.md#2-wpis)) to ramka spoza `vendor/` **i spoza katalogu samego pakietu**. Na produkcji pakiet leży w `vendor/mrstroz/yii2-query-monitoring/`, więc jego własne ramki odpadają razem z ramkami Yii. We własnym zestawie testów pakiet leży w `src/`, a kod grający rolę aplikacji — w `tests/`; bez wykluczenia katalogu pakietu pierwszą ramką spoza `vendor/` byłby `Command::prepare()` z `src/`, czyli pomiar dałby podłogę płytszą, niż zobaczy produkcja.

**Cztery ścieżki:**

| Ścieżka | Po co |
|---|---|
| `ActiveRecord::find()->one()` | podłoga |
| `createCommand()` | podłoga |
| zapytanie zliczające przez `ActiveDataProvider` | podłoga — najgłębsza realistyczna ścieżka aplikacyjna, wykonywana przy każdej liście w `GridView` |
| zapytanie wystawione bez udziału kodu aplikacji | kontrolna, podłogi nie podnosi; oczekiwany wynik to brak ramki aplikacji poza skryptem wejściowym dla wszystkich zapytań tej ścieżki |

**Podłoga `N`** to najgłębsza z trzech ścieżek aplikacyjnych. Czwarta sprawdza, że ślad nie przypisuje zapytania kodowi, który go nie wystawił. Skrypt wejściowy `tests/app/web/index.php` leży poza `vendor/` i poza katalogiem pakietu, więc w definicji pomiaru był ramką aplikacji na dnie każdego stosu, a YQM-28 wyklucza go z `caller` ([ADR 0009](../adr/0009-caller-i-route-w-formacie-v2.md)); kontroler, widok i model też nimi są. Dlatego stos tej ścieżki jest ustalony: zapytanie wystawia komponent rdzenia Yii, zanim sterowanie dojdzie do kontrolera, więc jedyną ramką z `tests/` jest skrypt wejściowy. YQM-27 zapisuje ścieżki wszystkich ramek spoza `vendor/` tej ścieżki jako dowód, że stos wygląda tak, jak opisano. Wynik to odsetek zapytań bez ramki aplikacji poza skryptem wejściowym, z oczekiwaniem 100%, oraz pozycja ramki skryptu wejściowego. Na jej podstawie ADR-0009 wykluczył skrypt wejściowy, więc ta ścieżka daje `caller: []`. Ścieżkę realizuje `yii\caching\DbCache` z rdzenia Yii jako cache reguł `UrlManager` z ładnymi adresami, włączany w aplikacji testowej przełącznikiem `QM_DB_CACHE`, z tabelą `qm_cache`: zapytanie pada w `Request::resolve()`, bez nowej zależności. Zapytanie musi paść **w trakcie żądania i przed kontrolerem**. Uchwyt rejestrowany przez sam pakiet tego nie da: finalizacja dzieje się w `EVENT_AFTER_REQUEST`, flaga „sfinalizowano” nigdy nie jest zerowana ([ADR 0003](../adr/0003-finalizacja-w-after-request-i-shutdown.md)), a wpis dodany po zamknięciu kolektora jest pomijany i nie zwiększa `dropped` ([spec 02 §5](../spec/02-format-paczki.md#5-limity)) — sonda zmierzyłaby brak danych i nie odróżniła go od braku ramki aplikacji, czyli tej samej dwuznaczności, dla której ta ścieżka powstała.

**Warianty rozstrzygnięcia.** `N` nigdy nie jest mniejsze niż podłoga. Tabela rozstrzyga tryb wejścia `caller`, nie podłogę. Progi są absolutnym przybliżeniem 5% dla żądania o medianie 100 ms, bo [spec 00 §6](../spec/00-przeglad-i-zakres.md#6-kryteria-sukcesu) daje ten budżet na cały pakiet — ślad, normalizację, serializację i zapis razem.

| Koszt 200 wywołań w jednym żądaniu | Co robi YQM-28 |
|---|---|
| poniżej 1 ms | `caller` wchodzi bez zmian, `N` równe podłodze, trzy ramki |
| 1–2 ms | koszt przy podłodze mieści się w progu → `caller` wchodzi z podłogą; nie mieści się → wiersz trzeci |
| powyżej 2 ms | `caller` za flagą konfiguracji, domyślnie wyłączoną; przy wyłączonej wpis ma `caller: null`. Jedyne wyjście, gdy koszt przy podłodze nadal przekracza próg |
