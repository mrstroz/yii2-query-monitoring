# 01. Zbieranie danych

Jak wpisy powstają i kiedy kolektor je przyjmuje. Format wpisu i paczki jest w [02](02-format-paczki.md), odbiorca paczki w [03](03-adaptery-wyjsciowe.md).

Zdanie, którego kod jeszcze nie realizuje, opisuje zachowanie docelowe. Odwołania do Yii dotyczą wersji 2.0.55.

## 1. Komponent i konfiguracja

Pakiet dostarcza komponent aplikacji Yii rejestrowany w `bootstrap`. Przy starcie komponent:

1. Ustawia `commandMap` dla drivera każdego połączenia SQL z listy `connections` na klasę `Command` pakietu. Połączenie z modułu (`admin/db`) jest pobierane przez `getModule()`, więc moduł ładuje się już w bootstrapie.
2. Rejestruje subskrybenta zdarzeń sterownika w każdym połączeniu MongoDB z listy.
3. Podpina się pod `EVENT_BEFORE_ACTION` i `EVENT_AFTER_REQUEST` aplikacji.
4. Rejestruje callback przez `register_shutdown_function`.

| Klucz konfiguracji | Znaczenie | Wartość początkowa |
|---|---|---|
| `enabled` | Wyłączenie pakietu bez usuwania konfiguracji | `true` |
| `app` | Nazwa aplikacji w nagłówku paczki | wymagane |
| `connections` | Lista id komponentów `Connection`, np. `['db', 'dbRead', 'mongodb']`. Połączenie w module jako `admin/db` | `[]` |
| `maxEntries` | Limit wpisów w paczce | `500` |
| `maxBatchBytes` | Limit rozmiaru JSON paczki | `262144` |
| `maxQueryLength` | Limit długości `query` w bajtach razem z wielokropkiem, najmniej `3` (długość `…`) | `2048` |
| `flushIntervalSeconds` | Odstęp wysyłki w zadaniu konsolowym | `30` |
| `adapter` | Klasa, obiekt lub `callable`. Brak oznacza adapter plikowy | `null` |
| `file` | Ustawienia adaptera plikowego, [03 §3](03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) | `[]` |

Połączenie spoza listy nie jest mierzone. Połączenie tworzone dynamicznie w kodzie i własna klasa `Command` są poza zakresem.

Pakiet nie łączy się z bazą w bootstrapie: driver odczytuje z prefiksu `dsn`. Z jednym `Yii::error` pomijane są, a reszta listy działa: nieznane id połączenia lub modułu, połączenie bez `dsn` (np. tylko `masters`/`slaves`), driver inny niż `mysql` i `pgsql`, połączenie z własnym `commandClass` albo własnym `commandMap` dla swojego drivera oraz ten sam obiekt połączenia pod drugim id z listy (liczy się pierwsze id). Błędna konfiguracja pakietu (np. brak `app`, `maxQueryLength` poniżej `3`, przy `adapter: null` także nieznany klucz `file`, pusty `file.path` lub nieznany alias w nim albo `file.maxSize` lub `file.maxFiles` poniżej `1`) i wersja Yii, w której `yii\db\Command` nie ma prywatnych pól `_isolationLevel` i `_retryHandler` używanych przez pomiar ([ADR-0001](../adr/0001-podmiana-klasy-command-zamiast-profilera.md)), wyłączają pakiet w tym procesie: bez podmiany `Command` i bez wysyłki, z jednym `Yii::error`. Aplikacja odpowiada normalnie.

## 2. Źródło SQL

Pakiet dostarcza klasę rozszerzającą `yii\db\Command`. Podmiana przez `commandMap` obejmuje wszystko, co aplikacja wykonuje przez `Connection::createCommand()`, w tym Active Record, `Query`, migracje i zapytania o schemat.

| Co | Zachowanie |
|---|---|
| Pomiar | Suma czasu wywołań `PDO::prepare()` i `PDOStatement::execute()`. Bez `Connection::open()`, bez pobrania wyników i zapisu do cache w `queryInternal()`, bez osobnych `fetch` |
| Wynik | Wyjątek z `PDO::prepare()` lub `PDOStatement::execute()` daje `result: error` z SQLSTATE. Wyjątek jest rzucany dalej bez zmian |
| Cache | Trafienie w cache zapytań Yii nie wykonuje polecenia i nie daje wpisu |
| Savepointy | `SAVEPOINT`, `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT` idą przez `Command` i dają wpisy |
| Transakcje | `begin`, `commit`, `rollback` idą przez PDO i nie dają wpisów |
| Ponowienia | Każda próba `PDOStatement::execute()` w pętli ponowień `Command::internalExecute()` to osobny wpis |

`time_ms` to czas wywołań sterownika widziany z PHP, nie czas serwera bazy, w milisekundach zaokrąglonych do trzech miejsc po przecinku. PDO MySQL domyślnie buforuje wynik, więc transfer danych mieści się w `execute()` i duży `SELECT` ma duży pomiar. Błąd przy późniejszym odczycie kursora nie jest raportowany.

## 3. Źródło MongoDB

Pakiet rejestruje `MongoDB\Driver\Monitoring\CommandSubscriber` dla `Manager` połączenia z listy. Każda para zdarzeń `CommandStarted` i `CommandSucceeded` lub `CommandFailed` daje jeden wpis.

| Co | Zachowanie |
|---|---|
| Pomiar | `durationMicros` ze zdarzenia sterownika |
| `CommandFailed` | `result: error` z kodem liczbowym |
| `writeErrors` lub `writeConcernError` w `CommandSucceeded` | `result: error` z pierwszym kodem liczbowym |
| `getMore` | Osobny wpis z `op: getMore` |
| Podzielony `insertMany` | Tyle wpisów, ile poleceń sterownik wysłał |

Sposób dostępu do `Manager` z `yii\mongodb\Connection` jest otwartą kwestią 2 w [00 §9](00-przeglad-i-zakres.md#9-otwarte-kwestie).

## 4. Żądanie HTTP

| Moment | Co robi kolektor |
|---|---|
| Bootstrap | Zaczyna przyjmować wpisy |
| `EVENT_BEFORE_ACTION` aplikacji, pierwsze wystąpienie | Zapamiętuje akcję wejściową. Kolejne wystąpienia nie nadpisują. Gdy `Yii::$app->errorHandler->exception` jest ustawiony, zdarzenie pochodzi z `errorAction` i akcja nie jest zapamiętywana |
| `EVENT_AFTER_REQUEST` | Finalizacja: ustawienie flagi „sfinalizowano”, zamknięcie kolektora, budowa paczki, `send()` |
| Callback shutdown | Finalizacja, jeśli flaga nie jest ustawiona. Pokrywa `exit()` i `exit(1)` z `ErrorHandler` |

Flaga „sfinalizowano” jest ustawiana przed budową paczki, także przy pustym buforze. Wyjątek adaptera lub zajęta blokada pliku nie cofają flagi, więc shutdown nie próbuje drugi raz.

Zapytania z widoków i z obsługi wyjątków trafiają na listę, bo dzieją się przed `EVENT_AFTER_REQUEST`. Operacje po finalizacji są poza zakresem i nie są zliczane. Żądanie bez wpisów nie wysyła paczki. Błąd krytyczny PHP gubi paczkę.

## 5. Zadanie konsolowe

Zadanie to jedno uruchomienie procesu. Worker kolejki to jedno zadanie z wieloma paczkami. Wszystkie paczki mają wspólne `id` i `seq` od 1.

Kolektor wysyła paczkę po osiągnięciu dowolnego limitu:

| Limit | Kiedy sprawdzany |
|---|---|
| `maxEntries` | Natychmiast po dodaniu wpisu numer `maxEntries`. Paczka z 500 wpisami jest wysłana, zanim proces wróci do swojej pracy |
| `maxBatchBytes` | Przed dodaniem wpisu. Gdy wpis nie zmieści się w bieżącej paczce, kolektor wysyła dotychczasowy bufor, a wpis otwiera następną paczkę |
| `flushIntervalSeconds` od poprzedniej wysyłki, pierwszy okres od startu kolektora | Przy dodaniu wpisu |
| Koniec procesu | Callback shutdown wysyła niepustą resztę |

Bezczynny proces nie wysyła, bo nie ma timera. Akcja wejściowa jest brana z `EVENT_BEFORE_ACTION` tak samo jak w HTTP.

## 6. Ochrona aplikacji

Każde wywołanie kolektora i adaptera jest w `try/catch`. Wyjątek daje jeden `Yii::error` na proces, bez treści zapytań, i nie jest propagowany. Flaga ponownego wejścia sprawia, że zapytania wykonane przez adapter podczas `send()` nie trafiają do kolektora.

## 7. Poza zakresem

Bezpośrednie użycie PDO lub `MongoDB\Driver\Manager` poza połączeniami Yii. Rozpoznawanie jobów w workerze. Reset kolektora między żądaniami w długo żyjących procesach HTTP. Lista w [00 §4](00-przeglad-i-zakres.md#4-poza-zakresem-wersji-1).
