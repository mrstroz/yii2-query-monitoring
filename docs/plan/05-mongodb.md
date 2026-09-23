# E4. Źródło MongoDB

**Cel:** wpisy z `yii\mongodb\Connection` przez zdarzenia sterownika, z normalizacją filtrów i potoków, zbudowane z tych samych części co źródło SQL.

**Koniec etapu:** aplikacja z MySQL i MongoDB daje jedną paczkę z wpisami z obu baz, a `writeErrors` w udanym poleceniu daje `result: error`. Aplikacja tylko z SQL instaluje pakiet i działa bez `ext-mongodb` i `yiisoft/yii2-mongodb`.

**Zależności zewnętrzne:** brak.

## Zadania

- [x] (^) **YQM-31** MongoDB 7 w środowisku testowym: `ext-mongodb` w obrazie, usługa w `docker-compose.yml` i w CI, `yiisoft/yii2-mongodb` w `require-dev`, połączenie `mongodb` w aplikacji testowej
      Spec: [00 §7](../spec/00-przeglad-i-zakres.md#7-środowiska)
      Gotowe, gdy: test w `Integration/Yii` wykonuje `find` przez `yii\mongodb\Connection` aplikacji testowej lokalnie i w CI na PHP 8.1 i 8.4, `QM_MONGODB_DSN` jest ustawione w compose i w CI tak jak DSN SQL, więc przy `failOnSkipped` pełny bieg niczego nie pomija, a polecenie bez baz z [`tests/README.md`](../../tests/README.md) dostaje `-e QM_MONGODB_DSN=` i nadal przechodzi.
      Obraz ma dziś tylko `pdo_mysql` i `pdo_pgsql` (`docker/php/Dockerfile`), a w `vendor/` nie ma `yii2-mongodb`, więc sonda nie miałaby na czym działać.

- [x] (^) **YQM-32** Sonda: dostęp do `Manager`, zasięg subskrybenta, kształt poleceń i głębokość stosu
      Spec: [00 §9](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie) · ADR: 0010, powstaje w tym zadaniu ([rejestr](../adr/README.md)) · Zależy od: YQM-31
      Gotowe, gdy: pozycja 2 w spec 00 §9 jest rozstrzygnięta, ADR-0010 zapisuje sposób podpięcia i [podział klas](#podział-klas), spec 01 §3 i 00 §7 mówią, jak pakiet dochodzi do `Manager` i od jakich wersji `ext-mongodb` i `yii2-mongodb`, a odpowiedzi na sześć pytań z [ustaleń](#ustalenia-do-yqm-32) stoją w tabeli ryzyk w [`roadmap.md`](roadmap.md). Zakres poleceń, czas, pierwszeństwo kodów błędu i parowanie zdarzeń są już w spec 01 §3; sonda je poprawia tylko wtedy, gdy sterownik na to nie pozwala.
      Sonda nie dodaje kodu w `src/`. Pomiar stosu żyje w `Integration/Yii` jak `CallerProbeTest` z YQM-27.

- [ ] (=) **YQM-33** Instalacja źródła SQL wydzielona z `QueryMonitor` za interfejs źródła
      Spec: [01 §1](../spec/01-zbieranie-danych.md#1-komponent-i-konfiguracja)
      Gotowe, gdy: `QueryMonitor` oddaje komponent spod każdego id pierwszemu źródłu, którego `supports()` go przyjmuje, a źródło SQL ma w sobie `Command::supportsInstalledYii()`, sprawdzenia drivera i `commandMap` oraz normalizator z `createNormalizer()`, tworzony przy pierwszym połączeniu SQL; test z fałszywymi źródłami pokazuje, że źródło rzucające przy instalacji błąd zgodności nie blokuje następnego połączenia, a log ma jeden błąd; dotychczasowe testy przechodzą bez zmian.
      Wersja Yii bez pól z ADR-0001 wyłącza według spec 01 §1 tylko źródło SQL, a dziś `QueryMonitor::install()` wyłącza nią cały pakiet. `createNormalizer()` zostaje, bo `tests/app/FaultyQueryMonitor.php:25` wymusza przez niego błąd normalizatora z YQM-9.

- [ ] (=) **YQM-34** Normalizator MongoDB: `op` i `query` z dokumentu polecenia
      Spec: [02 §4](../spec/02-format-paczki.md#4-normalizacja) · ADR: [0004](../adr/0004-normalizacja-literalow-na-znak-zapytania.md) · Zależy od: YQM-32
      Gotowe, gdy: testy tabelaryczne w `Unit` na dokumentach poleceń zapisanych przez sondę pokrywają każdy wiersz tabeli MongoDB ze spec 02 §4 oraz `find`, `insert`, `update`, `delete`, `aggregate`, `count` i `getMore` w postaci, jaką wysyła `yii2-mongodb`, polecenie spoza tabeli normalizacji (`createIndexes`) daje `query: null`, a żaden `query` nie zawiera wartości z pozycji danych: filtra, aktualizacji, dokumentów, potoku, `sort`, `limit` i `skip`. Nazwy kolekcji, pól i operatorów zostają jak w spec 02 §4.
      Spec 02 §4 nazywa sekcje `filter`, `update` i `pipeline`, a protokół niesie warunek w `updates[].q`, `deletes[].q` i `query` polecenia `count`; tablice wartości (`$in`) i poziomy w potoku nie mają reguły. Najpierw poprawka spec 02 §4, potem kod.

- [ ] (^) **YQM-35** Źródło MongoDB: subskrybent zdarzeń sterownika, `Recorder` i instalacja na połączeniach z listy
      Spec: [01 §3](../spec/01-zbieranie-danych.md#3-źródło-mongodb) · [01 §1](../spec/01-zbieranie-danych.md#1-komponent-i-konfiguracja) · Zależy od: YQM-33, YQM-34
      Gotowe, gdy: `find()->all()` modelu `yii\mongodb\ActiveRecord` daje wpis `db: mongodb` z `conn`, `op: find`, znormalizowanym `query`, `time_ms` i `caller` z ramką kontrolera przy granicy 64 z ADR-0009; połączenie otwarte przed bootstrapem pakietu i otwarte ponownie po `close()` daje wpisy, każde polecenie raz; połączenie MongoDB spoza listy nie daje wpisów; testy `mongodb\Recorder` w `Unit` pokrywają parowanie zdarzeń ze spec 01 §3; wyjątek wymuszony w subskrybencie nie zmienia wyniku operacji ani odpowiedzi; polecenie wykonane przez adapter albo po finalizacji nie daje wpisu.
      Wyjątek z subskrybenta przerywa rozsyłanie zdarzenia w sterowniku (`if (EG(exception)) break;` w `phongo_apm_dispatch_event()`, `src/phongo_apm.c` w `mongo-php-driver`) i zostaje wyjątkiem PHP, więc każda metoda subskrybenta idzie przez `Guard`.

- [ ] (=) **YQM-36** Wynik polecenia MongoDB: `CommandFailed`, `writeErrors` i `writeConcernError`
      Spec: [01 §3](../spec/01-zbieranie-danych.md#3-źródło-mongodb) · [02 §2](../spec/02-format-paczki.md#2-wpis) · Zależy od: YQM-35
      Gotowe, gdy: zduplikowany klucz w `insert` daje w `CommandSucceeded` wpis `result: error` z `error: "11000"`, wymuszony `writeConcernError` daje swój kod jako tekst, odpowiedź z `writeErrors` i `writeConcernError` naraz daje kod z `writeErrors`, polecenie odrzucone przez serwer daje kod z `CommandFailed`, a aplikacja w każdym przypadku dostaje ten sam wynik albo wyjątek co bez pakietu.
      `getReply()` zamienia na obiekty PHP całą odpowiedź, z `firstBatch` wyników `find` włącznie; kiedy pakiet ją czyta, zapisuje spec 01 §3 w tym zadaniu.

- [ ] (=) **YQM-37** `getMore` i podzielony `insert` jako osobne wpisy
      Spec: [01 §3](../spec/01-zbieranie-danych.md#3-źródło-mongodb) · Zależy od: YQM-35
      Gotowe, gdy: odczyt kursora dłuższego niż pierwsza porcja daje wpis `op: find` i po jednym wpisie `op: getMore` na każdą następną porcję, a `batchInsert` większy niż `maxWriteBatchSize` serwera daje tyle wpisów `op: insert`, ile zdarzeń `CommandStarted` polecenia `insert` zobaczył niezależny subskrybent testowy.

- [ ] (=) **YQM-38** `caller` wpisu MongoDB z granicą zmierzoną dla ścieżki zdarzeń sterownika
      Spec: [01 §3](../spec/01-zbieranie-danych.md#3-źródło-mongodb) · ADR: [0009](../adr/0009-caller-i-route-w-formacie-v2.md) · Zależy od: YQM-35
      Gotowe, gdy: każda ścieżka z pytania 5 [ustaleń](#ustalenia-do-yqm-32) daje `caller` z pierwszą ramką aplikacji tej ścieżki, spec 01 §3 nazywa ramki od domknięcia strażnika i granicę tak jak §2 dla SQL, a ADR-0009 ma liczby dla MongoDB obok liczb dla SQL.
      YQM-35 bierze ślad z granicą 64 i sprawdza go tylko na `find()->all()`. ADR-0009 każe zmierzyć `N` osobno, bo ślad jest brany w procedurze zdarzenia, a nie w `sql\Recorder::record()`.

- [ ] (=) **YQM-39** Zależności opcjonalne w CI: minimalne i bieżące `ext-mongodb`, aplikacja konsumenta bez MongoDB
      Spec: [00 §7](../spec/00-przeglad-i-zakres.md#7-środowiska) · ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Zależy od: YQM-35
      Gotowe, gdy: macierz CI biegnie z minimalnymi wersjami `ext-mongodb` i `yii2-mongodb` ustalonymi w YQM-32 oraz z bieżącymi, a osobny job instaluje pakiet z repozytorium `path` w fixture'ze aplikacji konsumenta bez `ext-mongodb` i `yii2-mongodb` i jego skrypt, uruchomiony bez PHPUnit, dostaje paczkę z wpisem SQL.
      `yii2-mongodb` od 3.0.3 wymaga `ext-mongodb` 1.20.1 (Packagist), więc `require-dev` pakietu nie instaluje się bez rozszerzenia, a `failOnSkipped` nie pozwala na PHPUnit z pominiętymi testami MongoDB. Skrypt poza PHPUnit nie zmienia podziału z ADR 0008; `tests/README.md` dostaje o nim akapit.

- [ ] (=) **YQM-40** Jedna paczka z wpisami SQL i MongoDB, przykład konfiguracji MongoDB w `README.md`
      Spec: [00 §6](../spec/00-przeglad-i-zakres.md#6-kryteria-sukcesu) · Zależy od: YQM-36, YQM-37, YQM-38
      Gotowe, gdy: jedno żądanie aplikacji testowej z zapytaniami do MySQL i MongoDB, a osobno do PostgreSQL i MongoDB, daje jedną paczkę z wpisami obu źródeł w kolejności zakończenia, a przykład konfiguracji z MongoDB w `README.md` przechodzi w `ReadmeExampleTest` jak przykład SQL.

## Podział klas

Źródło MongoDB składa się z tych samych czterech ról co SQL. Wspólna część, `QueryEntry`, `QueryCollector`, `Guard` i `CallerFrames`, się nie zmienia, a `sql\Command`, `sql\Recorder` i `SqlNormalizer` zostają nietknięte.

| Rola | SQL | MongoDB |
|---|---|---|
| Instalacja na połączeniu z listy | źródło SQL z YQM-33, dziś `QueryMonitor::installOn()` | źródło MongoDB |
| Hak w bibliotece | `sql\Command` w `commandMap` | subskrybent `MongoDB\Driver\Monitoring\CommandSubscriber` na `Manager` połączenia |
| Budowa wpisu | `sql\Recorder`: wpis na próbę `execute()` | `mongodb\Recorder`: wpis na parę zdarzeń, parowaną po `requestId` |
| Normalizacja | `SqlNormalizer` | normalizator MongoDB z YQM-34 |

`QueryMonitor` zna tylko interfejs źródła z dwiema metodami: `supports(object $component): bool` i `install(string $id, object $component): void`, która przy połączeniu do pominięcia rzuca `InvalidConfigException`, jak dziś `installOn()`. Komponent, którego nie przyjmuje żadne źródło, jest pomijany z jednym `Yii::error`, jak dziś połączenie o nieznanym id. Źródło dostaje w konstruktorze kolektor, `Guard` i `CallerFrames`, a źródło SQL także leniwą fabrykę `Closure(): SqlNormalizer` opartą na `createNormalizer()`, wywoływaną przy pierwszym połączeniu SQL. Klasy MongoDB są ładowane dopiero wtedy, gdy na liście stoi `yii\mongodb\Connection`, więc aplikacja tylko z SQL ich nie dotyka (YQM-39).

Subskrybent jest cienki jak `sql\Command`: odczytuje ze zdarzenia `requestId`, nazwę polecenia i czas, a dokument polecenia przekazuje do `Recorder::started()` i odpowiedź do metody zdarzenia końca wyłącznie na czas tego wywołania. `Recorder` normalizuje dokument od razu i zachowuje z niego tylko `op` i `query`, a przy zdarzeniu końca bierze ślad i dodaje wpis. Dzięki temu `Recorder` i normalizator dają się testować w `Unit` bez sterownika. Wspólnego rekordera dla obu źródeł nie ma: spec 01 §2 liczy ramki od domknięcia strażnika w `sql\Recorder::record()`, więc wspólna klasa przesunęłaby pozycje zmierzone w YQM-27 i sprawdzane w `RecorderCallerTest`.

## Ustalenia do YQM-32

| # | Pytanie | Co od odpowiedzi zależy |
|---|---|---|
| 1 | Czy `Manager` z publicznego `Connection::$manager` przyjmuje subskrybenta w `EVENT_AFTER_OPEN`, które `initConnection()` wyzwala po `selectServer()` w `open()`; czy połączenie otwarte przez wcześniejszy komponent z `bootstrap` ma już `$manager`; czy `close()` z ponownym `open()` daje nowy `Manager`; czy drugie `addSubscriber()` tego samego obiektu na tym samym `Manager` jest bez skutku | Otwarta kwestia 2 i ADR-0010. Podmiana klasy `Connection` tylko przy odpowiedzi „nie” |
| 2 | Od której wersji `ext-mongodb` jest `Manager::addSubscriber()`, od której `yii2-mongodb` jest `Connection::$manager`, i które wydania `yii2-mongodb` wspieramy: 3.0.3 i 3.0.4 wymagają `ext-mongodb` 1.20.1, a 2.1.11 i 3.0.2 deklarują `>=1.0.0` | Wersje w spec 00 §7, w `suggest` i w macierzy YQM-39 |
| 3 | Czy subskrybent jednego `Manager` dostaje zdarzenia drugiego `Manager` o tym samym DSN i opcjach. Sterownik zbiera subskrybentów po kliencie libmongoc (`manager->client == client` w `phongo_apm_get_subscribers_to_notify()`), a klient o tej samej konfiguracji jest współdzielony | Czy dwa takie połączenia dają podwójne wpisy albo wpisy połączenia spoza listy; reguła pominięcia w spec 01 §1 |
| 4 | Jakie dokumenty poleceń wysyła `yii2-mongodb` dla `find`, `insert`, `update`, `delete`, `aggregate`, `count` i `getMore`, oraz jakie polecenia padają bez udziału aplikacji (`endSessions`, `killCursors`, uwierzytelnienie) | Poprawka spec 02 §4 i fixture'y normalizatora w YQM-34 |
| 5 | Pozycja pierwszej ramki aplikacji, liczona od domknięcia strażnika w procedurze zdarzenia końca polecenia, na ścieżkach `ActiveRecord::find()->one()`, `Collection::insert()`, `ActiveDataProvider` w `GridView` i odczyt kursora z `getMore` | Granica śladu dla MongoDB w YQM-38 |
| 6 | Jak wymusić `writeConcernError` i `CommandFailed` na MongoDB 7: fail point `failCommand` przy `enableTestCommands` czy jednowęzłowy replica set | Usługa z YQM-31, którą sonda wtedy poprawia, i test YQM-36 |

Odpowiedź „tak” na pytanie 3 nie blokuje etapu, ale musi trafić do spec 01 §1 przed YQM-35: pakiet nie może przypisać polecenia połączeniu, które go nie wysłało.
