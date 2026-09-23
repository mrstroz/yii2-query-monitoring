# ADR-0010: Subskrybent sterownika na `Manager` połączenia MongoDB

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-23 |
| **Dotyczy** | Źródło MongoDB: instalacja na połączeniu, podział klas, połączenia o wspólnym kliencie sterownika |

## Kontekst

`yii\mongodb\Connection` trzyma sterownik w publicznym `$manager`. `open()` tworzy `Manager`, wybiera serwer i woła `initConnection()`, która wyzwala `EVENT_AFTER_OPEN`. `close()` ustawia `$manager` na `null` (`vendor/yiisoft/yii2-mongodb/src/Connection.php:379-435`). `ext-mongodb` od 1.10 przyjmuje `CommandSubscriber` przez `Manager::addSubscriber()`.

Sonda YQM-32 (`tests/Integration/Yii/MongoDbProbeTest.php`) na `ext-mongodb` 2.5.2, `yii2-mongodb` 3.0.4 i MongoDB 7 pokazała:

- subskrybent dodany w `EVENT_AFTER_OPEN` widzi polecenia połączenia;
- `open()` po `close()` tworzy nowy `Manager` i znowu wyzwala zdarzenie;
- połączenie otwarte wcześniej ma już `$manager`;
- drugie `addSubscriber()` tego samego obiektu na tym samym `Manager` nic nie zmienia.

Sterownik wybiera odbiorców zdarzenia po kliencie libmongoc, a nie po `Manager`. `Manager` o tym samym napisie DSN i identycznych tablicach `options` i `driverOptions` dostaje z puli ten sam trwały klient. Subskrybent jednego takiego `Manager` widzi więc polecenia drugiego. Klucz jest dokładny: inna kolejność kluczy w `options`, `disableClientPersistence => false` wobec pustych `driverOptions`, `appname` w DSN zamiast w `options` albo `?` na końcu DSN dają osobnego klienta. `disableClientPersistence => true` daje każdemu `Manager` własnego klienta. Subskrybent dodany tylko w `EVENT_AFTER_OPEN` połączenia, którego żądanie nie otwiera, nie widzi niczego. Ten sam obiekt subskrybenta na dwóch `Manager` jednego klienta dostaje zdarzenie raz.

## Decyzja

Źródło MongoDB podpina się pod `EVENT_AFTER_OPEN` każdego połączenia z listy, a gdy połączenie jest już otwarte, dodaje subskrybenta od razu. Subskrybent to jeden obiekt na klucz klienta. Klucz to `serialize()` z `dsn`, `options` i `driverOptions` bez normalizacji, liczony w `EVENT_AFTER_OPEN` z wartości, z którymi `yii2-mongodb` utworzył `Manager`. Połączenie z `disableClientPersistence => true` ma klucz tylko dla siebie. Każde id z listy dodaje subskrybenta swojego klucza do każdego swojego `Manager`, także po ponownym otwarciu, a `conn` wpisów to pierwsze id z listy, którego połączenie ma w chwili otwarcia ten sam klucz. Drugie id o tym samym kluczu jest mierzone pod `conn` pierwszego, bez `Yii::error`: konfiguracja jest poprawna, a `Guard` loguje jeden błąd na proces tylko z wyjątku, który przerwałby instalację. Polecenia `Manager` spoza listy, który dzieli klienta z połączeniem z listy, trafiają do paczki pod tym `conn`, o ile w procesie istnieje `Manager` połączenia z listy o tym kluczu. Reguły są w [spec 01 §1](../spec/01-zbieranie-danych.md#1-komponent-i-konfiguracja) i [§3](../spec/01-zbieranie-danych.md#3-źródło-mongodb).

Źródło ma cztery role jak SQL: instalację (źródło MongoDB), hak (subskrybent), budowę wpisu (`mongodb\Recorder`) i normalizator. Interfejs `CommandSubscriber` implementuje tylko subskrybent. Dzięki temu pozostałe klasy ładują się bez `ext-mongodb`, a źródło sprawdza `interface_exists()`, zanim utworzy subskrybenta.

## Konsekwencje

**Pozytywne:** nie trzeba podmieniać klasy `Connection` ani dotykać konfiguracji aplikacji. Mierzone jest połączenie otwarte przed bootstrapem pakietu i każde ponowne otwarcie. Dwa połączenia z listy na jednym kliencie nie dają podwójnych wpisów i żadne nie gubi poleceń, także gdy w żądaniu otwarte jest tylko drugie.

**Negatywne:** polecenie połączenia spoza listy, które dzieli klienta z połączeniem z listy, jest zapisywane jako polecenie tego połączenia. Drugie id z listy o tym samym kluczu nie ma osobnego `conn`. Klucz liczy pakiet, więc sterownik, który zmieni sposób łączenia klientów, może go rozjechać. Wykryje to `MongoDbProbeTest`. Klucz nie jest normalizowany, bo klucz mądrzejszy od sterownika połączyłby rozłączne klienty i zgubił polecenia drugiego.

**Wymagania:** `yii2-mongodb` z publicznym `$manager` i `EVENT_AFTER_OPEN`, `ext-mongodb` z `Manager::addSubscriber()`. Wspierane są `yii2-mongodb` 3.0.4 lub nowszy i `ext-mongodb` 2.0 lub nowszy ([spec 00 §7](../spec/00-przeglad-i-zakres.md#7-środowiska)). 3.0.3 woła `Cursor::getId(true)` (`src/BatchQueryResult.php:132`), które ext 2.0 odrzuca, a 3.0.4 woła `getId()` bez argumentu, które ext 1.20 zgłasza jako deprecated, a Yii zamienia to w wyjątek (`src/Query.php:215`). Żadna para z ext 1.x nie działa więc w aplikacji Yii. Minimum Yii 2.0.55, `yii2-mongodb` 3.0.4 i ext 2.0.0 sprawdza job CI z `--prefer-lowest` (YQM-39).

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Podmiana klasy `Connection` na klasę pakietu | Zmienia konfigurację aplikacji i koliduje z jej własną podklasą. `$manager` i `EVENT_AFTER_OPEN` wystarczają |
| Globalny subskrybent `MongoDB\Driver\Monitoring\addSubscriber()` | Dostaje polecenia każdego `Manager` w procesie i nie ma jak przypisać `conn` |
| Wymuszenie `disableClientPersistence` na połączeniach z listy | Oddziela klienta, ale zmienia działanie aplikacji: nowe połączenie TCP w każdym żądaniu PHP-FPM i koniec puli. Pakiet nie może zmieniać zachowania aplikacji ([spec 00 §2](../spec/00-przeglad-i-zakres.md#2-główna-zasada)) |
| Odsiewanie zdarzeń po serwerze albo `serverConnectionId` | Wspólny klient ma jedną pulę połączeń, więc serwer i połączenie niczego nie rozróżniają |
| Osobny subskrybent na każde id | Dwa połączenia z listy na jednym kliencie dają każde polecenie dwa razy, raz z błędnym `conn` |
| Pominięcie drugiego id o tym samym kluczu | Gdy w żądaniu otwarte jest tylko drugie id, żaden `Manager` nie ma subskrybenta pakietu i jego polecenia giną |

## Kiedy wrócić do tej decyzji

Gdy `yii2-mongodb` przestanie wystawiać `$manager` albo `EVENT_AFTER_OPEN`, gdy sterownik zacznie wybierać odbiorców zdarzeń po `Manager` albo zmieni klucz trwałego klienta, albo gdy użytkownik zgłosi wpisy z połączenia spoza listy jako problem.
