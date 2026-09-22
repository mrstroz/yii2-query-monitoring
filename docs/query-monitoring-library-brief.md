# Brief: biblioteka PHP do listy zapytań bazodanowych

## Cel

Potrzebujemy biblioteki instalowanej przez Composer w kilku aplikacjach Yii 2. Dla każdego żądania HTTP lub zadania konsolowego ma ona zebrać listę zapytań do MySQL, PostgreSQL i MongoDB. Każde zapytanie ma czas wykonania i wynik. Po instalacji i konfiguracji pomiar obejmuje także Active Record, bez zmian w modelach.

Biblioteka mierzy i buduje paczkę. Aplikacja wybiera przez adapter, gdzie paczka trafia. Domyślny adapter zapisuje ją do rotowanego pliku.

Biblioteka ma działać stale na produkcji. Nie wymaga `enableProfiling` ani `enableLogging` w Yii.

## Środowisko

- PHP 8.1 lub nowszy, Yii 2.0.45 lub nowszy.
- HTTP w modelu PHP-FPM: osobny cykl aplikacji Yii dla każdego żądania. RoadRunner i Swoole dla HTTP są poza zakresem. Długie zadania konsolowe są wspierane.
- `yiisoft/yii2-mongodb` i `ext-mongodb` to zależności opcjonalne (`suggest`). Aplikacja tylko z SQL instaluje bibliotekę bez MongoDB.

## Zakres pierwszej wersji

- Połączenia `yii\db\Connection` (MySQL, PostgreSQL) i `yii\mongodb\Connection`.
- Żądania HTTP i zadania konsolowe. Każda aplikacja podaje swoją nazwę.
- Wszystkie wykonane operacje, również krótkie. Bez progu czasu i bez próbkowania.
- Udane operacje i błędy. Błąd w bibliotece nie zmienia wyniku operacji ani odpowiedzi aplikacji.

Po stronie SQL mierzymy wszystko, co przechodzi przez podmienioną klasę `Command`, bez filtrowania. Zapytania o schemat i savepointy (`SAVEPOINT`, `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`) idą przez `Command`, więc wchodzą do listy. `begin`, `commit`, `rollback` i inicjalizacja połączenia idą bezpośrednio przez PDO i są poza zakresem. Bezpośrednie wywołania PDO lub sterownika MongoDB poza połączeniami Yii nie są mierzone. Trafienie w cache nie jest zapytaniem.

Monitorowane połączenia wskazuje ręczna lista id komponentów w konfiguracji, np. `['db', 'dbRead', 'mongodb']`. Połączenie w module podajemy jako `admin/db`. Dynamicznie tworzone połączenia i własne klasy `Command` są poza zakresem.

## Żądanie HTTP

Kolektor zbiera od podpięcia komponentu w bootstrapie do rozpoczęcia finalizacji w `EVENT_AFTER_REQUEST`, w tym widoki i obsługę wyjątków. Finalizacja zamyka kolektor i wysyła paczkę. Jeżeli finalizacja nie nastąpiła, wykonuje ją callback z `register_shutdown_function`, zarejestrowany przy starcie. To pokrywa `exit()` i `exit(1)` z handlera wyjątków Yii. Błąd krytyczny PHP nadal gubi paczkę. Operacje po finalizacji są poza zakresem i nie są zliczane. Żądanie bez zapytań nie wysyła paczki.

## Zadanie konsolowe

Zadanie to jedno uruchomienie `php yii ...`, od startu do końca procesu. Worker kolejki to jedno długie zadanie z wieloma paczkami. Biblioteka nie rozpoznaje pojedynczych jobów.

Zadanie wysyła paczkę po osiągnięciu dowolnego limitu: 500 wpisów, 256 KB lub 30 sekund od poprzedniej wysyłki. Pierwsze 30 sekund liczy się od startu kolektora. Czas jest sprawdzany przy kolejnej operacji. Bezczynny proces nie wysyła. Na końcu zadania biblioteka zawsze wysyła niepustą resztę, także przez `register_shutdown_function`. Wszystkie paczki jednego zadania mają wspólne `id` i kolejne `seq` od 1.

## Format paczki

Paczka to nagłówek i płaska lista zapytań w kolejności zakończenia.

Nagłówek:

- `v`: wersja formatu.
- `app`: nazwa aplikacji z konfiguracji.
- `type`: `http` lub `console`.
- `id`: losowy identyfikator generowany przez bibliotekę.
- `seq`: numer paczki w zadaniu konsolowym. Dla HTTP zawsze 1.
- `module`, `controller`, `action`: akcja wejściowa zapamiętana z `EVENT_BEFORE_ACTION` aplikacji. `module` to `uniqueId` modułu lub `null` dla głównej aplikacji. `controller` i `action` to lokalne id. Obsługa błędu i zagnieżdżone `runAction` nie nadpisują tych pól. Gdy żądanie kończy się przed routingiem, pola mają `null`.
- `ts`: moment wysyłki w UTC.
- `host`: wynik `gethostname()`.
- `dropped`: liczba wpisów pominiętych po przekroczeniu limitu.

Wpis na liście:

- `db`: `mysql`, `pgsql` lub `mongodb`.
- `conn`: id komponentu połączenia.
- `op`: rodzaj operacji, np. `select`, `insert`, `find`, `aggregate`, `getMore`.
- `query`: znormalizowany tekst, opis niżej. `null`, gdy normalizacja jest niepewna.
- `time_ms`: czas wykonania.
- `result`: `success` lub `error`.
- `error`: kod błędu, tylko przy `result: error`. SQLSTATE dla SQL, kod liczbowy dla MongoDB.

Jeden wpis to jedno polecenie faktycznie wysłane do bazy. Ponowienie to nowy wpis. `find` i `getMore` to osobne wpisy. Gdy sterownik MongoDB dzieli `insertMany` na kilka poleceń, każde daje osobny wpis.

## Czas i wynik

Dla SQL `time_ms` obejmuje `prepare` i `execute` wewnątrz `Command`. Nie obejmuje otwarcia połączenia ani osobnych wywołań `fetch`. Błąd w `prepare` lub `execute` daje `result: error`. Błąd przy odczycie kursora nie jest raportowany.

`time_ms` to czas wybranych wywołań sterownika widziany z PHP, nie czysty czas serwera bazy. Przy buforowanych wynikach (domyślne w PDO MySQL) transfer danych następuje w `execute`, więc duży `SELECT` ma duży pomiar.

Dla MongoDB `time_ms` pochodzi ze zdarzeń sterownika. Każdy `writeError` lub `writeConcernError` w udanym poleceniu daje `result: error` z pierwszym kodem.

## Normalizacja query

SQL: symbole parametrów `:name` zostają bez zmian. Literały tekstowe i liczbowe wpisane wprost zamieniamy na `?`, także w `LIMIT`. Komentarze usuwamy. Obie reguły działają także w zapytaniu, które ma parametry. Nazwy tabel i kolumn zostają. Różna długość `IN (...)` daje różny `query`. Przy niepewności `query: null`. Obcięcie do limitu następuje dopiero po normalizacji.

MongoDB: zachowujemy nazwy pól, operatory i etapy potoku. Każdą wartość zastępujemy `?`. Dla `insert` zapisujemy kolekcję i liczbę dokumentów. Klucz głębiej niż trzy poziomy lub tekst ponad limit daje `query: null`. Obcięta struktura nie ma sensu, więc MongoDB nie jest obcinane.

Założenie: nazwy pól, tabel, kolekcji i parametrów są częścią kodu aplikacji, nie danymi użytkowników. Filtr typu `{"users.alice@example.com": true}` ujawni ten klucz. Za takie klucze odpowiada aplikacja.

Dokładne wartości parametrów, dokumenty MongoDB, adresy URL z parametrami i dane uwierzytelniające nie trafiają do paczki.

## Limity

- `query` do 2 KB razem z wielokropkiem. Dłuższy SQL obcinamy i dopisujemy `…`. Dłuższy MongoDB daje `null`.
- 500 wpisów w paczce. Po przekroczeniu biblioteka przestaje dodawać wpisy i zlicza je w `dropped`. Błędy po limicie też są pomijane.
- 256 KB na cały JSON paczki. Po przekroczeniu jak wyżej, ten sam licznik.

Wartości do korekty po teście wydajności.

## Zbieranie danych bez profilera Yii

Po stronie SQL pakiet dostarcza klasę `Command` podłączaną w konfiguracji połączenia. Po stronie MongoDB pakiet podłącza się do zdarzeń sterownika dla wskazanego połączenia. Oba źródła używają tego samego formatu wpisu i tego samego kolektora.

Każda operacja powoduje odczyt czasu i dodanie wpisu do tablicy w pamięci. Biblioteka nie zapisuje pliku i nie wywołuje adaptera przy każdej operacji.

## Adapter wyjściowy

Kontrakt: `send(QueryBatch $batch): void`. Aplikacja wskazuje klasę, obiekt lub `callable` przyjmujący `QueryBatch`. Adapter jest wywoływany raz na gotową paczkę. Pakiet nie zna adresu workera ani kolejki.

Przy wyjątku z adaptera paczka przepada. Biblioteka zapisuje jeden krótki `Yii::error` bez treści zapytań. Bez ponowień i bez zapisu awaryjnego. Za czas działania odpowiada sam adapter. Zabezpieczenie przed ponownym wejściem zapobiega mierzeniu pracy adaptera.

Konfiguracja: włączenie pakietu, nazwa aplikacji, lista połączeń, limit wpisów, limit rozmiaru paczki, adapter, ustawienia pliku. Bez adaptera działa adapter plikowy.

## Domyślny adapter plikowy

Jedna paczka to jeden wiersz JSON w pliku na lokalnym dysku pod `runtime/logs`. Zapis i rotacja używają wspólnej blokady na osobnym pliku `.lock`, przez `flock` z `LOCK_EX | LOCK_NB`. Gdy blokada jest zajęta, paczka przepada. Plik podlega rotacji po osiągnięciu rozmiaru. Wartości początkowe: 10 MB i pięć kopii. Gdy plik jest niedostępny, aplikacja działa dalej, a biblioteka zapisuje jeden `Yii::error` na proces. Współdzielone wolumeny i NFS są poza zakresem.

## Kryteria odbioru

1. Seria operacji daje pełną listę wpisów z czasami i wynikami, w kolejności zakończenia, do pierwszego osiągniętego limitu (500 wpisów lub 256 KB). Nadwyżka jest zliczona w `dropped`.
2. Operacje z różnymi wartościami parametrów dają ten sam `query`. Paczka nie ujawnia wartości.
3. Active Record i zwykłe polecenia są mierzone bez zmian w kodzie aplikacji. Cache nie jest raportowany.
4. Domyślny adapter zapisuje poprawne paczki, rotuje pliki i nie przerywa aplikacji przy błędzie zapisu.
5. Własny adapter zastępuje zapis plikowy bez zmian w kolektorze.
6. Test wydajności: 200 zapytań na żądanie, 1000 żądań, 8 współbieżnych procesów PHP-FPM, pakiet włączony i wyłączony, z anonimizacją i zapisem do pliku. Porównujemy medianę i p95 czasu odpowiedzi oraz `memory_get_peak_usage(true)` na końcu żądania. Dopuszczalny narzut: do 5% na medianie i p95 oraz do 2 MB pamięci szczytowej.

## Poza zakresem

Worker, wysyłka do konkretnego systemu, dashboard, trwała baza statystyk, agregacja, analiza planów zapytań, rozpoznawanie jobów kolejki, `begin`/`commit`/`rollback` przez PDO, długo żyjące procesy HTTP (RoadRunner, Swoole), NFS.
