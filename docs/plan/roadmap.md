# Roadmap

## Stan na dziś

| Pole | Wartość |
|---|---|
| **Etap** | E4. Źródło MongoDB, 0 z 10, zadania spisane |
| **Ostatnio ukończone** | [YQM-30](04-kontekst-wpisu.md): `maxBatchBytes` 256 KB i `maxEntries` 500 zostają, na podstawie pomiaru na aplikacji rulewave (pozycja 3b w [spec 00 §9](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie) zamknięta). Test szczytu pamięci przy pełnej paczce i domyślne limity komponentu ze stałych kolektora przeszły do [E6](07-wydajnosc-i-odbior.md) |
| **Następne** | [YQM-31](05-mongodb.md): MongoDB 7 w środowisku testowym. Po nim sonda YQM-32 z otwartej kwestii 2 w [spec 00 §9](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie) |

Tę tabelę podmienia ten, kto kończy zadanie. To jedyne miejsce, w które trzeba zajrzeć na początku sesji.

## Etapy

| Etap | Plik | Cel | Co działa na końcu | Postęp |
|---|---|---|---|---|
| **E0** | [01-fundament-i-sql](01-fundament-i-sql.md) | Pakiet, kolektor, `Command`, cykl życia HTTP | Aplikacja z MySQL i PostgreSQL daje paczkę do jawnego adaptera, wyjątek nie gubi paczki | 12/12 |
| **E1** | [02-adapter-plikowy](02-adapter-plikowy.md) | Adapter plikowy z rotacją | Paczki w `runtime/logs`, bezpieczne przy 8 procesach | 6/6 |
| **E2** | [03-testy](03-testy.md) | Architektura i konwencje testów | Cztery testsuite'y, konwencje spisane i zastosowane, żaden scenariusz nie zniknął | 8/8 |
| **E3** | [04-kontekst-wpisu](04-kontekst-wpisu.md) | Kontekst wpisu i limity | Paczka `v: 2` z `route` w nagłówku i `caller` we wpisie, `maxQueryLength` 8192, limity paczki potwierdzone pomiarem | 4/4 |
| **E4** | [05-mongodb](05-mongodb.md) | Źródło MongoDB | Jedna paczka z wpisami SQL i MongoDB | 0/10 |
| **E5** | [06-konsola](06-konsola.md) | Zadania konsolowe | Wiele paczek z `seq` w jednym procesie | – |
| **E6** | [07-wydajnosc-i-odbior](07-wydajnosc-i-odbior.md) | Test wydajności, dokumentacja | Narzut w progu, limity potwierdzone, README pakietu | – |

40 zadań spisanych. Jedno zadanie to jedna sesja i jeden commit.

## Dlaczego w tej kolejności

E0 idzie pierwsze, bo podmiana klasy `Command` to założenie, na którym stoi cała reszta. Jeśli omija jakąś ścieżkę Yii albo nie widzi cache, trzeba to wiedzieć przed adapterem i MongoDB. Dlatego YQM-10 jest w E0, nie w etapie testów.

E1 przed resztą, bo bez adaptera plikowego nie da się obejrzeć paczek w prawdziwej aplikacji, a MongoDB wymaga sondy dostępu do `Manager`, która może zmienić spec 01 §3. Lepiej mieć działający pakiet dla aplikacji tylko z SQL, zanim ta sonda się rozstrzygnie.

E2 przed E4 i E5, bo zestaw testów po dwóch etapach przestał mieć jedną zasadę podziału, a MongoDB i konsola dokładają dwa nowe obszary testów. Uporządkowanie po nich kosztowałoby tyle samo pracy w trzech miejscach zamiast w jednym, więc nowe źródło danych i tryb konsolowy zaczynają już w jednej strukturze.

E3 przed E4, bo zmienia kontrakt paczki: `v: 2` z `caller` we wpisie i `route` w nagłówku. MongoDB dokłada drugie źródło wpisów, więc wchodzi od razu w nowym formacie, zamiast przerabiać dwa źródła naraz.

E5 po E4, bo limity konsoli i `seq` mają sens dopiero z oboma źródłami. E6 na końcu, bo test wydajności mierzy całość z normalizacją i zapisem, a limity w spec są wartościami początkowymi do korekty tym pomiarem.

Efekt uboczny: do końca E3 pakiet nie ma MongoDB, więc demo w aplikacji z MongoDB pokaże tylko połowę zapytań.

## Ryzyka wyciągnięte przed kolejkę

| Ryzyko | Co robimy | Kiedy |
|---|---|---|
| Podmiana klasy `Command` omija jakąś ścieżkę Active Record albo raportuje trafienie w cache | Test integracyjny z AR i `Connection::cache()` | YQM-10, E0 |
| `exit(1)` z `ErrorHandler` gubi paczkę mimo callbacku shutdown | Test z nieobsłużonym wyjątkiem w osobnym procesie | YQM-8, E0 |
| `Command::prepare()` łączy `open()` i `pdo->prepare()`, więc pomiar samego `prepare` może wymagać nadpisania całej metody i psuć wiązanie parametrów lub konwersję wyjątków | Test wiązania, konwersji wyjątków i ponownego wykonania przygotowanego polecenia. Rozstrzygnięte w YQM-5: kopia metody z zegarem wokół `pdo->prepare()` | YQM-5, E0 |
| Blokada `.lock` nie chroni rotacji przy wielu procesach i dwie rotacje nadpisują `.1` | Test 8 procesów z rozliczeniem paczek utraconych przez zajętą blokadę. Rozstrzygnięte w YQM-18: blokada chroni rotację, bez niej test pada. Przy sztucznym obciążeniu (8 procesów, 2 ms przerwy, `maxFiles` 481) przepada około 40% paczek; rzeczywisty odsetek mierzy E6 | YQM-18, E1 |
| `debug_backtrace` dla `caller` przy każdym zapytaniu zjada budżet 5% z [spec 00 §6](../spec/00-przeglad-i-zakres.md#6-kryteria-sukcesu) | Pomiar na PHP 8.1 i 8.4 przed zmianą formatu; przy koszcie powyżej 2 ms na 200 wywołań `caller` idzie za flagą domyślnie wyłączoną. Rozstrzygnięte w YQM-27 (PHP 8.1.34 i 8.4.25, MySQL i PostgreSQL): pierwsza ramka aplikacji stoi na pozycji 7 (`createCommand()`), 9 (`find()->one()`) i 24 (`COUNT` z `ActiveDataProvider` w `GridView`), a z zapytaniami ładującymi schemat bez `schemaCache` do 29 (MySQL; PostgreSQL 27), więc podłoga 29 i `N` = 30 od domknięcia w `Recorder::record()`. Przy `N` = 30 jedno wywołanie kosztuje 0,40–1,5 µs, a 200 wywołań w jednym żądaniu 0,08–0,3 ms; pełny stos (mierzony przy stosie do 33 ramek) najwyżej 0,34 ms. Dwa niezależne przebiegi różnią się do dwóch razy, ale każdy wynik leży co najmniej trzy razy poniżej 1 ms — wariant pierwszy. Pamięć zmierzona jako górna granica, przy 200 zatrzymanych surowych śladach naraz: 1,3–3,0 MB według `memory_get_usage()` i +2 MB `memory_get_peak_usage(true)` (raz +4 MB). To więcej niż 2 MB ze [spec 03 §4](../spec/03-adaptery-wyjsciowe.md#4-test-wydajności), ale to nie jest koszt pakietu: `caller` nie trzyma śladu, tylko najwyżej trzy ramki `plik:linia` jako tekst; ile to waży w pełnej paczce, mierzy YQM-30. Zapytania przed kontrolerem (`DbCache` reguł `UrlManager`) w 100% nie mają ramki aplikacji poza skryptem wejściowym, który stoi na pozycji 23 (SELECT) i 25 (INSERT), czyli w granicach `N`, a przy zapytaniach ładujących schemat tabeli cache na 33–37, poza ówczesnym `N` = 30. Po przeglądzie kodu YQM-27..29: lista `GridView` z `with()` zagnieżdżonym na dwa poziomy stawia pierwszą ramkę aplikacji na 31, a z `via()` na 32, poza `N` = 30, i każdy kolejny poziom `with()` dodaje 5 ramek; `N` podniesione do 64 ([ADR 0009](../adr/0009-caller-i-route-w-formacie-v2.md)). Przy stosie 80 ramek 200 wywołań kosztuje 0,38–0,46 ms przy `N` = 64, wobec 0,20–0,23 ms przy 30 i 0,50–0,58 ms bez granicy (PHP 8.1.34 i 8.4.25), nadal wariant pierwszy | YQM-27, E3 |
| Pełna paczka z `caller` i `query` do 8192 znaków przekracza 2 MB szczytu pamięci z [spec 03 §4](../spec/03-adaptery-wyjsciowe.md#4-test-wydajności) | `maxBatchBytes` i `maxEntries` z pomiaru na rzeczywistym ruchu; przy przekroczeniu mniej zapisywanych ramek `caller`. YQM-30: limity zostają, `caller` to około jednej trzeciej wpisu; pomiar pamięci przy pełnej paczce przeszedł do E6 | YQM-30, E3; pamięć E6 |
| Proces z drugim Composerem, np. `codecept.phar` albo narzędzie z własnym vendor, ładuje swój `ClassLoader` przed aplikacją: katalog vendor dla `caller` wskazuje wtedy na vendor narzędzia, więc ramki Yii aplikacji przechodzą za ramki aplikacji. Korzeń projektu zostaje poprawny, bo Composer rejestruje loader projektu na początku listy | Poza celem z [spec 00 §7](../spec/00-przeglad-i-zakres.md#7-środowiska), który zakłada PHP-FPM z jednym autoloaderem; zgłoszone w przeglądzie kodu YQM-27..29 i odłożone. Wracamy, gdy ktoś zgłosi `caller` pełen ramek `vendor/yiisoft` | Poza kolejką |
| `yii\mongodb\Connection` nie daje dostępu do `Manager` bez podmiany klasy | Sonda, pytania 1 i 2 w [ustaleniach](05-mongodb.md#ustalenia-do-yqm-32) | YQM-32, E4 |
| Subskrybent jednego `Manager` dostaje zdarzenia innego `Manager` o tej samej konfiguracji, bo sterownik zbiera subskrybentów po współdzielonym kliencie libmongoc: podwójne wpisy albo wpisy połączenia spoza listy | Sonda, pytanie 3; reguła pominięcia w spec 01 §1 przed YQM-35 | YQM-32, E4 |
| `getCommand()` i `getReply()` zamieniają cały BSON polecenia i odpowiedzi na obiekty PHP, więc duży `insert` albo `find` kosztuje proporcjonalnie do danych | Odpowiedź czytana tylko tam, gdzie niesie kod błędu (YQM-36); koszt w teście wydajności | YQM-36, E4; E6 |
| Normalizator literałów kosztuje więcej niż 5% czasu | Pomiar całości z normalizacją | E6 |

## Czego w planie nie ma

Lista w [spec 00 §4](../spec/00-przeglad-i-zakres.md#4-poza-zakresem-wersji-1). Agregaty i próbkowanie nie wrócą bez zmiany [ADR 0002](../adr/0002-plaska-lista-zamiast-agregatow.md). Rozpoznawanie jobów kolejki wymaga zmiany [ADR 0007](../adr/0007-zadanie-konsolowe-to-jeden-proces.md).
