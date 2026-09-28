# 01. Zbieranie danych

Jak wpisy powstają i kiedy kolektor je przyjmuje. Format wpisu i paczki jest w [02](02-format-paczki.md), odbiorca paczki w [03](03-adaptery-wyjsciowe.md).

Zdanie, którego kod jeszcze nie realizuje, opisuje zachowanie docelowe. Odwołania do Yii dotyczą wersji 2.0.55.

## 1. Komponent i konfiguracja

Pakiet dostarcza komponent aplikacji Yii rejestrowany w `bootstrap`. Przy starcie komponent:

1. Ustawia `commandMap` dla drivera każdego połączenia SQL z listy `connections` na klasę `Command` pakietu. Połączenie z modułu (`admin/db`) jest pobierane przez `getModule()`, więc moduł ładuje się już w bootstrapie.
2. Rejestruje subskrybenta zdarzeń sterownika w każdym połączeniu MongoDB z listy ([§3](#3-źródło-mongodb)).
3. Otwiera kontekst korzenia: `http` w `yii\web\Application`, `console` w `yii\console\Application` ([§5](#5-konsola-i-joby)).
4. Podpina się pod `EVENT_BEFORE_ACTION` i `EVENT_AFTER_REQUEST` aplikacji.
5. Rejestruje callback przez `register_shutdown_function`.

| Klucz konfiguracji | Znaczenie | Wartość początkowa |
|---|---|---|
| `enabled` | Wyłączenie pakietu bez usuwania konfiguracji | `true` |
| `app` | Nazwa aplikacji w nagłówku paczki | wymagane |
| `connections` | Lista id komponentów `Connection`, np. `['db', 'dbRead', 'mongodb']`. Połączenie w module jako `admin/db` | `[]` |
| `maxEntries` | Limit wpisów w paczce | `500` |
| `maxBatchBytes` | Limit rozmiaru JSON paczki | `262144` |
| `maxQueryLength` | Limit długości `query` w bajtach razem z wielokropkiem, najmniej `3` (długość `…`) | `8192` |
| `flushIntervalSeconds` | Odstęp wysyłki w kontekście `console` i `job` ([§5.2](#52-porcjowanie)), liczba całkowita, najmniej `1` | `30` |
| `excludedRoutes` | Wykluczenia tras według typu kontekstu: klucze `http`, `console`, `job`, każdy z listą wzorców ([§5.4](#54-wykluczenia-tras)) | `[]` |
| `adapter` | Klasa, obiekt lub `callable`. Brak oznacza adapter plikowy | `null` |
| `file` | Ustawienia adaptera plikowego, [03 §3](03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) | `[]` |

Punkty rozszerzeń dla podklasy komponentu to chronione metody `createNormalizer()`, `createCollector()` (bufor jednej paczki, wołany dla każdej nowej paczki z typem kontekstu, `id` i metadanymi joba), `createSources()` (źródła, które dostają stos kontekstów zamiast kolektora) i `createClock()` (zegar monotoniczny w sekundach dla `flushIntervalSeconds`, wołany raz w bootstrapie). Publiczne API to `beginJob()` i `endJob()` ([§5.3](#53-granice-joba)).

Połączenie spoza listy nie jest mierzone, z jednym wyjątkiem w MongoDB. `Manager` o identycznym `dsn` (ten sam napis) i identycznych tablicach `options` i `driverOptions` (te same klucze w tej samej kolejności i te same wartości) dzielą klienta sterownika, a sterownik oddaje zdarzenia klienta subskrybentom każdego z nich. Inna kolejność kluczy albo dodany `disableClientPersistence => false` daje osobnego klienta. Połączenia z listy o wspólnym kliencie mają jednego subskrybenta, a ich wpisy dostają `conn` pierwszego z nich na liście. Polecenie połączenia spoza listy, które dzieli klienta z połączeniem z listy, jest wpisem z tym `conn`, gdy w procesie istnieje już `Manager` któregoś z tych połączeń z listy. Połączenie z `driverOptions['disableClientPersistence'] => true` ma własnego klienta. Klucz klienta liczony jest przy każdym otwarciu z wartości, z którymi `yii2-mongodb` tworzy `Manager`, a `conn` to pierwsze id z listy, którego połączenie ma w tej chwili ten sam klucz. Połączenie tworzone dynamicznie w kodzie i własna klasa `Command` są poza zakresem.

Pakiet nie łączy się z bazą w bootstrapie: driver odczytuje z prefiksu `dsn`. Z jednym `Yii::error` pomijane są, a reszta listy działa: nieznane id połączenia lub modułu, połączenie bez `dsn` (np. tylko `masters`/`slaves`), driver inny niż `mysql` i `pgsql`, połączenie z własnym `commandClass` albo własnym `commandMap` dla swojego drivera oraz ten sam obiekt połączenia pod drugim id z listy (liczy się pierwsze id). W MongoDB pomijane jest połączenie, gdy nie ma `ext-mongodb`. Połączenie z listy, które ma ten sam klucz klienta co wcześniejsze id, jest mierzone pod `conn` tego id. Błędna konfiguracja pakietu (np. brak `app`, `maxQueryLength` poniżej `3`, `flushIntervalSeconds` poniżej `1`, nieznany klucz albo błędny wzorzec w `excludedRoutes`, przy `adapter: null` także nieznany klucz `file`, pusty `file.path` lub nieznany alias w nim, `file.maxSize` lub `file.maxFiles` poniżej `1` albo `file.fileMode` lub `file.dirMode` spoza 0–0777) wyłącza pakiet w tym procesie: bez podmiany `Command`, bez subskrybenta MongoDB i bez wysyłki, z jednym `Yii::error`. Wersja Yii, w której `yii\db\Command` nie ma prywatnych pól `_isolationLevel` i `_retryHandler` używanych przez pomiar ([ADR-0001](../adr/0001-podmiana-klasy-command-zamiast-profilera.md)), wyłącza tylko źródło SQL: połączenia SQL z listy są pomijane z jednym `Yii::error`, a połączenia MongoDB są mierzone. Aplikacja odpowiada normalnie.

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

`caller` powstaje przy zapisie wpisu, po pomiarze, z `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64)` wywołanego bezpośrednio w domknięciu strażnika w `Recorder::record()`, więc nie wchodzi do `time_ms`. Ramka 0 to to domknięcie, ramka 1 `Guard::run()`, ramka 2 `Recorder::record()`; granica 64 obejmuje ramki 0–63. Granica pochodzi z pomiaru: najgłębsza zmierzona pierwsza ramka aplikacji stoi na pozycji 32 (lista `GridView` z `with()` na dwa poziomy i `via()`), a każdy kolejny poziom `with()` dodaje 5 ramek ([ADR 0009](../adr/0009-caller-i-route-w-formacie-v2.md)). Po finalizacji, w wykluczonym kontekście i podczas `send()` ślad nie jest brany. Gdy paczka jest już pełna ([02 §5](02-format-paczki.md#5-limity)), zapytanie od razu zwiększa `dropped`, bez śladu i bez normalizacji.

`time_ms` to czas wywołań sterownika widziany z PHP, nie czas serwera bazy, w milisekundach zaokrąglonych do trzech miejsc po przecinku. PDO MySQL domyślnie buforuje wynik, więc transfer danych mieści się w `execute()` i duży `SELECT` ma duży pomiar. Błąd przy późniejszym odczycie kursora nie jest raportowany.

## 3. Źródło MongoDB

Pakiet rejestruje `MongoDB\Driver\Monitoring\CommandSubscriber` przez `addSubscriber()` na `Manager` z publicznego `Connection::$manager`: od razu, gdy połączenie jest otwarte, i w każdym `EVENT_AFTER_OPEN`, bo `open()` po `close()` tworzy nowy `Manager`. Każda para zdarzeń `CommandStarted` i `CommandSucceeded` lub `CommandFailed` daje jeden wpis, niezależnie od nazwy polecenia: `find` z Active Record, `createIndexes` z migracji, `killCursors` wysłane przez sterownik przy porzuconym kursorze i dowolne polecenie z `Connection::createCommand()`. Uzgadniania połączenia (`hello`) sterownik nie zgłasza. Połączenie otwarte przed bootstrapem pakietu jest mierzone od bootstrapu, a po `close()` i ponownym otwarciu dalej. Jeden `Manager` ma najwyżej jednego subskrybenta pakietu.

| Co | Zachowanie |
|---|---|
| `op` | `getCommandName()` zdarzenia bez zmiany wielkości liter, np. `getMore`, `findAndModify` |
| `query` | Z dokumentu polecenia według [02 §4](02-format-paczki.md#4-normalizacja). Polecenie, którego normalizacja nie opisuje, daje `null` |
| Pomiar | `durationMicros` zdarzenia końca podzielone przez 1000 i zaokrąglone do trzech miejsc po przecinku |
| `CommandFailed` | `result: error` z kodem liczbowym |
| `writeErrors` lub `writeConcernError` w `CommandSucceeded` | `result: error` z kodem pierwszego elementu `writeErrors`, a bez `writeErrors` z kodem `writeConcernError`. Błąd pojedynczej instrukcji jest dokładniejszy niż niepotwierdzony zapis. Z odpowiedzi pakiet bierze tylko te kody, a czyta ją dla każdego polecenia oprócz `find`, `getMore`, `aggregate` i `distinct`, których odpowiedź niesie dane wyników. `writeConcernError` z `aggregate` z `$out` albo `$merge` nie daje więc `result: error` |
| `getMore` | Osobny wpis z `op: getMore` |
| Podzielony `insertMany` | Tyle wpisów, ile poleceń sterownik wysłał |

Zdarzenia początku i końca łączy `requestId`. Przy `CommandStarted` pakiet wylicza `op` i `query` i do zdarzenia końca trzyma tylko te dwie wartości. Dokument polecenia i odpowiedź nie żyją w pakiecie dłużej niż wywołanie subskrybenta. Gdy najgłębszy kontekst nie przyjmuje wpisów (po finalizacji, w wykluczonym kontekście, podczas `send()` adaptera), `CommandStarted` nie zapisuje stanu. Gdy paczka jest pełna ([02 §5](02-format-paczki.md#5-limity)), `CommandStarted` od razu zwiększa `dropped`, bez normalizacji i bez zapisu stanu.

Zdarzenie końca najpierw usuwa zapisany stan, potem buduje wpis tak jak źródło SQL: po finalizacji go pomija, przy pełnej paczce zwiększa `dropped`. Zdarzenie końca bez zapisanego stanu nie daje wpisu i nie zmienia `dropped`. Tak kończy się polecenie rozpoczęte przed instalacją subskrybenta albo takie, którego normalizacja rzuciła wyjątek. `caller` powstaje przy zdarzeniu końca, po sprawdzeniu kolektora, z `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64)` wywołanego bezpośrednio w domknięciu strażnika w `mongodb\Recorder::succeeded()` albo `failed()`. Ramka 0 to to domknięcie, ramka 1 `Guard::run()`, ramka 2 metoda `Recorder`, ramka 3 metoda subskrybenta; granica 64 obejmuje ramki 0–63. Najgłębsza zmierzona pierwsza ramka aplikacji stoi na pozycji 32 (lista `GridView` z `with()` na dwa poziomy), a każdy kolejny poziom `with()` dodaje 5 ramek ([ADR 0009](../adr/0009-caller-i-route-w-formacie-v2.md)). Wyjątek w subskrybencie jest obsłużony jak w [§6](#6-ochrona-aplikacji) i nie wraca do sterownika.

## 4. Żądanie HTTP

| Moment | Co robi kolektor |
|---|---|
| Bootstrap | Otwiera kontekst `http` i zaczyna przyjmować wpisy |
| `EVENT_BEFORE_ACTION` aplikacji, pierwsze wystąpienie | Zapamiętuje akcję wejściową jako `route` kontekstu korzenia i sprawdza `excludedRoutes['http']` ([§5.4](#54-wykluczenia-tras)). Kolejne wystąpienia nie nadpisują. Gdy `Yii::$app->errorHandler->exception` jest ustawiony, zdarzenie pochodzi z `errorAction` i akcja nie jest zapamiętywana |
| `EVENT_AFTER_REQUEST` | Finalizacja procesu ([§5.5](#55-finalizacja-procesu)): flaga „sfinalizowano”, zakończenie otwartych jobów, zamknięcie kontekstu `http`, budowa paczki, `send()` |
| Callback shutdown | Finalizacja, jeśli flaga nie jest ustawiona. Pokrywa `exit()` i `exit(1)` z `ErrorHandler` |

Flaga „sfinalizowano” jest ustawiana przed budową paczki, także przy pustym buforze. Wyjątek adaptera lub zajęta blokada pliku nie cofają flagi, więc shutdown nie próbuje drugi raz. Kontekst `http` daje jedną paczkę z `seq: 1`: limity obcinają według [02 §5](02-format-paczki.md#5-limity). Job otwarty w żądaniu ([§5.3](#53-granice-joba)) ma własne paczki, a jego wpisy nie trafiają do paczki żądania.

Zapytania z widoków i z obsługi wyjątków trafiają na listę, bo dzieją się przed `EVENT_AFTER_REQUEST`. Operacje po finalizacji są poza zakresem i nie są zliczane. Żądanie bez wpisów nie wysyła paczki. Błąd krytyczny PHP gubi paczkę.

## 5. Konsola i joby

### 5.1 Konteksty

Wpis należy do kontekstu. Kontekst ma własne `id` z [02 §1](02-format-paczki.md#1-nagłówek) i własne `seq` od 1.

| Typ | Co obejmuje | Kto otwiera i zamyka | Paczki |
|---|---|---|---|
| `http` | Jedno żądanie | Komponent: bootstrap i finalizacja ([§4](#4-żądanie-http)) | Jedna |
| `console` | Jedno uruchomienie `php yii ...`, od bootstrapu do końca procesu | Komponent: bootstrap i finalizacja | Wiele, [§5.2](#52-porcjowanie) |
| `job` | Jedna próba wykonania joba | Aplikacja: `beginJob()` i `endJob()` ([§5.3](#53-granice-joba)) | Wiele, [§5.2](#52-porcjowanie) |

Otwarte konteksty tworzą stos: na dole kontekst korzenia (`http` albo `console`), nad nim joby w kolejności otwarcia. Źródła SQL i MongoDB oddają każdy wpis stosowi, który przekazuje go tylko najgłębszemu otwartemu kontekstowi. Wpis MongoDB należy do kontekstu otwartego w chwili zdarzenia końca polecenia, tak jak wpis SQL do kontekstu z chwili zakończenia `execute()`. Po zakończeniu joba wpisy znów trafiają do jego rodzica. Stos ma najwyżej 16 kontekstów razem z korzeniem. `beginJob()` ponad tym limitem daje uchwyt obojętny z jednym `Yii::error` na proces, a wpisy kolejnych jobów trafiają do najgłębszego istniejącego kontekstu i dostają jego metadane.

Joby mogą być wykonywane jeden po drugim w workerze albo synchronicznie zagnieżdżone: job w żądaniu HTTP, w komendzie albo w innym jobie. Wykonania równoległe w jednym procesie (fibers, Swoole) są poza zakresem.

### 5.2 Porcjowanie

Kontekst `console` i `job` wysyła paczkę po osiągnięciu dowolnego limitu. Wszystkie paczki jednego kontekstu mają wspólne `id` i kolejne `seq`. Następny kontekst, także kolejna próba tego samego joba, ma nowe `id` i `seq` od 1.

| Limit | Kiedy sprawdzany |
|---|---|
| `maxEntries` | Natychmiast po dodaniu wpisu numer `maxEntries`. Paczka z 500 wpisami jest wysłana, zanim proces wróci do swojej pracy |
| `maxBatchBytes` | Przed dodaniem wpisu. Wpis jest najpierw porównany z pustą paczką z bieżącym nagłówkiem. Gdy nie mieści się nawet w niej, przepada i zwiększa `dropped` bieżącego bufora, bez wysyłki. Gdy mieści się w pustej, a nie w bieżącym buforze, kolektor wysyła dotychczasowy bufor, a wpis otwiera następną paczkę |
| `flushIntervalSeconds` od poprzedniej wysyłki, pierwszy okres od otwarcia kontekstu | Przy dodaniu wpisu: gdy odstęp minął, a bufor nie jest pusty, bufor jest wysyłany przed dodaniem wpisu. To samo sprawdzenie robi `beginJob()` dla kontekstu, który staje się rodzicem |
| Zakończenie kontekstu | `endJob()` albo finalizacja procesu wysyła niepustą resztę |

Bufor jest pusty, gdy nie ma wpisów i `dropped` jest `0`. `dropped` liczy się osobno w każdej paczce i zaczyna od `0` po wysyłce. Bufor z samym `dropped` większym od `0` idzie przy następnej wysyłce albo przy zakończeniu kontekstu. Czas mierzy zegar monotoniczny. Bezczynny proces nie wysyła, bo nie ma timera ani sygnału. Paczka utracona przez wyjątek adaptera ([03 §2](03-adaptery-wyjsciowe.md#2-błąd-adaptera)) zostawia lukę w `seq`, a kontekst zbiera dalej do nowego bufora.

### 5.3 Granice joba

```php
$job = Yii::$app->queryMonitor->beginJob('app\jobs\SendInvoice', queue: 'queue', messageId: '42', attempt: 2);
try {
    // praca joba
} finally {
    Yii::$app->queryMonitor->endJob($job);
}
```

| Wywołanie | Zachowanie |
|---|---|
| `beginJob(string $name, ?string $queue = null, string\|int\|null $messageId = null, ?int $attempt = null): JobHandle` | Otwiera kontekst `job` nad najgłębszym otwartym kontekstem, z nowym `id`, i zwraca jego uchwyt. Metadane trafiają do pola `job` nagłówka ([02 §1](02-format-paczki.md#1-nagłówek)). Pusty `name` zamienia na `unknown` |
| `endJob(JobHandle $handle): void` | Kończy kontekst uchwytu: wysyła niepustą resztę i przywraca rodzica. Kontekst nad nim, którego aplikacja nie zamknęła, jest kończony najpierw, od najgłębszego, bo przy zagnieżdżeniu synchronicznym już się wykonał |
| `endJob()` na uchwycie zakończonym, obojętnym albo z innego komponentu | Nic nie robi |
| `beginJob()` przy `enabled: false`, przy pakiecie wyłączonym błędną konfiguracją, bez bootstrapu komponentu, po finalizacji procesu albo ponad limitem stosu | Zwraca uchwyt obojętny. Zapytania należą wtedy do najgłębszego otwartego kontekstu |

Obie metody nigdy nie rzucają wyjątku: błąd monitoringu jest obsłużony jak w [§6](#6-ochrona-aplikacji) i nie zmienia wyniku joba, jego wyjątku, ponowienia ani potwierdzenia wiadomości w kolejce. `endJob()` nie przyjmuje wyniku joba: ta sama metoda kończy job po sukcesie i po błędzie, a paczka nie zawiera wyjątku, argumentów ani treści wiadomości. Po zakończeniu kontekst nie trzyma żadnego wpisu ani stanu, który przeszedłby do następnego joba.

**`yii2-queue`.** Opcjonalny behavior `mrstroz\querymonitoring\queue\JobMonitorBehavior`, podpinany do komponentu kolejki, otwiera job w `Queue::EVENT_BEFORE_EXEC` (nazwa klasy joba, `queueName` z konfiguracji behavior, `id` wiadomości, numer próby) i kończy go w `EVENT_AFTER_EXEC` i `EVENT_AFTER_ERROR` tego samego obiektu zdarzenia. Zdarzenie końca bez wcześniejszego otwarcia, np. `handleError()` listenera po awarii procesu potomnego w trybie `isolate`, nic nie robi. Job, którego inny handler oznaczył jako `handled`, nie dostaje zdarzenia końca, więc behavior kończy go w `cli\Queue::EVENT_WORKER_LOOP` albo `EVENT_WORKER_STOP`, a w procesie bez pętli workera kończy go finalizacja. W trybie `isolate` zdarzenia wykonania wyzwala proces potomny `queue/exec`, więc tam powstają paczki joba. Pakiet nie wymaga `yiisoft/yii2-queue`: behavior ładuje się tylko wtedy, gdy aplikacja go skonfiguruje.

### 5.4 Wykluczenia tras

`excludedRoutes` ma osobne listy wzorców dla `http`, `console` i `job`. Wzorzec dla `http` i `console` porównuje się z `route` kontekstu korzenia, a dla `job` z nazwą joba przed obcięciem ([02 §1](02-format-paczki.md#1-nagłówek)), po zamianie pustej na `unknown`.

| Wzorzec | Pasuje do |
|---|---|
| Bez `*`, np. `health/index` | Dokładnie tej wartości. Wielkość liter ma znaczenie, bez `/` na początku |
| Zakończony `*`, np. `queue/*`, `app\jobs\*` | Każdej wartości zaczynającej się od tekstu przed `*` |
| `*` | Każdej wartości |
| Pusty, nie tekst, zaczynający się od `/` albo z `*` w innym miejscu niż ostatni znak | Błędna konfiguracja pakietu ([§1](#1-komponent-i-konfiguracja)), tak samo lista podana jako tekst i klucz inny niż `http`, `console`, `job` |

`route` równe `null` nie pasuje do żadnego wzorca. Wykluczenie dotyczy tylko wpisów tego kontekstu: jego zapytania są pomijane, nie zwiększają `dropped` i nie trafiają do rodzica, a kontekst niczego nie wysyła. Kontekst potomny ocenia się niezależnie własnym kluczem, więc job w wykluczonym listenerze, wykluczonym żądaniu albo wykluczonym jobie jest mierzony, o ile jego nazwa nie jest wykluczona.

Kontekst korzenia zbiera od bootstrapu, zanim trasa jest znana. Do `EVENT_BEFORE_ACTION` niczego nie wysyła, także w konsoli, a limit osiągnięty w tym czasie działa jak w HTTP: dalsze wpisy tylko zwiększają `dropped`. Gdy trasa okaże się wykluczona, bufor jest odrzucany razem z `dropped`. Gdy nie jest wykluczona, porcjowanie konsoli rusza, a bufor, który osiągnął limit, jest wysyłany od razu. Kontekst, którego trasa nigdy nie zostanie ustalona (błąd 404, nieznana komenda), nie jest wykluczony i wysyła przy finalizacji.

### 5.5 Finalizacja procesu

`EVENT_AFTER_REQUEST` aplikacji konsolowej, tak jak webowej, finalizuje proces, a callback shutdown robi to, gdy zdarzenie nie nastąpiło ([ADR-0003](../adr/0003-finalizacja-w-after-request-i-shutdown.md)). Finalizacja ustawia flagę „sfinalizowano”, kończy otwarte joby od najgłębszego, potem kontekst korzenia. Każdy wysyła swoją niepustą resztę. Zakończenie kontekstu, wysłanie paczki i finalizacja procesu to osobne operacje: kontekst zakończony przez `endJob()` nie wysyła drugi raz w shutdown, a błąd wysyłki jednej paczki nie przerywa kończenia pozostałych. Po finalizacji żaden wpis nie jest zbierany.

Callback shutdown nie działa po błędzie krytycznym PHP w niektórych fazach ani po `SIGKILL`. Resztę kontekstu, który jeszcze trwa, gubi wtedy każdy ze sposobów wysyłki.

## 6. Ochrona aplikacji

Każde wywołanie stosu kontekstów, kolektora i adaptera jest w `try/catch`, także `beginJob()` i `endJob()`. Wyjątek daje jeden `Yii::error` na proces, bez treści zapytań, i nie jest propagowany. Komunikat wyjątku trafia do logu tylko dla wyjątków pakietu: błędnej konfiguracji ([§1](#1-komponent-i-konfiguracja)), adaptera plikowego ([03 §2](03-adaptery-wyjsciowe.md#2-błąd-adaptera)) i limitu stosu kontekstów ([§5.1](#51-konteksty)), którego komunikat podaje sam limit; inne wyjątki są logowane tylko z nazwą klasy. Flaga ponownego wejścia należy do stosu kontekstów, nie do jednej paczki: podczas `send()` żaden kontekst nie przyjmuje wpisów, więc zapytania adaptera nie trafiają ani do wysyłanego kontekstu, ani do jego rodzica. Pamięć długo żyjącego procesu jest ograniczona: każdy otwarty kontekst trzyma najwyżej jeden bufor paczki, stos ma najwyżej 16 kontekstów, a zakończony kontekst nie jest nigdzie przechowywany.

## 7. Poza zakresem

Bezpośrednie użycie PDO lub `MongoDB\Driver\Manager` poza połączeniami Yii. Rozpoznawanie jobów bez wywołania `beginJob()` przez aplikację albo behavior. Wykonania równoległe w jednym procesie (fibers). Wynik joba w paczce. Reset kontekstu między żądaniami w długo żyjących procesach HTTP. Lista w [00 §4](00-przeglad-i-zakres.md#4-poza-zakresem-wersji-1).
