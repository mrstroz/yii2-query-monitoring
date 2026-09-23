# Roadmap

## Stan na dziś

| Pole | Wartość |
|---|---|
| **Etap** | E4. Źródło MongoDB, 8 z 10 |
| **Ostatnio ukończone** | [YQM-38](05-mongodb.md): granica `caller` 64 dla MongoDB potwierdzona pomiarem, najgłębsza ścieżka (`GridView` z `with()` na dwa poziomy) na pozycji 32 ([ADR 0009](../adr/0009-caller-i-route-w-formacie-v2.md)); `distinct` dołączył do poleceń, których odpowiedzi pakiet nie czyta |
| **Następne** | [YQM-39](05-mongodb.md): CI z minimalnymi i bieżącymi `ext-mongodb` i `yii2-mongodb` oraz aplikacja konsumenta bez MongoDB |

Tę tabelę podmienia ten, kto kończy zadanie. To jedyne miejsce, w które trzeba zajrzeć na początku sesji.

## Etapy

| Etap | Plik | Cel | Co działa na końcu | Postęp |
|---|---|---|---|---|
| **E0** | [01-fundament-i-sql](01-fundament-i-sql.md) | Pakiet, kolektor, `Command`, cykl życia HTTP | Aplikacja z MySQL i PostgreSQL daje paczkę do jawnego adaptera, wyjątek nie gubi paczki | 12/12 |
| **E1** | [02-adapter-plikowy](02-adapter-plikowy.md) | Adapter plikowy z rotacją | Paczki w `runtime/logs`, bezpieczne przy 8 procesach | 6/6 |
| **E2** | [03-testy](03-testy.md) | Architektura i konwencje testów | Cztery testsuite'y, konwencje spisane i zastosowane, żaden scenariusz nie zniknął | 8/8 |
| **E3** | [04-kontekst-wpisu](04-kontekst-wpisu.md) | Kontekst wpisu i limity | Paczka `v: 2` z `route` w nagłówku i `caller` we wpisie, `maxQueryLength` 8192, limity paczki potwierdzone pomiarem | 4/4 |
| **E4** | [05-mongodb](05-mongodb.md) | Źródło MongoDB | Jedna paczka z wpisami SQL i MongoDB | 8/10 |
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
| `yii\mongodb\Connection` nie daje dostępu do `Manager` bez podmiany klasy | Sonda, pytania 1 i 2 w [ustaleniach](05-mongodb.md#ustalenia-do-yqm-32). Rozstrzygnięte w YQM-32 (ext 2.5.2, yii2-mongodb 3.0.4, MongoDB 7): `addSubscriber()` na publicznym `$manager` w `EVENT_AFTER_OPEN` działa, połączenie otwarte wcześniej ma już `$manager`, `open()` po `close()` daje nowy `Manager` i nowe zdarzenie, drugie `addSubscriber()` tego samego obiektu nic nie zmienia. Wspierane `yii2-mongodb` 3.0.4+ (3.0.3 woła `Cursor::getId(true)`, które ext 2.0 odrzuca) i `ext-mongodb` 1.20.1+; minimum potwierdza YQM-39 | YQM-32, E4 |
| Subskrybent jednego `Manager` dostaje zdarzenia innego `Manager` o tej samej konfiguracji, bo sterownik zbiera subskrybentów po współdzielonym kliencie libmongoc: podwójne wpisy albo wpisy połączenia spoza listy | Sonda, pytanie 3. Rozstrzygnięte w YQM-32: tak, przy identycznym napisie DSN i identycznych tablicach `options` i `driverOptions`; każda różnica, także kolejność kluczy albo `disableClientPersistence => false`, oddziela klienta. Jeden subskrybent na klucz klienta, podpinany przez każde id z listy, `conn` pierwszego id ([spec 01 §1](../spec/01-zbieranie-danych.md#1-komponent-i-konfiguracja)); polecenia połączenia spoza listy na wspólnym kliencie są wpisami połączenia z listy, gdy jego `Manager` istnieje ([ADR 0010](../adr/0010-subskrybent-na-manager-polaczenia-mongodb.md)). Testy obu przypadków w YQM-35 | YQM-32, E4; YQM-35 |
| Dokumenty poleceń `yii2-mongodb` nie pasują do sekcji z [spec 02 §4](../spec/02-format-paczki.md#4-normalizacja) | Sonda, pytanie 4. YQM-32: `find` niesie `filter`, `sort`, `limit`, `skip`; `update` warunek w `updates[].q` i zmianę w `updates[].u`; `delete` w `deletes[].q`; `count` i `findAndModify` w `query`; `aggregate` w `pipeline`; `insert` w `documents`; `getMore` i `killCursors` kolekcję w `collection` i `killCursors`. Każde polecenie ma `$db` i `lsid`. W trakcie żądania sterownik sam wysyła tylko `killCursors` porzuconego kursora, `hello` nie jest zgłaszane. Poprawka spec 02 §4 zrobiona w YQM-34: tabela poleceń i ich sekcji | YQM-34, E4 |
| Ślad brany w procedurze zdarzenia końca potrzebuje innej granicy niż 64 z [ADR 0009](../adr/0009-caller-i-route-w-formacie-v2.md) | Sonda, pytanie 5. YQM-32, liczone od domknięcia strażnika w procedurze zdarzenia końca: pierwsza ramka aplikacji na 7 (`Collection::insert()`, `getMore` z `batch()`), 10 (`ActiveRecord::find()->one()`), 11 (`find` z `batch()`) i 22 (`count` i `find` z `ActiveDataProvider` w `GridView`), w granicy 64. YQM-38: `with()` na dwa poziomy w `GridView` daje 27 i 32, każdy poziom +5, granica 64 zostaje (ADR 0009) | YQM-38, E4 |
| Pojedynczy serwer MongoDB nie zwraca `writeConcernError`, więc YQM-36 nie ma czego testować | Sonda, pytanie 6. YQM-32: `w: 2` i tag na pojedynczym serwerze dają `CommandFailed` z kodem 2, a fail point `failCommand` z `writeConcernError` wymaga `enableTestCommands`, więc compose i CI uruchamiają `mongod` z tym parametrem. Duplikat `_id` daje `writeErrors` z kodem 11000 w `CommandSucceeded`, fail point przy duplikacie daje oba naraz, zły operator w filtrze daje `CommandFailed` z kodem 2 | YQM-36, E4 |
| `getCommand()` i `getReply()` zamieniają cały BSON polecenia i odpowiedzi na obiekty PHP, więc duży `insert` albo `find` kosztuje proporcjonalnie do danych | Dokument i odpowiedź idą do `mongodb\Recorder` jako domknięcia: dokument jest czytany tylko wtedy, gdy kolektor przyjmie wpis, a odpowiedź tylko dla poleceń innych niż `find`, `getMore` i `aggregate` (YQM-35, YQM-36). Koszt w teście wydajności | E6 |
| Normalizator literałów kosztuje więcej niż 5% czasu | Pomiar całości z normalizacją | E6 |

## Czego w planie nie ma

Lista w [spec 00 §4](../spec/00-przeglad-i-zakres.md#4-poza-zakresem-wersji-1). Agregaty i próbkowanie nie wrócą bez zmiany [ADR 0002](../adr/0002-plaska-lista-zamiast-agregatow.md). Rozpoznawanie jobów kolejki wymaga zmiany [ADR 0007](../adr/0007-zadanie-konsolowe-to-jeden-proces.md).
